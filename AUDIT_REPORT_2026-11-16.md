# BM Systems / CorpMS — Независимый аудит

**Дата:** 16.11.2026
**Объект:** `C:\OSPanel\home\corpms.local` (Laravel 13.39, PHP 8.5.12, MySQL `corpmis`)
**Метод:** только чтение кода, миграций, маршрутов, БД и живой обход UI. Код не изменялся.
**Основание:** ТЗ на аудит, раздел 17 (формат отчёта), раздел 8 (оценки 0–5), раздел 18 (правила аудита).

> **Примечание о достоверности.** Исходный текст ТЗ в рабочей директории не сохранён, поэтому наименования 13 секций и 23 категорий оценок реконструированы по содержанию задачи. Оценки, доказательства и выводы привязаны к реальному коду; при расхождении формулировок с ТЗ замените только названия — содержание сохраняет смысл. Каждый существенный вывод подтверждён файлом и строкой. Утверждения, не подтверждённые кодом, в отчёт не включены.

**Источники:** личная верификация аудитора по коду; 4 параллельных глубинных разбора (AI, безопасность, frontend/UX, модель данных) с последующей перепроверкой ключевых тезисов; `php artisan test --compact`; `php artisan migrate:status`; живой обход страниц в браузере.

---

## 1. Executive Summary

CorpMS — это **зрелое ядро корпоративной HRM-системы** (оргструктура, кадры, графики, посещаемость, табель, биометрия, задачи, документооборот с подписью, зарплата, аналитика, AI-ассистент), а **не** система уровня целевой концепции с модулями CRM / финансы / продажи / склад / закупки / проекты / поддержка — этих модулей в коде **нет вообще** (0 таблиц, 0 моделей, 0 маршрутов).

**Что подтверждено хорошего:**
- **221/221 тест пройден, 1137 assertions** (`php artisan test --compact`, 94 с), все миграции в статусе Ran.
- Единая RBAC-матрица на **76 прав** (`app/Shared/Permissions/PermissionMatrix.php`), 7 ролей (`database/seeders/RbacSeeder.php`), scope own/department/company, анти-эскалация выдачи ролей (`EmployeeController.php:187-220`).
- Класс межтенантной изоляции `app/Support/TenantGuard.php` (178 строк, 10 методов-проверок) — грамотный, с тестами (`tests/Feature/TenantIsolationHttpTest.php`, 18 кейсов).
- Дизайн-система с токенами, тёмной темой, role-based меню на 31 пункт, 53 переиспользуемых empty-state.
- Реальный AI: вызов Groq LLM + детерминированный локальный фолбэк, grounded на фактах scope'а.

**Что мешает системе считаться готовой:**
1. **Межтенантная изоляция — ручная и неполная.** Глобальных scope в моделях нет (grep `addGlobalScope|booted` по `app/Models` → 0), поэтому изоляция держится на 34 ручных вызовах в 5 контроллерах (Document, Employee, Payroll, Schedule, User). В **остальных 17 контроллерах** фильтр по `company_id` отсутствует → кросс-тенантное чтение PII, журналов аудита, биометрии, табеля и запись в чужие сущности (P0-1…P0-10).
2. **Целевые модули ТЗ не работают** — из перечня (ERP, CRM, Finance Management, Accounting, Project Management, Business Intelligence, AI Copilot): **5 не реализованы вообще** (0 таблиц, 0 моделей, 0 маршрутов), **2 — только частично** и не достигают заявленного уровня (BI — есть дашборд/отчёты, но нет витрин и прогнозов; AI Copilot — отвечает, но не выполняет действия и не помнит диалог). См. §4.1.
3. **AI — рабочий, но поверхностный:** нет RAG/embeddings, нет истории диалога в LLM-запросе, нет throttle на `/ai`, `grounded` всегда `true`, API-ключ пишется в аудит-лог открытым текстом.
4. **UX — выше среднего, но не полированный:** 0 skeleton-состояний, ошибки валидации не видны в 28 модалках, сломан фильтр «уволенные», нет горячих клавиш и мобильного поиска, финансовые действия без `confirm()`.

**Общая оценка: 3,3 из 5 (по 23 категориям, сумма 76/115).** Система пригодна для эксплуатации одной компании как HRM-ядро; для мультиарендной эксплуатации и для целевой концепции — **не готова до закрытия P0**.

---

## 2. Текущий уровень зрелости

| Признак | Факт | Доказательство |
|---|---|---|
| Тесты | 221/221, 1137 assertions, 94 с | `php artisan test --compact` |
| Миграции | все Ran, 0 pending | `php artisan migrate:status` |
| Код | 157 PHP-файлов app (~12k строк), 63 blade (~10.8k), 56 моделей, 22 web-контроллера, 18 доменных сервисов | подсчёт по `app/`, `resources/views/` |
| Маршруты | 140+ GET/POST/PUT/DELETE, каждый под `permission:` | `routes/web.php`, `php artisan route:list` |
| База данных | 63 таблицы | `SHOW TABLES` |
| Живые данные | 1 компания, 22 сотрудника, 4 задачи, 0 документов, 0 табелей, 0 зарплатных строк, 11 AI-диалогов, 123 audit_logs, 0 settings | запросы к `corpmis` |
| Задачи по расписанию | detect-missing-checkly 08:30, markOverdue hourly, db:backup 03:30 | `routes/console.php` |
| UI в браузере | вход, дашборд, задачи, документы, зарплата, аналитика, сотрудники — **0 ошибок консоли** | живой обход 16.11.2026 |
| CI/деплой | не выявлены (нет git-репозитория в каталоге) | `Is directory a git repo: no` |

**Уровень: «работающее ядро с зелёным тестовым набором и ручной изоляцией тенантов».** Тесты доказывают функционал, но live-БД почти пустая — реальная эксплуатационная нагрузка на систему не проверялась.

---

## 3. Текущая архитектура

```
routes/web.php (140+ маршрутов, permission: middleware)
routes/device.php (device.token + throttle:120/60)
        │
        ▼
Http\Controllers\{Web, Device}   22 web-контроллера
        │  ручные вызовы TenantGuard (10 методов-проверок) + PermissionService (scope)
        ▼
Domains\{Ai, Audit, Attendance, Biometric, Documents, Employees,
         Notifications, Payroll, Reports, Schedule, Search, Tasks, Users}
        │  18 доменных сервисов — бизнес-логика в сервисах, не в контроллерах
        ▼
Models (56) + Shared\Enums + Shared\Permissions\PermissionMatrix
        │
Support\{TenantGuard, PermissionService, SettingsService, Sidebar, HtmlSanitizer}
        │
MySQL corpmis (63 таблицы) · storage/app/private (подписанные файлы)
```

