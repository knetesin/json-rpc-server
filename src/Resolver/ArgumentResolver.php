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
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ArgumentResolver
{
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

            return $this->mapPositionalToDto($dtoType, $list);
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
    private function mapPositionalToDto(string $dtoClass, array $list): array
    {
        $ctor = (new \ReflectionClass($dtoClass))->getConstructor();
        if (null === $ctor) {
            return [];
        }
        $named = [];
        foreach ($ctor->getParameters() as $i => $p) {
            if (\array_key_exists($i, $list)) {
                $named[$p->getName()] = $list[$i];
            }
        }

        return $named;
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
                throw new InvalidParamsException('Invalid params', $this->denormViolations($e), $e);
            } catch (ExtraAttributesException $e) {
                throw new InvalidParamsException(\sprintf('Unknown parameter(s): %s. Set #[Rpc\\Method(rejectUnknown: false)] (or json_rpc_server.params.reject_unknown: false) to accept extra keys.', implode(', ', $e->getExtraAttributes())), array_values(array_map(static fn (string $name): array => ['path' => $name, 'message' => 'Unknown parameter', 'code' => null], $e->getExtraAttributes())), $e);
            } catch (SerializerException $e) {
                throw new InvalidParamsException($e->getMessage(), previous: $e);
            }

            $violations = $this->validator->validate($dto);
            if (\count($violations) > 0) {
                throw new InvalidParamsException('Invalid params', $violations);
            }

            return $dto;
        }

        $key = $p->lookupKey();
        if (\array_key_exists($key, $named)) {
            $value = $named[$key];
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

        throw new InvalidParamsException(\sprintf('Missing required parameter "%s"', $p->name));
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
     * @return list<array{path: string, message: string, code: ?string}>
     */
    private function denormViolations(PartialDenormalizationException $e): array
    {
        $out = [];
        foreach ($e->getErrors() as $err) {
            $out[] = [
                'path' => $err->getPath() ?? '',
                'message' => $err->getMessage(),
                'code' => 0 !== $err->getCode() ? (string) $err->getCode() : null,
            ];
        }

        return $out;
    }
}
