<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\Attribute\Cache as RpcCache;
use Knetesin\JsonRpcServerBundle\Attribute\MaxRequestSize as RpcMaxRequestSize;
use Knetesin\JsonRpcServerBundle\Attribute\Mcp as RpcMcp;
use Knetesin\JsonRpcServerBundle\Attribute\Method as RpcMethod;
use Knetesin\JsonRpcServerBundle\Attribute\Param as RpcParam;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimit as RpcRateLimit;
use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;
use Knetesin\JsonRpcServerBundle\Attribute\Stream as RpcStream;
use Knetesin\JsonRpcServerBundle\Cache\CacheChecker;
use Knetesin\JsonRpcServerBundle\Cache\Scope\IpScope;
use Knetesin\JsonRpcServerBundle\Cache\Scope\UserScope;
use Knetesin\JsonRpcServerBundle\Context\Context;
use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilderFactory;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Registry\ParameterMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Validator\Constraint;

final class MethodCompilerPass implements CompilerPassInterface
{
    public const TAG = 'json_rpc_server.method';

    public function process(ContainerBuilder $container): void
    {
        $rolesMatchParam = $container->getParameter('json_rpc_server.security.roles_match');
        if (!\is_string($rolesMatchParam) && !\is_int($rolesMatchParam)) {
            throw new \LogicException('Parameter json_rpc_server.security.roles_match must be a string or int.');
        }
        $defaultRolesMatch = RoleMatch::from($rolesMatchParam);
        /** @var list<string> $defaultRoles */
        $defaultRoles = (array) $container->getParameter('json_rpc_server.security.default_roles');
        /** @var list<string> $publicPrefixes */
        $publicPrefixes = (array) $container->getParameter('json_rpc_server.security.public_prefixes');
        /** @var list<string> $publicMethods */
        $publicMethods = (array) $container->getParameter('json_rpc_server.security.public_methods');
        $publicMethodsIndex = array_fill_keys($publicMethods, true);
        /** @var array<string, list<string>> $prefixRoles */
        $prefixRoles = (array) $container->getParameter('json_rpc_server.security.prefix_roles');
        // Sort by prefix length DESC so the longest match wins deterministically
        // when both "admin." and "admin.users." are configured.
        uksort($prefixRoles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $defaultAllowPositionalDto = (bool) $container->getParameter('json_rpc_server.params.allow_positional_dto');
        $defaultRejectUnknown = (bool) $container->getParameter('json_rpc_server.params.reject_unknown');
        $datetimeFormatParam = $container->hasParameter('json_rpc_server.serializer.datetime_format')
            ? $container->getParameter('json_rpc_server.serializer.datetime_format')
            : 'iso8601';
        $schemaMaxDepth = $container->hasParameter('json_rpc_server.mcp.schema_max_depth')
            ? (int) $container->getParameter('json_rpc_server.mcp.schema_max_depth')
            : 6;
        $schemaBuilder = JsonSchemaBuilderFactory::create(
            \is_string($datetimeFormatParam) ? $datetimeFormatParam : 'iso8601',
            $schemaMaxDepth,
        );
        $handlersPublic = (bool) $container->getParameter('json_rpc_server.handlers.public');
        $handlersShared = (bool) $container->getParameter('json_rpc_server.handlers.shared');
        $taggedIds = array_keys($container->findTaggedServiceIds(self::TAG));
        $methods = [];
        $handlerRefs = [];
        $scopeRefs = [
            UserScope::class => new Reference(UserScope::class),
            IpScope::class => new Reference(IpScope::class),
        ];

        foreach ($taggedIds as $serviceId) {
            $def = $container->getDefinition($serviceId);
            $class = $def->getClass() ?? $serviceId;

            $reflection = $container->getReflectionClass($class, false);
            if (null === $reflection) {
                continue;
            }

            $methodAttr = $this->firstAttribute($reflection, RpcMethod::class);
            if (null === $methodAttr) {
                continue;
            }

            $streamAttr = $this->firstAttribute($reflection, RpcStream::class);
            $mcpAttr = $this->firstAttribute($reflection, RpcMcp::class);
            $rateLimitAttr = $this->firstAttribute($reflection, RpcRateLimit::class);
            $cacheAttr = $this->firstAttribute($reflection, RpcCache::class);
            $maxRequestSizeAttr = $this->firstAttribute($reflection, RpcMaxRequestSize::class);

            if (null !== $cacheAttr && null !== $streamAttr) {
                throw new \LogicException(\sprintf('RPC method %s (%s) cannot be both #[Rpc\\Stream] and #[Rpc\\Cache] — streams produce ad-hoc data over time and cannot be replayed from a single cached blob.', $methodAttr->name, $class));
            }

            // symfony/rate-limiter is a soft dep — fail loudly the moment we
            // see a method asking for rate limiting without the package, so
            // the developer gets a fixable error at container build time
            // rather than a silent "rate limit never fires" at runtime.
            if (null !== $rateLimitAttr && !class_exists(\Symfony\Component\RateLimiter\RateLimiterFactory::class)) {
                throw new \LogicException(\sprintf('RPC method %s (%s) carries #[Rpc\\RateLimit], but symfony/rate-limiter is not installed. Run `composer require symfony/rate-limiter`.', $methodAttr->name, $class));
            }

            if (null !== $cacheAttr?->scope) {
                $scopeRefs[$cacheAttr->scope] ??= new Reference($cacheAttr->scope);
            }

            if (!$reflection->hasMethod('__invoke')) {
                throw new \LogicException(\sprintf('RPC method %s (%s) must define __invoke()', $methodAttr->name, $class));
            }

            $invoke = $reflection->getMethod('__invoke');
            $parameters = $this->buildParameters($invoke, $methodAttr->name, $class);
            $returnType = $invoke->getReturnType()?->__toString();

            $effectiveRoles = $this->resolveEffectiveRoles(
                $methodAttr->name,
                $methodAttr->roles,
                $defaultRoles,
                $publicPrefixes,
                $publicMethodsIndex,
                $prefixRoles,
            );

            $raw = [
                'name' => $methodAttr->name,
                'serviceClass' => $class,
                'roles' => $effectiveRoles,
                'rolesMatch' => ($methodAttr->rolesMatch ?? $defaultRolesMatch)->value,
                'allowPositionalDto' => $methodAttr->allowPositionalDto ?? $defaultAllowPositionalDto,
                'rejectUnknown' => $methodAttr->rejectUnknown ?? $defaultRejectUnknown,
                'deprecated' => $methodAttr->deprecated,
                'description' => $methodAttr->description,
                'parameters' => $parameters,
                'returnType' => $returnType,
                'isStreaming' => null !== $streamAttr,
                'streamFormat' => $streamAttr?->format->value,
                'hasMcpAttribute' => null !== $mcpAttr,
                'mcpEnabled' => null === $mcpAttr || $mcpAttr->enabled,
                'mcpDescription' => $mcpAttr?->description,
                'mcpFormat' => $mcpAttr?->format?->value,
                'mcpAnnotations' => $this->resolveMcpAnnotations($mcpAttr, $cacheAttr),
                'rateLimit' => null === $rateLimitAttr ? null : [
                    'limit' => $rateLimitAttr->limit,
                    'intervalSec' => $rateLimitAttr->intervalSec,
                    'scope' => $rateLimitAttr->scope->value,
                    'policy' => $rateLimitAttr->policy->value,
                ],
                'cache' => null === $cacheAttr ? null : [
                    'ttl' => $cacheAttr->ttl,
                    'scope' => $cacheAttr->scope,
                    'pool' => $cacheAttr->pool,
                    'tags' => $cacheAttr->tags,
                ],
                'maxRequestSize' => $maxRequestSizeAttr?->bytes,
            ];

            // Pre-compute the MCP JSON Schema once at compile time so /mcp/tools
            // doesn't walk reflection on every request. Build a thin metadata
            // stub — JsonSchemaBuilder only reads parameters here. Serialized
            // as JSON because the DI container dumper rejects stdClass instances
            // (which JsonSchemaBuilder uses for empty `properties: {}` objects).
            $raw['inputSchemaJson'] = json_encode(
                $schemaBuilder->fromMethod($this->stubMetadata($raw, $parameters)),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );

            $outputSchema = $this->resolveOutputSchema(
                $methodAttr->outputSchema,
                $returnType,
                $schemaBuilder,
                $methodAttr->name,
                $class,
            );
            if ([] !== $outputSchema) {
                $raw['outputSchemaJson'] = json_encode(
                    $outputSchema,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                );
            }

            if (isset($methods[$raw['name']])) {
                throw new \LogicException(\sprintf('Duplicate RPC method name "%s" (in %s and %s)', $raw['name'], $methods[$raw['name']]['serviceClass'], $class));
            }

            $methods[$raw['name']] = $raw;
            $handlerRefs[$raw['name']] = new Reference($serviceId);

            // Visibility and sharing semantics come from json_rpc_server.handlers.* config.
            // Safe defaults (private + non-shared) keep handlers off the public
            // service API and produce a fresh instance per dispatch — required
            // for long-running runtimes (RoadRunner, FrankenPHP, Swoole) where
            // mutable handler state would otherwise leak between requests.
            $def->setPublic($handlersPublic);
            $def->setShared($handlersShared);
        }

        ksort($methods);

        // Catch the easy misconfiguration: a method declares #[Rpc\Stream] but
        // the operator left routes.stream.enabled at the (default) false. The
        // method would silently respond 404 on /rpc/stream at runtime. Fail
        // loudly at container build instead.
        if (!(bool) $container->getParameter('json_rpc_server.routes.stream.enabled')) {
            $streamMethods = array_keys(array_filter($methods, static fn (array $m): bool => (bool) $m['isStreaming']));
            if ([] !== $streamMethods) {
                throw new \LogicException(\sprintf(
                    'json_rpc_server.routes.stream.enabled is false, but the following methods carry #[Rpc\\Stream]: %s. Either set routes.stream.enabled: true to serve them on /rpc/stream, or remove the attribute.',
                    implode(', ', $streamMethods),
                ));
            }
        }

        $registry = $container->getDefinition(MethodRegistry::class);
        $registry->setArgument(0, $methods);
        $registry->setArgument(1, new ServiceLocatorArgument($handlerRefs));

        if ($container->hasDefinition(CacheChecker::class)) {
            $container->getDefinition(CacheChecker::class)
                ->setArgument('$scopes', new ServiceLocatorArgument($scopeRefs));
        }

        // Parser hard cap = max of global default and every per-method override.
        // Methods without #[Rpc\MaxRequestSize] still use the global value (the
        // per-method limit is applied after parse, in RpcController).
        //
        // When the global default is 0 (uncapped), the parser must stay uncapped
        // as well — otherwise a single method declaring #[Rpc\MaxRequestSize]
        // would silently cap every OTHER method to its own limit at the parser
        // stage, before the per-method check ever runs.
        $globalDefault = (int) $container->getParameter('json_rpc_server.max_request_size');
        if ($globalDefault <= 0) {
            $parserCap = 0;
        } else {
            $parserCap = $globalDefault;
            foreach ($methods as $m) {
                if (null !== $m['maxRequestSize'] && $m['maxRequestSize'] > $parserCap) {
                    $parserCap = $m['maxRequestSize'];
                }
            }
        }
        $container->setParameter('json_rpc_server.parser_cap', $parserCap);
    }

    /**
     * Assembles the MCP `tools/list[].annotations` object from the
     * `#[Rpc\Mcp]` attribute, applying auto-derivation rules where the user
     * did not pin a value:
     *
     *   - `#[Rpc\Cache]` present → readOnlyHint=true and idempotentHint=true.
     *     A cached method is, by definition, a function of its arguments
     *     (otherwise caching by-arg would be unsafe) and must not mutate
     *     observable state during a hit — both flags follow from caching.
     *
     * Explicit booleans on the attribute always win — auto-derive only fills
     * `null` slots. The returned map omits null keys so the controller can
     * emit it verbatim into `annotations: {...}` (or skip the key entirely
     * when the map is empty).
     *
     * @return array<string, bool|string>
     */
    private function resolveMcpAnnotations(?RpcMcp $mcp, ?RpcCache $cache): array
    {
        $out = [];

        $title = $mcp?->title;
        if (null !== $title && '' !== $title) {
            $out['title'] = $title;
        }

        $cached = null !== $cache;
        $readOnly = $mcp?->readOnlyHint;
        if (null === $readOnly && $cached) {
            $readOnly = true;
        }
        $idempotent = $mcp?->idempotentHint;
        if (null === $idempotent && $cached) {
            $idempotent = true;
        }

        if (null !== $readOnly) {
            $out['readOnlyHint'] = $readOnly;
        }
        if (null !== $mcp?->destructiveHint) {
            $out['destructiveHint'] = $mcp->destructiveHint;
        }
        if (null !== $idempotent) {
            $out['idempotentHint'] = $idempotent;
        }
        if (null !== $mcp?->openWorldHint) {
            $out['openWorldHint'] = $mcp->openWorldHint;
        }

        return $out;
    }

    /**
     * Decides what ships as the method's response schema in MCP `tools/list`
     * and OpenRPC `result.schema`. Precedence:
     *   1. Explicit `#[Rpc\Method(outputSchema: ClassName::class)]`  → JsonSchemaBuilder::fromClass
     *   2. Explicit `#[Rpc\Method(outputSchema: ['type' => …])]`     → used as-is
     *   3. Otherwise derived from `__invoke()`'s return type:
     *      scalar → `{type: …}`; class/enum → JsonSchemaBuilder::fromClass;
     *      `array`/`mixed`/`void`/missing → no schema (empty array — caller
     *      drops it so consumers see "no advertised shape" instead of a
     *      meaningless `{type: array}` placeholder).
     *
     * @param class-string|array<string, mixed>|null $override
     *
     * @return array<string, mixed>
     */
    private function resolveOutputSchema(
        string|array|null $override,
        ?string $returnType,
        JsonSchemaBuilder $builder,
        string $methodName,
        string $class,
    ): array {
        if (\is_array($override)) {
            return $override;
        }
        if (\is_string($override)) {
            if (!class_exists($override) && !enum_exists($override)) {
                throw new \LogicException(\sprintf(
                    'RPC method %s (%s): #[Rpc\\Method(outputSchema: …)] must be a class-string or a JSON Schema array; "%s" is not a known class or enum.',
                    $methodName,
                    $class,
                    $override,
                ));
            }

            return $builder->fromClass($override);
        }

        return match ($returnType) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            null, 'void', 'mixed', 'array' => [],
            default => class_exists($returnType) || enum_exists($returnType)
                ? $builder->fromClass($returnType)
                : [],
        };
    }

