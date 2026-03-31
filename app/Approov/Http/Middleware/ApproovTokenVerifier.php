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
    public function __construct(
        private readonly ApproovService $approovService
    ) {
    }

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

    private function headerValues(Request $request, array $headers): array
    {
        $values = [];
        foreach ($headers as $header) {
            $values[$header] = $this->trimOrNull($request->header($header));
        }

        return $values;
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

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get(ApproovRequestAttributes::REQUEST_ID);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->trimOrNull($request->header(ApproovRequestAttributes::REQUEST_ID_HEADER));
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
