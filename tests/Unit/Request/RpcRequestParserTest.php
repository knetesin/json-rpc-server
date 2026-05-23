<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Request;

use Knetesin\JsonRpcServerBundle\Exception\InvalidRequestException;
use Knetesin\JsonRpcServerBundle\Exception\ParseException;
use Knetesin\JsonRpcServerBundle\Exception\RequestTooLargeException;
use Knetesin\JsonRpcServerBundle\Request\RpcRequestParser;
use PHPUnit\Framework\TestCase;

final class RpcRequestParserTest extends TestCase
{
    private RpcRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RpcRequestParser();
    }

    public function testSingleRequest(): void
    {
        $items = $this->parser->parseBatch('{"jsonrpc":"2.0","method":"foo","params":{"a":1},"id":42}');

        $this->assertCount(1, $items);
        $this->assertSame('foo', $items[0]->method);
        $this->assertSame(['a' => 1], $items[0]->params->all());
        $this->assertFalse($items[0]->params->isList());
        $this->assertSame(42, $items[0]->id);
        $this->assertFalse($items[0]->isNotification);
    }

    public function testNotification(): void
    {
        $items = $this->parser->parseBatch('{"jsonrpc":"2.0","method":"foo"}');

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->isNotification);
        $this->assertNull($items[0]->id);
    }

    public function testBatch(): void
    {
        $body = '[{"jsonrpc":"2.0","method":"a","id":1},{"jsonrpc":"2.0","method":"b","id":2}]';
        $items = $this->parser->parseBatch($body);

        $this->assertCount(2, $items);
        $this->assertSame('a', $items[0]->method);
        $this->assertSame('b', $items[1]->method);
        $this->assertTrue($this->parser->isBatchPayload($body));
    }

    public function testNullIdIsAllowed(): void
    {
        $items = $this->parser->parseBatch('{"jsonrpc":"2.0","method":"foo","id":null}');
        $this->assertNull($items[0]->id);
        $this->assertFalse($items[0]->isNotification);
    }

    public function testStringIdIsAllowed(): void
    {
        $items = $this->parser->parseBatch('{"jsonrpc":"2.0","method":"foo","id":"abc"}');
        $this->assertSame('abc', $items[0]->id);
    }

    public function testParseErrorOnInvalidJson(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parseBatch('not json');
    }

    public function testInvalidRequestOnWrongJsonrpcField(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->parser->parseBatch('{"jsonrpc":"1.0","method":"foo","id":1}');
    }

    public function testInvalidRequestOnMissingMethod(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->parser->parseBatch('{"jsonrpc":"2.0","id":1}');
    }

    public function testInvalidRequestOnEmptyBatch(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->parser->parseBatch('[]');
    }

    public function testInvalidRequestOnNonObjectItem(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->parser->parseBatch('"foo"');
    }

    public function testInvalidRequestOnBadParamsType(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->parser->parseBatch('{"jsonrpc":"2.0","method":"foo","params":"oops","id":1}');
    }

    public function testRequestTooLargeIsRejected(): void
    {
        $parser = new RpcRequestParser(maxRequestSize: 16);
        $body = '{"jsonrpc":"2.0","method":"foo","id":1}';

        $this->expectException(RequestTooLargeException::class);
        $this->expectExceptionMessageMatches('/too large/');
        $parser->parseBatch($body);
    }

    public function testZeroLimitDisablesSizeCheck(): void
    {
        $parser = new RpcRequestParser(maxRequestSize: 0);
        $body = str_repeat('x', 10_000);
        try {
            $parser->parseBatch($body);
        } catch (ParseException) {
            // expected: invalid JSON. But not RequestTooLarge.
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('expected ParseException');
    }
}
