# 06 — Frontend

Статус: **завершено (статический аудит Blade/CSS/JS)**. Браузерная проверка всех экранов не выполнялась; runtime-выводы помечены как Confirmed только там, где доказательство есть в коде.

## 1. Архитектура и состояние

- 142 Blade-шаблона, серверный рендер + ванильный JS; базовый UI не использует SPA/framework state management.
- CSS: 15 first-party файлов + 226 vendored Tabler; JS: 16 first-party файлов.
- Vite/Tailwind объявлены в `package.json`, но `npm`/`node_modules` в среде аудита недоступны — production build не проверен.
- Основной UI строится на серверных Blade + ванильном JS без единого состояния приложения; повторяющаяся логика вынесена частично (partials, `corpms.js`).
- Общие паттерны: flash/validation alerts в layout, `partials/empty-state.blade.php` на 62 страницах, `@forelse` на 74, kanban keyboard fallback (select/move buttons), responsive rules (`effects.css:1669-1675`, `bm-ui.css:70`, `bm-chat.css:735-740`, `workspace.css:63`).

## 2. Найденные проблемы

### FE-01 — Medium — Command palette: `aria-modal` без focus trap
- **Evidence:** `resources/views/layouts/tabler.blade.php:360` — `role="dialog" aria-modal="true"`; `public/assets/js/corpms.js:554-565` открывает палитру, ставит `palette.inert = false`, фокусирует input; `:568-575` восстанавливает фокус; `:588-620` обрабатывает Ctrl/Cmd+K, стрелки, Enter, Escape, но **не ловит Tab**; фон не переводится в `inert`.
- **Impact:** при открытой палитре Tab уходит в фон, хотя ARIA обещает модальность; нарушается предсказуемый порядок фокуса и контекст screen reader.
- **Fix:** ловить Tab/Shift+Tab внутри диалога или выставлять `inert` на app shell; сохранить restore focus.
- **Verification:** открыть палитру мышью и Ctrl+K, нажимать Tab — фокус остаётся внутри.
- **Status:** Confirmed.

### FE-02 — Medium — Combobox/listbox: выбор только визуальный
- **Evidence:** `resources/views/partials/navbar.blade.php:20` — `role="combobox"`; `resources/views/layouts/tabler.blade.php:370` — `role="listbox"`; `public/assets/js/corpms.js:500,507` рендерят `role="option"`; `setActive()` в `:397-402` и `:524-529` только toggle `.is-active`.
- **Impact:** ArrowUp/Down меняет подсветку, но `aria-selected`/`aria-activedescendant` не обновляются; screen reader не озвучивает активный вариант.
- **Fix:** присвоить option `id`, ставить `aria-selected`, обновлять `aria-activedescendant` на input; добавить `role="combobox"` palette input.
- **Verification:** проход стрелками с screen reader, проверка атрибутов в DOM.
- **Status:** Confirmed.

### FE-03 — Medium — Global search silently swallows 401/419/500/offline
- **Evidence:** `public/assets/js/corpms.js:345-350` — `res.ok ? res.json() : null`; `if (!data) { hide(); return; }`; `:355-359` — `.catch()` тоже прячет панель. Контраст: `public/assets/js/ai-panel.js:813` явно сообщает «Сессия истекла...».
- **Impact:** истёкшая сессия, 403, 429, 500 и сетевой отказ выглядят как «нет результатов»; пользователь не знает о необходимости перелогина/повтора.
- **Fix:** показывать `role="alert"`/`role="status"`; различать 401/419/403/429/5xx; сохранять запрос и открывать панель при ошибке.
- **Verification:** перехватить `/search/hint` с 401/419/500/offline, проверить сообщение и действие восстановления.
- **Status:** Confirmed.

### FE-04 — Medium — Chat polling backoff перезаписывается
- **Evidence:** `public/assets/js/bm-chat.js:485-493` — в `.catch()` планируется `Math.min(30000, 4000 * 2^n)`, но следующий `.then()` безусловно вызывает `schedulePoll()`; `:496-499` `delay || 4000`.
- **Impact:** при ошибках бэкенда exponential backoff сбрасывается, опрос идёт каждые 4 с — лишний трафик, расход батареи и давление на бэкенд во время инцидента.
- **Fix:** вычислять `nextDelay`, сбрасывать `pollBusy` в `.finally()`, вызывать `schedulePoll(nextDelay)` один раз.
- **Verification:** симулировать 500-е и проверить интервалы 4/8/16/30 с.
- **Status:** Confirmed.

### FE-05 — Medium — Chat edit race / losing draft
- **Evidence:** `public/assets/js/bm-chat.js:714-722` — вставка `<textarea>` в `.bm-chat__msg-body`; `:340-345` — `patchMessage()` делает `body.innerHTML = formatBody(...)`; `:746-749` — blur вызывает `save()` после 150 мс.
- **Impact:** poll-событие `updated` может уничтожить редактируемый черновик; Enter-save и blur-save могут гоняться, вызывая потерю текста или дубль PUT.
- **Fix:** пропускать patch для редактируемого message id; single-flight save; подавлять blur после Enter/Escape.
- **Verification:** открыть edit, ввести текст, форсировать poll `updated`, нажать Enter — один PUT и корректный текст.
- **Status:** Confirmed.

