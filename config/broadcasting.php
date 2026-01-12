<?php

return [
    'default' => env('BROADCAST_DRIVER', 'log'),
    'connections' => [
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
