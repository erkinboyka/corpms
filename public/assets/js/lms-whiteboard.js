/* Collaborative whiteboard: canvas + JSON elements + delta polling. */
(function () {
    'use strict';

    if (!window.LMS) { return; }

    var root = LMS.qs('[data-lms-whiteboard]');
    if (!root) { return; }

    var canvas = LMS.qs('#lmsBoardCanvas', root);
    var wrap = LMS.qs('#lmsCanvasWrap', root);
    if (!canvas || !wrap) { return; }

    var ctx = canvas.getContext('2d');
    var presenceLayer = LMS.qs('#lmsPresence', root);
    var statusLabel = LMS.qs('#lmsBoardStatus', root);
    var peersLabel = LMS.qs('#lmsBoardPeers', root);
    var hint = LMS.qs('#lmsBoardHint', root);
    var editor = LMS.qs('#lmsTextEditor', root);
    var textInput = LMS.qs('#lmsTextInput', root);
    var textSave = LMS.qs('#lmsTextSave', root);
    var textCancel = LMS.qs('#lmsTextCancel', root);
    var colorInput = LMS.qs('#lmsBoardColor', root);
    var widthInput = LMS.qs('#lmsBoardWidth', root);

    var myId = Number(root.dataset.myId || 0);
    var pollMs = Math.max(700, Number(root.dataset.pollMs || 1600));
    var urls = {
        elements: root.dataset.urlElements,
        draw: root.dataset.urlDraw,
        erase: root.dataset.urlErase,
        presence: root.dataset.urlPresence,
        presenceList: root.dataset.urlPresenceList
    };

    var state = {
        elements: [],
        maxId: 0,
        tool: 'path',
        color: colorInput ? colorInput.value : '#206bc4',
        width: widthInput ? Number(widthInput.value) : 3,
        drawing: false,
        draft: null,
        myIds: [],
        textPoint: null,
        lastPresence: 0,
        busy: false
    };

    function setStatus(text, isError) {
        if (!statusLabel) { return; }
        statusLabel.textContent = text;
        statusLabel.style.color = isError ? 'var(--crm-danger)' : 'var(--crm-success)';
    }

    function resize() {
        var rect = wrap.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.round(rect.width * dpr));
        canvas.height = Math.max(1, Math.round(rect.height * dpr));
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        render();
    }

    function point(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    function roundedRect(x, y, w, h, r) {
        var radius = Math.min(r, Math.abs(w) / 2, Math.abs(h) / 2);
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, radius);
        ctx.arcTo(x, y + h, x, y, radius);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
    }

    function wrapText(text, x, y, maxWidth, lineHeight) {
        var lines = String(text || '').split('\n');
        lines.forEach(function (line, index) {
            ctx.fillText(line, x, y + index * lineHeight);
        });
    }

    function drawElement(element) {
        if (!element || element.is_erased) { return; }
        var color = element.color || '#206bc4';
        var width = Number(element.width || 3);
        var payload = element.payload || {};
        ctx.save();
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = width;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        if (element.type === 'path' && Array.isArray(payload.points) && payload.points.length) {
            var points = payload.points;
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (var i = 1; i < points.length - 1; i++) {
                var midX = (points[i].x + points[i + 1].x) / 2;
                var midY = (points[i].y + points[i + 1].y) / 2;
                ctx.quadraticCurveTo(points[i].x, points[i].y, midX, midY);
            }
            if (points.length > 1) { ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y); }
            ctx.stroke();
        } else if (element.type === 'rect') {
            ctx.strokeRect(payload.x, payload.y, payload.w, payload.h);
        } else if (element.type === 'ellipse') {
            var radiusX = Math.abs(payload.w / 2);
            var radiusY = Math.abs(payload.h / 2);
            var centerX = Math.min(payload.x, payload.x + payload.w) + radiusX;
            var centerY = Math.min(payload.y, payload.y + payload.h) + radiusY;
            ctx.beginPath();
            ctx.ellipse(centerX, centerY, radiusX, radiusY, 0, 0, Math.PI * 2);
            ctx.stroke();
        } else if (element.type === 'text') {
            ctx.font = '600 16px ' + (getComputedStyle(document.body).fontFamily || 'sans-serif');
            wrapText(payload.text, payload.x, payload.y + 16, 320, 21);
        } else if (element.type === 'sticky') {
            var w = Math.max(120, Math.min(300, Number(payload.w || 190)));
            var h = Math.max(72, Math.min(220, Number(payload.h || 96)));
            ctx.globalAlpha = .16;
            roundedRect(payload.x, payload.y, w, h, 10);
            ctx.fill();
            ctx.globalAlpha = 1;
            roundedRect(payload.x, payload.y, w, h, 10);
            ctx.stroke();
            ctx.font = '500 14px ' + (getComputedStyle(document.body).fontFamily || 'sans-serif');
            wrapText(payload.text, payload.x + 12, payload.y + 24, w - 24, 19);
        }

        if (element.author) {
            ctx.globalAlpha = .75;
            ctx.font = '11px ' + (getComputedStyle(document.body).fontFamily || 'sans-serif');
            var labelX = payload.x || (payload.points && payload.points[0] && payload.points[0].x) || 0;
            var labelY = payload.y || (payload.points && payload.points[0] && payload.points[0].y) || 0;
            ctx.fillText(element.author, labelX + 4, labelY - 6);
        }
        ctx.restore();
    }

    function render() {
        var rect = wrap.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);
        state.elements.forEach(drawElement);
        if (state.draft) { drawElement(state.draft); }
    }

    function distanceToSegment(p, a, b) {
        var dx = b.x - a.x;
        var dy = b.y - a.y;
        var lengthSq = dx * dx + dy * dy;
        if (!lengthSq) { return Math.hypot(p.x - a.x, p.y - a.y); }
        var t = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / lengthSq));
        return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
    }

    function hitTest(element, p) {
        if (!element || element.is_erased) { return false; }
        var payload = element.payload || {};
        var tolerance = Math.max(8, Number(element.width || 3) + 6);

        if (element.type === 'path' && Array.isArray(payload.points)) {
            for (var i = 0; i < payload.points.length - 1; i++) {
                if (distanceToSegment(p, payload.points[i], payload.points[i + 1]) <= tolerance) { return true; }
            }
            return payload.points.length === 1 && Math.hypot(p.x - payload.points[0].x, p.y - payload.points[0].y) <= tolerance;
        }

        if (element.type === 'rect' || element.type === 'ellipse') {
            var x1 = Math.min(payload.x, payload.x + payload.w) - tolerance;
            var x2 = Math.max(payload.x, payload.x + payload.w) + tolerance;
            var y1 = Math.min(payload.y, payload.y + payload.h) - tolerance;
            var y2 = Math.max(payload.y, payload.y + payload.h) + tolerance;
            return p.x >= x1 && p.x <= x2 && p.y >= y1 && p.y <= y2;
        }

        var width = Number(payload.w || 200) + tolerance * 2;
        var height = Number(payload.h || 60) + tolerance * 2;
        return p.x >= payload.x - tolerance && p.x <= payload.x + width &&
               p.y >= payload.y - tolerance && p.y <= payload.y + height;
    }

    function appendElements(elements) {
        var known = {};
        state.elements.forEach(function (item) { known[item.id] = true; });
        elements.forEach(function (item) {
            if (known[item.id]) { return; }
            state.elements.push(item);
            state.maxId = Math.max(state.maxId, Number(item.id || 0));
        });
    }

    function pollElements() {
        if (state.busy) { return; }
        state.busy = true;
        LMS.request(urls.elements + (urls.elements.indexOf('?') >= 0 ? '&' : '?') + 'since=' + state.maxId)
            .then(function (data) {
                if (data.elements && data.elements.length) {
                    appendElements(data.elements);
                    render();
                }
                setStatus(LMS.t('syncOk', 'Синхронизация активна'), false);
            })
            .catch(function () { setStatus(LMS.t('syncLost', 'Синхронизация прервана — повторяем'), true); })
            .then(function () { state.busy = false; });
    }

    function renderPresence(list) {
        if (!presenceLayer) { return; }
        presenceLayer.innerHTML = '';
        (list || []).forEach(function (person) {
            if (Number(person.employee_id) === myId) { return; }
            var cursor = LMS.el('div', 'lms-presence-cursor');
            cursor.style.left = (Number(person.x || 0) * 100) + '%';
            cursor.style.top = (Number(person.y || 0) * 100) + '%';
            cursor.style.setProperty('--cursor-color', person.color || '#206bc4');
            cursor.appendChild(LMS.el('i', 'ti ti-pointer'));
            cursor.appendChild(LMS.el('span', '', person.name));
            presenceLayer.appendChild(cursor);
        });
        if (peersLabel) { peersLabel.textContent = String((list || []).length || 1); }
    }

    function pollPresence() {
        LMS.request(urls.presenceList)
            .then(function (data) { renderPresence(data.presence || []); })
            .catch(function () { /* transient; keep last known cursors */ });
    }

    function sendPresence(p) {
        var now = Date.now();
        if (now - state.lastPresence < 250) { return; }
        state.lastPresence = now;
        var rect = wrap.getBoundingClientRect();
        LMS.request(urls.presence, {
            method: 'POST',
            body: { x: Math.max(0, Math.min(1, p.x / rect.width)), y: Math.max(0, Math.min(1, p.y / rect.height)) }
        }).catch(function () { /* presence is best-effort */ });
    }

    function saveElement(type, payload, done) {
        LMS.request(urls.draw, {
            method: 'POST',
            body: { type: type, payload: payload, color: state.color, width: state.width }
        }).then(function (data) {
            state.myIds.push(Number(data.id));
            appendElements([{ id: Number(data.id), type: type, payload: payload, color: state.color,
                width: state.width, is_erased: false, author: root.dataset.myName || '' }]);
            render();
            if (done) { done(true); }
        }).catch(function () {
            setStatus(LMS.t('drawFailed', 'Не удалось сохранить штрих'), true);
            if (done) { done(false); }
        });
    }

    function eraseElement(id) {
        LMS.request(urls.erase.replace('__ID__', String(id)), { method: 'DELETE' })
            .then(function () {
                state.elements.forEach(function (item) { if (Number(item.id) === Number(id)) { item.is_erased = true; } });
                render();
            })
            .catch(function () { setStatus(LMS.t('eraseFailed', 'Не удалось удалить элемент'), true); });
    }

    function openEditor(p, type) {
        if (!editor || !textInput) { return; }
        state.textPoint = p;
        editor.hidden = false;
        editor.style.left = Math.min(p.x, wrap.clientWidth - 250) + 'px';
        editor.style.top = Math.min(p.y, wrap.clientHeight - 150) + 'px';
        textInput.value = '';
        textInput.focus();
        editor.dataset.type = type;
    }

    function closeEditor() {
        if (editor) { editor.hidden = true; }
        state.textPoint = null;
    }

    function onPointerDown(event) {
        if (event.button !== 0 && event.pointerType === 'mouse') { return; }
        if (editor && !editor.hidden) { return; }
        var p = point(event);

        if (state.tool === 'text' || state.tool === 'sticky') {
            openEditor(p, state.tool);
            return;
        }

        if (state.tool === 'eraser') {
            for (var i = state.elements.length - 1; i >= 0; i--) {
                if (hitTest(state.elements[i], p)) { eraseElement(state.elements[i].id); break; }
            }
            return;
        }

        state.drawing = true;
        state.draft = {
            id: -1,
            type: state.tool,
            color: state.color,
            width: state.width,
            is_erased: false,
            author: '',
            payload: state.tool === 'path' ? { points: [p] } : { x: p.x, y: p.y, w: 0, h: 0 }
        };
        canvas.setPointerCapture(event.pointerId);
        render();
    }

    function onPointerMove(event) {
        var p = point(event);
        sendPresence(p);
        if (!state.drawing || !state.draft) { return; }

        if (state.draft.type === 'path') {
            state.draft.payload.points.push(p);
        } else {
            state.draft.payload.w = p.x - state.draft.payload.x;
            state.draft.payload.h = p.y - state.draft.payload.y;
        }
        render();
    }

    function onPointerUp(event) {
        if (!state.drawing || !state.draft) { return; }
        state.drawing = false;
        var draft = state.draft;
        state.draft = null;

        var valid = draft.type === 'path'
            ? draft.payload.points.length > 1
            : Math.abs(draft.payload.w) > 4 || Math.abs(draft.payload.h) > 4;

        if (valid) { saveElement(draft.type, draft.payload); } else { render(); }
        if (canvas.hasPointerCapture && canvas.hasPointerCapture(event.pointerId)) {
            canvas.releasePointerCapture(event.pointerId);
        }
    }

    LMS.qsa('[data-tool]', root).forEach(function (button) {
        button.addEventListener('click', function () {
            state.tool = button.dataset.tool;
            LMS.qsa('[data-tool]', root).forEach(function (item) { item.classList.remove('is-active'); });
            button.classList.add('is-active');
            wrap.classList.toggle('is-eraser', state.tool === 'eraser');
            if (hint) {
                var labels = {
                    path: LMS.t('toolPath', 'Карандаш'), rect: LMS.t('toolRect', 'Прямоугольник'),
                    ellipse: LMS.t('toolEllipse', 'Круг'), text: LMS.t('toolText', 'Текст'),
                    sticky: LMS.t('toolSticky', 'Заметка'), eraser: LMS.t('toolEraser', 'Ластик')
                };
                hint.textContent = (labels[state.tool] || '') + ' · ' + LMS.t('boardHint', 'Выберите инструмент и ведите курсором по доске');
            }
        });
    });

    if (colorInput) { colorInput.addEventListener('input', function () { state.color = colorInput.value; }); }
    if (widthInput) { widthInput.addEventListener('input', function () { state.width = Number(widthInput.value); }); }

    var undo = LMS.qs('#lmsBoardUndo', root);
    if (undo) {
        undo.addEventListener('click', function () {
            for (var i = state.myIds.length - 1; i >= 0; i--) {
                var id = state.myIds[i];
                var item = state.elements.find(function (element) { return Number(element.id) === Number(id); });
                if (item && !item.is_erased) { eraseElement(id); state.myIds.splice(i, 1); return; }
            }
        });
    }

    if (textSave) {
        textSave.addEventListener('click', function () {
            var value = (textInput.value || '').trim();
            if (!value || !state.textPoint) { closeEditor(); return; }
            var type = editor.dataset.type || 'text';
            var payload = type === 'sticky'
                ? { x: state.textPoint.x, y: state.textPoint.y, w: 190, h: 96, text: value }
                : { x: state.textPoint.x, y: state.textPoint.y, text: value };
            closeEditor();
            saveElement(type, payload);
        });
    }
    if (textCancel) { textCancel.addEventListener('click', closeEditor); }
    if (textInput) {
        textInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); textSave.click(); }
            if (event.key === 'Escape') { closeEditor(); }
        });
    }

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);
    canvas.addEventListener('pointerleave', function () { if (!state.drawing) { return; } });

    window.addEventListener('resize', resize);
    resize();
    pollElements();
    pollPresence();
    setInterval(pollElements, pollMs);
    setInterval(pollPresence, Math.max(2000, pollMs));
})();
