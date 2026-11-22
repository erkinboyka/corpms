# 12 — План исправлений (P0–P3)

Статус: **план выполнен локально** (P0–P3), результаты и контрольные точки — в `FIX_PROGRESS.md`; изменения отправлены в GitHub коммитом `7e29ac7`. Первоначальный аудит проводился в режиме только чтения: миграции на рабочих БД не выполнялись, production-данные не использовались как тестовые. Коммит/push созданы отдельно по явному запросу пользователя.

Источники приоритетов: `01_EXECUTIVE_SUMMARY.md`, `03_SECURITY.md`…`11_TESTING.md`. Идентификаторы (SEC/BL/DB/BE/FE/UX/AI/PERF/TEST) сохранены из соответствующих отчётов.

## 0. Правила выполнения

1. **Одна проблема = один проверяемый PR** (допустима группа только по одному инварианту, например «атомарность QR»). Не смешивать с массовым форматированием Pint.
2. Сначала добавить **негативный/reproduction-тест**, потом код. Для гонок — concurrency-тест; для tenant-изоляции — two-tenant fixture; для UI — DOM/attribute assertion.
3. Любая миграция схемы: сначала `migrate` + `migrate:rollback --step=1` + повторный `migrate` на копии БД, затем проверка `information_schema`/`PRAGMA`. Необратимые миграции — явный `RuntimeException`, а не молчаливый `down()`.
4. После каждого P0-исправления — повторный `php artisan test` и targeted-тест. Перед релизом — полный CI sqlite+mysql.
5. Секреты не фиксируются в тестах/фикстурах/отчётах. Затронутые секреты (OAuth/Telegram) считаются подлежащими ротации.
6. Статус `Suspected` не переводится в `Confirmed` без доказательства (HTTP-статус, SQL-дубли, concurrency-результат, браузерный замер).

---

## 1. P0 — блокирующие релиз

