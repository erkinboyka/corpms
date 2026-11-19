/*
 * BM Chat — клиентская часть.
 *
 * Источник истины — база: клиент читает дельты /chat/{id}/events?since=N,
 * realtime (Reverb) только ускоряет опрос, а не заменяет его. Поэтому
 * обрыв websocket или его отсутствие не ломает переписку.
 *
 * Отправка идемпотентна: у сообщения есть client_id, повторный POST
 * возвращает уже сохранённую копию, а оптимистичный пузырь
 * подменяется серверной записью без дубликата.
 *
 * Разметка: chat/index.blade.php + chat/partials/message.blade.php,
 * стили: assets/css/bm-chat.css.
 */
(function () {
    'use strict';

    var root = document.getElementById('bm-chat');
    if (!root) { return; }

    var csrf = document.querySelector('meta[name="csrf-token"]');
    var token = csrf ? csrf.getAttribute('content') : '';

    var messagesEl = document.getElementById('chat-messages');
    var composer = document.getElementById('chat-composer');
    var input = document.getElementById('chat-input');
    var sendButton = document.getElementById('chat-send');
    var replyBar = document.getElementById('chat-reply-bar');
    var replyAuthor = document.getElementById('chat-reply-author');
    var replyText = document.getElementById('chat-reply-text');

    var activeId = parseInt(root.getAttribute('data-active-id') || '0', 10) || null;
    var myEmployeeId = parseInt(root.getAttribute('data-my-employee-id') || '0', 10) || null;
    var routeBase = root.getAttribute('data-route-messages-base') || '/chat';

    /* Граница дельт задана сервером: первый опрос не тянет всю историю. */
    var lastEventId = parseInt(root.getAttribute('data-last-event-id') || '0', 10) || 0;
    var replyDraft = null;
    var pendingClientIds = {}; /* client_id -> элемент оптимистичного пузыря */
    var renderedIds = {};      /* id сообщения -> true (дедупликация) */
    var pollTimer = null;
    var pollBusy = false;
    var consecutiveFailures = 0;
    var emojiMenu = null;

    /* ================================================================
       Утилиты
       ================================================================ */

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /** Белый список: текст ссылок и переносов, тегов из БД нет. */
    function formatBody(value) {
        var safe = escapeHtml(value);
        return safe.replace(/\n/g, '<br>');
    }

    function timeOf(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return ''; }
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/);
        if (!parts[0]) { return '?'; }
        var out = parts[0].charAt(0).toUpperCase();
        if (parts[1]) { out += parts[1].charAt(0).toUpperCase(); }
        return out;
    }

    function authorName(message) {
        var a = message.author;
        if (!a) { return 'Сотрудник'; }
        var name = ((a.first_name || '') + ' ' + (a.last_name || '')).trim();
        return name || 'Сотрудник';
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data || {}),
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var err = new Error((body && (body.message || body.error)) || 'Ошибка запроса');
                    err.status = response.status;
                    err.body = body;
                    throw err;
                }
                return body;
            });
        });
    }

    function method(url, verb, data) {
        return fetch(url, {
            method: verb,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: data ? JSON.stringify(data) : undefined,
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var err = new Error((body && (body.message || body.error)) || 'Ошибка запроса');
                    err.status = response.status;
                    err.body = body;
                    throw err;
                }
                return body;
            });
        });
    }

    function notify(text, isError) {
        if (window.BMToast && typeof window.BMToast.show === 'function') {
            window.BMToast.show(text, isError ? 'error' : 'success');
            return;
        }
        if (window.crmToast) { window.crmToast(text, isError ? 'alert-circle' : 'check'); return; }
        if (isError) { window.console && console.warn(text); }
    }

    function scrollToBottom() {
        if (!messagesEl) { return; }
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function isNearBottom() {
        if (!messagesEl) { return true; }
        return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
    }

    var latestButton = document.getElementById('chat-latest');
    if (latestButton && messagesEl) {
        messagesEl.addEventListener('scroll', function () {
            latestButton.hidden = isNearBottom();
        }, { passive: true });
        latestButton.addEventListener('click', function () {
            messagesEl.focus({ preventScroll: true });
            scrollToBottom();
        });
    }

    /* ================================================================
       Рендер сообщения (зеркало chat/partials/message.blade.php)
       ================================================================ */

    function reactionsHtml(message) {
        var list = message.reactions || [];
        if (!list.length) { return ''; }

        var grouped = {};
        list.forEach(function (r) {
            if (!grouped[r.emoji]) { grouped[r.emoji] = []; }
            grouped[r.emoji].push(r);
        });

        var html = '<div class="bm-chat__msg-reactions">';
        Object.keys(grouped).forEach(function (emoji) {
            var mine = grouped[emoji].some(function (r) {
                return myEmployeeId !== null && Number(r.employee_id) === myEmployeeId;
            });
            html += '<button type="button" class="bm-chat__reaction' + (mine ? ' is-mine' : '') + '"' +
                ' data-emoji="' + escapeHtml(emoji) + '" data-toggle-reaction' +
                ' title="' + (mine ? 'Снять реакцию' : 'Поставить реакцию') + '">' +
                '<span>' + escapeHtml(emoji) + '</span>' +
                '<span class="bm-chat__reaction-count">' + grouped[emoji].length + '</span>' +
                '</button>';
        });
        html += '</div>';
        return html;
    }

    function filesHtml(message) {
        var list = message.attachments || [];
        if (!list.length) { return ''; }

        var html = '<ul class="bm-chat__msg-files">';
        list.forEach(function (a) {
            html += '<li class="bm-chat__file"><i class="ti ti-file" aria-hidden="true"></i>' +
                '<span>' + escapeHtml(a.name) + '</span></li>';
        });
        return html + '</ul>';
    }

    function quotePreview(text) {
        var preview = String(text || '').replace(/\s+/g, ' ').trim();
        return (preview.length > 120 ? preview.slice(0, 119) + '…' : preview) || 'Вложение';
    }

    function quoteHtml(reply, interactive) {
        if (!reply) { return ''; }
        var contents = '<span class="bm-chat__msg-quote-author">' + escapeHtml(reply.author) + '</span>' +
            '<span class="bm-chat__msg-quote-text">' + escapeHtml(quotePreview(reply.body)) + '</span>';
        return interactive
            ? '<button type="button" class="bm-chat__msg-quote" data-reply-to="' + escapeHtml(reply.id) +
                '" title="Перейти к исходному сообщению">' + contents + '</button>'
            : '<div class="bm-chat__msg-quote">' + contents + '</div>';
    }

    function messageQuote(message) {
        var reply = message.reply_to;
        return reply ? quoteHtml({ id: reply.id, author: authorName(reply), body: reply.body }, true) : '';
    }

    function buildMessage(message) {
        if (message.kind === 'system') {
            var sys = document.createElement('div');
            sys.className = 'bm-chat__system';
            sys.setAttribute('data-message-id', message.id);
            sys.innerHTML = '<span>' + escapeHtml(message.body || '') + '</span>';
            return sys;
        }

        var mine = myEmployeeId !== null && Number(message.employee_id) === myEmployeeId;
        var name = authorName(message);

        var article = document.createElement('article');
        article.className = 'bm-chat__msg' + (mine ? ' is-mine' : '');
        article.setAttribute('data-message-id', message.id);
        if (message.client_id) { article.setAttribute('data-client-id', message.client_id); }
        if (message.edited_at) { article.setAttribute('data-edited', '1'); }

        var mayReply = composer && composer.getAttribute('data-can-post') === '1';
        var tools = '<div class="bm-chat__msg-tools" role="group" aria-label="Действия с сообщением">' +
            (mayReply ? '<button type="button" class="bm-chat__tool" data-chat-reply="' + message.id + '" title="Ответить" aria-label="Ответить"><i class="ti ti-arrow-back-up"></i></button>' : '') +
            '<button type="button" class="bm-chat__tool" data-chat-react="' + message.id + '" title="Реакция" aria-label="Реакция"><i class="ti ti-mood-smile"></i></button>' +
            (mine
                ? '<button type="button" class="bm-chat__tool" data-chat-edit="' + message.id + '" title="Редактировать" aria-label="Редактировать"><i class="ti ti-pencil"></i></button>' +
                  '<button type="button" class="bm-chat__tool bm-chat__tool--danger" data-chat-delete="' + message.id + '" title="Удалить" aria-label="Удалить"><i class="ti ti-trash"></i></button>'
                : '') +
            '</div>';

        article.innerHTML =
            '<span class="bm-chat__avatar bm-chat__avatar--xs" aria-hidden="true">' + escapeHtml(initials(name)) + '</span>' +
            '<div class="bm-chat__msg-main">' +
                '<header class="bm-chat__msg-meta">' +
                    '<span class="bm-chat__msg-author">' + escapeHtml(name) + '</span>' +
                    '<time class="bm-chat__msg-time">' + escapeHtml(timeOf(message.created_at)) + '</time>' +
                    (message.edited_at ? '<span class="bm-chat__msg-flag">изменено</span>' : '') +
                '</header>' +
                messageQuote(message) +
                '<div class="bm-chat__msg-body">' + formatBody(message.body || '') + '</div>' +
                filesHtml(message) +
                reactionsHtml(message) +
            '</div>' +
            tools;

        return article;
    }

    /** Вставка сообщения с дедупликацией по id и по client_id. */
    function appendMessage(message, options) {
        if (!messagesEl || !message) { return null; }
        options = options || {};

        var pending = message.client_id && pendingClientIds[message.client_id];
        if (message.id && renderedIds[message.id]) {
            if (pending) {
                pending.remove();
                delete pendingClientIds[message.client_id];
            }
            return messagesEl.querySelector('[data-message-id="' + message.id + '"]');
        }

        var node = buildMessage(message);
        var placeholder = messagesEl.querySelector('.bm-chat__placeholder--inline');
        if (placeholder) { placeholder.remove(); }

        /* Подменяем оптимистичный пузырь на месте: ответы могут прийти не по порядку. */
        if (pending && pending.parentNode === messagesEl) {
            delete pendingClientIds[message.client_id];
            pending.replaceWith(node);
        } else if (options.prepend && messagesEl.firstChild) {
            messagesEl.insertBefore(node, messagesEl.firstChild);
        } else {
            messagesEl.appendChild(node);
        }

        if (message.id) { renderedIds[message.id] = true; }
        return node;
    }

    function removeMessage(id) {
        var node = messagesEl && messagesEl.querySelector('[data-message-id="' + id + '"]');
        if (node && node.parentNode) { node.parentNode.removeChild(node); }
        delete renderedIds[id];
    }

    function updateMessage(message) {
        if (!messagesEl || !message) { return; }
        var old = messagesEl.querySelector('[data-message-id="' + message.id + '"]');
        if (!old) { appendMessage(message); return; }

        var fresh = buildMessage(message);
        old.parentNode.replaceChild(fresh, old);
        renderedIds[message.id] = true;
    }

    /* ================================================================
       Непрочитанные
       ================================================================ */

    function setBadge(conversationId, count) {
        var item = document.querySelector('[data-chat-item][data-id="' + conversationId + '"]');
        if (!item) { return; }

        var badge = item.querySelector('[data-chat-badge]');
        count = Number(count) || 0;

        if (count <= 0) {
            if (badge && badge.parentNode) { badge.parentNode.removeChild(badge); }
            return;
        }

        var text = count > 99 ? '99+' : String(count);
        if (badge) {
            if (badge.textContent !== text) {
                badge.textContent = text;
                badge.classList.remove('is-bumped');
                void badge.offsetWidth; /* перезапуск анимации */
                badge.classList.add('is-bumped');
            }
            return;
        }

        badge = document.createElement('span');
        badge.className = 'bm-chat__badge is-bumped';
        badge.setAttribute('data-chat-badge', '');
        badge.textContent = text;
        item.appendChild(badge);
    }

    function refreshUnread() {
        var url = root.getAttribute('data-route-unread');
        if (!url) { return; }

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (body) {
                if (!body || !body.conversations) { return; }
                body.conversations.forEach(function (row) { setBadge(row.id, row.unread_count); });
            })
            .catch(function () { /* сводка обновится следующим циклом */ });
    }

    /* ================================================================
       Опрос дельт
       ================================================================ */

    function applyEvent(event) {
        if (!event) { return; }

        if (event.id && event.id > lastEventId) { lastEventId = event.id; }

        var message = event.message;

        switch (event.type) {
            case 'created':
                if (message && (message.id || message.client_id)) {
                    appendMessage(message);
                }
                break;

            case 'updated':
                if (message) { updateMessage(message); }
                break;

            case 'deleted':
                if (message && message.id) { removeMessage(message.id); }
                break;

            case 'reaction':
                if (message) { updateMessage(message); }
                break;

            case 'read':
            case 'member_joined':
            case 'member_left':
            case 'pinned':
                /* Состав и прочтение подтягиваются следующей сводкой. */
                break;

            default:
                break;
        }
    }

    function poll() {
        if (!activeId || !messagesEl || pollBusy) { return; }
        if (document.hidden) { return; } /* вкладка скрыта — не тратим запросы */

        pollBusy = true;

        fetch(routeBase + '/' + activeId + '/events?since=' + lastEventId, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                if (response.status === 403 || response.status === 404) {
                    window.location.replace(routeBase);
                }
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json();
            })
            .then(function (body) {
                consecutiveFailures = 0;
                var wasNearBottom = isNearBottom();
                var events = body.events || [];

                events.forEach(applyEvent);

                if (body.last_event_id) { lastEventId = Math.max(lastEventId, Number(body.last_event_id)); }

                if (events.length) {
                    if (wasNearBottom) { scrollToBottom(); }
                    markRead();
                    refreshUnread();
                }
            })
            .catch(function () {
                consecutiveFailures += 1;
                /* Пауза растёт до 30с: сеть молчит — не долбим её. */
                schedulePoll(Math.min(30000, 4000 * Math.pow(2, Math.min(consecutiveFailures, 3))));
            })
            .then(function () {
                pollBusy = false;
                schedulePoll();
            });
    }

    function schedulePoll(delay) {
        if (pollTimer) { window.clearTimeout(pollTimer); }
        pollTimer = window.setTimeout(poll, delay || 4000);
    }

    function startPolling() {
        if (pollTimer) { window.clearTimeout(pollTimer); }
        schedulePoll(1500);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && activeId) { poll(); }
    });

    /* ================================================================
       Прочтение
       ================================================================ */

    var readTimer = null;

    function markRead() {
        if (!activeId || !messagesEl) { return; }
        if (readTimer) { return; }

        readTimer = window.setTimeout(function () {
            readTimer = null;
            if (document.hidden) { return; }

            var nodes = messagesEl.querySelectorAll('[data-message-id]');
            if (!nodes.length) { return; }
            var last = nodes[nodes.length - 1];
            var lastId = parseInt(last.getAttribute('data-message-id') || '0', 10);
            if (!lastId) { return; }

            post(routeBase + '/' + activeId + '/read', { up_to_message_id: lastId })
                .then(function (body) {
                    setBadge(activeId, body.unread_count);
                })
                .catch(function () { /* следующий цикл */ });
        }, 600);
    }

    if (messagesEl) {
        messagesEl.addEventListener('scroll', function () {
            if (isNearBottom()) { markRead(); }
        });
    }

    /* ================================================================
       Отправка
       ================================================================ */

    function makeClientId() {
        return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    function appendPending(clientId, text, reply) {
        var node = document.createElement('article');
        node.className = 'bm-chat__msg is-mine is-pending';
        node.setAttribute('data-client-id', clientId);
        node.innerHTML =
            '<span class="bm-chat__avatar bm-chat__avatar--xs" aria-hidden="true">?</span>' +
            '<div class="bm-chat__msg-main">' +
                '<header class="bm-chat__msg-meta">' +
                    '<span class="bm-chat__msg-author">Вы</span>' +
                    '<time class="bm-chat__msg-time">' + escapeHtml(timeOf(new Date().toISOString())) + '</time>' +
                 '</header>' +
                 quoteHtml(reply, false) +
                '<div class="bm-chat__msg-body">' + formatBody(text) + '</div>' +
            '</div>';

        var placeholder = messagesEl.querySelector('.bm-chat__placeholder--inline');
        if (placeholder) { placeholder.remove(); }

        messagesEl.appendChild(node);
        pendingClientIds[clientId] = node;
        scrollToBottom();
        return node;
    }

    function failPending(clientId, message, draft) {
        var node = pendingClientIds[clientId];
        if (!node) { return; }

        node.classList.remove('is-pending');
        node.classList.add('is-failed');

        var flag = document.createElement('div');
        flag.className = 'bm-chat__msg-error';
        flag.textContent = message || 'Не отправлено';

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-outline-danger';
        retry.textContent = 'Повторить';
        retry.addEventListener('click', function () {
            if (node.parentNode) { node.parentNode.removeChild(node); }
            sendMessage(draft);
        });

        var row = document.createElement('div');
        row.className = 'bm-chat__msg-error-row';
        row.appendChild(flag);
        row.appendChild(retry);
        node.appendChild(row);
    }

    function sendMessage(draft) {
        if (!activeId || !composer) { return Promise.resolve(); }

        var canPost = composer.getAttribute('data-can-post') === '1';
        if (!canPost) { notify('Недостаточно прав на отправку сообщений', true); return Promise.resolve(); }

        var text = draft.text.trim();
        if (!text) { return Promise.resolve(); }

        var clientId = draft.clientId;
        appendPending(clientId, text, draft.reply);

        var payload = { body: text, client_id: clientId };
        if (draft.reply) { payload.reply_to_id = Number(draft.reply.id); }

        return post(composer.getAttribute('data-url'), payload)
            .then(function (body) {
                if (!body.message) { throw new Error('Сервер не подтвердил отправку'); }
                appendMessage(body.message);
                refreshUnread();
            })
            .catch(function (err) {
                var msg = 'Сообщение не отправлено';
                if (err && err.body && err.body.errors) {
                    var first = Object.keys(err.body.errors)[0];
                    if (first) { msg = err.body.errors[first][0]; }
                } else if (err && err.message) {
                    msg = err.message;
                }
                failPending(clientId, msg, draft);
            })
            .then(function () {
                markRead();
            });
    }

    if (composer) {
        composer.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!input || sendButton.disabled) { return; }

            var draft = { text: input.value, reply: replyDraft, clientId: makeClientId() };
            input.value = '';
            setReply(null);
            autoGrow();
            sendMessage(draft);
        });
    }

    function autoGrow() {
        if (!input) { return; }
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
        sendButton.disabled = input.disabled || !input.value.trim();
    }

    if (input) {
        autoGrow();
        window.addEventListener('pageshow', autoGrow);
        input.addEventListener('input', autoGrow);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && replyDraft) {
                event.preventDefault();
                setReply(null);
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
                event.preventDefault();
                if (!sendButton.disabled) { composer.requestSubmit(); }
            }
        });
    }

    /* ================================================================
       Ответ / редактирование / удаление / реакции
       ================================================================ */

    function setReply(reply) {
        replyDraft = reply;
        if (!replyBar) { return; }

        var atBottom = isNearBottom();
        replyBar.hidden = !reply;
        replyAuthor.textContent = reply ? 'Ответ: ' + reply.author : '';
        replyText.textContent = reply ? quotePreview(reply.body) : '';
        if (atBottom) { scrollToBottom(); }
    }

    var cancelReply = document.getElementById('chat-reply-cancel');
    if (cancelReply) {
        cancelReply.addEventListener('click', function () {
            setReply(null);
            input.focus({ preventScroll: true });
        });
    }

    function findMessageData(id) {
        if (!messagesEl) { return null; }
        var node = messagesEl.querySelector('[data-message-id="' + id + '"]');
        if (!node) { return null; }
        return {
            node: node,
            body: node.querySelector('.bm-chat__msg-body'),
        };
    }

    function startEdit(id) {
        var found = findMessageData(id);
        if (!found || !found.body || found.body.querySelector('textarea')) { return; }

        var original = found.body.textContent;
        var area = document.createElement('textarea');
        area.className = 'form-control bm-chat__edit';
        area.value = original;
        area.rows = 2;
        area.maxLength = 8000;

        found.body.textContent = '';
        found.body.appendChild(area);
        area.focus();
        area.setSelectionRange(area.value.length, area.value.length);

        function cancel() { updateMessageFromBody(id, original, true); }

        function save() {
            var value = area.value.trim();
            if (!value || value === original) { cancel(); return; }

            method(routeBase + '/messages/' + id, 'PUT', { body: value })
                .then(function (body) {
                    if (body.message) { updateMessage(body.message); }
                })
                .catch(function (err) {
                    notify(err.message || 'Не удалось сохранить', true);
                    cancel();
                });
        }

        area.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); cancel(); }
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) { event.preventDefault(); save(); }
        });
        area.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (document.activeElement !== area && area.parentNode) { save(); }
            }, 150);
        });
    }

    /** Точечная правка тела без полной перерисовки (для cancel). */
    function updateMessageFromBody(id, text, clearEdited) {
        var found = findMessageData(id);
        if (!found || !found.body) { return; }
        found.body.innerHTML = formatBody(text);
        if (clearEdited) {
            var flag = found.node.querySelector('.bm-chat__msg-flag');
            if (flag) { flag.remove(); }
            found.node.removeAttribute('data-edited');
        }
    }

    function deleteMessage(id) {
        if (!window.confirm('Удалить сообщение?')) { return; }

        method(routeBase + '/messages/' + id, 'DELETE')
            .then(function () { removeMessage(id); refreshUnread(); })
            .catch(function (err) { notify(err.message || 'Не удалось удалить', true); });
    }

    var QUICK_EMOJI = ['👍', '❤️', '😄', '🎉', '🙏', '👀'];

    function closeEmojiMenu() {
        if (emojiMenu && emojiMenu.parentNode) { emojiMenu.parentNode.removeChild(emojiMenu); }
        emojiMenu = null;
    }

    function openEmojiMenu(anchor, messageId) {
        closeEmojiMenu();

        var message = null;
        var node = findMessageData(messageId);
        if (node) {
            message = { id: messageId, node: node.node };
        }
        if (!message) { return; }

        var menu = document.createElement('div');
        menu.className = 'bm-chat__emoji-menu';
        menu.setAttribute('role', 'menu');

        QUICK_EMOJI.forEach(function (emoji) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bm-chat__emoji-option';
            btn.textContent = emoji;
            btn.setAttribute('role', 'menuitem');
            btn.addEventListener('click', function () {
                toggleReaction(messageId, emoji, true);
                closeEmojiMenu();
            });
            menu.appendChild(btn);
        });

        document.body.appendChild(menu);

        var rect = anchor.getBoundingClientRect();
        menu.style.top = (window.scrollY + rect.top - menu.offsetHeight - 8) + 'px';
        menu.style.left = Math.max(8, Math.min(window.scrollX + rect.left - 40, window.innerWidth - menu.offsetWidth - 8)) + 'px';

        emojiMenu = menu;
    }

    document.addEventListener('click', function (event) {
        if (emojiMenu && !emojiMenu.contains(event.target) && !event.target.closest('[data-chat-react]')) {
            closeEmojiMenu();
        }
    });

    function toggleReaction(messageId, emoji, forceOn) {
        var node = findMessageData(messageId);
        if (!node) { return; }

        var existing = node.node.querySelector('[data-emoji="' + cssEscape(emoji) + '"]');
        var on = forceOn ? !(existing && existing.classList.contains('is-mine')) : !(existing && existing.classList.contains('is-mine'));

        method(routeBase + '/messages/' + messageId + '/reactions', 'POST', { emoji: emoji, on: on })
            .then(function (body) {
                var fresh = body.reactions || [];
                renderReactions(node.node, fresh);
            })
            .catch(function (err) { notify(err.message || 'Реакция не сработала', true); });
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function renderReactions(node, reactions) {
        var existing = node.querySelector('.bm-chat__msg-reactions');
        if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
        if (!reactions.length) { return; }

        var temp = document.createElement('div');
        temp.innerHTML = reactionsHtml({ reactions: reactions });
        var block = temp.firstChild;
        if (block) { node.querySelector('.bm-chat__msg-main').appendChild(block); }
    }

    /* ================================================================
       Делегирование кликов по ленте
       ================================================================ */

    if (messagesEl) {
        messagesEl.addEventListener('click', function (event) {
            var target = event.target.closest ? event.target.closest('button') : null;
            if (!target) { return; }

            if (target.hasAttribute('data-chat-reply')) {
                var id = target.getAttribute('data-chat-reply');
                var found = findMessageData(id);
                if (!found || !input || input.disabled) { return; }
                var author = found.node.querySelector('.bm-chat__msg-author');
                setReply({ id: id, author: author ? author.textContent.trim() : 'Сообщение',
                    body: found.body ? found.body.innerText : '' });
                input.focus({ preventScroll: true });
                return;
            }

            if (target.hasAttribute('data-chat-react')) {
                openEmojiMenu(target, target.getAttribute('data-chat-react'));
                return;
            }

            if (target.hasAttribute('data-chat-edit')) {
                startEdit(target.getAttribute('data-chat-edit'));
                return;
            }

            if (target.hasAttribute('data-chat-delete')) {
                deleteMessage(target.getAttribute('data-chat-delete'));
                return;
            }

            if (target.hasAttribute('data-toggle-reaction')) {
                var container = target.closest('[data-message-id]');
                if (container) {
                    toggleReaction(container.getAttribute('data-message-id'), target.getAttribute('data-emoji'));
                }
                return;
            }

            if (target.hasAttribute('data-reply-to')) {
                var replyId = target.getAttribute('data-reply-to');
                var replyNode = replyId && messagesEl.querySelector('[data-message-id="' + replyId + '"]');
                if (replyNode) {
                    replyNode.classList.add('is-highlighted');
                    var viewport = messagesEl.getBoundingClientRect();
                    var original = replyNode.getBoundingClientRect();
                    messagesEl.scrollTop += original.top + original.height / 2 - viewport.top - messagesEl.clientHeight / 2;
                    replyNode.setAttribute('tabindex', '-1');
                    replyNode.focus({ preventScroll: true });
                    window.setTimeout(function () { replyNode.classList.remove('is-highlighted'); }, 1200);
                } else {
                    notify('Исходное сообщение не загружено в текущей истории', true);
                }
            }
        });
    }

    /* ================================================================
       Список диалогов: фильтр и поиск
       ================================================================ */

    var filterValue = 'all';
    var searchValue = '';

    var filterEmpty = document.getElementById('chat-filter-empty');

    function applyListFilter() {
        var items = document.querySelectorAll('[data-chat-item]');
        var visible = 0;
        items.forEach(function (item) {
            var typeMatch = filterValue === 'all' || item.getAttribute('data-type') === filterValue;
            var title = (item.getAttribute('data-title') || '').toLowerCase();
            var searchMatch = !searchValue || title.indexOf(searchValue) !== -1;
            item.hidden = !(typeMatch && searchMatch);
            if (!item.hidden) { visible += 1; }
        });
        filterEmpty.hidden = visible > 0 || !items.length;
    }

    document.querySelectorAll('[data-chat-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-chat-filter]').forEach(function (other) {
                other.classList.remove('is-active');
                other.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
            filterValue = btn.getAttribute('data-chat-filter') || 'all';
            applyListFilter();
        });
    });

    var listFilter = document.getElementById('chat-list-filter');
    if (listFilter) {
        listFilter.addEventListener('input', function () {
            searchValue = listFilter.value.trim().toLowerCase();
            applyListFilter();
        });
    }

    /* ================================================================
       Информация о диалоге (правая панель)
       ================================================================ */

    var contextToggle = document.getElementById('chat-context-toggle');
    var context = document.getElementById('chat-context');
    if (contextToggle && context) {
        function setDetails(open) {
            context.classList.toggle('is-open', open);
            contextToggle.setAttribute('aria-expanded', String(open));
        }
        contextToggle.addEventListener('click', function () {
            setDetails(!context.classList.contains('is-open'));
        });
        document.addEventListener('pointerdown', function (event) {
            if (!context.contains(event.target) && !contextToggle.contains(event.target)) {
                setDetails(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && context.classList.contains('is-open')) {
                setDetails(false);
                contextToggle.focus();
            }
        });
    }

    /* ================================================================
       Модалка нового диалога
       ================================================================ */

    document.querySelectorAll('form[data-chat-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.chatConfirm)) { event.preventDefault(); }
        });
    });
    document.querySelectorAll('[data-chat-image-preview]').forEach(function (input) {
        var preview = document.getElementById(input.dataset.chatImagePreview);
        if (!preview) { return; }
        var original = preview.getAttribute('src');
        var localUrl = null;
        input.addEventListener('change', function () {
            if (localUrl) { URL.revokeObjectURL(localUrl); }
            var file = input.files[0];
            localUrl = file && /^image\/(jpeg|png|webp)$/.test(file.type) && file.size <= 8 * 1024 * 1024
                ? URL.createObjectURL(file)
                : null;
            var source = localUrl || original;
            if (source) { preview.src = source; }
            else { preview.removeAttribute('src'); }
            preview.hidden = !source;
        });
    });

    var newDialog = document.getElementById('chat-new-dialog');
    if (newDialog) {
        var typeSelect = document.getElementById('chat-new-type');
        var employeeBlock = document.getElementById('chat-new-employee');
        var membersBlock = document.getElementById('chat-new-members-block');
        var titleField = document.getElementById('chat-new-title-field');
        var visibilityField = document.getElementById('chat-new-visibility');
        var postField = document.getElementById('chat-new-post-permission');
        var employeeInput = document.getElementById('chat-new-employee-select');
        var membersInput = document.getElementById('chat-new-members');
        var newForm = newDialog.querySelector('form');

        function currentVisibility() {
            var select = visibilityField ? visibilityField.querySelector('select') : null;
            return select ? select.value : 'company';
        }

        function syncTypeFields() {
            var type = typeSelect ? typeSelect.value : 'direct';
            if (employeeBlock) { employeeBlock.hidden = type !== 'direct'; }
            if (titleField) { titleField.hidden = type === 'direct'; }
            if (visibilityField) { visibilityField.hidden = type !== 'channel'; }
            if (postField) { postField.hidden = type !== 'channel'; }

            /* Состав нужен группе и закрытому каналу. */
            var needMembers = type === 'group' || (type === 'channel' && currentVisibility() === 'private');
            if (membersBlock) { membersBlock.hidden = !needMembers; }
            employeeInput.disabled = type !== 'direct';
            membersInput.disabled = !needMembers;
        }

        if (typeSelect) { typeSelect.addEventListener('change', syncTypeFields); }
        if (visibilityField) {
            var visSelect = visibilityField.querySelector('select');
            if (visSelect) { visSelect.addEventListener('change', syncTypeFields); }
        }
        syncTypeFields();

        /* Отправка формы: JSON + переход в созданный диалог. */
        if (newForm) {
            var createButton = newForm.querySelector('[type="submit"]');
            var createError = document.getElementById('chat-new-error');
            newForm.addEventListener('submit', function (event) {
                event.preventDefault();

                if (createButton.disabled) { return; }
                createError.hidden = true;
                createButton.disabled = true;
                createButton.textContent = 'Создаём…';
                var data = new FormData(newForm);
                var payload = { member_ids: data.getAll('member_ids[]').map(Number) };

                data.forEach(function (value, key) {
                    if (key !== 'member_ids[]') { payload[key] = value; }
                });

                post(root.getAttribute('data-route-store'), payload)
                    .then(function (body) {
                        if (body.conversation && body.conversation.id) {
                            window.location.href = routeBase + '/' + body.conversation.id;
                        }
                    })
                    .catch(function (err) {
                        var msg = err.message || 'Не удалось создать диалог';
                        if (err.body && err.body.errors) {
                            var key = Object.keys(err.body.errors)[0];
                            if (key) { msg = err.body.errors[key][0]; }
                        }
                        createError.textContent = msg;
                        createError.hidden = false;
                    })
                    .finally(function () {
                        createButton.disabled = false;
                        createButton.textContent = 'Создать';
                    });
            });
        }
    }

    /* ================================================================
       Realtime (Reverb) — ускорение, не единственный источник
       ================================================================ */

    function subscribeRealtime() {
        var rt = window.BMRealtime;
        if (!rt || !rt.available || !activeId) { return; }

        rt.on(activeId, function () {
            /* Сразу забираем дельты из БД — там источник истины. */
            poll();
        });

        rt.onNotice(function (payload) {
            if (payload && payload.type === 'unread') { refreshUnread(); }
        });
    }

    /* ================================================================
       Старт
       ================================================================ */

    if (messagesEl) {
        var initial = messagesEl.querySelectorAll('[data-message-id]');
        initial.forEach(function (node) {
            var id = parseInt(node.getAttribute('data-message-id') || '0', 10);
            if (id) { renderedIds[id] = true; }
        });

        /* Позиция: к последнему сообщению, без анимации при загрузке. */
        scrollToBottom();
        markRead();
    }

    if (activeId) {
        startPolling();
        subscribeRealtime();
    }

    window.BMChat = {
        refreshUnread: refreshUnread,
        poll: poll,
        sendMessage: sendMessage,
    };
})();
