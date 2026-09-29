<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Request;

use Knetesin\JsonRpcServerBundle\Exception\InvalidRequestException;
use Knetesin\JsonRpcServerBundle\Exception\ParseException;
use Knetesin\JsonRpcServerBundle\Exception\RequestTooLargeException;

final class RpcRequestParser
{
    /**
     * @param positive-int $maxJsonDepth
     */
    public function __construct(
        /** Maximum body size in bytes. 0 disables the check. */
        private readonly int $maxRequestSize = 0,
        /** Max JSON nesting depth. Surfaces as ParseException when exceeded. */
        private readonly int $maxJsonDepth = 32,
    ) {
    }

    /**
     * Strict variant of {@see self::parseItems()}: parses a JSON-RPC payload in
     * one pass and returns both the batch flag and the parsed items, so callers
     * don't decode the body twice. Any invalid batch item fails the whole payload.
     *
     * @return array{bool, list<RpcRequest>}
     *
     * @throws ParseException when the body is not valid JSON
     * @throws InvalidRequestException when the payload or any batch item is not a valid request
     * @throws RequestTooLargeException when the body exceeds the parser cap
     */
    public function parse(string $body): array
    {
        [$isBatch, $items] = $this->parseItems($body);

        $requests = [];
        foreach ($items as $item) {
            if ($item instanceof InvalidRequestException) {
                throw $item;
            }
            $requests[] = $item;
        }

        return [$isBatch, $requests];
    }

    /**
     * Parses a JSON-RPC payload, keeping invalid batch items in place instead of
     * failing the whole batch (JSON-RPC 2.0 §6: each invalid item gets its own
     * -32600 response with `id: null`).
     *
     * Payload-level failures still throw: invalid JSON, a body over the parser
     * cap, a non-batch invalid request, and an empty batch `[]`. So an
     * {@see InvalidRequestException} entry can only appear when the batch flag is true.
     *
     * @return array{bool, list<RpcRequest|InvalidRequestException>} batch flag and the items in payload order
     *
     * @throws ParseException when the body is not valid JSON
     * @throws InvalidRequestException when the payload is neither a valid single request nor a non-empty batch
     * @throws RequestTooLargeException when the body exceeds the parser cap
     */
    public function parseItems(string $body): array
    {
        $this->guardSize($body);
        $decoded = $this->decode($body);

        if (!\is_array($decoded)) {
            throw new InvalidRequestException('Request must be an object or an array of objects');
        }

        if ($this->isBatch($decoded)) {
            if ([] === $decoded) {
                throw new InvalidRequestException('Empty batch');
            }

            $items = [];
            foreach ($decoded as $raw) {
                try {
                    $items[] = $this->parseOne($raw);
                } catch (InvalidRequestException $e) {
                    $items[] = $e;
                }
            }

            return [true, $items];
        }

        return [false, [$this->parseOne($decoded)]];
    }

    /**
     * @return list<RpcRequest> when the payload is a batch, returns multiple items
     *
     * @throws ParseException when the body is not valid JSON
     * @throws InvalidRequestException when the payload or any batch item is not a valid request
     * @throws RequestTooLargeException when the body exceeds the parser cap
     */
    public function parseBatch(string $body): array
    {
        return $this->parse($body)[1];
    }

    public function isBatchPayload(string $body): bool
    {
        return $this->parseItems($body)[0];
    }

    private function guardSize(string $body): void
    {
        if ($this->maxRequestSize > 0 && \strlen($body) > $this->maxRequestSize) {
            throw new RequestTooLargeException(\strlen($body), $this->maxRequestSize);
        }
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private function isBatch(array $decoded): bool
    {
        return [] !== $decoded && array_is_list($decoded);
    }

    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, $this->maxJsonDepth, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ParseException($e->getMessage(), $e);
        }
    }

    private function parseOne(mixed $raw): RpcRequest
    {
        if (!\is_array($raw) || array_is_list($raw)) {
            throw new InvalidRequestException('Request item must be an object');
        }

        $jsonrpc = $raw['jsonrpc'] ?? null;
        if ('2.0' !== $jsonrpc) {
            throw new InvalidRequestException('Field "jsonrpc" must equal "2.0"');
        }

        $method = $raw['method'] ?? null;
        if (!\is_string($method) || '' === $method) {
            throw new InvalidRequestException('Field "method" must be a non-empty string');
        }

        $hasId = \array_key_exists('id', $raw);
        $id = $hasId ? $raw['id'] : null;
        if ($hasId && !(null === $id || \is_string($id) || \is_int($id))) {
            throw new InvalidRequestException('Field "id" must be string, integer or null');
        }

        $params = $raw['params'] ?? null;
        if (null !== $params && !\is_array($params)) {
            throw new InvalidRequestException('Field "params" must be an array or object');
        }

        return new RpcRequest(
            id: $id,
            method: $method,
            params: new RpcParams($params),
            isNotification: !$hasId,
        );
    }
}
