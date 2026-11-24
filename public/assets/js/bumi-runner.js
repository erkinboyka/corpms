(() => {
    'use strict';

    const marker = document.getElementById('bumi-gen-state');
    if (!marker) return;

    function init() {
        const companion = document.querySelector('[data-ai-companion]');
        if (!companion) return;

        const toggleBtn = companion.querySelector('[data-ai-toggle]');

        // Bumi остаётся один на экране, но во время генерации бегает по странице
        // с охапкой бумаг и рисует отчёт. Маршрут считается от текущей позиции.
        function setPatrolBounds() {
            const view = window.visualViewport;
            const width = view ? view.width : window.innerWidth;
            const height = view ? view.height : window.innerHeight;
            const offsetX = view ? view.offsetLeft : 0;
            const offsetY = view ? view.offsetTop : 0;
            const rect = companion.getBoundingClientRect();
            const margin = 16;

            const left = Math.max(0, rect.left - offsetX - margin);
            const up = Math.max(0, rect.top - offsetY - margin);
            const right = Math.max(0, offsetX + width - margin - rect.right);
            const down = Math.max(0, offsetY + height - margin - rect.bottom);

            companion.style.setProperty('--bm-run-left', `-${Math.round(Math.min(left, width * .62))}px`);
            companion.style.setProperty('--bm-run-up', `-${Math.round(Math.min(up, height * .42))}px`);
            companion.style.setProperty('--bm-run-right', `${Math.round(Math.min(right, width * .18))}px`);
            companion.style.setProperty('--bm-run-down', `${Math.round(Math.min(down, height * .18))}px`);
        }

        setPatrolBounds();
        companion.setAttribute('data-bm-running', '');
        document.body.classList.add('bi-generating');

        const pausePatrol = () => companion.setAttribute('data-bm-paused', '');
        const resumePatrol = () => companion.removeAttribute('data-bm-paused');
        toggleBtn?.addEventListener('pointerdown', pausePatrol);
        toggleBtn?.addEventListener('pointerup', resumePatrol);
        toggleBtn?.addEventListener('pointercancel', resumePatrol);
        toggleBtn?.addEventListener('lostpointercapture', resumePatrol);
        window.addEventListener('resize', setPatrolBounds, { passive: true });
        window.visualViewport?.addEventListener('resize', setPatrolBounds, { passive: true });

        const statusUrl = marker.dataset.statusUrl || '';
        const stages = ['sketch', 'check', 'polish'];
        let stageIndex = 0;
        let pollTimer = 0;
        let stageTimer = 0;

        stageTimer = window.setInterval(() => {
            stageIndex = (stageIndex + 1) % stages.length;
            companion.dataset.bmStage = stages[stageIndex];
        }, 3200);

        let pollErrors = 0;
        let finishing = false;

        async function poll() {
            if (!statusUrl || finishing) return;

            try {
                const response = await fetch(statusUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (response.status === 401 || response.status === 419) {
                    window.location.reload();
                    return;
                }
                if (!response.ok) throw new Error('HTTP ' + response.status);

                const data = await response.json();
                pollErrors = 0;
                companion.dataset.bmProgress = (data.ready ?? 0) + '/' + (data.total ?? 0);
                const statusEl = document.querySelector('[data-bm-status]');
                const liveEl = document.querySelector('[data-bm-progress-live]');
                const progressText = data.status === 'generating'
                    ? 'Bumi собирает дашборд: ' + (data.ready ?? 0) + ' из ' + (data.total ?? 0)
                    : (data.status === 'ready' ? 'Дашборд готов' : 'Генерация завершилась с ошибкой');
                if (statusEl) statusEl.textContent = progressText;
                if (liveEl) liveEl.textContent = progressText;

                if (data.status !== 'generating') {
                    finishing = true;
                    window.clearInterval(pollTimer);
                    window.clearInterval(stageTimer);
                    document.body.classList.remove('bi-generating');
                    document.body.classList.add('bi-finishing');
                    window.setTimeout(() => window.location.reload(), 1150);
                }
            } catch (error) {
                pollErrors += 1;
                if (pollErrors >= 8) {
                    window.clearInterval(pollTimer);
                    window.setTimeout(() => { if (!finishing && document.body.classList.contains('bi-generating')) window.location.reload(); }, 15000);
                }
            }
        }

        poll();
        pollTimer = window.setInterval(poll, 3000);
        window.addEventListener('pagehide', () => {
            window.clearInterval(pollTimer);
            window.clearInterval(stageTimer);
            window.removeEventListener('resize', setPatrolBounds);
            window.visualViewport?.removeEventListener('resize', setPatrolBounds);
            toggleBtn?.removeEventListener('pointerdown', pausePatrol);
            toggleBtn?.removeEventListener('pointerup', resumePatrol);
            toggleBtn?.removeEventListener('pointercancel', resumePatrol);
            toggleBtn?.removeEventListener('lostpointercapture', resumePatrol);
        }, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
