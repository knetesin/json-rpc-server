<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Validates method guards and hands handler class attributes to them through
 * the MethodRegistry raw data. Registered as an optimization pass so it sees
 * every guard the Dispatcher's tagged iterator receives, including guards
 * tagged by late compiler passes.
 */
final class MethodGuardPass implements CompilerPassInterface
{
    public const string TAG = 'json_rpc_server.method_guard';

    /** Attribute namespaces consumed at build time, never passed to guards. */
    private const array SKIPPED_ATTRIBUTE_NAMESPACES = [
        'Knetesin\\JsonRpcServerBundle\\Attribute\\',
        'Symfony\\Component\\DependencyInjection\\Attribute\\',
    ];

    public function process(ContainerBuilder $container): void
    {
        $guardIds = array_keys($container->findTaggedServiceIds(self::TAG));
        // Without guards the registry stays exactly as MethodCompilerPass built it.
        if ([] === $guardIds) {
            return;
        }

        foreach ($guardIds as $serviceId) {
            $def = $container->getDefinition($serviceId);
            if ($def->isAbstract()) {
                continue;
            }
            $class = $def->getClass() ?? $serviceId;
            $reflection = $container->getReflectionClass($class, false);
            if (null !== $reflection && !$reflection->implementsInterface(MethodGuardInterface::class)) {
                throw new \LogicException(\sprintf('Service "%s" (%s) is tagged "%s" but does not implement %s.', $serviceId, $class, self::TAG, MethodGuardInterface::class));
            }
        }

        if (!$container->hasDefinition(MethodRegistry::class)) {
            return;
        }
        $registry = $container->getDefinition(MethodRegistry::class);
        $methods = $registry->getArgument(0);
        if (!\is_array($methods)) {
            return;
        }

        foreach ($methods as $name => $method) {
            if (!\is_array($method) || !\is_string($method['serviceClass'] ?? null)) {
                continue;
            }
            $class = $method['serviceClass'];
            $reflection = $container->getReflectionClass($class, false);
            if (null === $reflection) {
                continue;
            }

            $attributes = $this->collectAttributes($reflection, (string) $name, $class);
            if ([] !== $attributes) {
                // Escape "%" so attribute arguments are not read as %parameter%
                // placeholders; the dumper turns "%%" back into "%".
                $methods[$name]['attributes'] = $container->getParameterBag()->escapeValue($attributes);
            }
        }

        $registry->setArgument(0, $methods);
    }

    /**
     * Class-level attributes as `{class, args}` pairs the container can dump;
     * MethodRegistry rebuilds them with `new`. Each one is instantiated here
     * once so a broken attribute fails the build instead of being silently
     * invisible to guards. Attributes whose class is not loaded are skipped,
     * as PHP itself ignores them.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<array{class: class-string, args: array<array-key, mixed>}>
     */
    private function collectAttributes(\ReflectionClass $reflection, string $methodName, string $class): array
    {
        $out = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            foreach (self::SKIPPED_ATTRIBUTE_NAMESPACES as $namespace) {
                if (str_starts_with($name, $namespace)) {
                    continue 2;
                }
            }
            if (!class_exists($name)) {
                continue;
            }

            try {
                $attribute->newInstance();
            } catch (\Throwable $e) {
                throw new \LogicException(\sprintf('RPC method %s (%s): attribute #[%s] cannot be instantiated: %s', $methodName, $class, $name, $e->getMessage()), 0, $e);
            }

            $args = $attribute->getArguments();
            if (!self::isDumpable($args)) {
                throw new \LogicException(\sprintf('RPC method %s (%s): attribute #[%s] must take only scalar, array, enum or null arguments to be available to method guards — the container cannot dump objects.', $methodName, $class, $name));
            }
            // Array keys cannot be escaped, so the container would resolve
            // "%" in them as %parameter% placeholders.
            if (self::hasPercentKey($args)) {
                throw new \LogicException(\sprintf('RPC method %s (%s): attribute #[%s] uses an array key containing "%%" — the container would read it as a %%parameter%% placeholder. Remove "%%" from array keys in the attribute arguments.', $methodName, $class, $name));
            }

            $out[] = ['class' => $name, 'args' => $args];
        }

        return $out;
    }

    private static function isDumpable(mixed $value): bool
    {
        if (null === $value || \is_scalar($value) || $value instanceof \UnitEnum) {
            return true;
        }
        if (!\is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::isDumpable($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hasPercentKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (\is_string($key) && str_contains($key, '%')) {
                return true;
            }
            if (\is_array($item) && self::hasPercentKey($item)) {
                return true;
            }
        }

        return false;
    }
}
