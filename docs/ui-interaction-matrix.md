# Элементы управления в шаблонах

Одна строка на объявление. В циклах один шаблон обслуживает несколько записей. Адреса действий и директивы берутся из исходников. Для элементов без подписи по атрибутам указан текст либо имя поля. Эта инвентаризация не подтверждает выполнение действия.

| Шаблон и строка | Элемент | Подпись или поле | Обработчик или назначение |
|---|---|---|---|
| accounting/chart/index.blade.php · 15 | a | Проводки | {{ route('accounting.entries.index') }} |
| accounting/chart/index.blade.php · 19 | button | Типовой план | см. шаблон |
| accounting/chart/index.blade.php · 21 | button | Новый счёт | #newAccount |
| accounting/chart/index.blade.php · 87 | button | {{ $account->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| accounting/chart/index.blade.php · 115 | input | code | text |
| accounting/chart/index.blade.php · 119 | select | kind | см. шаблон |
| accounting/chart/index.blade.php · 128 | input | name | text |
| accounting/chart/index.blade.php · 133 | button | Отмена | modal |
| accounting/chart/index.blade.php · 134 | button | Добавить счёт | submit |
| accounting/documents/index.blade.php · 15 | a | Проводки | {{ route('accounting.entries.index') }} |
| accounting/documents/index.blade.php · 17 | button | Новый документ | #newDocument |
| accounting/documents/index.blade.php · 25 | input | Поиск документов | search |
| accounting/documents/index.blade.php · 28 | select | Вид | this.form.submit() |
| accounting/documents/index.blade.php · 34 | button | Найти | см. шаблон |
| accounting/documents/index.blade.php · 61 | a | №{{ $document->entry->id }} | {{ route('accounting.entries.show', $document->entry) }} |
| accounting/documents/index.blade.php · 72 | button | Удалить | см. шаблон |
| accounting/documents/index.blade.php · 101 | input | number | text |
| accounting/documents/index.blade.php · 105 | select | kind | см. шаблон |
| accounting/documents/index.blade.php · 113 | input | document_date | date |
| accounting/documents/index.blade.php · 123 | input | amount | number |
| accounting/documents/index.blade.php · 127 | input | counterparty | text |
| accounting/documents/index.blade.php · 132 | select | entry_id | см. шаблон |
| accounting/documents/index.blade.php · 144 | input | notes | text |
| accounting/documents/index.blade.php · 149 | button | Отмена | modal |
| accounting/documents/index.blade.php · 150 | button | Добавить | submit |
| accounting/entries/index.blade.php · 15 | a | План счетов | {{ route('accounting.chart.index') }} |
| accounting/entries/index.blade.php · 16 | a | Отчёты | {{ route('accounting.reports.index', ['month' => $month]) }} |
| accounting/entries/index.blade.php · 21 | button | Создать проводки для операций финансов без проводок | см. шаблон |
| accounting/entries/index.blade.php · 25 | button | Новая проводка | #newEntry |
| accounting/entries/index.blade.php · 55 | input | Месяц | this.form.submit() |
| accounting/entries/index.blade.php · 56 | button | Показать месяц | см. шаблон |
| accounting/entries/index.blade.php · 79 | a | {{ $entry->description ?: 'Проводка №'.$entry->id }} | {{ route('accounting.entries.show', $entry) }} |
| accounting/entries/index.blade.php · 90 | a | Открыть | {{ route('accounting.entries.show', $entry) }} |
| accounting/entries/index.blade.php · 114 | input | entry_date | date |
| accounting/entries/index.blade.php · 118 | input | description | text |
| accounting/entries/index.blade.php · 128 | select | Счёт строки {{ $i + 1 }} | см. шаблон |
| accounting/entries/index.blade.php · 137 | input | lines[{{ $i }}][debit] | number |
| accounting/entries/index.blade.php · 141 | input | lines[{{ $i }}][credit] | number |
| accounting/entries/index.blade.php · 148 | button | Отмена | modal |
| accounting/entries/index.blade.php · 149 | button | Провести | submit |
| accounting/entries/show.blade.php · 20 | a | К журналу | {{ route('accounting.entries.index') }} |
| accounting/reports/index.blade.php · 36 | a | Проводки | {{ $entriesUrl }} |
| accounting/reports/index.blade.php · 37 | a | План счетов | {{ route('accounting.chart.index') }} |
| accounting/reports/index.blade.php · 44 | a | Предыдущий месяц | {{ $prevUrl }} |
| accounting/reports/index.blade.php · 47 | input | Месяц | this.form.submit() |
| accounting/reports/index.blade.php · 48 | button | Показать месяц | submit |
| accounting/reports/index.blade.php · 49 | a | Следующий месяц | {{ $nextUrl }} |
| admin/integrations.blade.php · 48 | a | Настройки системы | {{ $settingsPage }} |
| admin/users.blade.php · 20 | button | Новая учётка | #newUser |
| admin/users.blade.php · 57 | input | Фильтр учётных записей | search |
| admin/users.blade.php · 110 | input | Новый пароль для {{ $user->email }} | password |
| admin/users.blade.php · 111 | button | Сбросить или задать пароль | см. шаблон |
| admin/users.blade.php · 135 | button | Закрыть | modal |
| admin/users.blade.php · 143 | input | name | text |
| admin/users.blade.php · 147 | input | email | email |
| admin/users.blade.php · 157 | select | employee_id | см. шаблон |
| admin/users.blade.php · 172 | input | password | password |
| admin/users.blade.php · 178 | button | Отмена | modal |
| admin/users.blade.php · 179 | button | Создать учётку | submit |
| ai/index.blade.php · 66 | input | Вопрос ассистенту | text |
| ai/index.blade.php · 69 | button | Спросить | см. шаблон |
| ai/index.blade.php · 91 | button | {{ $question }} | см. шаблон |
| analytics/index.blade.php · 457 | a | Журнал | {{ route('audit.index') }} |
| attendance/anomalies.blade.php · 29 | a | Все | {{ route('attendance.anomalies') }} |
| attendance/anomalies.blade.php · 30 | a | Открытые | {{ route('attendance.anomalies', ['status' => 'open']) }} |
| attendance/anomalies.blade.php · 31 | a | Разобранные | {{ route('attendance.anomalies', ['status' => 'resolved']) }} |
| attendance/anomalies.blade.php · 69 | input | Решение по аномалии | text |
| attendance/anomalies.blade.php · 70 | button | Закрыть аномалию | см. шаблон |
| attendance/corrections.blade.php · 15 | button | Подать заявку | #newCorrection |
| attendance/corrections.blade.php · 99 | button | Согласовать | см. шаблон |
| attendance/corrections.blade.php · 104 | button | Отклонить | см. шаблон |
| attendance/corrections.blade.php · 138 | input | date | date |
| attendance/corrections.blade.php · 142 | select | field | см. шаблон |
| attendance/corrections.blade.php · 155 | input | old_value | datetime-local |
| attendance/corrections.blade.php · 160 | input | new_value | datetime-local |
| attendance/corrections.blade.php · 166 | textarea | reason | см. шаблон |
| attendance/corrections.blade.php · 173 | button | Отмена | modal |
| attendance/corrections.blade.php · 174 | button | Отправить | submit |
| attendance/timesheet.blade.php · 38 | select | employee_id | см. шаблон |
| attendance/timesheet.blade.php · 45 | select | month | см. шаблон |
| attendance/timesheet.blade.php · 51 | input | year | number |
| attendance/timesheet.blade.php · 52 | button | Показать | см. шаблон |
| attendance/timesheet.blade.php · 55 | a | CSV/Excel | {{ route('attendance.timesheet.export') }}?{{ http_build_query(['year' => $year, 'month' => $month, 'empl |
| audit/index.blade.php · 54 | select | action | this.form.submit() |
| audit/index.blade.php · 65 | select | user_id | this.form.submit() |
| audit/index.blade.php · 74 | input | from | this.form.submit() |
| audit/index.blade.php · 78 | input | to | this.form.submit() |
| audit/index.blade.php · 86 | a | Сбросить | {{ route('audit.index') }} |
| auth/forgot-password.blade.php · 20 | input | email | email |
| auth/forgot-password.blade.php · 26 | button | Отправить ссылку | submit |
| auth/forgot-password.blade.php · 33 | a | Вернуться ко входу | {{ route('login') }} |
| auth/login.blade.php · 16 | input | email | email |
| auth/login.blade.php · 25 | input | password | password |
| auth/login.blade.php · 28 | button | Показать пароль | button |
| auth/login.blade.php · 37 | input | remember | checkbox |
| auth/login.blade.php · 40 | a | Забыли пароль? | {{ route('password.request') }} |
| auth/login.blade.php · 44 | button | Войти | submit |
| auth/reset-password.blade.php · 18 | input | email | email |
| auth/reset-password.blade.php · 26 | input | password | password |
| auth/reset-password.blade.php · 28 | button | Показать пароль | button |
| auth/reset-password.blade.php · 37 | input | password_confirmation | password |
| auth/reset-password.blade.php · 42 | button | Сохранить пароль | submit |
| auth/reset-password.blade.php · 49 | a | Вернуться ко входу | {{ route('login') }} |
| bi/index.blade.php · 15 | a | Срезы витрины | {{ route('bi.metrics.index', ['month' => $month]) }} |
| bi/index.blade.php · 20 | button | Пересобрать витрину | см. шаблон |
| bi/index.blade.php · 22 | button | Новый дашборд | #newDashboard |
| bi/index.blade.php · 29 | input | Месяц | this.form.submit() |
| bi/index.blade.php · 30 | button | Показать месяц | см. шаблон |
| bi/index.blade.php · 66 | a | Открыть | {{ route('bi.dashboards.show', ['dashboard' => $dashboard, 'month' => $month]) }} |
| bi/index.blade.php · 88 | input | name | text |
| bi/index.blade.php · 93 | button | Отмена | modal |
| bi/index.blade.php · 94 | button | Создать | submit |
| bi/metrics.blade.php · 15 | a | Дашборды | {{ route('bi.index', ['month' => $month]) }} |
| bi/metrics.blade.php · 20 | button | Пересобрать витрину | см. шаблон |
| bi/metrics.blade.php · 28 | input | Месяц | this.form.submit() |
| bi/metrics.blade.php · 29 | button | Показать месяц | см. шаблон |
| bi/show.blade.php · 19 | a | К дашбордам | {{ route('bi.index', ['month' => $month]) }} |
| bi/show.blade.php · 24 | button | Пересобрать | см. шаблон |
| bi/show.blade.php · 26 | button | Виджет | #newWidget |
| bi/show.blade.php · 33 | input | Месяц | this.form.submit() |
| bi/show.blade.php · 34 | button | Показать месяц | см. шаблон |
| bi/show.blade.php · 56 | button | Удалить | см. шаблон |
| bi/show.blade.php · 77 | button | Удалить | см. шаблон |
| bi/show.blade.php · 149 | input | title | text |
| bi/show.blade.php · 154 | select | kind | см. шаблон |
| bi/show.blade.php · 162 | select | metric_code | см. шаблон |
| bi/show.blade.php · 172 | button | Отмена | modal |
| bi/show.blade.php · 173 | button | Добавить | submit |
| biometrics/events.blade.php · 24 | a | К устройствам | {{ route('biometrics.index') }} |
| biometrics/events.blade.php · 30 | select | Устройство | this.form.submit() |
| biometrics/index.blade.php · 42 | a | События | {{ route('biometrics.events') }} |
| biometrics/index.blade.php · 43 | a | Приватность | {{ route('biometrics.privacy') }} |
| biometrics/index.blade.php · 44 | a | RFID | {{ route('rfid.index') }} |
| biometrics/index.blade.php · 46 | button | Зарегистрировать устройство | #registerDevice |
| biometrics/index.blade.php · 58 | button | 1Зарегистрируйте устройство | #registerDevice |
| biometrics/index.blade.php · 66 | button | 2Привяжите сотрудника (идентификация) | #enrollProfile |
| biometrics/index.blade.php · 74 | a | 3Отметки появятся в разделе «Посещаемость» | {{ route('attendance.index') }} |
| biometrics/index.blade.php · 140 | button | {{ $device->status->value === 'disabled' ? 'Включить' : 'Отключить' }} | см. шаблон |
| biometrics/index.blade.php · 144 | button | Удалить | #delDevice-{{ $device->id }} |
| biometrics/index.blade.php · 164 | button | Регистрация | #enrollProfile |
| biometrics/index.blade.php · 186 | button | Деактивировать | см. шаблон |
| biometrics/index.blade.php · 193 | button | Удалить | см. шаблон |
| biometrics/index.blade.php · 245 | button | 1Устройство | button |
| biometrics/index.blade.php · 249 | button | 2Подключение | button |
| biometrics/index.blade.php · 253 | button | 3Проверка | button |
| biometrics/index.blade.php · 264 | input | name | text |
| biometrics/index.blade.php · 269 | input | serial_number | text |
| biometrics/index.blade.php · 278 | input | device_type | radio |
| biometrics/index.blade.php · 295 | input | direction | radio |
| biometrics/index.blade.php · 304 | input | vendor | text |
| biometrics/index.blade.php · 309 | summary | Дополнительно (необязательно) | см. шаблон |
| biometrics/index.blade.php · 316 | input | model | text |
| biometrics/index.blade.php · 320 | input | location | text |
| biometrics/index.blade.php · 325 | input | ip | text |
| biometrics/index.blade.php · 330 | input | firmware | text |
| biometrics/index.blade.php · 348 | button | Отмена | modal |
| biometrics/index.blade.php · 349 | button | Назад | button |
| biometrics/index.blade.php · 350 | button | Далее | button |
| biometrics/index.blade.php · 351 | button | Зарегистрировать устройство | submit |
| biometrics/index.blade.php · 368 | button | Отмена | modal |
| biometrics/index.blade.php · 369 | button | Удалить | submit |
| biometrics/index.blade.php · 390 | select | employee_id | см. шаблон |
| biometrics/index.blade.php · 400 | select | device_id | см. шаблон |
| biometrics/index.blade.php · 414 | input | vendor | text |
| biometrics/index.blade.php · 419 | input | template_ref | text |
| biometrics/index.blade.php · 424 | input | template_hash | text |
| biometrics/index.blade.php · 430 | button | Отмена | modal |
| biometrics/index.blade.php · 431 | button | Записать | submit |
| biometrics/privacy.blade.php · 15 | a | К устройствам | {{ route('biometrics.index') }} |
| biometrics/privacy.blade.php · 61 | input | require_liveness | checkbox |
| biometrics/privacy.blade.php · 74 | input | image_retention_days | number |
| biometrics/privacy.blade.php · 79 | input | event_retention_days | number |
| biometrics/privacy.blade.php · 88 | input | purge_now | checkbox |
| biometrics/privacy.blade.php · 97 | button | Сохранить | см. шаблон |
| biometrics/rfid.blade.php · 23 | a | К устройствам | {{ route('biometrics.index') }} |
| biometrics/rfid.blade.php · 25 | button | Выдать карту | #newCard |
| biometrics/rfid.blade.php · 74 | button | Отозвать | #revokeCard-{{ $card->id }} |
| biometrics/rfid.blade.php · 147 | button | Отмена | modal |
| biometrics/rfid.blade.php · 148 | button | Отозвать | submit |
| biometrics/rfid.blade.php · 166 | select | employee_id | см. шаблон |
| biometrics/rfid.blade.php · 179 | input | card_uid | text |
| biometrics/rfid.blade.php · 184 | input | label | text |
| biometrics/rfid.blade.php · 188 | input | expires_at | date |
| biometrics/rfid.blade.php · 194 | button | Отмена | modal |
| biometrics/rfid.blade.php · 195 | button | Выдать | submit |
| companies/index.blade.php · 27 | button | Добавить компанию | #newCompany |
| companies/index.blade.php · 37 | input | Поиск компаний | search |
| companies/index.blade.php · 71 | button | Изменить компанию | #editCompany-{{ $company->id }} |
| companies/index.blade.php · 74 | button | Удалить компанию | см. шаблон |
| companies/index.blade.php · 102 | input | name | text |
| companies/index.blade.php · 106 | input | code | text |
| companies/index.blade.php · 110 | input | address | text |
| companies/index.blade.php · 116 | input | is_active | checkbox |
| companies/index.blade.php · 139 | input | inn | text |
| companies/index.blade.php · 145 | input | region | text |
| companies/index.blade.php · 155 | select | timezone | см. шаблон |
| companies/index.blade.php · 164 | input | email | email |
| companies/index.blade.php · 177 | button | Отмена | modal |
| companies/index.blade.php · 178 | button | Добавить | submit |
| companies/index.blade.php · 197 | input | name | text |
| companies/index.blade.php · 201 | input | code | text |
| companies/index.blade.php · 206 | input | legal_name | text |
| companies/index.blade.php · 211 | input | address | text |
| companies/index.blade.php · 217 | input | is_active | checkbox |
| companies/index.blade.php · 239 | input | inn | text |
| companies/index.blade.php · 246 | input | region | text |
| companies/index.blade.php · 257 | select | timezone | см. шаблон |
| companies/index.blade.php · 266 | input | email | email |
| companies/index.blade.php · 279 | button | Отмена | modal |
| companies/index.blade.php · 280 | button | Сохранить | submit |
| components/page-header.blade.php · 4 | button | Действие | см. шаблон |
| crm/activities/index.blade.php · 28 | button | Новая активность | #newActivity |
| crm/activities/index.blade.php · 38 | button | tab | submit |
| crm/activities/index.blade.php · 42 | select | Вид активности | this.form.submit() |
| crm/activities/index.blade.php · 99 | button | Вернуть в работу | см. шаблон |
| crm/activities/index.blade.php · 104 | button | Выполнено | см. шаблон |
| crm/activities/index.blade.php · 134 | select | kind | см. шаблон |
| crm/activities/index.blade.php · 142 | input | subject | text |
| crm/activities/index.blade.php · 152 | input | due_at | datetime-local |
| crm/activities/index.blade.php · 156 | select | owner_id | см. шаблон |
| crm/activities/index.blade.php · 171 | select | deal_id | см. шаблон |
| crm/activities/index.blade.php · 180 | select | client_id | см. шаблон |
| crm/activities/index.blade.php · 189 | select | lead_id | см. шаблон |
| crm/activities/index.blade.php · 202 | input | notes | text |
| crm/activities/index.blade.php · 207 | button | Отмена | modal |
| crm/activities/index.blade.php · 208 | button | Добавить | submit |
| crm/clients/index.blade.php · 18 | button | Новый клиент | #newClient |
| crm/clients/index.blade.php · 49 | input | Поиск клиентов | search |
| crm/clients/index.blade.php · 52 | select | Статус | this.form.submit() |
| crm/clients/index.blade.php · 57 | button | Найти | см. шаблон |
| crm/clients/index.blade.php · 78 | a | {{ $client->name }} | {{ route('crm.clients.show', $client) }} |
| crm/clients/index.blade.php · 93 | a | Открыть | {{ route('crm.clients.show', $client) }} |
| crm/clients/index.blade.php · 98 | button | {{ $client->status->value === 'active' ? 'В архив' : 'В работу' }} | см. шаблон |
| crm/clients/index.blade.php · 129 | input | name | text |
| crm/clients/index.blade.php · 133 | select | kind | см. шаблон |
| crm/clients/index.blade.php · 141 | select | owner_id | см. шаблон |
| crm/clients/index.blade.php · 156 | input | inn | text |
| crm/clients/index.blade.php · 160 | input | phone | text |
| crm/clients/index.blade.php · 164 | input | email | email |
| crm/clients/index.blade.php · 170 | input | website | text |
| crm/clients/index.blade.php · 174 | input | address | text |
| crm/clients/index.blade.php · 182 | textarea | notes | см. шаблон |
| crm/clients/index.blade.php · 187 | button | Отмена | modal |
| crm/clients/index.blade.php · 188 | button | Создать клиента | submit |
| crm/clients/show.blade.php · 23 | button | Изменить | #editClient |
| crm/clients/show.blade.php · 27 | button | status->value === 'active' ? 'archive' : 'archive-off' }} me-1">{{ $client->status->value === 'active' ?  | см. шаблон |
| crm/clients/show.blade.php · 31 | button | Сделка | #newDeal |
| crm/clients/show.blade.php · 105 | button | Контакт | #newContact |
| crm/clients/show.blade.php · 133 | button | Удалить | см. шаблон |
| crm/clients/show.blade.php · 148 | a | Все | {{ route('crm.activities.index') }} |
| crm/clients/show.blade.php · 214 | input | name | text |
| crm/clients/show.blade.php · 218 | select | kind | см. шаблон |
| crm/clients/show.blade.php · 226 | select | owner_id | см. шаблон |
| crm/clients/show.blade.php · 241 | input | inn | text |
| crm/clients/show.blade.php · 245 | input | phone | text |
| crm/clients/show.blade.php · 249 | input | email | email |
| crm/clients/show.blade.php · 255 | input | website | text |
| crm/clients/show.blade.php · 259 | input | address | text |
| crm/clients/show.blade.php · 267 | textarea | notes | см. шаблон |
| crm/clients/show.blade.php · 272 | button | Отмена | modal |
| crm/clients/show.blade.php · 273 | button | Сохранить | submit |
| crm/clients/show.blade.php · 291 | input | first_name | text |
| crm/clients/show.blade.php · 295 | input | last_name | text |
| crm/clients/show.blade.php · 300 | input | position | text |
| crm/clients/show.blade.php · 309 | input | phone | text |
| crm/clients/show.blade.php · 313 | input | email | email |
| crm/clients/show.blade.php · 317 | input | is_primary | checkbox |
| crm/clients/show.blade.php · 323 | button | Отмена | modal |
| crm/clients/show.blade.php · 324 | button | Добавить | submit |
| crm/clients/show.blade.php · 344 | input | title | text |
| crm/clients/show.blade.php · 353 | input | amount | number |
| crm/clients/show.blade.php · 357 | select | stage | см. шаблон |
| crm/clients/show.blade.php · 371 | input | expected_close_date | date |
| crm/clients/show.blade.php · 375 | select | owner_id | см. шаблон |
| crm/clients/show.blade.php · 388 | textarea | notes | см. шаблон |
| crm/clients/show.blade.php · 393 | button | Отмена | modal |
| crm/clients/show.blade.php · 394 | button | Создать сделку | submit |
| crm/deals/index.blade.php · 33 | button | Новая сделка | #newDeal |
| crm/deals/index.blade.php · 64 | input | Поиск сделок | search |
| crm/deals/index.blade.php · 67 | select | Ответственный | this.form.submit() |
| crm/deals/index.blade.php · 73 | button | Найти | см. шаблон |
| crm/deals/index.blade.php · 95 | a | {{ $deal->client->name }} | {{ route('crm.clients.show', $deal->client) }} |
| crm/deals/index.blade.php · 99 | button | Изменить | #editDeal{{ $deal->id }} |
| crm/deals/index.blade.php · 117 | select | Стадия сделки «{{ $deal->title }}» | this.form.submit() |
| crm/deals/index.blade.php · 146 | input | title | text |
| crm/deals/index.blade.php · 150 | select | client_id | см. шаблон |
| crm/deals/index.blade.php · 165 | input | amount | number |
| crm/deals/index.blade.php · 169 | select | stage | см. шаблон |
| crm/deals/index.blade.php · 177 | input | probability | number |
| crm/deals/index.blade.php · 187 | input | expected_close_date | date |
| crm/deals/index.blade.php · 191 | select | owner_id | см. шаблон |
| crm/deals/index.blade.php · 204 | textarea | notes | см. шаблон |
| crm/deals/index.blade.php · 209 | button | Отмена | modal |
| crm/deals/index.blade.php · 210 | button | Создать сделку | submit |
| crm/deals/index.blade.php · 231 | input | title | text |
| crm/deals/index.blade.php · 235 | select | client_id | см. шаблон |
| crm/deals/index.blade.php · 249 | input | amount | number |
| crm/deals/index.blade.php · 253 | input | probability | number |
| crm/deals/index.blade.php · 263 | input | expected_close_date | date |
| crm/deals/index.blade.php · 267 | select | owner_id | см. шаблон |
| crm/deals/index.blade.php · 280 | textarea | notes | см. шаблон |
| crm/deals/index.blade.php · 285 | button | Отмена | modal |
| crm/deals/index.blade.php · 286 | button | Сохранить | submit |
| crm/leads/index.blade.php · 18 | button | Новый лид | #newLead |
| crm/leads/index.blade.php · 49 | input | Поиск лидов | search |
| crm/leads/index.blade.php · 52 | select | Статус | this.form.submit() |
| crm/leads/index.blade.php · 60 | select | Источник | this.form.submit() |
| crm/leads/index.blade.php · 66 | button | Найти | см. шаблон |
| crm/leads/index.blade.php · 112 | button | Квалифицировать | см. шаблон |
| crm/leads/index.blade.php · 118 | button | Потерян | см. шаблон |
| crm/leads/index.blade.php · 125 | button | Конвертировать в сделку | см. шаблон |
| crm/leads/index.blade.php · 157 | input | title | text |
| crm/leads/index.blade.php · 161 | select | source | см. шаблон |
| crm/leads/index.blade.php · 169 | select | owner_id | см. шаблон |
| crm/leads/index.blade.php · 184 | input | contact_name | text |
| crm/leads/index.blade.php · 188 | input | phone | text |
| crm/leads/index.blade.php · 192 | input | email | email |
| crm/leads/index.blade.php · 202 | input | estimated_amount | number |
| crm/leads/index.blade.php · 206 | input | notes | text |
| crm/leads/index.blade.php · 212 | button | Отмена | modal |
| crm/leads/index.blade.php · 213 | button | Создать лид | submit |
| dashboard.blade.php · 116 | a | просрочено: {{ $overdue }} | {{ route('tasks.overdue') }} |
| dashboard.blade.php · 120 | a | Мои задачи | {{ route('tasks.mine') }} |
| dashboard.blade.php · 139 | a | подписать | {{ route('documents.index') }} |
| dashboard.blade.php · 159 | a | к заявкам | {{ route('schedules.leaves') }} |
| dashboard.blade.php · 168 | a | к документам | {{ route('documents.index') }} |
| dashboard.blade.php · 190 | a | Открыть | {{ route('attendance.index') }} |
| dashboard.blade.php · 243 | a | Открыть | {{ route('tasks.index') }} |
| dashboard.blade.php · 249 | a | Все задачи | {{ route('tasks.index') }} |
| dashboard.blade.php · 277 | a | Отчёт | {{ route('reports.attendance') }} |
| dashboard.blade.php · 365 | a | Журнал действий | {{ route('audit.index') }} |
| dashboard.blade.php · 467 | a | Все устройства | {{ route('biometrics.index') }} |
| departments/index.blade.php · 15 | a | Оргструктура | {{ route('orgchart.index') }} |
| departments/index.blade.php · 17 | button | Добавить подразделение | #newDepartment |
| departments/index.blade.php · 27 | input | Поиск отделов | search |
| departments/index.blade.php · 72 | button | Изменить отдел | #editDept-{{ $department->id }} |
| departments/index.blade.php · 75 | button | Удалить отдел | см. шаблон |
| departments/index.blade.php · 101 | input | name | text |
| departments/index.blade.php · 105 | input | code | text |
| departments/index.blade.php · 111 | select | parent_id | см. шаблон |
| departments/index.blade.php · 122 | button | Отмена | modal |
| departments/index.blade.php · 123 | button | Добавить | submit |
| departments/index.blade.php · 142 | input | name | text |
| departments/index.blade.php · 146 | select | parent_id | см. шаблон |
| departments/index.blade.php · 163 | select | director_id | см. шаблон |
| departments/index.blade.php · 172 | select | deputy_id | см. шаблон |
| departments/index.blade.php · 183 | button | Отмена | modal |
| departments/index.blade.php · 184 | button | Сохранить | submit |
| departments/orgchart-node.blade.php · 9 | summary | {{ $initials }} {{ $node->name }} код {{ $node->code }}@if($node->director) · {{ $node->director->last_na | см. шаблон |
| departments/orgchart-node.blade.php · 41 | a | {{ mb_strtoupper(mb_substr($worker->first_name, 0, 1) . mb_substr($worker->last_name, 0, 1)) }} {{ $worke | {{ route('employees.show', $worker) }} |
| departments/orgchart.blade.php · 20 | a | Списком | {{ route('departments.index') }} |
| departments/orgchart.blade.php · 22 | a | Добавить | {{ route('departments.index') }} |
| documents/index.blade.php · 42 | button | Новый документ | #newDocument |
| documents/index.blade.php · 60 | input | Поиск документов | text |
| documents/index.blade.php · 63 | select | Статус | this.form.submit() |
| documents/index.blade.php · 69 | button | Найти | см. шаблон |
| documents/index.blade.php · 72 | button | Список | button |
| documents/index.blade.php · 73 | button | Карты | button |
| documents/index.blade.php · 89 | a | {{ $document->title }} | {{ route('documents.show', $document) }} |
| documents/index.blade.php · 101 | a | Открыть | {{ route('documents.show', $document) }} |
| documents/index.blade.php · 130 | a | {{ $document->title }} | {{ route('documents.show', $document) }} |
| documents/index.blade.php · 165 | input | title | text |
| documents/index.blade.php · 169 | select | category_id | см. шаблон |
| documents/index.blade.php · 182 | textarea | body | см. шаблон |
| documents/index.blade.php · 186 | input | file | file |
| documents/index.blade.php · 195 | select | priority | см. шаблон |
| documents/index.blade.php · 203 | select | confidentiality | см. шаблон |
| documents/index.blade.php · 213 | button | Отмена | modal |
| documents/index.blade.php · 214 | button | Создать документ | submit |
| documents/show.blade.php · 61 | a | К документам | {{ route('documents.index') }} |
| documents/show.blade.php · 77 | a | Скачать файл версии | {{ route('documents.version.file', [$document, $currentVersion]) }} |
| documents/show.blade.php · 120 | a | Скачать файл этой версии | {{ route('documents.version.file', [$document, $version]) }} |
| documents/show.blade.php · 136 | input | file | file |
| documents/show.blade.php · 141 | input | change_note | text |
| documents/show.blade.php · 144 | button | Версия | см. шаблон |
| documents/show.blade.php · 185 | button | Отправить на согласование | см. шаблон |
| documents/show.blade.php · 191 | input | Комментарий решения | text |
| documents/show.blade.php · 192 | button | decision | см. шаблон |
| documents/show.blade.php · 193 | button | decision | см. шаблон |
| documents/show.blade.php · 199 | button | Зарегистрировать | см. шаблон |
| documents/show.blade.php · 206 | button | В архив | см. шаблон |
| documents/show.blade.php · 256 | input | otp_code | text |
| documents/show.blade.php · 259 | button | Подписать | см. шаблон |
| documents/show.blade.php · 264 | input | Причина отказа | text |
| documents/show.blade.php · 265 | button | Отказаться | см. шаблон |
| documents/show.blade.php · 275 | select | signer_ids[] | см. шаблон |
| documents/show.blade.php · 282 | input | requires_otp | checkbox |
| documents/show.blade.php · 286 | button | Запросить подписи | см. шаблон |
| documents/show.blade.php · 317 | button | Проверить подпись | см. шаблон |
| documents/show.blade.php · 323 | button | Отозвать подпись | см. шаблон |
| documents/templates.blade.php · 15 | a | Документы | {{ route('documents.index') }} |
| documents/templates.blade.php · 17 | button | Категория | #newCategory |
| documents/templates.blade.php · 22 | button | Новый шаблон | #newTemplate |
| documents/templates.blade.php · 92 | button | Сгенерировать | #gen-{{ $template->id }} |
| documents/templates.blade.php · 120 | input | code | text |
| documents/templates.blade.php · 124 | input | name | text |
| documents/templates.blade.php · 128 | button | Отмена | modal |
| documents/templates.blade.php · 129 | button | Добавить | submit |
| documents/templates.blade.php · 150 | input | name | text |
| documents/templates.blade.php · 154 | select | category_id | см. шаблон |
| documents/templates.blade.php · 167 | textarea | body | см. шаблон |
| documents/templates.blade.php · 177 | select | kind | см. шаблон |
| documents/templates.blade.php · 184 | select | workflow_id | см. шаблон |
| documents/templates.blade.php · 194 | input | variables | text |
| documents/templates.blade.php · 202 | button | Отмена | modal |
| documents/templates.blade.php · 203 | button | Создать шаблон | submit |
| documents/templates.blade.php · 224 | input | title | text |
| documents/templates.blade.php · 229 | select | priority | см. шаблон |
| documents/templates.blade.php · 238 | input | due_at | date |
| documents/templates.blade.php · 250 | input | variables[{{ $variable }}] | text |
| documents/templates.blade.php · 258 | button | Отмена | modal |
| documents/templates.blade.php · 259 | button | Создать документ | submit |
| documents/workflows.blade.php · 20 | a | Документы | {{ route('documents.index') }} |
| documents/workflows.blade.php · 21 | button | Создать маршрут | #newWorkflow |
| documents/workflows.blade.php · 95 | button | Закрыть | modal |
| documents/workflows.blade.php · 110 | input | name | text |
| documents/workflows.blade.php · 114 | input | applicable_categories | text |
| documents/workflows.blade.php · 125 | button | Добавить шаг | button |
| documents/workflows.blade.php · 134 | input | см. шаблон | checkbox |
| documents/workflows.blade.php · 136 | button | Удалить шаг | button |
| documents/workflows.blade.php · 143 | input | Согласование руководителем | text |
| documents/workflows.blade.php · 147 | select | см. шаблон | см. шаблон |
| documents/workflows.blade.php · 157 | select | см. шаблон | см. шаблон |
| documents/workflows.blade.php · 166 | select | см. шаблон | см. шаблон |
| documents/workflows.blade.php · 192 | button | Назад | button |
| documents/workflows.blade.php · 193 | button | Далее | button |
| documents/workflows.blade.php · 194 | button | Создать маршрут | submit |
| employees/index.blade.php · 29 | button | Принять сотрудника | #newEmployee |
| employees/index.blade.php · 39 | input | Поиск сотрудников | text |
| employees/index.blade.php · 42 | select | Отдел | см. шаблон |
| employees/index.blade.php · 48 | select | Статус | см. шаблон |
| employees/index.blade.php · 53 | button | Найти | см. шаблон |
| employees/index.blade.php · 56 | button | Список | button |
| employees/index.blade.php · 57 | button | Карты | button |
| employees/index.blade.php · 73 | a | {{ $employee->last_name }} {{ $employee->first_name }}{{ $employee->middle_name ? ' ' . $employee->middle | {{ route('employees.show', $employee) }} |
| employees/index.blade.php · 90 | a | Профиль | {{ route('employees.show', $employee) }} |
| employees/index.blade.php · 114 | a | {{ $entry['initials'] }} {{ $emp->last_name }} {{ $emp->first_name }} {{ $emp->position?->name ?? 'должно | {{ route('employees.show', $emp) }} |
| employees/index.blade.php · 165 | input | last_name | text |
| employees/index.blade.php · 169 | input | first_name | text |
| employees/index.blade.php · 173 | input | middle_name | text |
| employees/index.blade.php · 182 | input | employee_no | text |
| employees/index.blade.php · 186 | input | hired_at | date |
| employees/index.blade.php · 190 | select | department_id | см. шаблон |
| employees/index.blade.php · 199 | select | position_id | см. шаблон |
| employees/index.blade.php · 208 | select | status | см. шаблон |
| employees/index.blade.php · 228 | input | email | email |
| employees/index.blade.php · 232 | input | address | text |
| employees/index.blade.php · 240 | button | Отмена | modal |
| employees/index.blade.php · 241 | button | Оформить приём | submit |
| employees/show.blade.php · 47 | a | К списку | {{ route('employees.index') }} |
| employees/show.blade.php · 49 | button | Редактировать | #employee-editor |
| employees/show.blade.php · 50 | button | Роли | button |
| employees/show.blade.php · 59 | button | Профиль | #tab-profile |
| employees/show.blade.php · 62 | button | Роли {{ $employee->roles->count() }} | #tab-roles |
| employees/show.blade.php · 134 | a | {{ $employee->email }} | mailto:{{ $employee->email }} |
| employees/show.blade.php · 148 | a | {{ $employee->phone }} | tel:{{ preg_replace('/[^\d+]/', '', $employee->phone) }} |
| employees/show.blade.php · 218 | input | last_name | text |
| employees/show.blade.php · 222 | input | first_name | text |
| employees/show.blade.php · 226 | input | middle_name | text |
| employees/show.blade.php · 237 | input | email | email |
| employees/show.blade.php · 254 | input | employee_no | text |
| employees/show.blade.php · 259 | select | status | см. шаблон |
| employees/show.blade.php · 265 | input | fired_at | date |
| employees/show.blade.php · 269 | select | work_schedule_id | см. шаблон |
| employees/show.blade.php · 278 | select | department_id | см. шаблон |
| employees/show.blade.php · 287 | select | position_id | см. шаблон |
| employees/show.blade.php · 298 | button | Сохранить изменения | submit |
| employees/show.blade.php · 299 | button | Отмена | #employee-editor |
| employees/show.blade.php · 319 | button | Управление ролями | button |
| employees/show.blade.php · 327 | a | Прямые подчинённые | {{ route('employees.subordinates', $employee) }} |
| employees/show.blade.php · 330 | a | Все сотрудники | {{ route('employees.index') }} |
| employees/show.blade.php · 334 | button | Редактировать карточку | #employee-editor |
| employees/show.blade.php · 357 | input | role_ids[] | checkbox |
| employees/show.blade.php · 369 | button | Обновить роли | см. шаблон |
| employees/subordinates.blade.php · 12 | a | Сотрудники | {{ route('employees.index') }} |
| employees/subordinates.blade.php · 13 | a | {{ $employee->last_name }} {{ $employee->first_name }} | {{ route('employees.show', $employee) }} |
| employees/subordinates.blade.php · 29 | a | {{ mb_strtoupper(mb_substr($subordinate->first_name, 0, 1) . mb_substr($subordinate->last_name, 0, 1)) }} | {{ route('employees.show', $subordinate) }} |
| errors/partials/card.blade.php · 38 | a | Войти | {{ route('login') }} |
| errors/partials/card.blade.php · 42 | a | На главную | {{ url('/') }} |
| finance/accounts/index.blade.php · 19 | button | Новый счёт | #newAccount |
| finance/accounts/index.blade.php · 77 | a | Операции | {{ route('finance.transactions.index', ['account' => $account->id]) }} |
| finance/accounts/index.blade.php · 82 | button | {{ $account->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| finance/accounts/index.blade.php · 110 | input | name | text |
| finance/accounts/index.blade.php · 114 | select | kind | см. шаблон |
| finance/accounts/index.blade.php · 134 | input | opening_balance | number |
| finance/accounts/index.blade.php · 142 | input | notes | text |
| finance/accounts/index.blade.php · 147 | button | Отмена | modal |
| finance/accounts/index.blade.php · 148 | button | Создать счёт | submit |
| finance/budgets/index.blade.php · 15 | a | Операции | {{ route('finance.transactions.index', ['month' => $month]) }} |
| finance/budgets/index.blade.php · 17 | button | Задать бюджет | #newBudget |
| finance/budgets/index.blade.php · 47 | input | Месяц | this.form.submit() |
| finance/budgets/index.blade.php · 48 | button | Показать месяц | см. шаблон |
| finance/budgets/index.blade.php · 120 | input | month | month |
| finance/budgets/index.blade.php · 124 | select | direction | см. шаблон |
| finance/budgets/index.blade.php · 137 | select | category_id | см. шаблон |
| finance/budgets/index.blade.php · 147 | input | planned_amount | number |
| finance/budgets/index.blade.php · 151 | input | notes | text |
| finance/budgets/index.blade.php · 157 | button | Отмена | modal |
| finance/budgets/index.blade.php · 158 | button | Сохранить | submit |
| finance/categories/index.blade.php · 18 | button | Новая статья | #newCategory |
| finance/categories/index.blade.php · 54 | button | {{ $category->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| finance/categories/index.blade.php · 78 | input | name | text |
| finance/categories/index.blade.php · 82 | select | direction | см. шаблон |
| finance/categories/index.blade.php · 90 | button | Отмена | modal |
| finance/categories/index.blade.php · 91 | button | Создать | submit |
| finance/invoices/index.blade.php · 18 | button | Новый счёт | #newInvoice |
| finance/invoices/index.blade.php · 49 | input | Поиск счетов | search |
| finance/invoices/index.blade.php · 52 | select | Статус | this.form.submit() |
| finance/invoices/index.blade.php · 59 | select | Вид | this.form.submit() |
| finance/invoices/index.blade.php · 65 | button | Найти | см. шаблон |
| finance/invoices/index.blade.php · 89 | a | {{ $invoice->number }} | {{ route('finance.invoices.show', $invoice) }} |
| finance/invoices/index.blade.php · 110 | a | Открыть | {{ route('finance.invoices.show', $invoice) }} |
| finance/invoices/index.blade.php · 137 | input | number | text |
| finance/invoices/index.blade.php · 141 | select | kind | см. шаблон |
| finance/invoices/index.blade.php · 155 | select | client_id | см. шаблон |
| finance/invoices/index.blade.php · 164 | input | counterparty_name | text |
| finance/invoices/index.blade.php · 174 | input | amount | number |
| finance/invoices/index.blade.php · 178 | input | vat_rate | number |
| finance/invoices/index.blade.php · 184 | input | issue_date | date |
| finance/invoices/index.blade.php · 188 | input | due_date | date |
| finance/invoices/index.blade.php · 196 | input | notes | text |
| finance/invoices/index.blade.php · 201 | button | Отмена | modal |
| finance/invoices/index.blade.php · 202 | button | Создать черновик | submit |
| finance/invoices/show.blade.php · 24 | button | Выставить | см. шаблон |
| finance/invoices/show.blade.php · 28 | button | Принять оплату | #payInvoice |
| finance/invoices/show.blade.php · 33 | button | Отменить | см. шаблон |
| finance/invoices/show.blade.php · 128 | input | amount | number |
| finance/invoices/show.blade.php · 133 | input | paid_at | date |
| finance/invoices/show.blade.php · 143 | select | account_id | см. шаблон |
| finance/invoices/show.blade.php · 152 | select | method | см. шаблон |
| finance/invoices/show.blade.php · 166 | select | category_id | см. шаблон |
| finance/invoices/show.blade.php · 175 | input | reference | text |
| finance/invoices/show.blade.php · 180 | input | notes | text |
| finance/invoices/show.blade.php · 185 | button | Отмена | modal |
| finance/invoices/show.blade.php · 186 | button | Принять оплату | submit |
| finance/transactions/index.blade.php · 17 | button | Новая операция | #newTransaction |
| finance/transactions/index.blade.php · 47 | input | Месяц | this.form.submit() |
| finance/transactions/index.blade.php · 48 | select | Направление | this.form.submit() |
| finance/transactions/index.blade.php · 54 | select | Счёт | this.form.submit() |
| finance/transactions/index.blade.php · 60 | button | Показать | см. шаблон |
| finance/transactions/index.blade.php · 119 | select | direction | см. шаблон |
| finance/transactions/index.blade.php · 127 | input | amount | number |
| finance/transactions/index.blade.php · 131 | input | occurred_at | date |
| finance/transactions/index.blade.php · 141 | select | account_id | см. шаблон |
| finance/transactions/index.blade.php · 150 | select | category_id | см. шаблон |
| finance/transactions/index.blade.php · 160 | select | project_id | см. шаблон |
| finance/transactions/index.blade.php · 172 | input | description | text |
| finance/transactions/index.blade.php · 177 | button | Отмена | modal |
| finance/transactions/index.blade.php · 178 | button | Записать | submit |
| inventory/index.blade.php · 15 | a | Движения | {{ route('inventory.movements') }} |
| inventory/index.blade.php · 16 | a | Закупки | {{ route('purchases.index') }} |
| inventory/index.blade.php · 18 | button | Операция со складом | #newMovement |
| inventory/index.blade.php · 50 | select | warehouse | this.form.submit() |
| inventory/index.blade.php · 60 | a | Справочник складов | {{ route('inventory.warehouses') }} |
| inventory/index.blade.php · 122 | select | type | см. шаблон |
| inventory/index.blade.php · 136 | select | warehouse_id | см. шаблон |
| inventory/index.blade.php · 144 | select | product_id | см. шаблон |
| inventory/index.blade.php · 158 | input | quantity | number |
| inventory/index.blade.php · 162 | input | unit_price | number |
| inventory/index.blade.php · 167 | input | note | text |
| inventory/index.blade.php · 172 | button | Отмена | modal |
| inventory/index.blade.php · 173 | button | Записать движение | submit |
| inventory/movements.blade.php · 15 | a | Остатки | {{ route('inventory.index') }} |
| inventory/movements.blade.php · 17 | button | Операция со складом | #newMovement |
| inventory/movements.blade.php · 25 | input | Поиск движений | search |
| inventory/movements.blade.php · 100 | select | type | см. шаблон |
| inventory/movements.blade.php · 113 | select | warehouse_id | см. шаблон |
| inventory/movements.blade.php · 121 | select | product_id | см. шаблон |
| inventory/movements.blade.php · 135 | input | quantity | number |
| inventory/movements.blade.php · 139 | input | unit_price | number |
| inventory/movements.blade.php · 144 | input | note | text |
| inventory/movements.blade.php · 149 | button | Отмена | modal |
| inventory/movements.blade.php · 150 | button | Записать движение | submit |
| inventory/products.blade.php · 15 | a | Остатки | {{ route('inventory.index') }} |
| inventory/products.blade.php · 17 | button | Добавить номенклатуру | #newProduct |
| inventory/products.blade.php · 25 | input | Поиск товаров | search |
| inventory/products.blade.php · 87 | button | Изменить | #editProduct{{ $product->id }} |
| inventory/products.blade.php · 94 | button | {{ $product->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| inventory/products.blade.php · 123 | input | name | text |
| inventory/products.blade.php · 127 | input | description | text |
| inventory/products.blade.php · 136 | select | unit | см. шаблон |
| inventory/products.blade.php · 144 | input | price | number |
| inventory/products.blade.php · 149 | input | min_stock | number |
| inventory/products.blade.php · 154 | button | Отмена | modal |
| inventory/products.blade.php · 155 | button | Сохранить | submit |
| inventory/products.blade.php · 174 | input | sku | text |
| inventory/products.blade.php · 178 | input | name | text |
| inventory/products.blade.php · 184 | select | kind | см. шаблон |
| inventory/products.blade.php · 193 | input | description | text |
| inventory/products.blade.php · 203 | select | unit | см. шаблон |
| inventory/products.blade.php · 211 | input | price | number |
| inventory/products.blade.php · 216 | input | min_stock | number |
| inventory/products.blade.php · 222 | button | Отмена | modal |
| inventory/products.blade.php · 223 | button | Добавить | submit |
| inventory/suppliers.blade.php · 15 | a | Закупки | {{ route('purchases.index') }} |
| inventory/suppliers.blade.php · 17 | button | Добавить поставщика | #newSupplier |
| inventory/suppliers.blade.php · 25 | input | Поиск поставщиков | search |
| inventory/suppliers.blade.php · 78 | button | {{ $supplier->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| inventory/suppliers.blade.php · 105 | input | name | text |
| inventory/suppliers.blade.php · 110 | input | inn | text |
| inventory/suppliers.blade.php · 114 | input | contact_person | text |
| inventory/suppliers.blade.php · 124 | input | phone | text |
| inventory/suppliers.blade.php · 128 | input | email | email |
| inventory/suppliers.blade.php · 134 | input | address | text |
| inventory/suppliers.blade.php · 138 | input | notes | text |
| inventory/suppliers.blade.php · 144 | button | Отмена | modal |
| inventory/suppliers.blade.php · 145 | button | Добавить | submit |
| inventory/warehouses.blade.php · 15 | a | Остатки | {{ route('inventory.index') }} |
| inventory/warehouses.blade.php · 17 | button | Добавить склад | #newWarehouse |
| inventory/warehouses.blade.php · 25 | input | Поиск складов | search |
| inventory/warehouses.blade.php · 66 | button | {{ $warehouse->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| inventory/warehouses.blade.php · 94 | input | code | text |
| inventory/warehouses.blade.php · 98 | input | name | text |
| inventory/warehouses.blade.php · 103 | input | address | text |
| inventory/warehouses.blade.php · 108 | button | Отмена | modal |
| inventory/warehouses.blade.php · 109 | button | Добавить | submit |
| layouts/tabler.blade.php · 54 | a | Перейти к содержанию | #main-content |
| layouts/tabler.blade.php · 136 | a | Закрыть | alert |
| layouts/tabler.blade.php · 160 | a | Закрыть | alert |
| layouts/tabler.blade.php · 181 | a | {{ $bmDestination['title'] }} | {{ route($bmDestination['route']) }} |
| layouts/tabler.blade.php · 216 | button | Закрыть | offcanvas |
| layouts/tabler.blade.php · 226 | input | crm-c-theme | radio |
| layouts/tabler.blade.php · 236 | input | crm-c-theme | radio |
| layouts/tabler.blade.php · 253 | button | Бирюзовый | button |
| layouts/tabler.blade.php · 257 | button | Синий | button |
| layouts/tabler.blade.php · 261 | button | Фиолетовый | button |
| layouts/tabler.blade.php · 265 | button | Розовый | button |
| layouts/tabler.blade.php · 269 | button | Янтарный | button |
| layouts/tabler.blade.php · 280 | input | crm-c-scale | radio |
| layouts/tabler.blade.php · 285 | input | crm-c-scale | radio |
| layouts/tabler.blade.php · 290 | input | crm-c-scale | radio |
| layouts/tabler.blade.php · 306 | button | Сбросить настройки экрана | button |
| layouts/tabler.blade.php · 314 | button | Сбросить оформление | button |
| layouts/tabler.blade.php · 315 | button | Готово | offcanvas |
| layouts/tabler.blade.php · 321 | button | Спросить AI — открыть ассистента | button |
| layouts/tabler.blade.php · 335 | input | Поиск команд и разделов | text |
| notifications/index.blade.php · 61 | button | Отметить прочитанным | см. шаблон |
| partials/ai-panel.blade.php · 14 | a | Вся история диалога | {{ route('ai.index') }} |
| partials/ai-panel.blade.php · 17 | button | Закрыть панель | button |
| partials/ai-panel.blade.php · 32 | button | Посещаемость сегодня | button |
| partials/ai-panel.blade.php · 35 | button | Задачи в работе | button |
| partials/ai-panel.blade.php · 38 | button | Отчёт по задачам | button |
| partials/ai-panel.blade.php · 41 | button | Найти сотрудника | button |
| partials/ai-panel.blade.php · 44 | button | Численность по отделам | button |
| partials/ai-panel.blade.php · 47 | button | Инсайты | button |
| partials/ai-panel.blade.php · 50 | button | Сводный отчёт | button |
| partials/ai-panel.blade.php · 53 | button | Запомнить | button |
| partials/ai-panel.blade.php · 65 | input | Вопрос ассистенту | text |
| partials/ai-panel.blade.php · 69 | button | Отправить | submit |
| partials/breadcrumbs.blade.php · 16 | a | {{ $bmCrumb['label'] }} | {{ $bmCrumb['href'] }} |
| partials/currency-select.blade.php · 51 | select | {{ $name }} | см. шаблон |
| partials/empty-state.blade.php · 10 | a | {{ $actionLabel ?? 'Создать' }} | {{ $actionUrl }} |
| partials/nav-actions.blade.php · 12 | button | Переключить тему | button |
| partials/nav-actions.blade.php · 20 | a | Настройки системы | {{ route('settings.index') }} |
| partials/nav-actions.blade.php · 28 | button | Настройки интерфейса | #crm-settings |
| partials/nav-actions.blade.php · 35 | button | Уведомления | #crm-bell |
| partials/nav-actions.blade.php · 43 | button | Палитра команд (Ctrl+K) | button |
| partials/nav-actions.blade.php · 50 | a | @if ($navEmployee?->photo_path) photo_path, '/') }}" alt="" class="avatar-img"> @else {{ $navInitials !== | # |
| partials/nav-actions.blade.php · 81 | a | Профиль | {{ route('profile.show') }} |
| partials/nav-actions.blade.php · 85 | button | Выйти | submit |
| partials/navbar.blade.php · 17 | input | Поиск по системе | text |
| partials/navbar.blade.php · 24 | input | Поиск недоступен | text |
| partials/navbar.blade.php · 43 | button | Закрыть | offcanvas |
| partials/navbar.blade.php · 58 | button | Отметить прочитанным | см. шаблон |
| partials/navbar.blade.php · 69 | a | Все уведомления | {{ route('notifications.index') }} |
| partials/phone-field.blade.php · 39 | select | Код страны | см. шаблон |
| partials/phone-field.blade.php · 52 | input | {{ $name }} | tel |
| partials/sidebar.blade.php · 23 | button | Меню | #sidebar-menu |
| partials/sidebar.blade.php · 27 | button | Найти раздел | button |
| partials/sidebar.blade.php · 31 | summary | {{ $label }} | см. шаблон |
| partials/sidebar.blade.php · 38 | a | {{ $link['title'] }} | {{ route($link['route']) }} |
| partials/sidebar.blade.php · 46 | button | Закрыть меню | #sidebar-menu |
| partials/sidebar.blade.php · 47 | button | Компактное меню | button |
| payroll/index.blade.php · 16 | button | Новый период | #newPeriod |
| payroll/index.blade.php · 71 | a | {{ $period->label() }} | {{ route('payroll.show', $period) }} |
| payroll/index.blade.php · 113 | input | month | month |
| payroll/index.blade.php · 118 | button | Отмена | modal |
| payroll/index.blade.php · 119 | button | Создать | submit |
| payroll/period.blade.php · 25 | a | К списку | {{ route('payroll.index') }} |
| payroll/period.blade.php · 26 | a | CSV | {{ route('payroll.export', $period) }} |
| payroll/period.blade.php · 30 | button | {{ $period->status->value === 'draft' ? 'Рассчитать' : 'Пересчитать' }} | см. шаблон |
| payroll/period.blade.php · 37 | button | Согласовать | см. шаблон |
| payroll/period.blade.php · 44 | button | Выплатить | см. шаблон |
| payroll/period.blade.php · 84 | button | Рассчитать | см. шаблон |
| payroll/salaries.blade.php · 29 | button | 1 Оклады | button |
| payroll/salaries.blade.php · 38 | button | 2 Правила расчёта | button |
| payroll/salaries.blade.php · 47 | button | 3 Расчёт ведомости | button |
| payroll/salaries.blade.php · 62 | button | Назначить оклад | #newSalary |
| payroll/salaries.blade.php · 95 | input | Поиск по окладам | search |
| payroll/salaries.blade.php · 139 | button | Далее: правила расчёта | button |
| payroll/salaries.blade.php · 147 | button | Добавить правило | #newComponent |
| payroll/salaries.blade.php · 159 | input | Поиск по компонентам | search |
| payroll/salaries.blade.php · 197 | button | {{ $component->is_active ? 'Выключить' : 'Включить' }} | см. шаблон |
| payroll/salaries.blade.php · 221 | button | Назад | button |
| payroll/salaries.blade.php · 222 | button | Далее: расчёт ведомости | button |
| payroll/salaries.blade.php · 239 | a | Перейти к расчёту ведомости Оклады и включённые правила участвуют в расчёте автоматически. | {{ route('payroll.index') }} |
| payroll/salaries.blade.php · 249 | button | Назад | button |
| payroll/salaries.blade.php · 250 | a | К расчёту ведомости | {{ route('payroll.index') }} |
| payroll/salaries.blade.php · 268 | select | employee_id | см. шаблон |
| payroll/salaries.blade.php · 281 | input | base_amount | number |
| payroll/salaries.blade.php · 293 | input | effective_from | date |
| payroll/salaries.blade.php · 299 | button | Отмена | modal |
| payroll/salaries.blade.php · 300 | button | Сохранить | submit |
| payroll/salaries.blade.php · 318 | input | name | text |
| payroll/salaries.blade.php · 323 | input | code | text |
| payroll/salaries.blade.php · 328 | select | kind | см. шаблон |
| payroll/salaries.blade.php · 341 | select | calculation | см. шаблон |
| payroll/salaries.blade.php · 352 | input | rate | number |
| payroll/salaries.blade.php · 359 | button | Отмена | modal |
| payroll/salaries.blade.php · 360 | button | Добавить | submit |
| positions/index.blade.php · 16 | button | Добавить должность | #newPosition |
| positions/index.blade.php · 26 | input | Поиск должностей | search |
| positions/index.blade.php · 58 | button | Изменить должность | #editPosition-{{ $position->id }} |
| positions/index.blade.php · 61 | button | Удалить должность | см. шаблон |
| positions/index.blade.php · 88 | input | name | text |
| positions/index.blade.php · 93 | input | code | text |
| positions/index.blade.php · 98 | input | grade | text |
| positions/index.blade.php · 108 | textarea | description | см. шаблон |
| positions/index.blade.php · 113 | button | Отмена | modal |
| positions/index.blade.php · 114 | button | Добавить | submit |
| positions/index.blade.php · 133 | input | name | text |
| positions/index.blade.php · 138 | input | code | text |
| positions/index.blade.php · 143 | input | grade | text |
| positions/index.blade.php · 153 | textarea | description | см. шаблон |
| positions/index.blade.php · 158 | button | Отмена | modal |
| positions/index.blade.php · 159 | button | Сохранить | submit |
| profile.blade.php · 43 | button | Загрузить фото | button |
| profile.blade.php · 85 | input | photo | file |
| profile.blade.php · 128 | input | address | text |
| profile.blade.php · 134 | input | telegram_chat_id | text |
| profile.blade.php · 143 | input | birth_date | date |
| profile.blade.php · 148 | input | email | email |
| profile.blade.php · 155 | button | Сохранить | см. шаблон |
| profile.blade.php · 214 | input | current_password | password |
| profile.blade.php · 218 | input | password | password |
| profile.blade.php · 223 | input | password_confirmation | password |
| profile.blade.php · 228 | button | Сменить пароль | см. шаблон |
| projects/index.blade.php · 15 | a | Задачи | {{ route('tasks.index') }} |
| projects/index.blade.php · 17 | button | Новый проект | #newProject |
| projects/index.blade.php · 48 | input | Поиск проектов | search |
| projects/index.blade.php · 51 | select | Статус | this.form.submit() |
| projects/index.blade.php · 58 | button | Найти | см. шаблон |
| projects/index.blade.php · 80 | a | {{ $project->name }} | {{ route('projects.show', $project) }} |
| projects/index.blade.php · 103 | a | Открыть | {{ route('projects.show', $project) }} |
| projects/index.blade.php · 130 | input | code | text |
| projects/index.blade.php · 134 | input | name | text |
| projects/index.blade.php · 144 | select | status | см. шаблон |
| projects/index.blade.php · 152 | select | manager_id | см. шаблон |
| projects/index.blade.php · 162 | select | department_id | см. шаблон |
| projects/index.blade.php · 176 | input | start_date | date |
| projects/index.blade.php · 180 | input | due_date | date |
| projects/index.blade.php · 184 | input | budget | number |
| projects/index.blade.php · 192 | textarea | description | см. шаблон |
| projects/index.blade.php · 197 | button | Отмена | modal |
| projects/index.blade.php · 198 | button | Создать проект | submit |
| projects/show.blade.php · 20 | a | К портфелю | {{ route('projects.index') }} |
| projects/show.blade.php · 24 | select | Статус проекта | this.form.submit() |
| projects/show.blade.php · 30 | button | Изменить | #editProject |
| projects/show.blade.php · 79 | select | task_id | см. шаблон |
| projects/show.blade.php · 86 | button | Привязать | см. шаблон |
| projects/show.blade.php · 104 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| projects/show.blade.php · 115 | button | Отвязать | см. шаблон |
| projects/show.blade.php · 142 | select | employee_id | см. шаблон |
| projects/show.blade.php · 151 | select | role | см. шаблон |
| projects/show.blade.php · 159 | input | allocation_percent | number |
| projects/show.blade.php · 162 | button | Добавить участника | см. шаблон |
| projects/show.blade.php · 179 | button | Удалить | см. шаблон |
| projects/show.blade.php · 200 | input | title | text |
| projects/show.blade.php · 204 | input | due_date | date |
| projects/show.blade.php · 206 | button | см. шаблон | см. шаблон |
| projects/show.blade.php · 223 | button | {{ $milestone->isCompleted() ? 'Вернуть в работу' : 'Выполнено' }} | см. шаблон |
| projects/show.blade.php · 231 | button | Удалить | см. шаблон |
| projects/show.blade.php · 257 | input | name | text |
| projects/show.blade.php · 261 | select | manager_id | см. шаблон |
| projects/show.blade.php · 270 | select | department_id | см. шаблон |
| projects/show.blade.php · 285 | input | start_date | date |
| projects/show.blade.php · 289 | input | due_date | date |
| projects/show.blade.php · 293 | input | budget | number |
| projects/show.blade.php · 301 | textarea | description | см. шаблон |
| projects/show.blade.php · 306 | button | Отмена | modal |
| projects/show.blade.php · 307 | button | Сохранить | submit |
| purchases/index.blade.php · 15 | a | Поставщики | {{ route('inventory.suppliers') }} |
| purchases/index.blade.php · 17 | button | Новый заказ | #newOrder |
| purchases/index.blade.php · 25 | input | Поиск заказа | search |
| purchases/index.blade.php · 30 | select | status | this.form.submit() |
| purchases/index.blade.php · 67 | a | {{ $order->number }} | {{ route('purchases.show', $order) }} |
| purchases/index.blade.php · 76 | a | Открыть | {{ route('purchases.show', $order) }} |
| purchases/index.blade.php · 80 | button | Разместить | см. шаблон |
| purchases/index.blade.php · 86 | button | Отменить | см. шаблон |
| purchases/index.blade.php · 113 | input | number | text |
| purchases/index.blade.php · 117 | select | supplier_id | см. шаблон |
| purchases/index.blade.php · 126 | select | warehouse_id | см. шаблон |
| purchases/index.blade.php · 136 | input | order_date | date |
| purchases/index.blade.php · 140 | input | expected_at | date |
| purchases/index.blade.php · 144 | input | notes | text |
| purchases/index.blade.php · 155 | select | items[0][product_id] | см. шаблон |
| purchases/index.blade.php · 164 | input | items[0][quantity] | number |
| purchases/index.blade.php · 168 | input | items[0][unit_price] | number |
| purchases/index.blade.php · 171 | button | Убрать позицию | button |
| purchases/index.blade.php · 175 | button | Добавить позицию | button |
| purchases/index.blade.php · 179 | button | Отмена | modal |
| purchases/index.blade.php · 180 | button | Создать заказ | submit |
| purchases/show.blade.php · 17 | a | К списку | {{ route('purchases.index') }} |
| purchases/show.blade.php · 20 | button | Позиция | #addItem |
| purchases/show.blade.php · 22 | button | Разместить | см. шаблон |
| purchases/show.blade.php · 28 | button | Принять на склад | см. шаблон |
| purchases/show.blade.php · 32 | button | Отменить | см. шаблон |
| purchases/show.blade.php · 105 | button | Убрать | см. шаблон |
| purchases/show.blade.php · 135 | select | product_id | см. шаблон |
| purchases/show.blade.php · 145 | input | quantity | number |
| purchases/show.blade.php · 149 | input | unit_price | number |
| purchases/show.blade.php · 154 | button | Отмена | modal |
| purchases/show.blade.php · 155 | button | Добавить позицию | submit |
| reports/attendance.blade.php · 25 | a | К отчётам | {{ route('reports.index') }} |
| reports/attendance.blade.php · 26 | a | CSV/Excel | {{ route('reports.export', 'attendance') }}?{{ $periodQuery }} |
| reports/attendance.blade.php · 35 | input | from | date |
| reports/attendance.blade.php · 39 | input | to | date |
| reports/attendance.blade.php · 41 | button | Обновить | см. шаблон |
| reports/hr.blade.php · 25 | a | К отчётам | {{ route('reports.index') }} |
| reports/hr.blade.php · 26 | a | CSV/Excel | {{ route('reports.export', 'hr') . '?' . $periodQuery }} |
| reports/index.blade.php · 76 | input | from | date |
| reports/index.blade.php · 77 | input | to | date |
| reports/index.blade.php · 78 | button | Показать | см. шаблон |
| reports/index.blade.php · 87 | a | {{ $alert['title'] }} {{ $alert['text'] }} {{ $alert['action'] }} | {{ $alert['url'] }} |
| reports/index.blade.php · 120 | a | Открыть отчёт | {{ route('reports.hr') . '?' . $period }} |
| reports/index.blade.php · 121 | a | Скачать CSV | {{ route('reports.export', 'hr') . '?' . $period }} |
| reports/index.blade.php · 149 | a | Открыть табель | {{ route('reports.attendance') . '?' . $period }} |
| reports/index.blade.php · 150 | a | Скачать CSV | {{ route('reports.export', 'attendance') . '?' . $period }} |
| reports/index.blade.php · 178 | a | Открыть отчёт | {{ route('reports.tasks') . '?' . $period }} |
| reports/index.blade.php · 179 | a | Скачать CSV | {{ route('reports.export', 'tasks') . '?' . $period }} |
| reports/index.blade.php · 191 | a | Кадровый отчёт Численность по отделам, руководители, доля от компании. | {{ route('reports.hr') . '?' . $period }} |
| reports/index.blade.php · 199 | a | Рабочее время Часы, опоздания и незакрытые смены за период. | {{ route('reports.attendance') . '?' . $period }} |
| reports/index.blade.php · 207 | a | Результат по задачам Кто сколько закрыл, где просрочки и перегрузка. | {{ route('reports.tasks') . '?' . $period }} |
| reports/tasks.blade.php · 24 | a | К отчётам | {{ route('reports.index') }} |
| reports/tasks.blade.php · 25 | a | CSV/Excel | {{ route('reports.export', 'tasks') }}?{{ $periodQuery }} |
| reports/tasks.blade.php · 34 | input | from | date |
| reports/tasks.blade.php · 38 | input | to | date |
| reports/tasks.blade.php · 40 | button | Обновить | см. шаблон |
| schedules/holidays.blade.php · 44 | a | Предыдущий год | {{ route('schedules.holidays', ['year' => $year - 1]) }} |
| schedules/holidays.blade.php · 46 | input | Год | number |
| schedules/holidays.blade.php · 47 | button | Год | см. шаблон |
| schedules/holidays.blade.php · 49 | a | Следующий год | {{ route('schedules.holidays', ['year' => $year + 1]) }} |
| schedules/holidays.blade.php · 52 | button | Добавить праздник | #newHoliday |
| schedules/holidays.blade.php · 150 | button | Удалить праздник | см. шаблон |
| schedules/holidays.blade.php · 178 | input | name | text |
| schedules/holidays.blade.php · 182 | input | date | date |
| schedules/holidays.blade.php · 190 | select | kind | см. шаблон |
| schedules/holidays.blade.php · 199 | button | Отмена | modal |
| schedules/holidays.blade.php · 200 | button | Добавить | submit |
| schedules/index.blade.php · 21 | button | Добавить график | #newSchedule |
| schedules/index.blade.php · 58 | button | Изменить график | #editSchedule-{{ $schedule->id }} |
| schedules/index.blade.php · 61 | button | Удалить график | см. шаблон |
| schedules/index.blade.php · 89 | input | name | text |
| schedules/index.blade.php · 93 | select | type | см. шаблон |
| schedules/index.blade.php · 106 | input | start_time | time |
| schedules/index.blade.php · 110 | input | end_time | time |
| schedules/index.blade.php · 116 | input | break_start | time |
| schedules/index.blade.php · 120 | input | break_end | time |
| schedules/index.blade.php · 126 | input | grace_minutes | number |
| schedules/index.blade.php · 130 | input | min_rest_hours | number |
| schedules/index.blade.php · 136 | button | Отмена | modal |
| schedules/index.blade.php · 137 | button | Создать график | submit |
| schedules/index.blade.php · 156 | input | name | text |
| schedules/index.blade.php · 160 | select | type | см. шаблон |
| schedules/index.blade.php · 173 | input | start_time | time |
| schedules/index.blade.php · 177 | input | end_time | time |
| schedules/index.blade.php · 183 | input | break_start | time |
| schedules/index.blade.php · 187 | input | break_end | time |
| schedules/index.blade.php · 194 | input | allow_overtime | checkbox |
| schedules/index.blade.php · 201 | input | is_active | checkbox |
| schedules/index.blade.php · 209 | input | grace_minutes | number |
| schedules/index.blade.php · 213 | input | min_rest_hours | number |
| schedules/index.blade.php · 219 | button | Отмена | modal |
| schedules/index.blade.php · 220 | button | Сохранить | submit |
| schedules/index.blade.php · 232 | input | см. шаблон | time |
| schedules/leaves.blade.php · 33 | button | Отправить заявку | #newLeave |
| schedules/leaves.blade.php · 84 | button | Одобрить | см. шаблон |
| schedules/leaves.blade.php · 89 | button | Отклонить | см. шаблон |
| schedules/leaves.blade.php · 99 | select | Статус | this.form.submit() |
| schedules/leaves.blade.php · 133 | button | Одобрить | см. шаблон |
| schedules/leaves.blade.php · 138 | button | Отклонить | см. шаблон |
| schedules/leaves.blade.php · 170 | input | from_date | date |
| schedules/leaves.blade.php · 174 | input | to_date | date |
| schedules/leaves.blade.php · 183 | select | type | см. шаблон |
| schedules/leaves.blade.php · 191 | textarea | reason | см. шаблон |
| schedules/leaves.blade.php · 196 | button | Отмена | modal |
| schedules/leaves.blade.php · 197 | button | Отправить заявку | submit |
| schedules/mine.blade.php · 23 | a | Графики работы | {{ route('schedules.index') }} |
| search/index.blade.php · 10 | input | Поиск по системе | text |
| search/index.blade.php · 13 | button | Найти | см. шаблон |
| search/index.blade.php · 32 | a | {{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }}) | {{ route('employees.show', $employee) }} |
| search/index.blade.php · 47 | a | {{ $task->number }} — {{ \Illuminate\Support\Str::limit($task->title, 40) }} | {{ route('tasks.show', $task) }} |
| search/index.blade.php · 62 | a | {{ $document->document_number }} — {{ \Illuminate\Support\Str::limit($document->title, 40) }} | {{ route('documents.show', $document) }} |
| settings/index.blade.php · 68 | input | settings[{{ $key }}] | checkbox |
| settings/index.blade.php · 76 | input | settings[{{ $key }}] | password |
| settings/index.blade.php · 84 | input | settings[{{ $key }}] | {{ $meta['type'] === 'int' ? 'number' : 'text' }} |
| settings/index.blade.php · 112 | button | Сохранить | submit |
| tasks/index.blade.php · 49 | select | Статус | this.form.submit() |
| tasks/index.blade.php · 55 | select | Приоритет | this.form.submit() |
| tasks/index.blade.php · 63 | button | Список | button |
| tasks/index.blade.php · 64 | button | Kanban | button |
| tasks/index.blade.php · 65 | button | Календарь | button |
| tasks/index.blade.php · 86 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/index.blade.php · 94 | a | Открыть | {{ route('tasks.show', $task) }} |
| tasks/index.blade.php · 123 | button | Переместить задачу «{{ $task->title }}» в другую колонку | button |
| tasks/index.blade.php · 129 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/index.blade.php · 139 | button | {{ $colMeta['label'] }} | button |
| tasks/index.blade.php · 176 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/index.blade.php · 189 | a | {{ $task->title }} {{ $task->deadline->format('d.m.Y') }} @if ($task->deadline->isPast())· просрочено@end | {{ route('tasks.show', $task) }} |
| tasks/mine.blade.php · 24 | button | Создать задачу | #newTask |
| tasks/mine.blade.php · 34 | a | {{ $openLogs->task->title }} | {{ route('tasks.show', $openLogs->task) }} |
| tasks/mine.blade.php · 51 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/mine.blade.php · 89 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/mine.blade.php · 127 | input | title | text |
| tasks/mine.blade.php · 131 | textarea | description | см. шаблон |
| tasks/mine.blade.php · 140 | select | priority | см. шаблон |
| tasks/mine.blade.php · 148 | input | deadline | date |
| tasks/mine.blade.php · 153 | select | employee_ids[] | см. шаблон |
| tasks/mine.blade.php · 164 | select | department_ids[] | см. шаблон |
| tasks/mine.blade.php · 176 | button | Отмена | modal |
| tasks/mine.blade.php · 177 | button | Создать задачу | submit |
| tasks/overdue.blade.php · 30 | a | {{ $task->title }} | {{ route('tasks.show', $task) }} |
| tasks/overdue.blade.php · 42 | a | Открыть задачу | {{ route('tasks.show', $task) }} |
| tasks/show.blade.php · 85 | a | К задачам | {{ route('tasks.index') }} |
| tasks/show.blade.php · 87 | button | Изменить | #editTask |
| tasks/show.blade.php · 90 | button | см. шаблон | см. шаблон |
| tasks/show.blade.php · 128 | button | {{ $nextAction['label'] }} | см. шаблон |
| tasks/show.blade.php · 131 | a | {{ $nextAction['label'] }} | #task-result |
| tasks/show.blade.php · 183 | button | Остановить учёт | см. шаблон |
| tasks/show.blade.php · 185 | button | Начать учёт времени | см. шаблон |
| tasks/show.blade.php · 201 | textarea | summary | см. шаблон |
| tasks/show.blade.php · 204 | button | Отправить на проверку | см. шаблон |
| tasks/show.blade.php · 210 | summary | Дополнительные действия | см. шаблон |
| tasks/show.blade.php · 216 | select | status | см. шаблон |
| tasks/show.blade.php · 224 | input | comment | text |
| tasks/show.blade.php · 226 | button | Сохранить | см. шаблон |
| tasks/show.blade.php · 235 | a | {{ $child->title }} 'crm-chip--green', 'overdue' => 'crm-chip--red', 'cancelled' => 'crm-chip--grey', 're | {{ route('tasks.show', $child) }} |
| tasks/show.blade.php · 254 | input | title | text |
| tasks/show.blade.php · 258 | select | priority | см. шаблон |
| tasks/show.blade.php · 266 | select | employee_id | см. шаблон |
| tasks/show.blade.php · 275 | input | deadline | datetime-local |
| tasks/show.blade.php · 279 | textarea | description | см. шаблон |
| tasks/show.blade.php · 282 | button | Добавить | см. шаблон |
| tasks/show.blade.php · 309 | input | Комментарий | text |
| tasks/show.blade.php · 310 | button | см. шаблон | см. шаблон |
| tasks/show.blade.php · 319 | button | Исполнители | #tt-people |
| tasks/show.blade.php · 322 | button | История | #tt-history |
| tasks/show.blade.php · 325 | button | Файлы {{ $attachments->count() }} | #tt-files |
| tasks/show.blade.php · 358 | select | Кому делегировать | см. шаблон |
| tasks/show.blade.php · 365 | button | Делегировать | см. шаблон |
| tasks/show.blade.php · 418 | a | Скачать файл | {{ route('tasks.attachments.download', [$task, $attachment]) }} |
| tasks/show.blade.php · 428 | button | Удалить файл | см. шаблон |
| tasks/show.blade.php · 439 | input | file | file |
| tasks/show.blade.php · 440 | button | Приложить | см. шаблон |
| tasks/show.blade.php · 462 | input | title | text |
| tasks/show.blade.php · 466 | textarea | description | см. шаблон |
| tasks/show.blade.php · 475 | select | priority | см. шаблон |
| tasks/show.blade.php · 483 | input | deadline | datetime-local |
| tasks/show.blade.php · 490 | button | Отмена | modal |
| tasks/show.blade.php · 491 | button | Сохранить | submit |
| vendor/pagination/tabler.blade.php · 8 | a | Предыдущая | {{ $paginator->previousPageUrl() }} |
| vendor/pagination/tabler.blade.php · 23 | a | {{ $page }} | {{ $url }} |
| vendor/pagination/tabler.blade.php · 31 | a | Следующая | {{ $paginator->nextPageUrl() }} |
