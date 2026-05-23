<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugRpcCommandTest extends KernelTestCase
{
    public function testListsRegisteredMethods(): void
    {
        $kernel = $this->boot();
        $application = new Application($kernel);

        $tester = new CommandTester($application->find('debug:rpc'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('math.add', $display);
        $this->assertStringContainsString('math.legacy_add', $display);
    }

    public function testShowsDetailsForOneMethod(): void
    {
        $kernel = $this->boot();
        $application = new Application($kernel);

        $tester = new CommandTester($application->find('debug:rpc'));
        $tester->execute(['method' => 'math.legacy_add']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('math.legacy_add', $display);
        $this->assertStringContainsString('Use math.add instead', $display);
        $this->assertStringContainsString('AddRequest', $display);
    }

    public function testReportsUnknownMethod(): void
    {
        $kernel = $this->boot();
        $application = new Application($kernel);

        $tester = new CommandTester($application->find('debug:rpc'));
        $exit = $tester->execute(['method' => 'nope.does_not_exist']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('not registered', $tester->getDisplay());
    }

    public function testOpenRpcFlagEmitsValidDocument(): void
    {
        $kernel = $this->boot();
        $application = new Application($kernel);

        $tester = new CommandTester($application->find('debug:rpc'));
        $tester->execute([
            '--openrpc' => true,
            '--title' => 'Test API',
            '--api-version' => '7.7.7',
        ]);
        $tester->assertCommandIsSuccessful();

        $doc = json_decode($tester->getDisplay(), true, 32, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($doc);
        $this->assertSame('1.3.2', $doc['openrpc']);
        $this->assertSame('Test API', $doc['info']['title']);
        $this->assertSame('7.7.7', $doc['info']['version']);
        $this->assertNotEmpty($doc['methods']);

        $methods = array_column($doc['methods'], null, 'name');
        $this->assertArrayHasKey('math.add', $methods);
        $this->assertArrayHasKey('params', $methods['math.add']);
        $this->assertArrayHasKey('result', $methods['math.add']);

        // Deprecated method should be flagged with the OpenRPC `deprecated`
        // boolean plus the extension carrying the reason text.
        $this->assertTrue($methods['math.legacy_add']['deprecated']);
        $this->assertSame('Use math.add instead — will be removed in v2.', $methods['math.legacy_add']['x-deprecation-reason']);
    }
}
