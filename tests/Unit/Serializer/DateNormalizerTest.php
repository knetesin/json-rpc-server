<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Serializer;

use JsonRpcServer\Serializer\DateNormalizer;
use JsonRpcServer\Type\Date;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

final class DateNormalizerTest extends TestCase
{
    // ---------- Normalize: Date ----------

    public function testNormalizesDateWithDefaultFormat(): void
    {
        $normalizer = new DateNormalizer();
        $date = new Date(new \DateTimeImmutable('2026-03-14 10:11:12', new \DateTimeZone('UTC')));
        $this->assertSame('2026-03-14', $normalizer->normalize($date));
    }

    public function testNormalizesDateWithCustomFormat(): void
    {
        $normalizer = new DateNormalizer(dateFormat: 'd.m.Y');
        $date = new Date(new \DateTimeImmutable('2026-03-14', new \DateTimeZone('UTC')));
        $this->assertSame('14.03.2026', $normalizer->normalize($date));
    }

    // ---------- Normalize: DateTimeInterface ----------

    public function testNormalizesDateTimeAsIso8601ByDefault(): void
    {
        $normalizer = new DateNormalizer();
        $dt = new \DateTimeImmutable('2026-03-14 10:11:12', new \DateTimeZone('UTC'));
        $this->assertSame('2026-03-14T10:11:12+00:00', $normalizer->normalize($dt));
    }

    public function testNormalizesDateTimeAsUnixTimestamp(): void
    {
        $normalizer = new DateNormalizer(datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP);
        $dt = new \DateTimeImmutable('2026-03-14 10:11:12', new \DateTimeZone('UTC'));
        $this->assertSame(1773483072, $normalizer->normalize($dt));
    }

    public function testNormalizesDateTimeAsUnixMilliseconds(): void
    {
        $normalizer = new DateNormalizer(datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP_MS);
        $dt = new \DateTimeImmutable('2026-03-14 10:11:12.345', new \DateTimeZone('UTC'));
        $this->assertSame(1773483072345, $normalizer->normalize($dt));
    }

    public function testNormalizesDateTimeWithCustomFormat(): void
    {
        $normalizer = new DateNormalizer(datetimeFormat: 'Y-m-d H:i:s');
        $dt = new \DateTimeImmutable('2026-03-14 10:11:12', new \DateTimeZone('UTC'));
        $this->assertSame('2026-03-14 10:11:12', $normalizer->normalize($dt));
    }

    public function testNormalizesDateTimeShiftedToConfiguredTimezone(): void
    {
        $normalizer = new DateNormalizer(timezone: 'UTC');
        $dt = new \DateTimeImmutable('2026-03-14 10:11:12', new \DateTimeZone('+03:00'));
        $this->assertSame('2026-03-14T07:11:12+00:00', $normalizer->normalize($dt));
    }

    // ---------- Denormalize: Date (lenient) ----------

    public function testDenormalizesDateFromCanonicalString(): void
    {
        $normalizer = new DateNormalizer();
        $date = $normalizer->denormalize('2026-03-14', Date::class);

        $this->assertInstanceOf(Date::class, $date);
        $this->assertSame('2026-03-14', (string) $date);
    }

    public function testDenormalizesDateFromConfiguredFormat(): void
    {
        // Configured d.m.Y must round-trip exactly via the strict path.
        $normalizer = new DateNormalizer(dateFormat: 'd.m.Y');
        $date = $normalizer->denormalize('14.03.2026', Date::class);

        $this->assertInstanceOf(Date::class, $date);
        $this->assertSame('2026-03-14', $date->value->format('Y-m-d'));
    }

    public function testDenormalizesDateFromLenientFallback(): void
    {
        // ISO date when configured format is d.m.Y — falls through to
        // DateTimeImmutable parsing.
        $normalizer = new DateNormalizer(dateFormat: 'd.m.Y');
        $date = $normalizer->denormalize('2026-03-14', Date::class);

        $this->assertSame('2026-03-14', $date->value->format('Y-m-d'));
    }

