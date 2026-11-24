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
        // SEC-09: срок хранения исходного payload события (дней); журнал событий сохраняется
        'raw_payload_retention_days' => max(1, (int) env('CORPMS_BIO_RAW_TTL_DAYS', 30)),
    ],

    'tasks' => [
        // п.15: предел вложенности подзадач
        'max_subtask_depth' => env('CORPMS_TASKS_MAX_SUBTASK_DEPTH', 3),
    ],

    'onboarding' => [
        // ONB-01: роли, которые выдаёт оркестратор найма; отзываются только они
        'roles' => ['employee'],
        // ONB-01: код категории служебных документов онбординга
        'document_category_code' => env('CORPMS_ONBOARDING_DOC_CATEGORY', 'onboarding'),
        // ONB-01: заголовок служебной задачи адаптации (можно переопределить при запуске)
        'task_title' => 'Онбординг: ознакомление с регламентами',
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
        // секрет проверяется в заголовке X-Telegram-Bot-Api-Secret-Token; в URL не передаётся
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
        // AI-04/SEC-08: явный whitelist хостов провайдера (через запятую).
        // Пусто — допускается только хост из AI_BASE_URL и немаршрутизируемые
        // тестовые TLD; IP-литералы и частные сети запрещены всегда.
        'allowed_hosts' => env('AI_ALLOWED_HOSTS', ''),
        'api_key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'image_model' => env('AI_IMAGE_MODEL', ''),
        'timeout' => env('AI_TIMEOUT', 30),
        // D3: потолок синхронного ожидания LLM, сек — запрос не блокируется
        // дольше этого времени (деградация на локальный ответ + повтор в очереди);
        // 0 — без потолка. В фоновом job потолок не применяется.
        'sync_timeout' => env('AI_SYNC_TIMEOUT', 8),
        // D4 (RAG): модель OpenAI-совместимого /embeddings; пусто — RAG
        // работает в keyword-режиме без векторного поиска
        'embeddings_model' => env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        // RAG-01: извлечение текста вложений. OCR через Tesseract + Poppler:
        // скан-PDF rasterизируется pdftoppm, изображения читаются напрямую.
        // Пустые пути = автопоиск бинарников в PATH и стандартных каталогах.
        'rag' => [
            'max_file_bytes' => max(1, (int) env('AI_RAG_MAX_FILE_BYTES', 20 * 1024 * 1024)),
            'max_extract_chars' => max(1000, (int) env('AI_RAG_MAX_EXTRACT_CHARS', 500000)),
            'tesseract_path' => env('AI_RAG_TESSERACT_PATH', ''),
            'pdftoppm_path' => env('AI_RAG_PDFTOPPM_PATH', ''),
            'ocr_lang' => env('AI_RAG_OCR_LANG', 'eng+rus'),
            'ocr_dpi' => max(72, (int) env('AI_RAG_OCR_DPI', 200)),
            'ocr_psm' => max(3, min(13, (int) env('AI_RAG_OCR_PSM', 6))),
            'ocr_max_pages' => max(1, (int) env('AI_RAG_OCR_MAX_PAGES', 10)),
            'ocr_timeout' => max(5, (int) env('AI_RAG_OCR_TIMEOUT', 120)),
        ],
        // D5 (tool calling): белый список backend-инструментов для LLM;
        // false — инструменты не предлагаются модели и не исполняются
        'tools' => env('AI_TOOLS', true),
    ],
];
