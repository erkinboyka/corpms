# 11 — Тестирование и production readiness

Статус: **завершено (статический аудит + безопасные локальные проверки)**. Тесты выполнялись только в локальном/тестовом окружении; production-данные не изменялись. Секреты в отчёт не включены.

## 1. Фактически выполненные проверки

| Проверка | Команда / источник | Результат |
|---|---|---|
| Полный PHPUnit-прогон | `php artisan test` | **473 теста, 473 passed, 3541 assertion, 227,3 с** — PASS |
| Синтаксис PHP | `php -l` по 443 файлам в `app`, `bootstrap`, `config`, `database`, `routes`, `tests` | **0 ошибок** |
| Стиль PHP | `php vendor/bin/pint --test` | **FAIL: 210 файлов**; доминируют `line_ending` (173), `braces_position` (74), `single_line_empty_body` (68), `not_operator_with_successor_space` (52) |
| Frontend build / npm audit | `npm` недоступен, `node_modules` отсутствует | **Не выполнялось** |
| Коммерческий dependency audit | CI содержит `composer audit --locked` (`.github/workflows/ci.yml:70`); локально подтвердить нельзя (composer CLI не найден) | **Частично** |
| Браузерный smoke в рамках аудита | В консоли разработки выполнялся ранее для OAuth; полный UI smoke этим аудитом не заявлен | **Ограниченно** |

Вывод: локальный набор тестов зелёный, но **качество кода и ops-обвязка не проходят quality gate**: Pint выявляет 210 файлов, frontend-сборка не проверена, а инфраструктурные артефакты Deployment/DR отсутствуют.

## 2. Материалы тестирования

- 59 файлов тестов: 56 `tests/Feature`, 2 `tests/Unit`, 1 `tests/TestCase.php`.
- 449 тестовых методов, ~2514 assertion по статическому подсчёту; фактический прогон дал больше за счёт data-driven/динамических вызовов — 473/3541.
- 52 файла используют `RefreshDatabase`; 12 файлов фейкают внешние зависимости (`Http`, `Storage`, `Queue`, `Notification`).
- Data providers не найдены.
- `.phpunit.result.cache` **устарел и противоречив**: 499 записей времени, 383 defects, включая несуществующие классы `Tests\Feature\Web\TempDebugTest`, `Tests\Feature\Web\DebugOldTest`, `Tests\Feature\Workflow\TempLeaveHttpReproTest`. Файл не является доказательством текущего состояния; для надёжного CI нужно использовать `--do-not-cache-result`.

## 3. Найденные проблемы

### TEST-01 — High — CI / доказательная база
- **Evidence:** `.github/workflows/ci.yml:46-62` — PHP syntax, PHPUnit, concurrency, encoding/layout, upload JUnit; в репозитории нет артефакта успешного run. `.phpunit.result.cache` содержит 383 defects и несуществующие тесты.
- **Impact:** невозможно подтвердить, что green local run совпадает с CI; stale cache создаёт ложное впечатление провалов/успеха.
- **Fix:** обязательный green CI run с публикацией `storage/logs/ci-tests.xml`; `phpunit --do-not-cache-result`; удалить stale cache из рабочего дерева/игнорируемых артефактов.
- **Verification:** ссылка на успешный GitHub Actions run + свежий JUnit-артефакт для sqlite и mysql.
- **Status:** Confirmed (локальный прогон зелёный; подтверждения CI нет).

### TEST-02 — High — Backup / Restore
- **Evidence:** `docs/OPERATIONS.md:41-47` — локальная MySQL-копия и 806 файлов восстановлены, но `«Восстановление вне исходного хоста, доставка копий во внешнее хранилище и RPO/RTO под рабочей нагрузкой остаются непроверенными»`; `tools/verify-backup.php:31-33` требует `CREATE DATABASE`/`CREATE USER`/`GRANT ALL`.
- **Impact:** backup-копии создаются, но пригодность к восстановлению на другой хост и целевые RPO/RTO не подтверждены.
- **Fix:** регулярный изолированный restore-drill: копия БД + вложений + APP_KEY, импорт в отдельную БД, `PRAGMA integrity_check`/SHA-256, сверка контрольных сумм и сумм платежей, фиксация RTO/RPO, off-host доставка.
- **Verification:** сохранённый отчёт drill с датой, владельцем и метриками; алерт при отсутствии свежей проверки.
- **Status:** Confirmed (частично закрыто локально).