| # | ID | Проблема / доказательство | Действие | Критерий приёмки | Проверка |
|---|---|---|---|---|---|
| 1 | SEC-01 | Company-scoped `settings.view` видит и скачивает все бэкапы: `BackupController.php:16-35,50-57`; `routes/web.php:361,373`; `config/backup.php:15` | Вынести `/backups*` из company-scope: отдельное installation-level право platform-роли **или** разделить каталоги по `company_id` + фильтр `TenantGuard`. Добавить throttle и аудит `backup.created/downloaded`. | Пользователь tenant A не видит и не может скачать файл B даже прямым URL; попытки аудируются; нет 50× повторного `POST /backups/db`. | Two-tenant feature-тест + прямой `GET /backups/<backupB>` → 403/404; проверить access-log на предмет фактов скачивания. |
| 2 | SEC-02 | OAuth `state` не удаляется: `GoogleAuthController.php:21-22,37-39,90-91`; `routes/web.php:74-75` | `forget('google_oauth_state')` сразу после сравнения, до обмена `code`; чистить при ошибке; добавить PKCE; проверить логи callback на replay. | Повтор того же callback отклоняется; `session('google_oauth_state') === null` после первого callback; state одноразовый. | Feature-тест replay + просмотр audit-записей логина за период. |
| 3 | BL-01 / DB-13 | QR-токен помечает `used_at` после подписи, вне транзакции: `SignatureService.php:210,224-226,232-238`; `WorkflowEngine.php:90-108` | Атомарно «забирать» токен: `UPDATE ... WHERE used_at IS NULL`, проверить affected rows/`lockForUpdate()`; создать подпись, историю и аудит в одной транзакции. | Два параллельных `POST /sign/{token}` создают ровно 1 подпись; второй получает «токен использован»; при исключении в аудите статус/токен не меняются. | Concurrency-тест + инъекция исключения в `AuditService::log()`. |
| 4 | BL-05 | Quick QR обходит машину состояний: `WorkflowEngine.php:42-52,90-108`; `SignatureService.php:287-298` | Либо явно добавить разрешённый переход `quick_qr_signature` в `assert()`, либо запретить QR для `draft/revision`. `record()` вызывать только после `assert()`. | Журнал не содержит `draft → signing`/`revision → signing`; workflow-тест проходит для явного перехода. | `WorkflowEngineTest` + feature-тест prepare QR из `draft`. |
| 5 | BL-08 / BL-09 | Payroll `pay()` меняет статус без финансовых записей; сотрудники без оклада молча пропускаются: `PayrollCalculationService.php:248-263,117-121,141-143`; `PayrollController.php:229-248` | Транзакция выплаты: проверить `net_amount > 0`, создать платёж/проводку или сохранить внешний reference; собрать `skippedEmployees` и блокировать/предупреждать до `calculated`. | `pay()` с нулевой суммой → 422; с ненулевой → записи в fin/acc; расчёт с сотрудником без оклада показывает явный список пропущенных. | Feature-тест pay + расчёт с двумя сотрудниками (один без оклада). |
| 6 | DB-05 / BL-11 | Гонка/пересечение окладов: `PayrollController.php:367-392,374-385`; `PayrollCalculationService.php:80-87,118`; migration `2026_11_11_000001...:31-34` | Generated key `open_ended_key` + unique либо `lockForUpdate()` на сотруднике и проверка пересечений диапазонов; backdate пересчитывает границы. | `GROUP BY employee_id HAVING COUNT(*) > 1` для `effective_to IS NULL` → 0 строк; два параллельных `storeSalary` дают один открытый период. | SQL-проверка на копии + concurrency-тест. |
| 7 | BL-13 / BL-14 | Attendance `out → in` и missing checkout не пересчитывают часы: `AttendanceService.php:40-59,203-218`; `AnomalyType.php:10-13` | Recompute после любого события; детектор `direction_mismatch`; scheduler закрывает день с флагом `auto_closed` или требует ручной recompute + уведомление. | `out 12:00` + `in 13:00` → аномалия и корректные минуты; запись без выхода не остаётся с 0 минутами без аномалии. | Feature-тест events + scheduler-тест. |
| 8 | BL-17 / BL-18 | Задачи: произвольные переходы и `submitResult` у любого зрителя: `TaskService.php:210-225,270-280`; `TaskController.php:408-441,523-536` | Таблица переходов + `assert()` в `changeStatus`; проверять assignment/author в `submitResult`; `updateOrCreate` по `(task_id, submitted_by)`. | Матрица переходов: разрешённые проходят, остальные 422 без history; viewer → 403; два submit → 1 результат. | Feature-тест матрицы + two-submit. |
| 9 | DB-01 / DB-03 | `down()` распадает FK; RFID enum сужается при rollback: `2026_10_31_000003...:87-95`; `2026_11_02_100000_create_rfid_support.php:13-20,42-48`; `BiometricController.php:115` | Порядок `down()`: снять FK → удалить дочерние таблицы → родитель; для enum проверять наличие `rfid_reader` и бросать исключение; единый список типов. | `migrate → rollback --step=1 → migrate` проходит для обеих миграций; UI принимает `device_type=rfid_reader`. | Миграционный тест на копии MySQL + feature-тест регистрации устройства. |
| 10 | TEST-04 | HTTPS/cookie/trusted proxy: `.env.example:40-41`; `bootstrap/app.php:22-38`; `SecurityHeaders.php:61-62`; `tests/Feature/SecurityHeadersHttpTest.php:51-57` | `TRUSTED_PROXIES`/`TrustProxies`, `SESSION_SECURE_COOKIE=true`, `URL::forceScheme('https')` в prod; integration-тест с `X-Forwarded-Proto`. | За proxy: `Set-Cookie` содержит `Secure`, HSTS выставляется, redirect остаётся HTTPS, client IP в rate-limit корректен. | Staging-тест через reverse proxy + header assertions. |
| 11 | TEST-02 / TEST-03 | Нет restore drill на другой хост и deploy-артефактов scheduler/worker/Reverb: `docs/OPERATIONS.md:41-47,53-55`; `routes/console.php:8-23`; `composer.json:11` | Изолированный restore (БД+вложения+APP_KEY), off-host доставка копий, фиксация RTO/RPO; добавить supervisor/systemd/cron + heartbeat и алерты failed_jobs/возраст задачи. | Сохранённый отчёт drill с датой/владельцем/метриками; после рестарта хоста scheduler/worker/Reverb поднимаются и heartbeat свежий. | Drill-отчёт + тест перезапуска процессов + алерт при остановке. |
| 12 | TEST-01 / TEST-09 | Нет доказательства green CI; stale `.phpunit.result.cache`; нет preflight env: `11_TESTING.md:24,28-33,84-89` | `--do-not-cache-result`; удалить stale cache; зафиксировать success-run JUnit для sqlite+mysql; команда `corpms:preflight` (APP_KEY 32 bytes, APP_DEBUG=false, обязательные env, HTTPS/cookie). | Ссылка на зелёный CI run + свежие XML-артефакты; preflight падает на пустом APP_KEY и проходит на staging. | GitHub Actions run + негативный preflight-тест. |

