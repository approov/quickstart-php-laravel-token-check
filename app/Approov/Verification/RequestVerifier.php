<?php

declare(strict_types=1);

namespace App\Approov\Verification;

use App\Approov\Config\ApproovConfig;
use App\Approov\State\ApproovState;

interface RequestVerifier
{
    /**
     * Verifies an incoming request against the provided Approov configuration and state.
     *
     * @param  VerificationInput  $input  The normalized request data to verify.
     * @param  ApproovConfig  $config  The configuration used during verification.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @return AuthContext
     *
     * @throws \App\Approov\Exceptions\ApproovAuthException
     */
    public function verify(VerificationInput $input, ApproovConfig $config, ApproovState $state): AuthContext;
}
