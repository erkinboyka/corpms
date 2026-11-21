/* WebRTC meeting over DB-polling signaling. No Reverb, no external provider. */
(function () {
    'use strict';

    if (!window.LMS || !window.RTCPeerConnection) { return; }

    var root = LMS.qs('[data-lms-meeting]');
    if (!root) { return; }

    var myId = Number(root.dataset.myId || 0);
    var pollMs = Math.max(700, Number(root.dataset.pollMs || 1600));
    var endpoints = {
        join: root.dataset.urlJoin,
        leave: root.dataset.urlLeave,
        signals: root.dataset.urlSignals,
        signal: root.dataset.urlSignal,
        participants: root.dataset.urlParticipants
    };
    var iceServers;
    try { iceServers = JSON.parse(root.dataset.ice || '[]'); } catch (e) { iceServers = []; }

    var localVideo = LMS.qs('#lmsLocalVideo', root);
    var localTile = LMS.qs('#lmsLocalTile', root);
    var grid = LMS.qs('#lmsMeetGrid', root);
    var empty = LMS.qs('#lmsMeetEmpty', root);
    var timer = LMS.qs('#lmsMeetTimer', root);
    var status = LMS.qs('#lmsMeetStatus', root);
    var badge = LMS.qs('#lmsMeetingBadge', root);
    var joinBtn = LMS.qs('#lmsMeetJoin', root);
    var leaveBtn = LMS.qs('#lmsMeetLeave', root);
    var micBtn = LMS.qs('#lmsMeetMic', root);
    var camBtn = LMS.qs('#lmsMeetCam', root);
    var shareBtn = LMS.qs('#lmsMeetShare', root);
    var participantList = LMS.qs('#lmsParticipantList', root);
    var participantCount = LMS.qs('#lmsParticipantCount', root);

    var state = {
        joined: false,
        starting: false,
        localStream: null,
        screenTrack: null,
        cameraTrack: null,
        peers: {},
        pendingIce: {},
        offered: {},
        seen: {},
        names: {},
        lastSignal: 0,
        startedAt: 0,
        timers: []
    };

    function say(text, isError) {
        if (!status) { return; }
        status.textContent = text;
        status.style.color = isError ? 'var(--crm-danger)' : 'var(--crm-text-secondary)';
    }

    function setBadge(text) {
        if (!badge) { return; }
        badge.textContent = text;
        badge.classList.remove('crm-chip--cyan');
        badge.classList.add('crm-chip--green');
    }

    function post(url, body) { return LMS.request(url, { method: 'POST', body: body }); }

    function nameFor(id) {
        return state.names[Number(id)] || (LMS.t('meetPeer', 'Участник') + ' #' + id);
    }

    function attachRemote(peerId, stream) {
        var tileId = 'lmsRemoteTile_' + peerId;
        var tile = document.getElementById(tileId);
        if (!tile) {
            tile = LMS.el('div', 'lms-meet-tile is-remote');
            tile.id = tileId;
            var video = LMS.el('video');
            video.autoplay = true;
            video.playsInline = true;
            video.id = 'lmsRemoteVideo_' + peerId;
            tile.appendChild(video);
            tile.appendChild(LMS.el('span', 'lms-meet-tile__name', nameFor(peerId)));
            grid.insertBefore(tile, empty);
        }
        var media = LMS.qs('video', tile);
        if (media && media.srcObject !== stream) { media.srcObject = stream; }
        grid.classList.add('has-peers');
    }

    function ensurePeer(peerId) {
        peerId = Number(peerId);
        if (state.peers[peerId]) { return state.peers[peerId]; }

        var pc = new RTCPeerConnection({ iceServers: iceServers });
        state.peers[peerId] = pc;

        if (state.localStream) {
            state.localStream.getTracks().forEach(function (track) { pc.addTrack(track, state.localStream); });
        }
        if (state.screenTrack && !state.localStream.getVideoTracks().length) { pc.addTrack(state.screenTrack); }

        pc.onicecandidate = function (event) {
            if (!event.candidate) { return; }
            post(endpoints.signal, {
                type: 'ice',
                to_employee_id: peerId,
                payload: { candidate: event.candidate.toJSON ? event.candidate.toJSON() : event.candidate }
            }).catch(function () { /* candidate is optional */ });
        };

        pc.ontrack = function (event) {
            var stream = event.streams && event.streams[0];
            if (!stream) {
                stream = state.remoteStreams && state.remoteStreams[peerId];
                if (!stream) {
                    stream = new MediaStream();
                    state.remoteStreams = state.remoteStreams || {};
                    state.remoteStreams[peerId] = stream;
                }
                stream.addTrack(event.track);
            }
            attachRemote(peerId, stream);
        };

        pc.onconnectionstatechange = function () {
            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                closePeer(peerId);
            }
        };

        var queued = state.pendingIce[peerId] || [];
        queued.forEach(function (candidate) {
            pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
        });
        state.pendingIce[peerId] = [];

        return pc;
    }

    function closePeer(peerId) {
        var pc = state.peers[Number(peerId)];
        if (pc) {
            try { pc.close(); } catch (e) { /* noop */ }
            delete state.peers[Number(peerId)];
        }
        var tile = document.getElementById('lmsRemoteTile_' + peerId);
        if (tile && tile.parentNode) { tile.parentNode.removeChild(tile); }
        if (!Object.keys(state.peers).length) { grid.classList.remove('has-peers'); }
    }

    function offerTo(peerId) {
        peerId = Number(peerId);
        if (state.offered[peerId]) { return; }
        var pc = ensurePeer(peerId);
        state.offered[peerId] = true;
        pc.createOffer().then(function (offer) {
            return pc.setLocalDescription(offer).then(function () {
                return post(endpoints.signal, {
                    type: 'offer',
                    to_employee_id: peerId,
                    payload: { sdp: offer.toJSON ? offer.toJSON() : offer }
                });
            });
        }).catch(function () { say(LMS.t('meetError', 'Не удалось подключиться'), true); });
    }

    function handleSignal(signal) {
        var id = Number(signal.id || 0);
        if (!id || state.seen[id]) { return; }
        state.seen[id] = true;

        var from = Number(signal.from || 0);
        if (!from || from === myId) { return; }
        var payload = signal.payload || {};

        if (signal.type === 'hello') {
            if (myId < from) { offerTo(from); }
            return;
        }

        if (signal.type === 'bye') { closePeer(from); return; }

        if (signal.type === 'control') {
            if (payload.message) { say(String(payload.message), false); }
            return;
        }

        var pc = ensurePeer(from);

        if (signal.type === 'ice') {
            var candidate = payload.candidate;
            if (!candidate) { return; }
            if (pc.remoteDescription) {
                pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
            } else {
                state.pendingIce[from] = state.pendingIce[from] || [];
                state.pendingIce[from].push(candidate);
            }
            return;
        }

        if (signal.type === 'offer') {
            pc.setRemoteDescription(new RTCSessionDescription(payload.sdp))
                .then(function () { return pc.createAnswer(); })
                .then(function (answer) { return pc.setLocalDescription(answer).then(function () { return answer; }); })
                .then(function (answer) {
                    return post(endpoints.signal, {
                        type: 'answer',
                        to_employee_id: from,
                        payload: { sdp: answer.toJSON ? answer.toJSON() : answer }
                    });
                })
                .catch(function () { say(LMS.t('meetError', 'Не удалось подключиться'), true); });
            return;
        }

        if (signal.type === 'answer') {
            pc.setRemoteDescription(new RTCSessionDescription(payload.sdp))
                .catch(function () { say(LMS.t('meetError', 'Не удалось подключиться'), true); });
        }
    }

    function poll() {
        if (!state.joined) { return; }

        LMS.request(endpoints.signals + (endpoints.signals.indexOf('?') >= 0 ? '&' : '?') + 'since=' + state.lastSignal)
            .then(function (data) {
                (data.signals || []).forEach(function (signal) {
                    state.lastSignal = Math.max(state.lastSignal, Number(signal.id || 0));
                    handleSignal(signal);
                });
            })
            .catch(function () { /* polling is resilient */ });

        LMS.request(endpoints.participants)
            .then(function (data) {
                var list = data.participants || [];
                state.names = {};
                list.forEach(function (person) { state.names[Number(person.employee_id)] = person.name; });
                if (participantCount) { participantCount.textContent = String(list.length); }
                if (participantList) {
                    participantList.innerHTML = '';
                    list.forEach(function (person) {
                        var row = LMS.el('div', 'list-group-item d-flex align-items-center gap-2');
                        row.appendChild(LMS.el('span', 'lms-board__avatar', String(person.name || '?').slice(0, 2)));
                        row.appendChild(LMS.el('span', 'flex-fill', person.name));
                        row.appendChild(LMS.el('span',
                            'crm-chip crm-chip--' + (person.role === 'host' ? 'orange' : 'cyan'),
                            person.role === 'host' ? LMS.t('host', 'Ведущий') : LMS.t('participant', 'Участник')));
                        participantList.appendChild(row);
                    });
                }
                list.forEach(function (person) {
                    var peerId = Number(person.employee_id);
                    if (peerId !== myId && myId < peerId && !state.peers[peerId]) { offerTo(peerId); }
                });
            })
            .catch(function () { /* keep last known list */ });

        if (timer && state.startedAt) { timer.textContent = LMS.clock((Date.now() - state.startedAt) / 1000); }
    }

    function enableControls(enabled) {
        [micBtn, camBtn, shareBtn, leaveBtn].forEach(function (button) {
            if (button) { button.disabled = !enabled; }
        });
        if (joinBtn) { joinBtn.disabled = enabled; }
    }

    function join() {
        if (state.starting || state.joined) { return; }
        state.starting = true;
        say(LMS.t('meetConnecting', 'Подключаем камеру и микрофон…'), false);

        var mediaPromise = navigator.mediaDevices && navigator.mediaDevices.getUserMedia
            ? navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            : Promise.reject(new Error('getUserMedia unavailable'));

        mediaPromise.then(function (stream) {
            state.localStream = stream;
            state.cameraTrack = stream.getVideoTracks()[0] || null;
            if (localVideo) { localVideo.srcObject = stream; }
            return post(endpoints.join, {});
        }).catch(function () {
            say(LMS.t('meetNoMedia', 'Камера и микрофон недоступны — звонок продолжится в режиме слушателя'), true);
            return post(endpoints.join, {});
        }).then(function () {
            state.joined = true;
            state.starting = false;
            state.startedAt = Date.now();
            enableControls(true);
            setBadge(LMS.t('meetLive', 'live'));
            say(LMS.t('meetConnected', 'Вы в комнате'), false);
            post(endpoints.signal, { type: 'hello', to_employee_id: null, payload: { name: root.dataset.myName } }).catch(function () {});
            poll();
            state.timers.push(setInterval(poll, pollMs));
        }).catch(function () {
            state.starting = false;
            say(LMS.t('meetError', 'Не удалось подключиться'), true);
        });
    }

    function leave() {
        if (!state.joined && !state.starting) { return; }
        post(endpoints.signal, { type: 'bye', to_employee_id: null, payload: {} }).catch(function () {});
        post(endpoints.leave, {}).catch(function () {});

        state.timers.forEach(clearInterval);
        state.timers = [];
        Object.keys(state.peers).forEach(closePeer);
        if (state.localStream) { state.localStream.getTracks().forEach(function (track) { track.stop(); }); }
        if (state.screenTrack) { state.screenTrack.stop(); }
        state.localStream = null;
        state.joined = false;
        if (localVideo) { localVideo.srcObject = null; }
        if (localTile) { localTile.classList.remove('is-cam-off'); }
        enableControls(false);
        if (joinBtn) { joinBtn.disabled = false; }
        if (timer) { timer.textContent = '00:00'; }
        if (badge) {
            badge.textContent = LMS.t('meetEnded', 'ended');
            badge.classList.remove('crm-chip--green');
            badge.classList.add('crm-chip--cyan');
        }
        say(LMS.t('meetLeft', 'Вы отключились'), false);
    }

    function toggleMic() {
        if (!state.localStream) { return; }
        var tracks = state.localStream.getAudioTracks();
        var enabled = !tracks.length || !tracks[0].enabled;
        tracks.forEach(function (track) { track.enabled = enabled; });
        micBtn.classList.toggle('is-active', enabled);
        say(LMS.t(enabled ? 'micOn' : 'micOff', enabled ? 'Микрофон включён' : 'Микрофон выключен'), false);
    }

    function toggleCam() {
        if (!state.localStream) { return; }
        var tracks = state.localStream.getVideoTracks();
        var enabled = !tracks.length || !tracks[0].enabled;
        tracks.forEach(function (track) { track.enabled = enabled; });
        camBtn.classList.toggle('is-active', enabled);
        if (localTile) { localTile.classList.toggle('is-cam-off', !enabled); }
        say(LMS.t(enabled ? 'camOn' : 'camOff', enabled ? 'Камера включена' : 'Камера выключена'), false);
    }

    function toggleShare() {
        if (!state.localStream || !navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) { return; }

        if (state.screenTrack) {
            state.screenTrack.stop();
            state.screenTrack = null;
            var sender = Object.keys(state.peers).length ? state.peers[Object.keys(state.peers)[0]].getSenders().find(function (s) { return s.track && s.track.kind === 'video'; }) : null;
            if (sender && state.cameraTrack) { sender.replaceTrack(state.cameraTrack); }
            shareBtn.classList.remove('is-sharing');
            say(LMS.t('meetScreenOff', 'Демонстрация экрана остановлена'), false);
            return;
        }

        navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (stream) {
            state.screenTrack = stream.getVideoTracks()[0];
            shareBtn.classList.add('is-sharing');
            say(LMS.t('meetScreenOn', 'Демонстрация экрана включена'), false);
            Object.keys(state.peers).forEach(function (peerId) {
                var pc = state.peers[peerId];
                var sender = pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
                if (sender) { sender.replaceTrack(state.screenTrack); }
                else { pc.addTrack(state.screenTrack, stream); }
            });
            state.screenTrack.addEventListener('ended', function () {
                if (shareBtn) { shareBtn.click(); }
            });
        }).catch(function () { /* user cancelled screen picker */ });
    }

    if (joinBtn) { joinBtn.addEventListener('click', join); }
    if (leaveBtn) { leaveBtn.addEventListener('click', leave); }
    if (micBtn) { micBtn.addEventListener('click', toggleMic); }
    if (camBtn) { camBtn.addEventListener('click', toggleCam); }
    if (shareBtn) { shareBtn.addEventListener('click', toggleShare); }
    window.addEventListener('beforeunload', function () {
        if (state.joined) { post(endpoints.leave, {}).catch(function () {}); }
    });
})();
