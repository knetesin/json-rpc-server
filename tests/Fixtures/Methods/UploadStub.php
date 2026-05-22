<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Request\RpcRequest;

#[Rpc\Method('file.upload')]
#[Rpc\MaxRequestSize(4096)] // 4 KiB — small enough to exercise the limit in tests
#[Rpc\Mcp(description: 'Exercises per-method MaxRequestSize over /mcp/call.')]
final class UploadStub
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(RpcRequest $req): array
    {
        return ['received' => \strlen($req->params->getString('payload', ''))];
    }
}
