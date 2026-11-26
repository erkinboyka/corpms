@extends('layouts.tabler')
@section('workspace_layout', 'conversation')
@section('body_class', 'bm-assistant bm-public-chat')
@section('meta_robots', 'noindex,nofollow')

@section('title', __('Чат с Bumi'))

@section('content')
<div class="bm-assistant__workspace" data-bumi-public-workspace>
    <section class="bm-assistant__conversation" aria-labelledby="bumi-widget-heading">
        <header class="bm-assistant__thread-header">
            <div class="bm-assistant__identity">
                @include('partials.bumi-face', ['class' => 'bm-assistant__badge'])
                <div>
                    <h1 id="bumi-widget-heading">Bumi</h1>
                    <p>{{ $channel->greeting ?: __('Напишите, чем могу помочь') }}</p>
                </div>
            </div>
            <span class="crm-chip crm-chip--green">{{ __('на связи') }}</span>
        </header>

        <div id="bumi-widget-log" class="bm-assistant__messages" role="region" aria-label="{{ __('История диалога') }}" tabindex="0">
            <div class="bm-assistant__empty" id="bumi-widget-empty">
                @include('partials.bumi-face', ['class' => 'bm-assistant__mascot'])
                <h2>{{ __('Чем помочь?') }}</h2>
                <p>{{ __('Напишите вопрос — Bumi ответит по базе знаний компании и передаст обращение сотруднику.') }}</p>
                @if ($sampleQuestions !== [])
                    <div class="bm-assistant__prompts">
                        @foreach ($sampleQuestions as $question)
                            <button type="button" data-bumi-prompt="{{ $question }}">
                                <i class="ti ti-message-circle" aria-hidden="true"></i><span>{{ $question }}</span>
                                <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="bm-assistant__footer">
            <p id="bumi-widget-error" class="bm-assistant__error d-none" role="alert"></p>
            <form id="bumi-widget-form" class="bm-assistant__composer">
                <label class="visually-hidden" for="bumi-widget-input">{{ __('Ваше сообщение') }}</label>
                <textarea id="bumi-widget-input" rows="1" maxlength="4000" autocomplete="off"
                          placeholder="{{ __('Напишите сообщение…') }}" required></textarea>
                <button type="submit" class="bm-assistant__send" id="bumi-widget-send" aria-label="{{ __('Отправить') }}">
                    <i class="ti ti-arrow-up" aria-hidden="true"></i>
                </button>
            </form>
            <p class="bm-assistant__keys">{{ __('Enter — отправить · Shift+Enter — новая строка') }}</p>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var channel = '{{ $channel->public_token }}';
    var endpoint = '/api/bumi/channels/' + encodeURIComponent(channel) + '/messages';
    var log = document.getElementById('bumi-widget-log');
    var empty = document.getElementById('bumi-widget-empty');
    var form = document.getElementById('bumi-widget-form');
    var input = document.getElementById('bumi-widget-input');
    var button = document.getElementById('bumi-widget-send');
    var errorBox = document.getElementById('bumi-widget-error');
    var faceTemplate = document.querySelector('.bm-assistant__badge');
    var known = {};
    var pending = {};
    var lastId = 0;
    var sending = 0;
    var typing = null;
    var csrf = document.querySelector('meta[name="csrf-token"]');

    function visitorKey() {
        var key = null;
        try { key = localStorage.getItem('bumi_visitor_key'); } catch (e) { key = null; }
        if (key && /^[A-Za-z0-9_-]{16,64}$/.test(key)) { return key; }
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            key = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        } else {
            key = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        }
        try { localStorage.setItem('bumi_visitor_key', key); } catch (e) { /* приватный режим */ }
        return key;
    }

    var visitor = visitorKey();

    function clientId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'c-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function showError(text) {
        errorBox.textContent = text || '';
        errorBox.classList.toggle('d-none', !text);
    }

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    function fadeIn(node) {
        if (!node || reducedMotion.matches) { return; }
        node.classList.add('is-entering');
        node.addEventListener('animationend', function clear(event) {
            if (event.target !== node) { return; }
            node.classList.remove('is-entering');
            node.removeEventListener('animationend', clear);
        });
    }

    function makeFace() {
        if (!faceTemplate) {
            var fallback = document.createElement('span');
            fallback.className = 'ai-msg__avatar';
            fallback.textContent = 'B';
            return fallback;
        }
        var face = faceTemplate.cloneNode(true);
        face.removeAttribute('id');
        face.className = 'bumi-face ai-msg__avatar';
        return face;
    }

    function removeEmpty() {
        if (empty) { empty.remove(); empty = null; }
    }

    function buildRow(item) {
        var isBumi = item.direction === 'out';
        var row = document.createElement('div');
        row.className = 'ai-msg ' + (isBumi ? 'ai-msg--ai' : 'ai-msg--me');
        if (item.client_message_id) { row.setAttribute('data-client-id', item.client_message_id); }
        if (isBumi) { row.appendChild(makeFace()); }

        var bubble = document.createElement('div');
        bubble.className = 'ai-msg__bubble';

        var text = document.createElement(isBumi ? 'div' : 'p');
        text.className = 'bm-assistant__answer';
        text.textContent = item.text || '';
        bubble.appendChild(text);

        if (isBumi) {
            var footer = document.createElement('div');
            footer.className = 'ai-msg__meta bm-assistant__answer-footer';
            var label = document.createElement('span');
            label.textContent = 'Bumi';
            footer.appendChild(label);
            bubble.appendChild(footer);
        } else {
            var time = document.createElement('time');
            time.className = 'ai-msg__meta';
            var state = item.status_label && item.status !== 'sent' && item.status !== 'answered'
                ? ' · ' + item.status_label
                : '';
            time.textContent = '{{ __('Вы') }}' + state;
            bubble.appendChild(time);
        }

        row.appendChild(bubble);
        return row;
    }

    function removeTyping() {
        if (typing && typing.parentNode) { typing.parentNode.removeChild(typing); }
        typing = null;
    }

    function showTyping() {
        if (typing) { return; }
        typing = document.createElement('div');
        typing.className = 'ai-msg ai-msg--ai is-typing';
        typing.setAttribute('role', 'status');
        typing.setAttribute('aria-live', 'polite');
        typing.textContent = 'Bumi отвечает…';
        log.appendChild(typing);
        fadeIn(typing);
    }

    function addMessage(item) {
        var clientKey = item.client_message_id || '';
        var waiting = clientKey && pending[clientKey];

        if (waiting) {
            var answer = buildRow(item);
            waiting.replaceWith(answer);
            fadeIn(answer);
            if (window.CorpMsBumi) window.CorpMsBumi.react('ai_ready');
            delete pending[clientKey];
            known[item.id] = true;
        } else {
            if (known[item.id]) { return; }
            known[item.id] = true;
            removeEmpty();
            var row = buildRow(item);
            log.appendChild(row);
            fadeIn(row);
        }

        if (item.direction === 'out') { removeTyping(); }
        if (Number(item.id) > lastId) { lastId = Number(item.id); }
        log.scrollTop = log.scrollHeight;
    }

    function markFailed(row, id, text) {
        row.classList.remove('is-pending');
        row.classList.add('is-failed');
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'ai-msg__retry btn btn-sm btn-ghost-danger';
        retry.textContent = '{{ __('Повторить') }}';
        retry.addEventListener('click', function () {
            retry.remove();
            row.classList.remove('is-failed');
            send(text, id, row);
        });
        row.querySelector('.ai-msg__bubble').appendChild(retry);
    }

    function poll() {
        fetch(endpoint + '?visitor=' + encodeURIComponent(visitor) + '&since=' + lastId, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.json();
        }).then(function (data) {
            showError('');
            (data.messages || []).forEach(addMessage);
        }).catch(function () {
            showError('{{ __('Не удалось загрузить переписку. Повторите ещё раз.') }}');
        });
    }

    function send(text, id, row) {
        if (!text) { return; }
        sending++;
        button.disabled = true;

        if (!row) {
            removeEmpty();
            row = buildRow({ direction: 'in', text: text, client_message_id: id, status: 'pending', status_label: '{{ __('Отправляется') }}' });
            row.classList.add('is-pending');
            log.appendChild(row);
            fadeIn(row);
            pending[id] = row;
        } else {
            row.classList.add('is-pending');
        }

        showTyping();
        if (window.CorpMsBumi) window.CorpMsBumi.react('thinking');
        log.scrollTop = log.scrollHeight;

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf ? csrf.content : ''
            },
            credentials: 'same-origin',
            body: JSON.stringify({ visitor: visitor, message: text, client_message_id: id })
        }).then(function (response) {
            if (response.status === 403 || response.status === 422) {
                throw new Error('unavailable');
            }
            if (!response.ok) { throw new Error('network'); }
            if (input.value.trim() === text) { input.value = ''; }
            showError('');
            poll();
        }).catch(function (error) {
            markFailed(row, id, text);
            if (window.CorpMsBumi) window.CorpMsBumi.react('ai_error');
            showError(error.message === 'unavailable'
                ? '{{ __('Чат временно недоступен. Попробуйте позже.') }}'
                : '{{ __('Не удалось отправить. Сообщение осталось в поле — нажмите «Повторить».') }}');
        }).finally(function () {
            sending--;
            button.disabled = sending > 0;
            input.focus();
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending > 0) { return; }
        var text = input.value.trim();
        if (text === '') { return; }
        send(text, clientId(), null);
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            if (sending > 0) { return; }
            var text = input.value.trim();
            if (text !== '') { send(text, clientId(), null); }
        }
    });

    document.addEventListener('click', function (event) {
        var prompt = event.target.closest && event.target.closest('[data-bumi-prompt]');
        if (!prompt) { return; }
        input.value = prompt.getAttribute('data-bumi-prompt') || '';
        input.focus();
    });

    poll();
    window.setInterval(poll, 4000);
})();
</script>
@endpush
