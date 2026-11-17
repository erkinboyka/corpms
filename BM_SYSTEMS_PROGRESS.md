# BM SYSTEMS — PROGRESS

Автономная сессия CTO: старт 17.11.2026.
Перед продолжением — перечитывать этот файл и `BM_SYSTEMS_ROADMAP.md`.

## Что исследовано

- Полный аудит от 16.11.2026 (`AUDIT_REPORT_2026-11-16.md`) сверен с фактическим кодом:
  отдельной проверкой подтверждён статус каждого P0-дефекта (10 из 10) по file:line.
- Выяснено, что часть спринта аудита уже выполнена предыдущей сессией:
  middleware `EnsureTenantIsolation`, company-фильтры в списках сотрудников/аудита/табеля/
  биометрии/шаблонов/RFID/дашборда/поиска, `TenantIsolationHttpTest` (10 тестов),
  `TenantListScopeHttpTest` (10 тестов).
- Базовая линия тестов: **242 теста, 3 падения** (некорректный URL `/attendance/live`,
  неверный порядок имени, отсутствие `raw_payload` в фикстуре).

## Что сделано в этой сессии

### 1. Тестовая база приведена в зелёное состояние
- `tests/Feature/TenantListScopeHttpTest.php`: `/attendance/live` → `/attendance/live/feed`,
  корректный формат ФИО, `raw_payload` в фикстурах BiometricEvent (3 падения закрыты).

### 2. S4 — секреты не попадают в audit_logs
- `app/Http/Controllers/Web/SettingsController.php` — `maskSecret()` для `type=secret`.
- `app/Domains/Audit/Services/AuditService.php` — рекурсивная маскировка чувствительных
  ключей (`password|secret|token|api_key|private_key|credential|otp`).
- `database/migrations/2026_11_16_215115_mask_secret_values_in_audit_logs.php` — очистка
  уже записанных значений (применена: `php artisan migrate`).
- `tests/Feature/AuditSecretMaskingTest.php` — 4 теста (запись, пустые значения,
  HTTP-сценарий настройки, отсутствие ключа на странице `/audit`).

### 3. S5 — rate limiting
- `routes/web.php`: `/ai` GET 30/мин, `/ai` POST и `/ai/message` 15/мин,
  `/profile/ai-fill` 10/мин, `/search` 60/мин, `/search/hint` 120/мин.
- `routes/device.php`: Telegram webhook 60/мин.

### 4. S7 — ложный статус AI и фишинг-ссылки
- `app/Http/Controllers/Web/IntegrationController.php`: статус AI по фактической
  конфигурации `corpms.ai.base_url`/`corpms.ai.api_key` (как видит `AiAssistantService`).
