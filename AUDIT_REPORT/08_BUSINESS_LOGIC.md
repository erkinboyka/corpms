# 08 — Бизнес-логика и пользовательские сценарии

Статус: **завершено (статический аудит сценариев frontend → controller → service → DB)**. Конкурентные гонки и реальные partial failure не воспроизводились; такие выводы помечены как code-level Confirmed с оговоркой.

## 1. Подтверждённые проблемы

### BL-01 — High — QR-подпись: токен помечается `used_at` после создания подписи
- **Evidence:** `SignatureService.php:210` — поиск токена; `:224-226` — проверка `used_at`; `:232-234` — `$this->sign(...)` затем `$token->forceFill(['used_at' => now()])->save()`; `:236-238` — мета подписи сохраняется отдельно; публичные роуты `routes/web.php:101-105` только с throttle.
- **Impact:** два параллельных `POST /sign/{token}` могут оба пройти проверку и создать две подписи по одному одноразовому токену.
- **Fix:** в одной транзакции атомарно «забирать» токен (`UPDATE ... WHERE used_at IS NULL`, проверить affected rows, `lockForUpdate()`) и только после этого создавать подпись.
- **Verification:** concurrency-тест двух POST: `DocumentSignature::count() === 1`, второй — «уже использован».
- **Status:** Confirmed (code-level).

### BL-02 — Medium — OTP-попытки QR не блокируются атомарно и не аудируются
- **Evidence:** `SignatureService.php:428-434` — `$attempts = ... + 1`, `saveQuietly()`, `hash_equals()` на `:436`; аудита неудачных попыток нет; IP-throttle `routes/web.php:105`.
- **Impact:** параллельные неверные коды могут прочитать одинаковое число попыток и обойти лимит 5; распределённый brute-force не логируется.
- **Fix:** инкремент через `UPDATE ... attempts = attempts + 1` в транзакции/`lockForUpdate()`, аудит каждой неудачи.
- **Verification:** N параллельных неверных OTP → ровно одна revocation после 5-й попытки и N аудит-записей.
- **Status:** Confirmed.

### BL-03 — Medium — На QR-экране неверно подписан TTL кода
- **Evidence:** `SignatureService.php:312-330` — отдельные TTL QR (часы) и OTP (минуты); `SignatureService.php:423-425` — проверка `otp_expires_at`; `DocumentController.php:479` передаёт `$token->expires_at`; `resources/views/signatures/qr-sign.blade.php:46-48` подписывает его как «Код действителен до».
- **Impact:** пользователь думает, что код живёт 24 часа, хотя он живёт 5 минут.
- **Fix:** показывать отдельно «QR-ссылка действительна до» и «код подтверждения действует до».
- **Verification:** feature-тест страницы проверяет оба времени.
- **Status:** Confirmed.

### BL-04 — Medium — На публичной странице QR нельзя проверить содержимое документа
- **Evidence:** `signatures/qr-sign.blade.php:24-49` — только title/number/signer/version/короткий hash/TTL; body или файл версии не доступны.
- **Impact:** надпись «Проверьте документ» (`:14`) невыполнима: подписант не видит, что подписывает.
- **Fix:** показывать очищенный body или token-bound ссылку на версию.
- **Verification:** `/sign/{token}` содержит контент; истёкший токен его не отдаёт.
- **Status:** Confirmed.

### BL-05 — High — Quick QR обходит декларативную машину состояний
- **Evidence:** `WorkflowEngine.php:42-52` — `draft => ['submitted']`, `revision => ['submitted']`, `signing` только из `approved/registered/signing`; `SignatureService.php:287-298` — из любого `$from` не в `['approved','signing']` делается `update(status='signing')` + `engine->record(..., 'quick_qr_signature')`; `WorkflowEngine.php:90-108` — `record()` не вызывает `assert()`.
- **Impact:** журнал содержит недопустимые переходы `draft → signing`, `revision → signing`; QR становится обходом согласования.
- **Fix:** добавить явный разрешённый переход с action `quick_qr_signature` и вызывать `assert()` перед `record()`, либо запретить QR для черновиков.
- **Verification:** `WorkflowEngineTest` + feature-тест prepare QR из `draft`.
- **Status:** Confirmed.

