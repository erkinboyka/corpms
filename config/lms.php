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
];
