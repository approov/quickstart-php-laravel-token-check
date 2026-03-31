<?php

declare(strict_types=1);

namespace App\Approov\Verification;

final class AuthContext
{
    private function __construct(
        private readonly string $principal
    ) {
    }

    public static function approovToken(): self
    {
        return new self('approov-token');
    }

    public static function disabled(): self
    {
        return new self('approov-disabled');
    }

    public function principal(): string
    {
        return $this->principal;
    }

    public function toArray(): array
    {
        return ['principal' => $this->principal];
    }
}
