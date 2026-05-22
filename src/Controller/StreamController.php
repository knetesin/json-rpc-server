<?php

declare(strict_types=1);

namespace JsonRpcServer\Controller;

use JsonRpcServer\Attribute\StreamFormat;
use JsonRpcServer\Dispatcher\Dispatcher;
use JsonRpcServer\Event\StreamIterationCompletedEvent;
use JsonRpcServer\Event\StreamIterationFailedEvent;
use JsonRpcServer\Event\StreamRowEmittedEvent;
use JsonRpcServer\Exception\InternalErrorException;
use JsonRpcServer\Exception\InvalidRequestException;
use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\RpcErrorEnvelope;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcRequest;
use JsonRpcServer\Request\RpcRequestParser;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * NDJSON / SSE / JSON-array streaming endpoint. This is NOT JSON-RPC 2.0,
 * but a deliberate extension that mirrors the same envelope on input and
 * uses the same error shape for pre-stream failures so clients can reuse
 * their JSON-RPC error parsers.
 *
 * Failure modes:
 *   - errors detected before the iterator starts (parse, method-not-found,
 *     batch>1, method-not-streaming) produce HTTP 4xx/5xx and a plain
 *     JSON-RPC envelope body: { jsonrpc, error: { code, message, data? }, id }
 *   - errors raised mid-iteration cannot change the HTTP status (headers
 *     have already been flushed). An inline error frame is appended in the
 *     active stream format and the response ends cleanly:
 *       NDJSON:    final line   {"error":{...}}
 *       SSE:       event:error  data: {...}
 *       JsonArray: sentinel     [...,{"_error":{...}}]
 */
final class StreamController
{
    /** Defaults disable nginx output-buffering and HTTP caches so each chunk reaches the client immediately. */
    public const array DEFAULT_HEADERS = [
        'X-Accel-Buffering' => 'no',
        'Cache-Control' => 'no-cache',
    ];

    private readonly int $jsonFlags;

    /**
     * @param array<string, string> $extraHeaders extra response headers applied to every streamed response;
     *                                            defaults to {@see self::DEFAULT_HEADERS}
     */
    public function __construct(
        private readonly RpcRequestParser $parser,
        private readonly Dispatcher $dispatcher,
        private readonly NormalizerInterface $normalizer,
        private readonly LoggerInterface $logger,
        ?int $jsonEncodeFlags = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly array $extraHeaders = self::DEFAULT_HEADERS,
    ) {
        // JSON_THROW_ON_ERROR is forced regardless — a silently swallowed
        // encoding error mid-stream would corrupt the NDJSON / SSE payload.
        $this->jsonFlags = ($jsonEncodeFlags ?? RpcController::DEFAULT_JSON_FLAGS) | \JSON_THROW_ON_ERROR;
    }

    public function __invoke(Request $request): Response
    {
        try {
            $items = $this->parser->parseBatch($request->getContent());
        } catch (RpcException $e) {
            return $this->envelopeError(null, $e, 400);
        }

        if (1 !== \count($items)) {
            return $this->envelopeError(null, new InvalidRequestException('Streaming endpoint accepts only a single request'), 400);
        }

        $req = $items[0];

        try {
            $meta = $this->dispatcher->metadata($req->method);
        } catch (MethodNotFoundException $e) {
            return $this->envelopeError($req->id, $e, 404);
        } catch (RpcException $e) {
            return $this->envelopeError($req->id, $e, 400);
        }

        if (!$meta->isStreaming) {
            return $this->envelopeError($req->id, new InvalidRequestException(\sprintf('Method %s is not a streaming method', $req->method)), 400);
        }

        try {
            $iterator = $this->dispatcher->call($req);
        } catch (RpcException $e) {
            return $this->envelopeError($req->id, $e, 400);
        }

        if (!is_iterable($iterator)) {
            $this->logger->error('Streaming method did not return iterable', [
                'method' => $req->method,
                'got' => get_debug_type($iterator),
            ]);

            return $this->envelopeError($req->id, new InternalErrorException(), 500);
        }

        return $this->buildStream($req, $meta, $iterator, $meta->streamFormat ?? StreamFormat::Ndjson);
    }