### TEST-03 — High — Процессы в эксплуатации
- **Evidence:** `docs/OPERATIONS.md:53-55` требует scheduler каждую минуту, worker с автоперезапуском, мониторинг failed_jobs/backlog; `routes/console.php:8-23` объявляет 5 задач; `composer.json:11` — `laravel/reverb`. В репозитории нет supervisor/systemd/crontab/Procfile/Dockerfile.
- **Impact:** после рестарта хоста scheduler, queue worker и Reverb могут не подняться; backup, overdue-задачи, AI retry и health-алерты тихо остановятся.
- **Fix:** добавить deploy-артефакты (supervisor/systemd/cron), heartbeat scheduler/worker/Reverb, алерты по failed_jobs и возрасту задачи.
- **Verification:** тест перезапуска процессов; метрика heartbeat; алерт при остановке.
- **Status:** Confirmed.

### TEST-04 — High — Transport / cookies / trusted proxy
- **Evidence:** `.env.example:40-41` — `SESSION_SECURE_COOKIE=true` закомментирован; `config/session.php:172` читает переменную; `tests/Feature/SecurityHeadersHttpTest.php:51-57` проверяет `httponly`/`samesite`, но не `Secure`; `bootstrap/app.php:22-38` без `TrustProxies`; `SecurityHeaders.php:61-62` ставит HSTS только при `$request->isSecure()`.
- **Impact:** за reverse proxy приложение может не видеть HTTPS: кука без `Secure`, нет HSTS, неверный client IP в rate-limiting и audit.
- **Fix:** `TRUSTED_PROXIES`/`TrustProxies`, `SESSION_SECURE_COOKIE=true`, `URL::forceScheme('https')` в prod, integration-тест с `X-Forwarded-Proto`.
- **Verification:** проверка заголовков `Set-Cookie`, HSTS и redirect на staging за proxy.
- **Status:** Confirmed.

### TEST-05 — Medium — Quality gates в CI
- **Evidence:** `.github/workflows/ci.yml:46-51,70` — syntax/PHPUnit/concurrency/composer audit; `:44,88` `coverage: none`; Pint и npm не вызываются. Локально `pint --test` FAIL: 210 файлов; npm недоступен.
- **Impact:** стиль, frontend-сборка, npm-уязвимости и просадка покрытия не ловятся автоматически.
- **Fix:** обязательные job `pint --test`, `npm ci && npm run build && npm audit --audit-level=high`, coverage report/порог, `migrate --force` на чистой БД.
- **Verification:** новые job зелёные в том же CI run.
- **Status:** Confirmed.

### TEST-06 — Medium — Изоляция внешних интеграций в тестах
- **Evidence:** `tests/Feature/Web/AiRagRetrievalTest.php:112-127`, `tests/Feature/Notifications/NotificationsTest.php:72-100` используют `Http::fake`; `Http::preventStrayRequests()` не найден.
- **Impact:** случайные сетевые вызовы в тестах не блокируются; реальный контракт провайдера не проверяется.
- **Fix:** добавить `Http::preventStrayRequests()` в базовый `TestCase`, opt-in контрактные тесты с fixtures.
- **Verification:** CI зелёный без сети + отдельный отчёт контрактной проверки.
- **Status:** Confirmed.

### TEST-07 — Medium — Не покрытые негативные сценарии
- **Evidence:** `tests/Feature/CorpmsHealthTest.php:44-113` — только clean/failed jobs/backlog/отсутствующие каталоги; `app/Console/Commands/CorpmsHealth.php:70-100` проверяет БД и миграции; `tests/Feature/BackupDatabaseCommandTest.php:34-43,63-77` — только SQLite; `app/Console/Commands/BackupDatabase.php:126-160` содержит MySQL-путь.
- **Impact:** отказ БД, pending migrations, пустой mysqldump, устаревший rotated backup могут не быть пойманы.
- **Fix:** негативные тесты с mock PDO/миграцией, `Process`-mock для `mysqldump`, проверка `MYSQL_PWD`, пустого дампа и ротации.
- **Verification:** новые тесты в `CorpmsHealthTest` и `BackupDatabaseCommandTest` для sqlite/mysql.
- **Status:** Confirmed.

### TEST-08 — Medium — Scheduler не покрывается тестами
- **Evidence:** `tests/Feature/BackupDatabaseCommandTest.php:115-120` проверяет только `db:backup`; `routes/console.php:8-23` содержит `attendance.detect-missing-checkout`, `tasks.mark-overdue`, `db.backup`, `files.backup`, `ops.health`.
- **Impact:** случайное изменение расписания не ловится.
- **Fix:** `ScheduleTest` на все 5 имён, cron-выражений и `withoutOverlapping()`.
- **Verification:** тест падает при изменении любого расписания.
- **Status:** Confirmed.

