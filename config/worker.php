<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Queue worker pools
    |--------------------------------------------------------------------------
    |
    | "default" обрабатывает обычные задания (AI-ответы, импорты, уведомления),
    | "bi" — тяжёлую генерацию дашбордов. Разделение не даёт одному долгому
    | дашборду заблокировать ответы Bumi и фоновые операции CRM.
    |
    */
    'pools' => [
        'default' => [
            'queue' => env('QUEUE_DEFAULT_NAME', 'default'),
            'tries' => 3,
            'timeout' => 120,
        ],
        'bi' => [
            'queue' => env('QUEUE_BI_NAME', 'bi'),
            'tries' => 1,
            'timeout' => 600,
        ],
    ],

    /*
     * Насколько свежим считается heartbeat воркера. Canary-задание
     * (worker:selfcheck) запускается каждые 5 минут по расписанию.
     */
    'heartbeat_ttl_minutes' => (int) env('WORKER_HEARTBEAT_TTL_MINUTES', 10),

    /* Задание с reserved_at старше этого срока считается зависшим. */
    'stale_minutes' => (int) env('WORKER_STALE_MINUTES', 15),
];
