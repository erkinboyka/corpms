# Production-readiness: чеклист и инструкции

Статус на 23.11.2026: **НЕ готов к production, оценка 8/10**. Этот документ — подготовка к выпуску, а не разрешение на деплой. Реальное production-окружение не изменялось.

## Обозначения

- **Confirmed** — подтверждено локально/в коде; есть `file:line` или локальный прогон.
- **Target-verification** — нужно подтвердить на целевом хосте до публичного релиза.
- **Not tested** — данных нет; не считать выполненным.

## 1. Go / No-go

| Блок | Статус | Что должно быть подтверждено | Источник |
|---|---|---|---|
| Автотесты и стиль | Confirmed локально | Полный прогон 642/642, Pint и `git diff --check` зелёные; локальные результаты не заменяют staging | `docs/IMPROVEMENT_PLAN.md:84-90` |
| Preflight | Confirmed по коду | Команда читает только `config()`, не печатает значения секретов; `--json` разрешён | `app/Console/Commands/CorpmsPreflight.php:12-13,17-19,47-52` |
| Preflight — ключ/HTTPS/сессия/proxy | Confirmed тестами | Пустой `APP_KEY`, http `APP_URL`, insecure cookie, `TRUSTED_PROXIES=*`, Boost в prod должны падать | `tests/Feature/CorpmsPreflightTest.php:90,102,113,124,135,149` |
| Доверенные proxy | Confirmed тестами | Доверять только `X-Forwarded-For/Proto/Port`; `X-Forwarded-Host/Prefix` не влияют на URL | `config/trustedproxy.php:5-10`, `tests/Feature/TrustedProxyHttpTest.php:38-45,48,71` |
| Логи | Confirmed по коду | `LOG_LEVEL=info`, `RedactContextProcessor` подключён к basic/daily/stack и другим каналам | `config/logging.php:69,76,82,90` |
| AI egress | Confirmed по коду | Whitelist хостов, private/loopback/link-local после DNS запрещены | `app/Support/AiEndpointGuard.php:30,73,139`, `config/corpms.php:63-78` |
| OCR/RAG | Confirmed локально | Tesseract + Poppler установлены локально; скан-PDF/изображения/DOCX-картинки распознаются; без бинарников скан — `unsupported` | `app/Domains/Ai/Services/OcrEngine.php:36,44,57,106,197`, `app/Domains/Ai/Services/AttachmentTextExtractor.php:40,137,209`, `docs/IMPROVEMENT_PLAN.md:89` |
| Ролевой Dashboard | Confirmed локально | Метрики по правам, пустые данные, mobile/keyboard, permission override; роли `owner`/`accountant` не выдумывались | `app/Domains/Analytics/Services/DashboardMetricsService.php:20,37,114`, `tests/Feature/Web/DashboardRoleMetricsTest.php:49,63,83,94`, `docs/IMPROVEMENT_PLAN.md:90` |
| Релиз-процедура | Target-verification | Миграции, config/view cache, queue:restart, health-check на staging | `docs/OPERATIONS.md:14-22` |
| Backup и restore | Target-verification | Off-site копия, контрольные суммы, restore drill и замеры RTO/RPO | `docs/OPERATIONS.md:29-47`, `docs/RESTORE_DRILL.md:25-30` |
| Процессы | Target-verification | Scheduler timer, worker, при необходимости Reverb; перезапуск и алерты | `deploy/README.md:9-16`, `deploy/monitoring/CHECKLIST.md:6-15` |
| OCR на prod | Target-verification | Бинарники установлены на целевом хосте, языки доступны, качество на реальных сканах оценено | `.env.example:116-123`, `config/corpms.php:83-91` |
| Google OAuth | Target-verification | Полный authorization-code flow; ранее раскрытый секрет ротирован | `.env.example:57-61` |
| Telegram | Target-verification | Токен/секрет заданы, webhook заново зарегистрирован, реальная доставка проверена | `.env.example:102-105`, `docs/OPERATIONS.md:51-53` |
| Видимость аудит-отчётов | **Confirmed риск**: репозиторий `public`, в git 14 файлов `AUDIT_REPORT/` | Сделать репозиторий приватным или убрать отчёты из публичного доступа; без этого детали уязвимостей доступны всем | `AUDIT_REPORT/01_EXECUTIVE_SUMMARY.md:1`, `git ls-files AUDIT_REPORT` |
| Нагрузка prod-копии, RTO/RPO, fault injection | Not tested | Пороги, egress-файрвол ОС, отказ сервисов и реальное восстановление не проверялись | `docs/RESTORE_DRILL.md:25-30`, `docs/OPERATIONS.md:61-62` |

