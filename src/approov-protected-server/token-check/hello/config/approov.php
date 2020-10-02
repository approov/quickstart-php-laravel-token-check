<?php

return [
    'secret' => base64_decode(env('APPROOV_BASE64_SECRET'), true),
];
