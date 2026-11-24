<?php

declare(strict_types=1);

return [
    /*
     * WebRTC signaling is intentionally stored in the database and polled:
     * no external WebSocket provider is required for an office deployment.
     */
    'poll_ms' => (int) env('LMS_POLL_MS', 1600),

    'ice_servers' => [
        ['urls' => env('LMS_STUN_SERVER', 'stun:stun.l.google.com:19302')],
    ],

    'turn' => [
        'urls' => env('LMS_TURN_URL'),
        'username' => env('LMS_TURN_USERNAME'),
        'credential' => env('LMS_TURN_CREDENTIAL'),
    ],

    // Retention (days). null disables pruning for that data type.
    'retention' => [
        'signals_days' => env('LMS_RETENTION_SIGNALS_DAYS', 1),
        'presence_days' => env('LMS_RETENTION_PRESENCE_DAYS', 1),
        'activities_days' => env('LMS_RETENTION_ACTIVITIES_DAYS', 180),
        'erased_elements_days' => env('LMS_RETENTION_ERASED_ELEMENTS_DAYS', 90),
        'messages_days' => env('LMS_RETENTION_MESSAGES_DAYS', 365),
    ],
];
