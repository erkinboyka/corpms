# 13. Post-audit drills и локальная имитация staging

Дата: 2026-11-22. Режим: локальные изолированные проверки. Production-данные не изменялись; значения реальных секретов никуда не выводились (проверялся только факт наличия и сетевой ответ). Коммиты/push не выполнялись.

## 13.1. Staging reverse-proxy (локальная имитация)

| Проверка | Результат | Доказательство |
|---|---|---|
| HTTP → HTTPS redirect | **Confirmed** | Nginx 1.26 слушал `127.0.0.1:8080`; `GET /` → `301`, `Location: https://127.0.0.1:8443/`. |
| HSTS | **Confirmed** | `GET https://127.0.0.1:8443/login` → `Strict-Transport-Security: max-age=63072000; includeSubDomains`. Заголовок выставляется только при `Request::isSecure()` (`app/Http/Middleware/SecurityHeaders.php:61-63`). |
| Secure cookie | **Confirmed** | `Set-Cookie: XSRF-TOKEN=…; secure; samesite=lax` и `corpms-session=…; secure; httponly; samesite=lax`. Режим запуска: `SESSION_SECURE_COOKIE=true`. |
| Доверие только `X-Forwarded-For/Proto/Port` | **Confirmed** | `bootstrap/app.php:29-32` задаёт маску заголовков; `config/trustedproxy.php:10` читает `TRUSTED_PROXIES`; прогон с `TRUSTED_PROXIES=127.0.0.1`. Nginx дополнительно посылал `X-Forwarded-Host: evil.example` — значение не принимается (`tests/Feature/TrustedProxyHttpTest.php:71-89`). |
| Client IP в audit | **Confirmed** | `TrustedProxyHttpTest::test_failed_login_uses_forwarded_client_ip_for_audit` — `203.0.113.10` попадает в `audit_logs.ip` (`tests/Feature/TrustedProxyHttpTest.php:91-104`). |
| Preflight в prod-подобном окружении | **Confirmed** | `APP_ENV=production APP_DEBUG=false APP_URL=https://… SESSION_SECURE_COOKIE=true TRUSTED_PROXIES=127.0.0.1 php artisan config:cache && php artisan corpms:preflight --json` → `ok=true`, 13/13 проверок. |

Стенд: self-signed сертификат `CN=127.0.0.1`, Nginx → `php artisan serve` на `127.0.0.1:8100`. Конфигурация и сертификат находятся только во временном каталоге, в репозиторий не добавлены.

## 13.2. Backup / restore drill

| Проверка | Результат | Доказательство |
|---|---|---|
| Восстановление БД в изолированную БД | **Confirmed** | `php tools/verify-backup.php storage/app/backups/mysql_2026_11_22_051907_365648_922e68.sql C:/OSPanel/modules/MySQL-8.2/bin/mysql.exe` → `Isolated MySQL backup restoration: passed`. |
| Контрольные количества | **Confirmed** | `companies`, `employees`, `documents`, `tasks`, `fin_payments`, `stock_movements` — совпали с источником. |
| Бизнес-итоги | **Confirmed** | `SUM(fin_payments.amount)` и `SUM(stock_movements.quantity)` совпали с источником. |
| Изоляция | **Confirmed** | Скрипт создал временные БД/пользователя с правами только на эту БД и удалил их в `finally` (`tools/verify-backup.php:18-81`). |
| Вложения | **Confirmed** | `php artisan files:verify storage/app/backups/files_20261118_211326_29a7282938.zip --json` → `ok=true`, `files=806`, `bytes=1192921`, восстановлено в изолированную папку. |
| Off-site копия | **Not tested** | Внешнее хранилище/учётки не предоставлены; выполнена только локальная изолированная проверка контрольных сумм. |
| RTO/RPO под нагрузкой | **Not tested** | Замеры на prod-подобном объёме не проводились. |
| Перезапуск scheduler/worker/Reverb и алертов | **Not tested** | Целевая инфраструктура недоступна. |

## 13.3. Конкурентность и rollback на MySQL

`php tools/verify-concurrency.php` (создаёт и удаляет только свою временную БД `corpms_verify_*_test`):

```
receive: passed (one accepted, one rejected; balance=10)
stock: passed (one accepted, one rejected; balance=3)
payment: passed (one accepted, one rejected; balance=80)
migration rollback/reapply: passed
```

Confirmed для локального MySQL 8.2. Нагрузочные тесты и конкуренция на production-копии — Not tested.

## 13.4. Egress / LLM / реальные провайдеры

