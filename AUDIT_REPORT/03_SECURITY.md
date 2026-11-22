# 03 — Безопасность (OWASP Top 10 / API Security)

Статус: **завершено (статический аудит + проверка маршрутов)**. Динамическая эксплуатация не выполнялась. Реальные значения секретов не читались и не выводились.

## 1. Общая картина

- Маршрутов: **314**; без middleware `auth` — **30**, без `auth|permission|device.token` — **26**.
- Публичные роуты в основном ожидаемы: логин/2FA/reset, OAuth, QR `/sign/{token}`, `broadcasting/auth`, sitemap/legal/landing/up, device webhook, встроенные Laravel `storage/{path}`.
- Глобально включены `SecurityHeaders` (`bootstrap/app.php:29`), web-группа получает `EnsureActiveEmployee`, `SetLocale`, `EnsureOnboarded`, `EnsureTenantIsolation` (`bootstrap/app.php:31-38`).
- `EnsureTenantIsolation` проверяет route-bound Eloquent-модели с `company_id` и known relation chain; неизвестная модель без `company_id` отклоняется (`app/Http/Middleware/EnsureTenantIsolation.php:85-90,130-163`).
- Шифрование настроек и маскировка секретов в аудит-журнале присутствуют (`tests/Feature/AuditSecretMaskingTest.php`).
- XSS-санитайзер `HtmlSanitizer` использует whitelist и безопасные href (`app/Support/HtmlSanitizer.php:23-42,112-135,160-181`).
- SQL `DB::raw` найден, но в проверенных местах — константы/агрегаты, пользовательский ввод не интерполируется (`InventoryService.php:194`, `FinanceService.php:250`, `User.php:31`).

**Вывод:** системных RCE/SQLi/неподписанной загрузки файлов статически не подтверждено. Главная подтверждённая проблема — нарушение tenant isolation на бэкапах.

## 2. Подтверждённые находки

### SEC-01 — Critical — Кросс-tenant доступ к полным бэкапам БД
- **ID:** SEC-01 (источник: TEN-01)
- **OWASP:** A01 Broken Access Control / API1 BOLA; CWE-639.
- **Evidence:**
  - `routes/web.php:361` — `GET /backups` под `permission:settings.view@company`; `routes/web.php:373` — `GET /backups/{file}` под тем же правом.
  - `app/Http/Controllers/Web/BackupController.php:16-35` — `scandir(config('backup.path'))` и передача **всех** файлов в view без фильтра по компании.
  - `app/Http/Controllers/Web/BackupController.php:50-57` — `basename($file)` защищает от traversal, но не проверяет принадлежность бэкапа тенанту; `response()->download($path)`.
  - `config/backup.php:15` — общий каталог `storage/app/backups`.
  - `app/Console/Commands/BackupDatabase.php:17-23,62-68` — бэкап содержит всю БД; файл создаётся с `chmod 0600`.
  - `database/seeders/RbacSeeder.php:69,104` — `company_director` имеет `settings.view` и `settings.manage` в company-scope.
- **Impact:** пользователь с company-scoped правом `settings.view` может перечислить и скачать дамп **всей БД**, включая данные других компаний, ПДн, хэши паролей, токены и зашифрованные настройки. Это нарушает основную гарантию мультиарендности.
- **Exploitation:** директор компании A открывает `/backups`, видит имена всех бэкапов, запрашивает `/backups/<имя>` и получает полный дамп. Повторные `POST /backups/db` (`routes/web.php:371`) без throttle могут исчерпать диск.
- **Fix:**
  1. Вынести бэкапы из company-scope прав: отдельное installation-level право, доступное только платформенной роли.
  2. Либо хранить бэкапы раздельно по `company_id` и фильтровать список/скачивание через `TenantGuard`.
  3. Добавить throttle на `runDb/runFiles` и аудит `backup.created/downloaded`.
- **Verification:** тест с двумя компаниями и маркерами: `/backups` пользователя A не содержит файлы B; прямой `GET /backups/<backupB>` → 403/404; `POST /backups/db` имеет throttle.
- **Status:** Confirmed.

### SEC-02 — High — Одноразовость Google OAuth `state` не обеспечивается
- **ID:** SEC-02 (AUTH-01)
- **OWASP:** A01/A05; login CSRF / replay.
- **Evidence:**
  - `app/Http/Controllers/Auth/GoogleAuthController.php:21-22` — `state` генерируется и пишется в сессию.
  - `app/Http/Controllers/Auth/GoogleAuthController.php:37-39` — только сравнение со значением из сессии.
  - `app/Http/Controllers/Auth/GoogleAuthController.php:90-91` — после входа `session()->regenerate()`, но `forget('google_oauth_state')` отсутствует.
  - `routes/web.php:74-75` — оба OAuth-роута в `guest`.
- **Impact:** state остаётся действительным после первого обращения. Злоудей может направить жертву (у которой открыт `/auth/google`) на callback со своим `code` и state жертвы; в результате жертва будет залогинена в учётку атакующего. Возможен replay callback.
- **Fix:** удалять `state` сразу после проверки, до обмена кода; добавить PKCE; чистить transient-ключи после входа/ошибки.
- **Verification:** тест: `/auth/google` → callback → повтор того же callback должен быть отклонён; `session('google_oauth_state') === null` после первого callback.
- **Status:** Confirmed.

