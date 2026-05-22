<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Sets the security token from X-Test-Roles after the firewall ContextListener runs.
 */
#[AsEventListener(event: 'kernel.request', priority: 7)]
final class TestAuthenticationListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $header = $event->getRequest()->headers->get('X-Test-Roles');
        if (null === $header || '' === $header) {
            return;
        }

        $roles = array_map(trim(...), explode(',', $header));
        $user = new InMemoryUser('tester', null, $roles);
        $this->tokenStorage->setToken(new TestBrowserToken($roles, $user));
    }
}
