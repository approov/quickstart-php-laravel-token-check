<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    private const REQUEST_ID_HEADER = 'X-Request-Id';
    private const REQUEST_ID_ATTRIBUTE = 'request_id';
    private const APPROOV_REQUIRED_HEADERS_ATTRIBUTE = 'approov_required_headers';
    private const APPROOV_FAILURE_ATTRIBUTE = 'approov_failure';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->ensureRequestId($request);
        $response = $next($request);

        if (!$response->headers->has(self::REQUEST_ID_HEADER)) {
            $response->headers->set(self::REQUEST_ID_HEADER, $requestId);
        }

        $status = $response->getStatusCode();
        $context = $this->buildContext($request, $status);

        if ($status >= 500) {
            Log::error('http.request.completed', $context);
        } elseif ($status >= 400) {
            Log::warning('http.request.completed', $context);
        } else {
            Log::info('http.request.completed', $context);
        }

        return $response;
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
        $value = $request->attributes->get(self::APPROOV_REQUIRED_HEADERS_ATTRIBUTE);
        return is_array($value) ? $value : [];
    }

    private function summary(Request $request, int $status): string
    {
        $failure = $request->attributes->get(self::APPROOV_FAILURE_ATTRIBUTE);
        if (is_array($failure) && isset($failure['reason'])) {
            return 'approov_failed:' . $failure['reason'];
        }

        $approovAuth = $request->attributes->get('approov_auth');
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

        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->trimOrNull($request->header(self::REQUEST_ID_HEADER));
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
