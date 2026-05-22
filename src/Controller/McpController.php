<?php

declare(strict_types=1);

namespace JsonRpcServer\Controller;

use JsonRpcServer\Attribute\McpFormat;
use JsonRpcServer\Dispatcher\Dispatcher;
use JsonRpcServer\Exception\InternalErrorException;
use JsonRpcServer\Exception\InvalidRequestException;
use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\ParseException;
use JsonRpcServer\Exception\RateLimitExceededException;
use JsonRpcServer\Exception\RequestTooLargeException;
use JsonRpcServer\Exception\RpcErrorEnvelope;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Mcp\McpResultFormatter;
use JsonRpcServer\Mcp\McpResultTransformer;
use JsonRpcServer\Mcp\McpToolRegistry;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcParams;
use JsonRpcServer\Request\RpcRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * MCP transport: list tools (`/mcp/tools`) and invoke them (`/mcp/call`).
 *
 * Error envelope is uniform across both transport and business failures:
 *
 *   { "isError": true,
 *     "error":   { "code": ..., "message": "...", "data": ... },
 *     "content": [{ "type": "text", "text": "Error CODE: message\n  - path: violation" }] }
 *
 * HTTP status differentiates classes of failure:
 *   - 400 for malformed input (parse, missing/invalid fields, unknown format)
 *   - 404 when the named tool is not registered or not exposed
 *   - 413 when the request body exceeds the size limit
 *   - 200 for everything that came out of the dispatcher (auth, rate limit,
 *     invalid params, internal error) — MCP convention for tool execution
 *     errors. The body's `isError: true` is what the client should check.
 *
 * The format used to render successful results is resolved per request, in
 * priority order:
 *   1. `X-Mcp-Format` request header
 *   2. `?format=` query parameter
 *   3. `#[Rpc\Mcp(format: ...)]` attribute
 *   4. `json_rpc_server.mcp.default_format` bundle config
 *
 * If the method's handler implements McpResultTransformer, that hook runs
 * after `__invoke` but before normalization — useful for stripping internal
 * fields not meant for the LLM.
 */
final class McpController
{
    public const string DEFAULT_FORMAT_HEADER = 'X-Mcp-Format';
    public const string DEFAULT_FORMAT_QUERY = 'format';

    private readonly int $jsonFlags;

    /**
     * @param positive-int $maxJsonDepth
     */
    public function __construct(
        private readonly McpToolRegistry $tools,
        private readonly Dispatcher $dispatcher,
        private readonly McpResultFormatter $formatter,
        private readonly LoggerInterface $logger,
        private readonly bool $applyRateLimit = false,
        private readonly int $defaultMaxRequestSize = 0,
        private readonly int $maxJsonDepth = 32,
        ?int $jsonEncodeFlags = null,
        /** HTTP header name read for the per-request MCP format override. */
        private readonly string $formatHeader = self::DEFAULT_FORMAT_HEADER,
        /** Query-string parameter name read for the per-request MCP format override. */
        private readonly string $formatQuery = self::DEFAULT_FORMAT_QUERY,
    ) {
        $this->jsonFlags = ($jsonEncodeFlags ?? RpcController::DEFAULT_JSON_FLAGS) | \JSON_THROW_ON_ERROR;
    }

    public function tools(): JsonResponse
    {
        return $this->json(['tools' => $this->tools->getTools()]);
    }