- `app/Support/HtmlSanitizer.php`: `safeHref` отклоняет протокол-относительный `//host`
  и экранированный `/\host` (браузер нормализует `\` → `/`).
- `tests/Unit/HtmlSanitizerTest.php` — 4 теста (фишинг-URL, опасные схемы, безопасные
  ссылки, событийные обработчики).

### 5. S8 — фильтр уволенных и документы
- `resources/views/employees/index.blade.php`: option `fired` → `dismissed`.
- `app/Http/Controllers/Web/DocumentController.php`: `paginate(25)->withQueryString()`,
  поиск по `title/document_number/reg_number`, метрики по всем записям scope
  (считаются до фильтра статуса).
- `resources/views/documents/index.blade.php`: поиск в тулбаре, метрики из контроллера,
  пагинация `pagination::tabler`, счётчик «всего/показано».
- Тесты: `HrAndTasksHttpTest::test_employees_status_filter_includes_dismissed`,
  `DocumentsHttpTest::test_documents_index_paginates_searches_and_counts_metrics_over_all_rows`.

### 6. Остатки P0 закрыты (запись через тело запроса + списки)
- `app/Http/Controllers/Web/TaskController.php`:
  - company-база в `index` и `overdue`;
  - `assertEmployeesInCompany` / `assertDepartmentsInCompany` в `store`, `bulkCreate`,
    `createSubtask`, `delegate` (422 с ошибкой валидации);
  - делегирование: задание обязано принадлежать задаче из маршрута (404).
- `app/Domains/Tasks/Services/TaskService.php`:
  - `assignToDepartment` / `assignToEmployee` сверяют компанию с компанией задачи (403);
  - `nextNumber(companyId)` скоуплен по компании + цикл защиты от коллизии.
- `app/Http/Controllers/Web/BiometricController.php`: `enroll` — `TenantGuard` для
  сотрудника и устройства из тела запроса (403).
- `app/Http/Controllers/Web/DepartmentController.php`:
  - `index`/`orgChart` — company-скоуп;
  - `store`/`update` — `ensureReference` для `parent_id`/`director_id`/`deputy_id` (403).
- `app/Http/Controllers/Web/CompanyController.php`:
  - `positionsIndex` — company-скоуп;
  - `index` компаний — целиком только `super_admin`, остальные — своя компания.

### 7. Регресс-тесты
- `tests/Feature/TenantIsolationHttpTest.php`: +7 тестов (назначение чужому сотруднику/
  отделу, bulk, подзадача, делегирование + подмена задания, нумерация задач,
  enroll биометрии, ссылки отделов).
- `tests/Feature/TenantListScopeHttpTest.php`: +2 теста (списки задач/overdue,
  отделы/орг-чарт/должности).

### 8. S6 — восстановление пароля + парольная политика + CSP
- `app/Http/Controllers/Auth/PasswordResetController.php` (новый): `forgotForm`/`sendResetLink`
  (единый ответ «Ссылка отправлена» без утечки существования учётки), `resetForm`/`reset`
  (валидация токена, `Password::PASSWORD_RESET` → автологин + регенерация сессии, иначе
  ошибка в модалке/форме), аудит `auth.password_reset_self`.
- `resources/views/auth/forgot-password.blade.php`, `reset-password.blade.php` (новые).
- `routes/web.php`: группа `guest` — `password.request/email/reset/update`, POST-маршруты
  с `throttle:10,1`.
- `resources/views/auth/login.blade.php`: ссылка «Забыли пароль?».
- `app/Http/Controllers/Web/UserController.php`: политика `Password::min(12)` в
  `changePassword`, `resetPassword`, `store` (регрессия: старые тесты с 10-символьными
  паролями обновлены).
- `app/Http/Middleware/SecurityHeaders.php`: `Content-Security-Policy` (с временным
  `unsafe-inline` для script/style — нет nonce-инфраструктуры, зафиксировано в ограничениях).
- `.env`: `SESSION_SECURE_COOKIE=false` (локальный HTTP); `.env.example` — комментарий
  «prod: true».
- `tests/Feature/PasswordResetFlowTest.php` (новый): 7 тестов — страница, неутечка
  существования учётки, полный цикл (форма → слабый пароль отклонён → сброс → автологин →
  одноразовость токена), неверный токен, гостевой редирект, политика 12+ на `/profile/password`,
  throttle 10/мин.
- `tests/Feature/TenantIsolationHttpTest.php`: пароль в `test_password_reset_cross_tenant_blocked`
  обновлён до 12+ символов (новая политика).

### 9. S9 — ошибки валидации в модалках + `old()` + `confirm()` (P2)
- `resources/views/layouts/tabler.blade.php`: скрипт `data-crm-validation-errors` — при
  `$errors->any()` модалка-отправитель переоткрывается автоматически, поля подсвечиваются
  `is-invalid` + `invalid-feedback`, сводка ошибок выводится сверху (раньше после редиректа
  форма закрывалась и ошибку не было видно).
- `public/assets/js/corpms.js`: маркер `sessionStorage['crm-invalid-modal']` (id модалки)
  записывается в `initSubmitGuard`, очищается в `initAll` — скрипт знает, какую модалку открыть.
- `confirm()` добавлен: «Выплатить/Согласовать ведомость» (`payroll/period.blade.php`),
  «В архив» (`documents/show.blade.php`), «Сбросить пароль» (`admin/users.blade.php`).
- `old()` добавлен во все формы: employees, departments, companies, documents, tasks,
  admin-users — **69 мест** (критерий ≥60 выполнен).
- `tests/Feature/Web/ValidationErrorsUiTest.php` (новый): 5 тестов — рендер скрипта
  переоткрытия + сохранение `old()`, отсутствие маркера на страницах без ошибок,
  `confirm()` на согласовании/выплате ведомости, архивации документа, сбросе пароля.

### 10. B2 — нумерация документов с учётом soft-delete (P1)
- `app/Domains/Documents/Services/DocumentService.php`:
  - `nextNumber()`: `count()` **с `withTrashed()`** (раньше после soft-delete номер
    совпадал с unique-индексом `documents.document_number` → падение создания) +
    do/while-цикл «первый свободный номер» (защита от коллизии при гонке/пропусках).
  - `nextRegNumber()`: то же для `reg_number` (company-скоуп, `like 'Р-ГГГГ-%'`),
    формат `Р-ГГГГ-NNNNNN` сохранён (тесты его проверяют).
- `tests/Feature/Web/DocumentsHttpTest.php`: +2 теста — 250 документов + soft-delete
  (последний и средний) → два создания подряд дают уникальные `Д-…-000251/000252`,
  все номера уникальны `withTrashed`; `reg_number` не переиспользуется после
  удаления зарегистрированного документа (`Р-…-000002`, не `000001`).

### 11. B3 — валидатор `type` документов по enum `DocumentType` (P1)
- `app/Http/Controllers/Web/DocumentController.php`: `store()` — вместо
  `in:internal,order,contract,incoming,outgoing` (значения `order`/`contract`
  отсутствовали в enum и в enum-колонке БД → падение на касте/записи) теперь
  `Rule::enum(DocumentType::class)`; импорты `DocumentType`, `Rule`.
- `tests/Feature/Web/DocumentsHttpTest.php`:
  `test_store_rejects_type_outside_document_type_enum` — `type=contract` → ошибки
  валидации `type`, документ не создан; `type=outgoing` → документ создан, каст
  в `DocumentType::Outgoing`.

### 12. B5 — N+1 в CSV-экспорте + дубли счётчиков уведомлений (P1)
- `app/Http/Controllers/Web/ReportController.php`:
  - `csvAttendance()`: вместо агрегат-запроса **на каждого сотрудника** — одна
    группировка `groupBy('employee_id')` по всем ids (было 1 запрос/строку);
  - `csvTasks()`: вместо **4 count-запросов на каждого** — одна группировка по
    `status, employee_id` (было 4N запросов);
  - alias `over` → `over_sum` (`over` — зарезервированное слово оконных функций).
- `resources/views/layouts/tabler.blade.php`: счётчик непрочитанных уведомлений
  считается **один раз** до включения partials и передаётся в них.
- `resources/views/partials/navbar.blade.php`: `$bellUnread ??=` — переиспользует
  счётчик из layout вместо повторного запроса.
- `resources/views/partials/nav-actions.blade.php`: `$navUnreadBell = $bellUnread ?? …` —
  оба включения partial (navbar + sidebar) переиспользуют общий счётчик
  (раньше 3 count-запроса на страницу → теперь 1).
- `tests/Feature/Web/AnalyticsAndSystemHttpTest.php`: +2 теста —
  `test_csv_export_avoids_n_plus_one_queries` (30 сотрудников: экспорт attendance/tasks
  ≤20 запросов + все 30 строк в CSV), `test_unread_notifications_count_query_runs_once_per_page`
  (≤1 count-запроса `app_notifications` на `/dashboard`).

### 13. S10 — история диалога в LLM-контексте + честный `grounded` (P3)
- `app/Domains/Ai/Services/AiAssistantService.php`:
  - `historyMessages($asker, 6)` — последние реплики диалога (question/answer) в
    хронологическом порядке; в `llmAnswer()` вставляются перед текущим вопросом
    (обрезка `array_slice($history, -12)` — лимит контекста);
  - `grounded` теперь **честный**: `true` только когда ответил LLM, иначе
    локальный фолбэк → `false` (раньше всегда `true`); аудит `llm` = факт
    использования LLM, а не факт его настройки.
- `app/Domains/Ai/Services/AiPanelService.php`: та же история в `llmAnswerPanel()`;
  `build()` возвращает `grounded`, `persist()` сохраняет его в `ai_conversations`;
  `respond()` при сбое отдаёт `grounded=false`.
- `tests/Feature/Web/AiAndLiveFeedHttpTest.php`:
  - обновлён `test_ai_answers_attendance_question_from_facts` (фолбэк → `grounded=false`);
  - `test_second_llm_question_includes_dialogue_history_in_context` — второй запрос
    LLM содержит первую реплику, первый ответ и текущий вопрос в правильном порядке;
  - `test_grounded_is_false_when_llm_fails_over_to_local_fallback` — HTTP 500 от LLM
    → фолбэк, `grounded=false`;
  - `test_panel_message_grounded_flag_reflects_llm_or_fallback` — панель: без LLM
    `false`, с LLM `true`.

### 14. C3 — палитра команд Ctrl+K + поиск на 360px (P2)
- `resources/views/layouts/tabler.blade.php`: блок `#crm-palette` (dialog, input,
  список, футер с подсказками хоткеев); команды считаются **один раз** в layout
  (`Sidebar::build` переиспользуется и в сайдбаре — без дублирования запросов прав);
  `data-search-url` заполняется только при праве `search.global`; версии
  `effects.css?v43`, `corpms.js?v37`.
