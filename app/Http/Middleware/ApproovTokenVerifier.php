<?php

namespace App\Http\Middleware;

use App\ApproovApplication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApproovTokenVerifier
{
    private const APPROOV_PROTECTED_PATHS = [
        '/token-check',
        '/token-binding',
        '/token-double-binding',
    ];

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
        if (!ApproovApplication::isApproovEnabled()) {
            $request->attributes->set('approov_auth', $this->disabledAuthentication());
            return $next($request);
        }

        $rawToken = $request->header(ApproovApplication::APPROOV_HEADER);
        if (!ApproovApplication::hasText($rawToken)) {
            return $this->unauthorized();
        }

        try {
            $claims = $this->verifyApproovToken(trim($rawToken));
            $path = $request->getPathInfo();

            if ($this->needsBindingCheck($path) && ApproovApplication::isTokenBindingEnabled()) {
                $bindingValue = $this->extractBindingValue($path, $request);
                if (!ApproovApplication::hasText($bindingValue) || !$this->isBindingValid($bindingValue, $claims)) {
                    return $this->unauthorized();
                }
            }

            $request->attributes->set('approov_auth', ['principal' => 'approov-token']);
            return $next($request);
        } catch (\Throwable $e) {
            Log::error('Approov token verification failed: ' . $e->getMessage());
            return $this->unauthorized();
        }
    }

    protected function unauthorized(): Response
    {
        return response()->json(['message' => 'Approov authentication failed.'], 401);
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
        $digest = $this->trimOrNull($request->header(ApproovApplication::DIGEST_HEADER));
        if (!ApproovApplication::hasText($authorization) || !ApproovApplication::hasText($digest)) {
            return null;
        }

        return $authorization . $digest;
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
