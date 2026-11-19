# Проверка экранов BM Systems

Изолированная SQLite-база. Для учётных записей и интеграций использована роль администратора. Остальные внутренние экраны открыты от имени директора тестовой компании. Права ниже указаны для открытия маршрута.

| Экран | Маршрут | Доступ | Сценарий | Компоновка | HTTP |
|---|---|---|---|---|---|
| Вход | `/login` | Гость | Форма входа и ссылка восстановления | Доступ | 200 |
| Восстановление доступа | `/forgot-password` | Гость | Форма запроса ссылки | Доступ | 200 |
| Новый пароль | `/reset-password/{token}` | Гость | Форма смены пароля по токену | Доступ | 200 |
| Рабочий стол | `/dashboard` | Авторизованный пользователь | Приветствие, полоса приоритетов, посещаемость, задачи и активность | overview | 200 |
| Мой профиль | `/profile` | Авторизованный пользователь | Личные данные и настройки аккаунта | record | 200 |
| Компании | `/companies` | companies.view | Реестр и формы записи | directory | 200 |
| Отделы | `/departments` | departments.view | Справочник подразделений | directory | 200 |
| Оргструктура | `/orgchart` | org_chart.view | Иерархическая схема | planning | 200 |
| Должности | `/positions` | positions.view | Справочник должностей | directory | 200 |
| Сотрудники | `/employees` | employees.view | Поиск и фильтры, таблица или карточки, профиль из строки | directory | 200 |
| Профиль сотрудника | `/employees/{employee}` | employees.view | Личные и кадровые данные, действия по правам | record | 200 |
| Подчинённые | `/employees/{employee}/subordinates` | employees.view | Список непосредственных подчинённых | record | 200 |
| Графики | `/schedules` | schedules.view | Рабочие режимы и назначение графиков | planning | 200 |
| Праздники | `/holidays` | schedules.view | Календарь и перечень исключений | planning | 200 |
| Мой график | `/my-schedule` | attendance.view | Личный календарь смен | planning | 200 |
| Отпуска | `/leaves` | leaves.view | Заявки, статусы и согласование | stream | 200 |
| Посещаемость сегодня | `/attendance` | attendance.view | Полоса статусов, табель дня и поток отметок | stream | 200 |
| Посещаемость в реальном времени | `/attendance/live` | attendance.live.view | Табель дня и обновляемый поток общего шаблона | stream | 200 |
| Табель | `/attendance/timesheet` | attendance.timesheet.view | Фильтр сотрудника и месяца, план и факт, дневная таблица | ledger | 200 |
| Аномалии | `/attendance/anomalies` | attendance.anomalies.view | Список отклонений и фильтры | stream | 200 |
| Корректировки | `/attendance/corrections` | attendance.correct | Очередь исправлений и решения | stream | 200 |
| Ведомости | `/payroll` | payroll.view | Периоды, статусы и суммы | ledger | 200 |
| Оклады | `/payroll/salaries` | payroll.view | Сотрудники и компоненты начислений | ledger | 200 |
| Расчётный период | `/payroll/periods/{period}` | payroll.view | Строки ведомости, расчёт, утверждение, экспорт | ledger | 200 |
| Остатки | `/inventory` | inventory.view | Сводка и таблица по складам | ledger | 200 |
| Товары | `/inventory/products` | inventory.view | Справочник товаров и услуг | directory | 200 |
| Склады | `/inventory/warehouses` | inventory.view | Реестр мест хранения | directory | 200 |
| Поставщики | `/inventory/suppliers` | inventory.view | Контрагенты и контакты | directory | 200 |
| Движения | `/inventory/movements` | inventory.view | Журнал складских операций | ledger | 200 |
| Закупки | `/purchases` | purchases.view | Реестр заказов и статусы | directory | 200 |
| Заказ поставщику | `/purchases/{order}` | purchases.view | Позиции, суммы и действия по заказу | record | 200 |
| Клиенты | `/crm/clients` | crm.view | Реестр и фильтры | directory | 200 |
| Клиент | `/crm/clients/{client}` | crm.view | Контакты, сделки и активность | record | 200 |
| Лиды | `/crm/leads` | crm.view | Таблица входящих лидов | directory | 200 |
| Сделки | `/crm/deals` | crm.view | Воронка по стадиям, суммы и карточки сделок | planning | 200 |
| Активности | `/crm/activities` | crm.view | Журнал и срок следующего действия | stream | 200 |
| Касса и счета | `/finance/accounts` | finance.view | Баланс как основной показатель, затем потоки и реестр счетов | ledger | 200 |
| Статьи | `/finance/categories` | finance.view | Каталог статей доходов и расходов | directory | 200 |
| Операции | `/finance/transactions` | finance.view | Журнал доходов и расходов | ledger | 200 |
| Счета и акты | `/finance/invoices` | finance.view | Реестр, статусы и фильтры | directory | 200 |
| Счёт или акт | `/finance/invoices/{invoice}` | finance.view | Реквизиты, позиции и действия по оплате | record | 200 |
| Бюджеты | `/finance/budgets` | finance.view | План и факт по периодам | ledger | 200 |
| План счетов | `/accounting/chart` | accounting.view | Иерархия и признаки счетов | ledger | 200 |
| Проводки | `/accounting/entries` | accounting.view | Журнал по датам и счетам | ledger | 200 |
| Проводка | `/accounting/entries/{entry}` | accounting.view | Дебет, кредит и первичный документ | ledger | 200 |
| Первичные документы | `/accounting/documents` | accounting.view | Реестр и связь с проводками | directory | 200 |
| Бухгалтерские отчёты | `/accounting/reports` | accounting.view | Период и отчётные таблицы | ledger | 200 |
| Проекты | `/projects` | projects.view | Портфель, фильтры и статусы | overview | 200 |
| Проект | `/projects/{project}` | projects.view | Прогресс работы, бюджет, участники и задачи | record | 200 |
| BI-дашборды | `/bi` | bi.view | Каталог аналитических панелей | overview | 200 |
| Срезы витрины | `/bi/metrics` | bi.view | Фильтры и показатели | ledger | 200 |
| BI-панель | `/bi/dashboards/{dashboard}` | bi.view | Настроенные графики и таблицы | overview | 200 |
| Документы | `/documents` | documents.view | Сводка, реестр, фильтры и создание | directory | 200 |
| Мои документы | `/documents/mine` | documents.view | Сводка и личный реестр общего шаблона | directory | 200 |
| Шаблоны документов | `/documents/templates` | documents.templates.view | Каталог и редактор | directory | 200 |
| Документ | `/documents/{document}` | documents.view | Содержание, версии, подписи и согласование | directory | 200 |
| Маршруты согласования | `/workflows` | workflows.view | Реестр этапов и правила маршрутов | configuration | 200 |
| Учётные записи | `/users` | users.manage | Управление доступом пользователей | directory | 200 |
| Интеграции | `/integrations` | integrations.manage | Настройки подключений | configuration | 200 |
| Устройства | `/biometrics` | biometry.devices.view | Реестр терминалов | directory | 200 |
| События терминалов | `/biometrics/events` | biometry.events.view | Журнал отметок и диагностика | stream | 200 |
| Приватность биометрии | `/biometrics/privacy` | biometry.privacy.manage | Согласия и управление данными | configuration | 200 |
| RFID-карты | `/rfid` | biometry.rfid.view | Привязки и статусы карт | stream | 200 |
| Аналитика | `/analytics` | analytics.view | Общие показатели и графики | overview | 200 |
| Отчёты | `/reports` | reports.view | Критичные сигналы, период и переходы к отчётам | overview | 200 |
| Кадровый отчёт | `/reports/hr` | reports.view | Численность и детализация по периоду | overview | 200 |
| Отчёт о посещаемости | `/reports/attendance` | reports.view | Отметки и отклонения по периоду | ledger | 200 |
| Отчёт по задачам | `/reports/tasks` | reports.view | Статусы и сроки по периоду | ledger | 200 |
| Журнал действий | `/audit` | audit.view@company | Фильтры событий и история | stream | 200 |
| Настройки системы | `/settings` | settings.view@company | Группы параметров и формы | configuration | 200 |
| Глобальный поиск | `/search` | search.global | Поиск по сущностям и переходы к результатам | lookup | 200 |
| AI-ассистент | `/ai` | analytics.ai.use | История диалога, поле запроса и быстрые вопросы | conversation | 200 |
| Все задачи | `/tasks` | tasks.view | Реестр, фильтры и действия | overview | 200 |
| Мои задачи | `/tasks/mine` | tasks.view | Личный список и сроки | planning | 200 |
| Просроченные задачи | `/tasks/overdue` | tasks.view | Очередь задач с нарушенным сроком | stream | 200 |
| Задача | `/tasks/{task}` | tasks.view | Описание, статус, комментарии и вложения | planning | 200 |
| Уведомления | `/notifications` | attendance.view | Лента, отметка прочтения | stream | 200 |

## Проверенные состояния

- 77 маршрутов с данными тестовой компании дали HTTP 200. Параметризованные страницы открывались по существующим идентификаторам в изолированной базе.
- Chromium проверил заголовок основного содержимого и горизонтальный размер документа в 308 сочетаниях маршрута и ширины (390, 768, 1280 и 1440 пикселей). При 390 и 1280 использовалась тёмная тема, при 768 и 1440 — светлая.
- Вход проверен с переключением видимости пароля мышью и клавишей пробела. Для меню проверены открытие на мобильном экране, Escape, сохранение компактного состояния при перезагрузке и раскрытие группы в компактном режиме.
- На тестовой базе с 10 000 задач ответ «Мои задачи» уменьшился с 8 185 794 до примерно 224 000 байт после раздельной пагинации назначенных и созданных задач. Общие счётчики используют полное число результатов.
- Выборочные интерактивные проверки дополняют проверку компоновки. Полный прогон каждого действия из матрицы и всех сочетаний прав, ошибок и пустых данных остаётся открытым.
