<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Registry;

use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\Marker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\SubMarker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class MethodRegistryAttributesTest extends TestCase
{
    public function testRebuildsAttributesFromRawData(): void
    {
        $raw = ProfilerTestHelper::rawMethod('test.attrs');
        $raw['attributes'] = [
            ['class' => RequiresGroup::class, 'args' => [GroupPermission::Edit, 'on' => 'teamId']],
            ['class' => SubMarker::class, 'args' => ['label' => 'named', 'tags' => ['a', 'b']]],
            ['class' => Marker::class, 'args' => ['positional']],
        ];

        $attributes = $this->registry(['test.attrs' => $raw])->get('test.attrs')->attributes;

        $this->assertCount(3, $attributes);
        $this->assertEquals(new RequiresGroup(GroupPermission::Edit, 'teamId'), $attributes[0]);
        $this->assertInstanceOf(RequiresGroup::class, $attributes[0]);
        $this->assertSame(GroupPermission::Edit, $attributes[0]->permission);
        $this->assertEquals(new SubMarker('named', ['a', 'b']), $attributes[1]);
        $this->assertEquals(new Marker('positional'), $attributes[2]);
    }

    public function testMissingAttributesKeyYieldsEmptyList(): void
    {
        $meta = $this->registry(['test.plain' => ProfilerTestHelper::rawMethod('test.plain')])->get('test.plain');

        $this->assertSame([], $meta->attributes);
    }

    /**
     * @param array<string, array<string, mixed>> $raw
     */
    private function registry(array $raw): MethodRegistry
    {
        return new MethodRegistry($raw, $this->createStub(ContainerInterface::class));
    }
}
