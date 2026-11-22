# 01 — Executive summary

Дата аудита: 2026-11-22. Режим аудита: **только чтение и безопасные локальные проверки**. Производственные данные не изменялись, реальные секреты в отчёты не включались. После аудита по запросу пользователя выполнены локальные исправления (P0→P3 и UX-волна); коммиты/push не выполнялись.

## 1. Вердикт

**Проект НЕ готов к production до внешних подтверждений.**

Исходный аудит нашёл подтверждённую **Critical** проблему tenant isolation (полные бэкапы БД доступны company-scoped роли), High-дефекты бизнес-логики и эксплуатационные пробелы. Все найденные P0/P1/P2/P3-проблемы исправлены локально; финальная контрольная точка — `php artisan test --do-not-cache-result`: **620/620 тестов, 4234 assertion** — PASS. Локально закрыты также proxy/HTTPS/Secure cookie/HSTS, restore БД и вложений, конкурентность/rollback, frontend build и dependency audit, браузерный smoke (48 страниц, axe), реальный LLM-эндпоинт (200/11 моделей). Остаются неподтверждёнными только внешние факты: полный Google OAuth flow и ротация секрета, реальный Telegram (нет токенов), off-site копия, RTO/RPO и перезапуски сервисов, egress-файрвол ОС, нагрузка на production-копии — подробнее в `13_EXTERNAL_DRILLS.md`.

Сильные стороны:
- 314 маршрутов, 272 под `permission:`, 81 с `@company`; глобальные middleware `SecurityHeaders`, `EnsureActiveEmployee`, `SetLocale`, `EnsureOnboarded`, `EnsureTenantIsolation` (`bootstrap/app.php:29-38`).
- Device API защищён `device.token` + throttle (`routes/device.php:12-16,19-21`).
- Шифрование настроек и маскировка секретов в аудит-журнале присутствуют (`tests/Feature/AuditSecretMaskingTest.php:104-114,125`).
- Санитайзер rich-text и AI Markdown ограничены allowlist’ами (`app/Support/HtmlSanitizer.php:23-42,160-181`; `app/Support/AssistantMarkdown.php:13-21`).
- Финансы/склад имеют блокировки и транзакции в ключевых операциях (`FinanceService.php:170-229`; `InventoryService.php:107-186`).

Ключевые слабые стороны:
- **Безопасность:** кросс-tenant backup, replay OAuth state, утечка списка import-job, неполная изоляция scope в отдельных действиях.
- **Корректность:** QR/подписи и payroll/attendance/tasks имеют гонки и неатомарные переходы; декларативная машина состояний обходится.
- **Эксплуатация:** локальные drill’ы закрыты (restore, concurrency, proxy, preflight), но нет доказательств на целевой инфраструктуре: CI run, off-site копия, RTO/RPO, supervisor/systemd/cron, egress-файрвол.
- **Качество:** исторический долг по форматированию устранён — `vendor/bin/pint --test` PASS, база `tools/pint-baseline.json` пуста, gate `tools/verify-pint-baseline.php` строгий. Node/npm/Composer установлены: `npm ci`, `npm run build`, `npm audit --audit-level=high`, `composer audit --locked` — PASS; `.phpunit.result.cache` удалён, тесты запускаются с `--do-not-cache-result`.

## 2. Сводка по отчётам

| Файл | Статус | Главный вывод |
|---|---|---|
| `02_ARCHITECTURE.md` | Завершено | 933 файла, 314 маршрутов, 48 миграций, 107 моделей, 142 Blade, 59 тестовых файлов; стек Laravel 13 / Reverb / Vite+Tailwind |
| `03_SECURITY.md` | Завершено | 6 confirmed security findings + 4 suspected (SEC-10 условный); SEC-01 Critical |
| `04_DATABASE.md` | Завершено | 15 confirmed DB-проблем, 5 suspected, 4 не проверены; DB-01/03/05/13 приоритетны |
| `05_BACKEND_API.md` | Завершено | 6 confirmed + 4 suspected backend/API-проблемы (BE-02 зависит от broadcaster/формата канала); 116 POST без throttle (115 авторизованы) |
| `06_FRONTEND.md` | Завершено (static) | 7 confirmed frontend-дефектов + 2 suspected; palette/search/chat polling/TinyMCE |
| `07_UI_UX.md` | Завершено (static) | TOP-20 UX-проблем; доступность, навигация, состояние ошибок |
| `08_BUSINESS_LOGIC.md` | Завершено | 21 confirmed + 6 hypotheses; QR, payroll, attendance, tasks |
| `09_AI_SYSTEM.md` | Завершено | `grounded` не доказывает использование данных; source_items не сохраняются; SSRF/prompt-injection suspected |
| `10_PERFORMANCE.md` | Завершено (static) | N+1 чата, unbounded выборки, polling, maintenance без throttle; нагрузка не измерялась |
| `11_TESTING.md` | Завершено (аудит) | 473/473 PASS на момент аудита; после исправлений 620/620 PASS, TEST-02/03/04 подтверждены локально, TEST-01 — только симуляция |
| `12_REMEDIATION_PLAN.md` | Завершено | P0–P3 с критериями приёмки и порядок проверки |
| `13_EXTERNAL_DRILLS.md` | Завершено (локально) | proxy/HTTPS/HSTS, restore, concurrency, LLM и browser smoke — успешно; внешние факты перечислены как Not tested |

