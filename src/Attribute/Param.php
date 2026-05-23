<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

/**
 * Marks an `__invoke()` parameter as a JSON-RPC param. Use when you do not
 * want a full DTO — a method with one or two scalar inputs reads cleaner as:
 *
 *   public function __invoke(
 *       #[Rpc\Param('user_id')] #[Assert\Positive] int $userId,
 *       #[Rpc\Param('reason', required: false)] ?string $reason,
 *       Context $ctx,
 *   ): array { ... }
 *
 * Effects:
 *   - `name`     overrides the JSON key used to look up the value
 *     (`user_id` ↔ `$userId`). Default is the PHP parameter name.
 *   - Symfony Validator constraints declared on the same parameter
 *     (`#[Assert\Positive]`, `#[Assert\Email]`, …) are evaluated and
 *     surfaced as `-32602 Invalid params` violations.
 *   - The parameter shows up in the method's MCP `inputSchema` — so tools
 *     declared without a DTO are still discoverable by LLM clients.
 *
 * Auto-promotion: a bare builtin/mixed/untyped `__invoke()` parameter (i.e.
 * not Context / RpcRequest / HttpRequest / a DTO class) is treated AS IF it
 * carried `#[Rpc\Param]` — JSON key = PHP name, required driven by the
 * signature. Put the attribute explicitly only when you need a custom `name`
 * or want to hint `required: false` to the JSON Schema.
 *
 * `required` is informational for the JSON Schema only. Whether the param
 * is actually mandatory is driven by the PHP signature (default value or
 * nullable type makes it optional).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Param
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $required = true,
    ) {
    }
}
