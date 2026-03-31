<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Approov\Exceptions\ApproovAuthException;
use App\Approov\Support\ApproovRequestAttributes;
use App\Approov\Verification\AuthContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->ensureRequestId($request);

        try {
            $response = $next($request);
        } catch (ApproovAuthException $e) {
            $this->logRequest($request, $e->httpStatus());
            throw $e;
        } catch (\Throwable $e) {
            $this->logRequest($request, 500);
            throw $e;
        }

        if (!$response->headers->has(ApproovRequestAttributes::REQUEST_ID_HEADER)) {
            $response->headers->set(ApproovRequestAttributes::REQUEST_ID_HEADER, $requestId);
        }

        $this->logRequest($request, $response->getStatusCode());

        return $response;
    }

    private function logRequest(Request $request, int $status): void
    {
        $context = $this->buildContext($request, $status);

        if ($status >= 500) {
            Log::error('http.request.completed', $context);
            return;
        }

        if ($status >= 400) {
            Log::warning('http.request.completed', $context);
            return;
        }

        Log::info('http.request.completed', $context);
    }

    private function buildContext(Request $request, int $status): array
    {
        $context = [
            'summary' => $this->summary($request, $status),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $status,
            'ip' => $request->ip(),
            'port' => $request->getPort(),
        ];

        $requiredHeaders = $this->approovRequiredHeaders($request);
        if ($requiredHeaders !== []) {
            $context['required_headers'] = $requiredHeaders;
        }

        return $context;
    }

    private function approovRequiredHeaders(Request $request): array
    {
        $value = $request->attributes->get(ApproovRequestAttributes::REQUIRED_HEADERS);
        return is_array($value) ? $value : [];
    }

    private function summary(Request $request, int $status): string
    {
        $failure = $request->attributes->get(ApproovRequestAttributes::FAILURE);
        if (is_array($failure) && isset($failure['reason'])) {
            return 'approov_failed:' . $failure['reason'];
        }

        $approovAuth = $request->attributes->get(ApproovRequestAttributes::AUTH_CONTEXT);
        if ($approovAuth instanceof AuthContext) {
            $principal = $approovAuth->principal();
            if ($principal === 'approov-disabled') {
                return 'approov_disabled';
            }
            if ($principal !== '') {
                return 'approov_ok';
            }
        }

        if (is_array($approovAuth)) {
            $principal = $approovAuth['principal'] ?? null;
            if ($principal === 'approov-disabled') {
                return 'approov_disabled';
            }
            if ($principal !== null) {
                return 'approov_ok';
            }
        }

        if ($status >= 400) {
            return 'http_error';
        }

        return 'ok';
    }

    private function ensureRequestId(Request $request): string
    {
        $requestId = $this->requestId($request);
        if ($requestId === null) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set(ApproovRequestAttributes::REQUEST_ID, $requestId);

        return $requestId;
    }

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get(ApproovRequestAttributes::REQUEST_ID);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->trimOrNull($request->header(ApproovRequestAttributes::REQUEST_ID_HEADER));
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