Идентификаторы пересекаются между отчётами (например, SEC-03 = BE-01); итоговый приоритет — в разделах 3 и 4.

## 3. TOP-20 security / trust risks

| # | ID | Sev | Проблема / доказательство | Статус |
|---|---|---|---|---|
| 1 | SEC-01 | **Critical** | Company-scoped `settings.view` перечисляет/скачивает **все** бэкапы БД: `BackupController.php:16-35,50-57`; `routes/web.php:361,373`; `config/backup.php:15` | Confirmed |
| 2 | SEC-02 | High | OAuth `state` не удаляется после callback: `GoogleAuthController.php:21-22,37-39,90-91`; `routes/web.php:74-75` | Confirmed |
| 3 | BL-05 | High | Quick QR переводит `draft/revision → signing` в обход машины состояний: `WorkflowEngine.php:42-52,90-108`; `SignatureService.php:287-298` | Confirmed |
| 4 | BL-01 / DB-13 | High | QR token помечает `used_at` после подписи, без lock/транзакции: `SignatureService.php:210,224-226,232-238` | Confirmed (code-level race) |
| 5 | SEC-03 / BE-01 | Medium | `GET /imports` без `company_id`: `ImportController.php:20`; `routes/web.php:367` | Confirmed |
| 6 | BL-18 | High | `submitResult` доступен любому зрителю задачи и дублирует results: `TaskController.php:523-536`; `TaskService.php:270-280` | Confirmed |
| 7 | BL-15 / ATT-03 | Medium | `resolveAnomaly` не проверяет department scope: `AttendanceController.php:297-308`; `RbacSeeder.php:142` | Confirmed |
| 8 | SEC-10 / BE-02 | Medium | `/broadcasting/auth` может вызвать null dereference → 500 при драйвере reverb/pusher и канале без префикса `private-`: `routes/channels.php:8,17,42-43`; `Broadcaster.php:120,331,338`; контраст `PusherBroadcaster.php:90-93` | Suspected (conditional) |
| 9 | SEC-04 / BE-S3 | Medium | Vendor `POST /_boost/browser-logs` без middleware, log injection/disk: `composer.json:16`; `BoostServiceProvider.php:107-125` | Confirmed в чекауте |
| 10 | BE-06 | Medium | `/accounting/sync`, `/bi/rebuild`, `/backups/*` без throttle/очереди: `routes/web.php:263,291,371-372`; `BackupController.php:40,46` | Confirmed |
| 11 | BE-07 | Medium | Поиск `q` без валидации/wildcard escaping: `SearchController.php:34,58,97,128`; `DocumentController.php:79-85` | Confirmed |
| 12 | SEC-06 / BE-04 | Medium | Telegram secret в URL, `!==`, нет header-check: `routes/device.php:23-25`; `TelegramWebhookController.php:22,27-28` | Confirmed |
| 13 | SEC-05 | Low | Нет per-account TOTP lockout: `routes/web.php:72`; `TwoFactorController.php:26-40`; `TwoFactorService.php:30-43` | Confirmed |
| 14 | SEC-08 / AI-04 | Medium | SSRF через tenant-настройку `corpms.ai.base_url`: `SettingsService.php:83-87`; `SettingsController.php:83-85`; `AiAssistantService.php:242,299-301` | Suspected (нужны `settings.manage`) |
| 15 | SEC-07 | Low | Google `email_verified` не проверяется: `GoogleAuthController.php:60-67` | Suspected |
| 16 | SEC-09 | Low | Полный `raw_payload` биометрии хранится без политики удержания: `BiometricEventController.php:71,89` | Suspected |
| 17 | AI-03 | Medium | Недоверенный контент RAG/history/memory без delimiters: `AiAssistantService.php:132-150,250-260`; инструменты перепроверяют права на `:105-107` | Suspected (эскалация не доказана) |
| 18 | DB-12 | Low | MySQL `UNIQUE` с NULL допускает дубли ролей/аномалий: `2026_10_31_000002_create_rbac_tables.php:45`; `2026_10_31_000004_create_attendance_tables.php:79` | Confirmed (семантика схемы) |
| 19 | DB-05 / BL-11 | High | Гонка/пересечение окладов влияет на расчёт зарплаты: `PayrollController.php:367-392`; `PayrollCalculationService.php:80-87,118` | Confirmed (code-level race) |
| 20 | TEST-04 / TEST-09 | High | Prod preflight: secure cookie, trusted proxy, HSTS, APP_KEY/env: `.env.example:3,40-41,50-53`; `bootstrap/app.php:22-38`; `SecurityHeaders.php:61-62` | Confirmed |