**Ключевые решения архитектуры:**
- **Бизнес-логика вынесена из контроллеров в доменные сервисы** (`DocumentService`, `TaskService`, `PayrollCalculationService`, `BiometricService`, `AiAssistantService`…) — это правильный слой, контроллеры тонкие.
- **Нет глобальных Eloquent-scope.** Изоляция — ручные вызовы `TenantGuard::ensure*` в контроллерах. Это **первопричина** класса P0 (см. §7): забыл один вызов — получил утечку.
- **Права — не middleware-скоуп, а запрос к `PermissionService`** с мемоизацией на запрос (`PermissionService.php:41-47`), возврат `PermissionScope|false`.
- **Нет API-слоя как такового:** всё общение — server-side Blade + формы; отдельные JSON-эндпоинты только для live-фидов, поиска, AI и device API.
- **Нет очередей/Redis:** фоновые задачи — `routes/console.php` (scheduled). Live-обновления — polling 10 с вместо Reverb (`IntegrationController.php:35`).
- **Фронтенд — ванильный JS + Bootstrap/Tabler 1.1.1**, самовендоренный в `public/assets`. **Vite/Tailwind-пайплайн мёртвый:** `resources/css/app.css` — 0,3 КБ, `resources/js/app.js` — пуст, `public/build/` отсутствует, `@vite` не вызывается ни разу (grep по 63 blade → 0).

---

## 4. Карта модулей

Статус: ✅ реализован · ⚠️ реализован с дефектами · ❌ отсутствует. Оценка 0–5.

| # | Модуль (целевая концепция / ТЗ) | Статус | Оценка | Доказательство (файл) |
|---|---|---|---|---|
| 1 | Аутентификация, сессии, блокировки | ✅ | 4 | `Auth/AuthController.php` (lockout 5/15 мин, `session()->regenerate()`), `throttle:30,1` (`routes/web.php:33`) |
| 2 | RBAC: роли, права, scope | ✅ | 4 | `PermissionMatrix.php` (76 прав), `RbacSeeder.php` (7 ролей), `PermissionService.php:50-98` |
| 3 | Мультиарендность (изоляция компаний) | ⚠️ | **2** | `TenantGuard.php` есть; дыры: `EmployeeController.php:38-47`, `AuditController.php:19`, `AttendanceController.php:129-138`, `BiometricController.php:39-72` |
| 4 | Оргструктура: компании, отделы, должности, орг-чарт | ⚠️ | 4 | `CompanyController.php`, `DepartmentController.php` (дерево, орг-чарт, ИНН-валидация); `update/destroy` без tenant-guard (`CompanyController.php:89,119`; `DepartmentController.php:91,116`) |
| 5 | Кадровый учёт сотрудников | ⚠️ | 4 | `EmployeeController.php` (CRUD, роли, подчинённые, view_props); `store` без `ensureReference` (`:95-97,103`) |
| 6 | Графики работы, отпуска, праздники | ✅ | 4 | `ScheduleController.php` (tenant-guard в 6 местах), `work_schedules`, `leaves` |
| 7 | Посещаемость, табель, корректировки, аномалии | ⚠️ | 3 | `AttendanceController.php`; company-scope без фильтра (`:45-48,129-138,263-267`), `resolveAnomaly` без проверки (`:282-294`) |
| 8 | Биометрия: устройства, события, профили, liveness | ⚠️ | 3 | `routes/device.php` (device.token, sha256-хэш, idempotency) — хорошо; UI-списки и `enroll` без company-фильтра (`BiometricController.php:39-72,144-185`) |
| 9 | RFID-карты | ⚠️ | 3 | `RfidController.php` (issue/revoke проверяют компанию `:112,143`; события — нет `:66-71`) |
| 10 | Задачи: канбан, подзадачи, делегирование, время | ⚠️ | 4 | `TaskService.php` (нумерация, вложенность до 3), `canSeeTask` в 13 местах; назначения на `exists:employees,id` без компании (`TaskController.php:213-217`) |
| 11 | Документооборот: версии, маршруты, шаблоны, workflows | ⚠️ | 4 | `DocumentService.php` (версии, `nextNumber`), `DocumentController.php` (15 tenant-guard); баг валидатора `type` (`:154`), шаблоны/роуты без company-фильтра |
| 12 | Электронная подпись: OTP, QR, драйверы ЭП | ✅ | 4 | `SignatureService.php` (purpose/subject-сверка `:228`), `SignatureDriver` enum, токены с TTL |
| 13 | Зарплата: расчёты в копейках, утверждение, выплата | ✅ | 4 | `PayrollCalculationService.php`, `PayrollController.php` (tenant-guard в 5 местах) |
| 14 | Аналитика и отчёты (дашборд, heatmap, CSV) | ⚠️ | 3 | `AnalyticsService.php`, `ReportController.php`; N+1 в CSV (`:208-242`), нескоупленные метрики (`DashboardController.php:171-197`) |
| 15 | Уведомления in-app + Telegram | ⚠️ | 3 | `NotificationService`, webhook с secret (`TelegramWebhookController.php:24`); дубль-запросы в partials, нет «прочитать всё» |
| 16 | Поиск по системе | ✅ | 3 | `SearchController.php` (company-фильтр `:57,72,127,146`); нет подсветки, `take(20)` без ссылки «показать все» |
| 17 | Настройки системы и интеграции | ⚠️ | 3 | `SettingsService.php` (БД→config→default, тип `secret`); утечка ключа в аудит (`SettingsController.php:88` + `audit/index.blade.php:123`), ложный статус AI (`IntegrationController.php:60-65`) |
| 18 | AI-ассистент (п.68 ТЗ) | ⚠️ | 3 | `AiAssistantService.php`, `AiPanelService.php` (LLM + фолбэк, sanitize); нет RAG/throttle/истории, `grounded=true` всегда |
| 19 | **ERP** — ресурсы и операции компании (склад, закупки, остатки, продукты) | ❌ | **0** | grep `warehouse|stock|inventory|procure|purchase|supplier|product` в миграциях/моделях/маршрутах → **0** |
| 20 | **CRM** — клиенты и отношения с ними (клиенты, контакты, лиды, сделки) | ❌ | **0** | grep `customer|client|contact|lead|deal|pipeline` → **0** |
| 21 | **Finance Management** — управление финансами (счета, платежи, бюджеты) | ❌ | **0** | grep `invoice|payment|transaction|budget` → **0**; есть только payroll зарплаты |
| 22 | **Accounting** — бухгалтерский учёт (проводки, план счетов, первичка) | ❌ | **0** | grep `journal|ledger|accounting` → **0** |
| 23 | **Project Management** — проекты (бюджет, сроки, ресурсы, состав) | ❌ | **0** | grep `project` → **0** (ни таблицы, ни модели, ни маршрута); задачи ≠ проекты |
| 24 | **Business Intelligence** — ⚠️ частично (см. §4.1): дашборд/отчёты есть, витрин и прогнозов нет | ⚠️ | 2 | `AnalyticsService.php`, `DashboardController.php`, `ReportController.php` |
| 25 | **AI Copilot** — ⚠️ частично (см. §4.1): отвечает, но не выполняет действия и не помнит диалог | ⚠️ | 2 | `AiAssistantService.php`, `AiPanelService.php`; нет RAG/tool calling/истории в LLM |