**No-go остаётся**, пока target-verification пункты не имеют факта: дата, ответственный, команда/вывод. Оценка **8/10** не повышается только фактом написания этого чеклиста. Дополнительный no-go: репозиторий публичный, а `AUDIT_REPORT/` уже есть в git.

## 1.1. Локальные результаты 23.11.2026

Эти проверки выполнены в рабочей директории, **не на production**:

| Проверка | Результат |
|---|---|
| `php artisan test --do-not-cache-result` | 642/642 PASS, 4416 assertions |
| `php vendor/bin/pint --test` | PASS |
| `git diff --check` | OK |
| `php artisan corpms:preflight --json --skip-config-cache` | exit 1, ожидаемо для локального окружения: `environment=local`, `debug` включён, `APP_URL` http, session/proxy не prod- hardened. `app_key`, `database`, `cache`, `queue`, `filesystem`, `config_cache`, `dev_tools`, `integrations` — ok |
| `php artisan corpms:health --json` | exit 1: локальная MySQL ожидает миграции `2026_11_23_000001/000002/000003`; queue 5, failed 0, storage ok |
| OCR в PATH нового shell | `tesseract`/`pdftoppm` не находятся; по абсолютным путям работают: Tesseract `5.5.3.20260724` (`eng/osd/rus`), Poppler `26.09.0` |
| GitHub API `https://api.github.com/repos/erkinboyka/corpms` | `visibility: public`; `git ls-files AUDIT_REPORT` — 14 файлов |

Локальные миграции не применялись: чеклист требует проверки миграций на staging с обезличенными данными, а не изменения локальной БД. Preflight и health локально не являются доказательством prod-готовности.

## 2. Переменные окружения без секретов

Значения ниже — шаблон. Настоящие секреты брать из защищённого хранилища и **не** писать в чат, доки, `.env` в git, логи или вывод команд.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.invalid
APP_KEY=<из секретного хранилища, 32 байта>

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true

TRUSTED_PROXIES=<IP/CIDR доверенного reverse proxy, через запятую>   # никогда не "*"
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log    # или reverb + REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME

DB_CONNECTION=<mysql|sqlite>
BACKUP_PATH=<вне public/ и вне web-root>

