# 05 — Backend / API

Статус: **завершено (статический аудит маршрутов, middleware и контроллеров)**. HTTP-запросы, реальные статус-коды и ответы не проверялись; где поведение зависит от заголовков/данных — это указано в статусе. Секреты не читались и не выводились.

## 1. Факты по маршрутам

Источник: `C:\Users\123\AppData\Local\Temp\opencode\routes.json` (`php artisan route:list --json`, сгенерирован в ходе аудита) + сверка с `routes/*.php`.

| Метрика | Значение |
|---|---|
| Всего маршрутов | **314** |
| POST | **146**; из них с `throttle:` **30**, без **116** |
| GET\|HEAD | 126; DELETE 18; PATCH 12; PUT 9; catch-all 2; GET\|POST\|HEAD 1 |
| С `permission:` | **272** |
| С `permission:…@company` | **81** |
| С любым `throttle:` | **58** |
| С `device.token` | 4 |
| В web-группе | 305; вне группы: `_boost/browser-logs`, `storage/{path}` GET/PUT, `/up` |

Из 116 POST без throttle 115 имеют `auth` или `device.token`; единственное исключение — vendor-роут `POST /_boost/browser-logs` (см. SEC-04). Публичные POST (`login`, `forgot-password`, `reset-password`, `login/2fa`, `sign/{token}`) имеют собственный throttle — `routes/web.php:63,67,69,72,104-105`.

## 2. Находки (6 Confirmed + BE-02 Suspected)

### BE-01 — Medium — Список import-задач без фильтра по тенанту (дублирует SEC-03)
- **Evidence:** `app/Http/Controllers/Web/ImportController.php:20` — `ImportJob::orderByDesc('id')->limit(50)->get();`; `routes/web.php:367` — middleware `permission:employees.manage@company`; `app/Models/ImportJob.php:11` — поле `company_id` существует; `EnsureTenantIsolation.php:85-90` проверяет только route-bound модели, а `index()` параметров не имеет.
- **Impact:** пользователь компании A видит 50 последних задач всех компаний: ID, `company_id`, `user_id`, путь файла, статус и массивы ошибок.
- **Fix:** `where('company_id', $me?->company_id)` + fail-closed при NULL; проверить `show/run` через global scope.
- **Verification:** two-tenant feature-тест: список A не содержит ID/путей B.
- **Status:** Confirmed (уже учтено как SEC-03).

### BE-02 — Medium — `/broadcasting/auth` может вызвать null dereference вместо 403
- **Evidence:** `routes.json`: `broadcasting/auth` → framework `BroadcastController@authenticate`, middleware только `web`; `routes/channels.php:8` — `$user->id`; `:17` — `$user->employee`; `:42-43` — `$user->employee !== null`; `vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/Broadcaster.php:120,331,338` — колбэк получает `$request->user()` (null для гостя). Оговорка: `PusherBroadcaster.php:90-93` отклоняет гостя для каналов с префиксом `private-`/`presence-` (`UsePusherChannelConventions.php:15-34`), а `config/broadcasting.php:18` задаёт драйвер `null` по умолчанию (в `null`-драйвере `auth()` пустой — `NullBroadcaster.php:10-13`).
- **Impact:** при активном драйвере `reverb`/`pusher` и **непрефиксированном** `channel_name` (такой формат используют собственные тесты — `tests/Feature/Chat/ChatHttpTest.php:563,587`) гостевой POST вызывает `Error` и HTTP 500 вместо 403. Для корректных `private-...` каналов гость отклоняется фреймворком; HTTP-статусы не замерялись.
- **Fix:** `Broadcast::routes(['middleware' => ['web','auth']])` и/или `if ($user === null) return false;` в каждом колбэке; добавить guest-тест.
- **Verification:** guest POST `/broadcasting/auth` с драйвером `reverb` и `channel_name=chat.conversation.1` → 403 без stack trace; повторить для трёх шаблонов каналов и для `private-` префикса.
- **Status:** Suspected (conditional code path; зависит от broadcaster и формата имени канала; HTTP не выполнялся). См. SEC-10 в `03_SECURITY.md`.

