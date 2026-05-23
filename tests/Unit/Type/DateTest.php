<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Type;

use Knetesin\JsonRpcServerBundle\Type\Date;
use PHPUnit\Framework\TestCase;

final class DateTest extends TestCase
{
    public function testFromStringDefaultFormat(): void
    {
        $date = Date::fromString('2026-05-21');

        $this->assertSame('2026-05-21', $date->value->format('Y-m-d'));
        // `!` in createFromFormat zeroes the time component.
        $this->assertSame('00:00:00', $date->value->format('H:i:s'));
    }

    public function testFromStringWithCustomFormat(): void
    {
        $date = Date::fromString('21.05.2026', format: 'd.m.Y');

        $this->assertSame('2026-05-21', $date->value->format('Y-m-d'));
    }

    public function testFromStringRejectsMismatchedFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected format d.m.Y');

        Date::fromString('2026-05-21', format: 'd.m.Y');
    }

    public function testFormatMethodAcceptsCustomFormat(): void
    {
        $date = new Date(new \DateTimeImmutable('2026-05-21'));

        $this->assertSame('21/05/2026', $date->format('d/m/Y'));
        $this->assertSame('2026-05-21', $date->format()); // default
        $this->assertSame('2026-05-21', (string) $date);  // __toString
    }

    public function testDefaultFormatConstant(): void
    {
        $this->assertSame('Y-m-d', Date::DEFAULT_FORMAT);
    }
}