### BL-06 — Medium — Внутренний OTP: нет лимита попыток и сравнение не constant-time
- **Evidence:** `SignatureService.php:535-540` — счётчик увеличивается, но не проверяется; `hash('sha256', $otp) !== $stored`.
- **Impact:** неограниченный перебор OTP аутентифицированным пользователем и timing side channel.
- **Fix:** единая политика 5 попыток + `hash_equals()`, блокировка и аудит.
- **Verification:** 6 неверных OTP → запрос заблокирован.
- **Status:** Confirmed.

### BL-07 — Low — Генераторы SIG-ID, номеров задач и документов: `count()+1`
- **Evidence:** `SignatureService.php:581-586`; уникальный индекс `2026_10_31_000008_create_signature_qr_infra_tables.php:52`; `TaskService.php:371-383`; `DocumentService.php:424-429`.
- **Impact:** при параллельных операциях вторая транзакция упадёт из-за unique вместо корректного номера.
- **Fix:** sequence table с `lockForUpdate()` в транзакции или ULID/UUID.
- **Verification:** параллельные создание задачи/подписи/документа — уникальные номера без 500.
- **Status:** Confirmed (риск гонки).

### BL-08 — High — Payroll `pay()` меняет статус без финансовых/бухгалтерских записей
- **Evidence:** `PayrollCalculationService.php:248-263` — только `update(status=Paid, paid_at, paid_by)`; `PayrollController.php:229-248` — только аудит; `FinPayment`/`FinTransaction`/`AccEntry` в сценарии не создаются.
- **Impact:** ведомость помечена оплаченной даже при `net_amount = 0` и без реального платежа; reconcile с финансами невозможен.
- **Fix:** транзакция выплаты: проверить `net_amount > 0`, создать финансовую запись/проводку или сохранить внешний payment reference.
- **Verification:** `pay()` с нулевой ведомостью → 422; с ненулевой → записи в fin/acc и workflow.
- **Status:** Confirmed.

### BL-09 — High — Сотрудники без оклада молча исключаются из расчёта
- **Evidence:** `PayrollCalculationService.php:117-121` — `if ($salary === null) continue;`; `:141-143` — ошибка только если вообще нет строк.
- **Impact:** employee count и net amount занижены, расчёт считается успешным.
- **Fix:** собирать `skippedEmployees`, показывать warning/blocking validation до статуса `calculated`.
- **Verification:** employee без `EmployeeSalary` + employee с окладом → явный список исключённых/fail.
- **Status:** Confirmed.

### BL-10 — Medium — Payroll не журналирует переходы через WorkflowEngine
- **Evidence:** `PayrollCalculationService.php:347` (`Calculated`), `:237-241` (`Approved`), `:256-260` (`Paid`); `WorkflowEngine.php:63-68` описывает `draft → calculated → approved → paid`, но сервис его не вызывает.
- **Impact:** история ведомости отсутствует; декларативная машина и факт расходятся.
- **Fix:** `assert()` + `record()` перед каждым `update(status)`.
- **Verification:** после calculate/approve/pay — 3 записи в `workflow_transitions`.
- **Status:** Confirmed.

### BL-11 — Medium — Backdated оклад оставляет пересекающиеся периоды
- **Evidence:** `PayrollController.php:374-385` — prev `effective_to` закрывается только если `prev.effective_from < newFrom`; ветки `gt` нет; `PayrollCalculationService.php:80-87,118` берёт первую по `effective_from`.
- **Impact:** два активных оклада пересекаются, расчёт может использовать устаревший.
- **Fix:** пересчитывать границы при backdate или отклонять дату; DB-уникальность открытого периода (см. DB-05).
- **Verification:** previous 2026-06-01, new 2026-01-01 → нет пересечений, январь использует новый оклад.
- **Status:** Confirmed.

