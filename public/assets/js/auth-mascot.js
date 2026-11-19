(() => {
    const mascot = document.querySelector('[data-mascot-greeting]');
    if (!mascot) return;

    const trigger = mascot.querySelector('[data-mascot-react]');
    const bubble = mascot.querySelector('[data-mascot-speech]');
    const line = bubble.querySelector('[data-mascot-line]');
    const remainder = bubble.querySelector('[data-mascot-rest]');
    const status = mascot.querySelector('[data-mascot-status]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const letterDelay = 55;
    const afterPhraseDelay = 700;
    const greetings = [
        { mood: 'wink', text: 'Да пребудет с тобой Wi-Fi.' },
        { mood: 'curious', text: 'Шрек бы одобрил: болото без уведомлений.' },
        { mood: 'happy', text: 'Я как ВАЛЛ·И, только сортирую задачи.' },
        { mood: 'surprised', text: 'Синий экран? Нет, это просто моя морда.' },
        { mood: 'wink', text: 'Миссия выполнима. Начнём с почты?' },
    ];
    const reactions = [
        { mood: 'surprised', text: 'Ой! Чуть не ушёл в перезагрузку.' },
        { mood: 'happy', text: 'Бип! Это роботский смех.' },
        { mood: 'curious', text: 'Нео, красная кнопка тоже была просто кнопкой.' },
        { mood: 'wink', text: 'Ты нашёл пасхалку. Награда — подмигивание.' },
        { mood: 'happy', text: 'Бесконечность не обещаю. План на день — легко.' },
        { mood: 'curious', text: 'Если кликнуть ещё, квест сам себя сделает?' },
    ];
    let nextGreeting = 0;
    let nextReaction = 0;
    let phraseTimer;
    let greetingTimer;
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
        pointer = { x: event.clientX, y: event.clientY };
        if (!frame) frame = requestAnimationFrame(updateGaze);
    }, { passive: true });
    document.documentElement.addEventListener('pointerleave', resetGaze);
    window.addEventListener('blur', resetGaze);

    function hideBubble() {
        bubble.hidden = true;
        bubbleExit?.cancel();
        bubbleExit = null;
        delete mascot.dataset.mood;
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
        const letters = Array.from(text);
        let visible = 0;
        mascot.dataset.mood = mood;
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

        if (reducedMotion.matches) {
            line.textContent = text;
            remainder.textContent = '';
            finishTyping = null;
            phraseTimer = setTimeout(finish, (letters.length - 1) * letterDelay);
        } else {
            finishTyping = () => {
                clearTimeout(phraseTimer);
                finish();
            };
            typeLetter();
        }
    }

    function scheduleGreeting() {
        clearTimeout(greetingTimer);
        if (document.hidden) return;
        greetingTimer = setTimeout(() => {
            showPhrase(greetings[nextGreeting]);
            nextGreeting = (nextGreeting + 1) % greetings.length;
            scheduleGreeting();
        }, 10000 + Math.floor(Math.random() * 5001));
    }

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
        scheduleGreeting();
    });

    trigger.addEventListener('click', () => {
        showPhrase(reactions[nextReaction], true);
        nextReaction = (nextReaction + 1) % reactions.length;
        scheduleGreeting();
    });

    if (!document.hidden) showPhrase({ mood: 'happy', text: line.textContent });
    scheduleGreeting();
})();
