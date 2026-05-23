<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Mcp;

use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;

/**
 * Single place to build {@see JsonSchemaBuilder} with the same optional
 * PHPDoc ctor extractor everywhere (compiler pass + DI).
 */
final class JsonSchemaBuilderFactory
{
    public static function create(string $datetimeFormat, ?int $maxDepth = null): JsonSchemaBuilder
    {
        return new JsonSchemaBuilder(
            $datetimeFormat,
            $maxDepth,
            self::ctorTypeExtractor(),
        );
    }

    private static function ctorTypeExtractor(): ?PhpStanExtractor
    {
        if (!class_exists(PhpStanExtractor::class)) {
            return null;
        }

        try {
            return new PhpStanExtractor();
        } catch (\Throwable) {
            // Missing phpdocumentor/type-resolver or phpstan/phpdoc-parser —
            // schema generation degrades to untyped `array` without `items`.
            return null;
        }
    }
}
