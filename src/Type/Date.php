<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Type;

/**
 * Date-only value object. The wire format used by the JSON-RPC layer is
 * controlled by `json_rpc_server.serializer.date_format` (default `Y-m-d`) and applied
 * inside DateNormalizer — runtime denormalization never touches the constant
 * below.
 *
 * `fromString` exists as a convenience for ad-hoc construction (CLI commands,
 * tests, manual fixtures). It accepts an optional format so callers can match
 * the same format their bundle is configured with — leave it null to use the
 * conventional ISO `Y-m-d`.
 */
final readonly class Date implements \Stringable
{
    /** Conventional default; used when `fromString` / `__toString` are called without a format. */
    public const string DEFAULT_FORMAT = 'Y-m-d';

    public function __construct(public \DateTimeImmutable $value)
    {
    }

    /**
     * Builds a Date from a string strictly matching `$format` (default
     * {@see self::DEFAULT_FORMAT}). The leading `!` resets unspecified
     * fields to the Unix epoch so the time component is always 00:00:00.
     *
     * @param string|null $format any valid php date() format; null uses the default
     */
    public static function fromString(string $s, ?string $format = null): self
    {
        $effective = $format ?? self::DEFAULT_FORMAT;
        $value = \DateTimeImmutable::createFromFormat('!'.$effective, $s);
        if (false === $value) {
            throw new \InvalidArgumentException(\sprintf('Invalid date "%s", expected format %s', $s, $effective));
        }

        return new self($value);
    }

    /**
     * Formats the date with the given format (default {@see self::DEFAULT_FORMAT}).
     */
    public function format(?string $format = null): string
    {
        return $this->value->format($format ?? self::DEFAULT_FORMAT);
    }

    public function __toString(): string
    {
        return $this->value->format(self::DEFAULT_FORMAT);
    }
}
