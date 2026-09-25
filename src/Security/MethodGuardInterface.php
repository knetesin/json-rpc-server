<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Security;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

/**
 * Per-method access check that sees the resolved arguments — for rules roles
 * cannot express, such as "the caller may edit THIS group".
 *
 * The dispatcher runs every guard on every invocation (single call, batch
 * item, notification, stream, MCP `tools/call`): after the role and rate-limit
 * checks and argument resolution, before the cache lookup and the handler.
 * A cache hit therefore never skips a guard.
 *
 * Throw to deny. An {@see \Knetesin\JsonRpcServerBundle\Exception\RpcException}
 * reaches the client with its own code, message and data; any other exception
 * becomes -32603 Internal error. Both fire MethodInvocationFailedEvent.
 *
 * Implementations are auto-tagged `json_rpc_server.method_guard` — just
 * implement the interface in a service. Higher tag `priority` runs first
 * (e.g. `#[AsTaggedItem(priority: 10)]`); the first exception stops the chain.
 *
 * The handler's class-level attributes are available without reflection via
 * {@see MethodMetadata::getAttributes()}.
 */
interface MethodGuardInterface
{
    /**
     * @param array<string, mixed> $args resolved `__invoke()` arguments keyed by
     *                                   parameter name, in declaration order — the
     *                                   same instances the handler receives, so
     *                                   treat them as read-only
     */
    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void;
}