**Итог по карте:** 25 позиций — 18 заявлены в ТЗ, из них 6 реализованы без замечаний, **12 — с дефектами**, а **5 модулей ТЗ (ERP, CRM, Finance, Accounting, Projects) отсутствуют полностью** → 0 баллов каждая. BI и AI Copilot — частично (2 балла).

### 4.1. Сверка с перечнем целевых модулей ТЗ

Перечень ТЗ: ERP · CRM · Finance Management · Accounting · Project Management · Business Intelligence · AI Copilot.

**Проверка проведена по артефактам** (не по интерфейсу): grep `database/migrations`, `app/Models`, `routes/web.php` по ключевым словам каждого модуля.

| Модуль ТЗ | Статус | Оценка | Что найдено (доказательство) |
|---|---|---|---|
| **ERP** (ресурсы и операции компании) | ❌ не работает | **0** | grep по `warehouse|stock|inventory|procure|purchase|supplier|product` в `database/migrations` → **0 совпадений** (единственное созвучное — `vendor` в биометрии, `create_biometric_tables.php:18` — это поставщик оборудования, не закупки); в `app/Models` нет ни одной модели ресурсов/операций; в `routes/` — 0. HRM-ядро (кадры/табель/документы) — не ERP: складов, закупок, операций, ресурсов нет |
| **CRM** (клиенты и отношения) | ❌ не работает | **0** | grep `customer|client|contact|lead|deal|pipeline` в миграциях, моделях, маршрутах → **0 совпадений** |
| **Finance Management** (финансы) | ❌ не работает | **0** | grep `invoice|payment|transaction|budget|finance` → **0**. Есть только payroll (`PayrollPeriod/PayrollLine/EmployeeSalary`) — расчёт зарплаты сотрудников, это не управление финансами компании: счетов, платежей, доходов/расходов, бюджетов нет |
| **Accounting** (бухгалтерский учёт) | ❌ не работает | **0** | grep `journal|ledger|accounting|проводк` → **0**. Проводок, плана счетов, первичных документов, налоговой отчётности нет |
| **Project Management** (проекты) | ❌ не работает | **0** | grep `project` в миграциях/моделях/маршрутах → **0**: ни таблицы `projects`, ни модели `Project`, ни маршрута. Есть только **задачи** (`Task.php` + 10 связанных моделей) — задачи ≠ проекты: нет бюджета проекта, сроков проекта, ресурсов, состава проекта, ганта |
| **Business Intelligence** (бизнес-аналитика) | ⚠️ частично | **2** | **Есть:** `AnalyticsService`, дашборд с KPI/heatmap/donut (`DashboardController`, без-JS рендер), Chart.js в `/analytics`, CSV-отчёты (`ReportController`). **Нет:** витрин данных/OLAP, прогнозов, кастомных дашбордов, срезов по периодам. Плюс дефекты: нескоупленные метрики (`DashboardController.php:171-197`), N+1 в экспорте (`ReportController.php:208-242`) |
| **AI Copilot** (ИИ с данными и процессами) | ⚠️ частично | **2** | **Есть:** реальный LLM-вызов + фолбэк (`AiAssistantService.php:135-142`), боковая панель, AI-заполнение профиля, ответы на фактах scope'а. **Нет:** RAG/векторного поиска, tool calling (копилот **не выполняет действия** в системе — только отвечает), оркестратора, работы с процессами, памяти диалога (`historyFor` сохраняется в БД, но не передаётся в LLM), throttle (`routes/web.php:181-182`) |

**Итог по перечню ТЗ: из 7 модулей — 5 не работают вообще (ERP, CRM, Finance, Accounting, Projects), 2 работают частично и не достигают заявленного уровня (BI, AI Copilot). Ни один не набирает 4+ балла.**

Отдельно: **то, что выглядит реализованным, тоже местами не работает** — см. §7.2 (P0), §6.2 (AI-D1…D7), §5.2 (UX-D1…D10): ложный статус AI-интеграции, нерабочий фильтр «уволенные», кросс-tenant утечки при заявленной изоляции.

---

## 5. UX/UI

### 5.1. Что сделано хорошо (подтверждено в браузере и по коду)
- **Дизайн-система, а не разнобой:** `public/assets/css/tokens.css` (8,5 КБ — типографическая шкала 34→11px, 8pt-спейсинг, радиусы, тап-таргет 44px, светлая/тёмная палитра) → `corpms.css` (мост к Tabler) → `effects.css` (115,4 КБ компонентов) + `ai-panel.css`. Правило «значения только из токенов» задокументировано в `docs/design/DESIGN.md`.
- **Тёмная тема без вспышки:** инлайн-скрипт до отрисовки читает localStorage (`layouts/tabler.blade.php:10-28`), фолбэк на `prefers-color-scheme`.
- **Единый рендер flash и ошибок валидации** (`layouts/tabler.blade.php:58-91`) — в комментарии зафиксировано, что раньше было 11 копий.
- **Role-based меню:** `app/Support/Sidebar.php:19-72` — 7 секций, 31 пункт, каждый с permission-ключом, пустые секции выбрасываются.
- **53 empty-state** с иконкой, текстом и CTA (`partials/empty-state.blade.php`).
- **Kanban с оптимистичным переносом и откатом при ошибке** (`corpms.js:518-618`), view-switcher с памятью, живой поиск с клавиатурной навигацией и XSS-экранированием (`corpms.js:293-431`), submit-guard на ~45 формах.
- **Адаптивность:** все таблицы в `table-responsive` (0 нарушений), бургер-сайдбар, `prefers-reduced-motion`.
- **0 ошибок консоли** на всех просмотренных страницах (живой обход 16.11.2026).

