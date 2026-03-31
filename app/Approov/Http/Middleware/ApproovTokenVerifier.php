<?php

declare(strict_types=1);

namespace App\Approov\Http\Middleware;

use App\Approov\ApproovService;
use App\Approov\Exceptions\ApproovAuthException;
use App\Approov\Support\ApproovRequestAttributes;
use App\Approov\Verification\VerificationInput;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApproovTokenVerifier
{
    /**
     * Creates the middleware with the Approov application service dependency.
     *
     * @param  ApproovService  $approovService  The service that performs request verification and state lookups.
     * @return void
     */
    public function __construct(
        private readonly ApproovService $approovService
    ) {
    }

    /**
     * Verifies the incoming request's Approov token and stores verification state on the request.
     *
     * This middleware records the required headers, stores the authentication context on success,
     * and stores failure context on the request before rethrowing authentication failures.
     *
     * @param  Request  $request  The incoming request being verified.
     * @param  Closure(Request): Response  $next  The next middleware or controller action in the pipeline.
     * @param  string  ...$boundHeaders  Header names that must participate in token binding verification.
     * @return Response
     *
     * @throws ApproovAuthException
     */
    public function handle(Request $request, Closure $next, ...$boundHeaders): Response
    {
        $normalizedBindingHeaders = $this->normalizeBindingHeaders($boundHeaders);
        $request->attributes->set(
            ApproovRequestAttributes::REQUIRED_HEADERS,
            $this->approovService->requiredHeaders($normalizedBindingHeaders)
        );

        $input = new VerificationInput(
            $request->getMethod(),
            $request->getPathInfo(),
            $this->requestId($request),
            $this->trimOrNull($request->header($this->approovService->approovHeader())),
            $normalizedBindingHeaders,
            $this->headerValues($request, $normalizedBindingHeaders)
        );

        try {
            $authContext = $this->approovService->verifyRequest($input);
            $request->attributes->set(ApproovRequestAttributes::AUTH_CONTEXT, $authContext);

            return $next($request);
        } catch (ApproovAuthException $e) {
            $request->attributes->set(ApproovRequestAttributes::FAILURE, $e->context());
            throw $e;
        }
    }

    /**
     * Collects normalized request header values for the headers required by token binding.
     *
     * @param  Request  $request  The request that carries the header values.
     * @param  list<string>  $headers  The normalized header names to read from the request.
     * @return array<string, string|null>
     */
    private function headerValues(Request $request, array $headers): array
    {
        $values = [];
        foreach ($headers as $header) {
            $values[$header] = $this->trimOrNull($request->header($header));
        }

        return $values;
    }

    /**
     * Removes blank and non-string entries from the configured binding header list.
     *
     * @param  array<int, mixed>  $headers  The raw binding header values supplied to the middleware.
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

    /**
     * Resolves the current request identifier from request attributes or the inbound header.
     *
     * @param  Request  $request  The request to inspect for a request identifier.
     * @return string|null
     */
    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get(ApproovRequestAttributes::REQUEST_ID);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->trimOrNull($request->header(ApproovRequestAttributes::REQUEST_ID_HEADER));
    }

    /**
     * Trims a mixed input and returns null when it is not a non-empty string.
     *
     * @param  mixed  $value  The value to normalize.
     * @return string|null
     */
    private function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
