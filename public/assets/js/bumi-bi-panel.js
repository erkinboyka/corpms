(() => {
    'use strict';

    // На BI-страницах чёрная панель Bumi Mini работает как форма генерации дашбордов.
    // Общий AI-обработчик перехватывается, чтобы отправка ушла в очередь BI.
    const companion = document.querySelector('[data-bi-mode]');
    if (!companion) return;

    const form = companion.querySelector('#ai-form');
    const input = companion.querySelector('#ai-input');
    const hiddenInstruction = form?.querySelector('[name="instruction"]');
    if (!form || !input || !hiddenInstruction) return;

    document.addEventListener('submit', event => {
        if (event.target !== form) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const text = input.value.trim();
        if (text.length < 3 || form.dataset.bmSending === '1') return;

        form.dataset.bmSending = '1';
        hiddenInstruction.value = text;
        input.readOnly = true;
        form.submit();
    }, true);

    document.querySelector('[data-bi-generate]')?.addEventListener('click', () => {
        companion.querySelector('[data-ai-write]')?.click();
    });
})();