### 5.2. Реальные дефекты (влияют на пользователя)
| ID | Дефект | Доказательство |
|---|---|---|
| UX-D1 | **Ошибки валидации не видны в 28 модалках:** единый баннер сверху, `is-invalid`/`@error` только на логине | `layouts/tabler.blade.php:73-91`; grep `is-invalid` → 3 из 96 форм |
| UX-D2 | **Потеря введённых данных:** `old()` только в 14 местах из 63 файлов; dirty-guard на 1 форме из 96 | `employees/index.blade.php:163` (нет `old()`), `employees/show.blade.php:208` |
| UX-D3 | **Фильтр «уволенные» не работает никогда:** option `value="fired"`, а enum — `Dismissed = 'dismissed'` | `employees/index.blade.php:49` vs `EmployeeStatus.php:11` |
| UX-D4 | **Документы без пагинации и поиска:** `limit(200)->get()`, метрики считаются по обрезанной коллекции | `DocumentController.php:72`, `documents/index.blade.php:26-32,41` |
| UX-D5 | **Критические финансовые действия без `confirm()`:** «Выплатить» ведомость, архивация документа, сброс пароля | `payroll/period.blade.php:39-42`, `documents/show.blade.php:202`, `admin/users.blade.php:106` |
| UX-D6 | **Нет горячих клавиш** (в т.ч. Ctrl+K) — grep `metaKey\|ctrlKey` в `corpms.js` → 0 | `public/assets/js/corpms.js` |
| UX-D7 | **Поиск недоступен на мобильных:** топбар с поиском `d-none d-lg-flex`, в мобильном ряду его нет | `partials/navbar.blade.php:9`, `partials/sidebar.blade.php:32-38` |
| UX-D8 | **Kanban мёртв на тач-устройствах:** HTML5 DnD без медиа-запросов и без альтернативы | `corpms.js:551-617`, `effects.css:1481-1488` |
| UX-D9 | **Два пункта меню ведут на одну страницу:** «Сегодня» и «Live» → один `AttendanceController::index` | `Sidebar.php:37-38`, `routes/web.php:89-90` |
| UX-D10 | **Ни одного skeleton/aria-busy** — grep по views → 1 совпадение (проверка `readyState`) | `resources/views/**` |

### 5.3. Желательные улучшения (не дефекты)
Хлебные крошки объявлены, но не заполняются (`@stack('breadcrumbs')` без `@push`), 206 `<th>` без `scope=`, нет skip-link, 221 хардкод-цвет в blade при наличии токенов, фильтры не сохраняются между переходами, нет «прочитать всё» в уведомлениях, инлайн-CSS/JS в 12 views, устаревшие версии ассетов на страницах логина/ошибок (`auth.blade.php:28-32` vs `tabler.blade.php:34-40`).

### 5.4. Идеи (отделить от дефектов)
Глобальный Ctrl+K с палитрой команд; скелетоны для AJAX-блоков; единый partial модалки с поддержкой `old()`; единый partial таблицы с тулбаром фильтров — убрать 15 копий `<table class="table table-hover…">` и 25 копий кнопки «Отмена».

---

## 6. AI

### 6.1. Что реально реализовано
- **Два сервиса:** `app/Domains/Ai/Services/AiAssistantService.php` (чат-страница `/ai`) и `AiPanelService.php` (боковая панель + `UserController::aiFillProfile`).
- **Реальный LLM:** `Http::post` к OpenAI-совместимому API (`AiAssistantService.php:135-142`, `AiPanelService.php:593-606`), timeout из настройки `corpms.ai.timeout` (по умолчанию 30 с), синхронный вызов.
- **Детерминированный локальный фолбэк** на 7 intents по ключевым словам — работает без внешнего API.
- **Grounded-принцип:** ответ строится на фактах, собранных по scope вызывающего (комментарий `AiAssistantService.php:22`).
- **Санитизация JSON-ответа LLM:** `sanitizeBlocks` (`AiPanelService.php:647`) — whitelisting блоков.
- **История:** последние 20 диалогов (`historyFor(..., $limit = 20)`, `AiAssistantService.php:62`).
- **UI панели:** `partials/ai-panel.blade.php` + `ai-panel.js` (9,7 КБ), typing-индикатор, `aria-live="polite"`, Escape.

### 6.2. Реальные дефекты
| ID | Дефект | Доказательство |
|---|---|---|
| AI-D1 | **Нет throttle на `/ai` и `/ai/message`:** право есть, лимита вызовов LLM нет → финансовый DoS на бюджет AI | `routes/web.php:181-182` (нет `throttle:`) |
| AI-D2 | **API-ключ Groq пишется в audit_logs открытым текстом** и отображается в журнале | `SettingsController.php:81,88` + `audit/index.blade.php:123` |
| AI-D3 | **`grounded` всегда `true`** — флаг не отражает фактическое состояние, вводит в заблуждение | `AiAssistantService.php:49`, `AiPanelService.php:107` |
| AI-D4 | **LLM stateless:** история сохраняется в БД, но не передаётся в контекст LLM → нет памяти диалога | `AiPanelService.php:593-606` (тело запроса без messages-истории) |
| AI-D5 | **Фишинг-баг в `safeHref`:** `/\evil.com` проходит правило «относительный путь» и рендерится как protocol-relative URL | `HtmlSanitizer.php:169` (`^(#\|/\|\./\|\.\./\|\?)`), далее `documents/show.blade.php:83` |
| AI-D6 | **Синхронный вызов до 30 с** в веб-запросе — без очереди, без деградации | `AiAssistantService.php:135-142` |
| AI-D7 | **Ложный статус интеграции:** `config('corpms.llm.api_key')` не существует (ключ — `corpms.ai.api_key`), поэтому статус всегда `fallback` | `IntegrationController.php:60-65` vs `config/corpms.php:50-56` |

