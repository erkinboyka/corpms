(() => {
    'use strict';

    const bumiUi = (() => {
        try {
            const node = document.getElementById('bumi-i18n');
            return node ? (JSON.parse(node.textContent).ui || {}) : {};
        } catch (error) {
            return {};
        }
    })();
    const bt = (key, fallback) => (typeof bumiUi[key] === 'string' ? bumiUi[key] : fallback);
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let cancelActiveVoice = null;

    function bindDictation(form, onListening = () => {}, onTranscript = () => {}, onNotice = () => {}) {
        const mic = form.querySelector('[data-ai-voice]');
        const status = form.querySelector('[data-ai-voice-status]');
        const input = form.elements.question;
        let active;

        function announce(text) {
            status.textContent = text;
            status.hidden = !text;
            onNotice(text);
        }

        function resetIndicator() {
            mic.setAttribute('aria-pressed', 'false');
            mic.title = bt('dictateMessage', 'Диктовать сообщение');
            onListening(false);
        }

        function finish() {
            active = null;
            if (cancelActiveVoice === cancel) cancelActiveVoice = null;
            resetIndicator();
        }

        function cancel() {
            if (!active) return;
            const recognition = active;
            finish();
            recognition.abort?.();
            announce('');
        }

        function insertTranscript(transcript) {
            if (!transcript || input.readOnly) return;
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? start;
            const before = input.value.slice(0, start);
            const after = input.value.slice(end);
            const prefix = before && !/\s$/.test(before) ? ' ' : '';
            const suffix = after && !/^\s/.test(after) ? ' ' : '';
            const room = input.maxLength - before.length - after.length;
            if (room > prefix.length) {
                input.setRangeText((prefix + transcript + suffix).slice(0, room), start, end, 'end');
                onTranscript();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                announce(bt('textAdded', 'Текст добавлен. Проверьте его перед отправкой.'));
            } else {
                announce(bt('lengthReached', 'В сообщении уже достигнут предел длины.'));
            }
        }

        mic.addEventListener('click', () => {
            if (active) {
                active.stop();
                return;
            }
            if (!SpeechRecognition) {
                announce(bt('dictationUnsupported', 'Этот браузер не поддерживает диктовку.'));
                return;
            }
            if (input.readOnly) return;

            // На http://corpms.local браузер не даёт микрофон, поэтому серверная
            // диктовка — единственный рабочий путь; при недоступности она сама
            // покажет понятную причину вместо «not-allowed».
            const voice = window.CorpMsBumiVoice;
            if (voice?.enabled && voice.sttMode === 'server') {
                cancelActiveVoice?.();
                const handle = voice.dictate({
                    onTranscript: insertTranscript,
                    onStatus: announce,
                    onListening: active => {
                        mic.setAttribute('aria-pressed', String(!!active));
                        mic.title = active ? bt('dictationStop', 'Завершить диктовку') : bt('dictateMessage', 'Диктовать сообщение');
                        onListening(active);
                    },
                });
                if (!handle) return;
                active = { stop: () => handle.stop?.(), abort: () => handle.stop?.() };
                cancelActiveVoice = () => handle.stop?.();
                mic.setAttribute('aria-pressed', 'true');
                mic.title = bt('dictationStop', 'Завершить диктовку');
                onListening(true);
                announce(bt('micStarting', 'Включаю микрофон…'));
                return;
            }

            cancelActiveVoice?.();
            const recognition = new SpeechRecognition();
            recognition.lang = 'ru-RU';
            recognition.interimResults = false;
            recognition.continuous = false;

            recognition.onstart = () => {
                if (active !== recognition) return;
                announce(bt('listeningUi', 'Слушаю…'));
            };
            recognition.onresult = event => {
                if (active !== recognition || input.readOnly) return;
                const transcript = Array.from(event.results).slice(event.resultIndex)
                    .filter(result => result.isFinal).map(result => result[0].transcript.trim()).join(' ').trim();
                insertTranscript(transcript);
            };
            recognition.onerror = event => {
                if (active !== recognition) return;
                const errors = {
                    'not-allowed': window.isSecureContext
                        ? bt('micPermission', 'Разрешите браузеру доступ к микрофону для этого сайта.')
                        : bt('micNeedsHttps', 'Для доступа к микрофону откройте сайт по доверенному HTTPS.'),
                    'service-not-allowed': window.isSecureContext
                        ? bt('speechUnavailable', 'Распознавание речи недоступно для этого сайта.')
                        : bt('micNeedsHttps', 'Для доступа к микрофону откройте сайт по доверенному HTTPS.'),
                    'audio-capture': bt('micMissing', 'Браузер не нашёл доступный микрофон.'),
                    'no-speech': bt('speechNotRecognized', 'Речь не распознана. Попробуйте снова.'),
                    network: bt('speechServiceUnavailable', 'Служба распознавания речи недоступна. Можно ввести текст.'),
                };
                finish();
                announce(errors[event.error] || bt('dictationFinishFailed', 'Диктовку не удалось завершить.'));
            };
            recognition.onend = () => {
                if (active !== recognition) return;
                finish();
                if (status.textContent === bt('listeningUi', 'Слушаю…') || status.textContent === bt('micStarting', 'Включаю микрофон…')) {
                    announce(bt('speechNotRecognized', 'Речь не распознана. Попробуйте снова.'));
                }
            };

            try {
                active = recognition;
                cancelActiveVoice = cancel;
                mic.setAttribute('aria-pressed', 'true');
                mic.title = bt('dictationStop', 'Завершить диктовку');
                onListening(true);
                announce(bt('micStarting', 'Включаю микрофон…'));
                recognition.start();
            } catch {
                finish();
                announce(bt('dictationStartFailed', 'Браузер не смог начать диктовку.'));
            }
        });

        form.addEventListener('submit', () => {
            cancel();
            announce('');
        });
        return cancel;
    }

    // Реакции на лицо и moods теперь ведёт bumi-persona.js: у каждого
    // выражения есть причина, а не просто цикл «улыбка → подмигивание».
    function thinkingBubble(container) {
        const pending = document.createElement('div');
        pending.className = 'bumi-thinking';
        pending.setAttribute('role', 'status');
        pending.innerHTML = '<span class="visually-hidden">' + bt('processing', 'Бими обрабатывает запрос') + '</span><i></i><i></i><i></i>';
        const face = document.querySelector('[data-bumi-face] svg, [data-ai-toggle] svg');
        if (face) {
            const portrait = document.createElement('span');
            portrait.className = 'bumi-thinking__face';
            portrait.dataset.bumiEmotion = 'thinking';
            portrait.setAttribute('aria-hidden', 'true');
            portrait.append(face.cloneNode(true));
            pending.prepend(portrait);
        }
        container.append(pending);
        animateIn(pending);
        return pending;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    /** Анимация только для новых сообщений; SSR-историю не трогаем. */
    function animateIn(node) {
        if (!node || reduceMotion.matches) return node;
        node.classList.add('is-entering');
        node.addEventListener('animationend', function clear(event) {
            if (event.target !== node) return;
            node.classList.remove('is-entering');
            node.removeEventListener('animationend', clear);
        });
        return node;
    }

    /** Поставить ответ на место «думающего» сообщения и мягко убрать последнее. */
    function placeMessage(container, node, reference) {
        if (reference && reference.parentNode === container) container.insertBefore(node, reference);
        else container.append(node);
        return animateIn(node);
    }

    function retire(node) {
        if (!node) return;
        if (reduceMotion.matches) { node.remove(); return; }
        node.classList.add('is-leaving');
        window.setTimeout(() => node.remove(), 180);
    }

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-bumi-sign]')) return;
        event.preventDefault();
        const button = form.querySelector('button');
        if (button.disabled) return;
        button.disabled = true;
        const status = form.querySelector('[role=status]');
        status.textContent = bt('signing', 'Подписываю…');
        try {
            const response = await fetch(form.action, {method:'POST', headers:{Accept:'application/json'}, body:new FormData(form)});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || bt('signFailed', 'Подпись не выполнена.'));
            form.replaceChildren(status);
            status.textContent = data.message;
        } catch (error) {
            status.textContent = error instanceof SyntaxError || error instanceof TypeError ? bt('checkDocumentAndConnection', 'Проверьте состояние документа и соединение.') : error.message;
            button.disabled = false;
        }
    });

    let pointerBadge;
    let markedElement;
    let guideTimeout;
    function pointAt(target, origin) {
        if (!target) return;
        clearTimeout(guideTimeout);
        markedElement?.classList.remove('bumi-target');
        pointerBadge?.remove();
        target.scrollIntoView({block:'nearest', inline:'nearest', behavior:'instant'});
        markedElement = target;
        target.classList.add('bumi-target');
        const rect = target.getBoundingClientRect();
        const x = Math.max(4, Math.min(innerWidth-50, rect.right-30));
        const y = rect.top > 58 ? rect.top-48 : Math.min(innerHeight-50, rect.bottom+4);
        const face = document.querySelector('[data-bumi-face] svg, [data-ai-toggle] svg');
        if (face) {
            pointerBadge = document.createElement('span');
            pointerBadge.className = 'bumi-guide-pointer';
            pointerBadge.setAttribute('aria-hidden', 'true');
            pointerBadge.append(face.cloneNode(true));
            pointerBadge.style.left = x+'px';
            pointerBadge.style.top = y+'px';
            document.body.append(pointerBadge);
            if (origin && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                const from = origin.getBoundingClientRect();
                pointerBadge.animate([{transform:'translate('+(from.left-x)+'px,'+(from.top-y)+'px)'},{transform:'none'}], {duration:450,easing:'ease-out'});
            }
        }
        guideTimeout = setTimeout(() => {
            target.classList.remove('bumi-target');
            pointerBadge?.remove();
        }, 3800);
    }

    function guideReply(bubble) {
        const result = bubble.querySelector('a[data-bumi-guide], form.bumi-tool :is(input:not([type=hidden]),select,textarea), a[download]');
        if (result) requestAnimationFrame(() => pointAt(result, document.querySelector('[data-ai-toggle], .bm-assistant__badge')));
    }

    const guideKey = 'bumi-guide-destination';
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-bumi-guide]');
        if (!link || link.origin !== location.origin || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        try { sessionStorage.setItem(guideKey, JSON.stringify({path:link.pathname+link.search, until:Date.now()+30000})); } catch {}
    });

    document.addEventListener('click', event => {
        const control = event.target.closest('[data-bumi-templates], [data-template-use]');
        if (!control) return;
        const scope = control.closest('[data-ai-companion], [data-ai-workspace]');
        const composer = scope?.querySelector('.bm-assistant__composer, #ai-form')
            || document.querySelector('[data-ai-workspace] .bm-assistant__composer')
            || document.querySelector('#ai-form');
        if (!composer) return;
        const entry = composer.elements.question;
        const submit = composer.querySelector('[type=submit]');
        if (!entry || entry.readOnly || submit?.getAttribute('aria-label') === bt('sendingQuestion', 'Отправка вопроса')) return;

        const library = control.closest('[data-bumi-library]');
        const command = control.hasAttribute('data-template-use') && library
            ? [library.querySelector('[data-template-command]')?.value.trim(),
               library.querySelector('[data-template-topic]')?.value.trim()].filter(Boolean).join(' ')
            : bt('showTemplates', 'Покажи шаблоны');
        if (command.length < 3) return;

        const draft = entry.value;
        const status = library?.querySelector('[data-template-status]');
        control.classList.add('is-launching');
        window.setTimeout(() => control.classList.remove('is-launching'), 420);
        if (status) status.textContent = bt('sending', 'Отправляю запрос…');

        // Каталог/шаблон отправляет отдельную реплику, но черновик пользователя сохраняем.
        try {
            entry.value = command;
            entry.dispatchEvent(new Event('input', { bubbles: true }));
            composer.requestSubmit();
        } finally {
            entry.value = draft;
            entry.dispatchEvent(new Event('input', { bubbles: true }));
            window.setTimeout(() => { if (status) status.textContent = ''; }, 2400);
        }

        if (submit && !submit.disabled) entry.focus({ preventScroll: true });
    });

    const workspace = document.querySelector('[data-ai-workspace]');
    if (workspace) {
        const history = workspace.querySelector('[data-ai-history]');
        const form = workspace.querySelector('.bm-assistant__composer');
        const send = form.querySelector('button[type=submit]');
        const input = form.elements.question;
        const mic = form.querySelector('[data-ai-voice]');
        const copyStatus = workspace.querySelector('[data-ai-copy-status]');
        const prompts = workspace.querySelectorAll('[data-ai-prompt]');
        let submitting = false;
        const scrollToLatest = () => { history.scrollTop = history.scrollHeight; };
        scrollToLatest();
        workspace.querySelector('[data-ai-latest]')?.addEventListener('click', scrollToLatest);

        function updateComposer() {
            send.disabled = submitting || input.value.trim().length < 3;
            prompts.forEach(button => { button.disabled = submitting || !!input.value.trim(); });
        }
        updateComposer();
        bindDictation(form);
        input.addEventListener('input', updateComposer);
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229 && !send.disabled) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        prompts.forEach(button => {
            button.addEventListener('click', () => {
                input.value = button.dataset.aiPrompt;
                updateComposer();
                input.focus({ preventScroll: true });
            });
        });
        workspace.addEventListener('click', async event => {
                const button = event.target.closest('[data-ai-copy]');
                if (!button) return;
                const answer = button.closest('.ai-msg__bubble').querySelector('.bm-assistant__answer');
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(answer.innerText);
                    } else {
                        const range = document.createRange();
                        range.selectNodeContents(answer);
                        const selection = window.getSelection();
                        selection.removeAllRanges();
                        selection.addRange(range);
                        if (!document.execCommand('copy')) {
                            copyStatus.textContent = bt('copyManual', 'Ответ выделен. Нажмите Ctrl+C.');
                            return;
                        }
                        selection.removeAllRanges();
                    }
                    copyStatus.textContent = bt('answerCopied', 'Ответ скопирован');
                    window.CorpMsBumi?.react('copy');
                    const icon = button.querySelector('i');
                    icon.classList.replace('ti-copy', 'ti-check');
                    setTimeout(() => icon.classList.replace('ti-check', 'ti-copy'), 1200);
                } catch {
                    copyStatus.textContent = bt('copyFailed', 'Не удалось скопировать ответ.');
                }
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (send.disabled) {
                return;
            }
            const question = input.value.trim();
            submitting = true;
            window.CorpMsBumi?.react('thinking');
            input.value = '';
            history.querySelector('.bm-assistant__empty')?.remove();
            const row = document.createElement('div');
            row.className = 'ai-msg ai-msg--me';
            row.innerHTML = '<div class="ai-msg__bubble"></div>';
            row.firstChild.textContent = question;
            placeMessage(history, row);
            const pending = thinkingBubble(history);
            scrollToLatest();
            mic.disabled = true;
            send.setAttribute('aria-label', bt('sendingQuestion', 'Отправка вопроса'));
            send.querySelector('i').className = 'ti ti-loader-2';
            updateComposer();
            const answer = document.createElement('div');
            answer.className = 'ai-msg ai-msg--ai';
            const bubble = document.createElement('div');
            bubble.className = 'ai-msg__bubble';
            answer.append(bubble);
            try {
                const body = new FormData(form);
                body.set('question', question);
                const response = await fetch(form.dataset.messageUrl,{method:'POST',headers:{Accept:'application/json'},body});
                const data = await response.json();
                if(!response.ok || !data.ok) throw new Error(data.reply || responseError(response,data));
                bubble.innerHTML = '<div class="bm-assistant__answer ai-markdown">'+data.reply_html+'</div>'+(data.blocks_html || '')+
                    '<button type="button" class="bm-assistant__copy" data-ai-copy aria-label="' + bt('copyAnswer', 'Скопировать ответ') + '"><i class="ti ti-copy"></i></button>';
                window.CorpMsBumi?.react('ai_ready');
            } catch(error) {
                answer.classList.add('ai-msg--error');
                bubble.textContent = error instanceof SyntaxError || error instanceof TypeError ? bt('serverInterrupted', 'Связь с сервером прервалась. Повторите запрос.') : error.message;
                if(!input.value) input.value=question;
                window.CorpMsBumi?.react('ai_error');
            } finally {
                placeMessage(history, answer, pending);
                retire(pending);
                submitting = false;
                mic.disabled = false;
                send.setAttribute('aria-label', bt('sendQuestion', 'Отправить вопрос'));
                send.querySelector('i').className = 'ti ti-arrow-up';
                updateComposer();
                scrollToLatest();
                if (!answer.classList.contains('ai-msg--error')) guideReply(bubble);
            }
        });
    }

    const companion = document.querySelector('[data-ai-companion]');
    if (!companion) return;

    const panel = companion.querySelector('#ai-panel');
    const messages = companion.querySelector('#ai-messages');
    const form = companion.querySelector('#ai-form');
    const input = companion.querySelector('#ai-input');
    const sendBtn = companion.querySelector('#ai-send');
    const toggleBtn = companion.querySelector('[data-ai-toggle]');
    const status = companion.querySelector('[data-ai-pet-status]');
    const reaction = companion.querySelector('[data-ai-reaction]');
    const petSprite = companion.querySelector('[data-mini-avatar]').content.firstElementChild;
    const dock = companion.querySelector('.bm-mini-dock');
    const writeBtn = dock.querySelector('[data-ai-write]');
    const recordBtn = dock.querySelector('[data-mini-record]');
    const mic = form.querySelector('[data-ai-voice]');
    const role = companion.dataset.bumiRole || 'assistant';
    const roleTools = companion.querySelectorAll('[data-bumi-role-tool]');
    const lessonField = form.querySelector('[name="lesson_id"]');
    const modeField = form.querySelector('[name="bumi_mode"]');
    const greeting = companion.dataset.bumiGreeting || '';
    let greeted = false;
    const thread = panel.querySelector('[data-mini-thread]');
    const motion = window.matchMedia('(prefers-reduced-motion: no-preference)');
    // При открытой модалке фон размыт, и слежение за курсором заставляет
    // браузер заново композировать слой — курсор начинает заметно тормозить.
    const overlayIsOpen = () => document.body.classList.contains('modal-open')
        || !!document.querySelector('.modal.show, .offcanvas.show');
    let headCenter = { x: 0, y: 0 };
    let landingTimer;
    const positionKey = 'bm-ai-pet-position';
    const margin = 12;
    const gap = 8;
    const panelWidth = 400;
    let position = { x: 1, y: 1 };
    let drag;
    let ignoreClick = false;
    let reactionTimer;
    const stateLines = {
        idle: bt('online', 'На связи'),
        thinking: bt('thinking', 'Думаю…'),
        ready: bt('answerReady', 'Ответ готов'),
        error: bt('tryAgain', 'Попробуем ещё?'),
    };
    const cancelVoice = bindDictation(form, active => {
        companion.toggleAttribute('data-listening', active);
        recordBtn.setAttribute('aria-pressed', String(active));
        mic.querySelector('[data-mini-voice-label]').textContent = active ? bt('dictationStop', 'Завершить диктовку') : bt('dictate', 'Диктовать');
        recordBtn.setAttribute('aria-label', active ? bt('dictationStop', 'Завершить диктовку') : bt('dictateMessage', 'Диктовать сообщение'));
        status.textContent = active ? bt('dictation', 'Диктовка') : stateLines[companion.dataset.state];
        placeBubble();
    }, () => setOpen(true), text => {
        if (panel.hidden) showReaction(text, text ? 4200 : 0);
    });
    window.addEventListener('pagehide', () => cancelActiveVoice?.());

    function viewport() {
        const view = window.visualViewport;
        return view
            ? { left: view.offsetLeft, top: view.offsetTop, width: view.width, height: view.height }
            : { left: 0, top: 0, width: window.innerWidth, height: window.innerHeight };
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(value, max));
    }

    function petBounds() {
        const view = viewport();
        const left = view.left + margin;
        const top = view.top + margin;
        const x = Math.max(0, view.width - companion.offsetWidth - 2 * margin);
        const y = Math.max(0, view.height - companion.offsetHeight - 2 * margin);
        return { view, left, top, x, y };
    }

    function placeBubble() {
        const view = viewport();
        const rect = companion.getBoundingClientRect();
        const center = rect.left + rect.width / 2;
        headCenter = { x: center, y: rect.top + toggleBtn.offsetHeight * .36 };
        const available = Math.max(1, view.width - 2 * margin);
        const width = Math.min(panelWidth, available);
        const editorWidth = Math.min(330, available);
        const align = size => clamp(center - size / 2, view.left + margin, view.left + view.width - margin - size);
        const editorLeft = align(editorWidth);
        companion.style.setProperty('--mini-editor-width', editorWidth + 'px');
        companion.style.setProperty('--mini-editor-left', (editorLeft - rect.left) + 'px');
        const left = align(width);
        const above = rect.top - view.top - margin - gap;
        const below = view.top + view.height - margin - rect.bottom - gap;
        const placeAbove = above >= 512 || above >= below;
        companion.dataset.side = placeAbove ? 'above' : 'below';
        companion.style.setProperty('--pet-panel-width', width + 'px');
        companion.style.setProperty('--pet-panel-left', (left - editorLeft) + 'px');
        companion.style.setProperty('--pet-panel-room', Math.max(0, placeAbove ? above : below) + 'px');
        const reactionLeft = clamp(rect.left, view.left + margin, view.left + view.width - margin - 120);
        companion.style.setProperty('--pet-reaction-left', (reactionLeft - rect.left) + 'px');
    }

    function placePet() {
        const bounds = petBounds();
        companion.style.left = bounds.left + bounds.x * position.x + 'px';
        companion.style.top = bounds.top + bounds.y * position.y + 'px';
        placeBubble();
    }

    function movePet(left, top) {
        const bounds = petBounds();
        position = {
            x: bounds.x ? clamp((left - bounds.left) / bounds.x, 0, 1) : 0,
            y: bounds.y ? clamp((top - bounds.top) / bounds.y, 0, 1) : 0,
        };
        placePet();
    }

    try {
        const destination = JSON.parse(sessionStorage.getItem(guideKey));
        sessionStorage.removeItem(guideKey);
        if (destination?.path === location.pathname+location.search && destination.until >= Date.now()) {
            requestAnimationFrame(() => {
                const target = document.querySelector('main h1, main .page-title') || document.querySelector('main h2');
                if (!target) return;
                target.scrollIntoView({block:'nearest', behavior:'instant'});
                const rect = target.getBoundingClientRect();
                companion.setAttribute('data-guiding','');
                movePet(rect.left > 100 ? rect.left-companion.offsetWidth-8 : rect.right+8, rect.top);
                target.classList.add('bumi-target');
                showReaction(bt('rightHere', 'Вот здесь'), 3500);
                setTimeout(() => {
                    companion.removeAttribute('data-guiding');
                    target.classList.remove('bumi-target');
                }, 3500);
            });
        }
    } catch { /* Session storage is optional. */ }

    function savePosition() {
        try { localStorage.setItem(positionKey, JSON.stringify(position)); } catch { /* private mode */ }
    }

    try {
        const saved = JSON.parse(localStorage.getItem(positionKey));
        if (Number.isFinite(saved?.x) && Number.isFinite(saved?.y)) {
            position = { x: clamp(saved.x, 0, 1), y: clamp(saved.y, 0, 1) };
        }
    } catch { /* invalid or unavailable storage */ }

    status.textContent = stateLines.idle;
    placePet();
    new ResizeObserver(placePet).observe(companion);
    window.addEventListener('resize', placePet, { passive: true });
    window.visualViewport?.addEventListener('resize', placePet, { passive: true });
    window.visualViewport?.addEventListener('scroll', () => {
        if (window.visualViewport.scale !== 1 || window.visualViewport.offsetTop || window.visualViewport.offsetLeft) {
            placePet();
        }
    }, { passive: true });

    function showReaction(text, duration = 1800) {
        clearTimeout(reactionTimer);
        reaction.textContent = text;
        if (duration) reactionTimer = setTimeout(() => { reaction.textContent = ''; }, duration);
    }

    function showAssistantState(state) {
        companion.dataset.state = state;
        status.textContent = stateLines[state];
        const reason = { idle: 'ready', thinking: 'thinking', ready: 'ai_ready', error: 'ai_error' }[state];
        if (reason) window.CorpMsBumi?.react(reason);
    }

    function setOpen(open, restoreFocus = false) {
        if (!open) {
            cancelVoice();
            form.querySelector('[data-ai-voice-status]').hidden = true;
        }
        panel.hidden = !open;
        dock.hidden = open;
        companion.toggleAttribute('data-open', open);
        toggleBtn.setAttribute('aria-expanded', String(open));
        writeBtn.setAttribute('aria-expanded', String(open));
        placePet();
        if (open) {
            if (!greeted && greeting) {
                greeted = true;
                addMessage('ai', '<div class="ai-markdown">' + formatText(greeting) + '</div>');
            }
            scrollDown();
            input.focus({ preventScroll: true });
        } else if (restoreFocus) {
            writeBtn.focus({ preventScroll: true });
        }
    }

    function look(x, y) {
        if (!motion.matches) return;
        const horizontal = clamp(x, -1, 1) * (companion.dataset.facing === 'left' ? -1 : 1);
        const vertical = clamp(y, -1, 1);
        companion.style.setProperty('--mini-turn-x', horizontal * 10 + 'px');
        companion.style.setProperty('--mini-yaw', horizontal * 5 + 'deg');
        companion.style.setProperty('--mini-tilt', horizontal * 7 + 'deg');
        companion.style.setProperty('--mini-eye-x', horizontal * 14 + 'px');
        companion.style.setProperty('--mini-eye-y', vertical * 8 + 'px');
    }

    let gazeFrame = 0;
    let pointer;
    document.addEventListener('pointermove', event => {
        if (drag || event.pointerType !== 'mouse' || !motion.matches || overlayIsOpen()) return;
        pointer = { x: event.clientX, y: event.clientY };
        if (gazeFrame) return;
        gazeFrame = requestAnimationFrame(() => {
            gazeFrame = 0;
            if (!drag) look((pointer.x - headCenter.x) / 145, (pointer.y - headCenter.y) / 115);
        });
    }, { passive: true });

    writeBtn.addEventListener('click', () => setOpen(true));
    recordBtn.addEventListener('click', () => mic.click());
    mic?.addEventListener('click', () => {
        if (!panel.hidden) input.focus();
    });

    // Инструменты роли подставляют осмысленный запрос и отправляют его.
    roleTools.forEach(button => button.addEventListener('click', () => {
        if (input.readOnly) return;
        const prompt = button.dataset.bumiToolPrompt || '';
        if (prompt.length < 3) return;
        const draft = input.value;
        input.value = prompt;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (modeField && button.dataset.bumiToolMode) modeField.value = button.dataset.bumiToolMode;
        if (!panel.hidden) form.requestSubmit();
        input.value = draft;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }));

    toggleBtn.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) return;
        ignoreClick = false;
        clearTimeout(landingTimer);
        companion.removeAttribute('data-landing');
        // Используем базовые left/top, а не rect: во время генерации Bumi
        // может быть смещён CSS-анимацией по странице.
        drag = { id: event.pointerId, x: event.clientX, y: event.clientY, lastX: event.clientX, lastY: event.clientY, left: companion.offsetLeft, top: companion.offsetTop, moved: false };
        toggleBtn.setPointerCapture(event.pointerId);
    });
    toggleBtn.addEventListener('pointermove', event => {
        if (!drag || drag.id !== event.pointerId) return;
        const x = event.clientX - drag.x;
        const y = event.clientY - drag.y;
        if (!drag.moved && Math.hypot(x, y) < 6) return;
        if (!drag.moved) {
            drag.moved = true;
            companion.setAttribute('data-dragging', '');
            showReaction('');
        }
        const dx = event.clientX - drag.lastX;
        const dy = event.clientY - drag.lastY;
        if (Math.abs(dx) >= 1) companion.dataset.facing = dx < 0 ? 'left' : 'right';
        if (dx || dy) look(dx / 6, dy / 8);
        drag.lastX = event.clientX;
        drag.lastY = event.clientY;
        movePet(drag.left + x, drag.top + y);
    });

    function finishDrag(event) {
        if (!drag || drag.id !== event.pointerId) return;
        companion.removeAttribute('data-dragging');
        if (drag.moved) {
            ignoreClick = true;
            savePosition();
            look(0, 0);
            if (event.type === 'pointerup') {
                companion.setAttribute('data-landing', '');
                landingTimer = setTimeout(() => companion.removeAttribute('data-landing'), 540);
            }
        }
        drag = null;
    }

    toggleBtn.addEventListener('pointerup', finishDrag);
    toggleBtn.addEventListener('pointercancel', finishDrag);
    toggleBtn.addEventListener('lostpointercapture', finishDrag);
    toggleBtn.addEventListener('click', event => {
        if (ignoreClick && event.detail > 0) return;
        setOpen(panel.hidden, true);
    });
    toggleBtn.addEventListener('keydown', event => {
        const shifts = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        if (!Object.hasOwn(shifts, event.key)) return;
        event.preventDefault();
        const [dx, dy] = shifts[event.key];
        movePet(companion.offsetLeft + dx * 20, companion.offsetTop + dy * 20);
        savePosition();
        showReaction(bt('here', 'Я здесь!'));
    });

    companion.querySelector('[data-ai-close]').addEventListener('click', () => setOpen(false, true));
    document.addEventListener('pointerdown', event => {
        if (!panel.hidden && !companion.contains(event.target)) setOpen(false);
    });
    companion.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.stopPropagation();
        if (!panel.hidden) setOpen(false, true);
        else if (companion.hasAttribute('data-listening')) cancelVoice();
        else if (event.target === toggleBtn) {
            position = { x: 1, y: 1 };
            placePet();
            savePosition();
            showReaction(bt('welcomeBack', 'Вернулся!'));
        }
    });
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

        var svg = ['<svg class="ai-chart" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + escapeHtml(block.title || bt('diagram', 'Диаграмма')) + '">'];
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

    /* D6: текстовый блок (черновики документов) — plain text с сохранением переносов */
    function renderText(block) {
        var content = String(block.content || '');
        if (!content) { return null; }
        return '<pre class="ai-text">' + escapeHtml(content) + '</pre>';
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
            try {
                if (new URL(href, window.location.href).origin !== window.location.origin) continue;
            } catch (error) {
                continue;
            }
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
            else if (block.type === 'text') { body = renderText(block); }
            else if (block.type === 'actions') { body = renderActions(block); }
            if (!body) { continue; }

            var title = block.title ? '<div class="ai-block__title">' + escapeHtml(String(block.title)) + '</div>' : '';
            out += '<div class="ai-block">' + title + body + '</div>';
        }
        return out;
    }


    function addMessage(side, html, isError = false, reference = null) {
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ai-msg--' + side + (isError ? ' ai-msg--error' : '');
        if (side === 'ai') {
            const avatar = petSprite.cloneNode(true);
            if (isError) avatar.dataset.bumiEmotion = 'thinking';
            wrap.append(avatar);
        }
        const bubble = document.createElement('div');
        bubble.className = 'ai-msg__bubble';
        bubble.innerHTML = html;
        wrap.append(bubble);
        placeMessage(messages, wrap, reference);
        if (side === 'ai' && !isError) guideReply(bubble);
        placeBubble();
        scrollDown();
    }

    let inFlight = false;

    function setBusy(busy) {
        inFlight = busy;
        input.readOnly = busy;
        roleTools.forEach(button => { button.disabled = busy; });
        sendBtn.disabled = busy || input.value.trim().length < 3;
        recordBtn.disabled = busy;
        if (mic) mic.disabled = busy;
        form.classList.toggle('is-busy', busy);
        messages.setAttribute('aria-busy', String(busy));
    }

    function responseError(response, data) {
        if (response.status === 422) {
            const validation = Object.values(data.errors || {})[0];
            return validation?.[0] || bt('checkQuestionLength', 'Проверьте длину вопроса.');
        }
        if (response.status === 401 || response.status === 419) return bt('sessionExpired', 'Сессия истекла. Обновите страницу и войдите снова.');
        if (response.status === 403) return bt('accessDenied', 'У вас нет доступа к этому действию.');
        if (response.status === 429) return bt('tooManyRequests', 'Слишком много запросов. Попробуйте чуть позже.');
        return bt('assistantUnavailable', 'Ассистент пока недоступен. Попробуйте ещё раз.');
    }

    async function send(question, fromInput = false) {
        question = String(question || '').trim();
        var maxLen = Number(input.getAttribute('maxlength') || (form.dataset.biMode ? 2000 : 500));
        if (question.length < 3 || question.length > maxLen || inFlight) return;
        const token = form.querySelector('input[name="_token"]').value;
        cancelVoice();
        thread.hidden = false;
        input.placeholder = bt('messagePlaceholder', 'Сообщение для Bumi');
        addMessage('me', formatText(question));
        if (fromInput) input.value = '';
        setBusy(true);
        showAssistantState('thinking');
        const pending = thinkingBubble(messages);
        placeBubble();
        scrollDown();

        let success = false;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    question,
                    ...(lessonField && lessonField.value ? { lesson_id: Number(lessonField.value) } : {}),
                    ...(modeField && modeField.value ? { mode: modeField.value } : {}),
                }),
            });
            const data = (await response.json().catch(() => ({}))) || {};
            if (!response.ok) {
                addMessage('ai', formatText(responseError(response, data)), true, pending);
                return;
            }

            const reply = typeof data.reply === 'string' ? data.reply.trim() : '';
            success = data.ok === true && Boolean(reply);
            const blocks = Array.isArray(data.blocks) ? data.blocks : [];
            const replyHtml = reply && typeof data.reply_html === 'string'
                ? data.reply_html
                : formatText(reply || bt('noReply', 'Bumi пока не смог ответить. Попробуйте ещё раз.'));
            addMessage('ai', '<div class="ai-markdown">' + replyHtml + '</div>' + (data.blocks_html ?? renderBlocks(blocks)), !success, pending);
            if (success) {
                if (data.reason) window.CorpMsBumi?.react(data.reason);
                const canSpeak = document.body.dataset.bumiConversation === '1'
                    && document.body.dataset.bumiAutoSpeak !== '0'
                    && typeof window.CorpMsBumiVoice?.speak === 'function';
                if (canSpeak) void window.CorpMsBumiVoice.speak(data.speech || reply);
            }
        } catch (error) {
            addMessage('ai', formatText(bt('connectionLost', 'Связь с сервером оборвалась. Попробуйте ещё раз.')), true, pending);
        } finally {
            retire(pending);
            if (!success && fromInput && !input.value) input.value = question;
            setBusy(false);
            showAssistantState(success ? 'ready' : 'error');
            if (!panel.hidden && form.contains(document.activeElement)) input.focus();
        }
    }

    input.addEventListener('input', () => {
        sendBtn.disabled = inFlight || input.value.trim().length < 3;
    });

    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', event => {
        event.preventDefault();
        send(input.value, true);
    });


})();
