<?php

declare(strict_types=1);

namespace JsonRpcServer\DependencyInjection\Compiler;

use JsonRpcServer\Attribute\Cache as RpcCache;
use JsonRpcServer\Attribute\MaxRequestSize as RpcMaxRequestSize;
use JsonRpcServer\Attribute\Mcp as RpcMcp;
use JsonRpcServer\Attribute\Method as RpcMethod;
use JsonRpcServer\Attribute\Param as RpcParam;
use JsonRpcServer\Attribute\RateLimit as RpcRateLimit;
use JsonRpcServer\Attribute\RoleMatch;
use JsonRpcServer\Attribute\Stream as RpcStream;
use JsonRpcServer\Cache\CacheChecker;
use JsonRpcServer\Cache\Scope\IpScope;
use JsonRpcServer\Cache\Scope\UserScope;
use JsonRpcServer\Context\Context;
use JsonRpcServer\Mcp\JsonSchemaBuilder;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Registry\MethodRegistry;
use JsonRpcServer\Registry\ParameterMetadata;
use JsonRpcServer\Request\RpcRequest;
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
        $defaultAllowPositionalDto = (bool) $container->getParameter('json_rpc_server.params.allow_positional_dto');
        $defaultRejectUnknown = (bool) $container->getParameter('json_rpc_server.params.reject_unknown');
        $datetimeFormatParam = $container->hasParameter('json_rpc_server.serializer.datetime_format')
            ? $container->getParameter('json_rpc_server.serializer.datetime_format')
            : 'iso8601';
        $schemaMaxDepth = $container->hasParameter('json_rpc_server.mcp.schema_max_depth')
            ? (int) $container->getParameter('json_rpc_server.mcp.schema_max_depth')
            : 6;
        $schemaBuilder = new JsonSchemaBuilder(
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
     *   4. default_roles is non-empty        → apply default
     *   5. otherwise                         → public (historical behavior)
     *
     * @param list<string>        $attributeRoles
     * @param list<string>        $defaultRoles
     * @param list<string>        $publicPrefixes
     * @param array<string, true> $publicMethodsIndex
     *
     * @return list<string>
     */
    private function resolveEffectiveRoles(
        string $methodName,
        array $attributeRoles,
        array $defaultRoles,
        array $publicPrefixes,
        array $publicMethodsIndex,
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
                'hasParamAttribute' => null !== $paramAttr,
                'jsonName' => $paramAttr?->name,
                'paramRequired' => null !== $paramAttr ? $paramAttr->required : true,
                'constraints' => $this->collectConstraints($p),
            ];
        }

        return $out;
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
