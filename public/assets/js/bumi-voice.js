/* ============================================================================
   BUMI VOICE — разговорный режим репетитора.
   ----------------------------------------------------------------------------
   TTS по умолчанию браузерный (работает и на http). STT по умолчанию серверный:
   браузер не даст микрофон на небезопасном origin, поэтому на http используем
   relay на http://localhost того же сайта, а если его нет — честно говорим,
   почему диктовка недоступна.

   Публичный API:
     CorpMsBumiVoice.speak(text)                 -> Promise<boolean>
     CorpMsBumiVoice.stop()
     CorpMsBumiVoice.dictate({onTranscript,onStatus,onListening}) -> {stop}
   ============================================================================ */
(() => {
    'use strict';

    if (window.CorpMsBumiVoice) return;

    const body = document.body;
    const attr = (name, fallback = '') => body?.dataset[name] ?? fallback;
    const enabled = attr('bumiConversation', '0') === '1';
    const ttsMode = attr('bumiTts', 'browser');
    const sttMode = attr('bumiStt', 'server');
    const lang = attr('bumiVoiceLang', 'ru-RU') || 'ru-RU';
    const rate = Number(attr('bumiVoiceRate', '1')) || 1;
    const pitch = Number(attr('bumiVoicePitch', '1')) || 1;
    const voiceName = attr('bumiVoiceName', '');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const secure = window.isSecureContext === true;
    const micAvailable = secure && !!navigator.mediaDevices?.getUserMedia;
    const relayUrl = attr('bumiVoiceRelay', '');
    let relayOrigin = '';
    try { relayOrigin = relayUrl ? new URL(relayUrl, location.href).origin : ''; } catch (_) { relayOrigin = ''; }
    const relayAvailable = relayOrigin !== '';

    let recorder = null;
    let stream = null;
    let dictating = false;
    let audio = null;
    let activeHandle = null;

    function setSpeaking(on) {
        document.querySelectorAll('[data-bumi-face]').forEach((face) => {
            if (on) face.dataset.bumiSpeaking = '1';
            else delete face.dataset.bumiSpeaking;
        });
        document.querySelectorAll('[data-ai-companion]').forEach((pet) => {
            if (on) pet.dataset.bumiSpeaking = '1';
            else delete pet.dataset.bumiSpeaking;
        });
    }

    function stopAudio() {
        if (!audio) return;
        try { audio.pause(); } catch (_) { /* no-op */ }
        audio.src = '';
        audio = null;
        setSpeaking(false);
    }

    function browserSpeak(text) {
        if (!('speechSynthesis' in window)) return Promise.resolve(false);
        return new Promise((resolve) => {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = lang;
            utterance.rate = rate;
            utterance.pitch = pitch;
            if (voiceName) {
                const found = window.speechSynthesis.getVoices().find((voice) => voice.name === voiceName || voice.lang === voiceName);
                if (found) utterance.voice = found;
            }
            let done = false;
            const finish = (ok) => {
                if (done) return;
                done = true;
                setSpeaking(false);
                resolve(ok);
            };
            utterance.onstart = () => setSpeaking(true);
            utterance.onend = () => finish(true);
            utterance.onerror = () => finish(false);
            try {
                window.speechSynthesis.speak(utterance);
                if (!window.speechSynthesis.speaking) finish(false);
            } catch (_) {
                finish(false);
            }
        });
    }

    async function serverSpeak(text) {
        try {
            const response = await fetch('/bumi/voice/synthesize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'audio/*, application/json' },
                body: JSON.stringify({ text }),
            });
            if (!response.ok) return false;
            const blob = await response.blob();
            if (!blob.size) return false;

            stopAudio();
            audio = new Audio(URL.createObjectURL(blob));
            audio.onended = () => { URL.revokeObjectURL(audio.src); stopAudio(); };
            audio.onerror = () => stopAudio();
            setSpeaking(true);
            await audio.play();
            return true;
        } catch (_) {
            setSpeaking(false);
            return false;
        }
    }

    function speak(text) {
        if (!enabled || !text || typeof text !== 'string') return Promise.resolve(false);
        const clean = text.replace(/[#*`>_\[\]]/g, '').replace(/\s+/g, ' ').trim().slice(0, 1000);
        if (!clean) return Promise.resolve(false);
        if (ttsMode === 'off') return Promise.resolve(false);
        if (ttsMode === 'server') return serverSpeak(clean);
        return browserSpeak(clean);
    }

    function stop() {
        stopAudio();
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        activeHandle?.stop?.();
    }

    function pickMime() {
        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        return candidates.find((mime) => window.MediaRecorder?.isTypeSupported?.(mime)) || '';
    }

    async function uploadTranscript(blob, filename, hooks) {
        const form = new FormData();
        form.append('_token', csrf);
        form.append('audio', blob, filename);
        form.append('lang', lang.startsWith('ru') ? 'ru' : 'en');

        const response = await fetch('/bumi/voice/transcribe', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: form,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok || !data.text) {
            throw new Error(data.error || 'Диктовка недоступна.');
        }
        hooks.onTranscript?.(data.text);
    }

    /**
     * http://corpms.local не считается доверенным origin. Если тот же сайт
     * поднят на http://localhost, тихий iframe ловит микрофон там и присылает
     * Blob родителю; родитель уже сам отправляет его на серверный STT.
     */
    function relayDictate(hooks) {
        if (!relayAvailable) {
            hooks.onStatus?.('Браузер даёт микрофон только по HTTPS или на localhost. Откройте сайт по защищённому адресу или настройте BUMI_VOICE_RELAY_URL.');
            return null;
        }

        const nonce = (window.crypto?.randomUUID?.() || `r${Date.now()}${Math.floor(Math.random() * 1e9)}`).replace(/[^A-Za-z0-9_-]/g, '');
        const frame = document.createElement('iframe');
        frame.src = `${relayUrl}/bumi/voice/relay?parent=${encodeURIComponent(location.origin)}&nonce=${encodeURIComponent(nonce)}`;
        frame.allow = 'microphone';
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none;border:0';
        document.body.append(frame);

        let finished = false;
        let stopping = false;
        let timer = null;

        function cleanup() {
            if (timer) window.clearTimeout(timer);
            timer = null;
            window.removeEventListener('message', onMessage);
            frame.remove();
            if (activeHandle?.frame === frame) activeHandle = null;
            dictating = false;
        }

        async function onMessage(event) {
            if (finished || event.source !== frame.contentWindow || event.origin !== relayOrigin) return;
            const data = event.data;
            if (!data || data.channel !== 'bumi-voice-relay' || data.nonce !== nonce) return;

            if (data.type === 'recording') {
                hooks.onListening?.(true);
                hooks.onStatus?.('Слушаю…');
                return;
            }
            if (data.type === 'error') {
                finished = true;
                cleanup();
                hooks.onListening?.(false);
                hooks.onStatus?.(data.message || 'Не удалось включить микрофон через localhost.');
                return;
            }
            if (data.type !== 'audio' || !(data.blob instanceof Blob)) return;

            finished = true;
            cleanup();
            hooks.onListening?.(false);
            hooks.onStatus?.('Распознаю речь…');
            try {
                await uploadTranscript(data.blob, data.filename || 'dictation.webm', hooks);
                hooks.onStatus?.('Текст добавлен. Проверьте его перед отправкой.');
            } catch (error) {
                hooks.onStatus?.(error.message || 'Диктовка недоступна. Можно ввести текст.');
            }
        }

        window.addEventListener('message', onMessage);
        hooks.onStatus?.('Включаю микрофон через localhost…');
        timer = window.setTimeout(() => {
            if (finished) return;
            finished = true;
            cleanup();
            hooks.onListening?.(false);
            hooks.onStatus?.('Микрофон не ответил. Проверьте, что сайт открыт и на http://localhost, либо используйте HTTPS.');
        }, 45000);

        const handle = {
            frame,
            stop() {
                if (finished || stopping) return;
                stopping = true;
                hooks.onListening?.(false);
                hooks.onStatus?.('Завершаю запись…');
                frame.contentWindow?.postMessage({ channel: 'bumi-voice-relay', nonce, type: 'stop' }, relayOrigin);
            },
        };
        activeHandle = handle;

        return handle;
    }

    function browserDictate(hooks) {
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Recognition) {
            hooks.onStatus?.('Этот браузер не поддерживает диктовку.');
            return null;
        }
        const recognition = new Recognition();
        recognition.lang = lang;
        recognition.interimResults = false;
        recognition.continuous = false;
        recognition.onstart = () => hooks.onListening?.(true);
        recognition.onresult = (event) => {
            const text = Array.from(event.results).slice(event.resultIndex)
                .filter((result) => result.isFinal).map((result) => result[0].transcript.trim()).join(' ').trim();
            if (text) hooks.onTranscript?.(text);
        };
        recognition.onerror = (event) => {
            dictating = false;
            if (activeHandle?.recognition === recognition) activeHandle = null;
            hooks.onListening?.(false);
            hooks.onStatus?.(event.error === 'not-allowed' || event.error === 'service-not-allowed'
                ? 'Для диктовки откройте сайт по HTTPS или разрешите доступ к микрофону.'
                : 'Диктовку не удалось завершить. Можно ввести текст вручную.');
        };
        recognition.onend = () => {
            dictating = false;
            if (activeHandle?.recognition === recognition) activeHandle = null;
            hooks.onListening?.(false);
        };
        try {
            recognition.start();
        } catch (_) {
            hooks.onStatus?.('Браузер не смог включить микрофон.');
            return null;
        }
        const handle = { recognition, stop: () => recognition.abort() };
        activeHandle = handle;

        return handle;
    }

    function serverDictate(hooks) {
        if (!micAvailable) {
            hooks.onStatus?.('Браузер даёт микрофон только по HTTPS (или на localhost). Откройте сайт по защищённому адресу.');
            return null;
        }

        let stopped = false;
        const handle = {
            stop() {
                stopped = true;
                dictating = false;
                hooks.onListening?.(false);
                if (recorder && recorder.state !== 'inactive') recorder.stop();
                else stream?.getTracks().forEach((track) => track.stop());
            },
        };

        hooks.onStatus?.('Включаю микрофон…');
        navigator.mediaDevices.getUserMedia({ audio: true }).then((media) => {
            if (stopped) {
                media.getTracks().forEach((track) => track.stop());
                hooks.onStatus?.('Запись слишком короткая. Нажми и удерживай кнопку микрофона.');
                return;
            }
            stream = media;
            const mime = pickMime();
            const chunks = [];
            recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            recorder.ondataavailable = (event) => { if (event.data?.size) chunks.push(event.data); };
            recorder.onstop = async () => {
                stream?.getTracks().forEach((track) => track.stop());
                stream = null;
                recorder = null;
                dictating = false;
                if (activeHandle === handle) activeHandle = null;
                stopped = false;
                hooks.onListening?.(false);
                if (!chunks.length) {
                    hooks.onStatus?.('Речь не записана. Попробуйте ещё раз.');
                    return;
                }
                const blob = new Blob(chunks, { type: mime || 'audio/webm' });
                hooks.onStatus?.('Распознаю речь…');
                try {
                    await uploadTranscript(blob, mime.includes('mp4') ? 'dictation.m4a' : 'dictation.webm', hooks);
                    hooks.onStatus?.('Текст добавлен. Проверьте его перед отправкой.');
                } catch (error) {
                    hooks.onStatus?.(error.message || 'Диктовка недоступна. Можно ввести текст.');
                }
            };
            recorder.start();
            dictating = true;
            hooks.onListening?.(true);
            hooks.onStatus?.('Слушаю…');
        }).catch(() => {
            dictating = false;
            hooks.onListening?.(false);
            hooks.onStatus?.('Доступ к микрофону не получен. Разрешите его в браузере и попробуйте снова.');
        });

        return handle;
    }

    function dictate(hooks = {}) {
        if (!enabled || sttMode === 'off') {
            hooks.onStatus?.('Диктовка выключена в конфигурации.');
            return null;
        }
        if (dictating) {
            activeHandle?.stop?.();
            return null;
        }
        dictating = true;
        const handle = sttMode === 'browser'
            ? browserDictate(hooks)
            : (micAvailable ? serverDictate(hooks) : relayDictate(hooks));
        if (!handle) dictating = false;
        else activeHandle = handle;
        return handle;
    }

    window.CorpMsBumiVoice = Object.freeze({
        enabled,
        ttsMode,
        sttMode,
        micAvailable,
        relayAvailable,
        speak,
        stop,
        dictate,
        isDictating: () => dictating,
    });
})();
