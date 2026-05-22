<?php

declare(strict_types=1);

namespace JsonRpcServer\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class InvalidParamsException extends RpcException
{
    /** @var list<array{path: string, message: string, code: ?string}> */
    private array $violations;

    /**
     * @param ConstraintViolationListInterface|list<array{path: string, message: string, code?: ?string}>|null $violations
     */
    public function __construct(
        string $message = 'Invalid params',
        ConstraintViolationListInterface|array|null $violations = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->violations = [];
        if ($violations instanceof ConstraintViolationListInterface) {
            foreach ($violations as $violation) {
                $this->violations[] = [
                    'path' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                    'code' => $violation->getCode(),
                ];
            }
        } elseif (\is_array($violations)) {
            foreach ($violations as $v) {
                $this->violations[] = [
                    'path' => $v['path'],
                    'message' => $v['message'],
                    'code' => $v['code'] ?? null,
                ];
            }
        }
    }

    public function rpcCode(): int
    {
        return self::INVALID_PARAMS;
    }

    public function rpcData(): mixed
    {
        return $this->violations ?: null;
    }
}
