(() => {
    const mascot = document.querySelector('[data-mascot-greeting]');
    if (!mascot) return;

    const persona = window.CorpMsBumi;
    const trigger = mascot.querySelector('[data-mascot-react]');
    const bubble = mascot.querySelector('[data-mascot-speech]');
    const line = bubble.querySelector('[data-mascot-line]');
    const remainder = bubble.querySelector('[data-mascot-rest]');
    const status = mascot.querySelector('[data-mascot-status]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const letterDelay = 55;
    const afterPhraseDelay = 5200;
    let phraseTimer;
    let finishTyping;
    let bubbleExit;
    let frame = 0;
    let pointer;

    function resetGaze() {
        cancelAnimationFrame(frame);
        frame = 0;
        for (const name of ['--mascot-look-x', '--mascot-look-y']) {
            mascot.style.removeProperty(name);
        }
    }

    function updateGaze() {
        frame = 0;
        const bounds = trigger.getBoundingClientRect();
        const dx = (pointer.x - bounds.left - bounds.width * .53) / (bounds.width * .8);
        const dy = (pointer.y - bounds.top - bounds.height * .33) / (bounds.height * .7);
        const distance = Math.max(1, Math.hypot(dx, dy));
        mascot.style.setProperty('--mascot-look-x', dx / distance);
        mascot.style.setProperty('--mascot-look-y', dy / distance);
    }

    window.addEventListener('pointermove', event => {
        if (reducedMotion.matches || event.pointerType !== 'mouse') return;
        // Модалка размывает фон: лишняя перерисовка взгляда тормозит курсор.
        if (document.body.classList.contains('modal-open')) return;
        pointer = { x: event.clientX, y: event.clientY };
        if (!frame) frame = requestAnimationFrame(updateGaze);
    }, { passive: true });
    document.documentElement.addEventListener('pointerleave', resetGaze);
    window.addEventListener('blur', resetGaze);

    function hideBubble() {
        bubble.hidden = true;
        bubbleExit?.cancel();
        bubbleExit = null;
    }

    function hidePhrase() {
        finishTyping = null;
        if (reducedMotion.matches) {
            hideBubble();
            return;
        }

        const exit = bubble.animate([
            { opacity: 1, transform: 'translateY(-50%) scale(1)' },
            { opacity: 0, transform: 'translate(-9px, calc(-50% - 3px)) scale(.88)' },
        ], { duration: 260, easing: 'cubic-bezier(.4, 0, .7, 1)', fill: 'forwards' });
        bubbleExit = exit;
        exit.onfinish = () => {
            if (bubbleExit === exit) hideBubble();
        };
    }

    function showPhrase({ mood, text }, announce = false) {
        clearTimeout(phraseTimer);
        bubbleExit?.cancel();
        bubbleExit = null;
        const letters = Array.from(text || '');
        let visible = 0;
        if (mood) mascot.dataset.mood = mood;
        bubble.hidden = false;
        if (announce) status.textContent = text;

        function finish() {
            line.textContent = text;
            remainder.textContent = '';
            finishTyping = null;
            phraseTimer = setTimeout(hidePhrase, afterPhraseDelay);
        }

        function typeLetter() {
            visible++;
            if (visible === letters.length) {
                finish();
                return;
            }
            line.textContent = letters.slice(0, visible).join('');
            remainder.textContent = letters.slice(visible).join('');
            phraseTimer = setTimeout(typeLetter, letterDelay);
        }

        if (reducedMotion.matches || letters.length < 2) {
            line.textContent = text;
            remainder.textContent = '';
            finishTyping = null;
            phraseTimer = setTimeout(finish, Math.max(0, (letters.length - 1) * letterDelay));
        } else {
            finishTyping = () => {
                clearTimeout(phraseTimer);
                finish();
            };
            typeLetter();
        }
    }

    // Стартовая реплика приходит от сервера и уже имеет причину (время суток,
    // ошибку входа, успешный сброс пароля). Больше нет «случайных приветствий».
    const initialMood = mascot.dataset.mood || document.body.dataset.bumiMood || 'happy';
    const initialLine = line.textContent.trim() || document.body.dataset.bumiLine || 'Привет! Я Bumi';
    if (!document.hidden) showPhrase({ mood: initialMood, text: initialLine });

    trigger.addEventListener('click', () => {
        // react() возвращает {mood, line, reason}, а типописец ждёт {mood, text}.
        // source:'auth' не даёт общему слою показать реплику дважды: здесь её
        // показывает этот сценарий, с озвучиванием в status.
        const reaction = persona?.react('poke', { source: 'auth' });
        showPhrase(
            reaction
                ? { mood: reaction.mood, text: reaction.line }
                : { mood: 'curious', text: 'Ты меня позвал? Я на связи.' },
            true,
        );
    });

    // Форма входа, ошибки и сеть озвучиваются тем же persona-слоем, что и чат.
    document.addEventListener('bumi:mood', (event) => {
        const detail = event.detail || {};
        if (!detail.line || detail.source === 'auth') return;
        showPhrase({ mood: detail.mood, text: detail.line });
    });

    document.querySelector('form')?.addEventListener('submit', () => {
        persona?.react('auth');
    });

    reducedMotion.addEventListener('change', () => {
        resetGaze();
        if (reducedMotion.matches) {
            finishTyping?.();
            if (bubbleExit) hideBubble();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(phraseTimer);
            finishTyping = null;
            hideBubble();
        }
    });
})();
