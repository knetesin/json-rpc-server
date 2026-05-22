<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Exception;

use JsonRpcServer\Exception\AccessDeniedException;
use JsonRpcServer\Exception\InternalErrorException;
use JsonRpcServer\Exception\InvalidParamsException;
use JsonRpcServer\Exception\InvalidRequestException;
use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\NotFoundException;
use JsonRpcServer\Exception\ParseException;
use JsonRpcServer\Exception\RpcException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class RpcExceptionsTest extends TestCase
{
    public function testStandardErrorCodes(): void
    {
        $this->assertSame(-32700, (new ParseException())->rpcCode());
        $this->assertSame(-32600, (new InvalidRequestException())->rpcCode());
        $this->assertSame(-32601, (new MethodNotFoundException('foo'))->rpcCode());
        $this->assertSame(-32602, (new InvalidParamsException())->rpcCode());
        $this->assertSame(-32603, (new InternalErrorException())->rpcCode());
    }

    public function testAccessDeniedDefaultCode(): void
    {
        $e = new AccessDeniedException();
        $this->assertSame(-32001, $e->rpcCode());
        $this->assertSame(AccessDeniedException::DEFAULT_CODE, $e->rpcCode());
    }

    public function testAccessDeniedCustomCode(): void
    {
        $e = new AccessDeniedException('Forbidden by quota', -32050);
        $this->assertSame(-32050, $e->rpcCode());
        $this->assertSame('Forbidden by quota', $e->getMessage());
    }

    public function testNotFoundDefaultAndCustomCode(): void
    {
        $this->assertSame(-32002, (new NotFoundException())->rpcCode());
        $this->assertSame(-32099, (new NotFoundException('gone', -32099))->rpcCode());
    }

    public function testInvalidParamsCarriesViolations(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Must not be blank', '', [], '', 'name', null),
            new ConstraintViolation('Too long', '', [], '', 'description', null),
        ]);

        $e = new InvalidParamsException('Invalid params', $violations);
        $data = $e->rpcData();

        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertSame('name', $data[0]['path']);
        $this->assertSame('Must not be blank', $data[0]['message']);
    }

    public function testInvalidParamsWithoutViolationsHasNullData(): void
    {
        $this->assertNull((new InvalidParamsException())->rpcData());
    }

    public function testCustomExceptionExtension(): void
    {
        $e = new class extends RpcException {
            public function __construct()
            {
                parent::__construct('Quota exceeded');
            }

            public function rpcCode(): int
            {
                return -32010;
            }

            public function rpcData(): mixed
            {
                return ['retryAfter' => 60];
            }
        };

        $this->assertSame(-32010, $e->rpcCode());
        $this->assertSame(['retryAfter' => 60], $e->rpcData());
    }

    public function testServerErrorRangeConstants(): void
    {
        $this->assertSame(-32099, RpcException::SERVER_ERROR_MIN);
        $this->assertSame(-32000, RpcException::SERVER_ERROR_MAX);
    }
}
