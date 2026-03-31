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

    private function failUnauthorized(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode,
        array $context = []
    ): never {
        $this->fail($input, $state, $approovHeader, $errorCode, 401, self::UNAUTHORIZED_MESSAGE, $context);
    }

    private function failServer(
        VerificationInput $input,
        ApproovState $state,
        string $approovHeader,
        ApproovErrorCode $errorCode,
        array $context = []
    ): never {
        $this->fail($input, $state, $approovHeader, $errorCode, 500, self::INTERNAL_MESSAGE, $context);
    }

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

    private function runtimeErrorCode(\RuntimeException $e): ApproovErrorCode
    {
        return match ($e->getMessage()) {
            'APPROOV_BASE64URL_SECRET environment variable is not set' => ApproovErrorCode::ApproovSecretMissing,
            'APPROOV_BASE64URL_SECRET environment variable is invalid' => ApproovErrorCode::ApproovSecretInvalid,
            default => ApproovErrorCode::InternalVerificationError,
        };
    }

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

    private function payClaim(array $claims): ?string
    {
        $expected = $claims['pay'] ?? null;
        if (!is_string($expected)) {
            return null;
        }

        $trimmed = trim($expected);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hashBase64(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

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

    private function decodeJwtPart(string $value): array
    {
        $decoded = $this->base64UrlDecode($value);
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new \UnexpectedValueException('Invalid JWT payload.');
        }

        return $json;
    }

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

    private function mapJwtAlgorithm(?string $algorithm): string
    {
        return match ($algorithm) {
            'HS256' => 'sha256',
            default => throw new \UnexpectedValueException('Unsupported JWT algorithm.'),
        };
    }

    private function trimOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
