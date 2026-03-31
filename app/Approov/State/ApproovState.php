<?php

declare(strict_types=1);

namespace App\Approov\State;

final class ApproovState
{
    /**
     * Creates an immutable snapshot of the current Approov feature flags.
     *
     * @param  bool  $approovEnabled  Whether Approov request verification is enabled.
     * @param  bool  $tokenBindingEnabled  Whether token binding enforcement is enabled.
     * @return void
     */
    public function __construct(
        private readonly bool $approovEnabled,
        private readonly bool $tokenBindingEnabled
    ) {
    }

    /**
     * Indicates whether Approov request verification is enabled.
     *
     * @return bool
     */
    public function approovEnabled(): bool
    {
        return $this->approovEnabled;
    }

    /**
     * Indicates whether token binding enforcement is enabled.
     *
     * @return bool
     */
    public function tokenBindingEnabled(): bool
    {
        return $this->tokenBindingEnabled;
    }

    /**
     * Returns the state snapshot as an array payload.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function toArray(): array
    {
        return [
            'approovEnabled' => $this->approovEnabled,
            'tokenBindingEnabled' => $this->tokenBindingEnabled,
        ];
    }
}