### BE-03 — Medium — JSON-эндпоинты возвращают HTML-redirect при ошибке валидации без `Accept: application/json`
- **Evidence:** `bootstrap/app.php:41-43` — `shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`; `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:925-929` — callback заменяет решение по `Content-Type`. JSON-контроллеры с `validate()`: `AiController.php:67,72`; `LmsController.php:275,277`; `ChatController.php:117,119`. Контраст — двойные ответы в `DocumentController.php:371-379`, `TaskController.php:399-450`, `LmsController.php:415-424`.
- **Impact:** клиент, который шлёт `Content-Type: application/json`, но `Accept: */*`, при успехе получает JSON, а при 422 — 302 HTML. Нарушается контракт API.
- **Fix:** расширить предикат на `$request->isJson()`/список JSON-роутов либо требовать `Accept: application/json` во всех клиентах.
- **Verification:** `POST /ai/message` с JSON и `Accept: */*`, `question: "x"` → ожидается 422 JSON (сейчас 302 HTML).
- **Status:** Confirmed (зависит от заголовков запроса).

### BE-04 — Low/Medium — Telegram webhook: секрет в URL, нет проверки header, не constant-time, нет валидации payload (дублирует SEC-06)
- **Evidence:** `routes/device.php:23-25` — `POST /api/telegram/webhook/{secret}` + только `throttle:60,1`; `TelegramWebhookController.php:22` — `$secret !== config(...)`; `:27-28` — `$request->json()->all()` сразу в `handleUpdate()`; `config/corpms.php:40` — источник секрета.
- **Impact:** секрет попадает в access-логи и Referer; заголовок `X-Telegram-Bot-Api-Secret-Token` не проверяется; произвольная структура JSON уходит в сервис.
- **Fix:** убрать `{secret}` из пути, проверять header через `hash_equals()`, валидировать форму update.
- **Verification:** (a) правильный header при неверном пути отклоняется; (b) `[]`/`null` → 422; (c) секрет отсутствует в логах.
- **Status:** Confirmed (уже учтено как SEC-06).

### BE-05 — Low — GET-запрос создаёт LMS-встречу (не идемпотентен)
- **Evidence:** `routes/web.php:433` — `GET /whiteboards/{whiteboard}`; `:428` — группа `lms.view` + `throttle:180,1`; `LmsController.php:131-136` — если active meeting не найден, вызывается `$this->lms->createMeeting(...)`.
- **Impact:** prefetch, crawler или повторный GET создают строки `lms_meetings`/`lms_meeting_participants` и аудит (`LmsService.php:390-409,416`). Explicit POST для создания уже есть: `routes/web.php:458`, `LmsController.php:323-334`.
- **Fix:** убрать создание из read-контроллера; требовать `POST /lms/meetings`.
- **Verification:** повторные GET доски без встреч не увеличивают число строк.
- **Status:** Confirmed.

### BE-06 — Low — Долгие обслуживающие операции без throttle и очереди
- **Evidence:** `routes/web.php:263` (`/accounting/sync`), `:291` (`/bi/rebuild`), `:371` (`/backups/db`), `:372` (`/backups/files`) — только `permission:`, без `throttle:`; `AccountingEntryController.php:187` — `syncFinance()`; `BiDashboardController.php:203` — `rebuild()`; `BackupController.php:40,46` — `Artisan::call('db:backup')` / `Artisan::call('files:backup')`. Контраст — AI/chat/search/LMS имеют throttle (`web.php:375-380,384-406,428-466`).
- **Impact:** параллельные запросы вызывают timeout’ы, блокировки БД и дублирование дорогих операций.
- **Fix:** перевести в queue jobs с single-flight lock; добавить `throttle:1,1` или мягче.
- **Verification:** 5 параллельных запросов к `/bi/rebuild` и `/backups/db` → только один выполняется/остальные 429.
- **Status:** Confirmed.

