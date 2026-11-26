(() => {
    'use strict';

    const host = document.querySelector('[data-ai-providers]');
    if (!host) return;

    const template = host.querySelector('[data-ai-provider-template]');
    const list = host.querySelector('[data-ai-provider-list]');
    const add = host.querySelector('[data-ai-provider-add]');
    if (!template || !list || !add) return;

    let nextIndex = list.querySelectorAll('[data-ai-provider-row]').length;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function wireRemove(row) {
        row.querySelectorAll('[data-ai-provider-remove]').forEach((button) => {
            if (button.dataset.bumiWired === '1') return;
            button.dataset.bumiWired = '1';
            button.addEventListener('click', () => {
                if (reduceMotion.matches) {
                    row.remove();
                    return;
                }
                row.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'ease-out' })
                    .onfinish = () => row.remove();
            });
        });
    }

    list.querySelectorAll('[data-ai-provider-row]').forEach(wireRemove);

    add.addEventListener('click', () => {
        const row = template.cloneNode(true);
        row.removeAttribute('hidden');
        row.removeAttribute('data-ai-provider-template');
        const index = nextIndex++;
        row.innerHTML = row.innerHTML.replaceAll('__INDEX__', String(index));
        list.append(row);
        row.querySelectorAll('input').forEach((input) => { input.disabled = false; });
        wireRemove(row);

        const radio = row.querySelector('input[type=radio]');
        if (radio) {
            radio.checked = true;
            radio.focus({ preventScroll: true });
        }
        if (!reduceMotion.matches) {
            row.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }],
                { duration: 200, easing: 'ease-out' });
        }
        window.CorpMsBumi?.react('channel_connected');
    });
})();
