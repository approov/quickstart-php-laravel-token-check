<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Approov\Exceptions\ApproovAuthException;
use App\Approov\Http\ApproovExceptionResponder;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
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

    /**
     * Registers exception rendering callbacks for the application.
     *
     * @return void
     */
    public function register(): void
    {
        $this->renderable(
            /**
             * Converts Approov authentication failures into JSON API responses.
             *
             * @param  ApproovAuthException  $e  The Approov exception raised during request verification.
             * @param  Request  $request  The request that triggered the authentication failure.
             * @return \Illuminate\Http\JsonResponse
             */
            function (ApproovAuthException $e, Request $request) {
                return app(ApproovExceptionResponder::class)->toResponse($e, $request);
            }
        );
    }
}
