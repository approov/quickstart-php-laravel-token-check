<?php

declare(strict_types=1);

namespace App\Approov\Verification;

final class VerificationInput
{
    private readonly array $boundHeaders;
    private readonly array $headerValues;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly ?string $requestId,
        private readonly ?string $approovToken,
        array $boundHeaders,
        array $headerValues
    ) {
        $this->boundHeaders = $this->normalizeBindingHeaders($boundHeaders);
        $this->headerValues = $headerValues;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function approovToken(): ?string
    {
        return $this->approovToken;
    }

    public function boundHeaders(): array
    {
        return $this->boundHeaders;
    }

    public function headerValue(string $header): ?string
    {
        $value = $this->headerValues[$header] ?? null;

        return is_string($value) ? $value : null;
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
}
