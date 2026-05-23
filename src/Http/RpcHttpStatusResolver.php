<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Http;

use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Exception\NotFoundException;
use Knetesin\JsonRpcServerBundle\Exception\RateLimitExceededException;
use Knetesin\JsonRpcServerBundle\Exception\RequestTooLargeException;
use Knetesin\JsonRpcServerBundle\Exception\RpcException;

/**
 * Maps JSON-RPC failures to HTTP status codes for {@see \Knetesin\JsonRpcServerBundle\Controller\RpcController}.
 *
 * When {@code mapHttpStatus} is false (default), only {@code 413} is elevated for oversized
 * bodies; every other error stays {@code 200} with the canonical {@code error.code} in the body.
 */
final class RpcHttpStatusResolver
{
    public function statusForException(RpcException $e, bool $mapHttpStatus): int
    {
        if ($e instanceof RequestTooLargeException) {
            return 413;
        }

        return $mapHttpStatus ? $this->httpFromRpcCode($e->rpcCode()) : 200;
    }

    /**
     * @param array<string, mixed> $envelope JSON-RPC response object (result or error)
     */
    public function statusForEnvelope(array $envelope, bool $mapHttpStatus): int
    {
        if (!isset($envelope['error']) || !\is_array($envelope['error'])) {
            return 200;
        }

        $http = $this->httpFromEnvelope($envelope);
        if (413 === $http || $mapHttpStatus) {
            return $http;
        }

        return 200;
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    public function statusForResponses(array $responses, bool $mapHttpStatus): int
    {
        $status = 200;
        foreach ($responses as $response) {
            $status = max($status, $this->statusForEnvelope($response, $mapHttpStatus));
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function httpFromEnvelope(array $envelope): int
    {
        /** @var array{code?: int|string, message?: string} $error */
        $error = $envelope['error'];
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? '');

        if (RpcException::INVALID_REQUEST === $code && str_contains($message, 'too large')) {
            return 413;
        }

        return $this->httpFromRpcCode($code);
    }

    private function httpFromRpcCode(int $code): int
    {
        return match ($code) {
            RpcException::PARSE_ERROR,
            RpcException::INVALID_REQUEST,
            RpcException::INVALID_PARAMS,
            AccessDeniedException::DEFAULT_CODE => 400,
            RpcException::METHOD_NOT_FOUND,
            NotFoundException::DEFAULT_CODE => 404,
            RateLimitExceededException::DEFAULT_CODE => 429,
            RpcException::INTERNAL_ERROR => 500,
            default => $code >= RpcException::SERVER_ERROR_MIN && $code <= RpcException::SERVER_ERROR_MAX
                ? 400
                : 500,
        };
    }
}
