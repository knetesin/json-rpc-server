<?php

declare(strict_types=1);

namespace JsonRpcServer\Controller;

use JsonRpcServer\OpenRpc\OpenRpcDocumentBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Exposes the bundle's OpenRPC document at a fixed route (default
 * `/rpc.openrpc.json`) so RPC clients can discover the API at runtime —
 * generate their typed wrappers, validate payloads, render docs.
 *
 * Title / version / description come from `json_rpc_server.openrpc.*`.
 * The route itself is enabled by default but can be turned off (e.g. in
 * production deployments that don't want to ship a discoverable spec) via
 * `json_rpc_server.routes.openrpc.enabled: false`.
 */
final class OpenRpcController
{
    public function __construct(
        private readonly OpenRpcDocumentBuilder $builder,
        private readonly string $title = 'JSON-RPC API',
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $document = $this->builder->build($this->title, $this->version, $this->description);

        $response = new JsonResponse($document);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        // Long Cache-Control: false. The document tracks the running code,
        // so a redeploy can change it. Clients should fetch fresh on demand.
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }
}