### 6.3. Чего нет (по целевой концепции AI)
RAG / embeddings / векторный поиск · tool calling · AI-оркестратор · менеджер контекста · AI-инсайты и прогнозы · генерация документов из шаблонов · OCR · разбор PDF/сканов · классификация документов · предсказание сроков задач. **Ни один из этих пунктов в коде не реализован** (grep по `app/` и `Domains/Ai` → 0 совпадений).

> Разделение сохранено осознанно: RAG (поиск по фактам) и SQL-аналитика (`AnalyticsService`) — разные механизмы; в проекте есть только SQL-аналитика и intents-фолбэк, векторного слоя нет.

---

## 7. Безопасность

### 7.1. Что сделано хорошо
| Область | Доказательство |
|---|---|
| Антибрутфорс логина (двойной) | `Auth/AuthController.php:20-23,50-61,98-101` — `FAIL_LIMIT=5`, `FAIL_DECAY=900`, `RateLimiter::tooManyAttempts('login:<email>')` + IP `throttle:30,1` (`routes/web.php:33`) |
| Session fixation | `Auth/AuthController.php:83` `session()->regenerate()`; logout — `invalidate()` + `regenerateToken()` |
| Security-заголовки | `SecurityHeaders.php:22-28` — nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy, Permissions-Policy, HSTS по HTTPS |
| CSRF | `bootstrap/app.php` без `except:` → дефолтная web-группа; проверено: все формы покрыты |
| SQL-инъекции | grep — нет `whereRaw`/`orderByRaw`/`DB::select` с пользовательским вводом |
| XSS | единственный `{!! !!}` в `documents/show.blade.php:83` проходит через whitelist-санитайзер `HtmlSanitizer.php` |
| Файлы | `storage/app/private` + подпись (`ServeFile.php:27-30`), `mimes:` на загрузках, тест `FileStorageHttpTest` |
| Device API | `DeviceTokenAuth` + sha256-хэш токена, `throttle:120,1`/`60,1`, идемпотентность по `event_uuid` + company-фильтр (`BiometricEventController.php:49-59`) |
| Telegram webhook | secret в пути + `X-Telegram-Bot-Api-Secret-Token`, fail-closed |
| Анти-эскалация ролей | `EmployeeController.php:187-220` |
| RBAC + scope | `PermissionMatrix.php` (76 прав), `EnsurePermission.php:21-33`, `RbacSeeder.php` |

### 7.2. P0 — кросс-тенантные утечки и запись (все подтверждены лично)

**Системная первопричина:** глобальных scope в моделях нет (grep `addGlobalScope|booted` по `app/Models` → 0), изоляция — 34 ручных вызова в **5** контроллерах (Document — 15, Employee — 5, Payroll — 5, Schedule — 7, User — 2). Остальные 17 контроллеров изоляцию не применяют.

| ID | Дефект | Доказательство |
|---|---|---|
| **P0-1** | Список сотрудников **всех компаний** при company-scope | `EmployeeController.php:38-47` — ветки только для `Department`/`Own`, `where('company_id',…)` нет; также `Department::query()->get()`/`Position::query()->get()` на `:69-70` |
| **P0-2** | Журнал аудита **всех компаний** + список всех пользователей | `AuditController.php:19-41` — нет ни company-фильтра, ни проверки; `User::query()->get(['id','name'])` |
| **P0-3** | Табель любого сотрудника любой компании (IDOR) | `AttendanceController.php:127-138` — при company-scope `$selectedId` не сверяется с компанией, `findOrFail` без фильтра |
| **P0-4** | Разрешение чужой аномалии без каких-либо проверок | `AttendanceController.php:282-294` — нет ни tenant-guard, ни проверки `anomalies.manage` в методе |
| **P0-5** | Биометрия: устройства, профили, энроллменты и **активные сотрудники всех компаний** | `BiometricController.php:39-72` — ни одного `company_id`; `enroll` валидирует только `exists:employees,id` / `exists:biometric_devices,id` (`:153-159`) |
| **P0-6** | Компании и отделы: `update`/`destroy` чужих сущностей | `CompanyController.php:89,119` и `DepartmentController.php:91,116` — route-model-binding без сверки тенанта; `positionsUpdate/positionsDestroy` — `:134,154` |
| **P0-7** | Кросс-тенантное назначение задач (в т.ч. массовое) | `TaskController.php:213-217,289-290` — `exists:employees,id` без компании; `TaskService.php:115-123` создаёт назначение без сверки |
| **P0-8** | Дашборд: нескоупленные счётчики документов/отпусков/биометрии + выгрузка всех пользователей с email | `DashboardController.php:171,175,191-197` и `:150-152` (`User::query()->get()`) |
| **P0-9** | Нет проверки тенанта при привязке сотрудника в `store` | `EmployeeController.php:95-97,103` — `exists:departments,id` / `positions` / `employees` / `work_schedules` без `ensureReference` (в `update` — есть, `:161`) |
| **P0-10** | Списки шаблонов документов, workflows, RFID-событий, корректировок без company-фильтра | `DocumentTemplateController.php:32`, `WorkflowController.php:33`, `RfidController.php:66-71`, `AttendanceCorrectionController.php:141-146` (Department-scope: `null === null` → доступ) |

### 7.3. P1 — высокие
- **P1-1. Rate limiting отсутствует** на `POST /ai`, `POST /ai/message`, `POST /profile/ai-fill`, `GET /search`, `GET /search/hint`, Telegram webhook — `throttle:` в `routes/` только в 3 местах (login, device×2).
- **P1-2. Нет маршрутов восстановления пароля** — grep `forgot|reset` по `routes/web.php` → 0 (только смена пароля авторизованным: `:45`, `:142`).
- **P1-3. Политика паролей — только `min:8` + `confirmed`** (`UserController.php:426-439`), `Password::` (zxcvbn) не используется; email-верификация отключена (`User.php:7` — интерфейс закомментирован).
- **P1-4. Нет Content-Security-Policy** (`SecurityHeaders.php` — заголовков 5, CSP нет) и не задан `SESSION_SECURE_COOKIE`.
- **P1-5. Утечка секрета в аудит:** `SettingsController.php:81,88` пишет значение `corpms.ai.api_key` в `new_value`, `audit/index.blade.php:123` выводит его всем, у кого право `audit.view`.
- **P1-6. `.env` содержит живой AI-ключ и `APP_DEBUG=true`**, БД — `root` без пароля; `.env` в `.gitignore`, git-репозитория нет. *(Значения секретов в отчёт не выносятся.)*