### SEC-03 — Medium — Утечка метаданных import-заданий всех тенантов
- **ID:** SEC-03 (TEN-02)
- **OWASP:** A01 / API1.
- **Evidence:**
  - `routes/web.php:367` — `GET /imports` под `permission:employees.manage@company`.
  - `app/Http/Controllers/Web/ImportController.php:20` — `ImportJob::orderByDesc('id')->limit(50)->get();` без фильтра по компании.
  - `database/migrations/2026_11_21_000003_create_import_jobs_table.php:13` — у `import_jobs` есть `company_id`.
  - `resources/views/imports/index.blade.php:32-41` — выводятся ID, тип, статус, счётчики строк.
  - `EnsureTenantIsolation.php:85-90,141-153` — route-bound `ImportJob` на `show/run` проверяется, но список — нет.
- **Impact:** компания A видит последние 50 задач импорта других компаний: ID, типы, статусы, объёмы, активность.
- **Fix:** `ImportJob::query()->where('company_id', $me->company_id)->orderByDesc('id')->limit(50)->get();` либо global scope.
- **Verification:** two-tenant test: список содержит только задачи текущей компании.
- **Status:** Confirmed.

### SEC-04 — Medium — Неавторизованная запись браузерных логов через dev-роут
- **ID:** SEC-04 (LOG-01)
- **OWASP:** A05 Security Misconfiguration.
- **Evidence:** `composer.json:16` — `laravel/boost` в `require-dev`; `route:list`: `POST /_boost/browser-logs` без middleware; `vendor/laravel/boost/src/BoostServiceProvider.php:107-147` пишет `logs` в `Log::channel('browser')` и явно `withoutMiddleware(VerifyCsrfToken::class)`; канал определён в `:178-184`.
- **Impact:** любой анонимный клиент может подделывать записи в `storage/logs/browser.log` и заполнять диск. Если логи потребляются Boost/AI, возможна инъекция ложных «ошибок».
- **Fix:** не ставить dev-зависимости в prod (`composer install --no-dev`); закрыть роут `auth`/allowlist + throttle.
- **Verification:** в prod `POST /_boost/browser-logs` → 404/403; в dev есть throttle.
- **Status:** Confirmed в чекауте; production-impact зависит от installation.

### SEC-05 — Low — Нет per-account блокировки TOTP
- **ID:** SEC-05 (AUTH-02)
- **OWASP:** A07 Identification and Authentication Failures.
- **Evidence:** `routes/web.php:72` — `throttle:10,1` только по IP; `app/Http/Controllers/Web/TwoFactorController.php:26-40` — без счётчика попыток; `app/Support/TwoFactorService.php:30-43` — проверка 6 цифр и окна ±1 без журнала неудач.
- **Impact:** распределённый перебор TOTP не останавливается блокировкой аккаунта; IP-throttle снижает скорость, но не привязан к пользователю.
- **Fix:** `RateLimiter` по `2fa:<user_id>`, аудит неудач, временная блокировка и уведомление.
- **Verification:** 20 неверных кодов для одного пользователя с разных IP → 429/блокировка после порога.
- **Status:** Confirmed.

### SEC-06 — Low — Секрет Telegram webhook в URL и не constant-time сравнение
- **ID:** SEC-06 (WEB-01)
- **OWASP:** A02 Cryptographic Failures / A09 Logging.
- **Evidence:** `routes/device.php:24-25` — `POST /api/telegram/webhook/{secret}`; `app/Http/Controllers/Device/TelegramWebhookController.php:20-24` — `$secret !== config(...)`; комментарий `:14` упоминает header, но код его не читает.
- **Impact:** секрет попадает в access-логи прокси/веб-сервера и Referer; сравнение `!==` не constant-time.
- **Fix:** использовать `X-Telegram-Bot-Api-Secret-Token`, убрать сегмент из маршрута, `hash_equals()`, ротировать секрет.
- **Verification:** запрос с правильным URL-секретом, но без header должен перестать проходить; секрет отсутствует в access-логах.
- **Status:** Confirmed.

## 3. Гипотезы / условные риски

### SEC-07 — Suspected — Google OAuth не требует `email_verified`
- **Evidence:** `app/Http/Controllers/Auth/GoogleAuthController.php:60-67` — читаются `sub`, `email`, затем поиск/создание пользователя; `email_verified` не проверяется.
- **Условие:** стандартный Google OpenID Connect обычно возвращает подтверждённый email, поэтому не подтверждено как эксплуатационная уязвимость.
- **Fix:** требовать `email_verified === true`, при корпоративном использовании проверять `hd`.
- **Status:** Suspected.