- `resources/views/partials/sidebar.blade.php`: использует `$navItems` из layout,
  если он передан (иначе строит сам — обратная совместимость).
- `resources/views/partials/nav-actions.blade.php`: кнопка `data-crm-palette-open`
  (иконка ti-command) — доступна в мобильном сайдбаре, где топбар с поиском скрыт
  (критерий «поиск доступен на 360px»: из палитры работает быстрый поиск `/search`).
- `public/assets/js/corpms.js`: `initCommandPalette()` — Ctrl+K/Cmd+K (toggle),
  фильтрация по названию/секции, пункт «Найти: …» → `/search?q=` (если право есть),
  ↑↓/Enter/Escape, клик по фону закрывает, блокировка скролла body.
- `public/assets/css/effects.css`: стили `.crm-palette*` (оверлей, диалог, пункты,
  активный пункт, футер); ≤575px — меты скрыты, футер переносится.
- `tests/Feature/Web/CommandPaletteTest.php` (новый): 4 теста — разметка+кнопка
  открытия, permission-фильтр команд (super_admin видит «Настройки», сотрудник —
  нет), `data-search-url` только при `search.global`, валидность JSON команд.

### 15. C4 — Kanban для тач-устройств: меню «переместить в колонку» (P2)
- `resources/views/tasks/index.blade.php`: на каждой карточке кнопка
  `data-kanban-move` (ti-arrows-move, `aria-expanded`) + скрытое меню
  `.crm-kanban__menu` со всеми колонками **кроме текущей** (`@continue`);
  подсказка под доской больше не скрыта на мобильных и упоминает меню.
- `public/assets/js/corpms.js`:
  - вынесен общий `changeStatus(card, target)` — мгновенный перенос UI + фоновый
    `PUT /tasks/{id}/status` с откатом и тостом; используется и drag&drop (`drop`),
    и меню переноса (код не дублируется);
  - обработчики клика по кнопке/пункту меню, закрытие по Escape и клику вне;
  - `positionMenu()` — при нехватке места снизу (overflow доски/viewport) меню
    переворачивается вверх, а если и сверху не влезло — возвращается (доска скроллится).
- `public/assets/css/effects.css`: `.crm-kanban__card { position: relative }`,
  кнопка (на `hover: none` всегда заметна), стили меню-выпадашки; `k-title`
  с правым отступом под кнопку; версии `effects.css?v44`, `corpms.js?v38`.
- `tests/Feature/Web/HrAndTasksHttpTest.php`: +2 теста — карточка рендерит кнопку
  и меню без текущей колонки (единственная задача: 7 пунктов `data-move-to`,
  `data-move-to="new"` отсутствует, подсказка про тач); JSON-контракт переноса
  (`X-Requested-With` → `{ok, status}`, невалидный статус → 422 с ошибкой поля).

### 16. C5 — скелетоны/`aria-busy` для AJAX-блоков (P2)
- `resources/views/partials/navbar.blade.php`: `#crm-search-panel` стартует с
  `aria-busy="false"` (JS ставит `true` на время fetch подсказок).
- `resources/views/partials/ai-panel.blade.php`: `#ai-messages` — `aria-busy="false"`
  (переключается `setBusy()`); `#ai-typing` получил `role="status"` (live-регион
  для скринридеров).
- `public/assets/js/corpms.js`:
  - живой поиск: пока ответ не пришёл, панель открыта с индикатором
    «Ищем…» (`aria-busy=true` + `.crm-search-loading` со спиннером), а не пустая;
    счётчик `seq` отбрасывает устаревшие ответы и ответы после закрытия панели;
  - kanban: `aria-busy` на карточку на время `PUT /tasks/{id}/status` (снимается
    после успеха/отката), CSS блокирует повторные клики (`pointer-events: none`).
- `public/assets/js/ai-panel.js`: `setBusy()` дополнительно ставит/снимает
  `aria-busy` на потоке сообщений.
- `public/assets/css/effects.css`: `.crm-search-loading`, `.crm-spinner`
  (с учётом `prefers-reduced-motion`), `.crm-kanban__card[aria-busy="true"]`;
  версии `effects.css?v45`, `corpms.js?v39`, `ai-panel.js?v2`.
