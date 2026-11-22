# 02 — Архитектура и инвентаризация

Статус: **завершено (первичная инвентаризация)**. Источник: фактические файлы репозитория, `composer.lock`, `php artisan route:list --json`, `git ls-files`. Документация README не использовалась как доказательство.

## 1. Стек и версии

| Компонент | Факт | Источник |
|---|---|---|
| PHP | 8.3.6 (CLI, ZTS, Visual C++ 2019 x64) | `php -v` |
| Laravel framework | **v13.43.0** (composer.json требует `^13.17`) | `composer.lock` |
| Laravel Reverb | v1.14.0 | `composer.lock` |
| PHPUnit | 12.5.42 | `composer.lock` |
| Laravel Pint / Pail / Tinker | 1.32.1 / 1.2.7 / 3.0.2 | `composer.lock` |
| Frontend build | Vite 8, Tailwind CSS 4, laravel-vite-plugin 3.1 | `package.json` |
| БД | SQLite по умолчанию (`.env.example`: `DB_CONNECTION=sqlite`); в dev `.env` используется SQLite/MySQL-совместимый доступ — точный драйвер проверен ниже | `config/database.php`, `.env.example` |
| Очереди/кэш/сессии | `database` в `.env.example`; в `phpunit.xml` переопределены на `sync`/`array` | `.env.example`, `phpunit.xml` |
| WebSocket | Laravel Reverb; connection `reverb` описана, но драйвер по умолчанию — `null` | `config/broadcasting.php:18,33-47`, `config/reverb.php` |

Сторок в репозитории: **933**. Крупнейшие области: `app/` 309, `public/` 267 (из них 226 — vendored Tabler-ассеты), `resources/` 145, `tests/` 59, `database/` 53, `lang/` 19, `tools/` 17, `docs/` 16, `config/` 15, `routes/` 4.

## 2. Структура backend

- `app/Models/` — **107** Eloquent-моделей.
- `app/Domains/` — **39** PHP-файлов, сгруппированных по 20 доменам: Accounting, Ai, Analytics, Attendance, Audit, Bi, Biometric, Chat, Crm, Documents, Finance, Inventory, Lms, Notifications, Payroll, Projects, Tasks, Workflow и др.
- `app/Http/Controllers/` — **58** контроллеров; `app/Http/Middleware/` — **8**.
- `app/Http/Middleware/`: `DeviceTokenAuth`, `EnsureActiveEmployee`, `EnsureOnboarded`, `EnsurePermission`, `EnsureTenantIsolation`, `NoCacheHtml`, `SecurityHeaders`, `SetLocale`.
- `app/Support/`: `AssistantMarkdown`, `AvatarInitials`, `CertificateService`, `EntityPhotoService`, `EnumLabels`, `HtmlSanitizer`, `PermissionService`, `PhoneHelper`, `QrCodeGenerator`, `SettingsService`, `Sidebar`, `TenantGuard`, `TwoFactorService`.
- `app/Console/` — 7 файлов (команды `db:backup`, `files:backup`, `corpms:health` и др. — точный перечень см. ниже).

### Глобальный middleware (`bootstrap/app.php:22-39`)

- Глобально добавлен `SecurityHeaders` (`bootstrap/app.php:29`).
- В web-группу добавлены `NoCacheHtml`, `EnsureActiveEmployee`, `SetLocale`, `EnsureOnboarded`, `EnsureTenantIsolation` (`bootstrap/app.php:31-38`).
- Alias: `permission` → `EnsurePermission`, `device.token` → `DeviceTokenAuth` (`bootstrap/app.php:23-26`).
- Исключения рендерят JSON для `api/*` или `expectsJson()` (`bootstrap/app.php:40-43`).

### Маршруты

`php artisan route:list --json` → **314** маршрутов:

| Метод | Кол-во |
|---|---|
| POST | 146 |
| GET\|HEAD | 126 |
| DELETE | 18 |
| PATCH | 12 |
| PUT | 9 |
| catch-all (GET\|HEAD\|POST\|PUT\|PATCH\|DELETE\|OPTIONS\|QUERY) | 2 |
| GET\|POST\|HEAD | 1 |

Крупнейшие префиксы: `lms` 28, `crm` 20, `chat` 19, `documents` 18, `tasks` 17, `finance` 16, `inventory` 13, `projects` 12, `accounting` 12, `payroll` 11, `attendance` 11, `biometrics` 10, `purchases` 8, `profile` 8, `bi` 7, `employees` 6, `reports` 5, `schedules` 5, `api` 5, `imports` 4, `backups` 4, `ai` 4.

**30 маршрутов без middleware `auth`** (проверено по JSON route:list). Большинство ожидаемо публичные: `login`, `login/2fa`, `forgot-password`, `reset-password`, `auth/google*`, `landing`, `welcome`, `privacy`, `terms`, `cookies`, `up`, `sitemap.xml`, `broadcasting/auth`, `locale/{locale}`, `sign/{token}` (QR), webhook Telegram, device API с `device.token`, встроенные `storage/{path}`.

`storage/{path}` GET/PUT — **не кастомный код**, а встроенные маршруты `Illuminate\Filesystem\FilesystemServiceProvider` (`vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:83-112`). GET требует публичной visibility или валидной подписи; PUT требует `?upload=1` и валидной подписи (`vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php:35-45`, `ReceiveFile.php:27-38`). Конфиг: локальный диск `serve=true`, `visibility` по умолчанию private (`config/filesystems.php:33-39`); `public` disk использует симлинк `public/storage` (`config/filesystems.php:41-48,76-78`). Прямой анонимной загрузки нет.

### Файлы маршрутов

