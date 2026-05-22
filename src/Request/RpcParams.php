<?php

declare(strict_types=1);

namespace JsonRpcServer\Request;

use JsonRpcServer\Exception\InvalidParamsException;

/**
 * Typed accessor over a JSON-RPC `params` value.
 *
 * Modelled after Symfony's InputBag, but with JSON-RPC semantics:
 *
 *   - both object-form (`{"a":1}`) and array-form (`[1,2,3]`) are supported;
 *     `isList()` discriminates them;
 *   - the typed getters (`getString`, `getInt`, …) are strict — a present
 *     value of the wrong shape raises `InvalidParamsException` (-32602)
 *     instead of silently coercing, which would mask client bugs;
 *   - a missing key, or a present key with a `null` JSON value, both yield
 *     the supplied default. Use `has()` if that distinction matters.
 *
 * Always non-null: when the JSON-RPC envelope has no `params` field at all,
 * the parser still constructs an empty `RpcParams`.
 *
 * @implements \IteratorAggregate<array-key, mixed>
 */
final class RpcParams implements \Countable, \IteratorAggregate
{
    /** @var array<array-key, mixed> */
    private readonly array $data;

    private readonly bool $isList;

    /**
     * @param array<array-key, mixed>|null $data
     */
    public function __construct(?array $data)
    {
        if (null === $data) {
            $this->data = [];
            $this->isList = false;

            return;
        }
        $this->data = $data;
        $this->isList = [] !== $data && array_is_list($data);
    }

    public function isList(): bool
    {
        return $this->isList;
    }

    public function isEmpty(): bool
    {
        return [] === $this->data;
    }

    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * @return \Generator<array-key, mixed>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->data as $k => $v) {
            yield $k => $v;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Positional accessor for array-form params. Returns default when out of range. */
    public function at(int $index, mixed $default = null): mixed
    {
        return $this->data[$index] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->fetch($key);
        if (null === $value) {
            return $default;
        }
        if (!\is_string($value)) {
            throw $this->typeError($key, 'string', $value);
        }

        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->fetch($key);
        if (null === $value) {
            return $default;
        }
        if (!\is_int($value)) {
            throw $this->typeError($key, 'integer', $value);
        }

        return $value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->fetch($key);
        if (null === $value) {
            return $default;
        }
        if (\is_int($value)) {
            return (float) $value;
        }
        if (!\is_float($value)) {
            throw $this->typeError($key, 'number', $value);
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->fetch($key);
        if (null === $value) {
            return $default;
        }
        if (!\is_bool($value)) {
            throw $this->typeError($key, 'boolean', $value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->fetch($key);
        if (null === $value) {
            return $default;
        }
        if (!\is_array($value)) {
            throw $this->typeError($key, 'array', $value);
        }

        return $value;
    }

    /**
     * Strict variants: throw InvalidParamsException (-32602) when the key is
     * absent or null. Use these for required scalars on methods that don't
     * model their input as a DTO — same UX as a missing field on a DTO ctor,
     * without writing the if/throw boilerplate in every handler.
     */
    public function requireString(string $key): string
    {
        $value = $this->requirePresent($key);
        if (!\is_string($value)) {
            throw $this->typeError($key, 'string', $value);
        }

        return $value;
    }

    public function requireInt(string $key): int
    {
        $value = $this->requirePresent($key);
        if (!\is_int($value)) {
            throw $this->typeError($key, 'integer', $value);
        }

        return $value;
    }

    public function requireFloat(string $key): float
    {
        $value = $this->requirePresent($key);
        if (\is_int($value)) {
            return (float) $value;
        }
        if (!\is_float($value)) {
            throw $this->typeError($key, 'number', $value);
        }

        return $value;
    }

    public function requireBool(string $key): bool
    {
        $value = $this->requirePresent($key);
        if (!\is_bool($value)) {
            throw $this->typeError($key, 'boolean', $value);
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function requireArray(string $key): array
    {
        $value = $this->requirePresent($key);
        if (!\is_array($value)) {
            throw $this->typeError($key, 'array', $value);
        }

        return $value;
    }

    private function requirePresent(string $key): mixed
    {
        if (!\array_key_exists($key, $this->data) || null === $this->data[$key]) {
            throw new InvalidParamsException(\sprintf('Missing required parameter "%s"', $key));
        }

        return $this->data[$key];
    }

    private function fetch(string $key): mixed
    {
        if (!\array_key_exists($key, $this->data)) {
            return null;
        }

        return $this->data[$key];
    }

    private function typeError(string $key, string $expected, mixed $actual): InvalidParamsException
    {
        return new InvalidParamsException(\sprintf(
            'Parameter "%s" must be %s, got %s',
            $key,
            $expected,
            get_debug_type($actual),
        ));
    }
}
