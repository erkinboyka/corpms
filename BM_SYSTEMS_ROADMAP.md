# BM SYSTEMS — ROADMAP

Правила: задача закрыта только при выполнении критерия и зелёных тестах.
Приоритеты: P0 безопасность → P1 фундамент → P2 качество → P3 AI → P4 модули ТЗ.

Легенда: `[x]` готово · `[~]` в работе · `[ ]` не начато

---

## Фаза A — Безопасность и изоляция (P0/P1)

- [x] **S1** Обязательная точка изоляции: `EnsureTenantIsolation` (route-модели) — готово
- [x] **S2** Company-фильтры во всех списках (сотрудники, аудит, табель, биометрия, шаблоны,
      workflows, RFID, дашборд, **+ отделы, орг-чарт, должности, компании, задачи**) — готово
- [x] **S3** Кросс-тенантная запись (enroll, назначения задач, ссылки отделов, делегирование,
      подмена задания чужой задачи) — готово, 8 регресс-тестов
- [x] **S4** Маскировка секретов в audit_logs + очистка старых записей — готово
- [x] **S5** `throttle` на AI/поиск/ai-fill/Telegram webhook — готово
- [x] **S6** Восстановление пароля + `Password::min(12)` + `SESSION_SECURE_COOKIE` + CSP — готово
  - Критерий: `forgot-password` → письмо → сброс → вход; старый пароль отвергается; тесты —
    выполнено, `PasswordResetFlowTest` (7 тестов)
