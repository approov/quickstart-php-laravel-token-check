<?php

declare(strict_types=1);

namespace App\Approov\Verification;

use App\Approov\Config\ApproovConfig;
use App\Approov\Exceptions\ApproovAuthException;
use App\Approov\Exceptions\ApproovErrorCode;
use App\Approov\State\ApproovState;

final class LocalRequestVerifier implements RequestVerifier
{
    private const UNAUTHORIZED_MESSAGE = 'Approov authentication failed.';
    private const INTERNAL_MESSAGE = 'Approov verification failed.';

    /**
     * Verifies the Approov token and any configured binding headers for an incoming request.
     *
     * @param  VerificationInput  $input  The normalized request data to verify.
     * @param  ApproovConfig  $config  The configuration used to resolve the token header and shared secret.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @return AuthContext
     *
     * @throws ApproovAuthException
     */
    public function verify(VerificationInput $input, ApproovConfig $config, ApproovState $state): AuthContext
    {
        $rawToken = $this->trimOrNull($input->approovToken());
        if (!$this->hasText($rawToken)) {
            $this->failUnauthorized($input, $state, $config->approovHeader(), ApproovErrorCode::MissingApproovToken);
        }

        try {
            $claims = $this->verifyApproovToken($rawToken, $config->approovSecret());

            if ($state->tokenBindingEnabled() && $input->boundHeaders() !== []) {
                $bindingValue = $this->extractBindingValue($input);
                if (!$this->hasText($bindingValue)) {
                    $this->failUnauthorized($input, $state, $config->approovHeader(), ApproovErrorCode::MissingBindingHeader);
                }

                $expectedBindingHash = $this->payClaim($claims);
                if (!$this->hasText($expectedBindingHash)) {
                    $this->failUnauthorized($input, $state, $config->approovHeader(), ApproovErrorCode::MissingPayClaim);
                }

                $computedBindingHash = $this->hashBase64($bindingValue);
                if (!hash_equals($expectedBindingHash, $computedBindingHash)) {
                    $this->failUnauthorized($input, $state, $config->approovHeader(), ApproovErrorCode::BindingMismatch);
                }
            }

            return AuthContext::approovToken();
        } catch (\Throwable $e) {
            $this->handleVerificationException($input, $state, $config->approovHeader(), $e);
        }
    }

    /**
     * Throws an unauthorized Approov authentication exception for a client-side verification failure.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name used to carry the Approov token.
     * @param  ApproovErrorCode  $errorCode  The specific unauthorized failure code.
     * @param  array<string, mixed>  $context  Additional failure context to merge into the exception payload.
     * @return never
     *
     * @throws ApproovAuthException
     */
    private function failUnauthorized(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode,
        array $context = []
    ): never {
        $this->fail($input, $state, $approovHeader, $errorCode, 401, self::UNAUTHORIZED_MESSAGE, $context);
    }

    /**
     * Throws an internal-server Approov authentication exception for a server-side verification failure.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name used to carry the Approov token.
     * @param  ApproovErrorCode  $errorCode  The specific internal failure code.
     * @param  array<string, mixed>  $context  Additional failure context to merge into the exception payload.
     * @return never
     *
     * @throws ApproovAuthException
     */
    private function failServer(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode,
        array $context = []
    ): never {
        $this->fail($input, $state, $approovHeader, $errorCode, 500, self::INTERNAL_MESSAGE, $context);
    }

    /**
     * Throws an Approov authentication exception with the supplied status, message, and merged context.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name used to carry the Approov token.
     * @param  ApproovErrorCode  $errorCode  The specific failure code to attach to the exception.
     * @param  int  $httpStatus  The HTTP status to expose for this failure.
     * @param  string  $safeMessage  The sanitized client-facing error message.
     * @param  array<string, mixed>  $context  Additional failure context to merge into the exception payload.
     * @return never
     *
     * @throws ApproovAuthException
     */
    private function fail(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode,
        int $httpStatus,
        string $safeMessage,
        array $context = []
    ): never {
        $context = array_merge($this->baseLogContext($input, $state, $approovHeader, $errorCode), $context);

        throw new ApproovAuthException($errorCode, $httpStatus, $safeMessage, $context);
    }

