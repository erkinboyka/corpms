# 07 — UI / UX и доступность

Статус: **завершено (статический аудит Blade/JS)**. Браузерный скриншот/скринридер/проверка контраста не выполнялись; где нет доказательства в коде, статус — `Suspected`.

Источники: 142 Blade-шаблона, 16 first-party JS, CSS layout/shell, навигация, таблицы, формы, модалки, kanban, LMS, чат, AI, документы и QR. См. также `06_FRONTEND.md` (frontend-инженерный ракурс).

## 1. TOP-20 UX-проблем

| # | ID | Sev/Pri | Проблема | Evidence (file:line) | Влияние | Рекомендация | Статус |
|---|---|---|---|---|---|---|---|
| 1 | UX-01 | Medium/P2 | Палитра объявляет `aria-modal`, но не ловит Tab и не делает фон `inert` | `resources/views/layouts/tabler.blade.php:360`; `public/assets/js/corpms.js:554-565` (open), `:568-575` (close), `:588-620` (keys) | Фокус уходит за модальное окно; ломается порядок фокуса и контекст screen reader | Trap Tab/Shift+Tab или `inert` на app shell; сохранить restore focus | Confirmed |
| 2 | UX-02 | Medium/P2 | Combobox/listbox: активный вариант только подсвечивается, нет `aria-selected`/`aria-activedescendant` | `resources/views/partials/navbar.blade.php:20`; `resources/views/layouts/tabler.blade.php:363,370`; `public/assets/js/corpms.js:397,500,507,524` | Arrow-навигация не озвучивается; ALS не знает выбранный пункт | Дать option `id`, ставить `aria-selected`, обновлять `aria-activedescendant`; добавить `role="combobox"` palette input | Confirmed |
| 3 | UX-03 | Medium/P2 | Глобальный поиск молча прячет 401/419/403/429/500/offline | `public/assets/js/corpms.js:346,350,355-359`; контраст `public/assets/js/ai-panel.js:813` | Истёкшая сессия выглядит как «нет результатов»; пользователь не знает, что делать | Показывать `role="alert"` с диагностикой и действием «войти/повторить»; сохранять запрос | Confirmed |
| 4 | UX-04 | Medium/P2 | Chat polling: exponential backoff перезаписывается безусловным `.then()` | `public/assets/js/bm-chat.js:485-493,496-499` | При больном бэкенде опрос идёт каждые 4 с вместо 30 с | Вычислять `nextDelay`, сбрасывать `pollBusy` в `.finally()`, звонить `schedulePoll(nextDelay)` один раз | Confirmed |
| 5 | UX-05 | Medium/P2 | Chat edit race: poll может заменить `<textarea>` и потерять черновик | `public/assets/js/bm-chat.js:340-345,714-722,744-749,757` | Потря текста или дубли PUT при Enter+blur | Не патчить редактируемое сообщение; single-flight save; гасить blur после Enter/Escape | Confirmed |
| 6 | UX-06 | Low/P3 | LMS-опросы продолжаются в скрытых секциях / скрытом табе | `public/assets/js/lms.js:243,260`; `lms-whiteboard.js:410-411`; `lms-pulse.js:86-108` | Лишний трафик, stale DOM, уведомления о невидимых данных | Start/stop по `setSection()` и `visibilitychange`; clear timer’ы | Confirmed |
| 7 | UX-07 | Low/P3 | TinyMCE: при смене темы накапливаются submit-обработчики | `public/assets/js/corpms.js:1152-1168,1185-1187,1218-1219` | Duplicate toast и повторные сохранения после нескольких переключений темы | Хранить ссылку на listener и remove, либо один delegated handler | Confirmed |
| 8 | UX-08 | Medium/P2 | Кнопки закрытия flash/validation — `<a>` без `href` | `resources/views/layouts/tabler.blade.php:164,188` | Недоступны с клавиатуры как кнопки | `<button type="button" class="btn-close" ...>` | Confirmed |
| 9 | UX-09 | Medium/P2 | Bell-кнопка имеет только `title`, нет отдельного aria-label | `resources/views/partials/nav-actions.blade.php:35-38` | Screen reader может объявить только иконку/счётчик | Добавить `aria-label="{{ __('Уведомления') }}"` | Confirmed |
| 10 | UX-10 | Medium/P2 | Language-триггер — `href="#"` с title-only именем | `resources/views/partials/nav-actions.blade.php:50-52` | При отсутствии JS — переход наверх; слабое имя в AT | `<button type="button">` + explicit `aria-label` | Confirmed |
| 11 | UX-11 | Medium/P2 | Profile-триггер — `href="#"`, имя по инициалам `CM` | `resources/views/partials/nav-actions.blade.php:61,67` | Непонятное назначение меню; переход наверх при сбое JS | `<button type="button">` + `aria-label` «Профиль/меню пользователя» | Confirmed |
| 12 | UX-12 | Medium/P2 | Лабели не связаны с полями (onboarding, imports) | `resources/views/onboarding/index.blade.php:14-15`; `resources/views/imports/index.blade.php:11-12,18-19` | Клик по label не фокусирует поле; нарушение WCAG 1.3.1/3.3.2/4.1.2 | `for`/`id` или обёртка `<label>` | Confirmed |
| 13 | UX-13 | Medium/P2 | Effective label для notes в accounting — `<h3>`, а не `<label>` | `resources/views/accounting/documents/index.blade.php:141,144` | Поле `ad-notes` не имеет программной связи с заголовком | Заменить `<h3>` на `<label for="ad-notes">` | Confirmed |
| 14 | UX-14 | Medium/P2 | Непоследовательная таксономия: Аналитика / Отчёты / BI / Bumi + дубли «Отчёты», «Операции» | `app/Support/Sidebar.php:102,113,124,136-142`; `resources/views/reports/index.blade.php:70-71`; `resources/views/analytics/index.blade.php:128-129`; `resources/views/bi/index.blade.php:11` | Пользователь не может предсказать, где метрика/экспорт | Одна таксономия: Сводка / Отчёты / Бух. отчёты / BI-витрина / Bumi; уникальные breadcrumbs | Confirmed |
| 15 | UX-15 | Low/P3 | Module icons в `Sidebar::ITEMS` не рендерятся; иконка берётся по переводу секции | `app/Support/Sidebar.php:95,113`; `resources/views/partials/sidebar.blade.php:5,33,37` | Все финансы/операции получают одну иконку; смена копии ломает иконку | Рендерить `$entry['icon']` для каждого пункта | Confirmed |
| 16 | UX-16 | Low/P3 | Поиск-поле скрыто на <lg, palette button — единственный вход | `resources/views/partials/navbar.blade.php:9,16`; `resources/views/partials/nav-actions.blade.php:41-46` | На планшете/мобиле непонятно, где поиск | Показывать поле с `md` или добавить подписанную иконку search в mobile actions | Confirmed |
| 17 | UX-17 | Low/P3 | LMS pulse silent catch: повторные ошибки не видны | `public/assets/js/lms-pulse.js:86-108`; контраст `lms-whiteboard.js:208-210` | Пользователь думает, что данные актуальны, хотя sync упал | Выводить `syncLost/syncOk` и action retry | Confirmed |
| 18 | UX-18 | Low/P3 | Hard-coded geometry / inline type bypass токенов | `resources/views/admin/users.blade.php:74`; `resources/views/analytics/index.blade.php:209`; `resources/views/bi/index.blade.php:36` | Переполнение на узких экранах, непоследовательная типографика | Token utility classes и адаптивная колонка | Suspected (нужен browser check) |
| 19 | UX-19 | Low/P3 | `autofocus` на login/QR/search может пропустить заголовки | `resources/views/auth/login.blade.php:17`; `resources/views/signatures/qr-sign.blade.php:57`; `resources/views/search/index.blade.php:10` | Дезориентация keyboard/screen-reader пользователя, прокрутка мимо инструкций | Убрать или заменить skip-link/focus management | Suspected |
| 20 | UX-20 | Medium/P2 | FIN/Analytics/Payroll list-экраны используют `get()` без пагинации | `app/Http/Controllers/Web/AttendanceController.php:53,91`; `PayrollController.php:137,263`; `RfidController.php:50,74,92` | Медленные страницы и нечитаемые таблицы при росте данных | Server-side pagination + агрегаты; перенести scope-фильтры в SQL | Confirmed (backend-часть), Suspected (визуальный эффект) |

