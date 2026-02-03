<?php

namespace App\Http\Middleware;

use App\ApproovApplication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApproovTokenVerifier
{
    private const APPROOV_PROTECTED_PATHS = [
        '/token-check',
        '/token-binding',
        '/token-double-binding',
    ];
    private const REQUEST_ID_HEADER = 'X-Request-Id';
    private const REQUEST_ID_ATTRIBUTE = 'request_id';
    private const APPROOV_REQUIRED_HEADERS_ATTRIBUTE = 'approov_required_headers';
    private const APPROOV_FAILURE_ATTRIBUTE = 'approov_failure';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldNotFilter($request)) {
            return $next($request);
        }

        return $this->doFilterInternal($request, $next);
    }

    protected function shouldNotFilter(Request $request): bool
    {
        $path = $request->getPathInfo();
        return $path === null || !in_array($path, self::APPROOV_PROTECTED_PATHS, true);
    }

    protected function doFilterInternal(Request $request, Closure $next): Response
    {
        if (ApproovApplication::isApproovEnabled()) {
            ApproovApplication::logIfApproovSecretMissing();
            $request->attributes->set(
                self::APPROOV_REQUIRED_HEADERS_ATTRIBUTE,
                $this->requiredHeaders($request)
            );
        }

        if (!ApproovApplication::isApproovEnabled()) {
            $request->attributes->set('approov_auth', $this->disabledAuthentication());
            return $next($request);
        }

        $rawToken = $request->header(ApproovApplication::APPROOV_HEADER);
        if (!ApproovApplication::hasText($rawToken)) {
            return $this->unauthorized($request, 'missing_approov_token');
        }

        try {
            $claims = $this->verifyApproovToken(trim($rawToken));
            $path = $request->getPathInfo();

            if ($this->needsBindingCheck($path) && ApproovApplication::isTokenBindingEnabled()) {
                $bindingValue = $this->extractBindingValue($path, $request);
                if (!ApproovApplication::hasText($bindingValue)) {
                    return $this->unauthorized($request, 'missing_binding_header');
                }
                if (!$this->isBindingValid($bindingValue, $claims)) {
                    return $this->unauthorized($request, 'binding_mismatch');
                }
            }

            $request->attributes->set('approov_auth', ['principal' => 'approov-token']);
            return $next($request);
        } catch (\Throwable $e) {
            return $this->unauthorized($request, 'token_verification_failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    protected function unauthorized(Request $request, string $reason, array $context = []): Response
    {
        $context = array_merge($this->baseLogContext($request, $reason), $context);
        $request->attributes->set(self::APPROOV_FAILURE_ATTRIBUTE, $context);

        return response()->json(['message' => 'Approov authentication failed.'], 401);
    }

    private function baseLogContext(Request $request, string $reason): array
    {
        $context = [
            'reason' => $reason,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'approov' => [
                'enabled' => ApproovApplication::isApproovEnabled(),
                'binding_enabled' => ApproovApplication::isTokenBindingEnabled(),
                'headers' => $this->approovHeaderFlags($request),
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

    private function approovHeaderFlags(Request $request): array
    {
        $flags = [
            'approov_token' => ApproovApplication::hasText($request->header(ApproovApplication::APPROOV_HEADER)),
        ];

        if (ApproovApplication::isTokenBindingEnabled() && $this->needsBindingCheck($request->getPathInfo())) {
            $flags['authorization'] = ApproovApplication::hasText($request->header(ApproovApplication::AUTH_HEADER));
            if ($request->getPathInfo() === '/token-double-binding') {
                $flags['session_id'] = ApproovApplication::hasText($request->header(ApproovApplication::SESSION_ID_HEADER));
            }
        }

        return $flags;
    }

    private function requiredHeaders(Request $request): array
    {
        $headers = [ApproovApplication::APPROOV_HEADER];

        if (!ApproovApplication::isTokenBindingEnabled()) {
            return $headers;
        }

        $path = $request->getPathInfo();
        if ($path === '/token-binding' || $path === '/token-double-binding') {
            $headers[] = ApproovApplication::AUTH_HEADER;
        }
        if ($path === '/token-double-binding') {
            $headers[] = ApproovApplication::SESSION_ID_HEADER;
        }

        return $headers;
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

    protected function needsBindingCheck(?string $path): bool
    {
        return $path === '/token-binding' || $path === '/token-double-binding';
    }

    protected function extractBindingValue(?string $path, Request $request): ?string
    {
        if ($path === '/token-binding') {
            return $this->trimOrNull($request->header(ApproovApplication::AUTH_HEADER));
        }

        $authorization = $this->trimOrNull($request->header(ApproovApplication::AUTH_HEADER));
        $sessionId = $this->trimOrNull($request->header(ApproovApplication::SESSION_ID_HEADER));
        if (!ApproovApplication::hasText($authorization) || !ApproovApplication::hasText($sessionId)) {
            return null;
        }

        return $authorization . $sessionId;
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
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => throw new \UnexpectedValueException('Unsupported JWT algorithm.'),
        };
    }
}