    public function testDenormalizesDateFromAlternativeFormats(): void
    {
        $normalizer = new DateNormalizer();
        // PHP's DateTime parser handles all of these — keeps the bundle
        // friendly to whichever wire format the client picks.
        foreach (['2026/03/14', '14-Mar-2026', '14 March 2026'] as $input) {
            $date = $normalizer->denormalize($input, Date::class);
            $this->assertInstanceOf(Date::class, $date);
            $this->assertSame('2026-03-14', $date->value->format('Y-m-d'), "input: $input");
        }
    }

    public function testDenormalizesDateFromUnixTimestamp(): void
    {
        $normalizer = new DateNormalizer(
            datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP,
            timezone: 'UTC',
        );
        // 1773483072 = 2026-03-14 10:11:12 UTC → date 2026-03-14
        $date = $normalizer->denormalize(1773483072, Date::class);

        $this->assertSame('2026-03-14', $date->value->format('Y-m-d'));
    }

    public function testDenormalizesDateFromUnixMilliseconds(): void
    {
        $normalizer = new DateNormalizer(
            datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP_MS,
            timezone: 'UTC',
        );
        $date = $normalizer->denormalize(1773483072345, Date::class);

        $this->assertSame('2026-03-14', $date->value->format('Y-m-d'));
    }

    // ---------- Denormalize: DateTime ----------

    public function testDenormalizesDateTimeImmutableFromIsoString(): void
    {
        $normalizer = new DateNormalizer();
        $dt = $normalizer->denormalize('2026-03-14T10:11:12+00:00', \DateTimeImmutable::class);

        $this->assertInstanceOf(\DateTimeImmutable::class, $dt);
        $this->assertSame('2026-03-14T10:11:12+00:00', $dt->format('Y-m-d\TH:i:sP'));
    }

    public function testDenormalizesDateTimeFromUnixSeconds(): void
    {
        $normalizer = new DateNormalizer(datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP);
        $dt = $normalizer->denormalize(1773483072, \DateTimeImmutable::class);

        $this->assertSame(1773483072, $dt->getTimestamp());
    }

    public function testDenormalizesDateTimeFromUnixMs(): void
    {
        $normalizer = new DateNormalizer(datetimeFormat: DateNormalizer::FORMAT_TIMESTAMP_MS);
        $dt = $normalizer->denormalize(1773483072345, \DateTimeImmutable::class);

        $this->assertSame(1773483072, $dt->getTimestamp());
        $this->assertSame('345', $dt->format('v'));
    }

    // ---------- Edge cases ----------

    public function testDenormalizesNullAndEmpty(): void
    {
        $normalizer = new DateNormalizer();
        $this->assertNull($normalizer->denormalize(null, Date::class));
        $this->assertNull($normalizer->denormalize('', \DateTimeImmutable::class));
    }

    public function testInvalidDateStringThrows(): void
    {
        $normalizer = new DateNormalizer();
        $this->expectException(NotNormalizableValueException::class);
        $normalizer->denormalize('not-a-date', Date::class);
    }

    public function testSupportsBothTypesForNormalization(): void
    {
        $normalizer = new DateNormalizer();
        $this->assertTrue($normalizer->supportsNormalization(new Date(new \DateTimeImmutable())));
        $this->assertTrue($normalizer->supportsNormalization(new \DateTimeImmutable()));
        $this->assertFalse($normalizer->supportsNormalization('not a date'));
    }

    public function testSupportsTypesForDenormalization(): void
    {
        $normalizer = new DateNormalizer();
        $this->assertTrue($normalizer->supportsDenormalization('x', Date::class));
        $this->assertTrue($normalizer->supportsDenormalization('x', \DateTimeImmutable::class));
        $this->assertFalse($normalizer->supportsDenormalization('x', 'string'));
    }
}
