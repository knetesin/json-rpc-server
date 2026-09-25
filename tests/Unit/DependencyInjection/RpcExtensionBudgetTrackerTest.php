<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection;

use Knetesin\JsonRpcServerBundle\Batch\NullBudgetTracker;
use Knetesin\JsonRpcServerBundle\Controller\RpcController;
use Knetesin\JsonRpcServerBundle\DependencyInjection\RpcExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * `parallel_batch.budget: 0` means "no global budget", not "budget of zero
 * slots" that would reject every fan-out reservation.
 */
final class RpcExtensionBudgetTrackerTest extends TestCase
{
    public function testZeroBudgetWithApcuStoreWiresNullTrackerWithoutWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, \E_USER_WARNING);
        try {
            $container = $this->load(['budget' => 0, 'budget_store' => 'apcu']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(
            NullBudgetTracker::class,
            $container->getDefinition('json_rpc_server.parallel_batch.budget_tracker')->getClass(),
        );
        $this->assertEquals(
            new Reference('json_rpc_server.parallel_batch.budget_tracker'),
            $container->getDefinition(RpcController::class)->getArgument('$budget'),
        );
    }

    public function testCustomStoreIdIsWiredAsIsRegardlessOfBudget(): void
    {
        $container = $this->load(['budget' => 0, 'budget_store' => 'app.budget_tracker']);

        $this->assertFalse($container->hasDefinition('json_rpc_server.parallel_batch.budget_tracker'));
        $this->assertEquals(
            new Reference('app.budget_tracker'),
            $container->getDefinition(RpcController::class)->getArgument('$budget'),
        );
    }

    /**
     * @param array<string, mixed> $parallelBatch
     */
    private function load(array $parallelBatch): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.bundles', []);

        (new RpcExtension())->load(
            [['parallel_batch' => ['enabled' => true] + $parallelBatch]],
            $container,
        );

        return $container;
    }
}
