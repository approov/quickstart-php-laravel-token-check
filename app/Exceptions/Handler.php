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

    public function register(): void
    {
        $this->renderable(function (ApproovAuthException $e, Request $request) {
            return app(ApproovExceptionResponder::class)->toResponse($e, $request);
        });
    }
}