    /**
     * @param iterable<mixed> $iterator
     */
    private function buildStream(RpcRequest $req, MethodMetadata $meta, iterable $iterator, StreamFormat $format): StreamedResponse
    {
        $contentType = match ($format) {
            StreamFormat::Ndjson => 'application/x-ndjson',
            StreamFormat::Sse => 'text/event-stream',
            StreamFormat::JsonArray => 'application/json',
        };

        $response = new StreamedResponse(function () use ($req, $meta, $iterator, $format) {
            $first = true;
            $rowCount = 0;
            $startedAt = microtime(true);
            if (StreamFormat::JsonArray === $format) {
                echo '[';
            }

            try {
                foreach ($iterator as $row) {
                    $row = $this->normalize($row);
                    $json = json_encode($row, $this->jsonFlags);

                    match ($format) {
                        StreamFormat::Ndjson => print ($json."\n"),
                        StreamFormat::Sse => print ('data: '.$json."\n\n"),
                        StreamFormat::JsonArray => print (($first ? '' : ',').$json),
                    };
                    $first = false;
                    $this->flush();

                    $this->events?->dispatch(new StreamRowEmittedEvent($meta, $row, $rowCount));
                    ++$rowCount;
                }
                $this->events?->dispatch(new StreamIterationCompletedEvent(
                    $meta,
                    $rowCount,
                    microtime(true) - $startedAt,
                ));
            } catch (RpcException $e) {
                $this->events?->dispatch(new StreamIterationFailedEvent(
                    $meta,
                    $e,
                    $rowCount,
                    microtime(true) - $startedAt,
                ));
                $this->writeStreamError($format, $first, $e);
            } catch (\Throwable $e) {
                $this->logger->error('Stream iteration failure', [
                    'method' => $req->method,
                    'exception' => $e,
                ]);
                $this->events?->dispatch(new StreamIterationFailedEvent(
                    $meta,
                    $e,
                    $rowCount,
                    microtime(true) - $startedAt,
                ));
                $this->writeStreamError($format, $first, new InternalErrorException(previous: $e));
            }

            if (StreamFormat::JsonArray === $format) {
                echo ']';
            }
            $this->flush();
        });

        $response->headers->set('Content-Type', $contentType);
        foreach ($this->extraHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function writeStreamError(StreamFormat $format, bool $firstItemInArray, RpcException $e): void
    {
        $error = RpcErrorEnvelope::error($e);
        $payload = ['error' => $error];
        $sentinel = ['_error' => $error];

        $jsonInline = json_encode($payload, $this->jsonFlags);

        match ($format) {
            StreamFormat::Ndjson => print ($jsonInline."\n"),
            StreamFormat::Sse => print ('event: error'."\n".'data: '.$jsonInline."\n\n"),
            StreamFormat::JsonArray => print (
                ($firstItemInArray ? '' : ',').json_encode($sentinel, $this->jsonFlags)
            ),
        };
        $this->flush();
    }

    private function envelopeError(string|int|null $id, RpcException $e, int $status): JsonResponse
    {
        $response = new JsonResponse(RpcErrorEnvelope::jsonRpc($id, $e), $status);
        $response->setEncodingOptions($this->jsonFlags);

        return $response;
    }

    /**
     * Push the current row out to the client immediately.
     *
     * `flush()` alone only drains PHP's SAPI buffer — under PHP-FPM with the
     * default `output_buffering = 4096`, an ob_*() handler intercepts our
     * writes and we have to flush *it* first before flush() can do anything
     * useful. We do not close handlers (no `ob_end_*`) because the test
     * harness — and many integration setups — install their own capture
     * buffer; closing it would break unrelated code.
     *
     * `@` on ob_flush(): some handlers (zlib.output_compression, third-party
     * SSI processors) refuse to flush mid-request and emit a warning. We do
     * not want one of those warnings to abort the stream.
     */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    private function normalize(mixed $value): mixed
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        return $this->normalizer->normalize($value, 'json', ['skip_null_values' => false]);
    }
}