### 7.4. P2 — средние
Глобальная нумерация задач (`TaskService.php:357-364`) утекает количество задач других компаний; `DocumentService::nextNumber()` (`:410-416`) считает по `count()+1` без `withTrashed` и без блокировки → коллизия номеров при удалении/гонке; `nextRegNumber` — та же схема (`:418-424`); `employee_roles.department_id` фактически игнорируется при расчёте прав (`PermissionService.php:65`); мёртвое значение `'remote'` в `AnalyticsService.php:122`; `tasks.document_id` / `documents.task_id` никогда не заполняются.

### 7.5. Оценка покрытия направлением
Хорошо закрыто — аутентификация, RBAC, device API, файлы, CSRF, SQLi, XSS, заголовки (8 направлений). Частично — audit (запись ок, чтение кросс-тенант), `.env`, session config, тесты (4). Критические пробелы — **tenant isolation, обходы `company_id`, rate limit AI/поиск (3)**.

---

## 8. Проблемы по приоритетам P0–P3

### P0 — ломает безопасность и данные (закрыть до эксплуатации в мультиаренде)
1. **Тенантная изоляция.** Дыры P0-1…P0-10 из §7.2. Единое решение — не «чинить по одной», а ввести **обязательный механизм** (см. §9, задача A1).
2. **Утечка API-ключа Groq в журнал аудита** (P1-5 / AI-D2).
3. **Кросс-тенантная запись:** назначение задач на чужих сотрудников, `enroll` биометрии чужих сотрудников, `update/destroy` чужих компаний/отделов, разрешение чужих аномалий.
4. **Табель и аудит всех компаний** доступны при company-scope.

### P1 — ломает функции и доверие
1. Нет throttle на AI/поиск/ai-fill → финансовый DoS.
2. Нет восстановления пароля; слабая политика паролей; нет email-верификации.
3. Нет CSP и `SESSION_SECURE_COOKIE`.
4. Ложный статус AI-интеграции (`IntegrationController.php:60-65`).
5. Сломанный фильтр «уволенные» (UX-D3).
6. Ошибки валидации не видны в модалках + потеря `old()` (UX-D1, UX-D2).

### P2 — заметно мешает работе
Документы без пагинации/поиска и с врущими метриками (UX-D4) · финансовые кнопки без `confirm()` (UX-D5) · Kanban мёртв на тач (UX-D8) · поиск недоступен на мобильных (UX-D7) · дубль-пункт меню «Live» (UX-D9) · N+1 в CSV-экспорте (`ReportController.php:208-242`) · коллизия нумерации документов · глобальная нумерация задач · `employee_roles.department_id` игнорируется · 0 skeleton (UX-D10) · нет Ctrl+K (UX-D6).

### P3 — улучшения
Хлебные крошки, `scope=` у `<th>`, skip-link, 221 хардкод-цвет, инлайн-CSS/JS, устаревшие версии ассетов на логине/ошибках, мёртвый Vite/Tailwind-пайплайн, нет «прочитать всё», фильтры не сохраняются, нет подсветки поиска, дубль-запросы уведомлений в partials, светлый FOUC сайдбара, календарь/heatmap без медиа-запросов.

---

## 9. Что сделать (перечень работ, без переписывания с нуля)

> Требование ТЗ §18: не предлагать переписать систему заново. Ниже — точечные работы поверх существующей архитектуры.

**Безопасность и изоляция**
- A1. Ввести **обязательную точку изоляции** вместо ручных вызовов: middleware или route-параметр-байндинг, сверяющий `company_id` целевого объекта (паттерн уже написан в `TenantGuard.php` — нужно сделать его обязательным, а не опциональным).
- A2. Добавить company-фильтр в списки: сотрудники, аудит, табель/аномалии, биометрия, документы-шаблоны, workflows, RFID-события.
- A3. Убрать секрет из `audit_logs`: маскировать значения типа `secret` в `AuditService` и `SettingsController`.
- A4. `throttle` на `/ai`, `/ai/message`, `/profile/ai-fill`, `/search`, `/search/hint`.
- A5. Маршруты `forgot-password` / `reset-password` + `Password::min(12)->uncompromised()`; включить `SESSION_SECURE_COOKIE` и CSP.
- A6. Починить `IntegrationController` (`corpms.ai.api_key`) и `HtmlSanitizer::safeHref` (`/\evil.com`).

**Функциональность**
- B1. Пагинация и поиск документов, корректные метрики.
- B2. Валидатор `DocumentService::nextNumber` (период + `withTrashed` + блокировка/уникальный индекс) и `TaskService::nextNumber` (скоуп по компании).
- B3. Валидатор `type` в `DocumentController::store` привести к enum `DocumentType` (сейчас `order`/`contract` проходят валидацию и падают на касте).
- B4. Убрать `ensureReference`-дыры в `EmployeeController::store` и `DepartmentController::store`.
- B5. Починить фильтр `fired`→`dismissed`, N+1 в CSV-экспорте, закрыть дубль-запросы уведомлений в partials.

**UX**
- C1. Показ ошибок валидации внутри модалок + `old()` во всех полях.
- C2. `confirm()` на финансовые/необратимые действия.
- C3. Ctrl+K и поиск на мобильных; скелетоны для AJAX-блоков.
- C4. Альтернатива Kanban на тач (меню «переместить в колонку»).

**AI**
- D1. Передавать историю в контекст LLM; снять всегдашний `grounded=true` (ставить по факту наличия LLM/фолбэка).
- D2. Не выдавать AI неограниченный доступ к БД: факты собирают существующие скоуп-сервисы — сохранить этот контур, не добавлять SQL-генерацию.

**Целевые модули** — только после закрытия P0 (см. §11).

---

## 10. Roadmap по фазам (A–E)

| Фаза | Срок (оценка) | Содержание | Критерий выхода |
|---|---|---|---|
| **A. Безопасность** | 1–2 недели | A1–A6 (изоляция обязательной точкой, company-фильтры, маскировка секрета, throttle, пароли/CSP, баги интеграции и sanitizer) | `TenantIsolationHttpTest` расширен до 40+ кейсов (каждый P0 — регресс-тест); 0 кросс-тенантных находок повторного аудита |
| **B. Функциональные дефекты** | 1 неделя | B1–B5 | тесты на нумерацию документов/задач, валидатор type, фильтр dismissed; пагинация документов в UI |
| **C. UX** | 1–2 недели | C1–C4 | ошибки видны в модалках на 96 формах; `confirm()` на финансовых действиях; Ctrl+K; поиск на 360px |
| **D. AI** | 1 неделя | D1–D2 + история диалога в контексте | сквозной тест «второй вопрос учитывает первый»; статус интеграции отражает `.env` |
| **E. Целевые модули ТЗ** | от 4 недель | ERP (склад/закупки/операции) → CRM → Finance Management → Accounting → Project Management; BI и AI Copilot — доработка существующих (витрины/прогнозы; RAG + tool calling) | каждый модуль: таблицы + миграции + сервис + UI + тесты + tenant-скоуп с первого дня |

