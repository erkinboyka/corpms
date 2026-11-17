# BM SYSTEMS — АУДИТ (автономная сессия 17.11.2026)

Базовый аудит: `AUDIT_REPORT_2026-11-16.md` (полный, 23 категории, 76/115).
Настоящий файл — рабочее состояние задач после вмешательства автономной команды:
фиксирует, что уже закрыто, что осталось и почему.

## 1. Фактический уровень проекта (на 17.11.2026)

- **Стек:** Laravel 13.39, PHP 8.5, MySQL `corpmis`, Blade + Tabler 1.1.1 (без Vite/Tailwind),
  `declare(strict_types=1)`, доменные сервисы в `app/Domains/*`.
- **Тесты:** 262/262, 1318 assertions (`php artisan test --compact`, ~65 с).
- **Миграции:** все применены (`php artisan migrate:status`), включая очистку секретов в аудите.
- **Ядро (HRM):** оргструктура, кадры, графики/отпуска, табель, биометрия/RFID, задачи,
  документооборот + ЭП, payroll, аналитика, уведомления, AI-панель — работает и закрыто тестами.
- **Модули ТЗ:** ERP, CRM, Finance, Accounting, Project Management — **не реализованы (0)**.
  BI — частично (SQL-аналитика без витрин), AI Copilot — частично (LLM + фолбэк, без RAG/tool calling).

## 2. Межтенантная изоляция: система вместо ручных проверок

**Механизм (S1, готов):**
- `app/Http/Middleware/EnsureTenantIsolation.php` — обязательная точка: проходит по всем
  route-параметрам после `SubstituteBindings`, сверяет `company_id` (через цепочку связей
  из `RELATED`), fail-closed для неизвестных моделей, сверяет вложенные параметры маршрута
  (404 при подмене родителя). Регистрация — `bootstrap/app.php` (web-группа).
- `TenantGuard` — ручные проверки для **ID из тела запроса** (middleware их не видит).

**Закрыто (было P0, подтверждено тестами):**

| # | Дефект | Статус | Тест |
|---|---|---|---|
| P0-1 | Список сотрудников всех компаний | закрыт | TenantListScopeHttpTest |
| P0-2 | Аудит/пользователи всех компаний | закрыт | там же |
| P0-3 | IDOR табеля | закрыт | там же |
| P0-4 | Разрешение чужой аномалии | закрыт (middleware + permission на маршруте) | TenantIsolationHttpTest |
| P0-5 | Биометрия: списки + **enroll чужих ID** | закрыт | `test_biometric_enroll_foreign_employee_and_device_blocked` |
| P0-6 | Компании/отделы update-destroy + **ссылки из тела (parent/director/deputy)** | закрыт | `test_department_store_and_update_foreign_references_blocked` |
| P0-7 | Назначения задач чужим (store/bulk/subtask/delegate), **глобальная нумерация**, **нескоупленные списки** | закрыт | 5 тестов + `test_task_numbering_is_scoped_per_company` |
| P0-8 | Дашборд: счётчики и выгрузка пользователей | закрыт | TenantListScopeHttpTest |
| P0-9 | `ensureReference` в store сотрудника | закрыт | TenantIsolationHttpTest |
| P0-10 | Шаблоны/workflows/RFID/корректировки списки | закрыт | TenantListScopeHttpTest |
| — | Списки: отделы, орг-чарт, должности, справочник компаний | закрыт | `test_departments_orgchart_and_positions_scoped_to_own_company` |

**Остатки (P2, осознанно не блокируют):**
- `EnsurePermission` проверяет наличие права, а не scope: держатель `anomalies.manage`
  со scope=own может разрешить аномалию **внутри своей компании** (не кросс-тенант).
- `settings` — глобальная таблица: `biometry.privacy` пишет `corpms.biometric.*` для всех
  тенантов сразу (нужен company-level settings при появлении второго тенанта в проде).
- Модели без `company_id`, не описанные в `RELATED` middleware, — fail-closed 403 при
  маршрутизации (безопасно, но при добавлении маршрутов их нужно дописать в `RELATED`).

## 3. Безопасность: закрыто в этой сессии

| Задача | Что сделано |
|---|---|
| **S4** секрет в аудите | `SettingsController::maskSecret` (type=secret → `••••••`), маскировка в `AuditService::maskSensitive` (защита в глубину), миграция `2026_11_16_215115_mask_secret_values_in_audit_logs` очистила уже записанные значения. Тесты: `AuditSecretMaskingTest` (4). |
| **S5** rate limiting | `throttle:` на `/ai` (30/15), `/ai/message` (15), `/profile/ai-fill` (10), `/search` (60), `/search/hint` (120), Telegram webhook (60). |
| **S7** ложный статус AI + фишинг-ссылки | `IntegrationController` использует фактическую конфигурацию (`corpms.ai.*` через SettingsService); `HtmlSanitizer::safeHref` режет `//host` и `/\host`. Тесты: `HtmlSanitizerTest` (4). |
| **S8** фильтр «уволенные» | `fired` → `dismissed` в `employees/index.blade.php`. |
| **S8** пагинация/поиск документов | `DocumentController::index`: `paginate(25)`, поиск `q`, метрики по всем записям scope (не по странице и не по фильтру). Тест: `DocumentsHttpTest::test_documents_index_paginates_searches_and_counts_metrics_over_all_rows`. |

## 4. Открытые проблемы (по приоритетам)

### P1 — следующий спринт
1. **S6:** `forgot-password` / `reset-password`, политика `Password::min(12)`,
   `SESSION_SECURE_COOKIE`, CSP.
2. **S9:** ошибки валидации внутри модалок (единый баннер сверху не виден в модалке),
   `old()` во всех полях, `confirm()` на финансовых/необратимых действиях.
3. **S10:** история диалога в контексте LLM (сейчас stateless), честный `grounded`.
4. **B2:** коллизия нумерации документов (`DocumentService::nextNumber` — `count()+1`
   без `withTrashed` и блокировки).
5. **B3:** валидатор `type` в `DocumentController::store` не соответствует enum.
6. **B5:** N+1 в CSV-экспорте (`ReportController.php:208-242`), дубль-запросы уведомлений.
7. **UX-D5:** `confirm()` на «Выплатить ведомость», архивацию, сброс пароля.

### P2
- UX-D6 (Ctrl+K), UX-D7 (поиск на мобильных), UX-D8 (Kanban на тач), UX-D9 (дубль меню),
  UX-D10 (skeleton/aria-busy), UX-D1/D2 (модалки/old() — частично в S9).
- Глобальная нумерация документов (см. B2), `employee_roles.department_id` игнорируется.

### P3 (целевые модули ТЗ) — старт после P0/P1
ERP → CRM → Finance Management → Accounting → Project Management;
BI (витрины/прогнозы), AI Copilot (RAG, tool calling, память диалога).
Каждый модуль: миграции + сервис + UI + тесты + tenant-скоуп с первого дня.

## 5. Известные ограничения среды
- Git-репозитория нет → `pint --dirty` недоступен; Pint запускается на изменённых файлах.
- `.env` содержит живой AI-ключ и `APP_DEBUG=true` (P1-6 из аудита) — не исправлялось:
  работа с секретами вне автономного разрешения.
- Очереди/Redis не подключены — AI-вызовы синхронные (до 30 с).