- [x] **S7** `IntegrationController` (фактическая конфигурация AI) + `safeHref` (`//`, `/\`) — готово
- [x] **A7** Учёт scope в `EnsurePermission`
  - Критерий: middleware умеет требовать минимальный scope (`permission:slug@scope`),
    узкий grant отклоняется, без суффикса — прежнее поведение — **выполнено**
    (`@company` на settings/audit/payroll-маршрутах, тесты `PermissionScopeGateTest` — 7/7)
      (anomalies.manage own-scope не должен разрешать чужие аномалии в компании)

## Фаза B — Функциональные дефекты (P1)

- [x] **S8a** Фильтр `fired` → `dismissed` — готово
- [x] **S8b** Пагинация + поиск документов, метрики по всем записям — готово
- [x] **B2** Нумерация документов: `withTrashed` + период + защита от коллизии
  - Критерий: 250 документов + soft-delete → номера уникальны; тест гонки/повтора — **выполнено**
    (`DocumentsHttpTest::test_document_numbering_survives_soft_delete_and_repeats`,
    `test_reg_number_not_reused_after_soft_delete`)
- [x] **B3** Валидатор `type` в `DocumentController::store` привести к enum `DocumentType`
  - Критерий: невалидный `type` отклоняется — **выполнено** (`Rule::enum(DocumentType::class)`,
    тест `test_store_rejects_type_outside_document_type_enum`)
- [x] **B5** N+1 в CSV-экспорте (`ReportController`), дубль-запросы уведомлений в partials
  - Критерий: нет запросов N+1 в экспорте, один счётчик уведомлений на страницу —
    **выполнено** (тесты `test_csv_export_avoids_n_plus_one_queries`,
    `test_unread_notifications_count_query_runs_once_per_page`)

## Фаза C — UX (P1/P2)

- [x] **S9** Ошибки валидации в модалках + `old()` во всех полях + `confirm()` на финансовых
  - Критерий: ошибка видна без закрытия формы; grep `old()` ≥ 60 мест; `confirm()` на
    «Выплатить», архивации, сбросе пароля — **выполнено** (69 `old()`, скрипт
    `data-crm-validation-errors`, тесты `ValidationErrorsUiTest`)
- [x] **C3** Ctrl+K палитра команд; поиск доступен на 360px
  - Критерий: хоткей открывает палитру с permission-фильтром разделов, поиск из палитры
    доступен на 360px — **выполнено** (`crm-palette` в layout, `initCommandPalette()`
    в corpms.js, кнопка в nav-actions, тесты `CommandPaletteTest` — 4/4)
- [x] **C4** Альтернатива Kanban для тач-устройств (меню «переместить в колонку»)
  - Критерий: на тач-устройстве карточку можно перенести без drag&drop, drag&пад
    десктопом сохраняется — **выполнено** (кнопка+меню на карточке, общий
    `changeStatus()` для DnD и меню, тесты `HrAndTasksHttpTest`:
    `test_kanban_card_renders_move_menu_without_current_status`,
    `test_kanban_move_menu_status_endpoint_supports_xhr_json`)
- [x] **C5** Скелетоны/`aria-busy` для AJAX-блоков; `confirm()` UX-D5
  - Критерий: AJAX-блоки показывают состояние загрузки; критические финансовые
    действия подтверждаются — **выполнено** (`confirm()` — в S9; aria-busy в
    живом поиске, AI-панели и kanban-переносе, тесты `AjaxLoadingStatesTest` — 3/3)

## Фаза D — AI (P1/P3)

- [x] **S10** История диалога в контексте LLM + честный `grounded`
  - Критерий: второй вопрос учитывает первый (тест с моком HTTP); `grounded=false` при фолбэке —
    **выполнено** (`historyMessages()` в обоих LLM-вызовах, `grounded = usedLlm`;
    тесты `test_second_llm_question_includes_dialogue_history_in_context`,
    `test_grounded_is_false_when_llm_fails_over_to_local_fallback`,
    `test_panel_message_grounded_flag_reflects_llm_or_fallback`)
- [x] **D3** Очередь AI-вызовов (деградация вместо 30-секундного блока)
  - Критерий: синхронный вызов LLM ограничен потолком (`corpms.ai.sync_timeout`,
    по умолчанию 8 с), при сбое пользователь сразу получает локальный ответ, а
    повтор уходит в очередь — **выполнено** (job `RetryAiAnswer` обновляет
    answer/grounded, тесты `AiRetryQueueTest` — 7/7)
- [x] **D4** RAG: embeddings + векторный поиск по документам компании (отдельно от SQL-аналитики,
      права доступа на документ применяются при поиске)
  - Критерий: ответы AI обогащаются релевантными фрагментами документов компании,
    права `documents.view` (own/department/company) применяются при поиске —
    **выполнено** (таблица `document_chunks`, `DocumentRagService` с
    keyword-деградацией при недоступности `/embeddings`, artisan
    `ai:index-documents`, тесты `AiRagRetrievalTest` — 6/6)
- [x] **D5** Tool calling: контролируемые backend-инструменты (белый список, валидация аргументов,
      журнал действий, подтверждение критических операций)
  - Критерий: LLM вызывает только разрешённые инструменты (права проверяются
    до показа инструмента и при исполнении), аргументы валидируются по схеме,
    каждый вызов/отказ пишется в audit_logs, критические операции не пишут
    в БД — **выполнено** (`AiToolRegistry`, один раунд tool_calls в
    `postWithTools`, kill-switch `AI_TOOLS`, тесты `AiToolCallingTest` — 9/9)
- [x] **D6** AI Memory (контекст между сессиями), AI Insights, AI Reports/Charts/Doc Generator
  - Критерий: AI помнит контекст пользователя между сессиями (только явные
    команды «запомни/забудь», изоляция по сотруднику, лимит 50 записей),
    инсайты и сводный отчёт считаются детерминированно по фактам с учётом
    прав (`local_only` — не подменяются LLM), генератор документов отдаёт
    черновик блоком `text` без записи в БД — **выполнено**
    (`AiMemoryService`, `intentMemory/Insights/SummaryReport/DocGenerator`,
    тесты `AiMemoryInsightsTest` — 9/9)

## Фаза E — Целевые модули ТЗ (P4)

Порядок: **ERP → CRM → Finance → Accounting → Projects**; каждый = миграции + доменный
сервис + Blade-UI + feature-тесты + tenant-скоуп с первого дня.

- [x] **E1 ERP:** склады, товары/услуги, остатки, приход/расход, поставщики, закупки
  - Критерий: остаток = сумма журнала движений, расход/списание не уходят в минус,
    услуги живут только в номенклатуре и не оприходуются, закупка `draft → ordered →
    received` приходуется ровно один раз, все справочники в company-скоупе —
    **выполнено** (`InventoryService`, `InventoryController`, `PurchaseController`,
    `ProductKind`, тесты `InventoryHttpTest` — 11/11)
- [x] **E2 CRM:** клиенты, контакты, лиды, сделки, воронка, активности
  - Критерий: клиент с контактами, лид конвертируется в клиента и сделку ровно
    один раз, сделка идёт по стадиям воронки и закрывается (`won`/`lost` фиксируют
    дату и вероятность), активности выполняются/возвращаются, всё в company-скоупе —
    **выполнено** (`CrmService`, `Crm{Client,Lead,Deal,Activity}Controller`,
    тесты `CrmHttpTest` — 6/6)
- [x] **E3 Finance:** счета-фактуры/акты, платежи, доходы/расходы, бюджеты, касса
  - Критерий: счёт `draft → issued → paid` (частичные платежи, переплата отклоняется),
    каждая оплата создаёт приход в журнале, баланс счёта = начальный остаток +
    доходы − расходы, бюджет сравнивается с фактом месяца, всё в company-скоупе —
    **выполнено** (`FinanceService`, `Fin{Account,Category,Transaction,Invoice,Budget}Controller`,
    тесты `FinanceHttpTest` — 6/6)
- [x] **E4 Accounting:** план счетов, проводки из Finance, первичные документы, баланс/ОПиУ
  - Критерий: проводка сбалансирована (дебет = кредит), в строке одна сторона;
    операция финансов даёт ровно одну проводку (повторный синк — без дублей);
    оборотка, ОПиУ и упрощённый баланс считаются по проводкам; всё в company-скоупе —
    **выполнено** (`AccountingService`, `Accounting{Chart,Entry,Document,Report}Controller`,
    тесты `AccountingHttpTest` — 6/6)
- [x] **E5 Projects:** проекты, бюджет, сроки, ресурсы, связь с задачами (Task ↔ Project)
  - Критерий: проект с бюджетом и сроками, участники с ролью и загрузкой, вехи,
    задачи привязываются/отвязываются (одна задача — один проект), прогресс =
    завершённые/все задачи, факт бюджета считается по расходным операциям финансов
    с `project_id`, всё в company-скоупе — **выполнено** (`ProjectService`,
    `Project{,Member,Milestone}Controller`, тесты `ProjectsHttpTest` — 7/7)
- [ ] **E6 BI:** витрины данных, срезы по периодам, кастомные дашборды, прогнозы
- [ ] **E7 AI Copilot:** интеграция с новыми модулями через tool calling (см. D5)

## Инфраструктура (рекомендовано до прод-эксплуатации)

- [~] Git-репозиторий готов (init + первый коммит `97841f1`), CI (Pint, тесты на изменение) — не настроен
- [x] **SESSION_SECURE_COOKIE**, CSP (в составе S6)
- [ ] Очереди/Redis для AI и экспортов
- [ ] Мониторинг/алерты (сейчас только `db:backup`, `markOverdue`, `detect-missing`)
