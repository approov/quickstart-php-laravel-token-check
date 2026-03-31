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

    /**
     * Creates the service with configuration, state storage, and request verification dependencies.
     *
     * @param  ApproovConfig  $config  The Approov configuration accessor.
     * @param  ApproovStateStore  $stateStore  The store that persists Approov feature state.
     * @param  RequestVerifier  $requestVerifier  The verifier that validates incoming Approov requests.
     * @return void
     */
    public function __construct(
        private readonly ApproovConfig $config,
        private readonly ApproovStateStore $stateStore,
        private readonly RequestVerifier $requestVerifier
    ) {
    }

    /**
     * Returns the root endpoint payload describing the running demo API.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string}
     */
    public function home(): array
    {
        return $this->infoPayload('Approov demo API is running on port 8080.');
    }

    /**
     * Returns the current persisted Approov and token-binding state.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function approovState(): array
    {
        return $this->state()->toArray();
    }

    /**
     * Enables Approov verification and token binding in the state store.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function enableApproov(): array
    {
        return $this->stateStore->enableApproov()->toArray();
    }

    /**
     * Disables Approov verification and token binding in the state store.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function disableApproov(): array
    {
        return $this->stateStore->disableApproov()->toArray();
    }

    /**
     * Enables token binding in the state store.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function enableTokenBinding(): array
    {
        return $this->stateStore->enableTokenBinding()->toArray();
    }

    /**
     * Disables token binding in the state store.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool}
     */
    public function disableTokenBinding(): array
    {
        return $this->stateStore->disableTokenBinding()->toArray();
    }

    /**
     * Returns the response payload for the unprotected demo endpoint.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string}
     */
    public function unprotected(): array
    {
        return $this->infoPayload("Unprotected endpoint '/unprotected'; no Approov checks performed.");
    }

    /**
     * Returns the response payload for the Approov-protected token-check endpoint.
     *
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string}
     */
    public function tokenCheck(): array
    {
        return $this->infoPayload("Protected endpoint '/token-check'; Approov token verified.");
    }

    /**
     * Returns the token-binding endpoint payload and whether the Authorization header is present.
     *
     * @param  string|null  $authorization  The Authorization header value supplied with the request.
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string, authorizationHeaderPresent: bool}
     */
    public function tokenBinding(?string $authorization): array
    {
        $response = $this->infoPayload("Protected endpoint '/token-binding'; Approov token binding enforced.");
        $response['authorizationHeaderPresent'] = $this->hasText($authorization);

        return $response;
    }

    /**
     * Returns the dual token-binding endpoint payload and whether both bound headers are present.
     *
     * @param  string|null  $authorization  The Authorization header value supplied with the request.
     * @param  string|null  $sessionId  The SessionId header value supplied with the request.
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string, authorizationHeaderPresent: bool, sessionIdHeaderPresent: bool}
     */
    public function tokenDoubleBinding(?string $authorization, ?string $sessionId): array
    {
        $response = $this->infoPayload("Protected endpoint '/token-double-binding'; dual token binding enforced.");
        $response['authorizationHeaderPresent'] = $this->hasText($authorization);
        $response['sessionIdHeaderPresent'] = $this->hasText($sessionId);

        return $response;
    }

    /**
     * Returns the configured HTTP header name expected to carry the Approov token.
     *
     * @return string
     */
    public function approovHeader(): string
    {
        return $this->config->approovHeader();
    }

    /**
     * Returns the headers that clients must supply for the current Approov state.
     *
     * When Approov is enabled, the returned list always includes the Approov token header and
     * includes bound headers only when token binding is enabled and headers were configured.
     *
     * @param  array<int, mixed>  $boundHeaders  The raw bound header names configured for the route.
     * @return list<string>
     */
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

    /**
     * Verifies an incoming request according to the current Approov configuration and state.
     *
     * When Approov is disabled, this returns a disabled authentication context without performing verification.
     *
     * @param  VerificationInput  $input  The normalized request data to verify.
     * @return AuthContext
     *
     * @throws \App\Approov\Exceptions\ApproovAuthException
     */
    public function verifyRequest(VerificationInput $input): AuthContext
    {
        $state = $this->state();
        if (!$state->approovEnabled()) {
            return AuthContext::disabled();
        }

        $this->config->logIfApproovSecretMissing();

        return $this->requestVerifier->verify($input, $this->config, $state);
    }

    /**
     * Returns the current persisted Approov state.
     *
     * @return ApproovState
     */
    private function state(): ApproovState
    {
        return $this->stateStore->state();
    }

    /**
     * Builds a standard informational payload that includes the current Approov state.
     *
     * @param  string  $details  The endpoint-specific message to include in the payload.
     * @return array{approovEnabled: bool, tokenBindingEnabled: bool, details: string}
     */
    private function infoPayload(string $details): array
    {
        $body = $this->state()->toArray();
        $body['details'] = $details;

        return $body;
    }

    /**
     * Removes blank and non-string entries from a bound-header list.
     *
     * @param  array<int, mixed>  $headers  The raw bound header values to normalize.
     * @return list<string>
     */
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

    /**
     * Determines whether a nullable string contains any non-whitespace characters.
     *
     * @param  string|null  $value  The value to inspect.
     * @return bool
     */
    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
