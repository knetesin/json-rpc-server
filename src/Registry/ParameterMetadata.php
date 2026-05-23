<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Registry;

use Symfony\Component\Validator\Constraint;

final readonly class ParameterMetadata
{
    /**
     * @param list<Constraint> $constraints
     * @param list<string> $dtoOwnKeys ctor-param names of the DTO class, used by the
     *                                 resolver to feed the denormalizer only its own
     *                                 subset of $named (so siblings — other DTOs or
     *                                 scalar #[Rpc\Param] — keep their own keys).
     *                                 Empty for non-DTO params.
     */
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $isContext,
        public bool $isDto,
        public bool $hasDefault,
        public mixed $default,
        public bool $allowsNull,
        public bool $isHttpRequest = false,
        public bool $isRpcRequest = false,
        public bool $hasParamAttribute = false,
        public ?string $jsonName = null,
        public bool $paramRequired = true,
        public array $constraints = [],
        public array $dtoOwnKeys = [],
    ) {
    }

    /** True for any parameter that is not a business input (DTO/scalar). */
    public function isInjected(): bool
    {
        return $this->isContext || $this->isHttpRequest || $this->isRpcRequest;
    }

    /** Key used to look the value up in the request `params` object. */
    public function lookupKey(): string
    {
        return $this->jsonName ?? $this->name;
    }
}
