<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class KernelTestCase extends TestCase
{
    private ?KernelInterface $kernel = null;
    private mixed $errorHandlerBefore = null;
    private mixed $exceptionHandlerBefore = null;

    /**
     * Snapshot the global error/exception handlers BEFORE any boot() runs, so
     * we can pop the handlers Symfony's DebugHandlersListener leaves behind.
     * Each boot() inside one test installs another pair — popping until we
     * reach the snapshot puts the global state back to whatever PHPUnit had.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandlerBefore = set_error_handler(static fn (int $errno, string $errstr): bool => false);
        restore_error_handler();

        $this->exceptionHandlerBefore = set_exception_handler(static function (\Throwable $e): void {});
        restore_exception_handler();
    }

    /**
     * @param array<string, mixed> $rpcConfig
     */
    protected function boot(array $rpcConfig = []): TestKernel
    {
        $this->kernel = new TestKernel($rpcConfig);
        $this->kernel->boot();

        return $this->kernel;
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }

        $this->popHandlersUntil(
            static fn (callable $handler): mixed => set_error_handler($handler),
            static function (): void { restore_error_handler(); },
            $this->errorHandlerBefore,
        );
        $this->popHandlersUntil(
            static fn (callable $handler): mixed => set_exception_handler($handler),
            static function (): void { restore_exception_handler(); },
            $this->exceptionHandlerBefore,
        );

        parent::tearDown();
    }

    protected function responseContent(Response $response): string
    {
        $content = $response->getContent();
        if (false === $content) {
            $this->fail('Response has no content');
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonResponse(Response $response): array
    {
        $decoded = json_decode($this->responseContent($response), true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            $this->fail('Expected JSON object');
        }

        return $decoded;
    }

    protected function jsonEncode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    protected function captureStreamBody(StreamedResponse $response): string
    {
        // Two-level buffer: StreamController calls ob_flush() to push each
        // row out under FPM. In tests that flush would drain to stdout and
        // leave our outer capture empty. The inner level catches each flush
        // and lets it collapse into the outer level — so we end up with
        // every row in $body.
        ob_start(); // outer — what we ultimately read
        ob_start(); // inner — what the controller flushes
        try {
            $response->sendContent();
        } finally {
            // Drain any rows still pending in the inner buffer up into the
            // outer one before we read.
            if (ob_get_level() >= 2) {
                ob_end_flush();
            }
        }
        $body = ob_get_clean();
        if (false === $body) {
            $this->fail('Failed to capture stream output');
        }

        return $body;
    }

    /**
     * @param callable(callable): mixed $setFn
     * @param callable(): void $restoreFn
     */
    private function popHandlersUntil(callable $setFn, callable $restoreFn, mixed $target): void
    {
        for ($i = 0; $i < 32; ++$i) {
            $current = $setFn(static fn () => null);
            $restoreFn();
            if ($current === $target) {
                return;
            }
            $restoreFn();
        }
    }
}
