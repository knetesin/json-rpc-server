<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Maker;

use Knetesin\JsonRpcServerBundle\Maker\MakeRpcMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;

final class MakeRpcMethodTest extends TestCase
{
    public function testCommandMetadata(): void
    {
        $this->assertSame('make:rpc-method', MakeRpcMethod::getCommandName());
        $this->assertSame(
            'Scaffold a JSON-RPC method handler (with optional DTO and test).',
            MakeRpcMethod::getCommandDescription(),
        );
    }

    public function testIsAMaker(): void
    {
        $this->assertInstanceOf(AbstractMaker::class, new MakeRpcMethod());
    }

    /**
     * dotCase is the heuristic that fills the --method default. Cover the
     * common shapes so a regression in the regex doesn't silently degrade
     * the developer experience.
     */
    #[DataProvider('dotCaseSamples')]
    public function testDotCaseDerivation(string $className, string $expected): void
    {
        $reflection = new \ReflectionMethod(MakeRpcMethod::class, 'dotCase');
        $reflection->setAccessible(true);

        $this->assertSame($expected, $reflection->invoke(null, $className));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dotCaseSamples(): iterable
    {
        yield 'simple two-word' => ['UserGet', 'user.get'];
        yield 'three-word chained' => ['UserGetByEmail', 'user.get_by_email'];
        yield 'four-word' => ['FooBarBazQux', 'foo.bar_baz_qux'];
        yield 'single word' => ['Foo', 'foo'];
        yield 'acronym not split' => ['ApiCall', 'api.call'];
    }
}
