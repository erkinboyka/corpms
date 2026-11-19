# Матрица технической приёмки

Матрица описывает проверяемую реализацию. Сопоставление с предоставленной целевой концепцией, отсутствующие модули и незаданные бизнес-правила описаны в BM_SYSTEMS_PRODUCT_AUDIT_2026-11-19.md. Обнаруженный дефект считается закрытым после проверки результата. Сквозная приёмка реальных интеграций и пользовательское согласование выделены отдельно.

| Область | Проверяемое поведение | Автоматические доказательства в tests/Feature | Что требует отдельной приёмки |
|---|---|---|---|
| Вход и учётки | Вход/выход, ограничение попыток, reset, блокировка inactive/dismissed, отзыв сеансов | AuthFlowTest, LoginThrottleHttpTest, PasswordResetFlowTest, HardeningRegressionTest, CreateAdministratorTest | Корпоративный SSO/MFA, если это требование бизнеса |
| Авторизация | Объединение ролей и override, own/department/company, запрет чужих компаний | RbacTest, PermissionScopeGateTest, PermissionWiringHttpTest, TenantIsolationHttpTest, TenantListScopeHttpTest, HardeningRegressionTest | Полная согласованная ролевая модель организации |
| Структура и кадры | CRUD, собственные справочники, карточки по области прав, увольнение с датой | OrgStructureHttpTest, HrAndTasksHttpTest, HardeningRegressionTest | Полный кадровый процесс и импорт реальных данных |
| Графики и отпуска | Изолированные календари, заявки и согласование | TenantIsolationHttpTest, CrudManageHttpTest, PayrollHttpTest, HardeningRegressionTest | Сменные/ночные графики и правила оплачиваемых дней по ТЗ |
| Посещаемость и устройства | Аутентификация устройства, обработка событий, табель и исправления, отключение терминала | Attendance/*, AttendanceCorrectionsHttpTest, BiometricsHttpTest, RfidHttpTest, HardeningRegressionTest | Физические терминалы, сеть, offline-очередь и часовые пояса |
| Задачи | Назначение, статусы, делегирование, вложения, время | Tasks/TaskServiceTest, HrAndTasksHttpTest, ProjectsHttpTest, FileStorageHttpTest | UX при большом числе задач, уведомления реальных пользователей |
| Документы | Создание, версии, маршруты согласования, права файлов | Documents/*, DocumentsHttpTest, Workflow/*, FileStorageHttpTest | Реальные шаблоны/форматы, хранение и восстановление вложений |
| Подписи | Запрос/подписание/проверка/отзыв в реализованной модели | DocumentWorkflowTest, DocumentsHttpTest, PermissionWiringHttpTest | Юридические требования и внешние квалифицированные подписи |
| Зарплата | Детерминированные суммы, состояния, календарь своей компании, видимые строки и итоги экспорта | PayrollHttpTest, HardeningRegressionTest | Утверждённые формулы, налоги, валюты и окончательный расчёт |
| Склад и закупки | Движения, контроль остатка, повторная приёмка, атомарные позиции | InventoryHttpTest, HardeningRegressionTest; tools/verify-concurrency.php | Объёмы реального склада, длительная конкурентная нагрузка |
| CRM | Клиенты/контакты/лиды/сделки/активности и права; повторная конверсия не создаёт дубль | CrmHttpTest, TenantIsolationHttpTest, HardeningRegressionTest | Фактическая воронка и внешние каналы |
| Финансы | Счета, платежи, баланс, отмена, защита от переплаты; отдельные итоги валют и запрет ложного бюджетного процента | FinanceHttpTest, HardeningRegressionTest; tools/verify-concurrency.php | Явные валюты legacy-документов, валютная модель BI/Accounting, банки и утверждённые правила |
| Бухгалтерия | План счетов, сбалансированные проводки, синхронизация | AccountingHttpTest | Регламентированный учёт и отчётность нужной юрисдикции |
| Проекты | Участники, задачи, вехи и состояния | ProjectsHttpTest | Пользовательская приёмка процессов |
| BI и отчёты | Метрики, дашборды, выборки по правам и экспорт | BiHttpTest, AnalyticsAndSystemHttpTest, HardeningRegressionTest | Сверка с независимым источником, корректность прогнозов |
| AI | Локальный ответ, tools, RAG, память, сбой/повтор с архивом, отзыв прав; без генерации личных данных | Ai*Test, AiQueueRecoveryTest, SettingsCompanyScopeTest, HardeningRegressionTest; реальный Groq chat и 4 повтора | Полнота retrieval, embeddings, качество ответов и стоимость |
| Уведомления | Адресация и права в реализованной модели | Notifications/*, PermissionWiringHttpTest | Реальная доставка Telegram/email; без разрешения сообщения не отправлялись |
| Эксплуатация | Health, backup БД/файлов, защита настроек/ключей, миграции и первичный администратор | CorpmsHealthTest, BackupDatabaseCommandTest, FilesBackupTest, SettingsCompanyScopeTest, HardeningRegressionTest, CreateAdministratorTest; фактический restore MySQL и 806 файлов | Облачный CI, внешний DR/мониторинг, нагрузочные SLO |
| Интерфейс | Desktop/mobile/dark, доступность, клавиатура, неверный вход и CSRF | tools/verify-browser.py: 48 проверок Chrome/axe без ошибок | Все роли, состояния форм, screen reader и пользовательская приёмка |

Названия тестов внутри подпапок сокращены для читаемости; полный список сценариев формируется PHPUnit. Процент реализации всей концепции не выводится из количества прошедших тестов.
