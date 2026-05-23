<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Mcp;

use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\Priority;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\UserRequest;
use Knetesin\JsonRpcServerBundle\Type\Date;
use PHPUnit\Framework\TestCase;

final class JsonSchemaBuilderTest extends TestCase
{
    private JsonSchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new JsonSchemaBuilder();
    }

    public function testBuildsObjectSchema(): void
    {
        $schema = $this->builder->fromClass(UserRequest::class);

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('properties', $schema);
    }

    public function testRequiredMatchesNonNullableNoDefaultParameters(): void
    {
        $schema = $this->builder->fromClass(UserRequest::class);

        $this->assertContains('name', $schema['required']);
        $this->assertContains('age', $schema['required']);
        $this->assertNotContains('email', $schema['required']);
    }

    public function testStringWithLengthConstraint(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame('string', $props->name['type']);
        $this->assertSame(1, $props->name['minLength']);
        $this->assertSame(255, $props->name['maxLength']);
    }

    public function testIntegerWithRangeConstraint(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame('integer', $props->age['type']);
        $this->assertSame(0, $props->age['minimum']);
        $this->assertSame(150, $props->age['maximum']);
    }

    public function testNullableStringHasNullInType(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame(['string', 'null'], $props->email['type']);
        $this->assertSame('email', $props->email['format']);
    }

    public function testDateMapsToStringWithDateFormat(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        // Field is nullable, so PHP type becomes ['string', 'null'].
        $this->assertSame(['string', 'null'], $props->birthday['type']);
        $this->assertSame('date', $props->birthday['format']);
    }

    public function testDateTimeMapsToStringWithDateTimeFormat(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame(['string', 'null'], $props->createdAt['type']);
        $this->assertSame('date-time', $props->createdAt['format']);
    }

    public function testBackedEnumProducesEnum(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame(['red', 'green', 'blue'], $props->color['enum']);
        $this->assertSame('string', $props->color['type']);
    }

    public function testIntegerBackedEnumProducesIntegerType(): void
    {
        $schema = $this->builder->fromClass(Priority::class);

        $this->assertSame('integer', $schema['type']);
        $this->assertSame([1, 5, 10], $schema['enum']);
    }

    public function testNestedDtoIsRecursivelyExpanded(): void
    {
        $props = $this->builder->fromClass(UserRequest::class)['properties'];

        $this->assertSame(['object', 'null'], $props->address['type']);
        $nested = $props->address['properties'];
        $this->assertObjectHasProperty('street', $nested);
        $this->assertObjectHasProperty('city', $nested);
    }

    public function testEmptyClassReturnsObject(): void
    {
        $schema = $this->builder->fromClass(null);
        $this->assertSame(['type' => 'object'], $schema);
    }

    public function testDateClassDirectly(): void
    {
        $schema = $this->builder->fromClass(Date::class);

        $this->assertSame(['type' => 'string', 'format' => 'date'], $schema);
    }

    public function testDateTimeBecomesIntegerWhenTimestampFormatConfigured(): void
    {
        $builder = new JsonSchemaBuilder(datetimeFormat: 'timestamp');
        $schema = $builder->fromClass(\DateTimeImmutable::class);

        $this->assertSame('integer', $schema['type']);
        $this->assertStringContainsString('Unix timestamp', $schema['description']);
    }

    public function testDateTimeBecomesIntegerWhenTimestampMsFormatConfigured(): void
    {
        $builder = new JsonSchemaBuilder(datetimeFormat: 'timestamp_ms');
        $schema = $builder->fromClass(\DateTimeImmutable::class);

        $this->assertSame('integer', $schema['type']);
        $this->assertStringContainsString('milliseconds', $schema['description']);
    }
}