    public function call(Request $request): JsonResponse
    {
        $body = $request->getContent();
        try {
            [$name, $args] = $this->parseInvocation($body);
            $meta = $this->resolveTool($name);
            $this->enforceBodyLimit($meta, $body);
            $format = $this->resolveFormat($request, $meta);
        } catch (RpcException $e) {
            return $this->errorResponse($e, $this->transportStatus($e));
        }

        try {
            $envelope = new RpcRequest(
                id: null,
                method: $name,
                params: new RpcParams($args),
                isNotification: false,
            );
            // Dispatcher already normalizes — transformer sees plain data,
            // never a raw DTO. See {@see McpResultTransformer} for the contract.
            $normalized = $this->dispatcher->call($envelope, applyRateLimit: $this->applyRateLimit);
            $normalized = $this->maybeTransform($name, $normalized);
            $content = $this->formatter->format($normalized, $format, $meta);

            $response = ['content' => $content];
            if (!\is_scalar($normalized) && null !== $normalized) {
                $response['structuredContent'] = $normalized;
            }

            return $this->json($response);
        } catch (RpcException $e) {
            return $this->errorResponse($e, 200);
        } catch (\Throwable $e) {
            $this->logger->error('MCP call failure', ['tool' => $name, 'exception' => $e]);

            return $this->errorResponse(new InternalErrorException(previous: $e), 200);
        }
    }

    private function json(mixed $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions($this->jsonFlags);

        return $response;
    }

    /**
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    private function parseInvocation(string $body): array
    {
        if ('' === $body) {
            throw new InvalidRequestException('Request body is empty');
        }
        try {
            $decoded = json_decode($body, true, $this->maxJsonDepth, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ParseException($e->getMessage(), $e);
        }
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidRequestException('Request must be a JSON object');
        }

        if (!\array_key_exists('name', $decoded)) {
            throw new InvalidRequestException('Field "name" is required');
        }
        $name = $decoded['name'];
        if (!\is_string($name) || '' === $name) {
            throw new InvalidRequestException('Field "name" must be a non-empty string');
        }

        $args = $decoded['arguments'] ?? [];
        if (!\is_array($args)) {
            throw new InvalidRequestException('"arguments" must be an object or an array');
        }

        return [$name, $args];
    }

    /**
     * Mirrors RpcController::checkPerMethodLimit. The per-method limit is
     * a tool-specific contract — without this check, a method that declares
     * #[Rpc\MaxRequestSize(1024)] is silently uncapped on /mcp/call.
     */
    private function enforceBodyLimit(MethodMetadata $meta, string $body): void
    {
        $limit = $meta->maxRequestSize ?? $this->defaultMaxRequestSize;
        if ($limit <= 0) {
            return;
        }
        $size = \strlen($body);
        if ($size > $limit) {
            throw new RequestTooLargeException($size, $limit);
        }
    }

    private function resolveTool(string $name): MethodMetadata
    {
        if (!$this->tools->hasTool($name)) {
            throw new MethodNotFoundException($name);
        }

        return $this->dispatcher->metadata($name);
    }

    private function resolveFormat(Request $request, MethodMetadata $method): McpFormat
    {
        $value = $request->headers->get($this->formatHeader) ?? $request->query->get($this->formatQuery);
        if (null !== $value && '' !== $value) {
            $resolved = McpFormat::tryFrom($value);
            if (null === $resolved) {
                $allowed = implode(', ', array_map(static fn (McpFormat $f) => $f->value, McpFormat::cases()));
                throw new InvalidRequestException(\sprintf('Unknown MCP format "%s". Allowed: %s.', $value, $allowed));
            }

            return $resolved;
        }

        return $method->mcpFormat;
    }

    private function maybeTransform(string $name, mixed $result): mixed
    {
        $handler = $this->dispatcher->handler($name);
        if ($handler instanceof McpResultTransformer) {
            return $handler->transformMcpResult($result);
        }

        return $result;
    }

    private function errorResponse(RpcException $e, int $status): JsonResponse
    {
        $response = $this->json(RpcErrorEnvelope::mcp($e), $status);
        if ($e instanceof RateLimitExceededException && null !== $e->retryAfter) {
            $response->headers->set('Retry-After', (string) max(0, $e->retryAfter));
        }

        return $response;
    }

    private function transportStatus(RpcException $e): int
    {
        return match (true) {
            $e instanceof MethodNotFoundException => 404,
            $e instanceof RequestTooLargeException => 413,
            default => 400,
        };
    }
}
