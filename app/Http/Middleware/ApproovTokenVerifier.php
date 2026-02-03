<?php

namespace App\Http\Middleware;

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
            return $this->unauthorized($request, 'missing_approov_token', $bindingHeaders);
        }

        try {
            $claims = $this->verifyApproovToken(trim($rawToken));

            if (ApproovApplication::isTokenBindingEnabled() && $bindingHeaders !== []) {
                $bindingValue = $this->extractBindingValue($request, $bindingHeaders);
                if (!ApproovApplication::hasText($bindingValue)) {
                    return $this->unauthorized($request, 'missing_binding_header', $bindingHeaders);
                }
                if (!$this->isBindingValid($bindingValue, $claims)) {
                    return $this->unauthorized($request, 'binding_mismatch', $bindingHeaders);
                }
            }

            $request->attributes->set('approov_auth', ['principal' => 'approov-token']);
            return $next($request);
        } catch (\Throwable $e) {
            return $this->unauthorized($request, 'token_verification_failed', $bindingHeaders, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    protected function unauthorized(Request $request, string $reason, array $bindingHeaders = [], array $context = []): Response
    {
        $context = array_merge($this->baseLogContext($request, $reason, $bindingHeaders), $context);
        $request->attributes->set(self::APPROOV_FAILURE_ATTRIBUTE, $context);

        return response()->json(['message' => 'Approov authentication failed.'], 401);
    }

    private function baseLogContext(Request $request, string $reason, array $bindingHeaders): array
    {
        $context = [
            'reason' => $reason,
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

    protected function isBindingValid(string $bindingValue, array $claims): bool
    {
        $expected = $claims['pay'] ?? null;
        if (!ApproovApplication::hasText($expected)) {
            return false;
        }

        $computed = $this->hashBase64Url($bindingValue);
        return trim($expected) === $computed;
    }

    protected function hashBase64Url(string $value): string
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