- `tests/Feature/Web/AjaxLoadingStatesTest.php` (новый): 3 теста — стартовый
  `aria-busy="false"` у панели поиска и потока AI-сообщений, `role="status"` у
  индикатора «печатает», хуки busy-состояния на странице Kanban.

### 17. A7 — учёт scope в `EnsurePermission` (P0/P1, фаза A закрыта)
- `app/Http/Middleware/EnsurePermission.php`: поддержка суффикса
  `permission:slug@scope` (`own|department|company`) — через `checkScope()`
  (право есть **не уже** указанного scope); без суффикса — прежний `check()`
  (обратная совместимость); невалидный scope → 500 (fail-closed, не тихий пропуск).
- `routes/web.php`: `@company` на company-глобальных маршрутах —
  `settings.view`, `settings.manage`, `audit.view`, `payroll.manage`
  (5 маршрутов), `payroll.approve` (approve/pay). Все носители этих прав в
  матрице — company/super, поэтому для легитимных ролей ничего не изменилось,
  а узкие override'ы (own/department) теперь отклоняются на gate.
  Оставлены без суффикса права с осознанными узкими сценариями в контроллерах:
  `leaves.approve` (own-согласование своих заявок), `attendance.corrections.approve`
  (own-согласование корректировок), `attendance.timesheet.export` (own-экспорт
  табеля сотрудником).
- `tests/Feature/PermissionScopeGateTest.php` (новый): 7 тестов — department/own
  grant → 403 на `settings.manage@company`, company-grant → проходит, super_admin →
  200, маршрут без суффикса пускает own (обратная совместимость), невалидный
  scope → 500, `payroll.manage@company` отклоняет узкий override.

### 18. D3 — очередь AI-вызовов: деградация вместо 30-секундного блока (P1/P3)
- `config/corpms.php`: секция `ai` получила `sync_timeout`
  (`env('AI_SYNC_TIMEOUT', 8)`) — потолок синхронного ожидания LLM, сек;
  0 — без потолка; в фоновом job не применяется.
- `app/Domains/Ai/Services/AiAssistantService.php`:
  - `syncTimeout(int)` — `min(настроенный таймаут, потолок)`; применяется в
    `llmAnswer()` при синхронном вызове (`$background = false`), фоновый
    повтор ждёт полный `corpms.ai.timeout`;
  - `historyFor(..., ?int $excludeId)` / `historyMessages(..., ?int $excludeId)` —
    повтор исключает текущий диалог из истории, чтобы его фолбэк-ответ
    не попал в контекст повторного вызова;
  - `queueRetry()` — ставит `RetryAiAnswer` в очередь, только если LLM
    настроен (`llmEnabled()`), ошибки диспатча не ломают ответ;
  - `retryLlm()` — публичный повтор: свежие факты + история без текущего
    диалога → `retryLlmUpdate()` → обновляет `answer`, `grounded`, пишет
    аудит `ai.retried`;
  - `ask()` — при `llmEnabled() && !usedLlm` после сохранения фолбэка
    ставит повтор в очередь (пользователь сразу получает ответ).
- `app/Domains/Ai/Services/AiPanelService.php`: `llmAnswerPanel()` получил
  потолок (`syncTimeout`) и `$background`; override `retryLlmUpdate()` —
  LLM-повтор с разбором fenced-json блоков (текст без json в `answer`);
  блоки в `query_used` добавляются только если локальных блоков не было
  (grounded-приоритет, как в `build()`); `persist()` ставит повтор
  (`panel: true`) при `llmEnabled() && !grounded`.
- `app/Domains/Ai/Jobs/RetryAiAnswer.php` (новый): `ShouldQueue`,
  `tries=3`, `backoff=[15, 60]`; пропускает диалог, если он уже
  `grounded` или удалён; при сбое бросает исключение → повтор с backoff;
  `panel`-флаг выбирает `AiPanelService` вместо `AiAssistantService`.
- `tests/Feature/Web/AiRetryQueueTest.php` (новый): 7 тестов — фолбэк +
  `Queue::assertPushed` (чат: `panel=false`, панель: `panel=true`), без LLM
  — очередь пуста, job обновляет `answer`/`grounded` и аудит (история
  повтора содержит старую реплику, но не фолбэк-ответ), skip при
  `grounded=true` (без HTTP), панельный retry парсит json-блоки и чистит
  текст, `syncTimeout()` капает 30→8, не поднимает 5 и не ограничивает
  при `cap=0`.

### 19. D4 — RAG: embeddings + векторный поиск по документам (P3)
- `database/migrations/2026_11_17_073209_create_document_chunks_table.php` (новый):
  таблица `document_chunks` — `company_id`/`document_id` (cascade), `chunk_index`,
  `content`, `embedding` json nullable (null = провайдер недоступен → keyword-режим),
  индекс `(company_id, document_id)`.
- `app/Models/DocumentChunk.php` (новый) + `Document::chunks()` (HasMany).
- `app/Domains/Ai/Services/DocumentRagService.php` (новый) — ядро RAG:
  - индексация: `indexDocument()` (чанкинг 1200/150 символов по словам с перекрытием,
    текст = title + номера + body без HTML), `indexMissing()` — self-healing
    (до 10 неиндексированных документов за вызов), `reindexAll()` — массовая
    переиндексация (`chunkById(50)`);
  - embeddings: POST `{base_url}/embeddings` с моделью `corpms.ai.embeddings_model`
    (`env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small')`), потолок ожидания —
    тот же `corpms.ai.sync_timeout`; ошибки/несовпадение числа векторов → null
    (все чанки без вектора); порядок векторов восстанавливается по `index`;
  - поиск `retrieve($asker, $query, $limit=3)`: право `documents.view` есть →
    сам-хилинг → scope через `AnalyticsService::accessibleEmployees()`
    (own/department/company — та же логика, что в `intentSearch` панели,
    с явной группировкой `OR`), косинусное сходство в PHP, при недоступности
    embeddings — keyword-сопоставление токенов (≥3 символов); не более одного
    фрагмента на документ, топ-N по score;
  - права: без `documents.view` → `[]`; scope own/department фильтрует по
    `author_id`/`signature_requests.signer_id` доступных сотрудников.
