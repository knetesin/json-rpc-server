<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Percent;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Strings that look like %parameter% placeholders must reach the registry verbatim. */
#[Rpc\Method(
    'test.percent',
    description: 'Share 10%-20% of %kernel.environment%',
    outputSchema: ['type' => 'string', 'description' => 'strftime-like, e.g. %Y'],
)]
#[Rpc\Mcp(description: 'Up to 100% of %kernel.environment%', title: '50% off')]
final class PercentEcho
{
    public function __invoke(string $format = '%Y-%m-%d'): string
    {
        return $format;
    }
}
