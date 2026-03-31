<?php

declare(strict_types=1);

namespace App\Approov\State;

final class ApproovState
{
    public function __construct(
        private readonly bool $approovEnabled,
        private readonly bool $tokenBindingEnabled
    ) {
    }

    public function approovEnabled(): bool
    {
        return $this->approovEnabled;
    }

    public function tokenBindingEnabled(): bool
    {
        return $this->tokenBindingEnabled;
    }

    public function toArray(): array
    {
        return [
            'approovEnabled' => $this->approovEnabled,
            'tokenBindingEnabled' => $this->tokenBindingEnabled,
        ];
    }
}
