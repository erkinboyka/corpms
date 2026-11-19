(function () {
    'use strict';

    const form = document.querySelector('[data-entry-form]');
    if (!form) return;

    const mode = form.elements.entry_mode;
    const simple = form.querySelector('#ae-simple');
    const advanced = form.querySelector('#ae-advanced');
    const toggle = form.querySelector('[data-entry-mode]');
    toggle.hidden = false;

    toggle.addEventListener('click', () => {
        const showAdvanced = mode.value === 'simple';
        mode.value = showAdvanced ? 'advanced' : 'simple';
        simple.hidden = simple.disabled = showAdvanced;
        advanced.hidden = advanced.disabled = !showAdvanced;
        toggle.textContent = showAdvanced ? 'Обычная проводка' : 'Нужно больше строк?';
        toggle.setAttribute('aria-expanded', String(showAdvanced));
    });
})();