## 4. TOP-20 UX-проблем

Полная таблица с доказательствами — `07_UI_UX.md`. Кратко: UX-01 palette focus trap; UX-02 aria-activedescendant; UX-03 silent search errors; UX-04 chat polling backoff; UX-05 chat edit race; UX-06 LMS polling lifecycle; UX-07 TinyMCE handler stack; UX-08 `<a class="btn-close">`; UX-09 bell aria-label; UX-10 language `href="#"`; UX-11 profile `href="#"`; UX-12 label/control association; UX-13 `<h3>` вместо label; UX-14 таксономия аналитики/отчётов; UX-15 module icons; UX-16 mobile search; UX-17 silent LMS sync failure; UX-18 inline geometry; UX-19 autofocus; UX-20 unbounded list screens.

### 4.1. Post-audit UX-волна (дополнительные 9 браузерных замечаний)

| Замечание | Вердикт | Что сделано / доказательство |
|---|---|---|
| Кнопка «Настройки диалога» только иконка | Confirmed → исправлено | В `resources/views/chat/index.blade.php` добавлена видимая подпись; в `bm-chat.css` — стиль и скрытие только на `<768px`. В браузере при 1359px: `labelText="Настройки"`, `display=block`. |
| Кнопки в окне настроек диалога без текста | Not confirmed | В DOM `#chat-settings-dialog`: «Установить обои», «Скрыть диалог у себя»; текст присутствует, изменений кода не потребовалось. |
| История статусов задачи показывает `review → completed` | Confirmed → исправлено | `resources/views/tasks/show.blade.php` теперь использует `$crm_label('task_status', …)`; в браузере: «На проверке →», «В работе →». |
| Dropdown статуса пропадает под следующей секцией | Confirmed → исправлено | Root cause: `workspace.css:506-517` задаёт `details::details-content { overflow: clip }`. Добавлено исключение в `crm-select.css` (`overflow: visible` при `details:has(.crm-select.is-open)`) и подъём секции через `z-index`. В браузере все точки меню попадают на `.crm-select__option`. |
| Select2 multiple не прокручивается в модалке | Confirmed → исправлено | `dropdownParent` перенесён с `.modal` на `document.body`, `.select2-container--open`/dropdown получили `z-index:2000`. В браузере: parent `BODY`, `scrollHeight=801 > clientHeight=200`, hit-тесты по списку — внутри dropdown, `defaultPrevented=false`. |
| Курс завершался после одного урока | Confirmed → исправлено | `LmsService::recalculate()` при отсутствии `is_required` теперь берёт все уроки; 0 уроков → 0%. Новый тест: 1 из 2 → 50%/`in_progress`, 2 из 2 → 100%/`completed`. |
| Чаты LMS нечитаемы в светлой/тёмной теме | Confirmed → исправлено | Цвета на токенах `--crm-surface-raised`/`--crm-text`/`--crm-accent-rgb`. Контраст в браузере: light **16.96:1**, dark **14.78:1**. |
| Выпадающий список шаблонов «без маршрута / вмым / ывмывмМм» | Не дефект кода | Это реальные имена workflow из БД (`documents/templates`: option values `""`, `1`, `3`). Требуется очистка справочника владельцем данных; production-данные не изменялись. |
| Действия аналитики `auth.login`, `ai.asked` и т.п. | Confirmed → исправлено | Добавлены `EnumLabels::AUDIT_ACTIONS`/сегменты и `$crm_label('audit_action', …)`. В браузере: «Вход в систему», «Изменение статуса задачи», «Запрос к ИИ», «Экспорт отчёта», «Подготовка QR-подписи». |

Исходные UX-правки проверялись через DOM/computed styles (встроенный браузер не давал скриншоты). Позже установлены Node/npm, Playwright и Chromium: полный smoke `tools/verify-browser.py` — 48 страниц, 0 axe-нарушений, 0 JS-ошибок, 200 HTTP-запросов без ошибок. Статические и feature-контракты добавлены в `FrontendAccessibilityTest` и `LmsHttpTest`; полный PHP-прогон — 620/620 PASS.

