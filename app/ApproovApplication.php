<?php

namespace App;

use Illuminate\Support\Facades\Log;

class ApproovApplication
{
    public const APPROOV_HEADER = 'Approov-Token';
    public const AUTH_HEADER = 'Authorization';
    public const DIGEST_HEADER = 'Content-Digest';

    private static bool $APPROOV_ENABLED = true;
    private static bool $TOKEN_BINDING_ENABLED = true;
    private static ?string $APPROOV_SECRET = null;

    public static function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public static function isApproovEnabled(): bool
    {
        return self::$APPROOV_ENABLED;
    }

    public static function isTokenBindingEnabled(): bool
    {
        return self::$TOKEN_BINDING_ENABLED;
    }

    public static function enableApproov(): void
    {
        self::$APPROOV_ENABLED = true;
        self::$TOKEN_BINDING_ENABLED = true;
    }

    public static function disableApproov(): void
    {
        self::$APPROOV_ENABLED = false;
        self::$TOKEN_BINDING_ENABLED = false;
    }

    public static function approovSecret(): string
    {
        if (self::$APPROOV_SECRET === null) {
            self::$APPROOV_SECRET = self::loadApproovSecret();
        }

        return self::$APPROOV_SECRET;
    }

    public static function home(): array
    {
        return self::infoPayload('Approov demo API is running on port 8080.');
    }

    public static function approovState(): array
    {
        return self::statePayload();
    }

    public static function enableApproovEndpoint(): array
    {
        self::enableApproov();
        return self::statePayload();
    }

    public static function disableApproovEndpoint(): array
    {
        self::disableApproov();
        return self::statePayload();
    }

    public static function enableTokenBindingEndpoint(): array
    {
        self::$TOKEN_BINDING_ENABLED = true;
        return self::statePayload();
    }

    public static function disableTokenBindingEndpoint(): array
    {
        self::$TOKEN_BINDING_ENABLED = false;
        return self::statePayload();
    }

    public static function unprotected(): array
    {
        return self::infoPayload("Unprotected endpoint '/unprotected'; no Approov checks performed.");
    }

    public static function tokenCheck(): array
    {
        return self::infoPayload("Protected endpoint '/token-check'; Approov token verified.");
    }

    public static function tokenBinding(?string $authorization): array
    {
        $response = self::infoPayload("Protected endpoint '/token-binding'; Approov token binding enforced.");
        $response['authorizationHeaderPresent'] = self::hasText($authorization);
        return $response;
    }

    public static function tokenDoubleBinding(?string $authorization, ?string $contentDigest): array
    {
        $response = self::infoPayload("Protected endpoint '/token-double-binding'; dual token binding enforced.");
        $response['authorizationHeaderPresent'] = self::hasText($authorization);
        $response['contentDigestHeaderPresent'] = self::hasText($contentDigest);
        return $response;
    }

    private static function statePayload(): array
    {
        return [
            'approovEnabled' => self::isApproovEnabled(),
            'tokenBindingEnabled' => self::isTokenBindingEnabled(),
        ];
    }

    private static function infoPayload(string $details): array
    {
        $body = self::statePayload();
        $body['details'] = $details;
        return $body;
    }

    private static function loadApproovSecret(): string
    {
        $secret = env('APPROOV_BASE64URL_SECRET');
        if (!self::hasText($secret)) {
            Log::error('APPROOV_BASE64URL_SECRET environment variable is not set');
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is not set');
        }

        $decoded = self::decodeBase64Url(trim($secret));
        if ($decoded === '') {
            Log::error('APPROOV_BASE64URL_SECRET environment variable is invalid');
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is invalid');
        }

        return $decoded;
    }

    private static function decodeBase64Url(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is invalid');
        }
        return $decoded;
    }
}
