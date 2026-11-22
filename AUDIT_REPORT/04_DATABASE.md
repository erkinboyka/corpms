# 04 — База данных

Статус: **завершено (статический аудит схемы, миграций, моделей и запросов)**. `EXPLAIN`, нагрузочные тесты и фактическое выполнение `down()`-миграций на копии prod БД не выполнялись — такие выводы помечены `Not tested`/`Suspected`.

## 1. Краткая оценка

- 48 миграций, 107 Eloquent-моделей.
- Подтверждено **15** статических проблем: 4 High, 9 Medium, 2 Low; отдельно 5 Suspected и 4 области без проверки.
- Наиболее опасны: сломанный rollback расписаний (DB-01), сужающий enum RFID (DB-03), гонки окладов/номеров (DB-05/DB-07/DB-08), неатомарные переходы статусов (DB-13).
- Транзакции есть в ключевых сервисах (`AccountingService`, `TaskService`, `SignatureService`, `DocumentService`, `PayrollCalculationService`, `FinanceService`, `InventoryService`, `ChatService`, `LmsService`), но не покрывают все мутации статусов/аудита.

## 2. Подтверждённые проблемы

### DB-01 — High — `down()` миграции расписаний ломает FK rollback
- **Evidence:** `database/migrations/2026_10_31_000003_create_schedules_tables.php:87-95` — включаются FK, затем `dropIfExists('work_schedules')` (`:92`), и только после этого `dropForeign(['work_schedule_id'])` (`:93-95`).
- **Impact:** в MySQL rollback упадёт или оставит неконсистентное состояние; цепочка `migrate:rollback` прервётся.
- **Fix:** в `down()` сначала снять FK с `employees`, затем удалять дочерние таблицы и `work_schedules`; не трогать глобальный `Schema::enable/disableForeignKeyConstraints()`.
- **Verification:** на копии БД `migrate` → `migrate:rollback --step=1` → повторный `migrate`; проверить `information_schema.KEY_COLUMN_USAGE`.
- **Status:** Confirmed (порядок кода); выполнение rollback не тестировалось.

### DB-02 — Medium — Необратимые/`down()`-миграции данных
- **Evidence:** `2026_11_16_215115_mask_secret_values_in_audit_logs.php:59-62` — пустой `down()` с комментарием «необратимо»; `2026_11_19_000002_encrypt_existing_secret_settings.php:21-23` — шифрование сохраняется; `2026_11_21_000004_add_two_factor_and_oauth_to_users_table.php:22` — `dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'google_id', 'auth_provider'])`.
- **Impact:** откат не восстанавливает секреты аудита, исходные настройки и 2FA/OAuth; оператор может не заметить потери.
- **Fix:** явный `RuntimeException` для необратимых миграций; для 2FA — не дропать секрет, а обнулять флаг подтверждения с архивной колонкой.
- **Verification:** `SELECT COUNT(*) FROM users WHERE two_factor_secret IS NOT NULL;` до/после `up+down`.
- **Status:** Confirmed.

### DB-03 — High — RFID enum: rollback и валидация рассинхронизированы
- **Evidence:** `2026_11_02_100000_create_rfid_support.php:13-20` добавляет `rfid_reader`; `:42-48` в `down()` сужает enum обратно; `app/Http/Controllers/Web/BiometricController.php:115` — `in:face_terminal,kiosk,fingerprint_gate,turnstile,qr_reader` без `rfid_reader`.
- **Impact:** rollback упадёт/потеряет значения при существующих `rfid_reader`; через UI нельзя зарегистрировать устройство этого типа, хотя БД его поддерживает.
- **Fix:** в `down()` проверять наличие `rfid_reader` и бросать исключение; заменить хардкод на enum/`Rule::in` с единым списком.
- **Verification:** SQL `SELECT COUNT(*) FROM biometric_devices WHERE device_type='rfid_reader';` + feature-тест регистрации.
- **Status:** Confirmed.

### DB-04 — Medium — Отсутствующие FK в схемах
- **Evidence:** `2026_10_31_000004_create_attendance_tables.php:29` (`corrected_by_employee_id`), `2026_10_31_000006_create_tasks_tables.php:24` (`document_id`), `2026_10_31_000007_create_documents_tables.php:42,43,48` (`template_id`, `task_id`, `current_version_id`), `2026_11_19_100000_create_chat_tables.php:39` (`last_read_message_id`).
- **Impact:** висячие ссылки после удаления сотрудников/документов/сообщений, неверная аналитика и нечитаемые границы прочитанного.
- **Fix:** добавить FK с `nullOnDelete()` после проверки сирот; `dropForeign()` как безопасный rollback.
- **Verification:** SQL-проверки `LEFT JOIN ... IS NULL` для каждой колонки, затем попытка вставить несуществующий ID.
- **Status:** Confirmed (схема), состояние данных не проверялось.

