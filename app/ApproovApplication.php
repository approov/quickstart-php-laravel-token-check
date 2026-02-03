<?php

namespace App;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApproovApplication
{
    public const APPROOV_HEADER = 'Approov-Token';
    public const AUTH_HEADER = 'Authorization';
    public const SESSION_ID_HEADER = 'SessionId';

    private const APPROOV_ENABLED_KEY = 'approov_enabled';
    private const TOKEN_BINDING_ENABLED_KEY = 'approov_token_binding_enabled';

    private static ?string $APPROOV_SECRET = null;
    private static bool $APPROOV_SECRET_LOGGED = false;

    public static function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public static function isApproovEnabled(): bool
    {
        return Cache::get(self::APPROOV_ENABLED_KEY, true);
    }

    public static function isTokenBindingEnabled(): bool
    {
        return Cache::get(self::TOKEN_BINDING_ENABLED_KEY, true);
    }

    public static function enableApproov(): void
    {
        Cache::forever(self::APPROOV_ENABLED_KEY, true);
        Cache::forever(self::TOKEN_BINDING_ENABLED_KEY, true);
    }

    public static function disableApproov(): void
    {
        Cache::forever(self::APPROOV_ENABLED_KEY, false);
        Cache::forever(self::TOKEN_BINDING_ENABLED_KEY, false);
    }

    public static function approovSecret(): string
    {
        if (self::$APPROOV_SECRET === null) {
            self::$APPROOV_SECRET = self::loadApproovSecret();
        }

        return self::$APPROOV_SECRET;
    }

    public static function logIfApproovSecretMissing(): void
    {
        $secret = env('APPROOV_BASE64URL_SECRET');
        if (self::isApproovSecretMissing($secret)) {
            self::logApproovSecretMissingOnce();
        }
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
        Cache::forever(self::TOKEN_BINDING_ENABLED_KEY, true);
        return self::statePayload();
    }

    public static function disableTokenBindingEndpoint(): array
    {
        Cache::forever(self::TOKEN_BINDING_ENABLED_KEY, false);
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

    public static function tokenDoubleBinding(?string $authorization, ?string $sessionId): array
    {
        $response = self::infoPayload("Protected endpoint '/token-double-binding'; dual token binding enforced.");
        $response['authorizationHeaderPresent'] = self::hasText($authorization);
        $response['sessionIdHeaderPresent'] = self::hasText($sessionId);
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
        if (self::isApproovSecretMissing($secret)) {
            self::logApproovSecretMissingOnce();
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is not set');
        }

        $decoded = self::decodeBase64Url(trim($secret));
        if ($decoded === '') {
            Log::error('APPROOV_BASE64URL_SECRET environment variable is invalid');
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is invalid');
        }

        return $decoded;
    }

    private static function isApproovSecretMissing(?string $secret): bool
    {
        if (!self::hasText($secret)) {
            return true;
        }

        $trimmed = trim($secret);
        $unquoted = trim($trimmed, "\"'");
        $lowered = strtolower($unquoted);
        return $lowered === 'approov_base64url_secret_here';
    }

    private static function logApproovSecretMissingOnce(): void
    {
        if (self::$APPROOV_SECRET_LOGGED) {
            return;
        }

        Log::error('APPROOV_BASE64URL_SECRET environment variable is not set');
        self::$APPROOV_SECRET_LOGGED = true;
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
