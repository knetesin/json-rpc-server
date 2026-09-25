<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Guard;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupAccessDeniedException;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Allows a #[RequiresGroup] method only when the group id taken from the
 * arguments is listed in the comma-separated `X-Allowed-Groups` header.
 * Inert for methods without the attribute.
 */
final class GroupGuard implements MethodGuardInterface
{
    /** @var list<string> method names in call order; reset by tests */
    public static array $calls = [];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void
    {
        self::$calls[] = $meta->name;

        $header = (string) $this->requestStack->getMainRequest()?->headers->get('X-Allowed-Groups', '');
        $allowed = array_filter(array_map('trim', explode(',', $header)), static fn (string $id): bool => '' !== $id);

        foreach ($meta->getAttributes(RequiresGroup::class) as $requirement) {
            $groupId = $this->groupId($args, $requirement->on);
            if (null === $groupId || !\in_array((string) $groupId, $allowed, true)) {
                throw new GroupAccessDeniedException($groupId, $requirement->permission);
            }
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function groupId(array $args, string $on): int|string|null
    {
        $value = $args[$on] ?? null;
        if (null === $value) {
            foreach ($args as $arg) {
                if (\is_object($arg) && property_exists($arg, $on)) {
                    $value = $arg->{$on};
                    break;
                }
            }
        }

        return \is_int($value) || \is_string($value) ? $value : null;
    }
}
