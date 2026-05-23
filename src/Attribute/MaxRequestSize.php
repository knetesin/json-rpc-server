<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

/**
 * Overrides the per-method request body limit. Use it to allow a single
 * `file.upload`-style method to accept a larger payload than the bundle's
 * `json_rpc_server.max_request_size` default.
 *
 * The parser's hard cap is raised at compile time to match the largest
 * value present across all `#[Rpc\MaxRequestSize]` attributes — so the
 * raw body is allowed through, then the per-method limit is enforced
 * after parsing. Methods without this attribute use the bundle-level
 * default unchanged.
 *
 * Only effective for single-request bodies. Batch payloads always use
 * the bundle default, because the parser must reject oversize bodies
 * before knowing which methods are inside.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MaxRequestSize
{
    public function __construct(public readonly int $bytes)
    {
    }
}
