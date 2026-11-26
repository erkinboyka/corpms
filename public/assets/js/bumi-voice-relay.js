/* Тихий microphone-relay для http://corpms.local.
   Работает только в доверенном origin (HTTPS или localhost) и только с
   родителем из белого списка, который передан сервером в CSP. */
(() => {
    'use strict';

    const body = document.body;
    const parentOrigin = body?.dataset.relayParent || '';
    const nonce = body?.dataset.relayNonce || '';
    const status = document.querySelector('.relay-status');
    const CHANNEL = 'bumi-voice-relay';

    if (!parentOrigin || !nonce) {
        if (status) status.textContent = 'Relay не настроен.';
        return;
    }

    function send(type, extra = {}) {
        window.parent.postMessage({ channel: CHANNEL, nonce, type, ...extra }, parentOrigin);
    }

    function say(text) {
        if (status) status.textContent = text;
    }

    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        say('Микрофон доступен только по HTTPS или на localhost.');
        send('error', { message: 'Браузер не даёт микрофон на этом адресе. Откройте сайт по HTTPS или через localhost.' });
        return;
    }

    let stream = null;
    let recorder = null;
    let timer = null;

    function cleanup() {
        if (timer) window.clearTimeout(timer);
        timer = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        recorder = null;
    }

    async function start() {
        say('Включаю микрофон…');
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (_) {
            say('Доступ к микрофону не получен.');
            send('error', { message: 'Разрешите доступ к микрофону для localhost и попробуйте снова.' });
            return;
        }

        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        const mime = candidates.find((type) => window.MediaRecorder?.isTypeSupported?.(type)) || '';
        const chunks = [];

        recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        recorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        recorder.onstop = () => {
            const blob = new Blob(chunks, { type: mime || 'audio/webm' });
            const filename = mime.includes('mp4') ? 'dictation.m4a' : 'dictation.webm';
            cleanup();
            if (!blob.size) {
                say('Речь не записана.');
                send('error', { message: 'Речь не записана. Нажмите микрофон и попробуйте ещё раз.' });
                return;
            }
            say('Аудио отправлено на распознавание.');
            send('audio', { blob, mime: blob.type, filename });
        };
        recorder.start();
        say('Слушаю…');
        send('recording');
        timer = window.setTimeout(() => {
            if (recorder && recorder.state !== 'inactive') recorder.stop();
        }, 30000);
    }

    function stop() {
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        else cleanup();
    }

    window.addEventListener('message', (event) => {
        if (event.origin !== parentOrigin || event.source !== window.parent) return;
        const data = event.data;
        if (!data || data.channel !== CHANNEL || data.nonce !== nonce) return;
        if (data.type === 'stop') stop();
    });

    start();
})();