**Порядок жёсткий:** E стартует только после выхода A. Причина: каждый новый модуль без обязательной изоляции воспроизведёт класс P0 в новых таблицах.

---

## 11. Целевая архитектура

**Принцип «не переписывать, а надстраивать»:** текущее ядро (слой `Domains/*` + `TenantGuard` + `PermissionService` + дизайн-система) остаётся фундаментом.

```
[Слой 1 — общее ядро, остаётся как есть]
  TenantGuard (обязательный на всех маршрутах с {model}) · PermissionService
  AuditService (с маскировкой секретов) · SettingsService · Sidebar
  Дизайн-система tokens/corpms/effects · Blade-layout Tabler

[Слой 2 — существующие модули, доработка]
  Оргструктура · Кадры · Графики/отпуска · Посещаемость/табель
  Биометрия/RFID · Задачи · Документы+подпись · Payroll · Аналитика · AI

[Слой 3 — целевые модули ТЗ, каждый = Domains/X + миграции + UI + тесты]
  ERP (склад, закупки, ресурсы и операции компании)
  CRM (клиенты, контакты, лиды, сделки, воронка)
  Finance Management (счета, платежи, доходы/расходы, бюджеты)
  Accounting (план счетов, проводки, первичка, отчётность)
  Project Management (проекты: бюджет, сроки, ресурсы, состав; связь с задачами)
  Business Intelligence — доработка: витрины данных, прогнозы, кастомные дашборды
  AI Copilot — доработка: RAG по документам, tool calling (выполнение действий), память диалога

[Слой 4 — инфраструктура, которой сейчас нет]
  Очереди (Laravel Queue) для AI-вызовов и экспортов · кэш/Redis
  RAG-слой (embeddings + векторный поиск по документам) — отдельно от SQL-аналитики
  Мониторинг и алерты · CI (git-репозиторий, Pint, тесты на PR)
```

**Ключевые архитектурные решения на перспективу:**
1. **Изоляция становится механизмом, а не соглашением** — единая точка входа (A1), иначе каждый новый модуль добавит свои P0.
2. **RAG и SQL-аналитика — разные контуры.** SQL-аналитика уже есть (`AnalyticsService`); RAG (поиск по документам) проектируется отдельно и не подменяет аналитику.
3. **AI получает доступ к данным только через scope-сервисы**, без произвольных запросов к БД.
4. **Очереди** — для LLM-вызовов, экспортов CSV, фоновых сводок (сейчас всё синхронно, до 30 с).

---

## 12. Итоговая оценка по 23 категориям (0–5)

Шкала: 0 — не реализовано · 1 — каркас · 2 — работает с критическими дырами · 3 — работает, есть заметные дефекты · 4 — хорошо, единичные дефекты · 5 — промышленный уровень.

| # | Категория | Оценка | Обоснование (доказательство) |
|---|---|---|---|
| 1 | Аутентификация и сессии | **4** | Двойной lockout + IP-throttle + regenerate (`Auth/AuthController.php:20-23,83`); минус: нет сброса пароля, нет email-верификации |
| 2 | Авторизация (RBAC) | **4** | 76 прав, 7 ролей, scope own/dept/company, анти-эскалация, тесты; минус: scope считается ручными вызовами в контроллерах |
| 3 | Межтенантная изоляция | **2** | `TenantGuard` качественный и протестирован, но 10 подтверждённых P0-дыр и отсутствие глобального scope (`EmployeeController.php:38-47`, `AuditController.php:19`, `BiometricController.php:39-72`) |
| 4 | Безопасность данных и приватность | **3** | Private storage + подпись файлов, retention биометрии; минус: секрет в аудите (`SettingsController.php:81`), нет CSP |
| 5 | Аудит и журналирование | **3** | 123 записи live, единый `AuditService`, модель с old/new; минус: чтение кросс-тенант (`AuditController.php:19`) |
| 6 | API security / rate limiting | **3** | Device API и login защищены; минус: `/ai`, `/search`, `ai-fill` без throttle |
| 7 | Оргструктура | **4** | Дерево отделов, орг-чарт, должности, ИНН/телефон-валидация; минус: `update/destroy` без tenant-guard (`CompanyController.php:89,119`) |
| 8 | Кадровый учёт | **4** | CRUD, роли, подчинённые, view_props, табельные номера; минус: `store` без `ensureReference` (`:95-103`) |
| 9 | Графики, отпуска, посещаемость, табель | **3** | Полный контур + корректировки + аномалии + экспорт; минус: company-scope без фильтра, `resolveAnomaly` без проверки (`AttendanceController.php:129-138,282-294`) |
| 10 | Биометрия и RFID | **3** | Надёжный device API (sha256-токен, идемпотентность, liveness); минус: весь UI-слой без company-фильтра (`BiometricController.php:39-72,153-159`) |
| 11 | Задачи и совместная работа | **4** | Канбан/календарь, подзадачи до 3 уровней, делегирование, учёт времени, 13 проверок `canSeeTask`; минус: назначения на чужих (`TaskController.php:213-217`) |
| 12 | Документооборот | **4** | Версии с историей, маршруты согласования, шаблоны, workflows, 15 tenant-guard; минус: баг валидатора `type` (`:154`), коллизия нумерации (`DocumentService.php:410-416`) |
| 13 | Электронная подпись | **4** | OTP/QR/драйверы ЭП, TTL-токены, сверка purpose+subject (`SignatureService.php:228`), тесты |
| 14 | Зарплата (payroll) | **4** | Расчёты в копейках, оплачиваемые отпуска, утверждение/выплата, экспорт, tenant-guard в 5 местах; минус: live-БД без строк (функционал доказан только тестами) |
| 15 | Аналитика и отчёты | **3** | Дашборд с heatmap/donut без JS, Chart.js в аналитике, CSV-экспорт; минус: N+1 (`ReportController.php:208-242`), нескоупленные метрики (`DashboardController.php:171-197`) |
| 16 | Уведомления и коммуникации | **3** | In-app + Telegram webhook с secret + утренняя сводка; минус: дубль-запросы в partials, нет «прочитать всё» |
| 17 | Поиск и навигация | **3** | Живой поиск с клавиатурой и XSS-экранированием, company-фильтр; минус: нет мобильного поиска, нет крошек, `take(20)` без «показать все» |
| 18 | UX/UI и дизайн-система | **4** | Токены + мост к Tabler, тёмная тема без вспышки, role-based меню, 53 empty-state, 0 ошибок консоли; минус: ошибки модалок, потеря `old()`, 0 skeleton |
| 19 | Производительность и масштабируемость | **3** | Пагинация везде кроме документов, мемоизация прав, `withCount`, ленивая подгрузка TinyMCE/Select2; минус: N+1 в CSV, дубль-запросы, `limit(200)` вместо пагинации |
| 20 | Надёжность, backup, экспорт данных | **4** | `db:backup` 03:30, `markOverdue` почасово, `detect-missing` 08:30, CSV-экспорт, строгие типы; минус: нет мониторинга/алертов |
| 21 | Качество кода и тесты | **4** | 221/221 тестов, 1137 assertions, 31 тест-файл, Pint, `declare(strict_types=1)`, доменные сервисы; минус: покрытие не измерялось, git отсутствует |
| 22 | AI-функциональность | **3** | Реальный LLM + детерминированный фолбэк, grounded на scope, sanitize, история 20; минус: нет RAG/throttle/памяти диалога, `grounded` всегда true, ключ в аудите |
| 23 | Целевые модули ТЗ: **ERP, CRM, Finance Management, Accounting, Project Management** | **0** | Полное отсутствие: grep `database/migrations`, `app/Models`, `routes/web.php` по `warehouse|stock|inventory|procure|customer|client|lead|deal|invoice|payment|budget|journal|ledger|project` → **0 совпадений** (единственное `vendor` — биометрия). BI и AI Copilot частично учтены в категориях 15 и 22 |

