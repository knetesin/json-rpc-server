<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Resolver;

use Knetesin\JsonRpcServerBundle\Context\ContextFactory;
use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\ParameterMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ArgumentResolver
{
    /** Expected types that -32602 details may name; anything else is a class name or internal. */
    private const array CLIENT_TYPE_NAMES = ['int', 'float', 'string', 'bool', 'true', 'false', 'null', 'array', 'iterable', 'object', 'mixed'];

    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
        private readonly ContextFactory $contextFactory,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<mixed>
     */
    public function resolve(MethodMetadata $method, RpcRequest $request): array
    {
        $named = $this->toNamedParams($method, $request->params);

        if ($method->rejectUnknown) {
            $this->assertNoOrphanKeys($method, $named);
        }

        $arguments = [];
        foreach ($method->parameters as $p) {
            $arguments[] = $this->resolveOne($method, $p, $named, $request);
        }

        return $arguments;
    }

    /**
     * The root params object must only contain keys claimed by some __invoke
     * parameter — a DTO's ctor field or a scalar #[Rpc\Param] (or auto-promoted
     * scalar). Without this check, extras would silently disappear: the DTO
     * branch filters $named to its own keys, so the denormalizer's own
     * ALLOW_EXTRA_ATTRIBUTES check (the historical orphan signal) never sees
     * them. We replicate the same error shape the denormalizer emits so the
     * client-facing -32602 payload is unchanged.
     *
     * Skipped when the method declares NO business params (i.e. only Context /
     * RpcRequest / HttpRequest injection) — such handlers read params manually
     * via the injected envelope, so the bundle has no schema to validate
     * against.
     *
     * @param array<string, mixed> $named
     */
    private function assertNoOrphanKeys(MethodMetadata $method, array $named): void
    {
        $owned = [];
        $hasBusinessParam = false;
        foreach ($method->parameters as $p) {
            if ($p->isInjected()) {
                continue;
            }
            $hasBusinessParam = true;
            if ($p->isDto) {
                foreach ($p->dtoOwnKeys as $k) {
                    $owned[$k] = true;
                }
                continue;
            }
            $owned[$p->lookupKey()] = true;
        }

        if (!$hasBusinessParam) {
            return;
        }

        $unknown = array_keys(array_diff_key($named, $owned));
        if ([] === $unknown) {
            return;
        }

        throw new InvalidParamsException(
            \sprintf('Unknown parameter(s): %s. Set #[Rpc\\Method(rejectUnknown: false)] (or json_rpc_server.params.reject_unknown: false) to accept extra keys.', implode(', ', $unknown)),
            array_map(
                static fn (string $name): array => ['path' => $name, 'message' => 'Unknown parameter', 'code' => null],
                $unknown,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toNamedParams(MethodMetadata $method, RpcParams $params): array
    {
        if ($params->isEmpty()) {
            return [];
        }
        if (!$params->isList()) {
            /** @var array<string, mixed> $assoc */
            $assoc = $params->all();

            return $assoc;
        }

        $list = array_values($params->all());
        $businessParams = array_values(array_filter(
            $method->parameters,
            static fn (ParameterMetadata $p) => !$p->isInjected(),
        ));

        if (1 === \count($businessParams) && $businessParams[0]->isDto) {
            if (!$method->allowPositionalDto) {
                throw new InvalidParamsException(\sprintf('Method "%s" requires named parameters. Send params as a JSON object, or opt in to positional DTO via #[Rpc\\Method(allowPositionalDto: true)] (per-method) or `json_rpc_server.params.allow_positional_dto: true` (globally).', $method->name));
            }

            $dtoType = $businessParams[0]->type;
            if (null === $dtoType || !class_exists($dtoType)) {
                throw new InvalidParamsException(\sprintf('Method "%s" DTO parameter has no valid class type.', $method->name));
            }

            return $this->mapPositionalToDto($method, $dtoType, $list);
        }

        // No business params: the handler reads the raw envelope itself, same
        // exemption as assertNoOrphanKeys().
        if ([] !== $businessParams) {
            $this->assertPositionalCount($method, \count($businessParams), \count($list));
        }

        $named = [];
        foreach ($businessParams as $i => $p) {
            if (\array_key_exists($i, $list)) {
                $named[$p->lookupKey()] = $list[$i];
            }
        }

        return $named;
    }

    /**
     * @param class-string $dtoClass
     * @param list<mixed> $list
     *
     * @return array<string, mixed>
     */
    private function mapPositionalToDto(MethodMetadata $method, string $dtoClass, array $list): array
    {
        $ctorParams = (new \ReflectionClass($dtoClass))->getConstructor()?->getParameters() ?? [];
        $this->assertPositionalCount($method, \count($ctorParams), \count($list));

        $named = [];
        foreach ($ctorParams as $i => $p) {
            if (\array_key_exists($i, $list)) {
                $named[$p->getName()] = $list[$i];
            }
        }

        return $named;
    }

    /**
     * Positional values beyond the declared parameters have no name to map to;
     * under rejectUnknown they are refused like unknown named keys.
     */
    private function assertPositionalCount(MethodMetadata $method, int $expected, int $given): void
    {
        if ($given <= $expected || !$method->rejectUnknown) {
            return;
        }

        throw new InvalidParamsException(\sprintf('Too many positional parameters: expected at most %d, got %d', $expected, $given));
    }

    /**
     * @param array<string, mixed> $named
     */
    private function resolveOne(MethodMetadata $method, ParameterMetadata $p, array $named, RpcRequest $request): mixed
    {
        if ($p->isContext) {
            return $this->contextFactory->create($method->name);
        }

        if ($p->isRpcRequest) {
            return $request;
        }

        if ($p->isHttpRequest) {
            return $this->requestStack->getMainRequest()
                ?? throw new \LogicException('No active HTTP request to inject — this method must be called inside an HTTP request lifecycle.');
        }

        if ($p->isDto) {
            $dtoType = $p->type;
            if (null === $dtoType || !class_exists($dtoType)) {
                throw new InvalidParamsException(\sprintf('Method "%s" parameter "%s" has no valid class type.', $method->name, $p->name));
            }

            // Feed the denormalizer only the keys this DTO owns. Siblings —
            // other DTOs or #[Rpc\Param] scalars — keep their own keys, and
            // ALLOW_EXTRA_ATTRIBUTES below has nothing to silently swallow.
            // When dtoOwnKeys is empty (legacy/no ctor) we fall back to the
            // whole $named to preserve the original single-DTO behavior.
            $dtoNamed = [] === $p->dtoOwnKeys
                ? $named
                : array_intersect_key($named, array_flip($p->dtoOwnKeys));

            try {
                $dto = $this->denormalizer->denormalize(
                    data: $dtoNamed,
                    type: $dtoType,
                    context: [
                        'collect_denormalization_errors' => true,
                        'disable_type_enforcement' => false,
                        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => !$method->rejectUnknown,
                    ],
                );
            } catch (PartialDenormalizationException $e) {
                throw new InvalidParamsException('Invalid params', $this->denormViolations($e, $dtoNamed), $e);
            } catch (ExtraAttributesException $e) {
                $extra = $e->getExtraAttributes();
                $details = [];
                foreach ($extra as $name) {
                    $details[] = ['path' => $name, 'message' => 'Unknown parameter', 'code' => null];
                }

                throw new InvalidParamsException(\sprintf('Unknown parameter(s): %s. Set #[Rpc\\Method(rejectUnknown: false)] (or json_rpc_server.params.reject_unknown: false) to accept extra keys.', implode(', ', $extra)), $details, $e);
            } catch (SerializerException $e) {
                // Serializer messages name DTO classes and internals; they stay in `previous` only.
                throw new InvalidParamsException('Invalid params', previous: $e);
            }

            $violations = $this->validator->validate($dto);
            if (\count($violations) > 0) {
                throw new InvalidParamsException('Invalid params', $violations);
            }

            return $dto;
        }

        $key = $p->lookupKey();
        if (\array_key_exists($key, $named)) {
            $value = $this->coerceScalarToDeclaredType($named[$key], $p, $key);
            if ([] !== $p->constraints) {
                $violations = $this->validator->validate($value, $p->constraints);
                if (\count($violations) > 0) {
                    throw new InvalidParamsException('Invalid params', $this->labelScalarViolations($violations, $key));
                }
            }

            return $value;
        }
        if ($p->hasDefault) {
            return $p->default;
        }
        if ($p->allowsNull) {
            return null;
        }

        throw new InvalidParamsException(\sprintf('Missing required parameter "%s"', $key));
    }

    /**
     * Scalars reach handlers raw (only DTOs go through the denormalizer), so a JSON string
     * for an `int` param would throw native TypeError. Coerce to the declared builtin type
     * when lossless; reject incompatible values as a clean Invalid params instead.
     */
    private function coerceScalarToDeclaredType(mixed $value, ParameterMetadata $p, string $key): mixed
    {
        $type = $p->type;
        if (null === $value || null === $type || !\in_array($type, ['int', 'float', 'string', 'bool'], true)) {
            return $value;
        }

        $coerced = match ($type) {
            'int' => match (true) {
                \is_int($value) => $value,
                \is_string($value) && 1 === preg_match('/^-?\d+$/', $value) => $this->intFromDigits($value),
                \is_float($value) && (float) (int) $value === $value => (int) $value,
                default => null,
            },
            'float' => match (true) {
                \is_int($value), \is_float($value) => (float) $value,
                \is_string($value) && is_numeric($value) => (float) $value,
                default => null,
            },
            'string' => match (true) {
                \is_string($value) => $value,
                \is_int($value), \is_float($value) => (string) $value,
                default => null,
            },
            'bool' => match (true) {
                \is_bool($value) => $value,
                \in_array($value, [1, '1', 'true'], true) => true,
                \in_array($value, [0, '0', 'false'], true) => false,
                default => null,
            },
        };

        if (null === $coerced) {
            throw new InvalidParamsException('Invalid params', [[
                'path' => $key,
                'message' => \sprintf('Expected type "%s", got "%s"', $type, get_debug_type($value)),
                'code' => null,
            ]]);
        }

        return $coerced;
    }

    /**
     * `(int)` saturates out-of-range digit strings at PHP_INT_MAX/MIN; PHP's
     * numeric-string arithmetic yields a float for them instead, which is how
     * overflow is detected here.
     */
    private function intFromDigits(string $digits): ?int
    {
        if (!is_numeric($digits)) {
            return null;
        }
        $number = $digits + 0;

        return \is_int($number) ? $number : null;
    }

    /**
     * @return list<array{path: string, message: string, code: ?string}>
     */
    private function labelScalarViolations(\Symfony\Component\Validator\ConstraintViolationListInterface $violations, string $path): array
    {
        $out = [];
        foreach ($violations as $v) {
            $out[] = [
                'path' => $path,
                'message' => (string) $v->getMessage(),
                'code' => $v->getCode(),
            ];
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $input what was fed to the denormalizer
     *
     * @return list<array{path: string, message: string, code: ?string}>
     */
    private function denormViolations(PartialDenormalizationException $e, array $input): array
    {
        $out = [];
        foreach ($e->getErrors() as $err) {
            $path = $err->getPath() ?? '';
            $out[] = [
                'path' => $path,
                // A missing key and an explicit null both arrive as a null value.
                'message' => 'null' === $err->getCurrentType() && !self::pathExists($input, $path)
                    ? 'This field is missing.'
                    : $this->denormMessage($err),
                'code' => 0 !== $err->getCode() ? (string) $err->getCode() : null,
            ];
        }

        return $out;
    }

    /**
     * Whether a serializer error path (`a.b[0].c`) names a key present in the input.
     *
     * @param array<array-key, mixed> $input
     */
    private static function pathExists(array $input, string $path): bool
    {
        $node = $input;
        foreach (preg_split('/[.\[\]]+/', $path, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }

        return true;
    }

    /**
     * Client-safe text: the serializer's own message names the DTO class and
     * internals. Only builtin expected types are echoed back; a class-typed
     * (date, enum, nested DTO) or unknown expectation gets a generic text.
     */
    private function denormMessage(NotNormalizableValueException $err): string
    {
        $types = [];
        foreach ($err->getExpectedTypes() ?? [] as $type) {
            // Generic arguments carry element types, possibly class names: drop them.
            $base = (string) preg_replace('/<.*>/', '', $type);
            foreach (explode('|', $base) as $part) {
                $part = trim($part, " ?()\t");
                $part = match ($part) {
                    'integer' => 'int',
                    'boolean' => 'bool',
                    'double' => 'float',
                    'list' => 'array',
                    default => $part,
                };
                if (!\in_array($part, self::CLIENT_TYPE_NAMES, true)) {
                    return 'This value is not valid.';
                }
                $types[] = $part;
            }
        }

        if ([] === $types) {
            return 'This value is not valid.';
        }

        return \sprintf('This value should be of type %s.', implode('|', array_unique($types)));
    }
}
