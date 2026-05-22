<?php

declare(strict_types=1);

namespace JsonRpcServer\Serializer;

use JsonRpcServer\Type\Date;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Renders dates and date-times in a configurable format.
 *
 * Two configuration knobs control the OUTPUT shape:
 *   - `json_rpc_server.serializer.datetime_format` controls DateTimeInterface → string|int
 *   - `json_rpc_server.serializer.date_format`     controls Date → string
 *
 * Symbolic formats accepted by `datetime_format`:
 *   - "iso8601"      → "Y-m-d\TH:i:sP"           e.g. "2026-05-21T15:00:00+03:00"
 *   - "timestamp"    → unix seconds (integer)
 *   - "timestamp_ms" → unix milliseconds (integer)
 * Anything else is treated as a raw php date() format ('Y-m-d H:i:s' etc.).
 *
 * `json_rpc_server.serializer.timezone` (e.g. "UTC") is applied when normalizing
 * DateTimeInterface and when truncating timestamps to dates. Leave it null
 * to keep source timezones untouched.
 *
 * **Input is lenient on purpose.** JSON-RPC clients vary widely — strict
 * format checking on the wire causes more grief than it prevents:
 *
 *   - `DateTimeInterface` strings flow through `new DateTimeImmutable($s)`,
 *     so ISO, RFC, "yesterday", "2024-01-01 12:00", etc. all work.
 *   - `DateTimeInterface` numbers are interpreted as a timestamp — milliseconds
 *     when `datetime_format = "timestamp_ms"`, seconds otherwise.
 *   - `Date` strings are first matched against `date_format` strictly (so the
 *     canonical format round-trips), and on miss fall through to
 *     `DateTimeImmutable` (covers "21.05.2026", "2026/05/21", etc.). The
 *     time component is dropped.
 *   - `Date` numbers are accepted as timestamps and truncated to date.
 *
 * If you need strict input validation, attach a Symfony validator constraint
 * (`#[Assert\Date]`, `#[Assert\Regex(...)]`) to the parameter.
 */
final class DateNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public const string FORMAT_ISO8601 = 'iso8601';
    public const string FORMAT_TIMESTAMP = 'timestamp';
    public const string FORMAT_TIMESTAMP_MS = 'timestamp_ms';

    /** Default ISO 8601 format used when datetime_format = "iso8601". */
    public const string DATETIME_ISO_FORMAT = 'Y-m-d\TH:i:sP';

    private readonly ?\DateTimeZone $timezone;

    public function __construct(
        private readonly string $datetimeFormat = self::FORMAT_ISO8601,
        private readonly string $dateFormat = 'Y-m-d',
        ?string $timezone = null,
    ) {
        $this->timezone = null !== $timezone && '' !== $timezone
            ? new \DateTimeZone($timezone)
            : null;
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): string|int
    {
        if ($object instanceof Date) {
            return $object->value->format($this->dateFormat);
        }
        if ($object instanceof \DateTimeInterface) {
            $value = null !== $this->timezone && $object instanceof \DateTimeImmutable
                ? $object->setTimezone($this->timezone)
                : $object;

            return match ($this->datetimeFormat) {
                self::FORMAT_ISO8601 => $value->format(self::DATETIME_ISO_FORMAT),
                self::FORMAT_TIMESTAMP => $value->getTimestamp(),
                self::FORMAT_TIMESTAMP_MS => (int) $value->format('Uv'),
                default => $value->format($this->datetimeFormat),
            };
        }
        throw new InvalidArgumentException('Unsupported date object: '.get_debug_type($object));
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Date || $data instanceof \DateTimeInterface;
    }

    /**
     * Maps null and the empty string to null regardless of the requested $type.
     *
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (null === $data || '' === $data) {
            return null;
        }

        try {
            if (Date::class === $type) {
                return $this->parseDate($data, $context);
            }

            if (\DateTimeImmutable::class === $type || \DateTimeInterface::class === $type) {
                return $this->parseDateTime($data, $context);
            }

            if (\DateTime::class === $type) {
                return \DateTime::createFromImmutable($this->parseDateTime($data, $context));
            }
        } catch (NotNormalizableValueException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw NotNormalizableValueException::createForUnexpectedDataType($e->getMessage(), $data, [$type], $context['deserialization_path'] ?? null);
        }

        throw new InvalidArgumentException('Unsupported date type: '.$type);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function parseDateTime(mixed $data, array $context): \DateTimeImmutable
    {
        if (\is_int($data) || \is_float($data)) {
            return $this->parseTimestamp($data, $context);
        }

        if (!\is_string($data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType('Expected string or numeric timestamp for date-time type', $data, ['string', 'integer'], $context['deserialization_path'] ?? null);
        }

        return new \DateTimeImmutable($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function parseDate(mixed $data, array $context): Date
    {
        // Numeric inputs: same timestamp semantics as datetime — but we then
        // truncate to date. The configured timezone (or UTC) drives which
        // day a particular timestamp falls under, so this stays stable
        // across deployments.
        if (\is_int($data) || \is_float($data)) {
            $dt = $this->parseTimestamp($data, $context);
            $tz = $this->timezone ?? new \DateTimeZone('UTC');

            return new Date($dt->setTimezone($tz)->setTime(0, 0));
        }

        if (!\is_string($data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType('Expected string or numeric timestamp for date type', $data, ['string', 'integer'], $context['deserialization_path'] ?? null);
        }

        // Strict match against the configured format first — keeps the
        // canonical round-trip exact (output produced by normalize() reads
        // back into the same Date without ambiguity).
        $strict = \DateTimeImmutable::createFromFormat('!'.$this->dateFormat, $data);
        if (false !== $strict) {
            return new Date($strict);
        }

        // Lenient fallback for the long tail of input formats (ISO, EU dot,
        // US slash, …). `new DateTimeImmutable` throws when it can't parse,
        // which propagates as NotNormalizableValueException.
        try {
            $dt = new \DateTimeImmutable($data);
        } catch (\Exception $e) {
            throw NotNormalizableValueException::createForUnexpectedDataType(\sprintf('Invalid date "%s"', $data), $data, ['string'], $context['deserialization_path'] ?? null);
        }

        return new Date($dt->setTime(0, 0));
    }

    /**
     * Parses a numeric value as a unix timestamp. Treats the input as
     * milliseconds when `datetime_format = "timestamp_ms"`, otherwise as
     * seconds — matches the symmetric `normalize()` behaviour so the same
     * wire shape round-trips cleanly.
     *
     * @param array<string, mixed> $context
     */
    private function parseTimestamp(int|float $data, array $context): \DateTimeImmutable
    {
        $seconds = self::FORMAT_TIMESTAMP_MS === $this->datetimeFormat
            ? $data / 1000
            : $data;
        $dt = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $seconds));
        if (false === $dt) {
            throw NotNormalizableValueException::createForUnexpectedDataType('Invalid numeric timestamp', $data, ['integer', 'float'], $context['deserialization_path'] ?? null);
        }

        return null !== $this->timezone ? $dt->setTimezone($this->timezone) : $dt;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return \in_array($type, [Date::class, \DateTimeImmutable::class, \DateTimeInterface::class, \DateTime::class], true);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Date::class => false,
            \DateTimeInterface::class => false,
            \DateTimeImmutable::class => false,
            \DateTime::class => false,
        ];
    }
}
