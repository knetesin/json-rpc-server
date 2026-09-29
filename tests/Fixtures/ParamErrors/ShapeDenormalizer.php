<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ParamErrors;

use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Fails like a custom denormalizer would: a serializer exception whose message names the class. */
final class ShapeDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        throw new UnexpectedValueException(\sprintf('Cannot build "%s" from the payload.', ShapeRequest::class));
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return ShapeRequest::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [ShapeRequest::class => true];
    }
}
