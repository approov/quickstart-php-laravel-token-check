<?php

declare(strict_types=1);

namespace App\Approov\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

final class ApproovConfig
{
    private const DEFAULT_APPROOV_HEADER = 'Approov-Token';
    private const APPROOV_SECRET_PLACEHOLDER = 'approov_base64url_secret_here';

    private ?string $approovSecret = null;
    private bool $approovSecretLogged = false;

    /**
     * Creates the configuration accessor with config and logging dependencies.
     *
     * @param  ConfigRepository  $config  The configuration repository for Approov settings.
     * @param  LoggerInterface  $logger  The logger used for missing or invalid secret diagnostics.
     * @return void
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns the configured Approov token header name or the default header when unset.
     *
     * @return string
     */
    public function approovHeader(): string
    {
        $value = $this->config->get('approov.token_header', self::DEFAULT_APPROOV_HEADER);
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed !== '' ? $trimmed : self::DEFAULT_APPROOV_HEADER;
    }

    /**
     * Returns the decoded Approov shared secret, loading and caching it on first access.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function approovSecret(): string
    {
        if ($this->approovSecret === null) {
            $this->approovSecret = $this->loadApproovSecret();
        }

        return $this->approovSecret;
    }

    /**
     * Logs a missing-secret error once when the configured Approov secret is absent or still a placeholder.
     *
     * @return void
     */
    public function logIfApproovSecretMissing(): void
    {
        $secret = $this->normalizedApproovSecret();
        if ($this->isApproovSecretMissing($secret)) {
            $this->logApproovSecretMissingOnce();
        }
    }

    /**
     * Loads, validates, and decodes the configured Approov secret.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    private function loadApproovSecret(): string
    {
        $secret = $this->normalizedApproovSecret();
        if ($this->isApproovSecretMissing($secret)) {
            $this->logApproovSecretMissingOnce();
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is not set');
        }

        $decoded = $this->decodeBase64Url($secret);
        if ($decoded === '') {
            $this->logger->error('APPROOV_BASE64URL_SECRET environment variable is invalid');
            throw new \RuntimeException('APPROOV_BASE64URL_SECRET environment variable is invalid');
        }

        return $decoded;
    }

    /**
     * Returns the normalized Approov secret value from configuration.
     *
     * @return string|null
     */
    private function normalizedApproovSecret(): ?string
    {
        return $this->normalizeApproovSecret($this->config->get('approov.base64url_secret'));
    }

    /**
     * Trims and unquotes a configured Approov secret value.
     *
     * @param  mixed  $secret  The raw configuration value to normalize.
     * @return string|null
     */
    private function normalizeApproovSecret(mixed $secret): ?string
    {
        if (!is_string($secret)) {
            return null;
        }

        $normalized = trim($secret);
        if ($normalized === '') {
            return null;
        }

        if ($this->hasWrappingQuotes($normalized)) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Determines whether a string is wrapped in matching single or double quotes.
     *
     * @param  string  $value  The value to inspect.
     * @return bool
     */
    private function hasWrappingQuotes(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];

        return ($first === '"' && $last === '"')
            || ($first === "'" && $last === "'");
    }

    /**
     * Determines whether the normalized secret is missing or still using the placeholder value.
     *
     * @param  string|null  $secret  The normalized secret value to inspect.
     * @return bool
     */
    private function isApproovSecretMissing(?string $secret): bool
    {
        if ($secret === null) {
            return true;
        }

        return strtolower($secret) === self::APPROOV_SECRET_PLACEHOLDER;
    }

    /**
     * Logs the missing-secret error only once for the lifetime of this configuration instance.
     *
     * @return void
     */
    private function logApproovSecretMissingOnce(): void
    {
        if ($this->approovSecretLogged) {
            return;
        }

        $this->logger->error('APPROOV_BASE64URL_SECRET environment variable is not set');
        $this->approovSecretLogged = true;
    }

    /**
     * Decodes a base64url-encoded string.
     *
     * @param  string  $value  The base64url-encoded value to decode.
     * @return string
     *
     * @throws \RuntimeException
     */
    private function decodeBase64Url(string $value): string
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
