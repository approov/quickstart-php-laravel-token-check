<?php

declare(strict_types=1);

namespace App\Approov;

use App\Approov\Config\ApproovConfig;
use App\Approov\State\ApproovState;
use App\Approov\State\ApproovStateStore;
use App\Approov\Verification\AuthContext;
use App\Approov\Verification\RequestVerifier;
use App\Approov\Verification\VerificationInput;

final class ApproovService
{
    public const AUTH_HEADER = 'Authorization';
    public const SESSION_ID_HEADER = 'SessionId';

    public function __construct(
        private readonly ApproovConfig $config,
        private readonly ApproovStateStore $stateStore,
        private readonly RequestVerifier $requestVerifier
    ) {
    }

    public function home(): array
    {
        return $this->infoPayload('Approov demo API is running on port 8080.');
    }

    public function approovState(): array
    {
        return $this->state()->toArray();
    }

    public function enableApproov(): array
    {
        return $this->stateStore->enableApproov()->toArray();
    }

    public function disableApproov(): array
    {
        return $this->stateStore->disableApproov()->toArray();
    }

    public function enableTokenBinding(): array
    {
        return $this->stateStore->enableTokenBinding()->toArray();
    }

    public function disableTokenBinding(): array
    {
        return $this->stateStore->disableTokenBinding()->toArray();
    }

    public function unprotected(): array
    {
        return $this->infoPayload("Unprotected endpoint '/unprotected'; no Approov checks performed.");
    }

    public function tokenCheck(): array
    {
        return $this->infoPayload("Protected endpoint '/token-check'; Approov token verified.");
    }

    public function tokenBinding(?string $authorization): array
    {
        $response = $this->infoPayload("Protected endpoint '/token-binding'; Approov token binding enforced.");
        $response['authorizationHeaderPresent'] = $this->hasText($authorization);

        return $response;
    }

    public function tokenDoubleBinding(?string $authorization, ?string $sessionId): array
    {
        $response = $this->infoPayload("Protected endpoint '/token-double-binding'; dual token binding enforced.");
        $response['authorizationHeaderPresent'] = $this->hasText($authorization);
        $response['sessionIdHeaderPresent'] = $this->hasText($sessionId);

        return $response;
    }

    public function approovHeader(): string
    {
        return $this->config->approovHeader();
    }

    public function requiredHeaders(array $boundHeaders): array
    {
        $state = $this->state();
        if (!$state->approovEnabled()) {
            return [];
        }

        $headers = [$this->config->approovHeader()];
        $normalizedBindingHeaders = $this->normalizeBindingHeaders($boundHeaders);

        if (!$state->tokenBindingEnabled() || $normalizedBindingHeaders === []) {
            return $headers;
        }

        return array_merge($headers, $normalizedBindingHeaders);
    }

    public function verifyRequest(VerificationInput $input): AuthContext
    {
        $state = $this->state();
        if (!$state->approovEnabled()) {
            return AuthContext::disabled();
        }

        $this->config->logIfApproovSecretMissing();

        return $this->requestVerifier->verify($input, $this->config, $state);
    }

    private function state(): ApproovState
    {
        return $this->stateStore->state();
    }

    private function infoPayload(string $details): array
    {
        $body = $this->state()->toArray();
        $body['details'] = $details;

        return $body;
    }

    private function normalizeBindingHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }

            $trimmed = trim($header);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
