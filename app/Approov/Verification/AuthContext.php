<?php

declare(strict_types=1);

namespace App\Approov\Verification;

final class AuthContext
{
    /**
     * Creates an immutable authentication context for the resolved principal.
     *
     * @param  string  $principal  The principal that was authenticated for the request.
     * @return void
     */
    private function __construct(
        private readonly string $principal
    ) {
    }

    /**
     * Creates an authentication context for a successfully verified Approov token.
     *
     * @return self
     */
    public static function approovToken(): self
    {
        return new self('approov-token');
    }

    /**
     * Creates an authentication context for requests processed while Approov is disabled.
     *
     * @return self
     */
    public static function disabled(): self
    {
        return new self('approov-disabled');
    }

    /**
     * Returns the principal identifier represented by this authentication context.
     *
     * @return string
     */
    public function principal(): string
    {
        return $this->principal;
    }

    /**
     * Returns the authentication context as an array payload.
     *
     * @return array{principal: string}
     */
    public function toArray(): array
    {
        return ['principal' => $this->principal];
    }
}
