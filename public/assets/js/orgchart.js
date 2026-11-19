(function () {
    'use strict';

    const chart = document.getElementById('main-content');
    if (!chart || !Element.prototype.animate) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const running = new WeakMap();

    chart.addEventListener('click', event => {
        const summary = event.target.closest('summary.crm-org__node');
        const branch = summary?.parentElement;
        if (!branch?.matches('.crm-org__branch')) return;
        const content = summary.nextElementSibling;
        const inner = content.firstElementChild;
        const previous = running.get(branch);
        if (reduceMotion.matches && !previous) return;

        event.preventDefault();
        const expand = previous ? !previous.expand : !branch.open;
        const start = branch.open ? content.getBoundingClientRect().height : 0;
        previous?.animation.cancel();
        branch.open = true;
        if (!expand && content.contains(document.activeElement)) summary.focus({ preventScroll: true });
        branch.toggleAttribute('data-org-closing', !expand);
        content.inert = !expand;

        const finish = () => {
            branch.open = expand;
            branch.removeAttribute('data-org-closing');
            content.classList.remove('is-animating');
            content.inert = false;
            running.get(branch)?.animation.cancel();
            running.delete(branch);
        };
        if (reduceMotion.matches) {
            finish();
            return;
        }

        const naturalHeight = inner.getBoundingClientRect().height;
        const end = expand ? naturalHeight : 0;
        if (Math.abs(start - end) < 1) {
            finish();
            return;
        }
        content.classList.add('is-animating');
        const duration = Math.min(350, 235 + Math.max(start, end) * .12);
        const travel = Math.abs(start - end) / Math.max(start, end);
        const animation = content.animate([{ height: start + 'px' }, { height: end + 'px' }], {
            duration: duration * Math.max(.45, travel),
            easing: 'cubic-bezier(.4,0,.3,1)',
            fill: 'both',
        });
        running.set(branch, { animation, expand });
        animation.finished.then(() => {
            if (running.get(branch)?.animation === animation) finish();
        }, () => { /* Новый клик отменяет предыдущее движение. */ });
    });
})();
