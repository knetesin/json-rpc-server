<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Request;

use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use PHPUnit\Framework\TestCase;

final class RpcParamsTest extends TestCase
{
    public function testRequireStringReturnsValue(): void
    {
        $params = new RpcParams(['name' => 'alice']);
        $this->assertSame('alice', $params->requireString('name'));
    }

    public function testRequireStringThrowsWhenMissing(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Missing required parameter "name"');
        (new RpcParams([]))->requireString('name');
    }

    public function testRequireStringThrowsOnExplicitNull(): void
    {
        // Distinction from getString: requireString treats null the same as
        // absent — the value isn't usable, so the caller would have to
        // double-check anyway. Saves the boilerplate.
        $this->expectException(InvalidParamsException::class);
        (new RpcParams(['name' => null]))->requireString('name');
    }

    public function testRequireStringThrowsOnWrongType(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('must be string');
        (new RpcParams(['name' => 42]))->requireString('name');
    }

    public function testRequireIntCoercesFromIntegerOnly(): void
    {
        $this->assertSame(7, (new RpcParams(['n' => 7]))->requireInt('n'));

        $this->expectException(InvalidParamsException::class);
        (new RpcParams(['n' => '7']))->requireInt('n');
    }

    public function testRequireFloatAcceptsInt(): void
    {
        // Int promotes to float — the JSON wire never sends "1.0", it sends
        // 1. Forcing the caller to deal with that would defeat the helper.
        $this->assertSame(1.0, (new RpcParams(['x' => 1]))->requireFloat('x'));
    }

    public function testRequireBoolStrict(): void
    {
        $this->assertTrue((new RpcParams(['ok' => true]))->requireBool('ok'));

        $this->expectException(InvalidParamsException::class);
        (new RpcParams(['ok' => 1]))->requireBool('ok');
    }

    public function testRequireArrayReturnsValue(): void
    {
        $params = new RpcParams(['ids' => [1, 2, 3]]);
        $this->assertSame([1, 2, 3], $params->requireArray('ids'));
    }

    public function testRequireArrayThrowsOnScalar(): void
    {
        $this->expectException(InvalidParamsException::class);
        (new RpcParams(['ids' => 'csv']))->requireArray('ids');
    }
}
