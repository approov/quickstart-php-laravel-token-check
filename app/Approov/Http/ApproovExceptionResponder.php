<?php

declare(strict_types=1);

namespace App\Approov\Http;

use App\Approov\Exceptions\ApproovAuthException;
use App\Approov\Support\ApproovRequestAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApproovExceptionResponder
{
    /**
     * Builds the JSON error response for an Approov authentication failure.
     *
     * When a request identifier is available, it is included in both the payload and response headers.
     *
     * @param  ApproovAuthException  $e  The Approov authentication exception to translate into a response.
     * @param  Request  $request  The request associated with the authentication failure.
     * @return JsonResponse
     */
    public function toResponse(ApproovAuthException $e, Request $request): JsonResponse
    {
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
            $response->headers->set(ApproovRequestAttributes::REQUEST_ID_HEADER, $requestId);
        }

        return $response;
    }

    /**
     * Resolves the request identifier from request attributes or the inbound request header.
     *
     * @param  Request  $request  The request to inspect for a request identifier.
     * @return string|null
     */
    private function requestId(Request $request): ?string
    {
        $fromAttributes = $request->attributes->get(ApproovRequestAttributes::REQUEST_ID);
        if (is_string($fromAttributes) && $fromAttributes !== '') {
            return $fromAttributes;
        }

        $fromHeaders = $request->header(ApproovRequestAttributes::REQUEST_ID_HEADER);
        if (!is_string($fromHeaders)) {
            return null;
        }

        $trimmed = trim($fromHeaders);

        return $trimmed === '' ? null : $trimmed;
    }
}
