<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

/**
 * Builds the JSON-RPC `error` object and its surrounding envelopes.
 *
 * Three flavours are produced from the same exception:
 *   - `error()`        — bare {code, message, data?} used as the inner payload
 *                        of any envelope.
 *   - `jsonRpc()`      — {jsonrpc: "2.0", error: ..., id: ...} — the canonical
 *                        envelope returned by `/rpc` and by pre-stream errors
 *                        on `/rpc/stream`.
 *   - `mcp()`          — {isError: true, error: ..., content: [text]} per MCP
 *                        convention. The text block restates the error so LLM
 *                        clients that only consume `content` still see it.
 */
final class RpcErrorEnvelope
{
    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public static function error(RpcException $e): array
    {
        $error = [
            'code' => $e->rpcCode(),
            'message' => $e->getMessage(),
        ];
        $data = $e->rpcData();
        if (null !== $data) {
            $error['data'] = $data;
        }

        return $error;
    }

    /**
     * @return array{jsonrpc: string, error: array{code: int, message: string, data?: mixed}, id: string|int|null}
     */
    public static function jsonRpc(string|int|null $id, RpcException $e): array
    {
        return [
            'jsonrpc' => '2.0',
            'error' => self::error($e),
            'id' => $id,
        ];
    }

    /**
     * @return array{isError: true, error: array{code: int, message: string, data?: mixed}, content: list<array{type: string, text: string}>}
     */
    public static function mcp(RpcException $e): array
    {
        $error = self::error($e);

        return [
            'isError' => true,
            'error' => $error,
            'content' => [['type' => 'text', 'text' => self::mcpErrorText($error)]],
        ];
    }

    /**
     * @param array{code: int, message: string, data?: mixed} $error
     */
    private static function mcpErrorText(array $error): string
    {
        $text = \sprintf('Error %d: %s', $error['code'], $error['message']);
        $data = $error['data'] ?? null;
        if (\is_array($data) && self::looksLikeViolationList($data)) {
            foreach ($data as $v) {
                $path = $v['path'] ?? '';
                $msg = $v['message'] ?? '';
                $text .= "\n  - ".('' !== $path ? $path.': ' : '').$msg;
            }
        }

        return $text;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function looksLikeViolationList(array $data): bool
    {
        if ([] === $data || !array_is_list($data)) {
            return false;
        }
        foreach ($data as $row) {
            if (!\is_array($row) || !isset($row['message'])) {
                return false;
            }
        }

        return true;
    }
}