### SEC-08 — Suspected — SSRF через tenant-настройку `corpms.ai.base_url`
- **Evidence:** `app/Support/SettingsService.php:83-87` — настройка доступна; `app/Http/Controllers/Web/SettingsController.php:83-85` — валидация только `url:http,https`; `AiAssistantService.php:301`, `DocumentRagService.php:299`, `BumiFiles.php:35` — POST на произвольный base URL; `SettingsService.php:172-180` защищает только от утечки глобального API-ключа при смене URL.
- **Условие:** требуется `settings.manage@company` (привилегированная роль). Риск trusted-admin/egress, а не анонимный.
- **Fix:** whitelist хостов, запрет private/link-local/loopback после DNS, отключение redirects, egress firewall.
- **Status:** Suspected.

### SEC-09 — Low/Suspected — Хранение полного `raw_payload` биометрии
- **Evidence:** `app/Http/Controllers/Device/BiometricEventController.php:71,89` — `'raw_payload' => $request->all()`; миграция `2026_10_31_000005_create_biometric_tables.php:59` — JSON. Вывод в UI через `{{ }}` (`resources/views/biometrics/rfid.blade.php:104`) XSS не Confirm.
- **Риск:** избыточное хранение ПДн без политики удержания/маскирования.
- **Status:** Suspected (privacy).

### SEC-10 — Low/Suspected — `/broadcasting/auth`: null dereference при непрефиксированном канале
- **Evidence:** `routes.json`: `broadcasting/auth` → framework `BroadcastController@authenticate`, middleware только `web`; `routes/channels.php:8,17,42-43` разыменовывают `$user` без проверки на null; `Broadcaster.php:120,331,338` передаёт `$request->user()` в колбэк. `PusherBroadcaster.php:90-93` отклоняет гостя только для `private-`/`presence-` каналов (`UsePusherChannelConventions.php:15-34`), а `config/broadcasting.php:18` задаёт `null` по умолчанию.
- **Условие:** активный драйвер `reverb`/`pusher` + `channel_name` без префикса (например, `chat.conversation.1`, как в `tests/Feature/Chat/ChatHttpTest.php:563`). При корректном `private-` имени гость получает отказ от фреймворка; при драйвере `null` колбэки не вызываются.
- **Риск:** HTTP 500 вместо 403 и stack trace в ответе/логах. Повышения прав не подтверждено.
- **Fix:** `if ($user === null) return false;` в каждом канальном колбэке либо `auth` на маршруте broadcast; guest-тест для трёх шаблонов каналов.
- **Status:** Suspected (conditional code path; HTTP-запросы не выполнялись). См. BE-02 в `05_BACKEND_API.md`.

## 4. Проверено, подтверждённых проблем не найдено

| Область | Результат | Доказательство |
|---|---|---|
| `storage/{path}` GET/PUT | Встроенные роуты Laravel 13, приватный диск требует подписи; PUT дополнительно `upload=true` | `vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111,119`; `ServeFile.php:55-61`; `ReceiveFile.php:45-48`; `config/filesystems.php:33-39` |
| QR-подпись | Код обязателен, 5 попыток, `hash_equals`, одноразовый токен | `app/Domains/Documents/Services/SignatureService.php:224-226,322,402-438`; `routes/web.php:101-105` |
| Broadcast/WebSocket | Для каналов с префиксом `private-`/`presence-` гости отклоняются провайдером; колбэки проверяют компанию и членство. Оговорка для непрефиксированных имён — SEC-10 | `vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/PusherBroadcaster.php:90-93`; `UsePusherChannelConventions.php:15-34`; `routes/channels.php:16-35,42-44` |
| XSS/rich text | Whitelist санитайзера, безопасные ссылки, Markdown strip | `app/Support/HtmlSanitizer.php:23-42,112-135,160-181`; `app/Support/AssistantMarkdown.php:13-21` |
| SQL/command injection | `DB::raw` только константы; Artisan фиксированные имена команд | `InventoryService.php:194`, `FinanceService.php:250`, `User.php:31`, backup commands |
| CSRF web-форм | Laravel web group `VerifyCsrfToken`; исключение — SEC-04 | `bootstrap/app.php:14-20` |

## 5. Не проверено

1. Реальные prod-значения `APP_DEBUG`, `SESSION_SECURE_COOKIE`, `APP_KEY`, `BACKUP_PATH` — по условию не читались.
2. Динамическая эксплуатация через HTTP/браузер — не выполнялась.
3. Полностью не прочитаны внутренности `Crm*`, `Fin*`, `Inventory*`, `Bi*`, `Lms*`, `AnalyticsController`, queue workers.
4. Сетевая конфигурация Reverb и инфраструктура (firewall, egress, TLS termination) не аудировались.
5. Политики хранения/ротации ПДн, биометрии и логов на целевой инфраструктуре не проверены.

## 6. Рекомендованный порядок закрытия

1. **P0:** SEC-01 (бэкапы) — немедленно ограничить доступ и проверить, не были ли дампы доступны шире.
2. **P1:** SEC-02 (OAuth state), SEC-03 (import list), SEC-04 (dev-роут в prod).
3. **P2:** SEC-05, SEC-06, SEC-08, SEC-09; SEC-10 после проверки активного broadcast-драйвера.
4. **P3:** SEC-07 после перехода на corporate IdP/PKI.