### FE-06 — Low — LMS polling не останавливается
- **Evidence:** `public/assets/js/lms.js:242-243` — `load(); setInterval(load, pollMs);`, `:258-261` скрывает секции, `clearInterval` отсутствует; `public/assets/js/lms-whiteboard.js:410-411` — два `setInterval`; `public/assets/js/lms-pulse.js:86-108` — `setInterval` с silent catch.
- **Impact:** скрытые секции продолжают опрашивать API и мутировать DOM; повторные ошибки не видны пользователю.
- **Fix:** start/stop по `setSection()` и `visibilitychange`, clear на teardown, визуальный индикатор degraded sync (в whiteboard уже есть `syncLost/syncOk` в `:208-210`).
- **Verification:** Network-панель при переключении секций и скрытии таба; симуляция 500.
- **Status:** Confirmed.

### FE-07 — Low — TinyMCE: накопление обработчиков при смене темы
- **Evidence:** `public/assets/js/corpms.js:1152-1168` — `retintRichEditors()` вызывает `editor.remove()` и `attachRichEditor()`; `:1185-1187` guard; `:1216-1228` каждый attach добавляет `submit` listener с `triggerSave()` и validation toast.
- **Impact:** после нескольких переключений темы один submit может вызвать дублирование toast и повторные сохранения.
- **Fix:** хранить ссылку на listener и remove перед re-attach, либо один delegated handler на форму.
- **Verification:** 5 раз переключить тему, submit пустого required rich field — ровно один toast/запрос.
- **Status:** Confirmed.

### FE-08 — Low — Hard-coded geometry / inline styles
- **Evidence:** `resources/views/admin/users.blade.php:74` `style="width: 340px"`; `resources/views/analytics/index.blade.php:209,258`; `resources/views/bi/index.blade.php:36`.
- **Impact:** обход дизайн-токенов, возможный overflow на узких экранах, непоследовательная типографика.
- **Fix:** utility classes (`crm-col--actions`, `crm-legend-dot`, `crm-metric__value--compact`), адаптивная ширина колонки.
- **Verification:** визуальная проверка 360/768/1280px; grep inline width/font-size.
- **Status:** Suspected (нужна браузерная проверка).

### FE-09 — Low — Autofocus на формах
- **Evidence:** `resources/views/auth/login.blade.php:17`, `resources/views/signatures/qr-sign.blade.php:57`, `resources/views/search/index.blade.php:10`.
- **Impact:** фокус может пропустить заголовок/инструкции; дизайнерски неоднозначно для screen reader.
- **Fix:** использовать skip link/focus management вместо autofocus, за исключением одношаговых сценариев.
- **Verification:** загрузка с screen reader на 320px.
- **Status:** Suspected.

## 3. Проверено, подтверждённых дефектов нет

| Область | Доказательство |
|---|---|
| Rich-text/output sanitization | `app/Support/HtmlSanitizer.php:23-42,45-49,119-135,160-181`; вызовы в `partials/rich-text.blade.php:6-9`, `documents/show.blade.php:99-103`, `chat/partials/message.blade.php:9-10,47` |
| AI Markdown | `app/Support/AssistantMarkdown.php:13-21` — `html_input=strip`, `allow_unsafe_links=false`, nesting 20, `strip_tags` |
| AI report SVG | `app/Domains/Ai/Services/BumiFiles.php:66`, `resources/views/ai/graphic.blade.php:21-26,41,50,68-69` — escaping |
| LMS lesson content | `resources/views/lms/lesson.blade.php:36` — `{!! nl2br(e($lesson->content)) !!}` |
| TinyMCE integration | `public/assets/js/corpms.js:1103-1132,1200-1203,1206-1212,1214-1228`; fallback на textarea; `triggerSave()` |
| Kanban keyboard fallback | `resources/views/crm/deals/index.blade.php:117-119`, `resources/views/tasks/index.blade.php:123`; blocked busy cards `effects.css:1791-1796` |
| Responsive basics | `effects.css:1669-1675`, `bm-ui.css:70`, `bm-chat.css:735-740,778`, `workspace.css:63` |
| LMS meeting cleanup | `public/assets/js/lms-meeting.js:309-310,382` — clear timers / beforeunload |

## 4. Не проверено визуально

Контраст, фактический overflow, focus order в Bootstrap-модалках/TinyMCE/Select2/AI dock, z-index между palette/modal/toast/sidebar, runtime AJAX-состояния, поддержка `field-sizing`/`:has()`/`dvh`/`color-mix()` в целевом браузерном матрице, screen-reader announcements — statically не подтверждались.
