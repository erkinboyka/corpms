# 10 — Производительность и масштабируемость

Статус: **завершено (статический анализ „горячих“ путей)**. Нагрузочные тесты, `EXPLAIN ANALYZE`, профилировщик и браузерный load-test в рамках этого аудита **не запускались**. Наличие `tools/benchmark-local.php:38-62` и CI browser/load job (`.github/workflows/ci.yml:71-103`) проверено, но результаты не используются как доказательство.

## 1. Профиль нагрузки

- 314 маршрутов, из них 146 POST; 58 маршрутов имеют `throttle:`.
- Интерактивные опросы: чат (4 с, backoff до 30 с), LMS dashboard/whiteboard/presence/pulse (1,5–3 с), AI panel и search debounce (220 мс).
- Фоновые задачи: `routes/console.php:8-23` — attendance detection (08:30), `tasks.mark-overdue` (hourly), `db:backup` (03:30), `files:backup` (03:40), health (15 min).
- Тяжёлые синхронные действия в HTTP: `/accounting/sync`, `/bi/rebuild`, `/backups/db`, `/backups/files` (`routes/web.php:263,291,371-372`).
- Внешние вызовы: LLM/embeddings/RAG с sync timeout 8 с (`AiAssistantService.php:245-248,374-379`; `config/corpms.php:56,60`).

## 2. Подтверждённые риски

### PERF-01 — High — N+1 в списке чатов
- **Evidence:** `app/Domains/Chat/Services/ChatService.php:230-232` — для каждого диалога `unreadCount()`; `:238-253` — отдельный `count()`; `:133-135` — `memberRow()` ещё один запрос.
- **Impact:** ~2N+ запросов к БД на один список диалогов; рост задержки линейно от числа диалогов.
- **Fix:** один `whereIn(...)->groupBy(...)` + предзагруженные member rows; индекс `messages(conversation_id, employee_id, id, deleted_at)`.
- **Verification:** `DB::enableQueryLog()` для 5 и 50 диалогов; число запросов должно быть постоянным.
- **Status:** Confirmed (код); замеры не проводились. См. DB-09.

### PERF-02 — High — Неограниченная выборка табеля и periodic live feed
- **Evidence:** `app/Http/Controllers/Web/AttendanceController.php:41-53` — `whereDate('date', now())->orderBy(...)->get()`; `:80-91` — тот же unbounded `get()` в `liveFeed()`.
- **Impact:** live-опрос выгружает все записи дня компании; при 10k сотрудников — тяжёлый JSON и нагрузка на БД/память.
- **Fix:** server-side pagination/limit + агрегаты `GROUP BY status`; индекс `attendance_records(date, employee_id)`.
- **Verification:** `EXPLAIN SELECT ... WHERE date = CURDATE() ORDER BY clock_in_at`; тест с 10k строк.
- **Status:** Confirmed (отсутствие лимита); объёмное поведение не тестировалось. См. DB-10.

### PERF-03 — High — Scope-фильтр применяется после `limit(200)` в RFID
- **Evidence:** `app/Http/Controllers/Web/RfidController.php:44-50` — `limit(200)->get()`; `:54-62` — последующая `filter()` по сотруднику/отделу; `:66-84` — тот же порядок для событий.
- **Impact:**
  - для пользователей `own`/`department` доступные строки отсекаются лимитом до фильтра;
  - дополнительная обработка коллекции в PHP на каждой загрузке.
- **Fix:** перенести условия scope в SQL до `limit()`.
- **Verification:** 250 чужих карт с большими ID + 1 своя с меньшим; своя должна отображаться.
- **Status:** Confirmed. См. DB-14.

### PERF-04 — Medium — Chat polling backoff сбрасывается
- **Evidence:** `public/assets/js/bm-chat.js:485-493` — `.catch()` планирует 4/8/16/30 с, но `.then()` безусловно вызывает `schedulePoll()`; `:496-499` — `delay || 4000`.
- **Impact:** при повторяющихся ошибках backend опрос идёт каждые 4 с, усугубляя инцидент; лишние запросы/батарея.
- **Fix:** `nextDelay`, `finally()`, один вызов `schedulePoll(nextDelay)`.
- **Verification:** симулировать 500 и проверить интервалы 4/8/16/30 с.
- **Status:** Confirmed. См. FE-04/UX-04.

### PERF-05 — Medium — LMS polling не останавливается для скрытых секций/таба
- **Evidence:** `public/assets/js/lms.js:243` — `setInterval(load, pollMs)`, `:260` — скрытие секций, `clearInterval` отсутствует; `lms-whiteboard.js:410-411` — два интервала; `lms-pulse.js:86-108` — интервал с silent catch.
- **Impact:** фоновый трафик, stale DOM, незаметная деградация синхронизации.
- **Fix:** start/stop по `setSection()`/`visibilitychange`; видимый индикатор `syncLost` (уже есть в `lms-whiteboard.js:208-210`).
- **Verification:** Network-панель при переключении секций и скрытии таба.
- **Status:** Confirmed. См. FE-06/UX-06.

### PERF-06 — Medium — Долгие maintenance-операции без очереди и throttle
- **Evidence:** `routes/web.php:263,291,371-372` — permission-only; `AccountingEntryController.php:187` — `syncFinance()`; `BiDashboardController.php:203` — `rebuild()`; `BackupController.php:40,46` — `Artisan::call()`.
- **Impact:** таймауты HTTP, блокировки БД, дублирование дорогих операций при параллельных запросах.
- **Fix:** queue jobs + single-flight lock + `throttle:1,1`.
- **Verification:** 5 параллельных `/bi/rebuild` → 1 выполнение, остальные 429/ожидание.
- **Status:** Confirmed. См. BE-06.