### DB-05 — High — Гонка при изменении оклада
- **Evidence:** `2026_11_11_000001_create_payroll_tables.php:31-34` — нет уникальности открытого периода; `PayrollController.php:367-392` — `DB::transaction()` без `lockForUpdate()`, ищет `effective_to IS NULL` и создаёт новую запись; `PayrollCalculationService.php:80-87,118` берёт первую по `effective_from`.
- **Impact:** параллельные изменения создают несколько открытых записей; расчёт зарплаты молча берёт произвольную — неверные суммы.
- **Fix:** генерируемая колонка `open_ended_key` + `unique`, либо `lockForUpdate()` на сотруднике и проверка пересечений диапазонов.
- **Verification:** SQL `GROUP BY employee_id HAVING COUNT(*) > 1` для `effective_to IS NULL`; два параллельных `storeSalary`.
- **Status:** Confirmed (отсутствие ограничения/блокировки); гонка не воспроизводилась.

### DB-06 — Medium — Дубли строк ведомости
- **Evidence:** `2026_11_11_000001_create_payroll_tables.php:58-70` — индекс `['period_id','employee_id']` не уникален; `PayrollCalculationService.php:112-113,136` — delete+create авто-строк.
- **Impact:** повторный расчёт/ручная добавка создаёт дубли компонента → завышение gross/net.
- **Fix:** `unique(['period_id','employee_id','component_id','source'])` после проверки дублей; rollback — `dropUnique`.
- **Verification:** SQL `GROUP BY 1,2,3,4 HAVING COUNT(*) > 1`.
- **Status:** Confirmed (схема).

### DB-07 — Medium — `reg_number` без уникального индекса, регистрация без транзакции
- **Evidence:** `2026_10_31_000007_create_documents_tables.php:32` — `string('reg_number')->nullable()` без unique; `DocumentService.php:437-438` комментарий о защите от коллизии; `:308-319` — `update()` + `engine->record()` + `audit->log()` без транзакции.
- **Impact:** параллельная регистрация может выдать одинаковые номера;partial failure расходится статус, workflow и журнал.
- **Fix:** `unique(['company_id','reg_number'])` после проверки дублей; обернуть `register()` в `DB::transaction()` + `lockForUpdate()`.
- **Verification:** SQL дубли `reg_number`; два параллельных `register()`.
- **Status:** Confirmed.

### DB-08 — Medium — Generators номеров через `COUNT()+1`
- **Evidence:** `DocumentService.php:424-429` (`count()` + `sprintf('Д-...')`), `TaskService.php:371-383` (`TSK-...`), `SignatureService.php:583-586` (`signature_id`).
- **Impact:** при параллельных запросах одинаковые номера; уникальные индексы превращают это в `500`.
- **Fix:** таблица `number_sequences(type, year, last_value)` с `SELECT … FOR UPDATE` в транзакции, либо ULID/UUID.
- **Verification:** 20 параллельных созданий задачи/документа/подписи — все уникальны.
- **Status:** Confirmed (код); гонка не воспроизводилась.

### DB-09 — Medium — N+1 в списке чатов
- **Evidence:** `ChatService.php:230-232` — для каждого диалога `unreadCount()`; `:238-253` — отдельный `count()`; `:133-135` — `memberRow()` ещё один запрос.
- **Impact:** ~2N дополнительных SQL-запросов на список диалогов.
- **Fix:** групповой `whereIn(...)->groupBy(...)` + предзагрузка member rows; индекс `messages(conversation_id, employee_id, id, deleted_at)`.
- **Verification:** `DB::enableQueryLog()` для 5 и 50 диалогов — число запросов должно быть постоянным.
- **Status:** Confirmed (код); замеры не проводились.

### DB-10 — Medium — Неограниченная выборка посещаемости
- **Evidence:** `AttendanceController.php:41-53` — `whereDate('date', now())->get()`; `:80-91` — тот же unbounded `get()` в `liveFeed()` (периодический опрос).
- **Impact:** тяжёлый ответ и нагрузка на БД при большом числе сотрудников.
- **Fix:** пагинация/лимит и агрегаты `GROUP BY status`; индекс `attendance_records(date, employee_id)`.
- **Verification:** `EXPLAIN SELECT ... WHERE date = CURDATE() ORDER BY clock_in_at`; тест с 10k строк.
- **Status:** Confirmed (отсутствие лимита); объёмное поведение не тестировалось.

### DB-11 — Medium — `rfid_reader` недоступен в UI
- **Evidence:** migration `2026_11_02_100000_create_rfid_support.php:19` vs `BiometricController.php:115` (список без `rfid_reader`).
- **Impact:** функциональный разрыв схемы и валидации.
- **Fix:** единый источник типов устройств.
- **Verification:** feature-тест `POST /biometrics/register-device` с `device_type=rfid_reader`.
- **Status:** Confirmed.

