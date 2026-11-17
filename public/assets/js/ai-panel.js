/*
 * Боковая AI-панель (док справа): toggle, отправка JSON на /ai/message,
 * рендер ответа и блоков (текст, SVG-диаграмма, таблица, кнопки-ссылки).
 * Разметка: partials/ai-panel.blade.php, стили: assets/css/ai-panel.css.
 */
(function () {
    'use strict';

    var panel = document.getElementById('ai-panel');
    if (!panel) { return; }

    var scrim = document.getElementById('ai-panel-scrim');
    var messages = document.getElementById('ai-messages');
    var typing = document.getElementById('ai-typing');
    var form = document.getElementById('ai-form');
    var input = document.getElementById('ai-input');
    var sendBtn = document.getElementById('ai-send');
    var toggleBtn = document.querySelector('[data-ai-toggle]');
    var OPEN_KEY = 'crm-ai-panel-open';

    /* ---------- открытие/закрытие ---------- */

    function setOpen(open) {
        panel.classList.toggle('is-open', open);
        if (scrim) { scrim.classList.toggle('is-open', open); }
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (toggleBtn) { toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        try { localStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch (e) { /* private mode */ }
        if (open && input) { input.focus(); }
    }

    function isOpen() { return panel.classList.contains('is-open'); }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () { setOpen(!isOpen()); });
    }
    document.addEventListener('click', function (event) {
        var closer = event.target.closest ? event.target.closest('[data-ai-close]') : null;
        if (closer) { setOpen(false); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) { setOpen(false); }
    });
    try {
        if (localStorage.getItem(OPEN_KEY) === '1') { setOpen(true); }
    } catch (e) { /* private mode */ }

    /* ---------- утилиты ---------- */

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* **жирный** → <strong> на уже экранированном тексте */
    function formatText(value) {
        var safe = escapeHtml(value);
        safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        return safe.split(/\n{2,}/).map(function (para) {
            return '<p>' + para.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function scrollDown() {
        messages.scrollTop = messages.scrollHeight;
    }

    /* ---------- рендер блоков ---------- */

    function renderChart(block) {
        var data = (block.data || []).slice(0, 10);
        if (data.length === 0) { return null; }

        var width = 340;
        var rowH = 34;
        var labelW = 110;
        var height = data.length * rowH + 6;
        var max = 1;
        var i;
        for (i = 0; i < data.length; i++) {
            if (Number(data[i].value) > max) { max = Number(data[i].value); }
        }

        var svg = ['<svg class="ai-chart" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + escapeHtml(block.title || 'Диаграмма') + '">'];
        for (i = 0; i < data.length; i++) {
            var label = String(data[i].label);
            var value = Number(data[i].value) || 0;
            var y = i * rowH + 4;
            var barW = Math.max(2, Math.round((width - labelW - 60) * (value / max)));
            svg.push('<text class="ai-chart__label" x="0" y="' + (y + 15) + '">' + escapeHtml(label.slice(0, 16)) + '</text>');
            svg.push('<rect class="ai-chart__bar" x="' + labelW + '" y="' + (y + 3) + '" width="' + barW + '" height="17" rx="4"></rect>');
            svg.push('<text class="ai-chart__value" x="' + (labelW + barW + 6) + '" y="' + (y + 15) + '">' + value + '</text>');
        }
        svg.push('</svg>');
        return svg.join('');
    }

    function renderTable(block) {
        var rows = block.rows || [];
        if (rows.length === 0) { return null; }
        var html = ['<table class="ai-table">'];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || [];
            html.push('<tr><td>' + formatText(String(row[0] === undefined ? '' : row[0])) + '</td>' +
                '<td>' + formatText(String(row[1] === undefined ? '' : row[1])) + '</td></tr>');
        }
        html.push('</table>');
        return html.join('');
    }

    function renderActions(block) {
        var items = block.items || [];
        if (items.length === 0) { return null; }
        var html = ['<div class="ai-actions">'];
        for (var i = 0; i < items.length; i++) {
            var item = items[i] || {};
            var href = String(item.url || '#');
            /* только локальные ссылки — чужие схемы/домены не пропускаем */
            if (href.charAt(0) !== '/' && href.charAt(0) !== '#') { continue; }
            html.push('<a class="ai-action" href="' + escapeHtml(href) + '">' +
                '<i class="ti ' + escapeHtml(String(item.icon || 'ti-arrow-right')) + '"></i>' +
                escapeHtml(String(item.label || href)) + '</a>');
        }
        html.push('</div>');
        return html.join('');
    }

    function renderBlocks(blocks) {
        var out = '';
        for (var i = 0; i < (blocks || []).length; i++) {
            var block = blocks[i] || {};
            var body = '';
            if (block.type === 'chart') { body = renderChart(block); }
            else if (block.type === 'table') { body = renderTable(block); }
            else if (block.type === 'actions') { body = renderActions(block); }
            if (!body) { continue; }

            var title = block.title ? '<div class="ai-block__title">' + escapeHtml(String(block.title)) + '</div>' : '';
            out += '<div class="ai-block">' + title + body + '</div>';
        }
        return out;
    }

    /* ---------- лента сообщений ---------- */

    function addMessage(side, html, isError) {
        var wrap = document.createElement('div');
        wrap.className = 'ai-msg ai-msg--' + side + (isError ? ' ai-msg--error' : '');
        wrap.innerHTML =
            '<div class="ai-msg__avatar"><i class="ti ti-' + (side === 'me' ? 'user' : 'sparkles') + '"></i></div>' +
            '<div class="ai-msg__bubble">' + html + '</div>';
        messages.insertBefore(wrap, typing);
        scrollDown();
        return wrap;
    }

    function showTyping(show) {
        if (typing) { typing.hidden = !show; }
        if (show) { scrollDown(); }
    }

    /* ---------- отправка ---------- */

    var inFlight = false;

    function setBusy(busy) {
        inFlight = busy;
        if (input) { input.disabled = busy; }
        if (sendBtn) { sendBtn.disabled = busy; }
        /* C5: контейнер сообщений сигнализирует скринридерам о загрузке ответа */
        if (messages) { messages.setAttribute('aria-busy', busy ? 'true' : 'false'); }
    }

    function send(question) {
        question = String(question || '').trim();
        if (question.length < 3 || inFlight) { return; }

        addMessage('me', formatText(question));
        setBusy(true);
        showTyping(true);

        var token = form ? (form.querySelector('input[name="_token"]') || {}).value : '';

        fetch(form.getAttribute('action'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ question: question })
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                showTyping(false);
                var data = result.data || {};

                if (result.status === 422) {
                    var first = null;
                    var errors = data.errors || {};
                    for (var key in errors) {
                        if (Object.prototype.hasOwnProperty.call(errors, key)) { first = errors[key][0]; break; }
                    }
                    addMessage('ai', escapeHtml(first || 'Проверьте запрос — слишком короткий вопрос.'), true);
                    return;
                }

                var reply = data.ok === false && !data.reply
                    ? 'Не получилось обработать запрос. Попробуйте иначе.'
                    : (data.reply || '…');
                addMessage('ai', formatText(reply) + renderBlocks(data.blocks || []));
            })
            .catch(function () {
                showTyping(false);
                addMessage('ai', escapeHtml('Связь с сервером оборвалась. Попробуйте ещё раз.'), true);
            })
            .then(function () {
                setBusy(false);
                if (input) { input.value = ''; input.focus(); }
            });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(input ? input.value : '');
        });
    }

    /* быстрые подсказки */
    document.addEventListener('click', function (event) {
        var chip = event.target.closest ? event.target.closest('[data-ai-chip]') : null;
        if (!chip) { return; }
        if (!isOpen()) { setOpen(true); }
        send(chip.getAttribute('data-ai-chip'));
    });
})();
