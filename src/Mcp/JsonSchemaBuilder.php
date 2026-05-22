<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Registry\ParameterMetadata;
use JsonRpcServer\Serializer\DateNormalizer;
use JsonRpcServer\Type\Date;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Builds a JSON Schema (draft-07-ish) describing a DTO class so it can be
 * consumed by MCP clients as a tool's `inputSchema`.
 *
 * Intentionally minimal: maps PHP types and a curated set of Symfony Validator
 * constraints. Anything not understood is omitted rather than guessed.
 *
 * The DateTimeInterface mapping depends on `json_rpc_server.serializer.datetime_format`:
 * when timestamps are configured the wire value is an integer, so the schema
 * advertises `integer` instead of `string`/`date-time` — keeps MCP clients
 * (and the LLMs behind them) from sending the wrong type.
 */
final class JsonSchemaBuilder
{
    /** Recursion guard fallback for self-referencing DTOs. */
    private const int DEFAULT_MAX_DEPTH = 6;

    /** Effective recursion guard for self-referencing DTOs. */
    private readonly int $maxDepth;

    public function __construct(
        /** One of DateNormalizer::FORMAT_* or a raw php date() string. */
        private readonly string $datetimeFormat = DateNormalizer::FORMAT_ISO8601,
        ?int $maxDepth = null,
    ) {
        $this->maxDepth = $maxDepth ?? self::DEFAULT_MAX_DEPTH;
    }

