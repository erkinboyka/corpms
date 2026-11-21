/* Pulse feed: append new LMS events without page reload. */
(function () {
    'use strict';

    if (!window.LMS) { return; }

    var icons = {
        course: 'school', lesson: 'book', lesson_done: 'circle-check',
        enroll: 'user-plus', board: 'pencil', meeting: 'video',
        assignment: 'clipboard-list', submit: 'send', review: 'star'
    };

    function lastId(feed) {
        var max = 0;
        LMS.qsa('[data-pulse-id]', feed).forEach(function (node) {
            max = Math.max(max, Number(node.dataset.pulseId || 0));
        });
        return max;
    }

    function addToMap(event) {
        var map = document.getElementById('lmsPulseMap');
        if (!map) { return; }

        var empty = LMS.qs('.lms-pulse-map__empty', map);
        if (empty && empty.parentNode) { empty.parentNode.removeChild(empty); }

        var index = LMS.qsa('.lms-pulse-node', map).length;
        var angle = (index * 137.5) * (Math.PI / 180);
        var radius = 20 + (index % 4) * 8;
        var x = 50 + radius * Math.cos(angle);
        var y = 50 + radius * Math.sin(angle);

        var node = LMS.el('div', 'lms-pulse-node is-hot');
        node.style.setProperty('--x', x.toFixed(1) + '%');
        node.style.setProperty('--y', y.toFixed(1) + '%');
        node.title = (event.label || '') + (event.author ? ' — ' + event.author : '');

        var dot = LMS.el('span', 'lms-pulse-node__dot lms-pulse-node__dot--' + (event.type || ''));
        dot.appendChild(LMS.el('i', 'ti ti-' + (icons[event.type] || 'activity')));
        node.appendChild(dot);
        node.appendChild(LMS.el('span', 'lms-pulse-node__label', event.author || ''));
        map.appendChild(node);

        LMS.qsa('.lms-pulse-node', map).forEach(function (old) { old.classList.remove('is-hot'); });
        node.classList.add('is-hot');
        LMS.qsa('.lms-pulse-node', map).slice(12).forEach(function (old) {
            if (old.parentNode) { old.parentNode.removeChild(old); }
        });
    }

    function makeItem(event) {
        var item = LMS.el('div', 'lms-pulse-item');
        item.dataset.pulseId = String(event.id || 0);

        var icon = LMS.el('span', 'lms-pulse-item__icon lms-pulse-item__icon--' + (event.type || ''));
        icon.appendChild(LMS.el('i', 'ti ti-' + (icons[event.type] || 'activity')));
        item.appendChild(icon);

        var body = LMS.el('div', 'lms-pulse-item__body');
        body.appendChild(LMS.el('div', 'lms-pulse-item__label', event.label));
        var meta = [];
        if (event.author) { meta.push(event.author); }
        if (event.created_at) { meta.push(event.created_at); }
        body.appendChild(LMS.el('div', 'lms-pulse-item__meta', meta.join(' · ')));
        item.appendChild(body);

        if (event.course_id) {
            var link = LMS.el('a', 'lms-pulse-item__link');
            link.href = '/lms/courses/' + encodeURIComponent(event.course_id);
            link.title = LMS.t('pulseOpen', 'Открыть курс');
            link.setAttribute('aria-label', LMS.t('pulseOpen', 'Открыть курс'));
            link.appendChild(LMS.el('i', 'ti ti-arrow-up-right'));
            item.appendChild(link);
        }

        return item;
    }

    LMS.qsa('[data-lms-pulse]').forEach(function (feed) {
        var url = feed.dataset.lmsPulse;
        if (!url) { return; }
        var pollMs = Math.max(1500, Number(feed.dataset.pollMs || 3000));
        var since = lastId(feed);

        setInterval(function () {
            LMS.request(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'since=' + since)
                .then(function (data) {
                    var events = data.events || [];
                    if (!events.length) { return; }

                    events.forEach(function (event) {
                        if (Number(event.id || 0) <= since) { return; }
                        since = Number(event.id);
                        var empty = LMS.qs('.crm-empty', feed);
                        if (empty && empty.parentNode) { empty.parentNode.removeChild(empty); }
                        LMS.qsa('.lms-pulse-item', feed).forEach(function (node) { node.classList.remove('is-dim'); });
                        addToMap(event);
                        var item = makeItem(event);
                        item.classList.add('is-dim');
                        feed.insertBefore(item, feed.firstChild);
                        LMS.qsa('.lms-pulse-item', feed).slice(12).forEach(function (node) {
                            if (node.parentNode) { node.parentNode.removeChild(node); }
                        });
                    });
                })
                .catch(function () { /* silent retry on next tick */ });
        }, pollMs);
    });
})();
