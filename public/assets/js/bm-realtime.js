/*
 * Инициализация realtime-канала (Laravel Reverb + Echo).
 *
 * Контракт для потребителей (bm-chat.js и др.):
 *   window.BMRealtime = {
 *       available: bool,          — Echo поднят и соединение есть
 *       echo: Echo|null,
 *       on(name, callback),       — подписка на приватный канал диалога
 *       onNotice(callback),       — личные уведомления сотрудника
 *   }
 *
 * Скрипт ничего не ломает, если библиотеки не загрузились или ключ
 * не задан: available=false, потребитель работает на polling.
 */
(function () {
    'use strict';

    var api = {
        available: false,
        echo: null,
        on: function () { return function () { return null; }; },
        onNotice: function () { return null; },
    };

    window.BMRealtime = api;

    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : null;
    }

    var key = meta('reverb-key');

    if (!key || typeof window.Echo === 'undefined' || typeof window.Pusher === 'undefined') {
        return; /* realtime недоступен — остаётся polling */
    }

    var host = meta('reverb-host') || window.location.hostname;
    var port = parseInt(meta('reverb-port') || '80', 10);
    var scheme = meta('reverb-scheme') || (window.location.protocol === 'https:' ? 'https' : 'http');

    try {
        api.echo = new window.Echo({
            broadcaster: 'reverb',
            key: key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });
        api.available = true;
    } catch (e) {
        api.available = false;
        return;
    }

    /** Подписка на диалог: callback(payload) при событии chat.delta. */
    api.on = function (conversationId, callback) {
        if (!api.available) { return function () { return null; }; }

        try {
            var channel = api.echo.private('chat.conversation.' + conversationId);
            channel.listen('.chat.delta', function (payload) { callback(payload); });

            return function () {
                try { channel.stopListening('.chat.delta'); } catch (e) { /* уже отписан */ }
            };
        } catch (e) {
            return function () { return null; };
        }
    };

    /** Личные уведомления (бейджи непрочитанных). */
    api.onNotice = function (callback) {
        if (!api.available || !api.employeeId) { return null; }

        try {
            var channel = api.echo.private('employee.' + api.employeeId + '.chat');
            channel.listen('.chat.notice', function (payload) { callback(payload); });
            return channel;
        } catch (e) {
            return null;
        }
    };

    api.employeeId = parseInt(meta('employee-id') || '0', 10) || null;
})();
