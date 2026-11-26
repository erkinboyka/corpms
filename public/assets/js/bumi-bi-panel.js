(() => {
    'use strict';

    // На BI-страницах чёрная панель Bumi Mini работает как генератор дашбордов.
    // Команда /bi переключает её в режим правки текущего дашборда: вызовы
    // инструментов, откат и оценка ответа. Внешний вид панели не меняется.
    const companion = document.querySelector('[data-bi-mode]');
    if (!companion) return;

    const form = companion.querySelector('#ai-form');
    const input = companion.querySelector('#ai-input');
    const hiddenInstruction = form?.querySelector('[name="instruction"]');
    if (!form || !input || !hiddenInstruction) return;

    const chatUrl = companion.dataset.biChatUrl || '';
    const ratingUrl = companion.dataset.biRatingUrl || '';
    const csrf = form.querySelector('[name="_token"]')?.value || '';
    const messages = companion.querySelector('#ai-messages');

    const button = (label, className) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = className || 'btn btn-ghost btn-sm text-xs';
        element.textContent = label;
        return element;
    };

    function addMessage(text, controls, isError, side) {
        const thread = companion.querySelector('[data-mini-thread]');
        if (thread) thread.hidden = false;
        const panel = companion.querySelector('#ai-panel');
        if (panel) panel.hidden = false;

        const row = document.createElement('div');
        row.className = 'ai-msg ai-msg--' + (side || 'ai') + (isError ? ' ai-msg--error' : '');
        const bubble = document.createElement('div');
        bubble.className = 'ai-msg__bubble';

        const answer = document.createElement('div');
        answer.className = 'bm-assistant__answer ai-markdown';
        answer.textContent = text;
        bubble.append(answer);

        if (controls && messages) {
            const tools = document.createElement('div');
            tools.className = 'bi-chat-tools mt-2 d-flex flex-wrap gap-1 align-items-center';

            if (controls.undoable && controls.id && chatUrl) {
                const undo = button('Отменить', 'btn btn-ghost btn-sm text-xs');
                undo.addEventListener('click', async () => {
                    undo.disabled = true;
                    try {
                        const response = await fetch(chatUrl + '/' + controls.id + '/undo', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        });
                        const data = await response.json().catch(() => ({}));
                        undo.textContent = response.ok ? 'Отменено' : (data.message || 'Не удалось отменить');
                        if (response.ok) tools.append(refreshLink());
                    } catch (error) {
                        undo.disabled = false;
                        undo.textContent = 'Повторить';
                    }
                });
                tools.append(undo);
            }

            if (controls.changes) {
                tools.append(refreshLink());
            }

            if (ratingUrl && controls.id) {
                const stars = document.createElement('span');
                stars.className = 'bi-chat-rating d-inline-flex gap-1 align-items-center';
                const label = document.createElement('span');
                label.className = 'text-secondary small me-1';
                label.textContent = 'Оценить:';
                stars.append(label);
                const url = ratingUrl.replace('/0/rating', '/' + controls.id + '/rating');

                for (let mark = 1; mark <= 5; mark++) {
                    const star = button(String(mark), 'btn btn-ghost btn-sm text-xs');
                    star.addEventListener('click', async () => {
                        try {
                            await fetch(url, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrf,
                                },
                                body: JSON.stringify({ rating: mark }),
                            });
                            for (const item of stars.querySelectorAll('button')) item.disabled = true;
                            label.textContent = 'Спасибо!';
                        } catch (error) {
                            star.textContent = String(mark);
                        }
                    });
                    stars.append(star);
                }
                tools.append(stars);
            }

            bubble.append(tools);
        }

        row.append(bubble);
        messages?.append(row);
        if (messages) messages.scrollTop = messages.scrollHeight;
    }

    function refreshLink() {
        const link = document.createElement('a');
        link.href = location.href;
        link.className = 'btn btn-ghost btn-sm text-xs';
        link.textContent = 'Обновить дашборд';
        return link;
    }

    async function askBi(question) {
        if (!chatUrl) {
            addMessage('Инструменты BI недоступны на этой странице.', null, true);
            return;
        }

        addMessage(question, null, false, 'me');

        try {
            const response = await fetch(chatUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ message: question }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                addMessage(data.error || 'Bumi не смогла выполнить запрос.', null, true);
                return;
            }

            const effects = Array.isArray(data.effects) ? data.effects : [];
            addMessage(data.text || 'Готово.', {
                id: data.id,
                undoable: !!data.undoable,
                changes: effects.some(effect => effect && effect.ok),
            }, false);
        } catch (error) {
            addMessage('Не удалось связаться с Bumi.', null, true);
        }
    }

    document.addEventListener('submit', event => {
        if (event.target !== form) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const text = input.value.trim();
        if (text.length < 3 || form.dataset.bmSending === '1') return;

        const match = /^\/bi(?:\s|$)/i.exec(text);
        if (match) {
            const question = text.replace(/^\/bi\s*/i, '').trim();
            if (question.length < 3) {
                addMessage('После /bi уточните, что нужно изменить: виджет, метрику или фильтр.', null, true);
                return;
            }

            form.dataset.bmSending = '1';
            input.readOnly = true;
            input.value = '';
            askBi(question).finally(() => {
                form.dataset.bmSending = '0';
                input.readOnly = false;
                input.focus();
            });
            return;
        }

        // На странице готового дашборда обычная отправка создаёт НОВЫЙ дашборд,
        // а не меняет текущий. Предупреждаем до native submit().
        const onDashboardPage = /\/bi\/dashboards\/\d+\/?$/.test(location.pathname);
        if (onDashboardPage && !window.confirm('Bumi создаст новый дашборд. Текущий останется без изменений. Продолжить?')) {
            return;
        }

        form.dataset.bmSending = '1';
        hiddenInstruction.value = text;
        input.readOnly = true;
        form.submit();
    }, true);

    document.querySelector('[data-bi-generate]')?.addEventListener('click', () => {
        companion.querySelector('[data-ai-write]')?.click();
    });
})();
