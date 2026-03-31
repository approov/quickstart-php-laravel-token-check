<?php

return [
    'token_header' => env('APPROOV_TOKEN_HEADER', 'Approov-Token'),
    'base64url_secret' => env('APPROOV_BASE64URL_SECRET'),
    'cache_keys' => [
        'approov_enabled' => 'approov_enabled',
        'token_binding_enabled' => 'approov_token_binding_enabled',
    ],
];