- `config/corpms.php`: `ai.embeddings_model`.
- `app/Console/Commands/IndexAiDocuments.php` (новый): `ai:index-documents`
  (`--company=` — ограничить, неверная компания → exit code INVALID).
- `app/Domains/Ai/Services/AiAssistantService.php`: `DocumentRagService` в
  constructor; `ragContext()` (guarded на `llmEnabled()`, сбои → `[]`),
  `ragSystemMessage()` — блок «Релевантные фрагменты документов» в системном
  промпте; `llmAnswer(..., $background, $rag)`; вызовы в `ask()` и
  `retryLlmUpdate()`.
- `app/Domains/Ai/Services/AiPanelService.php`: `ragContext` в `build()`,
  `llmAnswerPanel(..., $background, $rag)`, rag в `retryLlmUpdate()`.
- `tests/Feature/Web/AiRagRetrievalTest.php` (новый): 6 тестов — индекс без
  провайдера (embedding=null + keyword-поиск), artisan `ai:index-documents`
  (exit 0, чанки, неверная компания → 2), векторный поиск (fake `/embeddings`:
  «бюджет»→[1,0], иначе [0,1]; cosine=0 отсечён, остаётся только совпадение),
  keyword-деградация при 500 от провайдера, scope (worker `own` не видит
  чужой документ — `retrieve() === []`, admin company — видит), POST /ai →
  системный промпт содержит «Релевантные фрагменты документов» + title.

### 20. D5 — Tool calling: контролируемые backend-инструменты (P3)
- `app/Domains/Ai/Services/AiToolRegistry.php` (новый) — белый список
  инструментов для LLM:
  - инструменты: `search_documents` (documents.view), `search_tasks`
    (tasks.view), `attendance_summary` (attendance.view), `task_stats`
    (analytics.view), `create_task` (tasks.create, **критический**);
  - `toolsFor($asker)` — в формате OpenAI tools, только инструменты, на
    которые у вызывающего есть право (отсутствующее право → не предлагается);
  - `execute($asker, $name, $args)` — конфиг kill-switch → валидация схемы
    (обязательные поля `missing:`, посторонние ключи `unexpected:`, типы
    `type:`, длины `length:`, до 500 символов) → права → обработчик в try/catch;
  - аудит каждого исхода в `audit_logs`: `ai.tool.called` (успех),
    `ai.tool.denied` (нет прав), `ai.tool.invalid` (схема/неизвестный/сбой);
  - обработчики со scope: поиск документов/задач — та же логика company+scope,
    что в `intentSearch` (с явной группировкой OR); сводки — через
    `accessibleEmployees()`;
  - `create_task` — **критическая операция без записи в БД**: возвращает
    `needs_confirmation + draft + confirm_url` (`/tasks/mine#new-task`),
    пользователь подтверждает в существующем UI.
- `config/corpms.php`: `ai.tools` (`env AI_TOOLS`, default true) — kill-switch.
- `app/Domains/Ai/Services/AiAssistantService.php`: `AiToolRegistry` в
  constructor; новый `postWithTools(messages, base, key, model, timeout, asker)`
  — POST c `tools`/`tool_choice=auto`, **один раунд** tool_calls → `execute()`
  каждого вызова → сообщения `assistant(tool_calls)` + `role=tool` с JSON
  результата → второй запрос **без tools** (защита от зацикливания);
  `llmAnswer(..., $rag, $asker)` использует его; вызовы в `ask()` и
  `retryLlmUpdate()` передают сотрудника.
- `app/Domains/Ai/Services/AiPanelService.php`: `llmAnswerPanel(..., $rag,
  $asker)` также переведён на `postWithTools` (HTTP-код панели заменён общим
  методом); неиспользуемый import `Http` удалён; вызовы в `build()` и
  `retryLlmUpdate()` передают сотрудника.
- `tests/Feature/Web/AiToolCallingTest.php` (новый): 9 тестов — фильтрация
  инструментов по правам (admin видит create_task/task_stats, worker — нет),
  kill-switch `AI_TOOLS=false` → `toolsFor()===[]` и `error=disabled`,
  поиск документов → deep-link + аудит `ai.tool.called`, scope (worker own →
  чужой документ `count=0`), валидация (missing/unexpected/type/unknown →
  4 записи `ai.tool.invalid`), отказ по правам (worker
  `create_task` → `forbidden`, 0 задач, аудит `ai.tool.denied`), критическая
  операция (admin `create_task` → `needs_confirmation`, **0 записей в tasks**),
  интеграция POST /ai (первый запрос содержит `tools`, второй — `role=tool`
  с результатом поиска, grounded=true, аудит), фолбэк при 500 от LLM
  (Queue::fake, grounded=false, локальный ответ).

### 21. D6 — AI Memory, AI Insights, сводный отчёт, Doc Generator (P3)