### TEST-09 — Medium — Secret management / preflight
- **Evidence:** `.env.example:3,50-53` — `APP_KEY=` пустой, Google OAuth переменные закомментированы; `config/corpms.php:36-43,50-66` использует `TELEGRAM_*`, `AI_*`, `REVERB_*`, но шаблон их не описывает; `config/app.php:102-108` — ключ и previous keys.
- **Impact:** нет автоматической проверки обязательных env, длины APP_KEY и свежести `config:cache`.
- **Fix:** команда `corpms:preflight` (APP_ENV, APP_KEY 32 bytes, обязательные env, HTTPS/cookies, config cache), дополнить `.env.example` всеми используемыми переменными без значений.
- **Verification:** негативный тест с пустым APP_KEY и успешный прогон на staging.
- **Status:** Confirmed.

### TEST-10 — Medium — Логирование ПДн
- **Evidence:** `.env.example:24` — `LOG_LEVEL=debug` с предупреждением в комментарии; `config/logging.php:64,71` default `debug`; `app/Domains/Notifications/Services/NotificationService.php:63,97-100` логирует `employee_id`.
- **Impact:** при 14 днях ротации в логи попадают идентификаторы сотрудников и контекст запросов.
- **Fix:** `LOG_LEVEL=info` в prod-шаблоне, redaction-processor, агрегированные correlate-id вместо ПДн.
- **Verification:** тест отсутствия ПДн/токенов в log record context.
- **Status:** Suspected (утечка ПДн зависит от реального prod `LOG_LEVEL`).

### TEST-11 — Low — Качество тестов
- **Evidence:** 0 data providers; `phpunit.xml:2-6` без `failOnRisky`, `failOnWarning`, `beStrictAboutTestsThatDoNotTestAnything`; `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` — skeleton.
- **Impact:** ложное впечатление покрытия; тесты без assertion формально проходят.
- **Fix:** data providers для матриц прав/валют/дат, strict flags, удалить Example-тесты.
- **Verification:** PHPUnit отчёт без risky/warning.
- **Status:** Confirmed.

### TEST-12 — Low — Repo hygiene / docs drift
- **Evidence:** debug-скрипт с `SHOW TABLES` в корне был удалён при гигиене (`FIX_PROGRESS.md:87`; исходная ссылка на удалённый файл не проверяема); `docs/AUDIT_REPORT_2026-11-19.md:98` устарел относительно текущего `.github/workflows/ci.yml`; `README.md:34` и `docs/IMPROVEMENT_PLAN.md:23` расходятся в статусе CI; `boost.json`, `.osp/`, `.codex-tmp/`, `bm-workspace/` — служебные каталоги.
- **Impact:** устаревшие отчёты и debug-артефакты увеличивают риск утечки и мешают оценке готовности.
- **Fix:** пометить старые docs как archived, удалить `tmp_probe.php` и временные артефакты, оставить один актуальный OPERATIONS/IMPROVEMENT_PLAN.
- **Verification:** чистый `git status`, отсутствие debug-скриптов вне `tools/`, актуальная ссылка на CI.
- **Status:** Confirmed.

## 4. Отсутствующие тесты (приоритет)

**P0 / блокирующие релиз**
1. Prod preflight: APP_KEY length/rotation, APP_ENV=production, APP_DEBUG=false после `config:cache`, secure cookie, HTTPS redirect, trusted proxy.
2. Восстановление БД из backup в изолированную БД + APP_KEY, off-host копия, RTO/RPO.
3. Живучесть scheduler, queue worker, Reverb, failed_jobs, oldest job.
4. Негативные health-сценарии: БД недоступна, pending migrations, disk full, stale backup.

**P1**
5. MySQL `db:backup` path: argv `mysqldump`, `MYSQL_PWD`, empty dump failure, ротация `.sql`.
6. Все 5 scheduler-задач, cron-выражения и `withoutOverlapping`.
7. Конкурентные деньги/остатки в PHPUnit: повторная приёмка, отмена оплаты, параллельные списания (сейчас отдельный `tools/verify-concurrency.php` только в MySQL-CI).
8. Миграции вперёд/назад на копии prod-подобной БД.
9. Контракты Telegram/email/AI/embeddings и webhook-secret negative cases.

**P2**
10. Prompt-injection/RAG-negative, timeout/retry/backoff AI, отзыв прав в фоне.
11. Отсутствие ПДн в general logs, raw secret в HTML/audit.
12. Coverage report/threshold, удаление skeleton-тестов.

## 5. Итоговая оценка готовности к production

**Не готов без снятия TEST-01…TEST-04.**

Сильные стороны: широкая регрессия RBAC/tenant-изоляции, документов, AI, payroll/inventory; зелёный локальный прогон 473 теста. Слабые стороны: воспроизводимость deployment, backup/restore и мониторинг, TLS/trusted proxy, отсутствие Pint/npm gate в CI, устаревшие отчёты.
