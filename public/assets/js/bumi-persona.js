/* ============================================================================
   BUMI PERSONA — единый характер ассистента во всех интерфейсах.
   ----------------------------------------------------------------------------
   Настроение меняется только по причине: flash-сообщение, ошибка формы,
   действие пользователя, состояние сети, время ожидания. Фразы выбираются
   по счётчику причины (не по random), поэтому повторение предсказуемо и никогда
   не выглядит как «дежурный случайный мультик».

   Публичный API:
     window.CorpMsBumi.react('success')            -> {mood, line, reason}
     window.CorpMsBumi.phase('thinking', 'ai')     -> принудительное состояние
     window.CorpMsBumi.set(mood, line, reason)     -> прямое состояние
     window.CorpMsBumi.reset()                     -> вернуть серверное состояние
   ============================================================================ */
(() => {
    'use strict';

    if (window.CorpMsBumi) return;

    // Сервер отдаёт уже переведённые реплики через partials.bumi-i18n.
    // Без словаря остаются исходные русские фразы — так страница не ломается.
    const bumiI18n = (() => {
        try {
            const node = document.getElementById('bumi-i18n');
            return node ? JSON.parse(node.textContent) : null;
        } catch (error) {
            return null;
        }
    })();
    const translateLine = (line) => (bumiI18n?.lines && Object.prototype.hasOwnProperty.call(bumiI18n.lines, line))
        ? bumiI18n.lines[line]
        : line;
    const uiText = (key, fallback) => (bumiI18n?.ui && typeof bumiI18n.ui[key] === 'string')
        ? bumiI18n.ui[key]
        : fallback;

    const moods = {
        happy: 'радостный',
        sleepy: 'сонный',
        sluggish: 'вялый',
        sad: 'грустный',
        angry: 'злой',
        thinking: 'задумчивый',
        surprised: 'удивлённый',
        wink: 'подмигивает',
        curious: 'любопытный',
        proud: 'гордый',
    };

    // Каждая причина — отдельный набор реплик. Варианты с разными настроениями
    // задаются явно: Bumi не «веселится просто так» и не «злится без дела».
    const reactions = {
        ready: { mood: 'happy', lines: [
            'Я на связи. Скажи, что нужно — разберусь.',
            'Дежурю. Всё под контролем. Почти.',
            'На месте. Можешь начинать.',
        ] },
        curious: { mood: 'curious', lines: [
            'Интересно. Давай посмотрим вместе.',
            'Я прислушался. Что ты задумал?',
        ] },
        success: { mood: 'proud', lines: [
            'Готово. Я всё зафиксировал — можешь не перепроверять.',
            'Записал. Даже в журнале оставил след.',
            'Сделано. Я слегка доволен собой.',
        ] },
        saved: { mood: 'proud', lines: [
            'Сохранил. Теперь это часть системы, а не просто идея.',
            'В базе. Если что — найду быстрее, чем ты забудешь.',
        ] },
        validation: { mood: 'sad', lines: [
            'Стоп. Одно поле просит внимания — я уже подсветил.',
            'Не пропущу пустую строчку. Давай заполним её вместе.',
            'Тут что-то не заполнено. Я подожду.',
        ] },
        error: { mood: 'sad', lines: [
            'Ой. Кажется, что-то пошло не так. Я рядом — попробуем ещё.',
            'Не вышло. Но я не из тех, кто сдаётся после первой попытки.',
            'Сбой. У меня тоже бывают не лучшие дни.',
        ] },
        denied: { mood: 'angry', lines: [
            'Не пускают — значит, так надо. Пойдём обходным путём.',
            'Права не дали. Мне не нравится, но я уважаю правила.',
            'Доступ закрыт. Я не взломщик, я Bumi.',
        ] },
        warning: { mood: 'sluggish', lines: [
            'Принял. Но тут есть нюанс — давай глянем ещё раз.',
            'Не спешу радоваться: кажется, что-то ещё надо уточнить.',
        ] },
        chat: { mood: 'thinking', lines: [
            'Я в рабочем чате. Дай задачу — разберусь по фактам.',
            'Спроси что угодно по компании — проверю по данным, а не по догадкам.',
            'Я на связи. Лучше один точный ответ, чем три красивых.',
        ] },
        settings: { mood: 'curious', lines: [
            'Здесь меня настраивают. Осторожно: я записываю всё.',
            'Меняют контуры — я смотрю, что именно. Любопытно.',
            'Тут мои ручки и предохранители. Трогай аккуратно.',
        ] },
        thinking: { mood: 'thinking', lines: [
            'Секунду. У меня в голове уже три версии — выбираю точную.',
            'Считаю. Без красивого вранья.',
            'Думаю. Это у меня занимает целых два миллисекунды.',
        ] },
        ai_ready: { mood: 'proud', lines: [
            'Нашёл. Проверяю, чтобы не выдать красивую глупость.',
            'Ответ готов. Сверял с фактами, а не с настроением.',
        ] },
        ai_error: { mood: 'sad', lines: [
            'Связь дрогнула. Я не сдаюсь — повторим?',
            'Сервер молчит. Подожди секунду, я постучусь ещё.',
        ] },
        retry: { mood: 'thinking', lines: [
            'Ещё раз. Второй подход обычно лучше первого.',
            'Повторяю. Я упрямый — в хорошем смысле.',
        ] },
        offline: { mood: 'sluggish', lines: [
            'Интернет ушёл в отпуск. Я пока дежурю.',
            'Сети нет. Ничего, я умею ждать.',
        ] },
        online: { mood: 'happy', lines: [
            'Сеть вернулась. Я снова в строю.',
            'Вернулись? Я как раз точил контуры.',
        ] },
        idle: { mood: 'sleepy', lines: [
            'Ззз… Я на паузе. Толкни меня, если нужно.',
            'Пока тихо. Даже робот иногда дремлет.',
        ] },
        return: { mood: 'wink', lines: [
            'Ты вернулся. Пока тебя не было, я ничего не сломал.',
            'С возвращением. Я здесь, как будто и не уходил.',
        ] },
        poke: { variants: [
            { mood: 'wink', line: 'Да, это я. Настоящий.' },
            { mood: 'curious', line: 'Ты меня позвал? Я всегда на связи.' },
            { mood: 'sleepy', line: 'Ззз… а, это ты. Я просто думал.' },
            { mood: 'proud', line: 'Не дёргай — я дружелюбный, но гордый.' },
            { mood: 'surprised', line: 'Ой! Я не ожидал, что меня нажмут.' },
        ] },
        auth: { mood: 'wink', lines: [
            'С возвращением. Давай быстренько по делам.',
            'Пароль введён — я уже открыл тебе рабочее место.',
            'Вход разрешён. Миссия выполнима.',
        ] },
        after_hours: { mood: 'sleepy', lines: [
            'Ззз… Часы поздние. Давай по делу — и спать.',
            'Уже ночь. Я на связи, но с подушкой.',
        ] },
        morning: { mood: 'sluggish', lines: [
            'Кофе ещё не подействовал? У меня тоже. Начнём с малого.',
            'Рановато. Но я уже включил контуры.',
        ] },
        agent_paused: { mood: 'sleepy', lines: [
            'Я на паузе. Включи — вернусь с идеями.',
            'Пауза. Дремлю, но одно ухо настороже.',
        ] },
        agent_active: { mood: 'happy', lines: [
            'Я в сети и слегка доволен собой.',
            'Включили? Отлично, я был готов ещё вчера.',
        ] },
        channel_connected: { mood: 'surprised', lines: [
            'Канал подключён. Теперь клиенты найдут меня и без сайта.',
            'О, новый канал! Я уже разложил голосовые контуры.',
        ] },
        knowledge_saved: { mood: 'wink', lines: [
            'Запомнил. Если забуду — значит, ты меня перепрошил.',
            'В базе знаний. Спроси меня об этом через минуту.',
        ] },
        queued: { mood: 'sluggish', lines: [
            'В очереди. Даже роботы иногда ждут своей очереди.',
            'Поставил в очередь. Иду пить кофе.',
        ] },
        bi: { mood: 'thinking', lines: [
            'Витрина на месте. Скажи, что пересчитать — сделаю тихо и быстро.',
            'Смотрю на метрики. Графики пока молчат, но я их расслушаю.',
        ] },
        bi_generate: { mood: 'thinking', lines: [
            'Собираю дашборд. Будет красиво и по делу.',
            'Черчу витрину. Бумаги уже в охапке.',
        ] },
        bi_ready: { mood: 'proud', lines: [
            'Готово. Я даже держу пари, что графики понравятся.',
            'Дашборд собран. Проверять можешь, но я уже проверил.',
        ] },
        bi_error: { mood: 'angry', lines: [
            'Что-то пошло не так. Я не виню кота — виню настройки.',
            'Генерация сорвалась. Дай мне ещё попытку, я злюсь по делу.',
        ] },
        sign: { mood: 'proud', lines: [
            'Подпись легла. Документ теперь как закалённая сталь.',
            'Подписано. Изменять не буду — я уважаю свою работу.',
        ] },
        copy: { mood: 'wink', lines: [
            'Скопировано. Можешь вставлять с гордостью.',
            'Уже в буфере. Я не подглядывал, честно.',
        ] },
        attendance: { mood: 'happy', lines: [
            'Отметка есть. День засчитан честно.',
            'Присутствие зафиксировал. Удалёнка — тоже работа, я в курсе.',
        ] },
        delete: { mood: 'sad', lines: [
            'Убрал. Без жалости, но по делу.',
            'Запись удалена. Я помню её... в смысле, не помню.',
        ] },
        undo: { mood: 'curious', lines: [
            'Отменил. Как будто и не было — но я помню.',
            'Вернул всё на место. Люблю откаты.',
        ] },
        search: { mood: 'curious', lines: [
            'Ищу. Если найду — сразу покажу.',
            'Прочёсываю данные. Обычно что-то да находится.',
        ] },
        filter: { mood: 'curious', lines: [
            'Сузил выборку. Теперь видно главное.',
            'Фильтр применён. Шум ушёл.',
        ] },
        tutor: { mood: 'curious', lines: [
            'Я рядом с уроком. Спроси — объясню по шагам.',
            'Давай разберём тему. Сначала подсказка, потом ответ.',
        ] },
        tutor_explain: { mood: 'happy', lines: [
            'Собрал объяснение по материалу урока. Проверь, понятно ли.',
            'Разложил тему на шаги. Один вопрос напоследок — и проверим.',
        ] },
        tutor_hint: { mood: 'thinking', lines: [
            'Не выдам ответ сразу. Дам подсказку — ты додумай.',
            'Сначала наводящий вопрос. Так запоминается лучше.',
        ] },
        tutor_check: { mood: 'proud', lines: [
            'Проверяю понимание. Ответь своими словами — я сравню.',
            'Давай тест на понимание. Без подглядывания в конспект.',
        ] },
        listening: { mood: 'curious', lines: [
            'Слушаю. Говори спокойно, я записываю мысль.',
            'Микрофон включён. Жду твою реплику.',
        ] },
        speaking: { mood: 'wink', lines: [
            'Говорю — и рот работает, и текст на месте.',
            'Озвучиваю. Если что — всегда можно прочитать.',
        ] },
    };

    if (bumiI18n?.lines) {
        Object.values(reactions).forEach((preset) => {
            if (Array.isArray(preset.lines)) {
                preset.lines = preset.lines.map(translateLine);
            }
            if (Array.isArray(preset.variants)) {
                preset.variants = preset.variants.map((variant) => ({ ...variant, line: translateLine(variant.line) }));
            }
            if (typeof preset.line === 'string') {
                preset.line = translateLine(preset.line);
            }
        });
    }

    const body = document.body;
    const initial = {
        mood: body?.dataset.bumiMood || 'happy',
        line: body?.dataset.bumiLine || '',
        reason: body?.dataset.bumiReason || 'ready',
    };
    const seq = Object.create(null);
    const lastAt = Object.create(null);
    let current = { ...initial };

    // Сервер уже показал одну из фраз текущей причины. Продолжаем со следующей,
    // иначе первый клик по лицу дословно повторяет стартовую реплику.
    if (initial.line) {
        const preset = reactions[initial.reason];
        const list = preset?.variants?.map((variant) => variant.line)
            ?? preset?.lines
            ?? (preset?.line ? [preset.line] : []);
        const shownAt = list.indexOf(initial.line);
        if (shownAt >= 0) seq[initial.reason] = shownAt + 1;
    }

    let applying = false;
    let hideTimer = 0;
    let speechTimer = 0;
    let idleTimer = 0;
    let awayAt = 0;

    function nextIndex(key) {
        return (seq[key] = (seq[key] || 0) + 1) - 1;
    }

    function pick(list, key) {
        if (!list.length) return '';
        return list[nextIndex(key) % list.length];
    }

    function resolve(reason, override = {}) {
        const preset = reactions[reason] || reactions.ready;
        if (preset.variants) {
            const variant = pick(preset.variants, reason);
            return { mood: override.mood || variant.mood, line: override.line || variant.line };
        }
        const line = override.line || pick(preset.lines || [preset.line || ''], reason);
        return { mood: override.mood || preset.mood, line };
    }

    function showReaction(line, reason) {
        if (!line) return;
        clearTimeout(hideTimer);
        document.querySelectorAll('[data-ai-companion]').forEach((pet) => {
            const bubble = pet.querySelector('[data-ai-reaction]');
            if (!bubble) return;
            bubble.textContent = line;
            bubble.dataset.bumiReason = reason;
        });
        hideTimer = window.setTimeout(() => {
            document.querySelectorAll('[data-ai-reaction]').forEach((bubble) => {
                bubble.textContent = '';
                delete bubble.dataset.bumiReason;
            });
        }, 4800);
    }

    /** Отдельный пузырь рядом с лицом: само лицо остаётся только мимикой. */
    function showSpeech(line, reason, anchor) {
        if (!line) return;
        clearTimeout(speechTimer);
        let bubble = document.querySelector('.bumi-speech');
        if (!bubble) {
            bubble = document.createElement('div');
            bubble.className = 'bumi-speech';
            bubble.setAttribute('role', 'status');
            document.body.append(bubble);
        }
        bubble.textContent = line;
        bubble.dataset.bumiReason = reason;

        if (anchor && anchor.getBoundingClientRect) {
            const rect = anchor.getBoundingClientRect();
            const width = bubble.offsetWidth;
            const height = bubble.offsetHeight;
            bubble.style.left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.left + rect.width / 2 - width / 2)) + 'px';
            const above = rect.top - height - 12;
            bubble.style.top = (above > 12 ? above : Math.min(window.innerHeight - height - 12, rect.bottom + 12)) + 'px';
        } else {
            bubble.style.left = '12px';
            bubble.style.top = '12px';
            bubble.style.transform = '';
        }

        bubble.classList.remove('is-entering');
        void bubble.offsetWidth;
        bubble.classList.add('is-entering');
        speechTimer = window.setTimeout(() => bubble.remove(), 3800);
    }

    function set(mood, line, reason, source = 'persona') {
        const safeMood = moods[mood] ? mood : 'happy';
        const safeReason = reason || 'context';
        current = { mood: safeMood, line: line || '', reason: safeReason };

        if (body) {
            body.dataset.bumiMood = safeMood;
            body.dataset.bumiLine = current.line;
            body.dataset.bumiReason = safeReason;
        }

        document.querySelectorAll('[data-bumi-face]').forEach((face) => {
            face.dataset.bumiMood = safeMood;
            face.dataset.bumiEmotion = safeMood === 'surprised' ? 'surprise' : safeMood;
            face.dataset.bumiReason = safeReason;
            face.setAttribute('aria-label', `${uiText('bumiThoughtLabel', 'Узнать, что думает Bumi')}: ${bumiI18n?.moods?.[safeMood] || moods[safeMood]}`);
        });
        document.querySelectorAll('[data-ai-companion]').forEach((pet) => {
            pet.dataset.mood = safeMood;
        });
        document.querySelectorAll('[data-mascot-greeting]').forEach((mascot) => {
            mascot.dataset.mood = safeMood;
        });

        if (current.line) showReaction(current.line, safeReason);

        if (!applying) {
            applying = true;
            document.dispatchEvent(new CustomEvent('bumi:mood', {
                detail: { ...current, source },
            }));
            applying = false;
        }
        return current;
    }

    function react(reason, override = {}) {
        if (!reactions[reason] && !override.line && !override.mood) reason = 'ready';
        const now = Date.now();
        if (reason === current.reason && now - (lastAt[reason] || 0) < 1400) return current;
        lastAt[reason] = now;
        const resolved = resolve(reason, override);
        return set(resolved.mood, resolved.line, reason, override.source || 'react');
    }

    /**
     * Клик по лицу — не сброс на случайную эмоцию, а продолжение текущей
     * причины: следующая фраза из той же ситуации и подходящая мимика.
     */
    function cycleCurrent(anchor) {
        const reason = reactions[current.reason] ? current.reason : 'ready';
        const preset = reactions[reason];
        const index = nextIndex(reason);

        if (preset.variants?.length) {
            const variant = preset.variants[index % preset.variants.length];
            set(variant.mood, variant.line, reason, 'poke');
            showSpeech(variant.line, reason, anchor);
            return current;
        }

        const lines = preset.lines || [preset.line || ''];
        if (!lines.length) return react('poke', { source: 'poke' });
        const line = lines[index % lines.length];
        set(preset.mood, line, reason, 'poke');
        showSpeech(line, reason, anchor);
        return current;
    }

    function phase(mood, reason, line = '') {
        return set(mood, line, reason || 'context', 'phase');
    }

    function reset() {
        return set(initial.mood, initial.line, initial.reason, 'reset');
    }

    /**
     * Озвучить реплику: текст остаётся на экране, лицо показывает причину и
     * включает говорящий рот. Звук уходит в CorpMsBumiVoice, когда разговорный
     * режим включён в конфигурации.
     */
    function speak(line, reason = 'speaking', mood = null) {
        if (!line) return Promise.resolve(false);
        const anchor = document.querySelector('[data-bumi-face]');
        const nextMood = mood && moods[mood] ? mood : (reason === 'listening' ? 'curious' : current.mood);
        set(nextMood, line, reason, 'voice');
        showSpeech(line, reason, anchor);
        return window.CorpMsBumiVoice?.speak?.(line) || Promise.resolve(false);
    }

    window.CorpMsBumi = Object.freeze({ moods, reactions, set, react, cycleCurrent, phase, reset, speak, state: () => ({ ...current }) });

    /* ---------- причины, которые приходят из интерфейса ---------- */

    document.addEventListener('click', (event) => {
        const face = event.target.closest?.('[data-bumi-face]');
        if (face) cycleCurrent(face);
    }, true);

    document.addEventListener('submit', () => react('thinking'), true);
    document.addEventListener('invalid', () => react('validation'), true);

    window.addEventListener('error', () => react('error'));
    window.addEventListener('unhandledrejection', () => react('error'));
    window.addEventListener('offline', () => react('offline'));
    window.addEventListener('online', () => react('online'));

    // Уведомления уже показывают результат действия — Bumi просто озвучивает
    // ту же причину, не изобретая собственную «радость».
    const originalToast = window.crmToast;
    if (typeof originalToast === 'function') {
        window.crmToast = function bumiAwareToast(message, type, ...rest) {
            const tone = String(type || '');
            if (/success|check|download|restore|done|cloud-check/.test(tone)) react('success');
            else if (/error|alert|danger/.test(tone)) react(/alert-triangle|warning/.test(tone) ? 'warning' : 'error');
            else if (/info/.test(tone)) react('curious');
            return originalToast.call(this, message, type, ...rest);
        };
    }

    function scheduleIdle() {
        clearTimeout(idleTimer);
        idleTimer = window.setTimeout(() => react('idle'), 5 * 60 * 1000);
    }
    ['pointerdown', 'keydown', 'input', 'scroll'].forEach((type) => {
        window.addEventListener(type, scheduleIdle, { passive: true });
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            awayAt = Date.now();
            clearTimeout(idleTimer);
            return;
        }
        const away = Date.now() - (awayAt || Date.now());
        awayAt = 0;
        scheduleIdle();
        if (away > 10 * 60 * 1000) react('return');
        else if (away > 3 * 60 * 1000) react('sluggish');
    });
    scheduleIdle();

    // Серверное состояние остаётся стартовым: JS только продолжает историю.
    if (current.line) showReaction(current.line, current.reason);
})();