- **AI Memory (долговременная память ассистента):**
  - миграция `2026_11_17_194313_create_ai_memories_table` (**применена**):
    `employee_id` FK cascade, `memory_key` (64), `memory_value` (500), `source`,
    unique(employee_id, memory_key);
  - `app/Models/AiMemory.php` (новый) + `app/Domains/Ai/Services/AiMemoryService.php`
    (новый): `remember()` — upsert + вытеснение старейших (MAX_MEMORIES=50),
    `recall()`, `forget()`, `contextBlock()` (CONTEXT_LIMIT=20 записей в промпт),
    `normalizeKey()`; все операции в try/catch — память не ломает ответ;
  - `AiAssistantService`: 6-я зависимость `AiMemoryService`, метод
    `memoryContext(?Employee)`, блок «Память о сотруднике» в системном
    промпте LLM (`llmAnswer`);
  - команды в панели (`intentMemory`, работает на оригинальном вопросе —
    регистр значений сохраняется): «запомни: ключ — значение» / «запомни …»
    (сплит по `—`/`:`/`=`), «забудь …», «что помнишь?» / «покажи память»;
  - изоляция: память привязана к сотруднику, чужие записи не видны и не
    удаляются; пишется **только по явной команде** (автосохранения нет);
  - флаг `local_only`: детерминированные команды не переопределяются
    LLM-ответом и не ставят фоновый повтор `RetryAiAnswer`;
  - `AiPanelService::llmAnswerPanel` также получает `memoryContext($asker)`.
- **AI Insights («на что обратить внимание»):** `intentInsights` — ключи
  «инсайт/на что обратить/что не так/аномал/сводка дня/порекомендуй»;
  сигналы: просроченные/жищущие проверки задачи, опоздавшие/отсутствующие,
  заявки на отпуск без решения, own-scope «нет отметки прихода»; ответ —
  блок `text` (нумерованный список) + `actions` с проверкой прав;
  «спокойный» ответ, когда отклонений нет. `local_only`.
- **Сводный отчёт:** `intentSummaryReport` — ключи «сводный/общий отчёт,
  сводка по компании, полная сводка»; chart (ключевые показатели) + table +
  actions (`reports.index`, `reports.hr`, `analytics.index` при правах);
  при scope own — вежливый отказ. `local_only`.
- **Doc Generator:** `intentDocGenerator` — «сформируй справку/приказ/
  служебную записку» → детерминированный черновик из фактов как блок
  `type=text` + deep-link `documents.templates` (право
  `documents.templates.view`) / `documents.index`; **без записи в БД**
  (консистентно с D5). `local_only`.
- **Контракт панели:** новый блок `type=text` — `renderText()` в
  `ai-panel.js` (`<pre class="ai-text">`, escapeHtml, pre-wrap), стиль
  `.ai-text` в `ai-panel.css`; `sanitizeBlocks` принимает `text` (strip HTML,
  ≤4000 символов, заголовок ≤80); 3 новых чипа-подсказки в
  `partials/ai-panel.blade.php` («Инсайты», «Сводный отчёт», «Запомнить»).
- **Исправлен существующий баг URL:** `route()` возвращает абсолютный URL
  (`http://corpms.local/...`), а `ai-panel.js` рендерит только пути с `/` —
  хелпер `action()` теперь нормализует через `relativeUrl()` (path + query +
  fragment). Ранее кнопки-ссылки в блоках `actions` могли не отображаться.
- `app/Domains/Ai/Services/AiPanelService.php`: цепочка intent-ов дополнена
  `intentMemory → … → intentDocGenerator → … → intentInsights →
  intentSummaryReport → navigate`; `build()` возвращает `local_only`;
  `persist()` не делает `queueRetry` при `local_only`.
- `tests/Feature/Web/AiMemoryInsightsTest.php` (новый): **9 тестов, 51
  assertion** — remember/recall/forget через панель, изоляция между
  сотрудниками, memory-команды без LLM/ретрая (`Http::assertNothingSent` +
  `Queue::assertNothingPushed`), память в system-промпте LLM
  (`Http::assertSent`), insights own-scope (просроченная задача + нет
  отметки, `local_only`), insights «спокойный» ответ (созданы present-записи
  обоим сотрудникам), сводный отчёт (chart+table+actions `/reports`),
  docgen без записи в documents (0 строк), санитизация LLM `text`-блока
  (нет `<`, ≤4000, actions с внешним URL отброшен).

## Проверки

| Проверка | Результат |
|---|---|
| `php artisan test --compact` (после всех правок и Pint) | **329/329, 1698 assertions** |
| `vendor/bin/pint --format agent` (весь проект) | выполнено, стилизация применена |
| `php artisan migrate` | миграции маскировки, `document_chunks` и `ai_memories` применены |

## Изменённые файлы (сессия 17.11.2026)

