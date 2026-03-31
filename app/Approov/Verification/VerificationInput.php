<?php

declare(strict_types=1);

namespace App\Approov\Verification;

final class VerificationInput
{
    private readonly array $boundHeaders;
    private readonly array $headerValues;

    /**
     * Creates a normalized snapshot of the request data used during Approov verification.
     *
     * @param  string  $method  The HTTP method of the request being verified.
     * @param  string  $path  The request path being verified.
     * @param  string|null  $requestId  The request identifier associated with the request.
     * @param  string|null  $approovToken  The raw Approov token value supplied with the request.
     * @param  array<int, mixed>  $boundHeaders  The raw header names that participate in token binding.
     * @param  array<string, mixed>  $headerValues  The request header values indexed by header name.
     * @return void
     */
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

    /**
     * Returns the HTTP method of the request being verified.
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Returns the request path being verified.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns the request identifier associated with the verification attempt.
     *
     * @return string|null
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Returns the raw Approov token supplied with the request.
     *
     * @return string|null
     */
    public function approovToken(): ?string
    {
        return $this->approovToken;
    }

    /**
     * Returns the normalized list of header names required for token binding.
     *
     * @return list<string>
     */
    public function boundHeaders(): array
    {
        return $this->boundHeaders;
    }

    /**
     * Returns the raw header value stored for a given binding header.
     *
     * @param  string  $header  The normalized header name to look up.
     * @return string|null
     */
    public function headerValue(string $header): ?string
    {
        $value = $this->headerValues[$header] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Removes blank and non-string entries from a bound-header list.
     *
     * @param  array<int, mixed>  $headers  The raw header names to normalize.
     * @return list<string>
     */
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
