<?php

declare(strict_types=1);

namespace App\Approov\Exceptions;

use RuntimeException;

final class ApproovAuthException extends RuntimeException
{
    public function __construct(
        private readonly ApproovErrorCode $errorCode,
        private readonly int $httpStatus,
        private readonly string $safeMessage,
        private readonly array $context = []
    ) {
        parent::__construct($safeMessage);
    }

    public function errorCode(): ApproovErrorCode
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    public function context(): array
    {
        return $this->context;
    }
}
