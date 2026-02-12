<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Approov\ApproovAuthException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        ApproovAuthException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->renderable(function (ApproovAuthException $e, Request $request): JsonResponse {
            $status = $e->httpStatus();
            $requestId = $this->requestId($request);

            $payload = [
                'error' => $status >= 500 ? 'internal_error' : 'unauthorized',
                'code' => $e->errorCode()->value,
                'message' => $e->safeMessage(),
            ];

            if ($requestId !== null) {
                $payload['request_id'] = $requestId;
            }

            $response = response()->json($payload, $status);
            if ($requestId !== null) {
                $response->headers->set('X-Request-Id', $requestId);
            }

            return $response;
        });
    }

    private function requestId(Request $request): ?string
    {
        $fromAttributes = $request->attributes->get('request_id');
        if (is_string($fromAttributes) && $fromAttributes !== '') {
            return $fromAttributes;
        }

        $fromHeaders = $request->header('X-Request-Id');
        if (!is_string($fromHeaders)) {
            return null;
        }

        $trimmed = trim($fromHeaders);
        return $trimmed === '' ? null : $trimmed;
    }
}
