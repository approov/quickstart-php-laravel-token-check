<?php

declare(strict_types=1);

namespace App\Approov\Verification;

use App\Approov\Config\ApproovConfig;
use App\Approov\State\ApproovState;

interface RequestVerifier
{
    public function verify(VerificationInput $input, ApproovConfig $config, ApproovState $state): AuthContext;
}