### DB-12 — Low — `UNIQUE` с nullable колонками
- **Evidence:** `2026_10_31_000002_create_rbac_tables.php:45` (`employee_roles`), `2026_10_31_000004_create_attendance_tables.php:79` при nullable `employee_id` (`:69`), `2026_11_19_100000_create_chat_tables.php:66` при nullable `client_id` (`:59`). Контраст: правильный подход в `2026_11_19_000001_harden_settings_scope_constraints.php:20-21` (`scope_id` = `coalesce(company_id,0)`).
- **Impact:** в MySQL несколько `NULL` в уникальном ключе допустимы → дубли ролей/аномалий.
- **Fix:** генерируемые ключи с `COALESCE(...,0)` + unique.
- **Verification:** SQL `GROUP BY ... HAVING COUNT(*) > 1` для строк с NULL.
- **Status:** Confirmed (семантика); реальные дубли не проверялись.

### DB-13 — Medium — Неатомарные переходы статусов
- **Evidence:** `TaskService.php:210-225` — `update()` + `TaskStatusHistory::create()` + `audit->log()` без транзакции; `DocumentService.php:313-319` — `update()` + `record()` + `log()`; `SignatureService.php:232-238` — подпись создана, `used_at` токена проставляется вне транзакции `sign()`.
- **Impact:** частичное состояние при ошибке: статус изменён, но нет истории/аудита; QR-токен может остаться неотмеченным.
- **Fix:** `DB::transaction()` + `lockForUpdate()` на корневом объекте для каждого перехода.
- **Verification:** тест с исключением в `AuditService::log()` — статус и история остаются прежними; для QR —

 проверка `used_at`.
- **Status:** Confirmed (отсутствие транзакций); сбои не моделировались.

### DB-14 — Medium — Scope-фильтр после `limit(200)`
- **Evidence:** `RfidController.php:44-50` — `limit(200)->get()`, затем `:54-62` — `->filter(...)` по employee/department; `:66-84` — то же для событий.
- **Impact:** пользователь с scope `own`/`department` может не увидеть свои записи, если они вне первой сотни.
- **Fix:** переносить условия scope в SQL до `limit()`.
- **Verification:** 250 чужих карт с большими ID + 1 своя с меньшим; своя должна отображаться.
- **Status:** Confirmed.

### DB-15 — Low — Глобальные сертификаты не удаляются
- **Evidence:** migration `2026_11_21_000005_create_trusted_certificates_table.php:13-14` — `company_id` nullable, `type` = company/state; `EnsureTenantIsolation.php:149-150` rejected null; `TenantGuard.php:40-42`; для `Holiday` исключение есть (`:144-146`), для сертификатов нет; `CertificateController.php:49-52`.
- **Impact:** `super_admin` не может удалить глобальный сертификат через UI.
- **Fix:** исключение в tenant middleware для `TrustedCertificate` с `company_id = NULL` + platform-право, либо заполнить `company_id` миграцией (меняет семантику).
- **Verification:** SQLglobal records + feature-тест удаления как super_admin/обычный пользователь.
- **Status:** Confirmed (код); бизнес-требование не уточнялось.

## 3. Suspected (требуют замеров/данных)

| ID | Severity | Область | Evidence | Риск / проверка |
|---|---|---|---|---|
| DB-S1 | Low | `whereDate()` | `AnalyticsService.php:71-74`; `ReportController.php:219-220`; индекс `date` в `2026_10_31_000004...:34` | MySQL обычно не использует обычный индекс при `DATE(col)=?`; сравнить `EXPLAIN` с `whereBetween` |
| DB-S2 | Low | Период отчёта | `ReportController.php:176-197` — swap без лимита длины | диапазон 1900–2100 вызовет тяжёлые агрегаты; добавить `max 366 дней`, `422` |
| DB-S3 | Low | `audit_logs` без `company_id` | `2026_10_31_000008_create_signature_qr_infra_tables.php:98-109`; `DashboardController.php:156-166` | фильтрация через join, нет индекса `(company_id, created_at)`; проверить `EXPLAIN` |
| DB-S4 | Low | Дубль unique `settings` | `2026_11_18_140000_add_company_id_to_settings_table.php:24`; `2026_11_19_000001...:20-21` | два почти одинаковых индекса; `SHOW INDEX FROM settings` |
| DB-S5 | Low | `last_read_message_id` | `2026_11_19_100000_create_chat_tables.php:39`; `ChatService.php:247-250` | нет FK/проверки принадлежности диалогу; неверный unread count |

## 4. Не проверено

- DB-N1: выполнение всех `down()`-миграций на копии MySQL с данными.
- DB-N2: конкурентные гонки окладов/номеров/подписей.
- DB-N3: планы запросов, индексы, `EXPLAIN ANALYZE`.
- DB-N4: нагрузка на list-эндпоинты (чат, табель, RFID).

## 5. Рекомендуемый порядок исправлений

1. **P0:** DB-01, DB-03, DB-05, DB-13 — rollback, enum, оклады, атомарность переходов.
2. **P1:** DB-07, DB-08, DB-04, DB-14 — уникальность номеров, FK, видимость данных по scope.
3. **P2:** DB-09, DB-10, DB-06, DB-12, DB-15.
4. **P3:** Suspected/замеры DB-S1…S5 и DB-N1…N4.