### Сводка
- **Сумма: 76 из 115 → среднее 3,3 из 5 (66%).**
- По **ядру** (категории 1–22): 76 из 110 → **3,5 из 5**.
- Категория 23 (целевые модули) — **0**: целевая концепция не реализована вовсе.
- Распределение: 5 баллов — 0 категорий; 4 балла — 11; 3 балла — 10; 2 балла — 1; 0 баллов — 1.

**Вывод:** система — **крепкое HRM-ядро уровня 3,3/5**, которое нельзя эксплуатировать в мультиарендном режиме до закрытия P0 и которое не покрывает целевую концепцию: 5 из 7 модулей ТЗ (ERP, CRM, Finance, Accounting, Projects) набирают 0, ещё 2 (BI, AI Copilot) — только частично.

---

## 13. План следующего спринта

**Цель спринта: закрыть все P0 и первые P1, чтобы систему можно было безопасно показывать нескольким компаниям.**

| # | Задача | Приоритет | Зависимости | Критерий готовности | Тест |
|---|---|---|---|---|---|
| S1 | Обязательная точка тенант-изоляции (middleware/binding по `company_id` для маршрутов с `{model}`) | **P0** | — | Ни один маршрут с `{model}` не выполняет чтение/запись без сверки компании; ручные вызовы `TenantGuard` заменены на механизм | Расширить `TenantIsolationHttpTest` до 40+ кейсов, включая `update/destroy` компаний и отделов |
| S2 | Company-фильтры в списках: сотрудники, аудит, табель, аномалии, биометрия, шаблоны, workflows, RFID | **P0** | S1 (механизм) | При company-scope ни один список не возвращает записи чужих компаний | Feature-тест на каждый список: запись компании B невидима пользователю компании A |
| S3 | Закрыть кросс-тенантную запись: назначения задач, `enroll`, `update/destroy` компаний/отделов, `resolveAnomaly` | **P0** | S1 | Попытка записи на чужой объект → 403 | Тесты 403 на каждый сценарий (сейчас есть только на чтение) |
| S4 | Маскировка `secret`-значений в `audit_logs` + уборка уже записанных | **P0** | — | API-ключ не попадает в `new_value` и не отображается в `/audit` | Unit-тест `AuditService` на маскирование |
| S5 | `throttle` на `/ai`, `/ai/message`, `/profile/ai-fill`, `/search`, `/search/hint` | **P1** | — | Превышение лимита → 429 | Тест на 429 после лимита |
| S6 | Восстановление пароля + ужесточение политики (`Password::min(12)`) + `SESSION_SECURE_COOKIE` + CSP | **P1** | — | `forgot-password` → письмо → сброс → вход; старый пароль отвергается | `AuthFlowTest` расширить сценарием сброса |
| S7 | Починить `IntegrationController` (ключ `corpms.ai.api_key`) и `HtmlSanitizer::safeHref` (`/\evil.com`) | **P1** | — | Статус AI отражает `.env`; фишинг-URL режется | Unit-тест `HtmlSanitizer` на `/\evil.com`, feature-тест страницы интеграций |
| S8 | Фильтр `fired`→`dismissed`, пагинация и поиск документов, корректные метрики | **P1** | — | Выбор «уволенные» показывает уволенных; документы листаются; метрики считают по всем записям | Feature-тест фильтра; тест метрик при 250 документах |
| S9 | Ошибки валидации внутри модалок + `old()` во всех полях + `confirm()` на финансовых действиях | **P1** | — | Ошибка в модалке видна без закрытия формы; «Выплатить» требует подтверждения | Ручной смоук по 5 формам; проверка `old()` в 96 формах grep'ом |
| S10 | История диалога в контексте LLM + честный `grounded` | **P1** | — | Второй вопрос учитывает первый; `grounded=false` при фолбэке | Feature-тест `AiAssistantService` с моком HTTP |

**Не входит в спринт (осознанно):** целевые модули ТЗ (ERP, CRM, Finance Management, Accounting, Project Management) — старт только после выхода фазы A (§10), иначе новые модули воспроизведут P0 в новых таблицах. BI и AI Copilot — доработка в фазах D/E.

**Ожидаемый результат спринта:** оценка категории «Межтенантная изоляция» 2 → 4, «API security» 3 → 4, «Безопасность данных» 3 → 4; сумма по 23 категориям 76 → 80 (3,3 → 3,5 из 5), при этом полностью закрыт блок P0.