### BE-07 — Low — Несогласованная валидация поискового `q`
- **Evidence:** `SearchController.php:34,97` — `trim((string) request('q', ''))` без `validate()`, используется в LIKE на `:58,128`; `DocumentController.php:79-85` — также raw input + LIKE; контраст `ChatController.php:317` — `'q' => ['nullable','string','max:120']`.
- **Impact:** неограниченная длина и wildcard `%`/`_` попадают в SQL LIKE; throttle снижает, но не устраняет риск медленных full scan.
- **Fix:** единая валидация `max:120` и экранирование wildcard (`addcslashes($q, '%_\\')`).
- **Verification:** `q` из 10 000 `%` на `/search/hint` и `/search` → 422.
- **Status:** Confirmed.

## 3. Suspected

| ID | Severity | Область | Evidence | Почему не Confirmed |
|---|---|---|---|---|
| BE-S1 | Low | Глобальный `event_uuid` биометрии | `BiometricEventController.php:49,51`; `RfidService.php:73`; уникальный индекс без `company_id` в `2026_10_31_000005_create_biometric_tables.php:50` | Требуется угадывание UUID другого устройства; слой затронут только для device token |
| BE-S2 | Low | OAuth `state` принимает `null === null` | `GoogleAuthController.php:37` | Пересекается с SEC-02; динамическая эксплуатация не проводилась |
| BE-S3 | Low | Vendor `POST /_boost/browser-logs` без middleware | `vendor/laravel/boost/src/BoostServiceProvider.php:107-125`; routes.json `middleware: []` | Код vendor, production-impact зависит от `composer install --no-dev`; см. SEC-04 |
| BE-02 | Medium | `/broadcasting/auth` null dereference (детали в §2) | `routes/channels.php:8,17,42-43`; `Broadcaster.php:120,331,338`; `PusherBroadcaster.php:90-93` | Зависит от активного broadcaster и непрефиксированного имени канала; HTTP не выполнялся; см. SEC-10 |

## 4. Не проверено

1. HTTP-статусы, заголовки и тела ответов — запросы не отправлялись.
2. Реальные кросс-tenant наборы данных и поведение global scopes — БД не запрашивалась.
3. Полный аудит всех 58 контроллеров: выборка включала все Device-контроллеры и репрезентативные/high-risk Web-контроллеры.
4. Свежесть `routes.json` оценена по mtime; маршруты не регенерировались (read-only).
5. Производительность и параллельное выполнение обслуживающих операций — не измерялись.

## 5. Positive controls (проверено)

| Область | Evidence |
|---|---|
| Auth/throttle на логине и 2FA | `routes/web.php:63,67,69,72`; `AuthController.php:20-23,50-56,64,81` |
| Permission middleware | 272 роута; `EnsurePermission.php:35-47`, отказ 403 на `:49-51`; 81 роут с `@company` |
| Tenant isolation | `bootstrap/app.php:31-38`; `EnsureTenantIsolation.php:77-117,143-164,172-198` (fail-closed `:157-160`) |
| Device API | `routes/device.php:12-16` (`device.token` + `throttle:120,1`); `DeviceTokenAuth.php:23-38`; `:19-21` kiosk (`throttle:60,1`) |
| Device-side tenant scope | `BiometricEventController.php:56-59,220`; `RfidService.php:87-89` |
| Секрет storage-роутов | `ServeFile.php:27-28,55-59`; `ReceiveFile.php:28-29,45-47` |
| Traversal/path guards | `BackupController.php:53` (`basename`); `routes/web.php:381` (regex для AI-файлов); `ChatController.php:303` (storage prefix) |
| Request-scoped settings | `SettingsService.php:116-118` |
| Dual JSON/redirect там, где нужно | `DocumentController.php:371-379`; `TaskController.php:399-450`; `LmsController.php:415-424` |
| Scoped list-запросы в основном | `DocumentController.php:60-72`; `AttendanceController.php:41-50,80-89,135-150`; `LmsController.php:186-194`; `LmsService.php:71-83,384-388,523-524` |

## 6. Порядок закрытия

1. **P1:** BE-01, BE-03 — утечка списка, контракт ошибок API.
2. **P2:** BE-04, BE-05, BE-06 — вебхук, идемпотентность GET, долгие операции; BE-02 после проверки активного broadcaster.
3. **P3:** BE-07 и Suspected BE-S1…S3 после тестов/конфигурации prod.