### BL-12 — Medium — Активный `manual`-компонент автоматически начисляется всем
- **Evidence:** `PayrollCalculationService.php:56-59` — все `is_active`; `:131-138` — строка на каждый компонент; `:309` — `Manual => [1.0, $rate, $rateCents]`.
- **Impact:** «ручная» статья становится авто-начислением для всех сотрудников в расчёте.
- **Fix:** исключать `manual` из авто-цикла; создавать такие строки только из UI ручной корректировки.
- **Verification:** активный manual-компонент не создаёт `payroll_lines.source=manual` при расчёте.
- **Status:** Confirmed.

### BL-13 — High — Attendance: `out → in` ломает табель
- **Evidence:** `AttendanceService.php:40-59` — для `in` при существующем `clock_in_at` early return, пересчёт только в ветке `out` (`:58`); `AnomalyType.php:10-13` объявляет `direction_mismatch`, но `:211-215` создаёт только `MissingCheckout`.
- **Impact:** повторный вход после выхода не пересчитывает часы; `out` без утреннего `in` оставляет 0 минут без аномалии.
- **Fix:** recompute после любого события, детектор `direction_mismatch`, явная политика новой смены.
- **Verification:** `out 12:00` + `in 13:00` → аномалия и корректные поля.
- **Status:** Confirmed.

### BL-14 — Medium — `detectMissingCheckout` только создаёт аномалию
- **Evidence:** `AttendanceService.php:203-218` — `updateOrCreate(AttendanceAnomaly...)`, без закрытия дня/recompute.
- **Impact:** `clock_out_at=null`, `worked_minutes=0` остаются до ручной реакции HR; payroll может недосчитать часы.
- **Fix:** автозакрытие с флагом `auto_closed` либо обязательный recompute + уведомление.
- **Verification:** scheduler-тест с записью без выхода.
- **Status:** Confirmed.

### BL-15 — Medium — `resolveAnomaly` не проверяет department scope
- **Evidence:** `AttendanceController.php:297-308` — только route model binding и update; `RbacSeeder.php:142` — `attendance.anomalies.manage => department`; контраст: `AttendanceCorrectionController.php:144-156` проверяет scope.
- **Impact:** руководитель отдела может закрыть аномалию сотрудника другого отдела в своей компании (tenant isolation соблюдается).
- **Fix:** переиспользовать `scopeAllows()`/`PermissionService::canAccessEmployee`.
- **Verification:** 403 для чужого отдела.
- **Status:** Confirmed.

### BL-16 — Medium — Одобренная корректировка перезаписывает актуальные данные
- **Evidence:** `AttendanceCorrectionService.php:119-123` — `forceFill([$correction->field => $correction->new_value])`; `old_value` не сверяется с текущей записью, date window не проверяется.
- **Impact:** устаревшая заявка может перезаписать биометрические данные и исказить payroll.
- **Fix:** при approve проверять `old_value` против факта и допустимый период; при расхождении — conflict/reject.
- **Verification:** изменение записи между request и approve → отказ.
- **Status:** Confirmed.

### BL-17 — High — Задачи: `changeStatus` не проверяет переходы
- **Evidence:** `TaskService.php:210-225` — прямой `update(status)` + история/аудит без `assert()`; `TaskController.php:408-441` разрешает любой статус из enum.
- **Impact:** `new → completed`, `completed → new`, ручной `overdue` — без ограничений.
- **Fix:** TaskStatusEngine/таблица переходов + `assert()` в `changeStatus`, рольовые ограничения на terminal-статусы.
- **Verification:** матрица переходов: разрешённые проходят, остальные 422 без history.
- **Status:** Confirmed.

### BL-18 — High — `submitResult` доступен любому зрителю и создаёт дубли
- **Evidence:** `TaskController.php:523-536` — только `canSeeTask()`; `TaskService.php:270-280` — всегда `TaskResult::create()` + `changeStatus(Review)`; миграция `2026_10_31_000006_create_tasks_tables.php:130-137` без unique по task/submitter.
- **Impact:** не-исполнитель с `tasks.view=company` может отправить результат; повторная отправка дублирует results и снова переводит задачу в Review.
- **Fix:** проверять assignment/author + `updateOrCreate` по `(task_id, submitted_by)` или явный отказ повтора.
- **Verification:** viewer → 403; два submit → 1 запись.
- **Status:** Confirmed.