| Проверка | Результат | Доказательство |
|---|---|---|
| Whitelist хостов, запрет private/loopback/link-local, без redirects | **Confirmed (код/тесты)** | `tests/Feature/Web/AiEndpointSafetyTest.php`, `tests/Feature/HardeningRegressionTest.php` — 33/33 PASS, 107 assertions. |
| Google OAuth state/PKCE | **Confirmed (симуляция)** | `GoogleOAuthStateTest` — входит в 19/19 PASS, 67 assertions вместе с Telegram/Notifications. |
| Telegram webhook secret header | **Confirmed (симуляция)** | `TelegramWebhookSecurityTest`, `NotificationsTest` — те же 19/19 PASS. Http::fake, реальный Telegram не вызывался. |
| Реальный LLM-провайдер | **Confirmed (транспорт/авторизация)** | GET `{AI_BASE_URL}/models` с ключом из конфигурации → `http=200`, `json=yes`, `models=11`. Хост, ключ и тела ответа в отчёт не включены; генерация/запись в БД не выполнялась. |
| Реальный Google OAuth | **Suspected** | `GOOGLE_CLIENT_ID/SECRET` в `.env` заданы, форма client_id выглядит корректно, discovery-эндпоинт Google отвечает `200`; полный authorization-code flow не прогонялся (нужен интерактивный вход пользователя). Ранее раскрытый secret требует ротации — отдельный внешний шаг. |
| Реальный Telegram | **Not tested** | `TELEGRAM_BOT_TOKEN` / `TELEGRAM_WEBHOOK_SECRET` в окружении не заданы; значения не выдумывались. |
| Egress-файрвол ОС | **Not tested** | Глобальные правила Windows Firewall не изменялись во избежание сбоев других сервисов хоста; подтверждён только application-level guard. |

## 13.5. Frontend/CI зависимости

Установлено из `%USERPROFILE%\Downloads`: Node.js `v26.13.1` / npm `11.20.0`, Composer `2.11.2`, Playwright `1.65.2` + Chromium.

| Команда | Результат |
|---|---|
| `npm install --package-lock-only --ignore-scripts --no-audit --no-fund` | lockfile создан |
| `npm ci` | 55 пакетов, 0 уязвимостей |
| `npm run build` (Vite 8.3.8) | `✓ built in 2.72s` |
| `npm audit --audit-level=high` | `found 0 vulnerabilities` |
| `composer audit --locked` | `No security vulnerability advisories found` |

`package-lock.json` создан, но не закоммичен.

## 13.6. Браузерный smoke (Playwright + axe)

Первый запуск выявил три дефекта, все исправлены локально:

1. **Онбординг-ловушка в фикстуре:** demo-компания не была отмечена как онборденная, поэтому после входа вместо `/dashboard` открывался `/onboarding`, и smoke падал по таймауту. Исправлено в `tools/acceptance-environment.php:34-36`.
2. **Критический `button-name`** на `/inventory` и `/purchases`: сгенерированный `crm-select__trigger` терял accessible name скрытого `<select>` с `<label>`. Исправлено в `public/assets/js/crm-select.js:48-59`, версия ассета поднята в `resources/views/layouts/tabler.blade.php:64`.
3. **Разноречивые `@view-transition`:** `effects.css` задавал `navigation: none`, `motion.css` — `navigation: auto`; при быстрой навигации в Chrome возникала ошибка `Transition was aborted`. Оставлено одно объявление в `public/assets/css/effects.css:372`; `public/assets/css/motion.css:354-359` теперь только синкает анимации. Контраст тёмной темы на `/dashboard` дополнительно закреплён непрозрачным фоном панелей (`public/assets/css/dashboard.css:148-158,356-359`).

Финальный прогон: `python tools/verify-browser.py <fixture> --load` → `pages=48`, `failures=0`, `javascript_errors=[]`, `load.requests=200`, `load.errors=0`. Подтверждено на двух движках: системный Chrome (`p95≈1678 мс`) и bundled Chromium 1255 (`BROWSER_EXECUTABLE=…chromium-1255\chrome-win64\chrome.exe`, `p95≈1506 мс`) — оба с 0 ошибок. Установлены также headless shell и FFmpeg Playwright. Скриншоты и `report.json` — в `storage/logs/browser-acceptance/` (игнорируется Git).

Локальный лог Nginx содержит ожидаемый шум от Echo/Pusher: `GET /app/<key>?protocol=7&client=js&version=8.4.0` → `301` на HTTPS и последующие `400` на TLS-хендшейк, адресованные HTTP-порту 8080. Причина — локальные `REVERB_*` указывают на staging-прокси, где отдельный Reverb-сервер не поднят; это **не** дефект приложения и не влияет на результат smoke (JS-ошибок нет, consumers работают на polling — `public/assets/js/bm-realtime.js:34-36`). Сетевая конфигурация Reverb на целевом хосте остаётся **Not tested**.

Временные процессы стенда (Nginx `8080/8443`, `php artisan serve` на `8100`) после drill'ов остановлены; конфигурация и сертификат остаются только во временном каталоге.

## 13.7. Итог

Локально подтверждены: proxy/HTTPS/Secure cookie/HSTS, client IP в rate limiting/audit, preflight, restore БД и вложений, конкурентность/rollback, application-level egress guard, реальный LLM-эндпоинт, frontend build, dependency audit и браузерный smoke (48 страниц, axe WCAG 2.0/2.1 A/AA).

Остаются **Not tested / неполные внешние факты**: полный OAuth-флоу Google и ротация его секрета, реальный Telegram (нет токенов), off-site копия, RTO/RPO, перезапуски сервисов, egress-файрвол ОС, нагрузка на production-копии. Поэтому общий вердикт в `01_EXECUTIVE_SUMMARY.md` остаётся «НЕ готов к production» до внешних подтверждений.
