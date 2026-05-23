<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Registry;

use Knetesin\JsonRpcServerBundle\Attribute\Cache;
use Knetesin\JsonRpcServerBundle\Attribute\McpFormat;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;
use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;
use Knetesin\JsonRpcServerBundle\Exception\MethodNotFoundException;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Constraint;

final class MethodRegistry
{
    /**
     * Depth used to decode the precomputed `inputSchemaJson`. The schema
     * is produced at compile time by {@see \Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder}
     * and bounded by `json_rpc_server.mcp.schema_max_depth` (default 6).
     * Each DTO nesting level contributes ~3 JSON levels (`properties` →
     * field → `properties` → …), so 64 leaves comfortable headroom for the
     * deepest configurable schemas without exposing the runtime-payload
     * `json_rpc_server.max_json_depth` knob to internal data.
     */
    private const int SCHEMA_JSON_DEPTH = 64;

    /** @var array<string, MethodMetadata>|null */
    private ?array $methods = null;

    private readonly LoggerInterface $logger;

    /**
     * @param array<string, array<string, mixed>> $rawMethods serializable shape produced by the compiler pass
     */
    public function __construct(
        private readonly array $rawMethods,
        private readonly ContainerInterface $handlers,
        private readonly string $defaultMcpFormat = 'json',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function has(string $name): bool
    {
        return isset($this->rawMethods[$name]);
    }

    public function get(string $name): MethodMetadata
    {
        if (!isset($this->rawMethods[$name])) {
            throw new MethodNotFoundException($name);
        }
        $this->methods ??= [];

        return $this->methods[$name] ??= $this->hydrate($this->rawMethods[$name]);
    }

    public function handler(string $name): object
    {
        return $this->handlers->get($name);
    }

    /**
     * @return array<string, MethodMetadata>
     */
    public function all(): array
    {
        foreach (array_keys($this->rawMethods) as $name) {
            $this->get($name);
        }

        return $this->methods ?? [];
    }

    /**
     * Aggregate counts for observability (Web Profiler panel, debug tooling).
     *
     * @return array{
     *     total: int,
     *     streaming: int,
     *     deprecated: int,
     *     secured: int,
     *     cached: int,
     *     rate_limited: int,
     *     mcp_exposed: int,
     * }
     */
    public function statistics(?McpToolFilter $mcpFilter = null): array
    {
        $stats = [
            'total' => 0,
            'streaming' => 0,
            'deprecated' => 0,
            'secured' => 0,
            'cached' => 0,
            'rate_limited' => 0,
            'mcp_exposed' => 0,
        ];

        foreach ($this->rawMethods as $name => $raw) {
            ++$stats['total'];
            if ($raw['isStreaming'] ?? false) {
                ++$stats['streaming'];
            }
            if (null !== ($raw['deprecated'] ?? null)) {
                ++$stats['deprecated'];
            }
            if ([] !== ($raw['roles'] ?? [])) {
                ++$stats['secured'];
            }
            if (isset($raw['cache'])) {
                ++$stats['cached'];
            }
            if (isset($raw['rateLimit'])) {
                ++$stats['rate_limited'];
            }
            if (null !== $mcpFilter && $mcpFilter->isExposed($this->get($name))) {
                ++$stats['mcp_exposed'];
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hydrate(array $raw): MethodMetadata
    {
        $parameters = array_values(array_map(
            fn (array $p) => new ParameterMetadata(
                name: $p['name'],
                type: $p['type'],
                isContext: $p['isContext'],
                isDto: $p['isDto'],
                hasDefault: $p['hasDefault'],
                default: $p['default'],
                allowsNull: $p['allowsNull'],
                isHttpRequest: $p['isHttpRequest'] ?? false,
                isRpcRequest: $p['isRpcRequest'] ?? false,
                hasParamAttribute: $p['hasParamAttribute'] ?? false,
                jsonName: $p['jsonName'] ?? null,
                paramRequired: $p['paramRequired'] ?? true,
                constraints: $this->buildConstraints($p['constraints'] ?? []),
                dtoOwnKeys: $p['dtoOwnKeys'] ?? [],
            ),
            $raw['parameters'],
        ));

        return new MethodMetadata(
            name: $raw['name'],
            serviceClass: $raw['serviceClass'],
            roles: $raw['roles'],
            description: $raw['description'],
            parameters: $parameters,
            returnType: $raw['returnType'],
            isStreaming: $raw['isStreaming'],
            streamFormat: null !== $raw['streamFormat'] ? StreamFormat::from($raw['streamFormat']) : null,
            rolesMatch: RoleMatch::from($raw['rolesMatch'] ?? RoleMatch::Any->value),
            allowPositionalDto: $raw['allowPositionalDto'] ?? false,
            rejectUnknown: $raw['rejectUnknown'] ?? true,
            deprecated: $raw['deprecated'] ?? null,
            hasMcpAttribute: $raw['hasMcpAttribute'] ?? false,
            mcpEnabled: $raw['mcpEnabled'] ?? true,
            mcpDescription: $raw['mcpDescription'] ?? null,
            mcpFormat: isset($raw['mcpFormat']) ? McpFormat::from($raw['mcpFormat']) : McpFormat::from($this->defaultMcpFormat),
            rateLimit: isset($raw['rateLimit']) ? new RateLimit(
                limit: $raw['rateLimit']['limit'],
                intervalSec: $raw['rateLimit']['intervalSec'],
                scope: RateLimitScope::from($raw['rateLimit']['scope']),
                policy: RateLimitPolicy::from($raw['rateLimit']['policy'] ?? RateLimitPolicy::FixedWindow->value),
            ) : null,
            cache: isset($raw['cache']) ? new Cache(
                ttl: $raw['cache']['ttl'],
                scope: $raw['cache']['scope'],
                pool: $raw['cache']['pool'],
                tags: $raw['cache']['tags'] ?? [],
            ) : null,
            maxRequestSize: $raw['maxRequestSize'] ?? null,
            inputSchema: isset($raw['inputSchemaJson'])
                ? self::normalizeSchema((array) json_decode($raw['inputSchemaJson'], true, self::SCHEMA_JSON_DEPTH, \JSON_THROW_ON_ERROR))
                : [],
        );
    }

    /**
     * json_decode($json, assoc: true) collapses `{}` into `[]`, which then
     * re-encodes as `properties: []` — invalid per JSON Schema, where these
     * keys are objects. Walk the tree and restore stdClass for empty maps
     * under the object-shaped keywords.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function normalizeSchema(array $schema): array
    {
        static $objectKeys = ['properties', 'patternProperties', 'definitions', '$defs'];
        foreach ($schema as $key => $value) {
            if (\is_array($value)) {
                if (\in_array($key, $objectKeys, true) && [] === $value) {
                    $schema[$key] = new \stdClass();
                    continue;
                }
                $schema[$key] = self::normalizeSchema($value);
            }
        }

        return $schema;
    }

    /**
     * @param list<array{class: class-string<Constraint>, args: array<array-key, mixed>}> $raw
     *
     * @return list<Constraint>
     */
    private function buildConstraints(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $class = $entry['class'];
            try {
                $instance = new $class(...$entry['args']);
            } catch (\Throwable $e) {
                // Misconfigured constraint args would silently disable validation
                // at runtime — surface it so the developer notices instead of
                // shipping a method whose constraints never fire.
                $this->logger->warning('Failed to instantiate validator constraint; validation skipped for this attribute.', [
                    'constraint' => $class,
                    'args' => $entry['args'],
                    'exception' => $e,
                ]);
                continue;
            }
            $out[] = $instance;
        }

        return $out;
    }
}