    /**
     * Builds the minimum MethodMetadata that JsonSchemaBuilder::fromMethod
     * needs (just the parameter list). Used at compile time only — the full
     * MethodMetadata is hydrated by MethodRegistry from the raw array.
     *
     * @param array<string, mixed> $raw
     * @param list<array<string, mixed>> $rawParameters
     */
    private function stubMetadata(array $raw, array $rawParameters): MethodMetadata
    {
        $parameters = array_map(
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
                // Reuse the same constraint rehydration logic the runtime uses
                // so the precomputed schema sees Length/Range/Positive/etc.
                constraints: $this->rehydrateConstraints($p['constraints'] ?? []),
                dtoOwnKeys: $p['dtoOwnKeys'] ?? [],
            ),
            $rawParameters,
        );

        return new MethodMetadata(
            name: $raw['name'],
            serviceClass: $raw['serviceClass'],
            roles: $raw['roles'],
            description: $raw['description'],
            parameters: $parameters,
            returnType: $raw['returnType'],
            isStreaming: $raw['isStreaming'],
            streamFormat: null,
        );
    }

    /**
     * Mirrors MethodRegistry::buildConstraints. Used at compile time to feed
     * JsonSchemaBuilder with real Constraint instances so the precomputed
     * schema includes constraint-derived fields (minLength, exclusiveMinimum, …).
     *
     * @param list<array{class: class-string<Constraint>, args: array<array-key, mixed>}> $raw
     *
     * @return list<Constraint>
     */
    private function rehydrateConstraints(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $class = $entry['class'];
            if (!is_subclass_of($class, Constraint::class)) {
                continue;
            }
            try {
                $out[] = new $class(...$entry['args']);
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<object> $r
     * @param class-string<T> $attr
     *
     * @return T|null
     */
    private function firstAttribute(\ReflectionClass $r, string $attr): ?object
    {
        $attrs = $r->getAttributes($attr);

        return $attrs ? $attrs[0]->newInstance() : null;
    }

    /**
     * Resolution precedence (first match wins):
     *   1. attribute carries explicit roles  → use as-is
     *   2. name listed in public_methods     → public (operator allow)
     *   3. name matches a public_prefixes    → public (operator allow)
     *   4. name matches a prefix_roles entry → use those roles (longest prefix wins)
     *   5. default_roles is non-empty        → apply default
     *   6. otherwise                         → public (historical behavior)
     *
     * @param list<string> $attributeRoles
     * @param list<string> $defaultRoles
     * @param list<string> $publicPrefixes
     * @param array<string, true> $publicMethodsIndex
     * @param array<string, list<string>> $prefixRoles already sorted by prefix length DESC
     *
     * @return list<string>
     */
    private function resolveEffectiveRoles(
        string $methodName,
        array $attributeRoles,
        array $defaultRoles,
        array $publicPrefixes,
        array $publicMethodsIndex,
        array $prefixRoles,
    ): array {
        if ([] !== $attributeRoles) {
            return $attributeRoles;
        }
        if (isset($publicMethodsIndex[$methodName])) {
            return [];
        }
        foreach ($publicPrefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($methodName, $prefix)) {
                return [];
            }
        }
        foreach ($prefixRoles as $prefix => $roles) {
            if ('' !== $prefix && str_starts_with($methodName, $prefix)) {
                return $roles;
            }
        }

        return $defaultRoles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildParameters(\ReflectionMethod $invoke, string $methodName, string $class): array
    {
        $out = [];
        foreach ($invoke->getParameters() as $p) {
            $type = $p->getType();

            if (null !== $type && !$type instanceof \ReflectionNamedType) {
                throw new \LogicException(\sprintf('RPC method %s (%s): parameter $%s uses an unsupported union or intersection type. Use a single named type, "array", or "mixed".', $methodName, $class, $p->getName()));
            }

            $typeName = $type?->getName();
            $isBuiltin = $type instanceof \ReflectionNamedType ? $type->isBuiltin() : false;

            $isContext = Context::class === $typeName;
            $isHttpRequest = HttpRequest::class === $typeName;
            $isRpcRequest = RpcRequest::class === $typeName;
            $isDto = !$isContext
                && !$isHttpRequest
                && !$isRpcRequest
                && null !== $typeName
                && !$isBuiltin;

            $paramAttrInstances = $p->getAttributes(RpcParam::class);
            $paramAttr = $paramAttrInstances ? $paramAttrInstances[0]->newInstance() : null;

            // Auto-promote: a non-injected, non-DTO parameter (i.e. a builtin
            // scalar / mixed / untyped) is treated as if it carried #[Rpc\Param]
            // so it shows up in inputSchema. Without this the parameter still
            // resolves at runtime (scalar branch reads $named[lookupKey]) but
            // MCP clients never see it — silent schema/runtime drift.
            $isInjected = $isContext || $isHttpRequest || $isRpcRequest;
            $autoPromoted = null === $paramAttr && !$isInjected && !$isDto;

            $dtoOwnKeys = $isDto && class_exists((string) $typeName)
                ? $this->dtoCtorKeys((string) $typeName)
                : [];

            $out[] = [
                'name' => $p->getName(),
                'type' => $typeName,
                'isContext' => $isContext,
                'isDto' => $isDto,
                'hasDefault' => $p->isDefaultValueAvailable(),
                'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                'allowsNull' => $type?->allowsNull() ?? true,
                'isHttpRequest' => $isHttpRequest,
                'isRpcRequest' => $isRpcRequest,
                'hasParamAttribute' => null !== $paramAttr || $autoPromoted,
                'jsonName' => $paramAttr?->name,
                'paramRequired' => null !== $paramAttr ? $paramAttr->required : true,
                'constraints' => $this->collectConstraints($p),
                'dtoOwnKeys' => $dtoOwnKeys,
            ];
        }

        $this->assertNoKeyConflicts($methodName, $class, $out);

        return $out;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function dtoCtorKeys(string $class): array
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if (null === $ctor) {
            return [];
        }

        return array_map(static fn (\ReflectionParameter $p) => $p->getName(), $ctor->getParameters());
    }

    /**
     * Enforces that every JSON key in the flat root params object is owned by
     * exactly one __invoke parameter — either a DTO's ctor field, or a scalar
     * Rpc\Param (named or auto-promoted). Without this guard, two DTOs (or
     * DTO + scalar) sharing a key would silently double-resolve, with
     * unpredictable last-writer-wins semantics depending on declaration order.
     *
     * @param list<array<string, mixed>> $params
     */
    private function assertNoKeyConflicts(string $methodName, string $class, array $params): void
    {
        $owner = [];
        foreach ($params as $p) {
            if (true === ($p['isContext'] ?? false)
                || true === ($p['isHttpRequest'] ?? false)
                || true === ($p['isRpcRequest'] ?? false)
            ) {
                continue;
            }

            $keys = true === ($p['isDto'] ?? false)
                ? ($p['dtoOwnKeys'] ?? [])
                : [$p['jsonName'] ?? $p['name']];

            foreach ($keys as $key) {
                if (isset($owner[$key])) {
                    throw new \LogicException(\sprintf(
                        'RPC method %s (%s): parameter "$%s" claims JSON key "%s" already owned by parameter "$%s". Two DTOs (or DTO + scalar) cannot share a top-level key — rename a ctor argument or use #[Rpc\\Param(name: ...)] to disambiguate.',
                        $methodName,
                        $class,
                        $p['name'],
                        $key,
                        $owner[$key],
                    ));
                }
                $owner[$key] = $p['name'];
            }
        }
    }

    /**
     * Returns a serializable description of each Validator constraint attached
     * to the parameter — `{class, args}`. The container compiler dumps
     * parameters via var_export and refuses opaque objects, so we cannot ship
     * constructed instances. Hydration in `MethodRegistry` recreates them.
     *
     * Most Symfony Validator constraints are declared with
     * `Attribute::TARGET_PROPERTY | TARGET_METHOD`, omitting TARGET_PARAMETER —
     * `$attr->newInstance()` would refuse to construct them on a parameter.
     * Reading raw `getArguments()` sidesteps the check.
     *
     * @return list<array{class: class-string<Constraint>, args: array<array-key, mixed>}>
     */
    private function collectConstraints(\ReflectionParameter $param): array
    {
        $out = [];
        foreach ($param->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var class-string<Constraint> $class */
            $class = $attr->getName();
            $out[] = ['class' => $class, 'args' => $attr->getArguments()];
        }

        return $out;
    }
}
