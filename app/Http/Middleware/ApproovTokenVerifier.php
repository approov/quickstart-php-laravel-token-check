<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Approov\ApproovAuthException;
use App\Approov\ApproovErrorCode;
use App\ApproovApplication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApproovTokenVerifier
{
    private const REQUEST_ID_HEADER = 'X-Request-Id';
    private const REQUEST_ID_ATTRIBUTE = 'request_id';
    private const APPROOV_REQUIRED_HEADERS_ATTRIBUTE = 'approov_required_headers';
    private const APPROOV_FAILURE_ATTRIBUTE = 'approov_failure';
    private const UNAUTHORIZED_MESSAGE = 'Approov authentication failed.';
    private const INTERNAL_MESSAGE = 'Approov verification failed.';

    public function handle(Request $request, Closure $next, ...$boundHeaders): Response
    {
        return $this->doFilterInternal($request, $next, $boundHeaders);
    }

    protected function doFilterInternal(Request $request, Closure $next, array $boundHeaders = []): Response
    {
        $bindingHeaders = $this->normalizeBindingHeaders($boundHeaders);

        if (ApproovApplication::isApproovEnabled()) {
            ApproovApplication::logIfApproovSecretMissing();
            $request->attributes->set(
                self::APPROOV_REQUIRED_HEADERS_ATTRIBUTE,
                $this->requiredHeaders($bindingHeaders)
            );
        }

        if (!ApproovApplication::isApproovEnabled()) {
            $request->attributes->set('approov_auth', $this->disabledAuthentication());
            return $next($request);
        }

        $rawToken = $request->header(ApproovApplication::approovHeader());
        if (!ApproovApplication::hasText($rawToken)) {
            $this->failUnauthorized($request, ApproovErrorCode::MissingApproovToken, $bindingHeaders);
        }

        try {
            $claims = $this->verifyApproovToken(trim($rawToken));

            if (ApproovApplication::isTokenBindingEnabled() && $bindingHeaders !== []) {
                $bindingValue = $this->extractBindingValue($request, $bindingHeaders);
                if (!ApproovApplication::hasText($bindingValue)) {
                    $this->failUnauthorized($request, ApproovErrorCode::MissingBindingHeader, $bindingHeaders);
                }

                $expectedBindingHash = $this->payClaim($claims);
                if (!ApproovApplication::hasText($expectedBindingHash)) {
                    $this->failUnauthorized($request, ApproovErrorCode::MissingPayClaim, $bindingHeaders);
                }

                $computedBindingHash = $this->hashBase64($bindingValue);
                if (!hash_equals($expectedBindingHash, $computedBindingHash)) {
                    $this->failUnauthorized($request, ApproovErrorCode::BindingMismatch, $bindingHeaders);
                }
            }

            $request->attributes->set('approov_auth', ['principal' => 'approov-token']);
            return $next($request);
        } catch (\Throwable $e) {
            $this->handleVerificationException($request, $e, $bindingHeaders);
        }
    }

    protected function failUnauthorized(
        Request $request,
        ApproovErrorCode $errorCode,
        array $bindingHeaders = [],
        array $context = []
    ): never {
        $this->fail($request, $errorCode, 401, self::UNAUTHORIZED_MESSAGE, $bindingHeaders, $context);
    }

    protected function failServer(
        Request $request,
        ApproovErrorCode $errorCode,
        array $bindingHeaders = [],
        array $context = []
    ): never {
        $this->fail($request, $errorCode, 500, self::INTERNAL_MESSAGE, $bindingHeaders, $context);
    }

    private function fail(
        Request $request,
        ApproovErrorCode $errorCode,
        int $httpStatus,
        string $safeMessage,
        array $bindingHeaders = [],
        array $context = []
    ): never {
        $context = array_merge($this->baseLogContext($request, $errorCode, $bindingHeaders), $context);
        $request->attributes->set(self::APPROOV_FAILURE_ATTRIBUTE, $context);

        throw new ApproovAuthException($errorCode, $httpStatus, $safeMessage, $context);
    }

    private function handleVerificationException(Request $request, \Throwable $e, array $bindingHeaders): never
    {
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
                $this->failUnauthorized($request, $errorCode, $bindingHeaders, $context);
            }

            $this->failServer($request, $errorCode, $bindingHeaders, $context);
        }

        if ($e instanceof \RuntimeException) {
            $this->failServer($request, $this->runtimeErrorCode($e), $bindingHeaders, $context);
        }

        $this->failServer($request, ApproovErrorCode::InternalVerificationError, $bindingHeaders, $context);
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

    private function baseLogContext(Request $request, ApproovErrorCode $errorCode, array $bindingHeaders): array
    {
        $context = [
            'reason' => $errorCode->value,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'approov' => [
                'enabled' => ApproovApplication::isApproovEnabled(),
                'binding_enabled' => ApproovApplication::isTokenBindingEnabled(),
                'headers' => $this->approovHeaderFlags($request, $bindingHeaders),
            ],
        ];

        $requestId = $this->requestId($request);
        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        return $context;
    }

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fromHeader = $request->header(self::REQUEST_ID_HEADER);
        if (!is_string($fromHeader)) {
            return null;
        }

        $trimmed = trim($fromHeader);
        return $trimmed === '' ? null : $trimmed;
    }

    private function approovHeaderFlags(Request $request, array $bindingHeaders): array
    {
        $flags = [
            'approov_token' => ApproovApplication::hasText($request->header(ApproovApplication::approovHeader())),
        ];

        if (ApproovApplication::isTokenBindingEnabled() && $bindingHeaders !== []) {
            $bindingFlags = [];
            foreach ($bindingHeaders as $header) {
                $bindingFlags[$header] = ApproovApplication::hasText($request->header($header));
            }
            $flags['binding_headers'] = $bindingFlags;
        }

        return $flags;
    }

    private function requiredHeaders(array $bindingHeaders): array
    {
        $headers = [ApproovApplication::approovHeader()];

        if (!ApproovApplication::isTokenBindingEnabled() || $bindingHeaders === []) {
            return $headers;
        }

        return array_merge($headers, $bindingHeaders);
    }

    protected function verifyApproovToken(string $token): array
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
        $expected = hash_hmac($hashAlgorithm, $signingInput, ApproovApplication::approovSecret(), true);

        if (!hash_equals($expected, $signature)) {
            throw new \UnexpectedValueException('Invalid JWT signature.');
        }

        $this->validateExpiration($payload);
        return $payload;
    }

    protected function extractBindingValue(Request $request, array $bindingHeaders): ?string
    {
        $values = [];
        foreach ($bindingHeaders as $header) {
            $value = $this->trimOrNull($request->header($header));
            if (!ApproovApplication::hasText($value)) {
                return null;
            }
            $values[] = $value;
        }

        return implode('', $values);
    }

    protected function payClaim(array $claims): ?string
    {
        $expected = $claims['pay'] ?? null;
        if (!is_string($expected)) {
            return null;
        }

        $trimmed = trim($expected);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function hashBase64(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    protected function disabledAuthentication(): array
    {
        return ['principal' => 'approov-disabled'];
    }

    protected function validateExpiration(array $claims): void
    {
        if (!array_key_exists('exp', $claims)) {
            throw new \UnexpectedValueException('Approov token missing expiration.');
        }

        $expiration = (int) $claims['exp'];
        if ($expiration < time()) {
            throw new \UnexpectedValueException('Approov token expired.');
        }
    }

    protected function trimOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
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
}
