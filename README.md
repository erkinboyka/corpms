# CorpMS / BM Systems

Корпоративная система: структура компании и сотрудники, задачи, посещаемость и терминалы, документы, зарплата, склад и закупки, CRM, финансы, бухгалтерия, проекты, BI и помощник.

PHP 8.3+, Laravel 13, Composer, PDO SQLite/MySQL, mbstring, XML/DOM, ZIP для копий вложений. Пользовательский интерфейс использует локальные ресурсы Tabler в `public/assets`; текущие Blade-layout не требуют Vite для запуска. `npm run build` относится к дополнительной Vite-сборке, если она используется в дальнейших изменениях.

## Локальный запуск

1. Выполнить `composer install`.
2. Создать `.env` из `.env.example`, настроить отдельную локальную БД и `APP_URL`.
3. Для новой установки выполнить `php artisan key:generate`. Существующий `APP_KEY` при обновлении сохранять: он нужен для расшифровки секретов.
4. Выполнить `php artisan migrate` для выбранной локальной БД.
5. Настроить web-root на `public/` или выполнить `php artisan serve`.
6. Для тестовой демонстрации в отдельной локальной БД: `php artisan db:seed --class=RbacSeeder`, затем `php artisan db:seed --class=DemoSeeder`. Демонстрационные учётки и пароли не предназначены для промышленной установки.
7. Необязательный идемпотентный BI-набор: `php artisan db:seed --class=BiDemoSeeder`. Повторный запуск не удаляет пользовательские правки и не трогает дашборд по умолчанию.

Первый администратор создаётся через `php artisan corpms:admin admin@example.org --company-code=main --company-name="Название компании"`. Команда запрашивает пароль скрытым вводом и не изменяет существующие учётки. Не запускать DemoSeeder на рабочих данных.

## Проверки

```text
php vendor/phpunit/phpunit/phpunit
pwsh -File tools/verify-encoding.ps1
pwsh -File tools/verify-layout.ps1
```

По умолчанию PHPUnit использует SQLite в памяти, массивы для cache/session и отключённые внешние AI-настройки. Не задавать рабочее подключение через переменные окружения при запуске тестов: внешние значения могут переопределить `phpunit.xml`. Для MySQL использовать только специально выделенную тестовую БД.

`php tools/verify-concurrency.php` использует настроенное MySQL/MariaDB-подключение только для создания отдельной случайно названной БД `corpms_verify_*_test`, выполняет миграции и проверки двух параллельных процессов, затем удаляет эту временную БД. Требует права CREATE/DROP DATABASE. Рабочие таблицы не являются целью проверки; перед миграциями проверяются имя подключения, конфигурация и `SELECT DATABASE()`.

`php tools/run-mysql-suite.php` создаёт отдельную временную MySQL-базу, выполняет весь PHPUnit-набор и удаляет только эту базу. Требует CREATE/DROP DATABASE; результат сохраняется в storage/logs/closure-mysql.xml.

Браузерная приёмка: установить Python-зависимости из `tools/requirements-qa.txt` и Chromium (`python -m playwright install chromium`), создать fixture командой `php tools/acceptance-environment.php`, затем `python tools/verify-browser.py <выведенный-путь> --load`. Используются только отдельная SQLite-база с искусственными данными, локальный HTTP-сервер и отключённые интеграции. PHP_BINARY и BROWSER_EXECUTABLE задают пути к PHP и браузеру; без BROWSER_EXECUTABLE на Linux используется Playwright Chromium. Скриншоты и отчёт — storage/logs/browser-acceptance.

CI проверяет PHP-синтаксис, тесты SQLite/MySQL, конкурентные операции, браузер/доступность, UTF-8/структуру layout и зависимости. Файл CI подготовлен в `.github/workflows/ci.yml`; облачное выполнение подтверждается отдельным запуском после отправки изменений.

## Выпуск и эксплуатация

Подробная процедура: [docs/OPERATIONS.md](docs/OPERATIONS.md). Список пробелов, статусы и критерии готовности: [docs/IMPROVEMENT_PLAN.md](docs/IMPROVEMENT_PLAN.md). Исходный аудит: [docs/AUDIT_REPORT_2026-11-19.md](docs/AUDIT_REPORT_2026-11-19.md).

Полный продуктовый аудит по предоставленной концепции, оценки модулей, целевая архитектура и следующий спринт: [docs/BM_SYSTEMS_PRODUCT_AUDIT_2026-11-19.md](docs/BM_SYSTEMS_PRODUCT_AUDIT_2026-11-19.md).

Обязательные фоновые процессы: планировщик `php artisan schedule:run` каждую минуту и worker `php artisan queue:work` при асинхронной очереди. Расписание включает backup, health-check, просроченные задачи и незакрытые смены.

`php artisan corpms:health --json` возвращает ненулевой код при проблемах БД, миграций, очереди и каталогов storage. Это проверка приложения; доступность scheduler/worker и резервных копий контролируется отдельно.

`php artisan db:backup` сохраняет SQLite/MySQL-копию вне public. `php artisan files:backup` создаёт проверяемый архив вложений; `php artisan files:verify <путь.zip>` восстанавливает его в новую папку и сверяет SHA-256. APP_KEY резервируется отдельно в защищённом хранилище.
