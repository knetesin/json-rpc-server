<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Dispatcher;

use Knetesin\JsonRpcServerBundle\Context\ContextFactory;
use Knetesin\JsonRpcServerBundle\Dispatcher\Dispatcher;
use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Resolver\ArgumentResolver;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Guards may come from any iterable, not only the container's Countable
 * tagged iterator.
 */
final class GuardIterableTest extends TestCase
{
    public function testGuardFromNonCountableIterableRuns(): void
    {
        $guard = new class implements MethodGuardInterface {
            public function check(MethodMetadata $meta, array $args, RpcRequest $request): void
            {
                throw new AccessDeniedException('Denied by generator guard');
            }
        };
        $guards = (static function () use ($guard): \Generator {
            yield $guard;
        })();

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Denied by generator guard');

        $this->dispatcher($guards)->call(new RpcRequest(1, 'test.stub', new RpcParams(null), false));
    }

    public function testWithoutGuardsHandlerRuns(): void
    {
        $result = $this->dispatcher([])->call(new RpcRequest(1, 'test.stub', new RpcParams(null), false));

        $this->assertSame('handled', $result);
    }

    /**
     * @param iterable<MethodGuardInterface> $guards
     */
    private function dispatcher(iterable $guards): Dispatcher
    {
        $handlers = new class implements ContainerInterface {
            public function get(string $id): object
            {
                return new class {
                    public function __invoke(): string
                    {
                        return 'handled';
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $requestStack = new RequestStack();

        return new Dispatcher(
            new MethodRegistry(['test.stub' => ProfilerTestHelper::rawMethod('test.stub')], $handlers),
            new ArgumentResolver(
                $this->createStub(DenormalizerInterface::class),
                $this->createStub(ValidatorInterface::class),
                new ContextFactory($requestStack, new SecurityUserResolver()),
                $requestStack,
            ),
            $this->createStub(NormalizerInterface::class),
            guards: $guards,
        );
    }
}