AI_BASE_URL=<https://разрешённый LLM-хост>
AI_ALLOWED_HOSTS=<разрешённые хосты через запятую>
AI_API_KEY=<секрет из хранилища>
AI_EMBEDDINGS_MODEL=<модель /embeddings>
AI_RAG_TESSERACT_PATH=<абсолютный путь к tesseract>
AI_RAG_PDFTOPPM_PATH=<абсолютный путь к pdftoppm>
AI_RAG_OCR_LANG=eng+rus
AI_RAG_OCR_DPI=200
AI_RAG_OCR_PSM=6
AI_RAG_OCR_MAX_PAGES=10
AI_RAG_OCR_TIMEOUT=120
```

Источники ключей: `.env.example:1-8,22-27,36-48,50-55,94-123`, `config/corpms.php:63-91`.

## 3. Установка OCR на целевом хосте

### Windows — локально проверенный вариант

```powershell
winget install --id tesseract-ocr.tesseract --exact --accept-package-agreements --accept-source-agreements
winget install --id oschwartz10612.Poppler --exact --accept-package-agreements --accept-source-agreements
tesseract --version
tesseract --list-langs
pdftoppm -v
```

Ссылки:

- Tesseract: https://github.com/tesseract-ocr/tesseract/releases/latest
- точный установщик: https://github.com/tesseract-ocr/tesseract/releases/download/5.5.3/tesseract-ocr-w64-setup-5.5.3.20260724.exe
- Poppler: https://github.com/oschwartz10612/poppler-windows/releases
- точный архив: https://github.com/oschwartz10612/poppler-windows/releases/download/v26.09.0-0/Release-26.09.0-0.zip

`OcrEngine` ищет бинарники в `PATH`, стандартных путях Windows и через winget package glob. Если автоопределение не сработало, задать `AI_RAG_TESSERACT_PATH` и `AI_RAG_PDFTOPPM_PATH` явно. Проверка локально: Tesseract `5.5.3.20260724`, языки `eng/osd/rus`, Poppler `26.09.0-0`.

### Debian/Ubuntu — пример, на целевом хосте не проверялся

```bash
sudo apt-get update
sudo apt-get install -y tesseract-ocr tesseract-ocr-rus poppler-utils
tesseract --version
tesseract --list-langs
pdftoppm -v
```

После смены OCR-настроек переиндексировать документы:

```bash
php artisan ai:index-documents --company=<id>
# только при изменении поколения извлечения/OCR:
php artisan ai:index-documents --company=<id> --force
```

`--force` поднимает поколение индекса и переиндексирует все документы компании: `app/Console/Commands/IndexAiDocuments.php:19-21,35`.

## 4. Порядок выпуска на staging

1. Подготовить backup БД, вложений и `APP_KEY`; подтвердить доступность копий: `docs/OPERATIONS.md:16,29-47`.
2. Установить зависимости и выполнить миграции:

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan view:cache
   php artisan queue:restart
   ```

   Порядок — по `docs/OPERATIONS.md:18-19`; при несовместимой схеме сначала `php artisan down` (`docs/OPERATIONS.md:17`).

3. Preflight:

   ```bash
   php artisan corpms:preflight --json
   php artisan corpms:health --json
   ```

   `--skip-config-cache` использовать только в CI, где config cache не строится: `app/Console/Commands/CorpmsPreflight.php:17-19,131-138`. Preflight не печатает значения секретов: `app/Console/Commands/CorpmsPreflight.php:12-13`.

4. Smoke-проверки на выделенных проверочных данных: вход, права, чтение/запись документа, задача, обработка очереди, дашборд под разными ролями, OCR скан-PDF: `docs/OPERATIONS.md:20`.

5. Подтвердить перезапуск и мониторинг процессов: `deploy/README.md:9-16`, `deploy/monitoring/CHECKLIST.md:6-15`.

6. `php artisan up`, затем наблюдать 5xx, backlog, failed_jobs, backup heartbeat: `docs/OPERATIONS.md:21,57-59`.

## 5. Что нужно заполнить до перевода в «готов»

- [ ] Дата, ответственный и вывод `corpms:preflight --json` на staging/prod-конфигурации.
- [ ] Фактические `TRUSTED_PROXIES` и проверка, что это не `*`; только разрешённые `X-Forwarded-*`.
- [ ] Подтверждённая установка OCR на prod-хосте и результат на реальном скан-PDF.
- [ ] Внешняя копия backup + заполненный `docs/RESTORE_DRILL.md` с RTO/RPO.
- [ ] Перезапуск scheduler/worker/Reverb и срабатывание алертов.
- [ ] Ротация ранее раскрытого Google secret и проверка полного OAuth flow.
- [ ] Telegram: реальная доставка сообщения и заново зарегистрированный webhook.
- [ ] Решение по публичности `AUDIT_REPORT/`.
- [ ] Нагрузочная проверка на копии prod-данных и согласованные SLO.

Пока любой пункт не заполнен, вердикт остаётся **8/10 — НЕ готов к production**.
