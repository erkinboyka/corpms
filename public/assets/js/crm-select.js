/* Плавное раскрытие select.form-select без замены формы.
   Оригинальный <select> остаётся в DOM, хранит значение и получает change —
   поэтому существующие onchange="this.form.submit()" и JS-читалки .value работают. */
(function () {
    'use strict';
    if (window.CrmSelect) return;

    var SKIP_IDS = ['nt-employees', 'nt-departments']; // ими управляет Select2
    var enhanced = new WeakSet();
    var openWrap = null;

    function labelOf(select) {
        var option = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        if (option && option.textContent.trim()) return option.textContent.trim();
        return select.getAttribute('data-placeholder') || select.getAttribute('placeholder') || '';
    }

    function closeAll() {
        if (!openWrap) return;
        var wrap = openWrap;
        openWrap = null;
        wrap.classList.remove('is-open');
        var trigger = wrap.querySelector('.crm-select__trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }

    function enhance(select) {
        if (!select || enhanced.has(select) || select.multiple) return;
        if (SKIP_IDS.indexOf(select.id) !== -1) return;
        if (select.hasAttribute('data-crm-phone-country') || select.hasAttribute('data-no-crm-select')) return;
        if (select.closest('.select2-container, .crm-select')) return;
        if (!select.matches('select.form-select')) return;

        enhanced.add(select);

        var wrap = document.createElement('div');
        wrap.className = 'crm-select';
        if (select.classList.contains('form-select-sm')) wrap.classList.add('crm-select--sm');
        if (select.classList.contains('form-select-lg')) wrap.classList.add('crm-select--lg');
        if (select.disabled) wrap.classList.add('is-disabled');
        Array.prototype.forEach.call(select.classList, function (cls) {
            if (cls !== 'form-select' && /^(?:w-|mw-|mt-|mb-|ms-|me-)/.test(cls)) wrap.classList.add(cls);
        });

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'crm-select__trigger';
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.disabled = select.disabled;
        // The native select is hidden from AT, so its <label> no longer names
        // the generated button. Provide an explicit accessible name instead.
        var associatedLabel = select.labels && select.labels.length ? select.labels[0].textContent.trim() : '';
        trigger.setAttribute('aria-label', select.getAttribute('aria-label') || associatedLabel
            || select.getAttribute('data-placeholder') || labelOf(select) || 'Выбрать значение');

        var value = document.createElement('span');
        value.className = 'crm-select__value';
        trigger.appendChild(value);

        var arrow = document.createElement('i');
        arrow.className = 'ti ti-chevron-down crm-select__arrow';
        arrow.setAttribute('aria-hidden', 'true');
        trigger.appendChild(arrow);

        var menu = document.createElement('div');
        menu.className = 'crm-select__menu';
        menu.setAttribute('role', 'listbox');

        wrap.appendChild(trigger);
        wrap.appendChild(menu);

        select.classList.add('crm-select__native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        function sync() {
            var text = labelOf(select);
            value.textContent = text;
            value.classList.toggle('is-placeholder', !text);
            Array.prototype.forEach.call(menu.querySelectorAll('.crm-select__option'), function (item) {
                item.setAttribute('aria-selected', String(item.dataset.value === select.value));
            });
        }

        function rebuild() {
            menu.textContent = '';
            Array.prototype.forEach.call(select.children, function (node) {
                if (node.tagName === 'OPTGROUP') {
                    if (node.label) {
                        var label = document.createElement('div');
                        label.className = 'crm-select__group';
                        label.textContent = node.label;
                        menu.appendChild(label);
                    }
                    Array.prototype.forEach.call(node.children, addOption);
                } else if (node.tagName === 'OPTION') {
                    addOption(node);
                }
            });
            sync();
        }

        function addOption(option) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'crm-select__option';
            item.setAttribute('role', 'option');
            item.dataset.value = option.value;
            item.textContent = option.textContent.trim();
            item.disabled = option.disabled;
            item.addEventListener('click', function () {
                if (option.disabled) return;
                select.value = option.value;
                wrap.classList.remove('is-invalid');
                sync();
                closeAll();
                trigger.focus({ preventScroll: true });
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            menu.appendChild(item);
        }

        function isOpen() { return wrap.classList.contains('is-open'); }

        function open() {
            if (trigger.disabled || isOpen()) return;
            closeAll();
            openWrap = wrap;
            wrap.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            var active = menu.querySelector('[aria-selected="true"]:not(:disabled)') || menu.querySelector('.crm-select__option:not(:disabled)');
            if (active) requestAnimationFrame(function () { active.focus({ preventScroll: true }); });
        }

        function close() {
            if (!isOpen()) return;
            if (openWrap === wrap) openWrap = null;
            wrap.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function focusOption(step) {
            var items = Array.prototype.filter.call(menu.querySelectorAll('.crm-select__option'), function (item) { return !item.disabled; });
            if (!items.length) return;
            var index = items.indexOf(document.activeElement);
            var next = index < 0 ? (step > 0 ? 0 : items.length - 1) : (index + step + items.length) % items.length;
            items[next].focus({ preventScroll: true });
        }

        trigger.addEventListener('click', function () { isOpen() ? close() : open(); });
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
                if (event.key === 'ArrowUp') focusOption(-1);
            }
        });
        menu.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); focusOption(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); focusOption(-1); }
            else if (event.key === 'Enter' || event.key === ' ') {
                if (document.activeElement && document.activeElement.classList.contains('crm-select__option')) {
                    event.preventDefault();
                    document.activeElement.click();
                }
            } else if (event.key === 'Escape') {
                event.preventDefault();
                close();
                trigger.focus({ preventScroll: true });
            } else if (event.key === 'Tab') {
                close();
            }
        });

        select.addEventListener('change', sync);
        select.addEventListener('input', function () { wrap.classList.remove('is-invalid'); sync(); });
        select.addEventListener('invalid', function () {
            wrap.classList.add('is-invalid');
            trigger.focus({ preventScroll: true });
        });
        var form = select.form;
        if (form) form.addEventListener('reset', function () { setTimeout(sync, 0); });

        if ('MutationObserver' in window) {
            var observer = new MutationObserver(rebuild);
            observer.observe(select, { childList: true, subtree: true });
        }

        rebuild();
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var selects = scope.matches && scope.matches('select.form-select')
            ? [scope].concat(Array.prototype.slice.call(scope.querySelectorAll('select.form-select')))
            : scope.querySelectorAll('select.form-select');
        Array.prototype.forEach.call(selects, enhance);
    }

    function boot() {
        init(document);
        document.addEventListener('click', function (event) {
            if (openWrap && !openWrap.contains(event.target)) closeAll();
        }, true);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAll();
        }, true);
        ['show.bs.modal', 'shown.bs.modal', 'shown.bs.offcanvas'].forEach(function (name) {
            document.addEventListener(name, function (event) { init(event.target); });
        });
        if ('MutationObserver' in window) {
            var added = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes, function (node) {
                        if (node.nodeType === 1) init(node);
                    });
                });
            });
            added.observe(document.body, { childList: true, subtree: true });
        }
    }

    window.CrmSelect = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
