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

    /* Assignment submit modal: point the form at the clicked assignment. */
    (function () {
        var buttons = LMS.qsa('[data-lms-submit]');
        if (!buttons.length) { return; }

        var modalEl = document.getElementById('lmsSubmitModal');
        var form = LMS.qs('[data-lms-submit-form]');
        if (!modalEl || !form) { return; }

        var title = LMS.qs('[data-lms-submit-title]', form);
        var body = LMS.qs('[name="body"]', form);
        var link = LMS.qs('[name="link"]', form);
        var base = form.dataset.base || '';
        var modal = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
            : null;

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                form.action = base.replace('__ID__', String(button.dataset.lmsSubmit || ''));
                if (title) { title.textContent = button.dataset.lmsTitle || ''; }
                if (body) { body.value = ''; }
                if (link) { link.value = ''; }
                if (modal) { modal.show(); }
                else { modalEl.classList.add('show'); modalEl.style.display = 'block'; }
            });
        });

        LMS.qsa('[data-bs-dismiss="modal"]', modalEl).forEach(function (button) {
            button.addEventListener('click', function () {
                if (!modal) { modalEl.classList.remove('show'); modalEl.style.display = 'none'; }
            });
        });
    })();
})();
