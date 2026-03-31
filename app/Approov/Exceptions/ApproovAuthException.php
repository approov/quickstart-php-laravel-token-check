<?php

declare(strict_types=1);

namespace App\Approov\Exceptions;

use RuntimeException;

final class ApproovAuthException extends RuntimeException
{
    /**
     * Creates an exception that carries the public error payload for an Approov verification failure.
     *
     * @param  ApproovErrorCode  $errorCode  The machine-readable Approov failure code.
     * @param  int  $httpStatus  The HTTP status that should be returned to the client.
     * @param  string  $safeMessage  The sanitized error message safe to expose to clients.
     * @param  array<string, mixed>  $context  Additional structured failure context for logging and responses.
     * @return void
     */
    public function __construct(
        private readonly ApproovErrorCode $errorCode,
        private readonly int $httpStatus,
        private readonly string $safeMessage,
        private readonly array $context = []
    ) {
        parent::__construct($safeMessage);
    }

    /**
     * Returns the machine-readable Approov error code.
     *
     * @return ApproovErrorCode
     */
    public function errorCode(): ApproovErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Returns the HTTP status that should be sent for this verification failure.
     *
     * @return int
     */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Returns the sanitized error message safe to expose to API clients.
     *
     * @return string
     */
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    /**
     * Returns the structured failure context attached to the exception.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