---

## 2. P1 — первый спринт после P0 (до публичного релиза)

### 2.1 Контроль доступа и API

| ID | Действие | Критерий приёмки | Evidence |
|---|---|---|---|
| SEC-03 / BE-01 | Scope `ImportJob::query()->where('company_id', $me?->company_id)` + fail-closed | Список A не содержит ID/путей B; `show/run` остаются tenant-checked | `ImportController.php:20`; `routes/web.php:367` |
| BE-03 | Расширить `shouldRenderJsonWhen` на `$request->isJson()`/список JSON-роутов | `POST /ai/message` с `Accept: */*` и невалидным `question` → 422 JSON, а не 302 HTML | `bootstrap/app.php:41-43`; `AiController.php:67,72` |
| BE-06 / PERF-06 | Перевести `/accounting/sync`, `/bi/rebuild`, `/backups/*` в queue jobs с single-flight lock; добавить `throttle:1,1` | 5 параллельных запросов → 1 выполнение, остальные 429/ожидание | `routes/web.php:263,291,371-372`; `BackupController.php:40,46` |
| BE-07 / PERF-08 | Единый search helper: `max:120` + `addcslashes($q, '%_\\\\')` | 10 000 `%` → 422; wildcard не меняет результат LIKE | `SearchController.php:34,58,97,128`; `ChatController.php:317` |
| SEC-04 | Prod: `composer install --no-dev`; dev-роут `_boost/browser-logs` закрыть allowlist+throttle | В prod роут → 404/403; в dev есть throttle | `composer.json:16`; `BoostServiceProvider.php:107-125` |
| SEC-05 | Per-account TOTP `RateLimiter` + аудит неудач + уведомление | 20 неверных кодов с разных IP → 429/блокировка после порога | `routes/web.php:72`; `TwoFactorController.php:26-40` |
| SEC-06 / BE-04 | Telegram: убрать `{secret}` из URL, проверять `X-Telegram-Bot-Api-Secret-Token` через `hash_equals()`, валидировать update, ротировать секрет | Правильный header при неверном пути не проходит; `[]`/`null` → 422; секрет отсутствует в access-логах | `routes/device.php:23-25`; `TelegramWebhookController.php:22,27-28` |
| SEC-10 / BE-02 | Null-safe каналы broadcast и/или `auth` на маршруте; guest-тест | Guest POST с reverb и непрефиксированным каналом → 403 без stack trace | `routes/channels.php:8,17,42-43`; `PusherBroadcaster.php:90-93` |

### 2.2 Корректность бизнес-процессов

