<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | CorpMS — доменные настройки (п.70 ТЗ: настройки системы)
    |--------------------------------------------------------------------------
    */

    'biometric' => [
        // п.73: liveness-проверка обязательна для приёма событий
        'require_liveness' => env('CORPMS_BIO_REQUIRE_LIVENESS', true),
        // минуты offline до пометки устройства offline
        'offline_after_minutes' => env('CORPMS_BIO_OFFLINE_MINUTES', 15),
    ],

    'tasks' => [
        // п.15: предел вложенности подзадач
        'max_subtask_depth' => env('CORPMS_TASKS_MAX_SUBTASK_DEPTH', 3),
    ],

    'documents' => [
        // лимиты
        'max_file_size_mb' => env('CORPMS_DOC_MAX_FILE_MB', 25),
    ],

    'signature' => [
        // TTL OTP-кода в минутах
        'otp_ttl' => env('CORPMS_SIG_OTP_TTL', 5),
        // TTL QR-токена подписи в часах
        'qr_token_ttl_hours' => env('CORPMS_SIG_QR_TTL', 24),
    ],

    'telegram' => [
        // Bot API токен (п.66 ТЗ)
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        // секрет для маршрута /api/telegram/webhook
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
        // username бота для deep-link
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'CorpMS_bot'),
    ],

    'notifications' => [
        // сводка на утро: каналы сотрудниками настраиваются отдельно (п.13)
        'daily_summary_hour' => env('CORPMS_NOTIFY_SUMMARY_HOUR', 8),
    ],

    'ai' => [
        // AI-модуль (п.68 ТЗ): базовый URL OpenAI-совместимого API
        'base_url' => env('AI_BASE_URL', ''),
        'api_key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('AI_TIMEOUT', 30),
        // D3: потолок синхронного ожидания LLM, сек — запрос не блокируется
        // дольше этого времени (деградация на локальный ответ + повтор в очереди);
        // 0 — без потолка. В фоновом job потолок не применяется.
        'sync_timeout' => env('AI_SYNC_TIMEOUT', 8),
        // D4 (RAG): модель OpenAI-совместимого /embeddings; пусто — RAG
        // работает в keyword-режиме без векторного поиска
        'embeddings_model' => env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        // D5 (tool calling): белый список backend-инструментов для LLM;
        // false — инструменты не предлагаются модели и не исполняются
        'tools' => env('AI_TOOLS', true),
    ],
];