### BL-19 — Medium — `markOverdue()` обрабатывает только `in_progress`
- **Evidence:** `TaskService.php:353-363` — `where('status','in_progress')`; UI показывает overdue по дедлайну (`tasks/index.blade.php:82,132`), scheduler hourly (`routes/console.php:13-16`).
- **Impact:** расхождение UI и БД/фильтров; колонка overdue kanban наполняется неполно.
- **Fix:** все открытые статусы с `deadline <= now()` + запись истории.
- **Verification:** new/planned/review с прошедшим дедлайном → overdue + history.
- **Status:** Confirmed.

### BL-20 — Medium — Делегирование задачи не транзакционно
- **Evidence:** `TaskService.php:177-191` — `TaskDelegation::create()` и `$assignment->update()` без `DB::transaction()`.
- **Impact:** partial state при сбое.
- **Fix:** транзакция + `lockForUpdate()` на assignment.
- **Verification:** инъекция исключения между операциями → нет ни delegation, ни смены исполнителя.
- **Status:** Confirmed.

### BL-21 — Medium — Workflow-шаг `employee` резолвится на автора документа
- **Evidence:** `DocumentService.php:383-390` — `'employee' => collect([$document->author])`; `2026_10_31_000007_create_documents_tables.php:88-96` — у шага workflow нет `employee_id`.
- **Impact:** невозможно назначить конкретного сотрудника на шаг; возможное само-согласование автором.
- **Fix:** добавить `employee_id` в `document_workflow_steps` или убрать тип `employee`.
- **Verification:** шаг с employee B и автором A → actor B.
- **Status:** Confirmed.

## 2. Гипотезы (требуют concurrency-теста)

| ID | Severity | Сценарий | Evidence |
|---|---|---|---|
| H-BL-01 | Medium | Пересекающиеся payroll-периоды с разными `period_start` | `PayrollController.php:96-106` без транзакции; unique только `['company_id','period_start']` (`2026_11_11_000001...:55`) |
| H-BL-02 | Medium | Параллельные biometric events одного дня → 500 вместо upsert | `AttendanceService.php:33-36`; unique `['employee_id','date']` (`2026_10_31_000004...:33`) |
| H-BL-03 | Medium | Double decide correction | `AttendanceCorrectionService.php:94-104` — чтение статуса до транзакции, без `lockForUpdate()` |
| H-BL-04 | Medium | Параллельный START time tracking | `TaskService.php:285-305`; нет partial unique на `stopped_at IS NULL` |
| H-BL-05 | Medium | Параллельное согласование одного workflow-шага | `DocumentService.php:241-263` — транзакция есть, но нет lock на state/approval |
| H-BL-06 | Medium | Prompt injection через RAG/history/memory | `AiAssistantService.php:132-150,250-260`; см. `09_AI_SYSTEM.md` |

## 3. Сценарии без подтверждённых дефектов

- **Finance:** `FinanceService.php:170-229` — `lockForUpdate()` на invoice, проверка остатка, атомарный payment + transaction.
- **Inventory:** `InventoryService.php:107-140,146-186` — блокировка warehouse и проверка остатка, приём заказа в транзакции.
- **Chat:** idempotency `client_id` закрыта уникальным индексом (`2026_11_19_100000_create_chat_tables.php:66`); tool/read authorization в `ChatService`.
- **LMS:** уникальные enrolments/progress/submissions (`2026_11_21_010000_create_lms_tables.php:57,70,111,141,188`).

## 4. Порядок исправлений

1. **P0:** BL-01, BL-05, BL-08, BL-09, BL-13, BL-17, BL-18 — QR/однопоточность, workflow, payroll, taбель, задачи.
2. **P1:** BL-02…BL-04, BL-06, BL-10…BL-12, BL-14…BL-16, BL-19…BL-21.
3. **P2:** гипотезы H-BL-01…H-BL-06 после concurrency-тестов.
