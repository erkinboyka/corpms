/* ============================================================================
   CorpMS — фронтенд-скрипт (без сборщика, целенаправленно статический)
   - переключение светлой/тёмной темы с сохранением + синхронизация вкладок
   - «волна» на клик для кнопок и элементов меню
   - живой поиск в топбаре (подсказки списком с blur-панелью)
   - память скролла: страница на «Назад» и позиция сайдбара всегда
   ============================================================================ */
(function () {
    'use strict';

    var KEY = 'crm-theme';

    /* ---------- тема ---------- */

    function normalize(value) {
        return value === 'dark' || value === 'light' ? value : null;
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        /* подчиняем теме все вторичные поверхности (сайдбар и т.п.) */
        var scoped = document.querySelectorAll('[data-bs-theme]');
        for (var i = 0; i < scoped.length; i++) {
            if (scoped[i] !== document.documentElement) {
                scoped[i].setAttribute('data-bs-theme', theme);
            }
        }
        /* редакторы TinyMCE живут в iframe со своим скином — пересоздаём */
        if (typeof retintRichEditors === 'function') { retintRichEditors(); }
    }

    var themeTransition;
    function setTheme(theme, trigger) {
        try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode */ }
        if (themeTransition) {
            themeTransition.skipTransition();
            themeTransition = null;
            document.documentElement.classList.remove('crm-theme-transition');
            applyTheme(theme);
            return;
        }
        if (!trigger || !document.startViewTransition || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            applyTheme(theme);
            return;
        }
        var root = document.documentElement;
        var rect = trigger.getBoundingClientRect();
        var x = rect.left + rect.width / 2;
        var y = rect.top + rect.height / 2;
        var radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
        root.classList.add('crm-theme-transition');
        var transition = document.startViewTransition(function () {
            if (themeTransition === transition) { applyTheme(theme); }
        });
        themeTransition = transition;
        transition.ready.then(function () {
            var duration = getComputedStyle(root).getPropertyValue('--crm-motion-panel').trim();
            root.animate({
                clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)']
            }, { duration: parseFloat(duration) * (duration.endsWith('ms') ? 1 : 1000) || 520,
                easing: 'cubic-bezier(.2, 0, 0, 1)', pseudoElement: '::view-transition-new(root)' });
        }).catch(function () { /* снимок мог быть пропущен браузером */ });
        transition.finished.finally(function () {
            if (themeTransition !== transition) { return; }
            themeTransition = null;
            root.classList.remove('crm-theme-transition');
        }).catch(function () {});
    }

    /* тумблер: кнопка(и) по классу, иконка меняется CSS-ом от data-bs-theme */
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('.crm-theme-toggle');
        if (button) {
            event.preventDefault();
            setTheme(currentTheme() === 'dark' ? 'light' : 'dark', button);
        }
    });

    /* синхронизация между вкладками */
    window.addEventListener('storage', function (event) {
        if (event.key === KEY) {
            var theme = normalize(event.newValue);
            if (theme) {
                applyTheme(theme);
            }
        }
    });

    /* начальная синхронизация подчинённых поверхностей (DOM уже готов: defer) */
    applyTheme(currentTheme());

    /* ---------- кастомизация: акцент и масштаб шрифта (панель «Настройки») ---------- */

    var ACCENT_KEY = 'crm-accent';
    var SCALE_KEY = 'crm-scale';
    var ACCENTS = ['teal', 'blue', 'violet', 'rose', 'amber'];

    function applyAccent(value) {
        if (value && ACCENTS.indexOf(value) !== -1) {
            document.documentElement.setAttribute('data-crm-accent', value);
        } else {
            document.documentElement.removeAttribute('data-crm-accent');
        }
    }

    function applyScale(value) {
        if (value && value !== 'md') {
            document.documentElement.setAttribute('data-crm-scale', value);
        } else {
            document.documentElement.removeAttribute('data-crm-scale');
        }
    }

    function syncCustomizeForm() {
        var modal = document.getElementById('crm-settings');
        if (!modal) { return; }

        var theme = currentTheme();
        var themeInput = modal.querySelector('input[name="crm-c-theme"][value="' + theme + '"]');
        if (themeInput) { themeInput.checked = true; }

        var accent = null;
        var scale = null;
        try {
            accent = localStorage.getItem(ACCENT_KEY);
            scale = localStorage.getItem(SCALE_KEY);
        } catch (e) { /* private mode */ }

        var swatches = modal.querySelectorAll('[data-crm-accent]');
        for (var i = 0; i < swatches.length; i++) {
            swatches[i].classList.toggle('is-active', swatches[i].getAttribute('data-crm-accent') === (accent || 'teal'));
        }

        var scaleInput = modal.querySelector('input[name="crm-c-scale"][value="' + (scale || 'md') + '"]');
        if (scaleInput) { scaleInput.checked = true; }
    }

    document.addEventListener('show.bs.offcanvas', function (event) {
        if (event.target && event.target.id === 'crm-settings') {
            syncCustomizeForm();
        }
    });

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || !input.name) { return; }

        if (input.name === 'crm-c-theme') {
            setTheme(input.value, input);
            return;
        }

        if (input.name === 'crm-c-scale') {
            applyScale(input.value);
            try {
                if (input.value === 'md') { localStorage.removeItem(SCALE_KEY); }
                else { localStorage.setItem(SCALE_KEY, input.value); }
            } catch (e) { /* private mode */ }
        }
    });

    document.addEventListener('click', function (event) {
        var swatch = event.target.closest && event.target.closest('[data-crm-accent]');
        if (swatch && swatch.classList.contains('crm-swatch')) {
            var accent = swatch.getAttribute('data-crm-accent');
            applyAccent(accent);
            try {
                if (accent === 'teal') { localStorage.removeItem(ACCENT_KEY); }
                else { localStorage.setItem(ACCENT_KEY, accent); }
            } catch (e) { /* private mode */ }

            var siblings = document.querySelectorAll('#crm-c-accents .crm-swatch');
            for (var i = 0; i < siblings.length; i++) {
                siblings[i].classList.toggle('is-active', siblings[i] === swatch);
            }
            return;
        }

        var reset = event.target.closest && event.target.closest('#crm-c-reset');
        if (reset) {
            applyAccent(null);
            applyScale(null);
            setTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            try {
                localStorage.removeItem(ACCENT_KEY);
                localStorage.removeItem(SCALE_KEY);
            } catch (e) { /* private mode */ }
            syncCustomizeForm();
        }
    });

    /* ---------- волна на клик ---------- */

    function initRipple() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        document.addEventListener('pointerdown', function (event) {
            var el = event.target.closest && event.target.closest('.btn, .dropdown-item, .list-group-item-action');
            if (!el) { return; }
            var rect = el.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height) * 1.2;
            var wave = document.createElement('span');
            wave.className = 'crm-ripple';
            wave.style.width = wave.style.height = size + 'px';
            wave.style.left = (event.clientX - rect.left - size / 2) + 'px';
            wave.style.top = (event.clientY - rect.top - size / 2) + 'px';
            el.appendChild(wave);
            wave.addEventListener('animationend', function () { wave.remove(); });
        });
    }

    /* ---------- живой поиск в топбаре ---------- */

    /* ---------- память скролла (страница и сайдбар) ---------- */

    var SCROLL_PAGE_KEY = 'crm-scroll';
    var SCROLL_PAGE_MAX = 24;

    function readJSON(key) {
        try {
            var raw = sessionStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeJSON(key, value) {
        try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ }
    }

    function initScrollMemory() {
        /* --- страница: запоминаем позицию каждой и возвращаемся по «Назад/Вперёд» --- */

        var url = location.pathname + location.search;
        var side = document.querySelector('#bm-sidebar .bm-dock__body, aside.navbar-vertical');
        var SIDEBAR_KEY = 'crm-sidebar-scroll';

        /* Синхронное хранилище трогаем при уходе со страницы, не при прокрутке. */
        window.addEventListener('pagehide', function () {
            var map = readJSON(SCROLL_PAGE_KEY) || {};
            delete map[url];
            map[url] = Math.round(window.scrollY);
            Object.keys(map).slice(0, -SCROLL_PAGE_MAX).forEach(function (key) { delete map[key]; });
            writeJSON(SCROLL_PAGE_KEY, map);
            if (side && side.clientHeight) { writeJSON(SIDEBAR_KEY, side.scrollTop); }
        });

        var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
        if (nav && (nav.type === 'back_forward' || nav.type === 'reload')) {
            var remembered = (readJSON(SCROLL_PAGE_KEY) || {})[url];
            if (typeof remembered === 'number' && remembered > 0) {
                /* мгновенно: из-за scroll-behavior:smooth страница сама «доезжала»
                   до запомненной позиции поверх входящих анимаций — выглядело как глюк */
                try {
                    window.scrollTo({ top: remembered, behavior: 'instant' });
                } catch (e) {
                    window.scrollTo(0, remembered);
                }
            }
        }

        /* --- сайдбар: позиция внутри списка разделов сохраняется всегда --- */

        if (side) {
            var prev = readJSON(SIDEBAR_KEY);
            if (typeof prev === 'number' && prev > 0) {
                if (side.clientHeight) { side.scrollTop = prev; }
                else {
                    side.addEventListener('shown.bs.collapse', function () {
                        side.scrollTop = prev;
                    }, { once: true });
                }
            }
        }
    }

    function initSearch() {
        var host = document.getElementById('crm-search');
        if (!host) { return; }
        var input = host.querySelector('.crm-search-input');
        var panel = host.querySelector('.crm-search-panel');
        if (!input || !panel) { return; }
        var hintURL = host.dataset.hintUrl || '';
        var fullURL = host.dataset.fullUrl || '';
        var timer = null;
        var hideTimer = null;
        var active = -1; /* индекс активного элемента в списке ссылок */
        var seq = 0; /* номер запроса: применяем только последний (C5) */

        function groupsHTML(data) {
            var icons = {
                employees: 'ti-user',
                tasks: 'ti-checkbox',
                documents: 'ti-file-text',
            };
            var captions = {
                employees: 'Сотрудники',
                tasks: 'Задачи',
                documents: 'Документы',
            };
            var html = '';
            ['employees', 'tasks', 'documents'].forEach(function (kind) {
                var list = data[kind] || [];
                if (list.length === 0) { return; }
                html += '<div class="crm-search-group"><div class="crm-search-caption"><i class="ti ' + icons[kind] + '"></i>' + captions[kind] + '</div>';
                list.forEach(function (item, index) {
                    html += '<a class="crm-search-item" role="option" id="crm-search-option-' + index + '" aria-selected="false" href="' + escapeHtml(item.url || '') + '">' +
                        '<span class="crm-search-item-title">' + escapeHtml(item.title) + '</span>' +
                        '<span class="crm-search-item-meta">' + escapeHtml(item.meta || '') + '</span></a>';
                });
                html += '</div>';
            });
            if (html === '') {
                html = '<div class="crm-search-empty">Ничего не найдено</div>';
            }
            html += '<div class="crm-search-footer"><span>Enter — открыть полный поиск</span>' +
                '<span><span class="crm-hint-key">↑↓</span> выбор</span></div>';
            return html;
        }

        /* FE-03: ошибки поиска (401/419/500/offline) должны быть видимы,
           а не тихо закрывать панель. */
        function showSearchError(status, requestSeq) {
            if (requestSeq !== seq) { return; }
            panel.removeAttribute('aria-busy');
            var message = status === 401 || status === 419 || status === 403
                ? 'Нет доступа или сессия истекла — обновите страницу'
                : status === 429
                    ? 'Слишком много запросов — повторите через минуту'
                    : status >= 500
                        ? 'Сервис поиска временно недоступен'
                        : 'Не удалось выполнить поиск — проверьте соединение';
            panel.innerHTML = '<div class="crm-search-error" role="alert">' + message + '</div>';
            show();
        }

        function escapeHtml(text) {
            return String(text).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        /* C5: пока ответ не пришёл — панель открыта с индикатором загрузки */
        function setLoading() {
            panel.setAttribute('aria-busy', 'true');
            panel.innerHTML = '<div class="crm-search-loading" role="status">' +
                '<span class="crm-spinner" aria-hidden="true"></span>Ищем…</div>';
            show();
        }

        function fetchHints(query) {
            var mySeq = ++seq;
            var url = hintURL + (hintURL.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(query);
            setLoading();
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (res) {
                    if (mySeq !== seq) { return null; } /* устаревший ответ — игнорируем */
                    if (!res.ok) {
                        showSearchError(res.status, mySeq);
                        return null;
                    }
                    return res.json().catch(function () { showSearchError(0, mySeq); return null; });
                })
                .then(function (data) {
                    if (mySeq !== seq || !data) { return; }
                    panel.removeAttribute('aria-busy');
                    panel.innerHTML = groupsHTML(data);
                    active = -1;
                    input.removeAttribute('aria-activedescendant');
                    show();
                })
                .catch(function () {
                    if (mySeq !== seq) { return; }
                    showSearchError(0, mySeq); /* offline: видимая ошибка вместо тихого закрытия */
                });
        }

        /* Панель открывается/закрывается классом .is-open — одна transition на
           opacity+transform (effects.css). Раньше здесь жили одновременно
           inline-стили и CSS-animation crm-pop: они перебивали друг друга,
           из-за чего панель выскакивала без плавности и исчезала рывком. */
        function show() {
            window.clearTimeout(hideTimer);
            panel.hidden = false;
            panel.inert = false;
            if (!panel.classList.contains('is-open')) {
                void panel.offsetWidth; /* закрываемое состояние должно отрисоваться до класса */
                panel.classList.add('is-open');
            }
            input.setAttribute('aria-expanded', 'true');
        }

        function hide() {
            window.clearTimeout(timer);
            seq++; /* ответ, пришедший после закрытия, не должен открыть панель заново */
            if (!panel.classList.contains('is-open')) { return; }
            panel.classList.remove('is-open');
            panel.inert = true;
            input.setAttribute('aria-expanded', 'false');
            active = -1;
            input.removeAttribute('aria-activedescendant');
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(function () {
                if (panel.classList.contains('is-open')) { return; } /* успели открыть снова */
                panel.hidden = true;
                panel.removeAttribute('aria-busy');
            }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 360);
        }

        function items() {
            return Array.prototype.slice.call(panel.querySelectorAll('.crm-search-item'));
        }

        function setActive(next) {
            var list = items();
            if (list.length === 0) { return; }
            active = (next + list.length) % list.length;
            list.forEach(function (el, i) {
                el.classList.toggle('is-active', i === active);
                el.setAttribute('aria-selected', i === active ? 'true' : 'false');
            });
            input.setAttribute('aria-activedescendant', list[active].id);
        }

        input.addEventListener('input', function () {
            var query = input.value.trim();
            window.clearTimeout(timer);
            seq++;
            if (query.length < 2) {
                hide();
                return;
            }
            timer = window.setTimeout(function () { fetchHints(query); }, 220);
        });

        input.addEventListener('focus', function () {
            var query = input.value.trim();
            if (query.length >= 2) { fetchHints(query); }
        });

        input.addEventListener('keydown', function (event) {
            var list = items();
            if (event.key === 'Escape') {
                hide();
                input.blur();
                return;
            }
            if (!panel.classList.contains('is-open') || list.length === 0) { return; }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(active - 1);
            } else if (event.key === 'Enter') {
                if (active >= 0 && list[active]) {
                    event.preventDefault();
                    window.location.href = list[active].getAttribute('href');
                }
                /* иначе: обычный submit на /search?q= */
            }
        });

        /* клик по подсказке — обычный переход, закрытие после ухода */
        panel.addEventListener('click', function () { hide(); });

        /* клик мимо — закрыть */
        document.addEventListener('pointerdown', function (event) {
            if (!host.contains(event.target)) { hide(); }
        });

        /* полный URL для футера: клики по нему ведут на /search — уже заложено формой */
        if (fullURL) {
            panel.addEventListener('click', function (event) {
                var footer = event.target.closest && event.target.closest('.crm-search-footer');
                if (footer) {
                    var form = host.querySelector('form');
                    if (form) { form.submit(); }
                }
            });
        }
    }

    /* --- C3: палитра команд Ctrl+K --- */
    function initCommandPalette() {
        var palette = document.getElementById('crm-palette');
        if (!palette) { return; }
        var input = document.getElementById('crm-palette-input');
        var list = document.getElementById('crm-palette-list');
        if (!input || !list) { return; }

        var commands = [];
        try { commands = JSON.parse(palette.getAttribute('data-commands') || '[]'); } catch (e) { commands = []; }
        var searchURL = palette.getAttribute('data-search-url') || '';
        var active = -1;
        var lastQuery = '';

        function escapeHtml(text) {
            return String(text).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        function filtered() {
            var q = input.value.trim().toLowerCase();
            lastQuery = q;
            if (q === '') { return commands; }
            return commands.filter(function (cmd) {
                return cmd.title.toLowerCase().indexOf(q) !== -1
                    || (cmd.section || '').toLowerCase().indexOf(q) !== -1;
            });
        }

        function render() {
            var items = filtered();
            var q = input.value.trim();
            var html = '';

            // быстрый поиск по системе (если право search.global есть)
            var offset = 0;
            if (searchURL && q.length >= 2) {
                html += '<a class="crm-palette__item" role="option" aria-selected="false" id="crm-palette-option-0" href="' +
                    escapeHtml(searchURL + '?q=' + encodeURIComponent(q)) + '" data-palette-search="1">' +
                    '<i class="ti ti-search"></i><span class="crm-palette__title">Найти: ' + escapeHtml(q) + '</span>' +
                    '<span class="crm-palette__meta">поиск по системе</span></a>';
                offset = 1;
            }

            items.forEach(function (cmd, index) {
                html += '<a class="crm-palette__item" role="option" aria-selected="false" id="crm-palette-option-' + (offset + index) + '" href="' + escapeHtml(cmd.url) + '">' +
                    '<i class="ti ti-' + escapeHtml(cmd.icon) + '"></i>' +
                    '<span class="crm-palette__title">' + escapeHtml(cmd.title) + '</span>' +
                    '<span class="crm-palette__meta">' + escapeHtml(cmd.section || '') + '</span></a>';
            });

            if (html === '') {
                html = '<div class="crm-palette__empty">Ничего не найдено</div>';
            }
            list.innerHTML = html;
            active = -1;
            input.removeAttribute('aria-activedescendant');
        }

        function items() {
            return Array.prototype.slice.call(list.querySelectorAll('.crm-palette__item'));
        }

        function setActive(next) {
            var all = items();
            if (all.length === 0) { return; }
            active = (next + all.length) % all.length;
            all.forEach(function (el, i) {
                el.classList.toggle('is-active', i === active);
                el.setAttribute('aria-selected', i === active ? 'true' : 'false');
            });
            input.setAttribute('aria-activedescendant', all[active].id);
            if (all[active].scrollIntoView) { all[active].scrollIntoView({ block: 'nearest' }); }
        }

        /* FE-01: пока палитра открыта, фон недоступен для фокуса (inert)
           и Tab циклически остаётся внутри диалога — даже если inert
           не поддерживается старым браузером. */
        function background() {
            return Array.prototype.slice.call(document.querySelectorAll('[data-crm-palette-background]'));
        }

        function setBackgroundInert(on) {
            background().forEach(function (el) {
                if (on) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
            });
        }

        function focusable() {
            var selector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
                'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
            return Array.prototype.slice.call(palette.querySelectorAll(selector)).filter(function (el) {
                return ! el.hidden && el.getClientRects().length > 0;
            });
        }

        function trapTab(event) {
            var nodes = focusable();
            if (nodes.length === 0) {
                event.preventDefault();
                input.focus({ preventScroll: true });
                return;
            }
            var first = nodes[0];
            var last = nodes[nodes.length - 1];
            if (event.shiftKey && (document.activeElement === first || ! palette.contains(document.activeElement))) {
                event.preventDefault();
                last.focus({ preventScroll: true });
            } else if (! event.shiftKey && (document.activeElement === last || ! palette.contains(document.activeElement))) {
                event.preventDefault();
                first.focus({ preventScroll: true });
            }
        }

        var closeTimer = null;
        var previousFocus = null;
        var scrollLocked = false;
        var originalPadRight = null;

        /* overflow:hidden на body убирает полосу прокрутки и сдвигает страницу —
           компенсируем отступом, как это делает Bootstrap для модалок */
        function lockScroll(on) {
            if (on === scrollLocked) { return; }
            scrollLocked = on;
            if (on) {
                originalPadRight = document.body.style.paddingRight;
                var gap = window.innerWidth - document.documentElement.clientWidth;
                document.body.style.paddingRight = gap > 0 ? gap + 'px' : originalPadRight;
                document.body.classList.add('crm-palette-open');
            } else {
                document.body.classList.remove('crm-palette-open');
                document.body.style.paddingRight = originalPadRight || '';
                originalPadRight = null;
            }
        }

        function open() {
            if (palette.classList.contains('is-open')) { return; }
            window.clearTimeout(closeTimer);
            previousFocus = document.activeElement;
            palette.hidden = false;
            palette.inert = false;
            input.value = '';
            render();
            void palette.offsetWidth; /* закрытое состояние рисуется до класса */
            palette.classList.add('is-open');
            setBackgroundInert(true);
            input.focus({ preventScroll: true });
            lockScroll(true);
        }

        function close() {
            if (!palette.classList.contains('is-open')) { return; }
            palette.classList.remove('is-open');
            palette.inert = true;
            input.removeAttribute('aria-activedescendant');
            setBackgroundInert(false);
            if (previousFocus && previousFocus.isConnected) {
                previousFocus.focus({ preventScroll: true });
            }
            previousFocus = null;
            window.clearTimeout(closeTimer);
            closeTimer = window.setTimeout(function () {
                if (palette.classList.contains('is-open')) { return; } /* успели открыть снова */
                palette.hidden = true;
                lockScroll(false);
            }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 360);
        }

        function toggle() {
            if (palette.classList.contains('is-open')) { close(); } else { open(); }
        }

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && (event.key === 'k' || event.key === 'K')) {
                event.preventDefault();
                toggle();
            }
        });

        document.addEventListener('click', function (event) {
            var opener = event.target.closest && event.target.closest('[data-crm-palette-open]');
            if (opener) { event.preventDefault(); open(); return; }
            var closer = event.target.closest && event.target.closest('[data-crm-palette-close]');
            if (closer && !palette.hidden) { close(); }
        });

        input.addEventListener('input', render);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); setActive(active + 1); return; }
            if (event.key === 'ArrowUp') { event.preventDefault(); setActive(active - 1); return; }
            if (event.key === 'Enter') {
                var all = items();
                if (active >= 0 && all[active]) {
                    event.preventDefault();
                    window.location.href = all[active].getAttribute('href');
                } else if (all.length > 0) {
                    event.preventDefault();
                    window.location.href = all[0].getAttribute('href');
                }
            }
        });
        palette.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key === 'Tab') { trapTab(event); }
        });

        list.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('.crm-palette__item')) { close(); }
        });
    }

    /* --- часы в шапке дашборда (живые, без лишних тиков) --- */
    function initClock() {
        var clock = document.getElementById('crm-clock');
        if (!clock) { return; }
        var tick = function () {
            try {
                clock.textContent = new Date().toLocaleTimeString('ru-RU', {
                    hour: '2-digit', minute: '2-digit', timeZone: clock.dataset.timeZone || undefined,
                });
            } catch (e) { /* ignore */ }
        };
        tick();
        window.setInterval(tick, 30000);
    }

    /* --- KPI-числа «набегают» при загрузке --- */
    function initCountUp() {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var nodes = document.querySelectorAll('.crm-kpi__value[data-count]');
        for (var i = 0; i < nodes.length; i++) {
            (function (node) {
                var target = parseInt(node.getAttribute('data-count'), 10);
                if (isNaN(target) || target < 2 || reduce) { return; }
                var start = null;
                var dur = 650;
                var step = function (ts) {
                    if (start === null) { start = ts; }
                    var p = Math.min(1, (ts - start) / dur);
                    node.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
                    if (p < 1) { window.requestAnimationFrame(step); }
                };
                node.textContent = '0';
                window.requestAnimationFrame(step);
            })(nodes[i]);
        }
    }

    /* --- Переключатель вида: [data-crm-switch] кнопки -> container с data-view,
           выбор запоминается в localStorage по data-key --- */
    function initViewSwitch() {
        var groups = document.querySelectorAll('[data-crm-switch]');
        for (var g = 0; g < groups.length; g++) {
            (function (group) {
                var key = group.getAttribute('data-crm-switch');
                var container = document.querySelector('[data-crm-view-container="' + key + '"]');
                if (!container) { return; }
                var buttons = group.querySelectorAll('button[data-view]');
                if (!buttons.length) { return; }
                var blocks = container.querySelectorAll('[data-view-only]');
                var apply = function (view, animate) {
                    if (container.dataset.view === view) { return; }
                    container.setAttribute('data-view', view);
                    for (var b = 0; b < buttons.length; b++) {
                        var selected = buttons[b].dataset.view === view;
                        buttons[b].classList.toggle('is-active', selected);
                        buttons[b].setAttribute('aria-pressed', String(selected));
                    }
                    for (var i = 0; i < blocks.length; i++) {
                        var visible = blocks[i].dataset.viewOnly === view;
                        blocks[i].hidden = !visible;
                        blocks[i].classList.toggle('crm-view-enter', visible && animate);
                    }
                    var input = document.querySelector('[data-crm-view-input="' + key + '"]') || document.querySelector('input[name="view"][data-crm-view-input]');
                    if (input) { input.value = view; }
                    try { localStorage.setItem('crm-view-' + key, view); } catch (e) { /* private */ }
                };
                var saved = null;
                try { saved = localStorage.getItem('crm-view-' + key); } catch (e) { /* private */ }
                var valid = Array.from(buttons).some(function (button) { return button.dataset.view === saved; });
                var initial = (valid && saved) || container.getAttribute('data-view-default') || buttons[0].dataset.view;
                apply(initial, false);
                group.addEventListener('click', function (event) {
                    var btn = event.target.closest && event.target.closest('button[data-view]');
                    if (btn) { apply(btn.dataset.view, true); }
                });
            })(groups[g]);
        }
    }

    /* --- Kanban: перетаскивание карточки между колонками -> PUT /tasks/{id}/status
       UI обновляется мгновенно (оптимистично), запрос уходит в фоне --- */
    function initKanban() {
        var board = document.querySelector('[data-kanban]');
        if (!board) { return; }
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var token = csrf ? csrf.getAttribute('content') : '';
        var dragged = null;
        var pending = {}; /* taskId -> true: пока запрос в полёте, повторных не шлём */

        function recount(col) {
            if (!col) { return; }
            var n = col.querySelectorAll('.crm-kanban__card').length;
            var badge = col.querySelector('.crm-kanban__count');
            if (badge) { badge.textContent = String(n); }
            var empty = col.querySelector('.crm-kanban__empty');
            if (n === 0 && !empty) {
                empty = document.createElement('div');
                empty.className = 'crm-kanban__empty';
                empty.textContent = 'Пусто';
                col.appendChild(empty);
            } else if (n > 0 && empty) {
                empty.remove();
            }
        }

        function moveCard(card, target, source) {
            card.classList.remove('is-dragging');
            target.appendChild(card);
            card.classList.add('is-just-moved');
            window.setTimeout(function () { card.classList.remove('is-just-moved'); }, 700);
            recount(target);
            if (source && source !== target) { recount(source); } /* бейдж исходной колонки тоже обновляем */
        }

        /* общий перенос: мгновенный UI-шаг + запрос статуса в фоне.
           Используется и drag&drop, и меню «переместить в колонку» (C4, тач). */
        function changeStatus(card, target) {
            var taskId = card.getAttribute('data-task-id');
            var status = target.getAttribute('data-status');
            var prev = card.closest('.crm-kanban__col');
            if (!taskId || !status || prev === target) { return; }

            /* 1) мгновенный перенос в UI + счётчики обеих колонок */
            moveCard(card, target, prev);

            /* 2) запрос статуса — в фоне, без блокировки и перезагрузки */
            if (pending[taskId]) { return; }
            pending[taskId] = true;
            card.setAttribute('aria-busy', 'true'); /* C5: состояние загрузки на время запроса */

            fetch('/tasks/' + taskId + '/status', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ status: status })
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (!res.ok) { throw new Error(data.message || ('Ошибка ' + res.status)); }
                    return data;
                });
            }).then(function () {
                if (window.crmToast) { window.crmToast('Статус обновлён', 'check'); }
            }).catch(function (err) {
                /* откат: возвращаем карточку на прежнее место, сообщаем причину */
                if (card.isConnected && prev && prev !== target) {
                    moveCard(card, prev, target);
                }
                if (window.crmToast) {
                    window.crmToast((err && err.message) ? err.message : 'Не удалось изменить статус', 'alert-triangle');
                }
            }).then(function () {
                card.removeAttribute('aria-busy');
                delete pending[taskId];
            });
        }

        /* --- C4: меню «переместить в колонку» (тач-альтернатива drag&drop) --- */

        function closeMenus() {
            var open = board.querySelectorAll('.crm-kanban__menu:not([hidden])');
            for (var i = 0; i < open.length; i++) {
                open[i].hidden = true;
                open[i].style.top = '';
                open[i].style.bottom = '';
                var btn = open[i].parentNode && open[i].parentNode.querySelector('[data-kanban-move]');
                if (btn) { btn.setAttribute('aria-expanded', 'false'); }
            }
        }

        /* доска имеет overflow — при нехватке места снизу меню открываем вверх */
        function positionMenu(menu) {
            menu.style.top = '';
            menu.style.bottom = '';
            var boardRect = board.getBoundingClientRect();
            var menuRect = menu.getBoundingClientRect();
            var limit = Math.min(boardRect.bottom, window.innerHeight || boardRect.bottom) - 4;
            if (menuRect.bottom <= limit) { return; }

            menu.style.top = 'auto';
            menu.style.bottom = '6px';
            if (menu.getBoundingClientRect().top < boardRect.top) {
                /* и сверху не влезло — оставляем обычное положение (доска прокрутится) */
                menu.style.top = '';
                menu.style.bottom = '';
            }
        }

        board.addEventListener('click', function (event) {
            var opener = event.target.closest && event.target.closest('[data-kanban-move]');
            if (opener) {
                event.preventDefault();
                event.stopPropagation();
                var menu = opener.parentNode && opener.parentNode.querySelector('.crm-kanban__menu');
                if (!menu) { return; }
                var willOpen = menu.hidden;
                closeMenus();
                menu.hidden = !willOpen;
                opener.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) { positionMenu(menu); }
                return;
            }

            var item = event.target.closest && event.target.closest('[data-move-to]');
            if (item) {
                event.preventDefault();
                var card = item.closest('.crm-kanban__card');
                var status = item.getAttribute('data-move-to');
                var col = board.querySelector('.crm-kanban__col[data-status="' + status + '"]');
                closeMenus();
                if (card && col) { changeStatus(card, col); }
                return;
            }

            /* клик вне открытого меню — закрываем */
            if (!event.target.closest || !event.target.closest('.crm-kanban__menu')) { closeMenus(); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeMenus(); }
        });

        board.addEventListener('dragstart', function (event) {
            var card = event.target.closest && event.target.closest('.crm-kanban__card');
            if (!card) { return; }
            dragged = card;
            card.classList.add('is-dragging');
            try { event.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || ''); } catch (e) { /* ie */ }
            event.dataTransfer.effectAllowed = 'move';
        });
        board.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('is-dragging'); }
            dragged = null;
            var cols = board.querySelectorAll('.crm-kanban__col');
            for (var i = 0; i < cols.length; i++) { cols[i].classList.remove('is-dragover'); }
        });
        board.addEventListener('dragover', function (event) {
            var col = event.target.closest && event.target.closest('.crm-kanban__col');
            if (!col || !dragged) { return; }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            var cols = board.querySelectorAll('.crm-kanban__col');
            for (var i = 0; i < cols.length; i++) { cols[i].classList.toggle('is-dragover', cols[i] === col); }
        });
        board.addEventListener('drop', function (event) {
            var col = event.target.closest && event.target.closest('.crm-kanban__col');
            if (!col || !dragged) { return; }
            event.preventDefault();
            changeStatus(dragged, col);
        });
    }

    /* --- Формы: защита от потери введённых данных (предупреждение при уходе) --- */
    function initDirtyGuard() {
        var forms = document.querySelectorAll('form[data-dirty-guard]');
        for (var i = 0; i < forms.length; i++) {
            (function (form) {
                var changed = false;
                form.addEventListener('input', function () { changed = true; });
                form.addEventListener('change', function () { changed = true; });
                form.addEventListener('submit', function () { changed = false; });
                window.addEventListener('beforeunload', function (event) {
                    if (changed) { event.preventDefault(); event.returnValue = ''; }
                });
            })(forms[i]);
        }
    }

    /* --- Кнопка сохранения: состояние «Сохраняем…» против двойного сабмита --- */
    function initSubmitGuard() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches || !form.matches('form[data-submit-guard]')) { return; }
            if (form.dataset.crmSubmitting === '1') { event.preventDefault(); return; }
            form.dataset.crmSubmitting = '1';
            // S9: маркер модалки живёт только до конца страницы — если ошибок
            // не было, он устарел и не должен открывать чужую модалку дальше
            var ownerModal = form.closest('.modal');
            if (ownerModal && ownerModal.id) {
                try { sessionStorage.setItem('crm-invalid-modal', ownerModal.id); } catch (e) { /* private mode */ }
            }
            var btn = form.querySelector('[type="submit"]');
            if (btn) {
                btn.dataset.crmLabel = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Сохраняем…';
            }
        }, true);
    }

    /* --- Быстрый фильтр строк/карточек: input[data-crm-filter="селектор"] --- */
    function initRowFilter() {
        var inputs = document.querySelectorAll('input[data-crm-filter]');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                var selector = input.getAttribute('data-crm-filter');
                var countSel = input.getAttribute('data-crm-filter-count');
                input.addEventListener('input', function () {
                    var q = input.value.trim().toLowerCase();
                    var items = document.querySelectorAll(selector);
                    var shown = 0;
                    for (var k = 0; k < items.length; k++) {
                        var match = !q || items[k].textContent.toLowerCase().indexOf(q) !== -1;
                        items[k].style.display = match ? '' : 'none';
                        if (match) { shown++; }
                    }
                    if (countSel) {
                        var counter = document.querySelector(countSel);
                        if (counter) { counter.textContent = 'Показано ' + shown; }
                    }
                });
            })(inputs[i]);
        }
    }

    /* --- Кнопка вне .nav, ведущая на вкладку: data-crm-tab="#tab-roles-btn" --- */
    function initTabJump() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest && event.target.closest('[data-crm-tab]');
            if (!trigger) { return; }
            var target = document.querySelector(trigger.getAttribute('data-crm-tab'));
            if (!target) { return; }
            event.preventDefault();
            target.click();
            if (typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ block: 'nearest', behavior: 'instant' });
            }
        });
    }

    /* --- Ссылка-якорь на модалку (например, из пустого состояния): href="#modalId" --- */
    function initModalAnchor() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('a[href^="#"]');
            if (!link) { return; }
            var hash = link.getAttribute('href');
            if (!hash || hash.length < 2 || typeof bootstrap === 'undefined' || !bootstrap.Modal) { return; }
            var target = null;
            try { target = document.querySelector(hash); } catch (e) { return; }
            if (!target || !target.classList.contains('modal')) { return; }
            event.preventDefault();
            bootstrap.Modal.getOrCreateInstance(target).show();
        });
    }

    /* --- Системные уведомления (toast) --- */
    var toastStack = null;
    function ensureToastStack() {
        if (toastStack && toastStack.isConnected) { return toastStack; }
        toastStack = document.querySelector('.crm-toast-stack');
        if (!toastStack) {
            toastStack = document.createElement('div');
            toastStack.className = 'crm-toast-stack';
            toastStack.setAttribute('aria-live', 'polite');
            toastStack.setAttribute('aria-atomic', 'false');
            document.body.appendChild(toastStack);
        } else if (toastStack.parentNode !== document.body) {
            document.body.appendChild(toastStack);
        }
        return toastStack;
    }

    var TOAST_ICONS = {
        success: 'circle-check', error: 'alert-triangle', danger: 'alert-triangle',
        warning: 'alert-circle', info: 'info-circle', download: 'download',
        check: 'circle-check', 'alert-circle': 'alert-circle', 'alert-triangle': 'alert-triangle'
    };

    function crmToast(message, type) {
        type = type || 'info';
        var icon = TOAST_ICONS[type] || type || 'info-circle';
        var tone = ({ check: 'success', download: 'info', 'alert-triangle': 'error', 'alert-circle': 'warning' })[type] || type;
        if (['success', 'error', 'danger', 'warning', 'info'].indexOf(tone) === -1) { tone = 'info'; }

        var stack = ensureToastStack();
        var el = document.createElement('div');
        el.className = 'crm-toast crm-toast--' + tone;
        el.setAttribute('role', tone === 'error' || tone === 'danger' ? 'alert' : 'status');
        el.innerHTML = ''
            + '<span class="crm-toast__icon"><i class="ti ti-' + icon + '"></i></span>'
            + '<div class="crm-toast__body"></div>'
            + '<button type="button" class="crm-toast__close" aria-label="Закрыть"><i class="ti ti-x"></i></button>';
        el.querySelector('.crm-toast__body').textContent = String(message || '');
        stack.appendChild(el);

        var closed = false;
        function close() {
            if (closed) { return; }
            closed = true;
            el.classList.remove('crm-toast--show');
            window.setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 260);
        }
        el.querySelector('.crm-toast__close').addEventListener('click', close);
        requestAnimationFrame(function () { el.classList.add('crm-toast--show'); });
        var ttl = tone === 'error' || tone === 'danger' ? 5200 : 3200;
        window.setTimeout(close, ttl);
        return { close: close, el: el };
    }
    window.crmToast = crmToast;

    /* --- Системное подтверждение (вместо window.confirm) --- */
    function ensureConfirmModal() {
        var modal = document.getElementById('crm-confirm-modal');
        if (modal) { return modal; }
        modal = document.createElement('div');
        modal.className = 'modal modal-blur fade';
        modal.id = 'crm-confirm-modal';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = ''
            + '<div class="modal-dialog modal-sm modal-dialog-centered" role="document">'
            + '  <div class="modal-content">'
            + '    <div class="modal-status bg-danger" data-crm-confirm-status></div>'
            + '    <div class="modal-body py-4">'
            + '      <div class="d-flex align-items-start gap-3">'
            + '        <div class="text-danger"><i class="ti ti-alert-triangle icon" style="font-size:1.5rem"></i></div>'
            + '        <div class="flex-fill">'
            + '          <h3 class="mb-2" data-crm-confirm-title>Подтверждение</h3>'
            + '          <div class="text-secondary" data-crm-confirm-body></div>'
            + '        </div>'
            + '      </div>'
            + '    </div>'
            + '    <div class="modal-footer">'
            + '      <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal" data-crm-confirm-cancel>Отмена</button>'
            + '      <button type="button" class="btn btn-danger" data-crm-confirm-ok>Подтвердить</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(modal);
        return modal;
    }

    function crmConfirm(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var modal = ensureConfirmModal();
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) { resolve(window.confirm(String(message || ''))); return; }
            var title = modal.querySelector('[data-crm-confirm-title]');
            var body = modal.querySelector('[data-crm-confirm-body]');
            var okBtn = modal.querySelector('[data-crm-confirm-ok]');
            var status = modal.querySelector('[data-crm-confirm-status]');
            var danger = opts.danger !== false;
            if (title) { title.textContent = opts.title || 'Подтверждение'; }
            if (body) { body.textContent = String(message || ''); }
            if (status) { status.className = 'modal-status ' + (danger ? 'bg-danger' : 'bg-primary'); }
            if (okBtn) {
                okBtn.textContent = opts.confirmText || 'Подтвердить';
                okBtn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
            }
            var cancelBtn = modal.querySelector('[data-crm-confirm-cancel]');
            if (cancelBtn) { cancelBtn.textContent = opts.cancelText || 'Отмена'; }

            var done = false;
            function finish(val) {
                if (done) { return; }
                done = true;
                okBtn.removeEventListener('click', onOk);
                modal.removeEventListener('hidden.bs.modal', onHide);
                resolve(val);
            }
            function onOk() { finish(true); bootstrap.Modal.getOrCreateInstance(modal).hide(); }
            function onHide() { finish(false); }
            okBtn.addEventListener('click', onOk);
            modal.addEventListener('hidden.bs.modal', onHide, { once: true });
            try {
                bootstrap.Modal.getOrCreateInstance(modal).show();
            } catch (e) {
                resolve(window.confirm(String(message || '')));
            }
        });
    }
    window.crmConfirm = crmConfirm;

    function initConfirmForms() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches || !form.matches('form[data-confirm]')) { return; }
            if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') { event.stopImmediatePropagation(); }
            var msg = form.getAttribute('data-confirm') || 'Подтвердите действие';
            var danger = form.getAttribute('data-confirm-danger') !== '0';
            var confirmText = form.getAttribute('data-confirm-ok') || 'Подтвердить';
            crmConfirm(msg, { danger: danger, confirmText: confirmText }).then(function (ok) {
                if (!ok) { return; }
                form.dataset.confirmed = '1';
                delete form.dataset.crmSubmitting;
                var submitBtn = form.querySelector('[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    if (submitBtn.dataset.crmLabel) { submitBtn.innerHTML = submitBtn.dataset.crmLabel; }
                }
                if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
                else { form.submit(); }
            });
        }, true);

        document.addEventListener('click', function (event) {
            var btn = event.target.closest && event.target.closest('[data-confirm-click]');
            if (!btn || btn.dataset.confirmed === '1') { return; }
            var msg = btn.getAttribute('data-confirm-click') || 'Подтвердите действие';
            event.preventDefault();
            event.stopPropagation();
            crmConfirm(msg, { danger: btn.getAttribute('data-confirm-danger') !== '0', confirmText: btn.getAttribute('data-confirm-ok') || 'Подтвердить' }).then(function (ok) {
                if (!ok) { return; }
                btn.dataset.confirmed = '1';
                btn.click();
                window.setTimeout(function () { delete btn.dataset.confirmed; }, 0);
            });
        }, true);
    }

    function initFlashToasts() {
        var nodes = document.querySelectorAll('[data-crm-flashes]');
        for (var i = 0; i < nodes.length; i++) {
            var raw = nodes[i].getAttribute('data-crm-flashes');
            if (!raw) { continue; }
            try {
                var items = JSON.parse(raw);
                for (var j = 0; j < items.length; j++) {
                    var it = items[j] || {};
                    if (it.message) { crmToast(it.message, it.tone || 'info'); }
                }
            } catch (e) { /* ignore */ }
            nodes[i].remove();
        }
    }

    function initExportFeedback() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('a[href*="/export"]');
            if (!link || link.dataset.crmExported === '1') { return; }
            link.dataset.crmExported = '1';
            window.setTimeout(function () { delete link.dataset.crmExported; }, 1500);
            crmToast('Экспорт сформирован — файл скачивается', 'download');
        });
    }

    /* --- Сайдбар: сворачиваемые группы разделов (состояние в localStorage) --- */
    function initNavGroups() {
        var KEY = 'crm-nav-groups';
        var state = null;
        try {
            state = JSON.parse(localStorage.getItem(KEY) || 'null') || {};
        } catch (e) {
            state = {};
        }

        var groups = document.querySelectorAll('[data-nav-group]');
        for (var i = 0; i < groups.length; i++) {
            (function (group) {
                var head = group.querySelector('[data-nav-group-toggle]');
                if (!head) { return; }
                var label = (group.querySelector('.crm-nav-group__label') || {}).textContent || '';
                label = label.trim();
                if (!label) { return; }

                /* активный раздел всегда раскрыт — иначе пользователь «теряет» текущую страницу */
                var hasActive = group.classList.contains('is-active-group');
                var collapsed = !hasActive && state[label] !== false;

                function apply() {
                    group.classList.toggle('is-collapsed', collapsed);
                    head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                }
                apply();

                head.addEventListener('click', function () {
                    collapsed = !collapsed;
                    state[label] = collapsed;
                    apply();
                    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
                });
            })(groups[i]);
        }
    }

    /* ========================================================================
       П.2 — «документы как документы»: TinyMCE для тел документов/шаблонов и
       select2 для длинных списков. Библиотеки локальные (public/assets/vendor)
       и грузятся лениво — только если на странице есть целевые элементы.
       ======================================================================== */

    var vendorJs = {};
    var vendorCss = {};

    function loadJs(url) {
        if (!vendorJs[url]) {
            vendorJs[url] = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = url;
                script.onload = function () { resolve(); };
                script.onerror = function () { reject(new Error('Не загрузился ' + url)); };
                document.head.appendChild(script);
            });
        }
        return vendorJs[url];
    }

    function loadCss(url) {
        if (!vendorCss[url]) {
            vendorCss[url] = new Promise(function (resolve) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                link.onload = link.onerror = function () { resolve(); };
                /* вставляем в НАЧАЛО head: правила effects.css должны идти
                   после чужих библиотечных стилей и перекрывать их */
                document.head.insertBefore(link, document.head.firstChild);
            });
        }
        return vendorCss[url];
    }

    /* ---------- TinyMCE: тело документа (#nd-body), шаблона (#ntpl-body)
       и любое поле с атрибутом data-crm-rich (описания, причины, комментарии) ---------- */

    var RICH_TARGETS = ['#nd-body', '#ntpl-body'];

    /* UX-07: при смене темы редакторы пересоздаются. Храним ссылки на их
       обработчики и снимаем их перед повторной инициализацией, иначе
       submit-подписчики (и toast’ы) накапливаются. */
    var richHandlers = new WeakMap();

    /* Fullscreen-плагин делает редактор position:fixed, но внутри .modal-blur
       он привязывается к модальному окну и выглядит криво. На время
       fullscreen переносим контейнер редактора в <body> и возвращаем на место
       после выхода — геометрия становится точной без переопределения скина. */
    var richFullscreenHosts = new WeakMap();

    /* Библиотеку грузим заранее: если пользователь открыл модалку и сразу
       начал печатать, редактор должен уже быть (или появиться без задержки). */
    var richJsPromise = null;

    function loadRichEditorJs() {
        if (!richJsPromise) { richJsPromise = loadJs('/assets/vendor/tinymce/tinymce.min.js'); }
        return richJsPromise;
    }

    function moveRichEditorToBody(editor) {
        var container = editor && editor.getContainer && editor.getContainer();
        if (!container || container.parentElement === document.body) { return; }
        richFullscreenHosts.set(editor, {
            parent: container.parentElement,
            next: container.nextSibling
        });
        document.body.appendChild(container);
    }

    function restoreRichEditorHost(editor) {
        var stored = editor && richFullscreenHosts.get(editor);
        if (!stored) { return; }
        var container = editor.getContainer && editor.getContainer();
        if (container && stored.parent && stored.parent.isConnected) {
            if (stored.next && stored.next.parentNode === stored.parent) {
                stored.parent.insertBefore(container, stored.next);
            } else {
                stored.parent.appendChild(container);
            }
        }
        richFullscreenHosts.delete(editor);
    }

    function detachRichEditor(textarea) {
        var stored = richHandlers.get(textarea);
        if (!stored) { return; }
        if (stored.form && stored.submit) { stored.form.removeEventListener('submit', stored.submit, true); }
        if (stored.modal && stored.shown) { stored.modal.removeEventListener('shown.bs.modal', stored.shown); }
        if (stored.modal && stored.hide) { stored.modal.removeEventListener('hide.bs.modal', stored.hide); }
        var editor = window.tinymce && typeof window.tinymce.get === 'function'
            ? window.tinymce.get(textarea.id)
            : null;
        if (editor) { restoreRichEditorHost(editor); }
        richHandlers.delete(textarea);
    }

    /* iframe редактора имеет собственный белый фон (даже с тёмным скином) —
       гасим его цветом темы, иначе в тёмной теме виден белый блок */
    function ensureEditorThemeStyles() {
        if (document.getElementById('crm-tiny-theme-style')) { return; }
        var style = document.createElement('style');
        style.id = 'crm-tiny-theme-style';
        style.textContent = 'html[data-bs-theme="dark"] .tox-edit-area__iframe { background-color: #171f2a !important; }\n' +
            'html[data-bs-theme="light"] .tox-edit-area__iframe { background-color: #ffffff !important; }';
        document.head.appendChild(style);
    }

    /* опции TinyMCE: скин и фон тела документа — по текущей теме.
       Высота подбирается под размер поля: короткие заметки — компактно,
       описания и тексты документов — полноценный редактор. */
    function richEditorHeight(textarea) {
        var explicit = parseInt(textarea.getAttribute('data-rich-height'), 10);
        if (explicit) { return explicit; }
        var rows = parseInt(textarea.getAttribute('rows'), 10) || 4;
        if (rows <= 2) { return 170; }
        if (rows <= 4) { return 230; }
        return 300;
    }

    function richEditorOptions(textarea) {
        var dark = currentTheme() === 'dark';
        return {
            selector: '#' + textarea.id,
            base_url: '/assets/vendor/tinymce',
            language: 'ru',
            license_key: 'gpl',
            skin: dark ? 'oxide-dark' : 'oxide',
            theme: 'silver',
            menubar: false,
            promotion: false,
            branding: false,
            resize: true,
            height: richEditorHeight(textarea),
            plugins: 'autolink lists link table code searchreplace wordcount fullscreen',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | bullist numlist | blockquote | link table | code fullscreen',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; line-height: 1.6; padding: 10px 14px; ' +
                (dark ? 'background-color: #171f2a; color: #f7f9fd; }' : 'background-color: #ffffff; color: #1e293b; }'),
            placeholder: textarea.getAttribute('placeholder') || '',
            /* живое событие для страниц (автодетект $переменных в шаблонах) */
            setup: function (editor) {
                function notify() {
                    textarea.dispatchEvent(new CustomEvent('crm:rich-input', {
                        bubbles: true,
                        detail: { content: editor.getContent() }
                    }));
                }
                editor.on('init input change undo redo', notify);
                editor.on('FullscreenStateChanged', function (event) {
                    if (event.state) { moveRichEditorToBody(editor); }
                    else { restoreRichEditorHost(editor); }
                });
                editor.on('remove', function () { restoreRichEditorHost(editor); });
            }
        };
    }

    /* смена темы: пересоздаём открытые редакторы, чтобы переключились скин
       (oxide/oxide-dark) и цвета тела; в закрытых модалках вешаемся на
       повторный показ — attachRichEditor переинитится сам.
       В TinyMCE 6 свойства editors больше нет — перечисляем через tinymce.get(). */
    function allRichEditors() {
        if (!window.tinymce || typeof window.tinymce.get !== 'function') { return []; }
        if (Array.isArray(window.tinymce.editors) && window.tinymce.editors.length) {
            return window.tinymce.editors.slice();
        }
        try {
            var list = window.tinymce.get();
            return Array.isArray(list) ? list.slice() : [];
        } catch (e) {
            return [];
        }
    }

    function retintRichEditors() {
        var editors = allRichEditors();
        if (!editors.length) { return; }
        for (var i = 0; i < editors.length; i++) {
            (function (editor) {
                var textarea = document.getElementById(editor.id);
                if (!textarea) { return; }
                try { editor.save(); } catch (e) { /* нечего сохранять */ }
                var modal = textarea.closest('.modal');
                var visible = !modal || modal.classList.contains('show');
                detachRichEditor(textarea);
                editor.remove();
                if (visible) {
                    window.tinymce.init(richEditorOptions(textarea));
                } else {
                    textarea.dataset.crmEditor = '';
                    attachRichEditor(textarea);
                }
            })(editors[i]);
        }
    }

    function initRichEditors() {
        ensureEditorThemeStyles();
        /*预热 только сам скрипт: инициализация всё равно идёт после показа модалки*/
        loadRichEditorJs().catch(function () { /* обычный textarea — fallback */ });
        var nodes = document.querySelectorAll('[data-crm-rich]');
        for (var i = 0; i < nodes.length; i++) {
            attachRichEditor(nodes[i]);
        }
        for (var j = 0; j < RICH_TARGETS.length; j++) {
            var textarea = document.querySelector(RICH_TARGETS[j]);
            if (textarea) { attachRichEditor(textarea); }
        }
    }

    function attachRichEditor(textarea) {
        if (textarea.dataset.crmEditor === '1') { return; }
        detachRichEditor(textarea);
        textarea.dataset.crmEditor = '1';

        var modal = textarea.closest('.modal');
        var started = false;
        var stored = { form: null, submit: null, modal: modal, shown: null, hide: null };
        richHandlers.set(textarea, stored);

        function start() {
            if (started) { return; }
            /* если модалку закрыли, пока грузился скрипт, инициализацию
               откладываем до следующего показа — иначе TinyMCE получает
               скрытый узел с нулевыми размерами и ломает ввод */
            if (modal && !modal.classList.contains('show')) { return; }
            started = true;
            loadRichEditorJs()
                .then(function () {
                    if (!window.tinymce) { textarea.dataset.crmEditor = 'failed'; return; }
                    if (modal && !modal.classList.contains('show')) { started = false; return; }
                    window.tinymce.init(richEditorOptions(textarea));
                    var editor = window.tinymce.get(textarea.id);
                    if (editor && modal) {
                        /* после показа модалки фокус часто остаётся на кнопке —
                           возвращаем его в поле ввода, чтобы печатать сразу */
                        var active = document.activeElement;
                        if (!active || !modal.contains(active) || active === document.body) {
                            editor.focus();
                        }
                    }
                })
                .catch(function () {
                    /* библиотека недоступна — остаётся обычный textarea */
                    textarea.dataset.crmEditor = 'failed';
                });
        }

        /* в закрытой модалке TinyMCE считает нулевые размеры — стартуем после показа */
        if (modal) {
            stored.shown = start;
            modal.addEventListener('shown.bs.modal', start);
            stored.hide = function () {
                var editor = window.tinymce && typeof window.tinymce.get === 'function'
                    ? window.tinymce.get(textarea.id)
                    : null;
                if (editor) { restoreRichEditorHost(editor); }
                else { started = false; }
            };
            modal.addEventListener('hide.bs.modal', stored.hide);
            if (modal.classList.contains('show')) { start(); }
        } else {
            start();
        }

        /* перед отправкой: синхронизация содержимого + видимая проверка required
           (скрытый TinyMCE-ом textarea не показывает нативную подсказку браузера) */
        var form = textarea.closest('form');
        if (form) {
            stored.form = form;
            stored.submit = function (event) {
                if (window.tinymce) { window.tinymce.triggerSave(); }
                if (textarea.hasAttribute('required') && !textarea.value.trim()) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (window.crmToast) { window.crmToast('Заполните текст — поле обязательно', 'alert-circle'); }
                    start();
                    var editor = window.tinymce && window.tinymce.get(textarea.id);
                    if (editor) { editor.focus(); }
                }
            };
            form.addEventListener('submit', stored.submit, true);
        }
    }

    /* ---------- select2: сотрудники, категории, маршруты, подписи ---------- */

    var SELECT2_TARGETS = [
        '#nd-category', '#ntpl-cat', '#ntpl-wf', '#ds-signers',
        '#ts-emp', '#rc-empl', '#be-empl', '#ns-employee', '#ep-dept', '#ep-pos',
        '#st-emp', '#user-employee',
        /* мультивыбор исполнителей задачи (сотрудники + отделы) */
        '#nt-employees', '#nt-departments',
        /* страны в полях телефона: поиск + группы (часто 2 селекта на странице) */
        'select[data-crm-phone-country]', 'select[data-chat-employee-select]'
    ];

    function initSelect2Fields() {
        for (var i = 0; i < SELECT2_TARGETS.length; i++) {
            var selects = document.querySelectorAll(SELECT2_TARGETS[i]);
            for (var j = 0; j < selects.length; j++) {
                attachSelect2(selects[j]);
            }
        }
    }

    /* Утилитарные стили, которых нет в общих css (их не редактируем):
       - селект страны внутри input-group: фиксированная ширина слева, номер
         справа; в закрытом состоянии длинное название обрезается многоточием
         (полностью видно в выпадающем списке с поиском);
       - поле телефона занимает всю строку формы — номеру нужно пространство;
       - .avatar-img: без object-fit фото 16:9 растягивалось в прямоугольник. */
    var CRM_UTIL_CSS = [
        '.crm-cc { flex: 0 0 auto; width: 230px; min-width: 0; }',
        '.crm-cc .select2-container { width: 100% !important; }',
        '.crm-cc .select2-container--default .select2-selection--single { border-start-end-radius: 0; border-end-end-radius: 0; }',
        '.crm-cc .select2-selection__rendered { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
        '.input-group > .crm-cc ~ .form-control { border-start-start-radius: 0; border-end-start-radius: 0; }',
        '[data-crm-phone-group] { flex-wrap: nowrap; }',
        '[data-crm-phone-group] > .form-control { min-width: 0; }',
        '.crm-form__row > .crm-form__field:has([data-crm-phone-group]) { grid-column: 1 / -1; }',
        '.avatar > .avatar-img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; border-radius: inherit; }'
    ].join('\n');

    function ensureUtilStyles() {
        if (document.getElementById('crm-util-style')) { return; }
        var style = document.createElement('style');
        style.id = 'crm-util-style';
        style.textContent = CRM_UTIL_CSS;
        document.head.appendChild(style);
    }

    function attachSelect2(select) {
        if (select.dataset.crmSelect2 === '1' || select.dataset.crmSelect2 === 'ready') { return; }
        if (select.classList.contains('crm-select__native') || select.closest('.crm-select')) { return; }
        select.dataset.crmSelect2 = '1';

        var modal = select.closest('.modal');

        function start() {
            if (select.dataset.crmSelect2 === 'ready') { return; }
            select.dataset.crmSelect2 = 'ready';

            Promise.all([
                loadJs('/assets/vendor/select2/jquery.min.js'),
                loadCss('/assets/vendor/select2/select2.min.css')
            ])
                .then(function () { return loadJs('/assets/vendor/select2/select2.min.js'); })
                .then(function () { return loadJs('/assets/vendor/select2/i18n/ru.js'); })
                .then(function () {
                    var $ = window.jQuery;
                    if (!$ || !$.fn.select2) { return; }
                    var multiple = select.hasAttribute('multiple');
                    /* селект страны: без очистки (всегда есть значение) и с
                       автоподбором ширины списка — названия не обрезаются */
                    var country = select.hasAttribute('data-crm-phone-country');
                    ensureUtilStyles();
                    /* плейсхолдер: data-placeholder > текст пустого первого option > «выбрать…» */
                    var first = select.options[0];
                    var ph = select.getAttribute('data-placeholder')
                        || ((first && first.value === '' && first.text.trim())
                            ? first.text.trim()
                            : (multiple ? 'выбрать…' : null));
                    var options = {
                        language: 'ru',
                        allowClear: !select.hasAttribute('required') && !country,
                        placeholder: ph,
                        /* В модальном окне список нельзя вешать на .modal: у него
                           overflow:auto, поэтому выпадающий список обрезается и
                           колесо мыши не прокручивает варианты. Список живёт в body,
                           а z-index ниже поднимается выше backdrop модалки. */
                        dropdownParent: modal ? $(document.body) : null,
                        dropdownAutoWidth: country
                    };
                    if (select.dataset.chatEmployeeSelect) {
                        options.width = '100%';
                        options.minimumInputLength = 2;
                        options.maximumInputLength = 120;
                        if (multiple) { options.maximumSelectionLength = 200; }
                        options.ajax = {
                            url: select.dataset.chatEmployeeSelect,
                            delay: 250,
                            dataType: 'json',
                            data: function (params) { return { q: params.term }; },
                            processResults: function (data) {
                                return { results: data.employees.map(function (row) {
                                    var name = [row.last_name, row.first_name].filter(Boolean).join(' ');
                                    return { id: String(row.id), text: [name, row.email].filter(Boolean).join(' · ') };
                                }) };
                            }
                        };
                    }
                    $(select).select2(options);
                    /* select2 шлёт change через jQuery — нативных слушателей
                       (dirty-guard и др.) он не достигает, досылаем вручную */
                    $(select).on('select2:select select2:clear select2:unselect', function () {
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                })
                .catch(function () {
                    /* библиотеки недоступны — остаётся обычный select */
                    select.dataset.crmSelect2 = 'failed';
                });
        }

        if (modal) {
            modal.addEventListener('shown.bs.modal', start);
            if (modal.classList.contains('show')) { start(); }
        } else {
            start();
        }
    }

    /* ========================================================================
       Поля-теги: значение вводится по одному, Enter (или запятая) добавляет
       тег-чип, Backspace в пустом поле удаляет последний. Поле с name остаётся
       обычным input — на сервер уходит строка через запятую (формат прежний).
       Включение: data-crm-tags на input.
       ======================================================================== */

    function initTagFields() {
        var fields = document.querySelectorAll('[data-crm-tags]');
        for (var i = 0; i < fields.length; i++) {
            attachTagField(fields[i]);
        }
    }

    function attachTagField(input) {
        if (input.dataset.crmTags === '1') { return; }
        input.dataset.crmTags = '1';

        var separator = ', ';
        var wrap = document.createElement('div');
        wrap.className = 'crm-tags';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('crm-tags__input');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');

        var list = document.createElement('div');
        list.className = 'crm-tags__list';
        wrap.appendChild(list);

        var items = [];

        function parseValue(raw) {
            return String(raw || '')
                .split(',')
                .map(function (v) { return v.trim(); })
                .filter(function (v, i, arr) { return v !== '' && arr.indexOf(v) === i; });
        }

        function render() {
            list.innerHTML = '';
            items.forEach(function (item, index) {
                var chip = document.createElement('span');
                chip.className = 'crm-tags__chip';
                chip.textContent = item;

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'crm-tags__x';
                remove.setAttribute('aria-label', 'Удалить тег ' + item);
                remove.innerHTML = '&times;';
                /* blur до клика перестраивает чипы — кнопка открепляется, и клик
                   по ней не срабатывает. Гасим blur на mousedown (фокус остаётся
                   на поле ввода), тогда click доходит до живой кнопки. */
                remove.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                remove.addEventListener('click', function () {
                    items.splice(index, 1);
                    render();
                    sync();
                    input.focus();
                });

                chip.appendChild(remove);
                list.appendChild(chip);
            });
            /* подсказка: сколько уже введено */
            hint.textContent = items.length > 0
                ? items.length + ' шт. · Enter — добавить'
                : (input.getAttribute('data-crm-tags-hint') || 'Enter — добавить тег');
        }

        var hint = document.createElement('div');
        hint.className = 'crm-tags__hint';
        wrap.appendChild(hint);

        function commit() {
            var pieces = parseValue(input.value);
            if (pieces.length === 0) { return false; }
            pieces.forEach(function (piece) {
                if (items.indexOf(piece) === -1) { items.push(piece); }
            });
            input.value = '';
            render();

            return true;
        }

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || (event.key === ',' && input.selectionStart === input.value.length)) {
                if (commit()) {
                    event.preventDefault();
                    event.stopPropagation();
                } else {
                    event.preventDefault(); /* пустой ввод не отправляет форму */
                }
                return;
            }
            if (event.key === 'Backspace' && input.value === '' && items.length > 0) {
                items.pop();
                render();
                event.preventDefault();
            }
        });

        /* уход с поля — дозаписываем то, что не успели добавить Enter'ом;
           поле ввода остаётся пустым — список показывают чипы (items — истина) */
        input.addEventListener('blur', function () {
            if (input.value.trim() !== '') { commit(); }
            sync();
        });

        /* items[] — источник истины, input — временный редактор: пока пользователь
           не печатает, поле пустое (иначе после первого blur перестаёт работать
           Backspace — удаление последнего тега) */
        function sync() {
            input.value = '';
        }

        /* форма уходит с текущим значением input — синхронизация перед отправкой */
        var form = input.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                commit(); /* хвост, введённый без Enter */
                input.value = items.join(separator); /* серверу уходит список */
            }, true);
        }

        /* начальное значение (правка существующей записи) */
        items = parseValue(input.value);
        input.value = '';
        render();

        /* внешнее добавление тегов (автодетект $переменных в шаблонах) */
        input.addEventListener('crm:tags-add', function (event) {
            var values = (event.detail && event.detail.values) || [];
            var added = false;
            for (var n = 0; n < values.length; n++) {
                var piece = String(values[n] || '').trim();
                if (piece !== '' && items.indexOf(piece) === -1) {
                    items.push(piece);
                    added = true;
                }
            }
            if (added) {
                render();
                sync();
            }
        });
    }

    /* ---------- маски ввода: цифры (ИНН) и телефон ---------- */

    /* Телефон: страна из селекта [data-crm-phone-country] (value=ISO, data-dial).
       RU/KZ делят +7 — в этом случае страну задаёт селект (приоритет пояса
       компании уже учтён в partials/phone-field). Иностранные форматы
       (неизвестный код) не трогаем. */
    function phoneMatchDial(digits, dials) {
        var best = '';
        for (var i = 0; i < dials.length; i++) {
            if (digits.indexOf(dials[i]) === 0 && dials[i].length > best.length) {
                best = dials[i];
            }
        }
        return best;
    }

    /* Формат: +7 (XXX) XXX-XX-XX · +1 (XXX) XXX-XX-XX · +375 (XX) XXX-XX-XX ·
       +992 XX XXX XX XX (Таджикистан) · остальные +DIAL XXX XXX XXX */
    function phoneFormat(dial, national) {
        var out = '+' + dial;
        var i;
        if (dial === '7' || dial === '1') {
            if (national.length > 0) { out += ' (' + national.slice(0, 3); }
            if (national.length >= 3) { out += ')'; }
            if (national.length > 3) { out += ' ' + national.slice(3, 6); }
            if (national.length > 6) { out += '-' + national.slice(6, 8); }
            if (national.length > 8) { out += '-' + national.slice(8, 10); }
            return out;
        }
        if (dial === '375') {
            if (national.length > 0) { out += ' (' + national.slice(0, 2); }
            if (national.length >= 2) { out += ')'; }
            if (national.length > 2) { out += ' ' + national.slice(2, 5); }
            if (national.length > 5) { out += '-' + national.slice(5, 7); }
            if (national.length > 7) { out += '-' + national.slice(7, 9); }
            return out;
        }
        if (dial === '992') {
            if (national.length > 0) { out += ' ' + national.slice(0, 2); }
            if (national.length > 2) { out += ' ' + national.slice(2, 5); }
            if (national.length > 5) { out += ' ' + national.slice(5, 7); }
            if (national.length > 7) { out += ' ' + national.slice(7, 9); }
            return out;
        }
        for (i = 0; i < national.length; i += 3) {
            out += ' ' + national.slice(i, i + 3);
        }
        return out;
    }

    /* Сколько национальных цифр влезает в номер: код => макс. цифр.
       Неизвестные коды — 9 (TJ/UZ/KG/MD…). */
    var PHONE_CAPS = {
        '1': 10,    /* США/Канада */
        '7': 10,    /* RU/KZ */
        '994': 10,  /* AZ */
        '86': 11,   /* CN */
        '55': 11,   /* BR */
        '91': 10,   /* IN */
        '81': 10,   /* JP */
        '82': 10,   /* KR */
        '90': 10,   /* TR */
        '30': 10,   /* GR */
        '44': 10,   /* GB */
        '49': 10,   /* DE */
        '39': 10,   /* IT */
        '52': 10,   /* MX */
        '54': 10,   /* AR */
        '43': 10,   /* AT */
        '375': 9,   /* BY */
        '992': 9,   /* TJ */
        '998': 9,   /* UZ */
        '996': 9,   /* KG */
        '995': 9,   /* GE */
        '380': 9,   /* UA */
        '374': 8,   /* AM */
        '993': 8,   /* TM */
        '373': 8,   /* MD */
        '46': 9, '47': 9, '48': 9, '41': 9, '45': 8, '31': 9, '32': 9, '33': 9,
        '34': 9, '351': 9, '353': 9, '358': 9, '420': 9, '40': 9, '359': 8,
        '36': 8, '370': 8, '371': 8, '372': 7, '971': 9, '972': 9
    };

    function phoneNationalCap(dial) {
        return PHONE_CAPS[dial] || 9;
    }

    function initInputMasks() {
        var digits = document.querySelectorAll('[data-crm-digits]');
        for (var i = 0; i < digits.length; i++) {
            (function (field) {
                field.addEventListener('input', function () {
                    var clean = field.value.replace(/\D/g, '');
                    var max = parseInt(field.getAttribute('maxlength') || '12', 10);
                    if (clean.length > max) { clean = clean.slice(0, max); }
                    if (field.value !== clean) { field.value = clean; }
                });
            })(digits[i]);
        }

        var phones = document.querySelectorAll('[data-crm-phone]');
        for (var j = 0; j < phones.length; j++) {
            (function (field) {
                var group = field.closest('[data-crm-phone-group]');
                var countrySel = group ? group.querySelector('[data-crm-phone-country]') : null;
                if (!countrySel) { return; } /* старая разметка без селекта — не трогаем */

                function dials() {
                    var out = [];
                    for (var k = 0; k < countrySel.options.length; k++) {
                        out.push((countrySel.options[k].getAttribute('data-dial') || '+7').replace(/^\+/, ''));
                    }
                    return out;
                }
                function selectedDial() {
                    var opt = countrySel.options[countrySel.selectedIndex];
                    return (opt && opt.getAttribute('data-dial') || '+7').replace(/^\+/, '');
                }
                function selectByDial(dial) {
                    /* общий код +7 (RU/KZ): страну не переключаем — её задаёт селект */
                    if (dial === selectedDial()) { return; }
                    for (var k = 0; k < countrySel.options.length; k++) {
                        var od = (countrySel.options[k].getAttribute('data-dial') || '').replace(/^\+/, '');
                        if (od === dial) {
                            countrySel.selectedIndex = k;
                            /* change синхронизирует select2 (он слушает нативное
                               событие) и переформатирует номер — идемпотентно */
                            countrySel.dispatchEvent(new Event('change', { bubbles: true }));
                            return;
                        }
                    }
                }
                function reformat() {
                    var raw = field.value.trim();
                    if (raw === '') { field.value = ''; return; }
                    var digits = raw.replace(/\D/g, '');
                    if (digits === '') { field.value = ''; return; }
                    /* чужой/неполный код — если не распознан, оставляем как есть */
                    var dial = phoneMatchDial(digits, dials());
                    if (dial === '') { return; }
                    selectByDial(dial);
                    var national = digits.slice(dial.length).slice(0, phoneNationalCap(dial));
                    field.value = phoneFormat(dial, national);
                }

                field.addEventListener('input', function () {
                    var caretEnd = field.selectionStart === field.value.length;
                    var digits = field.value.replace(/\D/g, '');
                    if (digits === '') { field.value = ''; return; }
                    var dial = phoneMatchDial(digits, dials());
                    if (dial === '') {
                        /* цифры без кода — добавляем код выбранной страны */
                        dial = selectedDial();
                        digits = dial + digits.slice(0, phoneNationalCap(dial));
                    } else {
                        selectByDial(dial);
                    }
                    var national = digits.slice(dial.length).slice(0, phoneNationalCap(dial));
                    var out = phoneFormat(dial, national);
                    if (field.value !== out && caretEnd) {
                        field.value = out; /* курсор не в конце — не дёргаем при правке */
                    } else if (field.value !== out && !caretEnd) {
                        field.value = out;
                    }
                });

                /* потеря фокуса — финальная нормализация (в т.ч. при попытке
                   вставить что-то экзотическое) */
                field.addEventListener('blur', function () {
                    if (field.value.trim() !== '') { reformat(); }
                });

                /* смена страны: подменяем префикс, если номер был в старом формате */
                countrySel.addEventListener('change', function () {
                    var dialPlaceholder = (countrySel.options[countrySel.selectedIndex].getAttribute('data-dial') || '+7');
                    field.placeholder = dialPlaceholder === '+992' ? '+992 XX XXX XX XX' : dialPlaceholder + ' …';
                    var digits = field.value.replace(/\D/g, '');
                    if (digits === '') {
                        return;
                    }
                    var old = phoneMatchDial(digits, dials());
                    var dial = selectedDial();
                    var national = old !== '' ? digits.slice(old.length) : digits;
                    national = national.slice(0, phoneNationalCap(dial));
                    field.value = phoneFormat(dial, national);
                });

                /* автоподбор страны по часовому поясу компании (формы компаний):
                   селект пояса лежит рядом с partial, ищем по всей форме */
                var tzSelect = (group && group.querySelector('select[name="timezone"]'))
                    || field.closest('form') && field.closest('form').querySelector('select[name="timezone"]');
                if (tzSelect) {
                    tzSelect.addEventListener('change', function () {
                        var map = {};
                        try { map = JSON.parse(group.getAttribute('data-crm-tz-map') || '{}'); } catch (e) { map = {}; }
                        var iso = map[tzSelect.value];
                        if (!iso) { return; }
                        for (var k = 0; k < countrySel.options.length; k++) {
                            if (countrySel.options[k].value === iso) {
                                countrySel.selectedIndex = k;
                                countrySel.dispatchEvent(new Event('change'));
                                break;
                            }
                        }
                    });
                }

                /* нормализуем уже введённое значение при загрузке */
                if (field.value.trim() !== '') { reformat(); }
            })(phones[j]);
        }
    }

    /* ========================================================================
       П.6 — настраиваемые секции экрана (на всех страницах с [data-crm-sec]):
       - ширина соседних секций тянется за разделитель (как окна), состояние
         хранится по адресу страницы в localStorage;
       - шестерёнка внизу экрана открывает offcanvas вкл/откл секций
         (по образцу панели уведомлений);
       - разметка: [data-crm-sec="key"] (+ необязательно data-crm-sec-label).
       ======================================================================== */

    var SEC_WIDTHS_KEY = 'crm-sec-widths';
    var SEC_VIS_KEY = 'crm-sec-visibility';
    var SEC_MIN_PX = 220; /* минимальная ширина секции при перетаскивании */

    function secRead(key) {
        try { return JSON.parse(localStorage.getItem(key) || 'null') || {}; } catch (e) { return {}; }
    }
    function secWrite(key, data) {
        try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) { /* private mode */ }
    }
    function secEscape(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var SEC_BPS = [['sm', 576], ['md', 768], ['lg', 992], ['xl', 1200], ['xxl', 1440]];

    function secChildGate(el) {
        var cls = ' ' + el.className + ' ';
        /* колонка на всю ширину — «в ряд» она не встаёт, ресайза нет */
        if (/(^|\s)col-(sm|md|lg|xl|xxl)-12(\s|$)/.test(cls) || /(^|\s)col-12(\s|$)/.test(cls) || /(^|\s)col(\s|$)/.test(cls)) {
            return null;
        }
        /* первый брейкпоинт, на котором колонка уже встаёт в ряд */
        for (var i = 0; i < SEC_BPS.length; i++) {
            if (new RegExp('(^|\\s)col-' + SEC_BPS[i][0] + '-\\d').test(cls)) { return SEC_BPS[i][1]; }
        }
        return null;
    }

    function autoAnnotateSections() {
        var rows = document.querySelectorAll('.row');
        var rowIndex = 0;
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (row.closest('.modal, .offcanvas, .dropdown-menu')) { continue; }

            var kids = [];
            var ok = true;
            for (var c = 0; c < row.children.length; c++) {
                var child = row.children[c];
                if (child.nodeType !== 1) { continue; }
                if (!child.hasAttribute('data-crm-sec')
                    && !/(^|\s)col(-|\s)/.test(' ' + child.className + ' ')) {
                    ok = false; /* посторонний элемент — это не секционная раскладка */
                    break;
                }
                kids.push(child);
            }
            if (!ok || kids.length < 2 || kids.length > 3) { continue; }

            /* все колонки должны когда-либо вставать в ряд (col-12/col — нет) */
            var assign = false;
            for (var k = 0; k < kids.length; k++) {
                if (secChildGate(kids[k]) === null) { ok = false; break; }
                if (!kids[k].hasAttribute('data-crm-sec')) { assign = true; }
            }
            if (!ok || !assign) { continue; }

            for (var k2 = 0; k2 < kids.length; k2++) {
                if (!kids[k2].hasAttribute('data-crm-sec')) {
                    kids[k2].setAttribute('data-crm-sec', 'a' + rowIndex + 'c' + k2);
                }
            }
            rowIndex++;
        }

        /* блоки метрик верхней части страницы */
        var metrics = document.querySelectorAll('.crm-metrics');
        var mKey = 'metrics';
        var mN = 1;
        for (var m = 0; m < metrics.length; m++) {
            if (metrics[m].hasAttribute('data-crm-sec') || metrics[m].closest('[data-crm-sec]')) { continue; }
            while (document.querySelector('[data-crm-sec="' + mKey + '"]')) {
                mN++;
                mKey = 'metrics-' + mN;
            }
            metrics[m].setAttribute('data-crm-sec', mKey);
            metrics[m].setAttribute('data-crm-sec-label', 'Метрики');
            mKey = 'metrics';
            mN = 1;
        }
    }

    function initSectionConfig() {
        /* ---------- авто-разметка: строки-«секции» есть на любой странице ----------
           Строка (не внутри модалок/панелей) из 2–3 колонок целиком состоит из
           col-* → это раскладка секций; блоки .crm-metrics тоже секции.
           Явные [data-crm-sec] в разметке имеют приоритет (ключи и подписи). */
        autoAnnotateSections();

        var sections = document.querySelectorAll('[data-crm-sec]');
        if (!sections.length) { return; }

        var path = location.pathname;
        var widthStore = secRead(SEC_WIDTHS_KEY);
        var visStore = secRead(SEC_VIS_KEY);
        var widthPath = widthStore[path] || {};
        var visPath = visStore[path] || {};

        /* ---------- подписи для offcanvas ---------- */
        function labelOf(sec) {
            var explicit = sec.getAttribute('data-crm-sec-label');
            if (explicit) { return explicit; }
            var h = sec.querySelector('h3');
            if (h) {
                var clone = h.cloneNode(true);
                var drop = clone.querySelectorAll('span, button, small, a');
                for (var d = 0; d < drop.length; d++) { drop[d].parentNode.removeChild(drop[d]); }
                var t = (clone.textContent || '').replace(/\s+/g, ' ').trim();
                if (t) { return t; }
            }
            var strong = sec.querySelector('.card-header strong, .card-title, .crm-entity__title, .page-title');
            if (strong && strong.textContent.trim()) { return strong.textContent.trim(); }
            var text = (sec.textContent || '').replace(/\s+/g, ' ').trim();
            if (text) { return text.length > 48 ? text.slice(0, 48).trim() + '…' : text; }
            return 'Секция';
        }

        /* ---------- видимость секций ---------- */
        var refreshers = []; /* applyWidths() каждой строки */
        for (var i = 0; i < sections.length; i++) {
            var key0 = sections[i].getAttribute('data-crm-sec');
            sections[i].classList.toggle('crm-sec--off', visPath[key0] === false);
        }

        /* ---------- строки: разделители и ширины ---------- */
        var rows = [];
        for (var s = 0; s < sections.length; s++) {
            var row0 = sections[s].closest('.row');
            if (row0 && rows.indexOf(row0) === -1) { rows.push(row0); }
        }

        for (var r = 0; r < rows.length; r++) {
            (function (row) {
                var kids = [];
                for (var c = 0; c < row.children.length; c++) {
                    var child = row.children[c];
                    if (child.hasAttribute && child.hasAttribute('data-crm-sec')) { kids.push(child); }
                }
                if (kids.length < 2) { return; }

                var gate = 0;
                for (var k = 0; k < kids.length; k++) {
                    var g = secChildGate(kids[k]);
                    if (g === null) { return; } /* колонки не встают в ряд */
                    gate = Math.max(gate, g);
                }

                function gateOn() { return window.matchMedia('(min-width: ' + gate + 'px)').matches; }
                function rowW() { return row.getBoundingClientRect().width || 1; }
                function pctOf(el, total) { return Math.round((el.getBoundingClientRect().width / total) * 10000) / 100; }

                function sizeTo(el, pct) {
                    el.classList.add('crm-sec--sized');
                    el.style.setProperty('--crm-sec-w', pct + '%');
                }

                /* ---------- визуальные ряды колонок внутри строки ----------
                   Колонки, встающие в один физический ряд, пересекаются по
                   вертикали; уехавшая вниз колонка — уже другой ряд. */
                function sameLine(a, b) {
                    var ra = a.getBoundingClientRect();
                    var rb = b.getBoundingClientRect();
                    var overlap = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
                    if (overlap <= 0) { return false; }
                    return overlap >= 0.5 * Math.min(ra.height, rb.height);
                }

                function layoutLines() {
                    var lines = [];
                    for (var k = 0; k < kids.length; k++) {
                        var kid = kids[k];
                        if (kid.classList.contains('crm-sec--off')) { continue; }
                        var line = null;
                        for (var l = 0; l < lines.length; l++) {
                            if (sameLine(lines[l][0], kid)) { line = lines[l]; break; }
                        }
                        if (line) { line.push(kid); } else { lines.push([kid]); }
                    }
                    return lines;
                }

                /* Последний ряд неполон (3 карточки по col-xl-6, 5 по col-xl-4…) —
                   колонки растягиваются на всю ширину строки, иначе внизу остаётся
                   полупустая дыра. Колонка «в одиночестве» — её секция к тому же
                   центрируется с ограничением ширины (классы, не инлайн). */
                function updateLines() {
                    var lines = layoutLines();
                    var maxN = 0;
                    for (var l = 0; l < lines.length; l++) {
                        maxN = Math.max(maxN, lines[l].length);
                    }
                    var last = lines.length > 1 ? lines[lines.length - 1] : [];
                    var target = (last.length && last.length < maxN) ? last : [];

                    row.classList.toggle('crm-row--partial', target.length > 0);

                    for (var k = 0; k < kids.length; k++) {
                        var on = target.indexOf(kids[k]) !== -1;
                        kids[k].classList.toggle('crm-sec--stretch', on);
                        kids[k].classList.toggle('crm-sec--stretch-solo', on && target.length === 1);
                        if (on) {
                            kids[k].style.setProperty('--crm-stretch-w', (100 / target.length).toFixed(4) + '%');
                        } else {
                            kids[k].style.removeProperty('--crm-stretch-w');
                        }
                    }
                }

                function relayout() {
                    updateLines();
                    positionHandles();
                    refreshRzEdges();
                }

                function applyWidths() {
                    var haveAll = true;
                    for (var k2 = 0; k2 < kids.length; k2++) {
                        if (typeof widthPath[kids[k2].getAttribute('data-crm-sec')] !== 'number') { haveAll = false; break; }
                    }
                    var active = gateOn() && haveAll;
                    for (var k3 = 0; k3 < kids.length; k3++) {
                        var key3 = kids[k3].getAttribute('data-crm-sec');
                        if (active) { sizeTo(kids[k3], widthPath[key3]); }
                        else {
                            kids[k3].classList.remove('crm-sec--sized');
                            kids[k3].style.removeProperty('--crm-sec-w');
                        }
                    }
                    relayout();
                }

                function positionHandles() {
                    var on = gateOn();
                    row.classList.toggle('crm-row--resizable', on);
                    if (!on) { return; }
                    var rowRect = row.getBoundingClientRect();
                    for (var h = 0; h < handles.length; h++) {
                        var it = handles[h];
                        /* скрытая секция или колонки из разных физических рядов —
                           перетаскивать между ними нечего */
                        if (it.a.classList.contains('crm-sec--off') || it.b.classList.contains('crm-sec--off')
                            || !sameLine(it.a, it.b)) {
                            it.el.style.display = 'none';
                            continue;
                        }
                        it.el.style.display = '';
                        var ar = it.a.getBoundingClientRect();
                        var br = it.b.getBoundingClientRect();
                        var padR = parseFloat(window.getComputedStyle(it.a).paddingRight) || 0;
                        var padL = parseFloat(window.getComputedStyle(it.b).paddingLeft) || 0;
                        /* центр «желоба» между контентами соседних колонок */
                        it.el.style.left = ((ar.right - padR + br.left + padL) / 2 - rowRect.left) + 'px';
                    }
                }

                function persistRow() {
                    var total = rowW();
                    var ok = true;

                    /* Растянутые колонки последнего ряда меряем «как будто» их не
                       растягивали: иначе в localStorage улетит 100% и раскладка
                       поедет, когда колонки снова встанут в один ряд. */
                    var stretched = [];
                    for (var k0 = 0; k0 < kids.length; k0++) {
                        if (kids[k0].classList.contains('crm-sec--stretch')) {
                            stretched.push(kids[k0]);
                            kids[k0].classList.remove('crm-sec--stretch');
                            kids[k0].classList.remove('crm-sec--stretch-solo');
                        }
                    }

                    for (var k4 = 0; k4 < kids.length; k4++) {
                        var kid = kids[k4];
                        var key4 = kid.getAttribute('data-crm-sec');
                        if (kid.classList.contains('crm-sec--off')) {
                            if (typeof widthPath[key4] !== 'number') { ok = false; }
                            continue;
                        }
                        widthPath[key4] = pctOf(kid, total);
                    }
                    if (ok) { widthStore[path] = widthPath; secWrite(SEC_WIDTHS_KEY, widthStore); }

                    for (var k5 = 0; k5 < stretched.length; k5++) {
                        stretched[k5].classList.add('crm-sec--stretch');
                        if (stretched.length === 1) { stretched[k5].classList.add('crm-sec--stretch-solo'); }
                    }
                }

                /* разделители между соседними секциями */
                var handles = [];
                for (var h2 = 0; h2 < kids.length - 1; h2++) {
                    (function (a, b) {
                        var handle = document.createElement('div');
                        handle.className = 'crm-resize-handle';
                        handle.setAttribute('role', 'separator');
                        handle.setAttribute('aria-orientation', 'vertical');
                        handle.setAttribute('title', 'Потяните, чтобы изменить ширину секций');

                        handle.addEventListener('pointerdown', function (event) {
                            if (!gateOn()) { return; }
                            var total = rowW();
                            if (total < 10 || a.classList.contains('crm-sec--off') || b.classList.contains('crm-sec--off')) { return; }
                            event.preventDefault();

                            var startX = event.clientX;
                            var pctA0 = pctOf(a, total);
                            var pctB0 = pctOf(b, total);
                            var pair = pctA0 + pctB0;
                            var minPct = (SEC_MIN_PX / total) * 100;
                            var lo = Math.min(minPct, pair / 2);
                            var hi = pair - lo;

                            handle.classList.add('is-dragging');
                            document.body.classList.add('crm-sec-dragging');

                            function onMove(e) {
                                var nextA = Math.round(Math.min(hi, Math.max(lo, pctA0 + ((e.clientX - startX) / total) * 100)) * 100) / 100;
                                var nextB = Math.round((pair - nextA) * 100) / 100;
                                sizeTo(a, nextA);
                                sizeTo(b, nextB);
                                relayout();
                            }
                            function onUp() {
                                document.removeEventListener('pointermove', onMove);
                                document.removeEventListener('pointerup', onUp);
                                document.removeEventListener('pointercancel', onUp);
                                handle.classList.remove('is-dragging');
                                document.body.classList.remove('crm-sec-dragging');
                                persistRow();
                            }
                            document.addEventListener('pointermove', onMove);
                            document.addEventListener('pointerup', onUp);
                            document.addEventListener('pointercancel', onUp);
                        });

                        row.appendChild(handle);
                        handle._crmA = a;
                        handle._crmB = b;
                        handles.push({ el: handle, a: a, b: b });
                    })(kids[h2], kids[h2 + 1]);
                }

                refreshers.push(applyWidths);
                applyWidths();

                var mq = window.matchMedia('(min-width: ' + gate + 'px)');
                if (mq.addEventListener) { mq.addEventListener('change', applyWidths); }
                else if (mq.addListener) { mq.addListener(applyWidths); }

                if (typeof ResizeObserver !== 'undefined') {
                    /* колонки стали видимы (переключение видов) или сменили размер */
                    new ResizeObserver(function () { relayout(); }).observe(row);
                }
                window.addEventListener('resize', relayout);
            })(rows[r]);
        }

        /* ---------- offcanvas «Настройка экрана» + шестерёнка ---------- */
        var items = [];
        var seen = {};
        for (var q = 0; q < sections.length; q++) {
            var keyQ = sections[q].getAttribute('data-crm-sec');
            if (seen[keyQ]) { continue; }
            seen[keyQ] = true;
            items.push({ key: keyQ, label: labelOf(sections[q]) });
        }

        var listHtml = '';
        for (var n = 0; n < items.length; n++) {
            var checked = visPath[items[n].key] !== false ? ' checked' : '';
            listHtml += '<li class="list-group-item px-3 d-flex justify-content-between align-items-center gap-3">'
                + '<div class="fw-semibold">' + secEscape(items[n].label) + '</div>'
                + '<div class="form-check form-switch m-0">'
                + '<input class="form-check-input" type="checkbox" role="switch" data-crm-sec-key="' + secEscape(items[n].key) + '"' + checked + '>'
                + '</div></li>';
        }

        /* секции живут в общей панели «Настройки» (#crm-settings) */
        var listHost = document.getElementById('crm-sec-list');
        if (!listHost) { return; }
        listHost.innerHTML = listHtml;

        var secItem = document.getElementById('crm-sec-block');
        if (secItem) { secItem.hidden = false; }

        var panel = document.getElementById('crm-settings') || listHost;

        function refreshAll() {
            for (var f = 0; f < refreshers.length; f++) { refreshers[f](); }
        }

        panel.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || !input.hasAttribute || !input.hasAttribute('data-crm-sec-key')) { return; }
            var key = input.getAttribute('data-crm-sec-key');
            visPath[key] = input.checked;
            visStore[path] = visPath;
            secWrite(SEC_VIS_KEY, visStore);
            for (var v = 0; v < sections.length; v++) {
                if (sections[v].getAttribute('data-crm-sec') === key) {
                    sections[v].classList.toggle('crm-sec--off', !input.checked);
                }
            }
            refreshAll();
        });

        panel.addEventListener('click', function (event) {
            var reset = event.target.closest && event.target.closest('[data-crm-sec-reset]');
            if (!reset) { return; }
            delete widthStore[path];
            delete visStore[path];
            secWrite(SEC_WIDTHS_KEY, widthStore);
            secWrite(SEC_VIS_KEY, visStore);
            widthPath = {};
            visPath = {};
            var inputs = panel.querySelectorAll('[data-crm-sec-key]');
            for (var t = 0; t < inputs.length; t++) { inputs[t].checked = true; }
            for (var w = 0; w < sections.length; w++) {
                sections[w].classList.remove('crm-sec--off');
                sections[w].classList.remove('crm-sec--sized');
                sections[w].style.removeProperty('--crm-sec-w');
            }
            refreshAll();
            if (window.crmToast) { window.crmToast('Настройки экрана сброшены', 'restore'); }
        });

        /* отдельная шестёрёнка больше не создаётся: секции живут в общей
           панели «Настройки», которую открывает кнопка в шапке (#crm-settings) */
    }

    /* =======================================================================
       Секции страницы: авто-подстройка под ширину + ручной ресайз.

       Авто-раскладка: контейнер, где лежат только секции (или .row с
       колонками-секциями), превращается в гибкую сетку — на широком экране
       несколько секций встают в ряд и заполняют пустые места, одна секция
       центрируется с аккуратной максимальной шириной.

       Ресайз: у страницных секций появляются восемь «ручек» по краям и
       углам — тяните мышью в любую сторону; двойной клик по ручке
       возвращает исходный размер. Размер запоминается для страницы.
       ======================================================================= */

    var CRM_SECTIONS_CSS = [
        /* --- авто-сетка --- */
        '.crm-auto-grid { display: flex; flex-wrap: wrap; gap: 14px; margin: 0; }',
        '.crm-auto-grid > * { flex: 1 1 340px; width: auto; min-width: 0; margin: 0; }',
        '.crm-auto-grid > [class*="col-"] { padding-left: 0; padding-right: 0; }',
        '.crm-auto-grid > .crm-form__section, .crm-auto-grid > * > .crm-form__section { margin-bottom: 0; }',
        /* одна секция — аккуратно: не на всю ширину свободного экрана */
        '.crm-auto-grid--single > .crm-form__section,',
        '.crm-auto-grid--single > * > .crm-form__section { max-width: 1040px; margin-left: auto; margin-right: auto; }',

        /* последний ряд колонок неполон (например, 3 карточки по col-xl-6) —
           растягиваем его колонки, чтобы в строке не зияла полупустая дыра.
           flex-basis берётся из --crm-stretch-w: гипотетический размер равен
           ширине колонки, поэтому порядок переносов в ряд не меняется. */
        '.crm-row--partial > .crm-sec--stretch {',
        '    flex-grow: 1; flex-shrink: 0; flex-basis: auto;',
        '    width: var(--crm-stretch-w, 100%); max-width: none;',
        '}',
        /* единственная в таком ряду секция — аккуратно по центру и не в полную ширину */
        '.crm-row--partial > .crm-sec--stretch-solo > .crm-form__section,',
        '.crm-row--partial > .crm-sec--stretch-solo > .card {',
        '    max-width: 1040px; margin-left: auto; margin-right: auto;',
        '}',

        /* --- ручной ресайз: ручки по всем сторонам и углам --- */
        '.crm-resizable { position: relative; }',
        '.crm-resizable--sized { overflow: auto; }',
        '.crm-auto-grid > .crm-resizable--sized { flex-grow: 0; flex-shrink: 0; flex-basis: auto; align-self: flex-start; }',
        '.crm-rz-wrap { position: absolute; inset: 0; z-index: 40; pointer-events: none; }',
        '.crm-rz { position: absolute; z-index: 40; pointer-events: auto; }',
        '.crm-rz-n { top: 0; left: 12px; right: 12px; height: 7px; cursor: ns-resize; }',
        '.crm-rz-s { bottom: 0; left: 12px; right: 12px; height: 7px; cursor: ns-resize; }',
        '.crm-rz-w { top: 12px; bottom: 12px; left: 0; width: 7px; cursor: ew-resize; }',
        '.crm-rz-e { top: 12px; bottom: 12px; right: 0; width: 7px; cursor: ew-resize; }',
        '.crm-rz-nw { top: 0; left: 0; width: 15px; height: 15px; cursor: nwse-resize; }',
        '.crm-rz-ne { top: 0; right: 0; width: 15px; height: 15px; cursor: nesw-resize; }',
        '.crm-rz-sw { bottom: 0; left: 0; width: 15px; height: 15px; cursor: nesw-resize; }',
        '.crm-rz-se { bottom: 0; right: 0; width: 15px; height: 15px; cursor: nwse-resize; }',
        /* индикатор: уголок-«грип» виден при наведении на секцию */
        '.crm-rz-se::after {',
        '    content: ""; position: absolute; right: 7px; bottom: 7px; width: 9px; height: 9px;',
        '    border-right: 2px solid rgba(127, 137, 158, .55); border-bottom: 2px solid rgba(127, 137, 158, .55);',
        '    border-radius: 0 0 3px 0; opacity: 0; transition: opacity .15s ease;',
        '}',
        '.crm-resizable:hover > .crm-rz-se::after { opacity: 1; }',
        '.crm-resizable.is-resizing { outline: 2px dashed rgba(var(--crm-accent-rgb, 13, 119, 155), .65); outline-offset: 2px; z-index: 50; }',

        /* --- карточки с таблицами: живее --- */
        '.card .table:not(.table-striped) > tbody > tr:nth-child(even) > td { background-color: rgba(127, 137, 158, .06); }',
        '.card .table > thead > tr > th, .crm-form__section .table > thead > tr > th {',
        '    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;',
        '    color: var(--tblr-secondary); background: transparent; border-bottom-width: 1px; white-space: nowrap;',
        '}',
        '.card .table > tbody > tr, .crm-form__section .table > tbody > tr { transition: background-color .15s ease; }',
        '.card .table > tbody > tr:hover > td, .crm-form__section .table > tbody > tr:hover > td {',
        '    background-color: rgba(var(--crm-accent-rgb, 13, 119, 155), .08);',
        '}'
    ].join('\n');

    function ensureSectionStyles() {
        if (document.getElementById('crm-sections-style')) { return; }
        var style = document.createElement('style');
        style.id = 'crm-sections-style';
        style.textContent = CRM_SECTIONS_CSS;
        document.head.appendChild(style);
    }

    /** Страницная секция: не внутри модалки и не внутри карточки-формы. */
    function isPageSection(sec) {
        return !sec.closest('.modal') && !sec.closest('.card');
    }

    function allMatch(list, fn) {
        for (var i = 0; i < list.length; i++) {
            if (!fn(list[i], i)) { return false; }
        }
        return list.length > 0;
    }

    function initAdaptiveSections() {
        var sections = document.querySelectorAll('main .crm-form__section');
        var done = [];

        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            if (!isPageSection(sec)) { continue; }

            var parent = sec.parentElement;
            if (!parent) { continue; }

            var container = null;
            var row = parent.parentElement;

            if (/(^|\s)col/.test(parent.className) && row && row.classList.contains('row')
                && !row.closest('.tab-pane') && !row.querySelector('[data-crm-sec]')) {
                /* строка целиком из колонок-секций: раскладываем её */
                var rowOk = allMatch(row.children, function (el) {
                    return /(^|\s)col/.test(el.className)
                        && allMatch(el.children, function (c) { return c.classList.contains('crm-form__section'); });
                });
                if (rowOk) { container = row; }
            }

            if (!container) {
                /* колонка внутри строки обрабатывается только на уровне строки
                   (там живёт система разделителей initSectionConfig) — колоночные
                   вставки пропускаем, чтобы не спорить с её ширинами */
                var inRowCol = parent.parentElement
                    && parent.parentElement.classList.contains('row')
                    && /(^|\s)col/.test(parent.className);
                var isSectionList = allMatch(parent.children, function (el) {
                    return el.classList.contains('crm-form__section');
                });
                if (isSectionList && !inRowCol && !parent.closest('.tab-pane') && !parent.closest('details')) {
                    container = parent;
                }
            }

            if (!container || done.indexOf(container) !== -1) { continue; }
            done.push(container);
            container.classList.add('crm-auto-grid');
            if (container.children.length === 1) {
                container.classList.add('crm-auto-grid--single');
            }
        }
    }

    /* ------------------------- адаптив секций -------------------------
       Секция сообщает свой бокс атрибутами data-sec-w (xs…xl) и data-sec-h
       (xs…lg) — по ним CSS ужинает паддинги и кегль, стакает сетки внутри
       и обрезает длинный текст. Секции с заданной высотой помечаем отдельно:
       их содержимое скроллится, а не вылезает на соседей. */
    function secWB(w) {
        if (w < 360) { return 'xs'; }
        if (w < 560) { return 'sm'; }
        if (w < 900) { return 'md'; }
        if (w < 1300) { return 'lg'; }
        return 'xl';
    }

    function secHB(h) {
        if (h < 220) { return 'xs'; }
        if (h < 380) { return 'sm'; }
        if (h < 620) { return 'md'; }
        return 'lg';
    }

    function applySecSize(el) {
        var w = el.clientWidth;
        var h = el.clientHeight;
        if (!w || !h) { return; }   /* скрытая секция: бакет придет, когда её покажут */
        var bw = secWB(w);
        if (el.getAttribute('data-sec-w') !== bw) { el.setAttribute('data-sec-w', bw); }
        var bh = secHB(h);
        if (el.getAttribute('data-sec-h') !== bh) { el.setAttribute('data-sec-h', bh); }
        /* высоту задали руками — контент не должен вываливаться на соседей */
        el.classList.toggle('crm-sec--fixed-h', rzNum(el, 'height') > 0);
    }

    function initSectionSizes() {
        /* колонки строк, секции формы и ленты метрик — всё, что может менять
           размер и должно подстраивать содержимое (включая дашборд) */
        var list = document.querySelectorAll(
            'main .crm-form__section, main [data-crm-sec], main .row > [class*="col-"], main .crm-metrics'
        );
        var hasRO = typeof ResizeObserver !== 'undefined';
        for (var i = 0; i < list.length; i++) {
            (function (el) {
                if (el.dataset.crmSize === '1') { return; }
                /* колонка, внутри которой лежит своя секция, — бакет ставим на секцию,
                   иначе у одного бокса окажется два разных размера */
                if (el.hasAttribute('data-crm-sec') && el.querySelector('.crm-form__section')) { return; }
                if (el.classList.contains('crm-resize-handle')) { return; }
                el.dataset.crmSize = '1';
                applySecSize(el);
                if (hasRO) {
                    new ResizeObserver(function () { applySecSize(el); }).observe(el);
                }
            })(list[i]);
        }
        if (!hasRO) {
            window.addEventListener('resize', function () {
                for (var j = 0; j < list.length; j++) { applySecSize(list[j]); }
            });
        }
        /* контент мог подгрузиться после старта — сверяем бакеты ещё раз */
        window.setTimeout(function () {
            for (var k = 0; k < list.length; k++) { applySecSize(list[k]); }
        }, 600);
    }

    /* ------------------------- ручной ресайз ------------------------- */

    var RZ_DIRS = ['n', 's', 'e', 'w', 'nw', 'ne', 'sw', 'se'];
    var rzCounter = 0;

    function rzKey(el) {
        return 'crm-rz:' + location.pathname + '#' + (el.dataset.rzIdx || 0);
    }

    var RZ_MIN_W = 240;
    var RZ_MIN_H = 110;

    /* Утилиты Bootstrap вроде .h-100 / .w-100 / .mt-3 объявлены через
       !important — обычный инлайновый стиль такие правила не перебивает.
       Пишем свои размеры тоже с !important, иначе ресайз «не тянется». */
    function rzSet(el, prop, value) {
        if (value === '') { el.style.removeProperty(prop); return; }
        el.style.setProperty(prop, value, 'important');
    }

    function rzNum(el, prop) {
        return parseFloat(el.style.getPropertyValue(prop)) || 0;
    }

    /** Свободная ширина родителя: 0 — родитель сам распределяет место
        (флекс-сетка), ограничивать секцию не нужно. */
    function resizeBounds(el) {
        var host = el.parentElement;
        if (!host || host.classList.contains('crm-auto-grid')) { return 0; }
        var cs = window.getComputedStyle(host);
        var w = host.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
        return w > 0 ? w : 0;
    }

    function storeSize(el) {
        var s = {
            w: rzNum(el, 'width'),
            h: rzNum(el, 'height'),
            ml: rzNum(el, 'margin-left'),
            mt: rzNum(el, 'margin-top')
        };
        try { localStorage.setItem(rzKey(el), JSON.stringify(s)); } catch (e) { /* private mode */ }
    }

    function applyStoredSize(el) {
        var raw = null;
        try { raw = localStorage.getItem(rzKey(el)); } catch (e) { return; }
        if (!raw) { return; }
        try {
            var s = JSON.parse(raw);
            /* лечим остатки старых тестов: отрицательный отступ выталкивает
               секцию за колонку, ширина — шире её контента */
            if (s.ml < 0) { s.ml = 0; }
            var maxW = resizeBounds(el);
            if (maxW && s.w > maxW - s.ml) { s.w = Math.max(0, maxW - s.ml); }
            if (inRowColumn(el)) {
                /* ширину такой секции задаёт её колонка (и разделители) —
                   собственный инлайновый размер только ломает раскладку */
                rzSet(el, 'width', '');
            } else if (s.w) {
                rzSet(el, 'width', Math.round(s.w) + 'px');
            }
            if (s.h) { rzSet(el, 'height', Math.round(s.h) + 'px'); }
            if (s.ml) { rzSet(el, 'margin-left', Math.round(s.ml) + 'px'); }
            if (s.mt) { rzSet(el, 'margin-top', Math.round(s.mt) + 'px'); }
            if (rzNum(el, 'width') || rzNum(el, 'height')) { el.classList.add('crm-resizable--sized'); }
        } catch (e) { /* битые данные — игнорируем */ }
    }

    function resetResize(el) {
        rzSet(el, 'width', '');
        rzSet(el, 'height', '');
        rzSet(el, 'margin-left', '');
        rzSet(el, 'margin-top', '');
        el.classList.remove('crm-resizable--sized');
        try { localStorage.removeItem(rzKey(el)); } catch (e) { /* private mode */ }
    }

    /** Разделитель между колонкой секции и соседом в нужном направлении:
        горизонтальный ресайз внутри колонки строки отдаём ему — секция здесь
        блочный ребёнок и своим размером просто вылезет на соседа. */
    function inRowColumn(el) {
        var col = el.parentElement;
        return !!(col && col.parentElement && col.parentElement.classList.contains('row')
            && /(^|\s)col(-|\s|$)/.test(' ' + col.className + ' '));
    }

    function dividerFor(sec, dir) {
        var col = sec.parentElement;
        if (!inRowColumn(sec)) { return null; }
        var row = col.parentElement;
        /* разделители «включены» только когда система секций активна на строке */
        if (!row.classList.contains('crm-row--resizable')) { return null; }
        var wantNext = dir.indexOf('e') !== -1;
        var list = row.querySelectorAll('.crm-resize-handle');
        for (var i = 0; i < list.length; i++) {
            var h = list[i];
            if (wantNext && h._crmA === col && !h._crmB.classList.contains('crm-sec--off')) { return h; }
            if (!wantNext && h._crmB === col && !h._crmA.classList.contains('crm-sec--off')) { return h; }
        }
        return null;
    }

    /** Внешний край строки тянуть некуда — такую ручку прячем. */
    function refreshRzEdges() {
        var secs = document.querySelectorAll('[data-crm-rz="1"]');
        for (var i = 0; i < secs.length; i++) {
            var sec = secs[i];
            var inRow = inRowColumn(sec);
            var w = sec.querySelector('.crm-rz-w');
            var e = sec.querySelector('.crm-rz-e');
            if (w) { w.style.display = (inRow && !dividerFor(sec, 'w')) ? 'none' : ''; }
            if (e) { e.style.display = (inRow && !dividerFor(sec, 'e')) ? 'none' : ''; }
        }
    }

    function onResizeStart(event) {
        if (event.button !== 0) { return; }
        var handle = event.target;
        if (!handle.classList || !handle.classList.contains('crm-rz')) { return; }
        var el = handle.closest('.crm-resizable');
        if (!el) { return; }
        event.preventDefault();

        var dir = handle.dataset.rz;
        var east = dir.indexOf('e') !== -1;
        var west = dir.indexOf('w') !== -1;

        /* Горизонталь внутри колонки строки = жест разделителя: отдаём его
           системе секций, иначе секция просто вылезет на соседнюю колонку.
           Если разделителя нет (внешний край строки) — работаем только высотой. */
        if (east || west) {
            if (inRowColumn(el)) {
                var divider = dividerFor(el, dir);
                if (divider) {
                    divider.dispatchEvent(new PointerEvent('pointerdown', {
                        clientX: event.clientX,
                        clientY: event.clientY,
                        bubbles: true,
                        cancelable: true,
                        pointerId: 1,
                        isPrimary: true,
                        button: 0,
                        buttons: 1
                    }));
                    return;
                }
                east = false;
                west = false;
            }
        }

        var startX = event.clientX;
        var startY = event.clientY;
        var rect = el.getBoundingClientRect();
        var maxW = resizeBounds(el);
        var start = {
            w: rect.width,
            h: rect.height,
            ml: rzNum(el, 'margin-left'),
            mt: rzNum(el, 'margin-top')
        };

        /* фиксируем текущие размеры, дальше тянем от них */
        if ((east || west) && !rzNum(el, 'width')) { rzSet(el, 'width', Math.round(rect.width) + 'px'); }
        if (!rzNum(el, 'height')) { rzSet(el, 'height', Math.round(rect.height) + 'px'); }
        el.classList.add('crm-resizable--sized');
        el.classList.add('is-resizing');

        function move(e) {
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            var w = start.w;
            var h = start.h;
            var ml = start.ml;
            var mt = start.mt;

            if (east) { w = start.w + dx; }
            if (west) {
                /* левый край следует за мышью, правый остаётся на месте */
                ml = start.ml + dx;
                if (ml < 0) { ml = 0; }
                w = start.w - (ml - start.ml);
                if (w < RZ_MIN_W) { w = RZ_MIN_W; ml = Math.max(0, start.ml + start.w - w); }
            }
            /* секция — блочный ребёнок: за пределы родителя не выходим */
            if (maxW && ml + w > maxW) { w = Math.max(RZ_MIN_W, maxW - ml); }
            if (dir.indexOf('s') !== -1) { h = start.h + dy; }
            if (dir.indexOf('n') !== -1) { h = start.h - dy; mt = start.mt + dy; }

            w = Math.max(RZ_MIN_W, w);
            h = Math.max(RZ_MIN_H, h);

            rzSet(el, 'width', Math.round(w) + 'px');
            rzSet(el, 'height', Math.round(h) + 'px');
            if (west) { rzSet(el, 'margin-left', Math.round(ml) + 'px'); }
            if (dir.indexOf('n') !== -1) { rzSet(el, 'margin-top', Math.round(mt) + 'px'); }
        }

        function up() {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            el.classList.remove('is-resizing');
            storeSize(el);
        }

        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    }

    function attachResize(el) {
        el.dataset.crmRz = '1';
        el.dataset.rzIdx = String(rzCounter++);
        el.classList.add('crm-resizable');

        /* обёртка первым дочерним: поля форм сохраняют :last-child-отступы */
        var wrap = document.createElement('div');
        wrap.className = 'crm-rz-wrap';
        el.insertBefore(wrap, el.firstChild);

        for (var i = 0; i < RZ_DIRS.length; i++) {
            var handle = document.createElement('div');
            handle.className = 'crm-rz crm-rz-' + RZ_DIRS[i];
            handle.dataset.rz = RZ_DIRS[i];
            handle.title = 'Тяните — изменить размер · двойной клик — сброс';
            wrap.appendChild(handle);
        }

        el.addEventListener('mousedown', onResizeStart);
        el.addEventListener('dblclick', function (event) {
            var target = event.target;
            if (target.classList && target.classList.contains('crm-rz')) {
                resetResize(el);
            }
        });

        applyStoredSize(el);
    }

    function initResizableSections() {
        var sections = document.querySelectorAll('main .crm-form__section');
        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            if (!isPageSection(sec) || sec.dataset.crmRz === '1') { continue; }
            attachResize(sec);
        }
        refreshRzEdges();
    }

    function initPasswordToggles() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-password-toggle]');
            if (!button) { return; }
            var group = button.closest('.input-group') || button.parentElement;
            var input = group && group.querySelector('[data-password-input]');
            if (!input) { return; }
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-label', reveal ? 'Скрыть пароль' : 'Показать пароль');
            button.setAttribute('title', reveal ? 'Скрыть пароль' : 'Показать пароль');
            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('ti-eye', !reveal);
                icon.classList.toggle('ti-eye-off', reveal);
            }
        });
    }

    function initAll() {
        ensureUtilStyles();
        ensureSectionStyles();
        initRipple();
        initSearch();
        initCommandPalette();
        initTabJump();
        initModalAnchor();
        initConfirmForms();
        initFlashToasts();
        initScrollMemory();
        initClock();
        initCountUp();
        initViewSwitch();
        initKanban();
        initDirtyGuard();
        initSubmitGuard();
        initRowFilter();
        initExportFeedback();
        initNavGroups();
        initRichEditors();
        initSelect2Fields();
        initTagFields();
        initInputMasks();
        initPasswordToggles();
        initSectionConfig();
        initAdaptiveSections();
        initResizableSections();
        initSectionSizes();
        // S9: маркер модалки живёт только до конца страницы — если ошибок
        // не было, он устарел и не должен открывать чужую модалку дальше
        try { sessionStorage.removeItem('crm-invalid-modal'); } catch (e) { /* private mode */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
