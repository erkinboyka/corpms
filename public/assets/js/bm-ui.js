/* ============================================================================
   BM SYSTEMS — UI Shell v1.0
   Поведение новой оболочки: rail-режим сайдбара (Ctrl+B / кнопка).
   Навигация, поиск, палитра Ctrl+K, секции — в corpms.js (не дублируем).
   ============================================================================ */
(function () {
    'use strict';

    var KEY = 'bm-side-rail';

    function isRail() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }

    function setRail(on) {
        document.documentElement.classList.toggle('bm-side-rail', on);
        var button = document.querySelector('[data-bm-sidebar-collapse]');
        if (button) {
            button.setAttribute('aria-pressed', String(on));
        }
        try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* private mode */ }
    }


    function initDealBoard() {
        const board = document.querySelector('[data-deal-board]');
        if (!board) return;
        let card = null;
        let target = null;

        function clearTarget() {
            target?.classList.remove('bm-drop-target');
            target = null;
        }

        board.addEventListener('dragstart', function (event) {
            const handle = event.target.closest('[data-deal-grip]');
            if (!handle) return;
            card = handle.closest('.crm-kanban__card');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'deal');
            event.dataTransfer.setDragImage(card, 20, 16);
            card.classList.add('bm-dragging');
        });
        board.addEventListener('dragover', function (event) {
            if (!card) return;
            const column = event.target.closest('[data-deal-stage]');
            clearTarget();
            if (!column || column.contains(card)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            target = column;
            target.classList.add('bm-drop-target');
        });
        board.addEventListener('drop', function (event) {
            if (!target || !card || !target.contains(event.target)) return;
            event.preventDefault();
            const select = card.querySelector('select[name="stage"]');
            select.value = target.dataset.dealStage;
            select.form.requestSubmit();
        });
        board.addEventListener('dragend', function () {
            clearTarget();
            card?.classList.remove('bm-dragging');
            card = null;
        });
    }

    function initSidebarRail() {
        initDealBoard();
        const menu = document.getElementById('sidebar-menu');
        const mobileToggle = document.querySelector('.bm-dock__mobile');
        if (menu && mobileToggle) {
            menu.addEventListener('shown.bs.collapse', function () {
                menu.querySelector('button, a')?.focus();
            });
            menu.addEventListener('hidden.bs.collapse', function () {
                if (mobileToggle.offsetWidth) mobileToggle.focus();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && mobileToggle.offsetWidth && menu.classList.contains('show') &&
                    !document.querySelector('.modal.show, [role="dialog"]:not([hidden]):not([aria-hidden="true"]).is-open')) {
                    mobileToggle.click();
                }
            });
        }
        document.querySelectorAll('.bm-dock__group > summary').forEach(function (summary) {
            summary.addEventListener('click', function (event) {
                if (window.matchMedia('(min-width: 992px)').matches && document.documentElement.classList.contains('bm-side-rail')) {
                    event.preventDefault();
                    setRail(false);
                    summary.parentElement.open = true;
                }
            });
        });
        document.querySelectorAll('.table-responsive').forEach(function (region) {
            if (!region.hasAttribute('tabindex')) { region.setAttribute('tabindex', '0'); }
            if (!region.hasAttribute('role')) { region.setAttribute('role', 'region'); }
            if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) {
                region.setAttribute('aria-label', 'Таблица с горизонтальной прокруткой');
            }
        });
        if (isRail()) { setRail(true); }

        var btn = document.querySelector('[data-bm-sidebar-collapse]');
        if (btn) {
            btn.addEventListener('click', function () { setRail(!document.documentElement.classList.contains('bm-side-rail')); });
        }

        document.addEventListener('keydown', function (event) {
            if (!window.matchMedia('(min-width: 992px)').matches ||
                (event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable="true"]'))) { return; }
            if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && (event.key === 'b' || event.key === 'B' || event.key === 'и' || event.key === 'И')) {
                event.preventDefault();
                setRail(!document.documentElement.classList.contains('bm-side-rail'));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebarRail);
    } else {
        initSidebarRail();
    }
})();

// Give each record its column labels on narrow screens. Comparative ledgers stay tabular.
(function () {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;
    for (const table of workspace.querySelectorAll('table[data-record-key]')) {
        if (!table.tHead || table.tHead.rows.length !== 1) continue;
        const headers = Array.from(table.tHead.rows[0].cells);
        const key = Number(table.dataset.recordKey);
        for (const body of table.tBodies) {
            for (const row of body.rows) {
                if (row.cells.length !== headers.length || Array.from(row.cells).some(cell => cell.colSpan > 1)) continue;
                row.classList.add('bm-record-row');
                Array.from(row.cells).forEach((cell, index) => {
                    cell.dataset.column = headers[index].textContent.trim();
                    if (index === key) cell.classList.add('bm-record-name');
                });
            }
        }
    }

    // Jump directly to visible sections of a long record. Modal and tab contents have their own navigation.
    if (!['record', 'configuration'].includes(workspace.dataset.workspace)) return;
    const header = workspace.querySelector('.page-header');
    const sections = Array.from(workspace.querySelectorAll('.card-header :is(h2,h3,.card-title)'))
        .filter(title => title.textContent.trim() && !title.closest('.modal,.offcanvas,.tab-pane,[hidden]'));
    if (!header || sections.length < 3) return;
    const nav = document.createElement('nav');
    nav.className = 'bm-section-links d-print-none';
    nav.setAttribute('aria-label', 'Разделы страницы');
    for (const [index, title] of sections.entries()) {
        if (!title.id) title.id = 'bm-section-' + (index + 1);
        title.tabIndex = -1;
        const link = document.createElement('a');
        link.href = '#' + title.id;
        link.textContent = title.textContent.trim();
        nav.append(link);
    }
    header.after(nav);
})();
