/* LMS shared helpers — polling/CSRF/i18n. No external dependencies. */
(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function request(url, options) {
        options = options || {};
        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf()
        }, options.headers || {});

        var body = options.body;
        if (body && typeof body !== 'string' && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }

        return fetch(url, {
            method: options.method || 'GET',
            headers: headers,
            body: body,
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = {};
                try { data = text ? JSON.parse(text) : {}; } catch (e) { data = {}; }
                if (!response.ok) {
                    var message = data.message || ('HTTP ' + response.status);
                    if (data.errors) {
                        var first = data.errors[Object.keys(data.errors)[0]];
                        if (first && first[0]) { message = first[0]; }
                    }
                    var error = new Error(message);
                    error.status = response.status;
                    error.data = data;
                    throw error;
                }
                return data;
            });
        });
    }

    function t(key, fallback) {
        var dict = window.LMS_I18N || {};
        return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : (fallback || key);
    }

    function pad(value) { return value < 10 ? '0' + value : String(value); }

    function clock(seconds) {
        seconds = Math.max(0, Math.floor(seconds || 0));
        return pad(Math.floor(seconds / 60)) + ':' + pad(seconds % 60);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    window.LMS = {
        request: request,
        csrf: csrf,
        t: t,
        clock: clock,
        el: el,
        qs: function (selector, root) { return (root || document).querySelector(selector); },
        qsa: function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    };

    /* Assignment submit modal: one modal outside cards, AJAX submit, no native reload. */
    (function () {
        var modalEl = document.getElementById('lmsSubmitModal');
        var form = modalEl ? modalEl.querySelector('[data-lms-submit-form]') : null;
        if (!modalEl || !form) { return; }

        var title = form.querySelector('[data-lms-submit-title]');
        var body = form.querySelector('[name="body"]');
        var link = form.querySelector('[name="link"]');
        var submitBtn = form.querySelector('button[type="submit"]');
        var base = form.dataset.base || '';
        var modal = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
            : null;
        var backdrop = null;
        var busy = false;

        function show() {
            if (modal) { modal.show(); return; }
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
            }
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            modalEl.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function hide() {
            if (modal && modalEl.classList.contains('show')) { modal.hide(); return; }
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            if (backdrop && backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
            backdrop = null;
        }

        function toast(message, icon) { if (window.crmToast) { window.crmToast(message, icon); } }

        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest
                ? event.target.closest('[data-lms-submit]')
                : null;
            if (!button) { return; }
            event.preventDefault();
            form.action = base.replace('__ID__', String(button.dataset.lmsSubmit || ''));
            if (title) { title.textContent = button.dataset.lmsTitle || ''; }
            if (body) { body.value = ''; }
            if (link) { link.value = ''; }
            show();
            if (body) { body.focus(); }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (busy) { return; }
            if (!body || !body.value.trim()) {
                toast(LMS.t('submitFailed', 'Заполните текст работы'), 'alert-circle');
                if (body) { body.focus(); }
                return;
            }
            busy = true;
            if (submitBtn) { submitBtn.disabled = true; }
            var action = form.action && form.action !== '#' ? form.action : base;
            LMS.request(action, {
                method: 'POST',
                body: { body: body.value, link: link && link.value ? link.value : null }
            }).then(function () {
                hide();
                toast(LMS.t('submitSent', 'Работа отправлена'), 'check');
                window.setTimeout(function () { window.location.reload(); }, 700);
            }).catch(function (error) {
                toast((error && error.message) || LMS.t('submitFailed', 'Не удалось отправить работу'), 'alert-triangle');
            }).then(function () {
                busy = false;
                if (submitBtn) { submitBtn.disabled = false; }
            });
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (body) { body.value = ''; }
            if (link) { link.value = ''; }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modalEl.classList.contains('show')) { hide(); }
        });
        LMS.qsa('[data-bs-dismiss="modal"]', modalEl).forEach(function (button) {
            button.addEventListener('click', function () { if (!modal) { hide(); } });
        });
    })();

    /* Чат курса на доске и странице курса. */
    (function () {
        var root = LMS.qs('[data-lms-chat]');
        if (!root) { return; }
        var list = LMS.qs('[data-lms-chat-list]', root);
        var form = LMS.qs('[data-lms-chat-form]', root);
        if (!list || !form) { return; }

        var input = form.querySelector('[name="body"]');
        var myId = Number(root.dataset.myId || 0);
        var pollMs = Math.max(1500, Number(root.dataset.pollMs || 3000));
        var lastId = 0;
        var loaded = false;
        var seen = Object.create(null);

        function renderItem(item) {
            if (seen[item.id]) { return; }
            seen[item.id] = true;
            lastId = Math.max(lastId, Number(item.id) || 0);
            var mine = Number(item.employee_id) === myId;
            var row = LMS.el('div', 'lms-chat-item' + (mine ? ' is-mine' : ''));
            row.appendChild(LMS.el('span', 'lms-chat-item__who', mine ? LMS.t('chatYou', 'вы') : (item.author || '')));
            row.appendChild(LMS.el('div', 'lms-chat-item__body', item.body));
            row.appendChild(LMS.el('time', 'lms-chat-item__time', item.created_at || ''));
            list.appendChild(row);
        }

        function load() {
            LMS.request(root.dataset.urlList + '?since=' + lastId).then(function (data) {
                if (!loaded) {
                    list.innerHTML = '';
                    loaded = true;
                }
                if (data && data.last_id) {
                    lastId = Math.max(lastId, Number(data.last_id) || 0);
                }
                var items = (data && data.messages) || [];
                var empty = LMS.qs('[data-lms-chat-empty]', list);
                if (!items.length && !LMS.qs('.lms-chat-item', list)) {
                    if (!empty) {
                        empty = LMS.el('div', 'text-secondary small p-3',
                            LMS.t('chatEmpty', 'Пока нет сообщений. Напишите первым'));
                        empty.setAttribute('data-lms-chat-empty', '1');
                        list.appendChild(empty);
                    }
                } else if (empty && empty.parentNode) {
                    empty.parentNode.removeChild(empty);
                }
                items.forEach(renderItem);
                list.scrollTop = list.scrollHeight;
            }).catch(function () { /* фоновый опрос — без шума в консоли */ });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var value = input ? input.value.trim() : '';
            if (!value) { return; }
            LMS.request(root.dataset.urlSend, { method: 'POST', body: { body: value } })
                .then(function () {
                    if (input) { input.value = ''; }
                    load();
                })
                .catch(function (error) {
                    if (window.crmToast) {
                        window.crmToast((error && error.message) || LMS.t('chatFailed', 'Не удалось отправить сообщение'), 'alert-triangle');
                    }
                });
        });

        /* UX-06: опрос живёт только когда вкладка и секция чата видимы. */
        var pollTimer = null;
        function chatVisible() {
            if (document.hidden) { return false; }
            var section = root.closest('[data-lms-section]');
            return !section || !section.hidden;
        }
        function stopPolling() {
            if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
        }
        function syncPolling() {
            if (!chatVisible()) { stopPolling(); return; }
            if (pollTimer === null) {
                if (!loaded) { load(); }
                pollTimer = window.setInterval(load, pollMs);
            }
        }
        document.addEventListener('visibilitychange', syncPolling);
        document.addEventListener('lms:sectionchange', syncPolling);
        syncPolling();
    })();

    /* Разделы доски и плавающее окно видеозвонка. */
    (function () {
        var switcher = LMS.qs('[data-lms-section-switch]');
        if (!switcher) { return; }

        var sections = LMS.qsa('[data-lms-section]');
        var meetingWindow = LMS.qs('[data-lms-meeting-window]');
        var meetingButton = LMS.qs('[data-section="meeting"]', switcher);
        var dragHandle = meetingWindow ? LMS.qs('[data-lms-meeting-drag]', meetingWindow) : null;
        var minimizeButton = meetingWindow ? LMS.qs('[data-lms-meeting-minimize]', meetingWindow) : null;
        var closeButton = meetingWindow ? LMS.qs('[data-lms-meeting-close]', meetingWindow) : null;

        function setSection(name) {
            sections.forEach(function (section) {
                section.hidden = section.getAttribute('data-lms-section') !== name;
            });
            switcher.querySelectorAll('button[data-section]').forEach(function (button) {
                if (button.getAttribute('data-section') === 'meeting') { return; }
                button.classList.toggle('is-active', button.getAttribute('data-section') === name);
            });
            document.dispatchEvent(new CustomEvent('lms:sectionchange', { detail: { section: name } }));
        }

        function setMinimized(minimized) {
            if (!meetingWindow) { return; }
            meetingWindow.classList.toggle('is-minimized', minimized);
            if (!minimizeButton) { return; }
            minimizeButton.title = minimized
                ? LMS.t('meetingRestore', 'Развернуть')
                : LMS.t('meetingMinimize', 'Свернуть');
            var icon = minimizeButton.querySelector('i');
            if (icon) { icon.className = minimized ? 'ti ti-plus' : 'ti ti-minus'; }
        }

        function openMeeting() {
            if (!meetingWindow) { return; }
            meetingWindow.hidden = false;
            setMinimized(false);
            if (meetingButton) { meetingButton.classList.add('is-active'); }
        }

        function closeMeeting() {
            if (!meetingWindow) { return; }
            meetingWindow.hidden = true;
            if (meetingButton) { meetingButton.classList.remove('is-active'); }
        }

        switcher.addEventListener('click', function (event) {
            var button = event.target && event.target.closest
                ? event.target.closest('button[data-section]')
                : null;
            if (!button) { return; }
            var name = button.getAttribute('data-section');
            if (name === 'meeting') {
                if (meetingWindow && meetingWindow.hidden) { openMeeting(); } else { closeMeeting(); }
                return;
            }
            setSection(name);
        });

        if (minimizeButton) {
            minimizeButton.addEventListener('click', function () {
                if (!meetingWindow) { return; }
                setMinimized(!meetingWindow.classList.contains('is-minimized'));
            });
        }
        if (closeButton) { closeButton.addEventListener('click', closeMeeting); }

        if (dragHandle && meetingWindow) {
            var dragging = false;
            var startX = 0;
            var startY = 0;
            var startLeft = 0;
            var startTop = 0;

            dragHandle.addEventListener('pointerdown', function (event) {
                if (event.target && event.target.closest && event.target.closest('button')) { return; }
                var rect = meetingWindow.getBoundingClientRect();
                dragging = true;
                startX = event.clientX;
                startY = event.clientY;
                startLeft = rect.left;
                startTop = rect.top;
                meetingWindow.style.left = rect.left + 'px';
                meetingWindow.style.top = rect.top + 'px';
                meetingWindow.style.right = 'auto';
                meetingWindow.style.bottom = 'auto';
                if (dragHandle.setPointerCapture) {
                    try { dragHandle.setPointerCapture(event.pointerId); } catch (e) { /* synthetic events */ }
                }
                event.preventDefault();
            });

            dragHandle.addEventListener('pointermove', function (event) {
                if (!dragging) { return; }
                var maxLeft = Math.max(8, window.innerWidth - meetingWindow.offsetWidth - 8);
                var maxTop = Math.max(8, window.innerHeight - meetingWindow.offsetHeight - 8);
                var left = Math.min(maxLeft, Math.max(8, startLeft + event.clientX - startX));
                var top = Math.min(maxTop, Math.max(8, startTop + event.clientY - startY));
                meetingWindow.style.left = left + 'px';
                meetingWindow.style.top = top + 'px';
            });

            ['pointerup', 'pointercancel'].forEach(function (type) {
                dragHandle.addEventListener(type, function () { dragging = false; });
            });
        }
    })();
})();