### PERF-07 — Medium — `whereDate()` и неограниченные периодные отчёты
- **Evidence:** `AnalyticsService.php:71-74`; `ReportController.php:219-220`; `ReportController.php:176-197` — нет ограничения длины периода; индекс `date` в `2026_10_31_000004_create_attendance_tables.php:34`.
- **Impact:** `DATE(col)=?` обычно не использует ordinary index в MySQL; range в 200 лет вызывает тяжёлые агрегаты.
- **Fix:** `where('date', ...)`/`whereBetween`, валидация `max 366 дней` и `to <= today`, ответ 422.
- **Verification:** `EXPLAIN` до/после; `?from=1900-01-01&to=2100-01-01` → 422.
- **Status:** Suspected (зависит от MySQL plan); код подтверждён. См. DB-S1/DB-S2.

### PERF-08 — Medium — Поиск без валидации и с wildcard в LIKE
- **Evidence:** `SearchController.php:34,58,97,128`; `DocumentController.php:79-85`; контраст `ChatController.php:317` (`max:120`).
- **Impact:** длинные строки и `%`/`_` вызывают full scan; throttling снижает, но не устраняет.
- **Fix:** `max:120`, `addcslashes($q, '%_\\')`, единый search helper.
- **Verification:** 10k `%` → 422.
- **Status:** Confirmed (код); нагрузка не измерялась. См. BE-07.

### PERF-09 — Medium — Аудит-логи без прямого tenant-ключа
- **Evidence:** `2026_10_31_000008_create_signature_qr_infra_tables.php:98-109` — `audit_logs` без `company_id`; `DashboardController.php:156-166` — фильтрация через join с `employees`; `AnalyticsService.php:404-429` — агрегаты.
- **Impact:** быстро растущая таблица, join на каждом дашборде, нет индекса `(company_id, created_at)`.
- **Fix:** денормализовать `company_id` + индекс; safe rollback — `dropIndex` + `dropColumn` без удаления строк.
- **Verification:** `EXPLAIN ANALYZE` до/после на копии данных; сверить `COUNT(*)`.
- **Status:** Suspected (объём планов не измерялся). См. DB-S3.

### PERF-10 — Low — Дублирующий unique index `settings` и nullable-unique в RBAC
- **Evidence:** `2026_11_18_140000_add_company_id_to_settings_table.php:24` + `2026_11_19_000001_harden_settings_scope_constraints.php:20-21`; `2026_10_31_000002_create_rbac_tables.php:45`; `2026_10_31_000004_create_attendance_tables.php:79`.
- **Impact:** лишние проверки при вставке/обновлении; в MySQL несколько NULL в уникальном ключе допустимы → дубли ролей/аномалий.
- **Fix:** оставить `settings_scope_unique`; добавить generated key с `COALESCE(...,0)` для nullable uniqueness.
- **Verification:** `SHOW INDEX FROM settings`; SQL `HAVING COUNT(*) > 1` для NULL-строк.
- **Status:** Confirmed (схема), влияние на latency не измерялось. См. DB-12/DB-S4.

### PERF-11 — Low — Команды номеров через `COUNT()+1`
- **Evidence:** `DocumentService.php:424-429`; `TaskService.php:371-383`; `SignatureService.php:581-586`.
- **Impact:** при параллельных операциях — unique violation/500, а не корректный номер;sequence table требуется для масштабирования.
- **Fix:** `number_sequences(type, year, last_value)` + `SELECT … FOR UPDATE`; безопасный rollback — `dropIfExists('number_sequences')`.
- **Verification:** 20 параллельных созданий —— 20 уникальных номеров без exception.
- **Status:** Confirmed (код); гонка не воспроизводилась. См. DB-08/BL-07.

## 3. Что уже сделано хорошо

| Контроль | Evidence |
|---|---|
| Throttle на AI/chat/search/LMS | `routes/web.php:375-406,428-466` |
| Блокировки в финансах/складе | `FinanceService.php:170-229`; `InventoryService.php:107-186` |
| Транзакции в ключевых сервисах | AccountingService, TaskService, SignatureService, DocumentService, PayrollCalculationService, ChatService, LmsService (см. `04_DATABASE.md`) |
| Ограничения на kiosk feed | `BiometricEventController.php:217` — `limit` ≤ 100 |
| Клиентские timeout/Retry для LLM | `AiAssistantService.php:245-248,299-301,349-354,374-379` |
| Локальный benchmark-инструмент | `tools/benchmark-local.php:38-62` — isolated SQLite, 5 samples/route, query counts (не запускался в рамках аудита) |

## 4. Не проверено

1. `EXPLAIN`/`EXPLAIN ANALYZE` и реальные планы MySQL.
2. p95/p99 latency, throughput и query count на prod-подобных объёмах.
3. Влияние N+1/unbounded get на реальные страницы (чат, табель, RFID, отчёты).
4. Браузерный load-test из CI (`.github/workflows/ci.yml:94-103`) локально не выполнялся.
5. Параллельные гонки (оклады, номера, подписи, time tracking) — не воспроизводились. См. DB-N2.
6. Ёмкость диска/ротация бэкапов и стоимость внешних AI-провайдеров.

## 5. Приоритет оптимизаций

1. **P0:** PERF-01…PERF-03 — исправить до роста данных; это и корректность выдачи данных тоже.
2. **P1:** PERF-04…PERF-08 — убрать pressure на backend и сделать ограничения.
3. **P2:** PERF-09…PERF-11 — индексы/схема после замеров на копии данных.
4. **P3:** load/coverage/concurrency-тесты, метрики p95 и query budget в CI.