## 5. Критическая карта зависимостей

| Зависимость | Потребители / файлы | Тип риска | Статус проверки |
|---|---|---|---|
| Laravel framework 13.43.0 (`composer.json:10`) | весь backend, middleware, Eloquent, exceptions | глобальный upgrade/security surface | версия подтверждена из `composer.lock`; `composer audit` только в CI (`.github/workflows/ci.yml:70`), локально CLI недоступен |
| Laravel Reverb 1.14 (`composer.json:11`) | chat/LMS broadcast, `routes/channels.php`, `config/reverb.php` | процесс не поднимется после рестарта; условный guest auth 500 (SEC-10/BE-02) | конфиг/код проверены, сетевая конфигурация **не проверена** |
| Google OAuth direct HTTP (`GoogleAuthController.php:32,45,56`; `config/services.php:38-41`) | login | replay state, email_verified | код проверен; реальный провайдер не вызывался |
| Telegram webhook (`routes/device.php:23-25`; `TelegramService`) | уведомления | secret в URL, payload без валидации | код проверен, контракт провайдера **не проверен** |
| AI provider via `corpms.ai.base_url` (`config/corpms.php:52-66`) | `AiAssistantService`, `DocumentRagService`, `BumiFiles`, `RetryAiAnswer` | SSRF, provider outage, cost | код проверен; egress/firewall **не проверены** |
| Database queue (`phpunit.xml` sync в тестах; `config/queue.php`) | AI retry, notifications, jobs | scheduler/worker отсутствуют в репо как deploy-артефакты | TEST-03 подтверждено |
| Filesystem local/public + symlink (`config/filesystems.php:33-48,76-78`) | photo, AI artifacts, chat images, backups | tenant-scope backup (SEC-01), disk exhaustion | код проверен; ёмкость/ротация **не проверены** |
| Vite 8 / Tailwind 4 (`package.json:5-15`) | build frontend | сборка и npm audit не выполнялись | npm/node_modules недоступны |
| CI (`.github/workflows/ci.yml`) | PHPUnit sqlite+mysql, composer audit, Playwright browser/accessibility/load | нет Pint/npm gate; нет доказательства успешного run | файл проверен; run не подтверждён |

## 6. Отсутствующие тесты (сводка)

Полный список — `11_TESTING.md:112-130`.

- **P0:** prod preflight (APP_KEY/APP_DEBUG/HTTPS/cookie/trusted proxy); restore drill + RTO/RPO; scheduler/worker/Reverb heartbeat; негативные health-сценарии.
- **P1:** MySQL `db:backup` path; все 5 scheduler-задач; concurrency money/stock/salary/QR; migrate up/down на копии prod-БД; контракты Telegram/email/AI/webhook-secret.
- **P2:** prompt-injection/RAG-negative; timeout/retry/backoff; отсутствие ПДн в логах; coverage threshold; удаление skeleton-тестов.
- Дополнительно из модульных аудитов: two-tenant тесты бэкапов/импорта, replay OAuth, concurrency/workflow QR, department-scope, пересечение окладов/зарплат, контракт ошибок JSON, guest broadcast, accessibility-регрессия UI.

## 7. Что было фактически проверено

- `git ls-files`: 933 файла; `php artisan route:list --json`: 314 маршрутов.
- `php artisan test`: 473/473 PASS, 3541 assertion, 227,3 с.
- `php -l` по 443 PHP-файлам: 0 ошибок.
- `php vendor/bin/pint --test`: FAIL — 210 файлов.
- Статический разбор 48 миграций, 107 моделей, 58 контроллеров, 142 Blade, 59 тестов, middleware/routes/config.
- Динамическая эксплуатация, реальные prod-значения env, коммерческий dependency audit, npm build/audit, frontend load test и screen-reader проверки **не выполнялись** и честно помечены как Not tested/Suspected.

## 8. Немедленные действия (до любых исправлений кода)

1. Ограничить/отключить роуты `/backups*` для company-scoped ролей и проверить, кто имел к ним доступ (SEC-01).
2. Запретить/перевыпустить OAuth state и проверить логи необычных callback’ов (SEC-02).
3. Подтвердить, что prod работает за HTTPS с `SESSION_SECURE_COOKIE=true`, `TrustProxies` и валидным `APP_KEY` (TEST-04/09).
4. Поднять мониторинг scheduler/queue/Reverb и сделать restore drill (TEST-02/03).
5. **Не начинать исправления без явного подтверждения пользователя**; коммит/push не выполнять.