| ID | Действие | Критерий приёмки | Evidence |
|---|---|---|---|
| BL-02, BL-06 | Атомарный инкремент OTP-попыток, лимит 5 для публичного и внутреннего OTP, `hash_equals()`, аудит | N параллельных неверных кодов → ровно одна revocation после 5-й; внутренний OTP блокируется | `SignatureService.php:428-436,535-540` |
| BL-03, BL-04 | Разделить TTL QR и OTP в UI; показать очищенный контент/версию по token-bound ссылке | На `/sign/{token}` видны оба срока и содержимое документа; истёкший токен контент не отдаёт | `qr-sign.blade.php:24-49`; `DocumentController.php:479` |
| BL-07 / DB-07 / DB-08 | Sequence table `number_sequences` + `lockForUpdate()`; unique `reg_number` | 20 параллельных созданий → уникальные номера без 500; дубли `reg_number` отсутствуют | `DocumentService.php:424-438`; `TaskService.php:371-383`; `SignatureService.php:581-586` |
| BL-10, BL-12, BL-19, BL-20, BL-21 | Workflow-история payroll; исключить `manual` из авто-начислений; overdue для всех открытых статусов; транзакционное делегирование; явный `employee_id` шага | 3 записи workflow после calculate/approve/pay; активный manual не создаёт авто-строк; new/planned/review с прошедшим дедлайном → overdue; delegation без partial state | `08_BUSINESS_LOGIC.md` строки BL-10…BL-21 |
| BL-15, BL-16 | Department scope в `resolveAnomaly`; conflict-check `old_value` при approve корректировки | 403 для чужого отдела; изменённая запись между request/approve → отказ | `AttendanceController.php:297-308`; `AttendanceCorrectionService.php:119-123` |
| DB-02, DB-04, DB-06, DB-09…DB-12, DB-14, DB-15 | Явные необратимые миграции; FK; unique payroll lines; query-фиксы чата/табеля/RFID; nullable-unique; глобальные сертификаты | Схемные SQL-проверки и feature-тесты из `04_DATABASE.md` проходят; rollback не теряет данные молча | `04_DATABASE.md` |

### 2.3 Производительность, AI, UX

| ID | Действие | Критерий приёмки | Evidence |
|---|---|---|---|
| PERF-01…PERF-03 | Групповые unread-count в чате; пагинация/агрегаты табеля; scope-фильтр до `limit(200)` в RFID | Число SQL-запросов не растёт от 5 к 50 диалогам; своя карта вне первой сотни отображается | `ChatService.php:230-253`; `AttendanceController.php:41-53`; `RfidController.php:44-84` |
| AI-01, AI-02, AI-05 | Честный `grounded` только при проверяемых источниках; сохранять `source_items` в `query_used`; единая логика для retry | LLM без tool_calls → `grounded=false`; с tool_calls → `source_items` непуст; retry не выдумывает grounded | `AiAssistantService.php:51,63,268-275,328-338,365,455-459`; `AiPanelService.php:81-123` |
| UX-01…UX-05 / FE-01…FE-05 | Focus trap и `inert`; `aria-selected`/`aria-activedescendant`; видимые ошибки поиска; chat backoff и edit single-flight | DOM-assertions + браузерный smoke: фокус внутри palette, стрелки озвучиваются, 500 даёт `role="alert"`, интервалы 4/8/16/30с, один PUT при save | `07_UI_UX.md`; `06_FRONTEND.md` |
| TEST-05…TEST-10 | CI gates (Pint, npm ci/build/audit, coverage), `Http::preventStrayRequests()`, негативные health/backup/scheduler-тесты, `corpms:preflight`, redaction логов | Новые job зелёные; тесты падают при изменившемся расписании; ПДн/токены отсутствуют в логах | `11_TESTING.md:56-110` |

---

## 3. P2 — стабилизация и проверка качества