## 2. Проверено, подтверждённых дефектов не найдено

| Область | Evidence |
|---|---|
| Empty/loading/success patterns | `resources/views/layouts/tabler.blade.php:157-190`; `public/assets/js/corpms.js:334-339`; `resources/views/partials/empty-state.blade.php` (62 include); `@forelse` на 74 страницах |
| Kanban keyboard alternative | `resources/views/crm/deals/index.blade.php:117-119`; `resources/views/tasks/index.blade.php:123`; `public/assets/css/effects.css:1791-1796` |
| Responsive basics | `public/assets/css/effects.css:1669-1675`; `public/assets/css/bm-ui.css:70`; `public/assets/css/bm-chat.css:735-740,778`; `public/assets/css/workspace.css:63` |
| Rich-text sanitization | `app/Support/HtmlSanitizer.php:23-42,45-49,119-135,160-181`; вызовы в `partials/rich-text.blade.php:6-9`, `documents/show.blade.php:99-103`, `chat/partials/message.blade.php:9-10,47` |
| AI Markdown / SVG | `app/Support/AssistantMarkdown.php:13-21`; `app/Domains/Ai/Services/BumiFiles.php:66`; `resources/views/ai/graphic.blade.php:21-26,41,50,68-69` |
| LMS lesson escaping | `resources/views/lms/lesson.blade.php:36` |
| LMS/meeting cleanup | `public/assets/js/lms-meeting.js:309-310,382` |

## 3. Не проверено

1. Контраст светлой/тёмной темы, чипов, muted/disabled, AI/chat surfaces.
2. Реальный overflow на 320/360/768/1024px и фокусный порядок в Bootstrap modal, TinyMCE iframe, Select2, custom palette, AI dock.
3. z-index между palette ( `--crm-z-palette:1080` ), modal (1090), toast (1120), sidebar, offcanvas, chat context panel.
4. Runtime-интервалы опроса, session-expiry UX, screen-reader announcement; в CI есть browser/accessibility job (`.github/workflows/ci.yml:71-103`), но локально он этим аудитом не запускался.
5. Поддержка `field-sizing`, `:has()`, `dvh`, `color-mix()` в целевом browser matrix.