```
app/Domains/Audit/Services/AuditService.php
app/Domains/Ai/Jobs/RetryAiAnswer.php (новый)
app/Domains/Ai/Services/AiAssistantService.php
app/Domains/Ai/Services/AiMemoryService.php (новый)
app/Domains/Ai/Services/AiPanelService.php
app/Domains/Ai/Services/AiToolRegistry.php (новый)
app/Domains/Ai/Services/DocumentRagService.php (новый)
app/Console/Commands/IndexAiDocuments.php (новый)
app/Models/AiMemory.php (новый)
app/Models/DocumentChunk.php (новый)
app/Models/Document.php
app/Domains/Documents/Services/DocumentService.php
app/Domains/Tasks/Services/TaskService.php
app/Http/Controllers/Auth/PasswordResetController.php (новый)
app/Http/Controllers/Web/BiometricController.php
app/Http/Controllers/Web/CompanyController.php
app/Http/Controllers/Web/DepartmentController.php
app/Http/Controllers/Web/DocumentController.php
app/Http/Controllers/Web/IntegrationController.php
app/Http/Controllers/Web/ReportController.php
app/Http/Controllers/Web/SettingsController.php
app/Http/Controllers/Web/TaskController.php
app/Http/Controllers/Web/UserController.php
app/Http/Middleware/EnsurePermission.php
app/Http/Middleware/SecurityHeaders.php
app/Support/HtmlSanitizer.php
config/corpms.php
database/migrations/2026_11_16_215115_mask_secret_values_in_audit_logs.php
database/migrations/2026_11_17_073209_create_document_chunks_table.php (новый)
database/migrations/2026_11_17_194313_create_ai_memories_table.php (новый)
resources/views/auth/forgot-password.blade.php (новый)
resources/views/auth/login.blade.php
resources/views/auth/reset-password.blade.php (новый)
resources/views/companies/index.blade.php
resources/views/departments/index.blade.php
resources/views/documents/index.blade.php
resources/views/documents/show.blade.php
resources/views/employees/index.blade.php
resources/views/layouts/tabler.blade.php
resources/views/partials/ai-panel.blade.php
resources/views/partials/nav-actions.blade.php
resources/views/partials/navbar.blade.php
resources/views/partials/sidebar.blade.php
resources/views/payroll/period.blade.php
resources/views/admin/users.blade.php
resources/views/tasks/index.blade.php
public/assets/css/ai-panel.css
public/assets/css/effects.css
public/assets/js/ai-panel.js
public/assets/js/corpms.js
routes/device.php
routes/web.php
tests/Feature/AuditSecretMaskingTest.php (новый)
tests/Feature/PasswordResetFlowTest.php (новый)
tests/Feature/PermissionScopeGateTest.php (новый)
tests/Feature/TenantIsolationHttpTest.php
tests/Feature/TenantListScopeHttpTest.php
tests/Feature/Web/AnalyticsAndSystemHttpTest.php
tests/Feature/Web/AiAndLiveFeedHttpTest.php
tests/Feature/Web/AiMemoryInsightsTest.php (новый)
tests/Feature/Web/AiRetryQueueTest.php (новый)
tests/Feature/Web/AiRagRetrievalTest.php (новый)
tests/Feature/Web/AiToolCallingTest.php (новый)
tests/Feature/Web/AjaxLoadingStatesTest.php (новый)
tests/Feature/Web/CommandPaletteTest.php (новый)
tests/Feature/Web/DocumentsHttpTest.php
tests/Feature/Web/HrAndTasksHttpTest.php
tests/Feature/Web/ValidationErrorsUiTest.php (новый)
tests/Unit/HtmlSanitizerTest.php (новый)
.env (SESSION_SECURE_COOKIE=false)
.env.example (комментарий prod)
BM_SYSTEMS_AUDIT.md (новый)
BM_SYSTEMS_ROADMAP.md (новый)
BM_SYSTEMS_PROGRESS.md (новый)
```

## Текущее состояние

- Фаза A (безопасность): **готова на 100%** — S1–S8 + A7 (scope в
  `EnsurePermission` через `permission:slug@scope`) выполнены.
- Фаза B (функциональные дефекты): **B2, B3, B5 выполнены** — нумерация документов
  с учётом soft-delete + защита от коллизии; валидатор `type` → enum `DocumentType`;
  N+1 в CSV-экспорте устранён (агрегаты одной группировкой), счётчик уведомлений
  считается один раз на страницу. Фаза B закрыта.
- Фаза C (UX): **S9, C3, C4, C5 выполнены** — скрипт переоткрытия модалок при
  ошибках, `old()` в 69 местах, `confirm()` на финансовых/необратимых действиях;
  палитра Ctrl+K с permission-фильтром и поиском; Kanban-перенос для тач-устройств
  (меню на карточке, DnD сохранён); скелетоны/`aria-busy` для AJAX-блоков
  (поиск, AI-панель, kanban). Фаза C закрыта.
- Фаза D (AI): **S10, D3, D4, D5, D6 выполнены** — история диалога в контексте LLM (оба вызова:
  `/ai` и `/ai/message`), честный `grounded`; синхронный вызов LLM ограничен
  потолком 8 с, при сбое — локальный фолбэк + фоновый повтор `RetryAiAnswer`
  в очереди (database); RAG — чанки документов + embeddings (`DocumentRagService`,
  keyword-деградация, права `documents.view` при поиске, artisan
  `ai:index-documents`); tool calling — белый список инструментов
  (`AiToolRegistry`: валидация аргументов, аудит вызовов, один раунд
  tool_calls в LLM-запросе, критические операции только через deep-link
  подтверждения — ИИ не пишет в БД); AI Memory (`AiMemoryService`, только
  явные команды, изоляция по сотруднику, память в промпте LLM), AI Insights
  и сводный отчёт (`local_only`, детерминированные), Doc Generator (черновик
  `text`-блоком без записи в БД), исправлен баг абсолютных URL в actions
  панели (`relativeUrl()`). **Фаза D закрыта.**
- Тесты: **329/329, 1698 assertions**.

## Следующая задача (высший приоритет)

**Фаза E — модули ТЗ: E3 Finance → E4 Accounting → E5 Projects** (E1 ERP и E2 CRM
закрыты 18.11.2026 — см. раздел ниже; дальше счета/платежи/бюджеты, план счетов и
проводки, проекты). Параллельно — инфраструктура: CI (Pint + тесты на коммит),
worker `queue:work` для фоновых AI-повторов и экспортов, Redis/мониторинг.
Git-репозиторий готов (первый коммит `97841f1`), CI ещё не настроен.

## Известные ограничения

- `.env`: живой AI-ключ, `APP_DEBUG=true`, БД root без пароля — не изменялось (секреты).
- Очередь настроена (`queue.default=database`, таблицы `jobs`/`failed_jobs` есть),
  но worker (`php artisan queue:work`) не запущен — фоновые AI-повторы
  (`RetryAiAnswer`) накапливаются в `jobs` и не выполняются, пока worker не
  поднят. В тестах `QUEUE_CONNECTION=sync` (повтор выполняется инлайн).
  CSV-экспорт по-прежнему синхронный.
- `settings` глобальные (не по компаниям) — осознанное ограничение до внедрения
  company-level settings.
- Tool calling (D5): ровно **один раунд** инструментов на запрос (защита от
  зацикливания и потолка sync_timeout) — цепочки из нескольких вызовов
  инструментов модель не строит; критические операции (`create_task`)
  возвращают только deep-link подтверждения, ИИ не пишет в БД по дизайну.
  Инструменты включаются/выключаются `AI_TOOLS=false`.
