<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Registry;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\Marker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\SubMarker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use PHPUnit\Framework\TestCase;

final class MethodMetadataAttributesTest extends TestCase
{
    public function testAttributesDefaultToEmpty(): void
    {
        $meta = $this->meta();

        $this->assertSame([], $meta->attributes);
        $this->assertSame([], $meta->getAttributes(RequiresGroup::class));
    }

    public function testGetAttributesFiltersByClassIncludingSubclassesInDeclarationOrder(): void
    {
        $first = new Marker('first');
        $view = new RequiresGroup(GroupPermission::View);
        $sub = new SubMarker('second');
        $edit = new RequiresGroup(GroupPermission::Edit, on: 'teamId');

        $meta = $this->meta([$first, $view, $sub, $edit]);

        $this->assertSame([$first, $sub], $meta->getAttributes(Marker::class));
        $this->assertSame([$sub], $meta->getAttributes(SubMarker::class));
        $this->assertSame([$view, $edit], $meta->getAttributes(RequiresGroup::class));
        $this->assertSame([], $meta->getAttributes(\stdClass::class));
    }

    /**
     * @param list<object> $attributes
     */
    private function meta(array $attributes = []): MethodMetadata
    {
        return new MethodMetadata(
            name: 'test.meta',
            serviceClass: 'App\\Stub',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
            attributes: $attributes,
        );
    }
}