- `routes/web.php` — basic web UI + все доменные группы.
- `routes/device.php` — API биометрических устройств, RFID, kiosk, Telegram webhook;prefix `apiPrefix: ''` (`bootstrap/app.php:18-19`).
- `routes/channels.php` — broadcast-каналы `App.Models.User.{id}`, `chat.conversation.{conversationId}`, `employee.{employeeId}.chat`; проверка компании и `ChatService::assertCanView` (`routes/channels.php:16-35`).
- `routes/console.php` — scheduler: `attendance.detect-missing-checkout` ежедневно 08:30, `tasks.mark-overdue` hourly, `db:backup` 03:30, `files:backup` 03:40, `corpms:health` каждые 15 мин (`routes/console.php:8-23`).

## 3. Фронтенд

- **142** Blade-файла в `resources/views`.
- Шаблон:layout'а — `resources/views/layouts/tabler.blade.php` (Table UI, подключение CSS/JS с versioned query-параметрами).
- Статика: `public/assets/css/` — 15 файлов, `public/assets/js/` — 16 файлов, `public/assets/vendor/` — 226 файлов (Tabler).
- Vite/Tailwind присутствуют в `package.json`, но basic UI построен на серверных Blade + ванильном JS; production build будет проверен отдельно.

## 4. База данных

- **48** миграций в `database/migrations`.
- 107 моделей; связи и индексы будут разобраны в `04_DATABASE.md`.
- `phpunit.xml`: тесты используют `sqlite` `:memory:`, `RefreshDatabase`, `QUEUE_CONNECTION=sync`.
- В `.env.example` резервное копирование объявлено как `db:backup` + `files:backup`, `BACKUP_PATH` закомментирован.

## 5. Интеграции

1. **Google OAuth** — `GoogleAuthController` (`app/Http/Controllers/Auth/GoogleAuthController.php`), маршруты `auth/google` и `auth/google/callback` (`routes/web.php:74-75`), конфиг `services.google` (`config/services.php:38-41`).
2. **Telegram** — webhook `api/telegram/webhook/{secret}` + `TelegramService` (`app/Domains/Notifications/Services/TelegramService.php`).
3. **ИИ** — `AiAssistantService`, `AiPanelService`, `AiToolRegistry`, `Bumi*` инструменты, `DocumentRagService`, `RetryAiAnswer`; провайдер через `AI_BASE_URL`/`AI_API_KEY` в `.env`, в тестах обнуляются (`phpunit.xml:32-34`).
4. **Reverb/WebSocket** — `config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`.
5. **Биометрия/RFID** — device API в `routes/device.php`, `BiometricService`, `RfidService`, `DeviceTokenAuth`.
6. **Хранилище** — локальный `public` disk + симлинк; загрузка фото через `EntityPhotoService`.

## 6. RBAC (первичная инвентаризация)

- `app/Shared/Permissions/PermissionMatrix.php` — **121** разрешение (подсчёт по строкам матрицы), `database/seeders/RbacSeeder.php:214-217` — **7 системных ролей**: `super_admin`, `company_director`, `deputy_director`, `department_director`, `hr_manager`, `document_manager`, `employee`.
- Модель прав: `PermissionService::can()` возвращает `PermissionScope` (`own`/`department`/`company`) или `false`; `super_admin` получает `Company` безусловно (`app/Support/PermissionService.php:76-78`). Per-employee override **только расширяет** доступ (`PermissionService.php:99-106,210-220`).
- Объектный доступ combining scope + company: `canAccessEmployee()` (`PermissionService.php:150-166`), `scopeEmployees()` (`PermissionService.php:176-190`).
- Middleware `EnsurePermission` проверяет минимальный scope (`slug@scope`), `EnsureTenantIsolation` подключён после `SubstituteBindings`.

## 7. Тесты

- **59** тестовых файлов: `tests/Unit` и `tests/Feature`.
- В рамках аудита выполнен повторный полный прогон: **473 теста / 3541 assertion, все зелёные** (`11_TESTING.md:9`).
- Покрытие по доменам и отсутствующие тесты — в `11_TESTING.md`.

## 8. Потоки данных (высокий уровень)

```
Browser (Blade/Table)
  -> web middleware (SecurityHeaders -> NoCacheHtml -> auth -> permission -> EnsureActiveEmployee/SetLocale/EnsureOnboarded -> EnsureTenantIsolation)
  -> Controller (тонкий слой) -> Domain Service (бизнес-инварианты)
  -> Eloquent Model / Query Builder -> SQLite/MySQL
  -> AuditService / NotificationService / Broadcast (Reverb) / Jobs (database queue)
```

Публичные исключения: QR-подпись (`/sign/{token}` + POST с кодом), device API, Telegram webhook, OAuth callback.

## 9. Неиспользуемые/подозрительные артефакты (факты)

- `tmp_probe.php` в корне репозитория — автономный отладочный скрипт с `SHOW TABLES` и прямыми запросами к accounting-таблицам; не referenced приложением. Кандидат на удаление (проверка зависимостей будет в Этапе 9).
- `boost.json`, `.osp/`, `.codex-tmp/`, `bm-workspace/` — служебные/неотслеживаемые каталоги; в `git ls-files` не входят, в аудит кода не включены.
- `docs/` содержит 16 файлов предыдущих аудитов и дизайн-спецификаций; по правилу аудита не являются доказательством реализации.

## 10. Что ещё не охвачено на этом этапе

Точный список таблиц/связей, RBAC-матрица, полный перечень API-ответов, покрытие тестами по модулям и визуальная проверка экранов будут добавлены в последующие файлы по мере завершения модульных аудитов.
