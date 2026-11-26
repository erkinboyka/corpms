<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | CorpMS — доменные настройки (п.70 ТЗ: настройки системы)
    |--------------------------------------------------------------------------
    */

    'service_worker' => [
        // Консервативный SW: только versioned/static assets публичной витрины.
        // По умолчанию выключен; включение — CORPMS_SERVICE_WORKER_ENABLED=true.
        'enabled' => env('CORPMS_SERVICE_WORKER_ENABLED', false),
    ],

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
        // SEC-03: отдельный секрет подписи; не использовать app.key напрямую
        'signing_secret' => env('CORPMS_SIGNATURE_SECRET', ''),
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
        // Список моделей, которые разрешено выбирать в настройках Bumi.
        // Пусто — доступна только модель corpms.ai.model (без свободного ввода).
        'available_models' => array_values(array_unique(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            preg_split('/[\s,;]+/', (string) env('AI_AVAILABLE_MODELS', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )))),
        'image_model' => env('AI_IMAGE_MODEL', ''),
        'timeout' => env('AI_TIMEOUT', 30),
        // D3: потолок синхронного ожидания LLM, сек — запрос не блокируется
        // дольше этого времени (деградация на локальный ответ + повтор в очереди);
        // 0 — без потолка. В фоновом job потолок не применяется.
        'sync_timeout' => env('AI_SYNC_TIMEOUT', 8),
        // D4 (RAG): модель OpenAI-совместимого /embeddings; пусто — RAG
        // работает в keyword-режиме без векторного поиска
        'embeddings_model' => env('AI_EMBEDDING_MODEL') ?: env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        // Псевдоним для новых конфигураций; пусто — используется канонический
        // corpms.ai.embeddings_model (в т.ч. сохранённый в БД компаний).
        'embedding_model' => env('AI_EMBEDDING_MODEL', ''),
        // Опционально: если провайдер поддерживает параметр dimensions и
        // возвращает вектора строго этой длины. Пусто — длина берётся из ответа.
        'embedding_dimensions' => env('AI_EMBEDDING_DIMENSIONS') ?: null,
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

    /*
    |--------------------------------------------------------------------------
    | Bumi: репетитор, коуч и разговорный режим
    |--------------------------------------------------------------------------
    |
    | Разговорный режим — мастер-выключатель. Пока он выключен, Bumi молчит
    | и продолжает показывать реплики текстом, как и раньше. Включённый режим
    | не отменяет текст: лицо двигает ртом, реплика остаётся на экране, а звук
    | идёт через браузер или через настроенного AI-провайдера.
    |
    | STT по умолчанию серверный: браузерное распознавание запрещено на
    | http://corpms.local (не доверенный origin), поэтому аудио уходит на
    | /bumi/voice/transcribe. Сам микрофон требует HTTPS/localhost или relay
    | на доверенном localhost-origin того же сайта.
    */
    'bumi' => [
        'conversation' => [
            'enabled' => (bool) env('BUMI_CONVERSATION_MODE', false),
            'auto_speak' => (bool) env('BUMI_AUTO_SPEAK', true),
            'listen_mode' => env('BUMI_LISTEN_MODE', 'push_to_talk'), // push_to_talk|hands_free
            'lang' => env('BUMI_VOICE_LANG', 'ru-RU'),
            'tts' => env('BUMI_VOICE_TTS', 'browser'), // browser|server|off
            'stt' => env('BUMI_VOICE_STT', 'server'), // server|browser|off
            'tts_model' => env('BUMI_VOICE_TTS_MODEL', 'gpt-4o-mini-tts'),
            'stt_model' => env('BUMI_VOICE_STT_MODEL', 'whisper-1'),
            'voice_name' => env('BUMI_VOICE_NAME', ''),
            'rate' => (float) env('BUMI_VOICE_RATE', 1.0),
            'pitch' => (float) env('BUMI_VOICE_PITCH', 1.0),
            'max_audio_bytes' => max(1, (int) env('BUMI_VOICE_MAX_BYTES', 10 * 1024 * 1024)),
            // Небезопасный origin (http://corpms.local) не имеет доступа к
            // getUserMedia. Если тот же сайт поднят на http://localhost, тихий
            // iframe на доверенном origin ловит микрофон и отдаёт аудио родителю.
            'relay_url' => rtrim((string) env('BUMI_VOICE_RELAY_URL', ''), '/'),
            'parent_origins' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $origin): string => rtrim(trim((string) $origin), '/'),
                preg_split('/[\s,;]+/', (string) env('BUMI_VOICE_PARENT_ORIGINS', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            )))),
        ],
        'tutor' => [
            'enabled' => (bool) env('BUMI_TUTOR_ENABLED', true),
            'mode' => env('BUMI_TUTOR_MODE', 'coach'), // coach|tutor
            'hint_limit' => max(1, (int) env('BUMI_TUTOR_HINT_LIMIT', 3)),
            'reveal_answer' => (bool) env('BUMI_TUTOR_REVEAL_ANSWER', false),
            'context_chars' => max(500, (int) env('BUMI_TUTOR_CONTEXT_CHARS', 6000)),
        ],
    ],
];