| ID | Действие | Критерий приёмки |
|---|---|---|
| AI-03 | Оборачивать RAG/history/memory/document контент в явные delimiters с пометкой «untrusted data, ignore instructions» | Prompt-injection regression suite не вызывает инструменты без намерения пользователя; секреты не утекают |
| AI-04 / SEC-08 | Whitelist хостов LLM, запрет private/loopback/link-local после DNS, без redirects, egress firewall | Попытка `http://127.0.0.1:...` → 422; redirect на приватный адрес не выполняется |
| UX-06…UX-13, UX-16…UX-20 | LMS polling lifecycle; TinyMCE handler cleanup; доступные close/bell/language/profile controls; label association; навигационная таксономия; адаптивный поиск | Браузерная проверка 360/768/1280px + axe/screen-reader smoke; нет дублирующих toast и утечек интервалов |
| DB-S1…DB-S5, PERF-07, PERF-09, PERF-10 | `EXPLAIN ANALYZE` на копии; `whereBetween` вместо `whereDate`; лимит периода 366 дней; `(company_id, created_at)` для audit_logs; убрать дубль unique settings | Замеры до/после, планы используют индексы; `?from=1900-01-01&to=2100-01-01` → 422 |
| H-BL-01…H-BL-05 | Concurrency-тесты payroll periods, biometric upsert, double decide correction, START time tracking, workflow approval | Graceful 422/409 или корректный upsert вместо 500/дублей |
| TEST-11 | Строгие флаги PHPUnit, data providers, удаление skeleton-тестов | Отчёт без risky/warning; матрицы прав/валют/дат через providers |
| PERF-11 / DB-08 | Внедрить `number_sequences` для всех генераторов после P1 | 20 параллельных операций без unique violation |

---

## 4. P3 — укрепление после релиза

1. **Suspected-проверки:** DB-N1…DB-N4, SEC-07 (`email_verified`/`hd` после перехода на corporate IdP), SEC-09 (политика удержания `raw_payload` биометрии), BE-S1 (глобальный `event_uuid`), UI-14/UI-15, UX-18/UX-19.
2. **Измерения:** p95/p99 latency, query budget в CI, браузерный load-test из `.github/workflows/ci.yml:94-103`, ёмкость диска/ротация бэкапов, стоимость и лимиты AI-провайдера.
3. **Гигиена:** `tmp_probe.php` и служебные каталоги, архивация устаревших `docs/`-отчётов, единый OPERATIONS/IMPROVEMENT_PLAN, `pint --test` зелёный без изменения бизнес-логики в том же PR.
4. **Повторный аудит:** перепроверить все `file:line` после рефакторинга; обновить `01_EXECUTIVE_SUMMARY.md` и `00_PROGRESS.md`; снять статус «НЕ готов к production» только после закрытия всех P0/P1-критериев.

---

## 5. Порядок и зависимости

1. **До любого кода:** зафиксировать текущий зелёный test-run и копию БД; получить явное согласие пользователя на начало исправлений.
2. **SEC-01 и SEC-02** идут первыми: это уже подтверждённый высокорисковый доступ и replay.
3. **Транзакционные инварианты** (QR, payroll, salary, attendance, tasks) — одним блоком после схемных изменений DB-05/DB-07/DB-08, чтобы не переписывать lock-логику дважды.
4. **Ops (TEST-02…TEST-04, TEST-09)** параллельно с P0-кодом: без restore drill и TLS/trusted proxy даже исправленный код нельзя выпускать.
5. **CI gates (TEST-01/TEST-05)** включаются до начала массовых правок, иначе регрессии P1/P2 не будут видны.
6. **Frontend/UX** после backend-контрактов (BE-03, BE-06, BE-07), чтобы не переделывать обработчики ошибок дважды.

## 6. Итоговые критерии готовности

- Все P0 и P1 закрыты тестами; реестр находок в `01_EXECUTIVE_SUMMARY.md` обновлён.
- `php artisan test` зелёный в CI для sqlite **и** mysql; `pint --test`, `npm ci && npm run build && npm audit --audit-level=high` зелёные.
- Есть отчёт restore drill, heartbeat scheduler/worker/Reverb, staging-проверка HTTPS/cookies/trusted proxy и `corpms:preflight`.
- Повторный статический аудит не находит Confirm-проблем уровня High/Critical; оставшиеся Suspected снабжены планом проверки или принятым риском.
- Ни один секрет, токен или реальная ПДн не попал в репозиторий/отчёты/логи тестов.