    /**
     * Resolves the right schema source for a method:
     *   - if it takes a DTO, the schema comes from the DTO constructor;
     *   - otherwise it is built from every parameter carrying #[Rpc\Param].
     *
     * @return array<string, mixed>
     */
    public function fromMethod(MethodMetadata $meta): array
    {
        $dto = $meta->getDtoParameter();
        if (null !== $dto) {
            return $this->fromClass($dto->type);
        }

        $paramSchemas = [];
        $required = [];
        foreach ($meta->parameters as $p) {
            if (!$p->hasParamAttribute) {
                continue;
            }
            $name = $p->lookupKey();
            $paramSchemas[$name] = $this->schemaForParamMetadata($p);
            if ($p->paramRequired && !$p->hasDefault && !$p->allowsNull) {
                $required[] = $name;
            }
        }

        if ([] === $paramSchemas) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        $schema = ['type' => 'object', 'properties' => (object) $paramSchemas];
        if ([] !== $required) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForParamMetadata(ParameterMetadata $p): array
    {
        $base = match ($p->type) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            null => [],
            default => !\in_array($p->type, ['mixed'], true)
                ? $this->fromClass($p->type)
                : [],
        };

        if ($p->allowsNull && isset($base['type']) && \is_string($base['type'])) {
            $base['type'] = [$base['type'], 'null'];
        }

        foreach ($p->constraints as $constraint) {
            $this->applyConstraint($constraint, $base);
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromClass(?string $class, int $depth = 0): array
    {
        if (null === $class || $depth >= $this->maxDepth) {
            return ['type' => 'object'];
        }
        if (Date::class === $class) {
            return ['type' => 'string', 'format' => 'date'];
        }
        if (is_a($class, \DateTimeInterface::class, true)) {
            return $this->dateTimeSchema();
        }
        if (!class_exists($class) && !enum_exists($class)) {
            return ['type' => 'object'];
        }
        if (enum_exists($class)) {
            return $this->schemaForEnum($class);
        }

        $reflection = new \ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        if (null === $ctor) {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        $properties = [];
        $required = [];
        foreach ($ctor->getParameters() as $param) {
            $properties[$param->getName()] = $this->schemaForParameter($param, $depth + 1);
            if (!$param->isDefaultValueAvailable() && !($param->getType()?->allowsNull() ?? true)) {
                $required[] = $param->getName();
            }
        }

        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForParameter(\ReflectionParameter $param, int $depth): array
    {
        $type = $param->getType();
        $base = $this->schemaForType($type, $depth);

        foreach ($this->collectAttributes($param) as $instance) {
            $this->applyConstraint($instance, $base);
        }

        return $base;
    }

    /**
     * Validator constraint attributes do not declare TARGET_PARAMETER, so we read
     * them via the corresponding promoted property when present, and fall back to
     * the parameter for everything else. Attributes whose declared targets do not
     * include parameters or properties are silently skipped.
     *
     * @return list<object>
     */
    private function collectAttributes(\ReflectionParameter $param): array
    {
        $sources = [];
        if ($param->isPromoted()) {
            $declaringClass = $param->getDeclaringClass();
            if (null !== $declaringClass && $declaringClass->hasProperty($param->getName())) {
                $sources[] = $declaringClass->getProperty($param->getName())->getAttributes();
            }
        }
        $sources[] = $param->getAttributes();

        $instances = [];
        $seen = [];
        foreach ($sources as $list) {
            foreach ($list as $attr) {
                $name = $attr->getName();
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                try {
                    $instances[] = $attr->newInstance();
                } catch (\Error) {
                    // Attribute target mismatch — skip.
                }
            }
        }

        return $instances;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForType(?\ReflectionType $type, int $depth): array
    {
        if (!$type instanceof \ReflectionNamedType) {
            return [];
        }
        $name = $type->getName();
        $base = match (true) {
            'string' === $name => ['type' => 'string'],
            'int' === $name => ['type' => 'integer'],
            'float' === $name => ['type' => 'number'],
            'bool' === $name => ['type' => 'boolean'],
            'array' === $name => ['type' => 'array'],
            !$type->isBuiltin() => $this->fromClass($name, $depth),
            default => [],
        };

        if ($type->allowsNull() && isset($base['type']) && \is_string($base['type'])) {
            $base['type'] = [$base['type'], 'null'];
        }

        return $base;
    }

    /**
     * Schema for DateTimeInterface — varies by the bundle's configured wire
     * format. Timestamps go on the wire as integers, so the schema has to
     * match or MCP clients will send strings into a numeric denormalizer
     * (NotNormalizableValueException at runtime).
     *
     * @return array<string, mixed>
     */
    private function dateTimeSchema(): array
    {
        return match ($this->datetimeFormat) {
            DateNormalizer::FORMAT_TIMESTAMP => ['type' => 'integer', 'description' => 'Unix timestamp (seconds since epoch)'],
            DateNormalizer::FORMAT_TIMESTAMP_MS => ['type' => 'integer', 'description' => 'Unix timestamp in milliseconds'],
            default => ['type' => 'string', 'format' => 'date-time'],
        };
    }

    /**
     * @param class-string<\UnitEnum> $class
     *
     * @return array<string, mixed>
     */
    private function schemaForEnum(string $class): array
    {
        $cases = $class::cases();
        if (is_a($class, \BackedEnum::class, true)) {
            /** @var list<\BackedEnum> $cases */
            $values = array_map(static fn (\BackedEnum $c): int|string => $c->value, $cases);
            $type = \is_int($values[0] ?? null) ? 'integer' : 'string';

            return ['type' => $type, 'enum' => $values];
        }

        return ['type' => 'string', 'enum' => array_map(static fn (\UnitEnum $c): string => $c->name, $cases)];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyConstraint(object $constraint, array &$schema): void
    {
        switch (true) {
            case $constraint instanceof Assert\NotBlank:
            case $constraint instanceof Assert\NotNull:
                if (isset($schema['type']) && \is_array($schema['type'])) {
                    $schema['type'] = array_values(array_filter($schema['type'], static fn ($t) => 'null' !== $t));
                    if (1 === \count($schema['type'])) {
                        $schema['type'] = $schema['type'][0];
                    }
                }
                break;

            case $constraint instanceof Assert\Length:
                if (null !== $constraint->min) {
                    $schema['minLength'] = $constraint->min;
                }
                if (null !== $constraint->max) {
                    $schema['maxLength'] = $constraint->max;
                }
                break;

            case $constraint instanceof Assert\Range:
                if (null !== $constraint->min) {
                    $schema['minimum'] = $constraint->min;
                }
                if (null !== $constraint->max) {
                    $schema['maximum'] = $constraint->max;
                }
                break;

            case $constraint instanceof Assert\Positive:
                $schema['exclusiveMinimum'] = 0;
                break;

            case $constraint instanceof Assert\PositiveOrZero:
                $schema['minimum'] = 0;
                break;

            case $constraint instanceof Assert\Negative:
                $schema['exclusiveMaximum'] = 0;
                break;

            case $constraint instanceof Assert\NegativeOrZero:
                $schema['maximum'] = 0;
                break;

            case $constraint instanceof Assert\Choice:
                if (null !== $constraint->choices) {
                    $schema['enum'] = array_values($constraint->choices);
                }
                break;

            case $constraint instanceof Assert\Email:
                $schema['format'] = 'email';
                break;

            case $constraint instanceof Assert\Url:
                $schema['format'] = 'uri';
                break;

            case $constraint instanceof Assert\Regex:
                if (null !== $constraint->pattern) {
                    $schema['pattern'] = trim($constraint->pattern, '/');
                }
                break;
        }
    }
}