- RAG: индексация документов self-healing (до 10 документов за запрос) + команда
  `ai:index-documents`; если провайдер AI не поддерживает `/embeddings`
  (например, у части endpoint'ов Groq), чанки хранятся без векторов и поиск
  работает в keyword-режиме — это задокументированная деградация, не ошибка.
  Self-healing не выполняется для документов, созданных мимо сервиса, если
  чанки уже есть, но текст менялся — переиндексировать командой.

---

## Сессия 18.11.2026 — старт Фазы E (целевые модули ТЗ)

Предыдущая сессия оставила E1 в рабочем дереве без документации и без проверки
полного прогона. В этой сессии E1 доведён до критерия и закрыт.

### E1 ERP — выполнен

- **Модель данных:** `suppliers`, `products`, `warehouses`, `stock_movements`,
  `purchase_orders`, `purchase_order_items`; у каждой записи `company_id`,
  уникальность артикула/кода/номера — в пределах компании.
- **Складской движок** `App\Domains\Inventory\Services\InventoryService`:
  остаток = сумма неизменяемого журнала `stock_movements`; расход/списание
  проверяют достаточность остатка в транзакции и не уходят в минус;
  `receive()` создаёт приход по позициям и переводит заказ в `received`
  ровно один раз; `addItem()`/`removeItem()` пересчитывают сумму только в черновике.
- **Товары и услуги:** новая колонка `products.kind` (enum `ProductKind`:
  `goods`/`service`). Услуга не хранится на складе — движение по ней отклоняется
  (`LogicException` → ошибка формы), в остатках и в форме движений её нет,
  но в закупочный заказ она входит и в сумме учитывается, а при приёме не
  оприходуется.
- **Справочники:** создание, изменение карточки товара (цена/минимум/название,
  артикул и вид неизменны), включение/выключение без удаления — история движений
  сохраняется. Склады и поставщики: создание + включение/выключение.
- **Закупки:** создание с позициями, размещение, приём на склад, отмена,
  добавление/удаление позиции в черновике, фильтр по статусу и поиск по номеру.
- **Права:** `inventory.view/manage/move`, `purchases.view/manage` (группа
  «Склад и закупки»), scope `company`; выданы директору, заму, HR (view),
  директору отдела (view). Сайдбар — секция «Склад».
- **Изоляция:** company-скоуп в списках + `TenantGuard::ensureReference` для ID
  из тела (склад/товар/поставщик) + `EnsureTenantIsolation` для маршрутных моделей.
  Чужой товар нельзя ни изменить, ни добавить в свой заказ (403).
- **UI:** «Остатки» (KPI, низкие остатки, форма движения), «Товары и услуги»,
  «Склады», «Поставщики», «Движения», «Закупки» + карточка заказа.

### Проверки

| Проверка | Результат |
|---|---|
| `php artisan test --compact` | **340/340, 1844 assertions** |
| `tests/Feature/Web/InventoryHttpTest.php` | 11/11 (гейтинг, CRUD, минус остатка, услуги, статусы закупки, изоляция) |
| `vendor/bin/pint --dirty --format agent` | выполнено |
| `php artisan migrate` | `add_kind_to_products_table` применена |

### E2 CRM — выполнен

- **Модель данных:** `crm_clients`, `crm_contacts`, `crm_leads`, `crm_deals`,
  `crm_activities`; у каждой записи `company_id`, индексы по компании + статусу/стадии.
- **Домен** `App\Domains\Crm\Services\CrmService`: воронка (`board`), метрики
  (`metrics`, `clientMetrics`), конверсия лида (`convertLead` — атомарно клиент +
  сделка + статус), стадии (`moveDeal` — `won`/`lost` фиксируют `closed_at` и
  вероятность, возврат в работу снимает дату), активности (`completeActivity`,
  `reopenActivity`).
- **Клиенты:** карточка, контакты (основной контакт единственный), ответственный,
  ИНН/телефон/сайт/адрес, архив/возврат без удаления истории.
- **Лиды:** источник (сайт/звонок/рекомендация/мероприятие/другое), квалификация,
  потеря, конверсия в клиента и сделку; повторная конверсия и конверсия
  потерянного лида отклоняются (`LogicException` → ошибка формы).
- **Сделки:** канбан по 6 стадиям (новая → квалификация → предложение →
  переговоры → выиграна/проиграна), сумма, вероятность, срок закрытия,
  ответственный; смена стадии — отдельный POST (без JS), редактирование условий.
- **Активности:** звонок/встреча/письмо/задача/заметка, привязка к сделке, лиду
  или клиенту, срок, выполнение/возврат, вкладки «сегодня/предстоящие/
  просроченные/выполненные/все».
- **Права:** `crm.view`, `crm.manage`, `crm.deals.manage` (группа «CRM»);
  директор/зам — полный набор, директор отдела — просмотр. Сайдбар — секция «CRM».
- **Изоляция:** company-скоуп в списках, `EnsureTenantIsolation` для маршрутных
  моделей, `TenantGuard::ensureReference` для ID из тела (клиент, сделка, лид,
  ответственный). Чужие объекты — 403 на чтение и на изменение.
- **UI:** «Клиенты» + карточка клиента (контакты, сделки, активности, реквизиты),
  «Лиды», «Воронка сделок» (канбан), «Активности».

### Проверки (E2)

| Проверка | Результат |
|---|---|
| `php artisan test --compact` | **346/346, 1964 assertions** |
| `tests/Feature/Web/CrmHttpTest.php` | 6/6 (гейтинг, клиенты/контакты, лиды и конверсия, стадии, активности, изоляция) |
| `vendor/bin/pint --dirty --format agent` | выполнено |
| `php artisan migrate` + `db:seed --class=RbacSeeder` | `create_crm_tables` применена, права CRM выданы |

### Следующий шаг

**E3 Finance:** счета-фактуры/акты, платежи, доходы/расходы, бюджеты, касса —
по тому же каркасу (миграции + сервис + Blade-UI + feature-тесты + tenant-скоуп).


