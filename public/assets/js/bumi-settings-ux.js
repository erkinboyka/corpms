(() => {
    'use strict';

    // Bumi в чатах: действия меняют карточку на месте и дают честный откат.
    // Обычные HTML-формы остаются рабочим no-JS фолбэком.
    const FLASH_MS = 3200;
    const bumi = (reason) => window.CorpMsBumi?.react(reason);

    const csrf = (form) => {
        const token = form.querySelector('[name="_token"]')?.value;
        if (token) return token;

        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    };

    const postJson = async (url, form, values = {}) => {
        const body = new FormData(form);
        Object.entries(values).forEach(([key, value]) => body.set(key, String(value)));

        const response = await fetch(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(form),
            },
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || payload.ok === false) {
            throw new Error(payload?.message || 'Не удалось выполнить действие.');
        }

        return payload;
    };

    const toast = (message, type = 'info') => {
        if (typeof window.crmToast !== 'function') return null;

        return window.crmToast(message, type);
    };

    const toastWithUndo = (message, undo) => {
        const shown = toast(message, 'success');
        if (!shown?.el) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-ghost-light ms-2';
        button.textContent = 'Отменить';
        button.addEventListener('click', () => {
            undo();
            shown.close();
        });
        shown.el.querySelector('.crm-toast__body')?.appendChild(button);
    };

    const highlight = (scope) => {
        if (!scope) return;
        scope.classList.add('is-changed');
        window.setTimeout(() => scope.classList.remove('is-changed'), FLASH_MS);
    };

    const counterNames = {
        active: 'agents-active',
        connected: 'channels-connected',
        qa_active: 'qa-active',
    };

    const setCounters = (counts) => {
        if (!counts || typeof counts !== 'object') return;
        Object.entries(counts).forEach(([key, value]) => {
            const name = counterNames[key] || key;
            document.querySelectorAll('[data-bumi-counter="' + name + '"]').forEach((node) => {
                node.textContent = String(value);
            });
        });
    };

    const applyEffects = (effects) => {
        if (!Array.isArray(effects)) return;

        effects.forEach((effect) => {
            if (!effect || typeof effect !== 'object') return;
            const id = String(effect.id ?? '');

            if (effect.kind === 'agent' && id) {
                document.querySelectorAll('[data-bumi-agent-status="' + id + '"]').forEach((node) => {
                    if (effect.label) node.textContent = effect.label;
                    if (effect.class) node.className = 'crm-chip ' + effect.class;
                });
            }

            if (effect.kind === 'channel' && id) {
                document.querySelectorAll('[data-bumi-channel-status="' + id + '"]').forEach((node) => {
                    if (effect.label) node.textContent = effect.label;
                    if (effect.class) node.className = 'crm-chip ' + effect.class;
                });
                document.querySelectorAll('[data-bumi-channel-badge="' + id + '"]').forEach((node) => {
                    node.hidden = !effect.connected;
                });
            }

            if (effect.kind === 'knowledge') {
                document.querySelectorAll('tr[data-bumi-qa-id="' + id + '"] [data-bumi-toggle-chip]').forEach((node) => {
                    if (effect.label) node.textContent = effect.label;
                    if (effect.class) node.className = 'crm-chip ' + effect.class;
                });
            }
        });
    };

    const knowledgeRow = (effect) => {
        const row = document.createElement('tr');
        row.setAttribute('data-bumi-qa', '');
        row.setAttribute('data-bumi-qa-id', String(effect.id));

        const title = document.createElement('td');
        const strong = document.createElement('div');
        strong.className = 'fw-semibold';
        strong.textContent = String(effect.title || effect.question || '—');
        title.appendChild(strong);
        if (effect.question && effect.title && effect.question !== effect.title) {
            const question = document.createElement('div');
            question.className = 'text-secondary small';
            question.textContent = String(effect.question);
            title.appendChild(question);
        }

        const answer = document.createElement('td');
        answer.className = 'text-break';
        answer.textContent = String(effect.answer || '');

        const statusCell = document.createElement('td');
        const chip = document.createElement('span');
        chip.className = 'crm-chip ' + (effect.enabled ? 'crm-chip--green' : 'crm-chip--grey');
        chip.setAttribute('data-bumi-toggle-chip', '');
        chip.textContent = effect.enabled
            ? (effect.label_enabled || 'В работе')
            : (effect.label_disabled || 'Выключено');
        statusCell.appendChild(chip);

        const actions = document.createElement('td');
        const group = document.createElement('div');
        group.className = 'btn-group btn-group-sm';
        const csrfValue = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const makeForm = (url, name, attrs) => {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = url;
            Object.entries(attrs).forEach(([key, value]) => form.setAttribute(key, value));
            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = csrfValue;
            form.appendChild(token);
            const button = document.createElement('button');
            button.type = 'submit';
            button.className = 'btn btn-ghost-secondary';
            button.innerHTML = name;
            form.appendChild(button);
            group.appendChild(form);
        };

        makeForm(effect.toggle_url || '#', '<i class="ti ti-eye-off"></i>', {
            'data-bumi-toggle': '',
            'data-bumi-toggle-kind': 'knowledge',
            'data-bumi-id': String(effect.id),
        });
        makeForm(effect.delete_url || '#', '<i class="ti ti-trash"></i>', {
            'data-confirm': effect.confirm_message || 'Удалить запись из базы знаний Bumi?',
            'data-confirm-danger': '1',
            'data-bumi-delete': '',
            'data-bumi-id': String(effect.id),
        });
        actions.appendChild(group);

        row.append(title, answer, statusCell, actions);
        return row;
    };

    const statusChip = (form) => {
        const scope = form.closest('[data-bumi-card], tr[data-bumi-qa-id], .crm-form__section');
        return scope?.querySelector('[data-bumi-toggle-chip]') || null;
    };

    const buttonMarkup = (kind, enabled) => {
        if (kind === 'knowledge') {
            return `<i class="ti ti-${enabled ? 'eye-off' : 'eye'}"></i>`;
        }
        if (kind === 'agent') {
            return `<i class="ti ti-${enabled ? 'player-pause' : 'player-play'} me-1"></i>${enabled ? 'Поставить на паузу' : 'Включить агента'}`;
        }

        return `<i class="ti ti-${enabled ? 'player-pause' : 'player-play'} me-1"></i>${enabled ? 'Отключить канал' : 'Подключить канал'}`;
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form?.matches?.('form[data-bumi-toggle]')) return;

        event.preventDefault();
        const button = form.querySelector('[data-bumi-toggle-button]');
        const hidden = form.querySelector('[name="enabled"]');
        const chip = statusChip(form);
        const original = {
            enabled: hidden?.value,
            html: button?.innerHTML,
            chipHtml: chip?.innerHTML,
            chipClass: chip?.className,
        };
        const requested = hidden?.value === '1';

        if (button) button.disabled = true;

        try {
            const data = await postJson(form.action, form, { enabled: requested ? 1 : 0 });
            if (hidden) hidden.value = data.enabled ? '0' : '1';
            if (button) button.innerHTML = buttonMarkup(form.dataset.bumiToggleKind, data.enabled);
            if (chip) {
                chip.textContent = data.label;
                chip.className = `crm-chip ${data.class}`;
            }
            applyEffects(data.effects);
            setCounters(data.counts);
            highlight(form.closest('[data-bumi-card], tr[data-bumi-qa-id], .crm-form__section'));
            const kind = form.dataset.bumiToggleKind;
            bumi(kind === 'agent'
                ? (data.enabled ? 'agent_active' : 'agent_paused')
                : (kind === 'channel' && data.enabled ? 'channel_connected' : (data.enabled ? 'knowledge_saved' : 'delete')));

            toastWithUndo(data.message, async () => {
                try {
                    const undone = await postJson(data.undo_url, form, { enabled: data.undo_enabled ? 1 : 0 });
                    applyEffects(undone.effects);
                    setCounters(undone.counts);
                    if (hidden) hidden.value = original.enabled;
                    if (button && original.html !== undefined) button.innerHTML = original.html;
                    if (chip) {
                        if (original.chipHtml !== undefined) chip.innerHTML = original.chipHtml;
                        if (original.chipClass !== undefined) chip.className = original.chipClass;
                    }
                    toast('Действие отменено', 'info');
                    bumi('undo');
                } catch (error) {
                    toast(error.message, 'error');
                    bumi('error');
                }
            });
        } catch (error) {
            if (button && original.html !== undefined) button.innerHTML = original.html;
            toast(error.message, 'error');
            bumi('error');
        } finally {
            if (button) button.disabled = false;
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form?.matches?.('form[data-bumi-retry]')) return;

        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        const originalHtml = button?.innerHTML;
        const scope = form.closest('[data-bumi-message-id]');

        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>В очереди…';
        }

        try {
            const data = await postJson(form.action, form);
            const chip = scope?.querySelector('[data-bumi-status]');
            if (chip) {
                chip.textContent = data.status_label;
                chip.className = `crm-chip ${data.status_class}`;
                chip.setAttribute('data-bumi-status', data.status);
            }
            applyEffects(data.effects);
            setCounters(data.counts);
            highlight(scope);
            toast(data.already_queued ? 'Ответ уже в очереди' : data.message, data.already_queued ? 'info' : 'success');
            bumi(data.already_queued ? 'queued' : 'knowledge_saved');
        } catch (error) {
            toast(error.message, 'error');
            bumi('error');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form?.matches?.('form[data-bumi-delete]')) return;

        event.preventDefault();
        const row = form.closest('tr[data-bumi-qa-id]');
        const id = String(form.dataset.bumiId || row?.getAttribute('data-bumi-qa-id') || '');
        if (!row || !id) return;

        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;

        try {
            const data = await postJson(form.action, form);
            row.remove();
            setCounters(data.counts);
            toastWithUndo(data.message, async () => {
                try {
                    const undone = await postJson(data.undo_url, form);
                    const effect = (undone.effects || []).find((item) => item?.kind === 'knowledge_restore');
                    if (effect) {
                        const restored = knowledgeRow(effect);
                        const tbody = document.querySelector('[data-bumi-qa-body]');
                        if (tbody) tbody.appendChild(restored);
                        highlight(restored);
                    }
                    setCounters(undone.counts);
                    toast(undone.message || 'Действие отменено', 'success');
                } catch (error) {
                    toast(error.message, 'error');
                }
            });
        } catch (error) {
            if (button) button.disabled = false;
            toast(error.message, 'error');
        }
    });

    const fieldValue = (textarea) => {
        const editor = window.tinymce && typeof window.tinymce.get === 'function'
            ? window.tinymce.get(textarea.id)
            : null;

        return editor ? editor.getContent({ format: 'text' }).trim() : textarea.value.trim();
    };

    const playgroundNode = (label, value, marker, source = '') => {
        const row = document.createElement('div');
        row.className = 'd-flex gap-3';
        row.setAttribute('data-bumi-playground-' + marker, '');

        const avatar = document.createElement('span');
        avatar.className = 'avatar avatar-sm';
        avatar.innerHTML = `<i class="ti ti-${marker === 'message' ? 'user' : 'robot'}"></i>`;

        const body = document.createElement('div');
        const caption = document.createElement('div');
        caption.className = 'small text-secondary d-flex flex-wrap align-items-center gap-2';
        caption.textContent = marker === 'message' ? 'Клиент' : 'Bumi';
        if (marker === 'reply' && source) {
            const chip = document.createElement('span');
            chip.className = 'crm-chip crm-chip--grey';
            chip.setAttribute('data-bumi-playground-source', '');
            chip.textContent = String(source);
            caption.appendChild(chip);
        }
        const text = document.createElement('div');
        text.className = 'text-break';
        text.textContent = value;

        body.append(caption, text);
        row.append(avatar, body);
        return row;
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form?.matches?.('form[data-bumi-playground-form]')) return;

        event.preventDefault();
        const textarea = form.querySelector('textarea[name="message"]');
        if (!textarea) return;

        const value = fieldValue(textarea);
        if (!value) return;

        const thread = document.getElementById('bumi-playground-thread');
        if (!thread) return;

        const button = form.querySelector('[type="submit"]');
        // data-submit-guard уже подменил кнопку спиннером в фазе capture.
        const originalHtml = button?.dataset.crmLabel || button?.innerHTML;
        const pending = playgroundNode('message', value, 'message');
        const typing = document.createElement('div');
        typing.className = 'd-flex gap-3 ai-msg is-typing';
        typing.setAttribute('role', 'status');
        typing.setAttribute('aria-live', 'polite');
        typing.textContent = 'Bumi отвечает…';

        if (!thread.querySelector('.card')) {
            const card = document.createElement('div');
            card.className = 'card';
            const head = document.createElement('div');
            head.className = 'card-header';
            head.innerHTML = '<h3 class="card-title">Ответ Bumi</h3>';
            const body = document.createElement('div');
            body.className = 'card-body d-flex flex-column gap-3';
            card.append(head, body);
            thread.appendChild(card);
        }

        const body = thread.querySelector('.card-body');
        body.append(pending, typing);

        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Проверяем…';
        }

        try {
            const data = await postJson(form.action, form, { message: value });
            pending.replaceWith(playgroundNode('message', data.message, 'message'));
            typing.replaceWith(playgroundNode('reply', data.reply, 'reply', data.source_label || ''));
            highlight(thread.querySelector('.card'));

            const editor = window.tinymce && typeof window.tinymce.get === 'function'
                ? window.tinymce.get(textarea.id)
                : null;
            if (editor) editor.setContent('');
            textarea.value = '';
            textarea.focus();
        } catch (error) {
            pending.remove();
            typing.remove();
            toast(error.message, 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml || '<i class="ti ti-send me-1"></i>Проверить';
            }
            delete form.dataset.crmSubmitting;
        }
    });
})();