    /**
     * Normalizes unexpected verifier exceptions into the appropriate Approov authentication failure.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name used to carry the Approov token.
     * @param  \Throwable  $e  The exception raised during verification.
     * @return never
     *
     * @throws ApproovAuthException
     */
    private function handleVerificationException(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        \Throwable $e
    ): never {
        if ($e instanceof ApproovAuthException) {
            throw $e;
        }

        $context = [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        if ($e instanceof \UnexpectedValueException) {
            $errorCode = $this->unexpectedValueErrorCode($e);
            if ($this->isUnauthorizedError($errorCode)) {
                $this->failUnauthorized($input, $state, $approovHeader, $errorCode, $context);
            }

            $this->failServer($input, $state, $approovHeader, $errorCode, $context);
        }

        if ($e instanceof \TypeError || $e instanceof \ValueError) {
            $errorCode = $this->typeOrValueErrorCode($e);
            if ($this->isUnauthorizedError($errorCode)) {
                $this->failUnauthorized($input, $state, $approovHeader, $errorCode, $context);
            }

            $this->failServer($input, $state, $approovHeader, $errorCode, $context);
        }

        if ($e instanceof \RuntimeException) {
            $this->failServer($input, $state, $approovHeader, $this->runtimeErrorCode($e), $context);
        }

        $this->failServer($input, $state, $approovHeader, ApproovErrorCode::InternalVerificationError, $context);
    }

    /**
     * Maps JWT parsing and validation errors to an Approov error code.
     *
     * @param  \UnexpectedValueException  $e  The parsing or validation exception to classify.
     * @return ApproovErrorCode
     */
    private function unexpectedValueErrorCode(\UnexpectedValueException $e): ApproovErrorCode
    {
        return match ($e->getMessage()) {
            'Invalid JWT format.',
            'Invalid JWT payload.',
            'Invalid base64url data.',
            'Approov token missing expiration.' => ApproovErrorCode::InvalidTokenFormat,
            'Unsupported JWT algorithm.' => ApproovErrorCode::UnsupportedTokenAlgorithm,
            'Invalid JWT signature.' => ApproovErrorCode::InvalidTokenSignature,
            'Approov token expired.' => ApproovErrorCode::TokenExpired,
            default => ApproovErrorCode::InternalVerificationError,
        };
    }

    /**
     * Maps runtime configuration failures to an Approov error code.
     *
     * @param  \RuntimeException  $e  The runtime exception to classify.
     * @return ApproovErrorCode
     */
    private function runtimeErrorCode(\RuntimeException $e): ApproovErrorCode
    {
        return match ($e->getMessage()) {
            'APPROOV_BASE64URL_SECRET environment variable is not set' => ApproovErrorCode::ApproovSecretMissing,
            'APPROOV_BASE64URL_SECRET environment variable is invalid' => ApproovErrorCode::ApproovSecretInvalid,
            default => ApproovErrorCode::InternalVerificationError,
        };
    }

    /**
     * Infers an Approov error code from a type or value error raised during verification.
     *
     * @param  \Throwable  $e  The type or value error to classify.
     * @return ApproovErrorCode
     */
    private function typeOrValueErrorCode(\Throwable $e): ApproovErrorCode
    {
        if ($this->isAlgorithmTypeError($e)) {
            return ApproovErrorCode::UnsupportedTokenAlgorithm;
        }

        if ($this->isClaimTypeError($e)) {
            return ApproovErrorCode::InvalidTokenFormat;
        }

        return ApproovErrorCode::InternalVerificationError;
    }

    /**
     * Determines whether a throwable originated from invalid JWT algorithm handling.
     *
     * @param  \Throwable  $e  The throwable to inspect.
     * @return bool
     */
    private function isAlgorithmTypeError(\Throwable $e): bool
    {
        if ($e instanceof \TypeError && str_contains($e->getMessage(), 'mapJwtAlgorithm')) {
            return true;
        }

        if ($e instanceof \ValueError && str_contains($e->getMessage(), 'hash_hmac')) {
            return true;
        }

        foreach ($e->getTrace() as $frame) {
            if (($frame['class'] ?? null) !== self::class) {
                continue;
            }

            if (($frame['function'] ?? null) === 'mapJwtAlgorithm') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a throwable originated from invalid JWT claim decoding or validation.
     *
     * @param  \Throwable  $e  The throwable to inspect.
     * @return bool
     */
    private function isClaimTypeError(\Throwable $e): bool
    {
        foreach ($e->getTrace() as $frame) {
            if (($frame['class'] ?? null) !== self::class) {
                continue;
            }

            if (in_array($frame['function'] ?? '', [
                'verifyApproovToken',
                'decodeJwtPart',
                'base64UrlDecode',
                'validateExpiration',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether an error code should be reported to the client as unauthorized.
     *
     * @param  ApproovErrorCode  $errorCode  The error code to classify.
     * @return bool
     */
    private function isUnauthorizedError(ApproovErrorCode $errorCode): bool
    {
        return match ($errorCode) {
            ApproovErrorCode::MissingApproovToken,
            ApproovErrorCode::InvalidTokenFormat,
            ApproovErrorCode::UnsupportedTokenAlgorithm,
            ApproovErrorCode::InvalidTokenSignature,
            ApproovErrorCode::TokenExpired,
            ApproovErrorCode::MissingBindingHeader,
            ApproovErrorCode::MissingPayClaim,
            ApproovErrorCode::BindingMismatch => true,
            default => false,
        };
    }

    /**
     * Builds the base failure context attached to Approov authentication exceptions.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name used to carry the Approov token.
     * @param  ApproovErrorCode  $errorCode  The failure code being reported.
     * @return array<string, mixed>
     */
    private function baseLogContext(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode
    ): array {
        $context = [
            'reason' => $errorCode->value,
            'method' => $input->method(),
            'path' => $input->path(),
            'approov' => [
                'enabled' => $state->approovEnabled(),
                'binding_enabled' => $state->tokenBindingEnabled(),
                'headers' => $this->approovHeaderFlags($input, $state, $approovHeader),
            ],
        ];

        $requestId = $input->requestId();
        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        return $context;
    }

    /**
     * Returns booleans indicating which Approov-related headers were present on the request.
     *
     * @param  VerificationInput  $input  The request data being verified.
     * @param  ApproovState  $state  The current Approov and token-binding state.
     * @param  string  $approovHeader  The configured header name associated with the verification attempt.
     * @return array<string, bool|array<string, bool>>
     */
    private function approovHeaderFlags(VerificationInput $input, ApproovState $state, string $approovHeader): array
    {
        $flags = [
            'approov_token' => $this->hasText($input->approovToken()),
        ];

        if ($state->tokenBindingEnabled() && $input->boundHeaders() !== []) {
            $bindingFlags = [];
            foreach ($input->boundHeaders() as $header) {
                $bindingFlags[$header] = $this->hasText($input->headerValue($header));
            }
            $flags['binding_headers'] = $bindingFlags;
        }

        return $flags;
    }

    /**
     * Verifies the JWT structure, signature, and expiration of an Approov token.
     *
     * @param  string  $token  The raw JWT token supplied by the client.
     * @param  string  $secret  The decoded shared secret used to verify the token signature.
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function verifyApproovToken(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \UnexpectedValueException('Invalid JWT format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJwtPart($encodedHeader);
        $payload = $this->decodeJwtPart($encodedPayload);

        $algorithm = $header['alg'] ?? null;
        $hashAlgorithm = $this->mapJwtAlgorithm($algorithm);

        $signature = $this->base64UrlDecode($encodedSignature);
        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $expected = hash_hmac($hashAlgorithm, $signingInput, $secret, true);

        if (!hash_equals($expected, $signature)) {
            throw new \UnexpectedValueException('Invalid JWT signature.');
        }

        $this->validateExpiration($payload);

        return $payload;
    }

    /**
     * Concatenates the configured bound header values or returns null when any required header is blank.
     *
     * @param  VerificationInput  $input  The request data carrying the bound header values.
     * @return string|null
     */
    private function extractBindingValue(VerificationInput $input): ?string
    {
        $values = [];
        foreach ($input->boundHeaders() as $header) {
            $value = $this->trimOrNull($input->headerValue($header));
            if (!$this->hasText($value)) {
                return null;
            }

            $values[] = $value;
        }

        return implode('', $values);
    }

    /**
     * Returns the non-empty pay claim from a verified token payload.
     *
     * @param  array<string, mixed>  $claims  The verified JWT payload claims.
     * @return string|null
     */
    private function payClaim(array $claims): ?string
    {
        $expected = $claims['pay'] ?? null;
        if (!is_string($expected)) {
            return null;
        }

        $trimmed = trim($expected);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns the base64-encoded SHA-256 hash of a token-binding value.
     *
     * @param  string  $value  The concatenated binding value to hash.
     * @return string
     */
    private function hashBase64(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    /**
     * Ensures the verified token payload contains a future expiration timestamp.
     *
     * @param  array<string, mixed>  $claims  The verified JWT payload claims.
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function validateExpiration(array $claims): void
    {
        if (!array_key_exists('exp', $claims)) {
            throw new \UnexpectedValueException('Approov token missing expiration.');
        }

        $expiration = (int) $claims['exp'];
        if ($expiration < time()) {
            throw new \UnexpectedValueException('Approov token expired.');
        }
    }

    /**
     * Decodes and JSON-parses a JWT header or payload segment.
     *
     * @param  string  $value  The base64url-encoded JWT segment to decode.
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function decodeJwtPart(string $value): array
    {
        $decoded = $this->base64UrlDecode($value);
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new \UnexpectedValueException('Invalid JWT payload.');
        }

        return $json;
    }

    /**
     * Decodes a base64url-encoded JWT segment.
     *
     * @param  string  $value  The base64url-encoded value to decode.
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * Maps a JWT algorithm name to the hashing algorithm used for signature verification.
     *
     * @param  string|null  $algorithm  The JWT alg header value to map.
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    private function mapJwtAlgorithm(?string $algorithm): string
    {
        return match ($algorithm) {
            'HS256' => 'sha256',
            default => throw new \UnexpectedValueException('Unsupported JWT algorithm.'),
        };
    }

    /**
     * Trims a nullable string while preserving empty-string results for blank input.
     *
     * @param  string|null  $value  The value to normalize.
     * @return string|null
     */
    private function trimOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
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
