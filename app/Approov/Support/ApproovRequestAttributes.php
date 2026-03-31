<?php

declare(strict_types=1);

namespace App\Approov\Support;

final class ApproovRequestAttributes
{
    public const REQUEST_ID = 'request_id';
    public const REQUEST_ID_HEADER = 'Request-Id';
    public const AUTH_CONTEXT = 'approov_auth';
    public const REQUIRED_HEADERS = 'approov_required_headers';
    public const FAILURE = 'approov_failure';

    private function __construct()
    {
    }
}
