<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

/**
 * Base class for any exception that should be surfaced to the client as a
 * JSON-RPC `error` object.
 *
 * Code ranges (per JSON-RPC 2.0 §5.1):
 *   -32700           Parse error
 *   -32600           Invalid Request
 *   -32601           Method not found
 *   -32602           Invalid params
 *   -32603           Internal error
 *   -32000..-32099   Server-defined errors — pick your own.
 *
 * The defaults shipped with the bundle (AccessDeniedException = -32001,
 * NotFoundException = -32002) are just sensible starting points. They are
 * fully overridable via the constructor; you are also free to define your
 * own subclasses with whatever codes your protocol contract expects.
 */
abstract class RpcException extends \RuntimeException
{
    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;

    /**
     * Server-defined error range per JSON-RPC 2.0 §5.1. Naming follows numeric
     * ordering — `MIN` is the smallest (most negative) value, `MAX` is the
     * largest (least negative). Any code in `[MIN; MAX]` is yours to assign.
     */
    public const int SERVER_ERROR_MIN = -32099;
    public const int SERVER_ERROR_MAX = -32000;

    abstract public function rpcCode(): int;

    public function rpcData(): mixed
    {
        return null;
    }
}
