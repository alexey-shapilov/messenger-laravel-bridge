<?php

return [
    // You must specify the "default_bus" if you define more than one bus.
    'default_bus' => null,

    // optional
    'buses' => [
        'messenger.bus.default' => [
            // true| false | 'allow_no_handlers'
            'default_middleware' => true,
            'middleware' => [
//                ['id' => '', class => '', 'arguments' => []],
            ],
        ],
    ],

    'transports' => [
        'async' => [
            'dsn' => env('MESSENGER_TRANSPORT_DSN', 'redis://localhost:6379/messages'),
            // Service of a custom serializer to use.
            'serializer' => null,
            'options' => [],
            'retry_strategy' => [
                // Service id to override the retry strategy entirely
                'service' => null,
                'max_retries' => 3,
                // Time in ms to delay (or the initial value when multiplier is used
                'delay' => 1000,
                // If greater than 1, delay will grow exponentially for each retry: this delay = (delay * (multiple ^ retries))
                'multiplier' => 2,
                // Max time in ms that a retry should ever be delayed (0 = infinite)
                'max_delay' => 0
            ]
        ],
        'sync' => [
            'dsn' => 'sync://',
            'serializer' => null,
            'options' => [],
            'retry_strategy' => [
                'service' => null,
                'max_retries' => 3,
                'delay' => 1000,
                'multiplier' => 2,
                'max_delay' => 0
            ]
        ],
//        'failed' => 'doctrine://default?queue_name=failed',
    ],

    // Transport name to send failed messages to (after all retries have failed).
    'failure_transport' => null,

    'serializer' => [
        // Service to use as the default serializer for the transports.
        'default_serializer' => 'messenger.transport.native_php_serializer',
    ],

    'routing' => [
        // requires at least one element
        '*' => [
            'senders' => [],
        ],
    ],
];
