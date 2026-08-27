/**
 * NexProctor attempt monitor — streams, snapshots, faces, noise, tab/screen, attention.
 *
 * @module local_nexproctor/monitor
 */
define(['core/ajax', 'core/notification', 'local_nexproctor/facedetect', 'local_nexproctor/startflow'],
    function(Ajax, Notification, FaceDetect, StartFlow) {

    const state = {
        sessionid: 0,
        cameraStream: null,
        screenStream: null,
        video: null,
        screenVideo: null,
        audioCtx: null,
        analyser: null,
        noiseTimer: null,
        noiseEnabled: false,
        noiseCooldownUntil: 0,
        faceMissStreak: 0,
        multiFaceStreak: 0,
        multiFaceCooldownUntil: 0,
        noFaceCooldownUntil: 0,
        tabCooldownUntil: 0,
        awayStreak: 0,
        lastFaceCount: 1,
        faceEngineReady: false,
        running: false,
        stopping: false,
        timers: [],
        cfg: null,
        fsGateShown: false,
        deviceGateShown: false,
        deviceIssues: {camera: false, mic: false, screen: false},
        camDragBound: false,
        bound: false,
        fsGraceUntil: 0,
        gateComplete: false
    };

    const GATE_STORAGE_PREFIX = 'np-proctor-gate-';

    const gateStorageKey = function(cfg) {
        const id = (cfg && cfg.attemptid) ? cfg.attemptid : 0;
        return GATE_STORAGE_PREFIX + String(id || (cfg && cfg.cmid) || '0');
    };

    const readGateComplete = function(cfg) {
        if (state.gateComplete) {
            return true;
        }
        try {
            return window.sessionStorage.getItem(gateStorageKey(cfg)) === '1';
        } catch (e) {
            return false;
        }
    };

    const markGateComplete = function(cfg) {
        state.gateComplete = true;
        try {
            window.sessionStorage.setItem(gateStorageKey(cfg), '1');
        } catch (e) {
            // Ignore.
        }
    };

    const clearGateComplete = function(cfg) {
        state.gateComplete = false;
        cfg = cfg || state.cfg;
        if (!cfg) {
            return;
        }
        try {
            window.sessionStorage.removeItem(gateStorageKey(cfg));
        } catch (e) {
            // Ignore.
        }
    };

    const streamsReady = function(cfg) {
        const s = cfg.settings || {};
        if ((flag(s.requirecamera) || flag(s.detectfaces)) && !trackLive(state.cameraStream, 'video')) {
            return false;
        }
        if (needsMic(cfg) && !trackLive(state.cameraStream, 'audio')) {
            return false;
        }
        if (flag(s.requirescreenshare) && !trackLive(state.screenStream, 'video')) {
            return false;
        }
        return true;
    };

    /** Coerce Moodle setting to boolean (handles 0/1/"0"/"1"). */
    const flag = function(v) {
        return v === true || v === 1 || v === '1';
    };

    const call = function(method, args) {
        return Ajax.call([{methodname: method, args: args}])[0];
    };

    const isAttemptUrl = function() {
        return /\/mod\/quiz\/attempt\.php(\?|$)/i.test(window.location.href);
    };

    const logEvent = function(type, severity, payload, penalty) {
        if (!state.sessionid || state.stopping) {
            return Promise.resolve(null);
        }
        return call('local_nexproctor_log_event', {
            sessionid: state.sessionid,
            eventtype: type,
            severity: severity || 'info',
            payload: payload ? JSON.stringify(payload) : '',
            penalty: penalty || 0
        }).catch(function() {
            return null;
        });
    };

    const grabJpeg = function(videoEl, quality) {
        if (!videoEl || !videoEl.videoWidth) {
            return null;
        }
        const canvas = document.createElement('canvas');
        // Higher-res stills for review evidence.
        const maxW = 1280;
        const w = Math.min(maxW, videoEl.videoWidth);
        const h = Math.round(videoEl.videoHeight * (w / videoEl.videoWidth));
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(videoEl, 0, 0, w, h);
        return canvas.toDataURL('image/jpeg', quality == null ? 0.92 : quality);
    };

    /**
     * Prefer ImageCapture grabFrame for sharper stills from a live track.
     */
    const grabFromStream = async function(stream, fallbackVideo, quality) {
        const q = quality == null ? 0.92 : quality;
        try {
            const track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
            if (track && window.ImageCapture) {
                const ic = new window.ImageCapture(track);
                const bitmap = await ic.grabFrame();
                const canvas = document.createElement('canvas');
                const maxW = 1280;
                const scale = Math.min(1, maxW / bitmap.width);
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                const ctx = canvas.getContext('2d');
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                if (bitmap.close) {
                    bitmap.close();
                }
                return canvas.toDataURL('image/jpeg', q);
            }
        } catch (e) {
            // Fall through to video element.
        }
        return grabJpeg(fallbackVideo, q);
    };

    const uploadEvidence = function(filearea, dataUrl, eventtype, severity, eventid) {
        if (!state.sessionid || !dataUrl || state.stopping) {
            return Promise.resolve(null);
        }
        return call('local_nexproctor_upload_evidence', {
            sessionid: state.sessionid,
            eventid: eventid || 0,
            filearea: filearea,
            mimetype: filearea === 'audioclip' ? 'audio/webm' : 'image/jpeg',
            data: dataUrl,
            eventtype: eventtype || '',
            severity: severity || 'warning'
        }).catch(function() {
            return null;
        });
    };

    /**
     * Fullscreen element, arena shell, or body — HUD must live inside fullscreen root.
     */
    const mountRoot = function() {
        return document.fullscreenElement
            || document.getElementById('ll-arena')
            || document.documentElement
            || document.body;
    };

    const remountChrome = function() {
        const root = mountRoot();
        // Only remount the cam wrap — never the inner video alone (that empties the box).
        ['np-monitor-hud', 'np-monitor-cam-wrap', 'np-fs-gate', 'np-device-gate'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el && el.parentNode !== root) {
                root.appendChild(el);
            }
        });
        // Repair: if video was detached from wrap, put it back.
        const wrap = document.getElementById('np-monitor-cam-wrap');
        const cam = document.getElementById('np-monitor-cam');
        if (wrap && cam && cam.parentNode !== wrap) {
            wrap.insertBefore(cam, wrap.firstChild);
            state.video = cam;
        }
    };

    const CAM_POS_KEY = 'nexproctor_cam_pos';

    const clampCamPos = function(wrap, left, top) {
        const w = wrap.offsetWidth || 120;
        const h = wrap.offsetHeight || 90;
        const maxL = Math.max(0, window.innerWidth - w - 4);
        const maxT = Math.max(0, window.innerHeight - h - 4);
        return {
            left: Math.min(maxL, Math.max(0, left)),
            top: Math.min(maxT, Math.max(0, top))
        };
    };

    const applyCamPos = function(wrap, left, top) {
        const p = clampCamPos(wrap, left, top);
        wrap.style.left = p.left + 'px';
        wrap.style.top = p.top + 'px';
        wrap.style.right = 'auto';
        wrap.style.bottom = 'auto';
        try {
            window.sessionStorage.setItem(CAM_POS_KEY, JSON.stringify(p));
        } catch (e) {
            // Ignore.
        }
    };

    const restoreCamPos = function(wrap) {
        try {
            const raw = window.sessionStorage.getItem(CAM_POS_KEY);
            if (raw) {
                const p = JSON.parse(raw);
                if (p && typeof p.left === 'number' && typeof p.top === 'number') {
                    applyCamPos(wrap, p.left, p.top);
                    return;
                }
            }
        } catch (e) {
            // Ignore.
        }
        // Default: bottom-left so quiz "Next" (bottom-right) stays clear.
        window.requestAnimationFrame(function() {
            const h = wrap.offsetHeight || 90;
            applyCamPos(wrap, 12, Math.max(12, window.innerHeight - h - 16));
        });
    };

    const enableCamDrag = function(wrap) {
        if (!wrap || wrap.getAttribute('data-np-drag') === '1') {
            return;
        }
        wrap.setAttribute('data-np-drag', '1');
        wrap.setAttribute('title', 'Drag to move');
        let dragging = false;
        let startX = 0;
        let startY = 0;
        let origLeft = 0;
        let origTop = 0;

        wrap.addEventListener('pointerdown', function(e) {
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();
            const rect = wrap.getBoundingClientRect();
            dragging = true;
            startX = e.clientX;
            startY = e.clientY;
            origLeft = rect.left;
            origTop = rect.top;
            wrap.style.left = origLeft + 'px';
            wrap.style.top = origTop + 'px';
            wrap.style.right = 'auto';
            wrap.style.bottom = 'auto';
            wrap.classList.add('is-dragging');
            try {
                wrap.setPointerCapture(e.pointerId);
            } catch (err) {
                // Ignore.
            }
        });
        wrap.addEventListener('pointermove', function(e) {
            if (!dragging) {
                return;
            }
            applyCamPos(wrap, origLeft + (e.clientX - startX), origTop + (e.clientY - startY));
        });
        const endDrag = function() {
            if (!dragging) {
                return;
            }
            dragging = false;
            wrap.classList.remove('is-dragging');
        };
        wrap.addEventListener('pointerup', endDrag);
        wrap.addEventListener('pointercancel', endDrag);
        window.addEventListener('resize', function() {
            const rect = wrap.getBoundingClientRect();
            applyCamPos(wrap, rect.left, rect.top);
        });
    };

    const ensureHud = function(cfg) {
        const root = mountRoot();
        const needCam = !!(cfg && cfg.settings && (
            flag(cfg.settings.requirecamera) || flag(cfg.settings.detectfaces)
        ));

        if (!document.getElementById('np-monitor-hud')) {
            const hud = document.createElement('div');
            hud.id = 'np-monitor-hud';
            hud.className = 'np-monitor-hud';
            hud.innerHTML =
                '<span class="np-monitor-hud__dot"></span>' +
                '<span class="np-monitor-hud__label">NexProctor</span>' +
                '<span class="np-monitor-hud__score" data-np-score>100</span>';
            root.appendChild(hud);
        }

        let wrap = document.getElementById('np-monitor-cam-wrap');
        if (!needCam) {
            // Webcam disabled — do not show the black placeholder tile.
            if (wrap) {
                wrap.remove();
            }
            state.video = null;
            remountChrome();
            return;
        }

        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'np-monitor-cam-wrap';
            wrap.className = 'np-monitor-cam-wrap';
            const preview = document.createElement('video');
            preview.id = 'np-monitor-cam';
            preview.className = 'np-monitor-cam';
            preview.autoplay = true;
            preview.muted = true;
            preview.playsInline = true;
            wrap.appendChild(preview);
            const grip = document.createElement('span');
            grip.className = 'np-monitor-cam-wrap__grip';
            grip.setAttribute('aria-hidden', 'true');
            grip.textContent = '⠿';
            wrap.appendChild(grip);
            root.appendChild(wrap);
            enableCamDrag(wrap);
            restoreCamPos(wrap);
            state.video = preview;
        } else {
            state.video = document.getElementById('np-monitor-cam') || wrap.querySelector('video');
            enableCamDrag(wrap);
        }
        if (state.cameraStream && state.video) {
            wrap.classList.add('has-stream');
        } else {
            wrap.classList.remove('has-stream');
        }
        remountChrome();
    };

    const setScore = function(n) {
        const el = document.querySelector('[data-np-score]');
        if (el) {
            el.textContent = String(n);
        }
    };

    const showFullscreenGate = function(cfg) {
        if (!flag(cfg.settings.requirefullscreen) || state.stopping) {
            return;
        }
        let gate = document.getElementById('np-fs-gate');
        if (!gate) {
            gate = document.createElement('div');
            gate.id = 'np-fs-gate';
            gate.className = 'np-fs-gate';
            gate.innerHTML =
                '<div class="np-fs-gate__card" role="dialog" aria-modal="true">' +
                    '<h3 class="np-fs-gate__title"></h3>' +
                    '<p class="np-fs-gate__body"></p>' +
                    '<button type="button" class="btn btn-primary np-fs-gate__btn"></button>' +
                '</div>';
            mountRoot().appendChild(gate);
            gate.querySelector('.np-fs-gate__btn').addEventListener('click', function() {
                enterFullscreen(cfg);
            });
        }
        const str = cfg.strings || {};
        gate.querySelector('.np-fs-gate__title').textContent =
            str.fullscreenRequired || 'Fullscreen required';
        gate.querySelector('.np-fs-gate__body').textContent =
            str.fullscreenRequiredBody || 'Return to fullscreen to continue the assessment.';
        gate.querySelector('.np-fs-gate__btn').textContent =
            str.fullscreenReturn || 'Enter fullscreen';
        gate.classList.add('is-visible');
        document.body.classList.add('np-fs-blocked');
        state.fsGateShown = true;
        remountChrome();
    };

    const hideFullscreenGate = function() {
        const gate = document.getElementById('np-fs-gate');
        if (gate) {
            gate.classList.remove('is-visible');
        }
        document.body.classList.remove('np-fs-blocked');
        state.fsGateShown = false;
    };

    const needsMic = function(cfg) {
        return flag(cfg.settings.requiremic) || flag(cfg.settings.detectnoise);
    };

    const trackLive = function(stream, kind) {
        if (!stream) {
            return false;
        }
        return stream.getTracks().some(function(t) {
            return t.kind === kind && t.readyState === 'live';
        });
    };

    const anyDeviceIssue = function() {
        return !!(state.deviceIssues.camera || state.deviceIssues.mic || state.deviceIssues.screen);
    };

    const syncDeviceIssuesFromStreams = function(cfg) {
        const s = cfg.settings;
        if (flag(s.requirecamera) && !trackLive(state.cameraStream, 'video')) {
            state.deviceIssues.camera = true;
        } else if (trackLive(state.cameraStream, 'video')) {
            state.deviceIssues.camera = false;
        }
        if (needsMic(cfg) && !trackLive(state.cameraStream, 'audio')) {
            state.deviceIssues.mic = true;
        } else if (trackLive(state.cameraStream, 'audio')) {
            state.deviceIssues.mic = false;
        }
        if (flag(s.requirescreenshare) && !trackLive(state.screenStream, 'video')) {
            state.deviceIssues.screen = true;
        } else if (trackLive(state.screenStream, 'video')) {
            state.deviceIssues.screen = false;
        }
    };

    const hideDeviceGate = function() {
        const gate = document.getElementById('np-device-gate');
        if (gate) {
            gate.classList.remove('is-visible');
        }
        document.body.classList.remove('np-device-blocked');
        state.deviceGateShown = false;
    };

    const refreshDeviceGate = function(cfg) {
        if (state.stopping || !state.running) {
            hideDeviceGate();
            return;
        }
        syncDeviceIssuesFromStreams(cfg);
        if (!anyDeviceIssue()) {
            hideDeviceGate();
            return;
        }

        const str = cfg.strings || {};
        let gate = document.getElementById('np-device-gate');
        if (!gate) {
            gate = document.createElement('div');
            gate.id = 'np-device-gate';
            gate.className = 'np-device-gate';
            gate.innerHTML =
                '<div class="np-device-gate__card" role="dialog" aria-modal="true">' +
                    '<h3 class="np-device-gate__title"></h3>' +
                    '<p class="np-device-gate__body"></p>' +
                    '<ul class="np-device-gate__list"></ul>' +
                    '<div class="np-device-gate__actions">' +
                        '<button type="button" class="btn btn-primary" data-np-fix-av></button>' +
                        '<button type="button" class="btn btn-primary" data-np-fix-screen></button>' +
                    '</div>' +
                    '<p class="np-device-gate__hint"></p>' +
                '</div>';
            mountRoot().appendChild(gate);
            gate.querySelector('[data-np-fix-av]').addEventListener('click', function() {
                restoreCameraMic(cfg);
            });
            gate.querySelector('[data-np-fix-screen]').addEventListener('click', function() {
                restoreScreen(cfg);
            });
        }

        const issues = [];
        if (state.deviceIssues.camera) {
            issues.push(str.cameraLost || 'Camera disconnected');
        }
        if (state.deviceIssues.mic) {
            issues.push(str.micLost || 'Microphone disconnected');
        }
        if (state.deviceIssues.screen) {
            issues.push(str.screenLost || 'Screen share stopped');
        }

        gate.querySelector('.np-device-gate__title').textContent =
            str.deviceRequiredTitle || 'Proctoring interrupted';
        gate.querySelector('.np-device-gate__body').textContent =
            str.deviceRequiredBody ||
            'Required devices were stopped. Restore them to continue the assessment. You cannot proceed until this is fixed.';
        gate.querySelector('.np-device-gate__list').innerHTML = issues.map(function(item) {
            return '<li>' + item + '</li>';
        }).join('');
        gate.querySelector('.np-device-gate__hint').textContent =
            str.deviceRequiredHint || 'The quiz is locked until camera, microphone, and screen share are active again.';

        const avBtn = gate.querySelector('[data-np-fix-av]');
        const scBtn = gate.querySelector('[data-np-fix-screen]');
        const needAv = state.deviceIssues.camera || state.deviceIssues.mic;
        const needSc = state.deviceIssues.screen;
        avBtn.hidden = !needAv;
        scBtn.hidden = !needSc;
        avBtn.textContent = str.deviceRestoreAv || 'Restore camera & microphone';
        scBtn.textContent = str.deviceRestoreScreen || 'Restore screen share';

        gate.classList.add('is-visible');
        document.body.classList.add('np-device-blocked');
        state.deviceGateShown = true;
        remountChrome();
    };

    const markDeviceLost = function(cfg, kind, eventType, severity) {
        if (state.stopping) {
            return;
        }
        if (kind === 'camera') {
            state.deviceIssues.camera = true;
        } else if (kind === 'mic') {
            state.deviceIssues.mic = true;
        } else if (kind === 'screen') {
            state.deviceIssues.screen = true;
        }
        logEvent(eventType, severity);
        refreshDeviceGate(cfg);
    };

    const bindCameraTrackEnded = function(cfg) {
        if (!state.cameraStream) {
            return;
        }
        state.cameraStream.getTracks().forEach(function(t) {
            t.onended = function() {
                if (state.stopping) {
                    return;
                }
                if (t.kind === 'video' && flag(cfg.settings.requirecamera)) {
                    markDeviceLost(cfg, 'camera', 'camera_lost', 'danger');
                } else if (t.kind === 'audio' && needsMic(cfg)) {
                    markDeviceLost(cfg, 'mic', 'mic_lost', 'warning');
                }
            };
        });
    };

    const bindScreenTrackEnded = function(cfg) {
        if (!state.screenStream) {
            return;
        }
        state.screenStream.getVideoTracks().forEach(function(t) {
            t.onended = function() {
                if (state.stopping) {
                    return;
                }
                if (flag(cfg.settings.requirescreenshare)) {
                    markDeviceLost(cfg, 'screen', 'screenshare_lost', 'danger');
                }
            };
        });
    };

    const restoreCameraMic = async function(cfg) {
        const gate = document.getElementById('np-device-gate');
        const hint = gate && gate.querySelector('.np-device-gate__hint');
        const str = cfg.strings || {};
        const s = cfg.settings;
        const needVideo = flag(s.requirecamera) || state.deviceIssues.camera;
        const needAudio = needsMic(cfg) || state.deviceIssues.mic;
        if (hint) {
            hint.textContent = str.deviceRestoring || 'Requesting camera / microphone…';
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: needVideo ? {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 720}} : false,
                audio: needAudio ? {
                    echoCancellation: false,
                    noiseSuppression: false,
                    autoGainControl: true
                } : false
            });
            const old = state.cameraStream;
            state.cameraStream = stream;
            ensureHud(cfg);
            if (state.video) {
                state.video.srcObject = stream;
                const camWrap = document.getElementById('np-monitor-cam-wrap');
                if (camWrap) {
                    camWrap.classList.add('has-stream');
                }
                await state.video.play().catch(function() {
                    // Ignore.
                });
            }
            stopTracks(old);
            if (needAudio && stream.getAudioTracks().length) {
                setupNoise(stream, cfg);
            } else {
                teardownNoise();
            }
            bindCameraTrackEnded(cfg);
            state.deviceIssues.camera = !!(needVideo && !trackLive(stream, 'video'));
            state.deviceIssues.mic = !!(needAudio && !trackLive(stream, 'audio'));
            refreshDeviceGate(cfg);
        } catch (err) {
            if (hint) {
                hint.textContent = (err && err.message) ||
                    (str.deviceRestoreFailed || 'Could not restore devices. Click again and allow access.');
            }
        }
    };

    const restoreScreen = async function(cfg) {
        const gate = document.getElementById('np-device-gate');
        const hint = gate && gate.querySelector('.np-device-gate__hint');
        const str = cfg.strings || {};
        if (hint) {
            hint.textContent = str.deviceRestoringScreen || 'Choose entire screen to share…';
        }
        try {
            let stream;
            try {
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        displaySurface: 'monitor',
                        width: {ideal: 1920},
                        height: {ideal: 1080},
                        frameRate: {ideal: 5, max: 15}
                    },
                    audio: false,
                    preferCurrentTab: false,
                    selfBrowserSurface: 'exclude',
                    surfaceSwitching: 'include',
                    monitorTypeSurfaces: 'include'
                });
            } catch (e1) {
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {displaySurface: 'monitor'},
                    audio: false
                });
            }
            const vtrack = stream.getVideoTracks()[0];
            const settings = vtrack && vtrack.getSettings ? vtrack.getSettings() : {};
            if (settings.displaySurface && settings.displaySurface !== 'monitor') {
                stopTracks(stream);
                throw new Error(str.needScreen || 'Please share your entire screen (not a window or tab).');
            }
            const old = state.screenStream;
            state.screenStream = stream;
            if (!state.screenVideo) {
                state.screenVideo = document.createElement('video');
                state.screenVideo.autoplay = true;
                state.screenVideo.muted = true;
                state.screenVideo.playsInline = true;
                state.screenVideo.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0';
                mountRoot().appendChild(state.screenVideo);
            }
            state.screenVideo.srcObject = stream;
            await state.screenVideo.play().catch(function() {
                // Ignore.
            });
            stopTracks(old);
            bindScreenTrackEnded(cfg);
            state.deviceIssues.screen = !trackLive(stream, 'video');
            refreshDeviceGate(cfg);
        } catch (err) {
            if (hint) {
                hint.textContent = (err && err.message) ||
                    (str.deviceRestoreFailed || 'Could not restore screen share. Click again and allow access.');
            }
        }
    };

    const enterFullscreen = async function(cfg) {
        // Prefer documentElement so fixed HUD/camera stay visible.
        const el = document.documentElement;
        try {
            if (!document.fullscreenElement) {
                if (el.requestFullscreen) {
                    await el.requestFullscreen();
                } else if (el.webkitRequestFullscreen) {
                    el.webkitRequestFullscreen();
                }
            }
        } catch (e) {
            // User gesture may be required — gate stays visible.
        }
        remountChrome();
        if (document.fullscreenElement) {
            hideFullscreenGate();
        }
    };

    const onFullscreenChange = function(cfg) {
        remountChrome();
        resumeNoiseAudio();
        if (!flag(cfg.settings.requirefullscreen) || state.stopping || !state.running) {
            return;
        }
        if (!document.fullscreenElement) {
            logEvent('fullscreen_exit', 'warning');
            // CodeRunner Run/Submit often drops fullscreen briefly — auto-recover without blocking.
            if (Date.now() < state.fsGraceUntil) {
                window.setTimeout(function() {
                    if (!state.stopping && state.running && !document.fullscreenElement) {
                        enterFullscreen(cfg);
                    }
                }, 80);
                return;
            }
            showFullscreenGate(cfg);
        } else {
            hideFullscreenGate();
            // Fullscreen re-entry often leaves AudioContext suspended.
            if (flag(cfg.settings.detectnoise) && state.cameraStream) {
                window.setTimeout(function() {
                    if (!state.stopping) {
                        setupNoise(state.cameraStream, cfg);
                    }
                }, 300);
            }
        }
    };

    /** True when click is CodeRunner run / check / submit (may exit fullscreen). */
    const isCodingActionClick = function(target) {
        if (!target || !target.closest) {
            return false;
        }
        const el = target.closest('button, input[type="submit"], input[type="button"], a.btn, .btn');
        if (!el) {
            return false;
        }
        const label = (
            (el.getAttribute('name') || '') + ' ' +
            (el.getAttribute('value') || '') + ' ' +
            (el.getAttribute('data-ll-custom-run') != null ? 'run' : '') + ' ' +
            (el.className || '') + ' ' +
            (el.textContent || '')
        ).toLowerCase();
        if (/precheck|ll-cr-btn--run|data-ll-custom-run|▶|\brun\b|\bcheck\b|\bsubmit\b|\bcompile\b/.test(label)) {
            return true;
        }
        if (el.closest('.ll-cr-actions, .ll-custom-test, .coderunner, .que.coderunner')) {
            return /run|check|submit|precheck|compile/.test(label);
        }
        return false;
    };

    const armFullscreenGrace = function(ms) {
        state.fsGraceUntil = Date.now() + (ms || 3500);
    };

    /**
     * NexProctor face engine (MediaPipe Face Detector).
     * Returns face count: 0 = no face, 1 = one face, 2+ = multiple.
     */
    const detectFaceCount = async function(video) {
        if (!video || video.readyState < 2 || !video.videoWidth) {
            return state.lastFaceCount;
        }
        try {
            if (!state.faceEngineReady) {
                state.faceEngineReady = !!(await FaceDetect.init());
            }
            if (!state.faceEngineReady) {
                return state.lastFaceCount;
            }
            const n = await FaceDetect.countFaces(video);
            state.lastFaceCount = n;
            return n;
        } catch (e) {
            return state.lastFaceCount;
        }
    };

    const checkFaces = async function(cfg) {
        if (!flag(cfg.settings.detectfaces) || !state.video || state.stopping) {
            return;
        }
        const count = await detectFaceCount(state.video);
        const now = Date.now();

        if (count === 0) {
            state.faceMissStreak++;
            state.multiFaceStreak = 0;
            // ~8 ticks @ 2s ≈ 16s without a confirmed face (reduces false alarms).
            if (state.faceMissStreak >= 8 && now > state.noFaceCooldownUntil) {
                state.noFaceCooldownUntil = now + 12000;
                state.faceMissStreak = 0;
                const jpg = flag(cfg.settings.photoonviolation)
                    ? grabJpeg(state.video, 0.92)
                    : null;
                if (jpg) {
                    uploadEvidence('snapshot', jpg, 'no_face', 'warning');
                } else {
                    logEvent('no_face', 'warning');
                }
            }
        } else if (count >= 2) {
            state.faceMissStreak = 0;
            state.multiFaceStreak++;
            // Require sustained multi-face (not a one-frame flicker / monitor ghost).
            if (state.multiFaceStreak >= 3 && now > state.multiFaceCooldownUntil) {
                state.multiFaceCooldownUntil = now + 15000;
                state.multiFaceStreak = 0;
                const jpg = flag(cfg.settings.photoonviolation)
                    ? grabJpeg(state.video, 0.92)
                    : null;
                if (jpg) {
                    uploadEvidence('snapshot', jpg, 'multi_face', 'danger');
                } else {
                    logEvent('multi_face', 'danger');
                }
            }
        } else {
            state.faceMissStreak = 0;
            state.multiFaceStreak = 0;
            // Attention: face present but box near edge / tiny.
            if (flag(cfg.settings.detectattention)) {
                try {
                    const det = await FaceDetect.detect(state.video);
                    if (det.faces && det.faces[0]) {
                        const box = det.faces[0];
                        const vw = state.video.videoWidth || 1;
                        const vh = state.video.videoHeight || 1;
                        const area = (box.width * box.height) / (vw * vh);
                        const cx = box.x + box.width / 2;
                        const centered = cx > vw * 0.12 && cx < vw * 0.88;
                        if (area < 0.03 || !centered) {
                            state.awayStreak++;
                            if (state.awayStreak >= 4 && now > state.noFaceCooldownUntil) {
                                state.awayStreak = 0;
                                const jpg = grabJpeg(state.video, 0.92);
                                if (jpg) {
                                    uploadEvidence('snapshot', jpg, 'looking_away', 'warning');
                                } else {
                                    logEvent('looking_away', 'warning');
                                }
                            }
                        } else {
                            state.awayStreak = 0;
                        }
                    }
                } catch (eAtt) {
                    state.awayStreak = 0;
                }
            }
        }
    };

    const teardownNoise = function() {
        state.noiseEnabled = false;
        if (state.noiseTimer) {
            window.clearTimeout(state.noiseTimer);
            state.noiseTimer = null;
        }
        if (state.audioCtx) {
            try {
                state.audioCtx.close();
            } catch (e) {
                // Ignore.
            }
        }
        state.audioCtx = null;
        state.analyser = null;
    };

    const resumeNoiseAudio = function() {
        if (!state.audioCtx) {
            return;
        }
        if (state.audioCtx.state === 'suspended') {
            state.audioCtx.resume().catch(function() {
                // Ignore — next user gesture will retry.
            });
        }
    };

    const setupNoise = function(stream, cfg) {
        if (!flag(cfg.settings.detectnoise) || !stream || state.stopping) {
            return;
        }
        const audioTracks = stream.getAudioTracks().filter(function(t) {
            return t.readyState === 'live';
        });
        if (!audioTracks.length) {
            return;
        }

        // Always rebuild — AudioContext often dies after fullscreen / tab blur.
        teardownNoise();

        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            const ctx = new Ctx();
            const source = ctx.createMediaStreamSource(new MediaStream(audioTracks));
            const analyser = ctx.createAnalyser();
            analyser.fftSize = 2048;
            analyser.smoothingTimeConstant = 0.3;
            source.connect(analyser);
            state.audioCtx = ctx;
            state.analyser = analyser;
            state.noiseEnabled = true;

            resumeNoiseAudio();

            const timeData = new Uint8Array(analyser.fftSize);
            const freqData = new Uint8Array(analyser.frequencyBinCount);

            const tick = function() {
                // Keep the loop alive until stop(); only skip analysis when not ready.
                if (state.stopping || !state.noiseEnabled) {
                    return;
                }
                state.noiseTimer = window.setTimeout(tick, 250);

                if (!state.running || !state.analyser || !state.audioCtx) {
                    return;
                }
                resumeNoiseAudio();
                if (state.audioCtx.state !== 'running') {
                    return;
                }

                state.analyser.getByteTimeDomainData(timeData);
                let sum = 0;
                let peak = 0;
                for (let i = 0; i < timeData.length; i++) {
                    const v = (timeData[i] - 128) / 128;
                    sum += v * v;
                    const a = Math.abs(v);
                    if (a > peak) {
                        peak = a;
                    }
                }
                const rms = Math.sqrt(sum / timeData.length);

                // Mid-band energy helps catch speech even when RMS is soft.
                state.analyser.getByteFrequencyData(freqData);
                let mid = 0;
                const midFrom = Math.floor(freqData.length * 0.08);
                const midTo = Math.floor(freqData.length * 0.45);
                for (let i = midFrom; i < midTo; i++) {
                    mid += freqData[i];
                }
                mid = mid / Math.max(1, (midTo - midFrom)) / 255;

                const now = Date.now();
                // Speech / talk: RMS or peak or mid-band voice energy.
                const loud = rms > 0.028 || peak > 0.12 || mid > 0.18;
                if (loud && now > state.noiseCooldownUntil) {
                    state.noiseCooldownUntil = now + 10000;
                    recordNoiseClip(stream);
                }
            };
            tick();
        } catch (e) {
            state.noiseEnabled = false;
            // eslint-disable-next-line no-console
            console.warn('NexProctor noise setup failed', e);
        }
    };

    const pickAudioMime = function() {
        if (!window.MediaRecorder) {
            return '';
        }
        const candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4'
        ];
        for (let i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    };

    const recordNoiseClip = function(stream) {
        try {
            const tracks = (stream && stream.getAudioTracks)
                ? stream.getAudioTracks().filter(function(t) {
                    return t.readyState === 'live';
                })
                : [];
            if (!tracks.length || !window.MediaRecorder) {
                logEvent('noise_detected', 'warning');
                return;
            }
            const audioOnly = new MediaStream(tracks);
            const mime = pickAudioMime();
            const rec = mime
                ? new MediaRecorder(audioOnly, {mimeType: mime})
                : new MediaRecorder(audioOnly);
            const chunks = [];
            rec.ondataavailable = function(ev) {
                if (ev.data && ev.data.size) {
                    chunks.push(ev.data);
                }
            };
            rec.onstop = function() {
                if (!chunks.length) {
                    logEvent('noise_detected', 'warning');
                    return;
                }
                const blob = new Blob(chunks, {type: mime || 'audio/webm'});
                const reader = new FileReader();
                reader.onloadend = function() {
                    uploadEvidence('audioclip', reader.result, 'noise_detected', 'warning');
                };
                reader.readAsDataURL(blob);
            };
            rec.start(200);
            window.setTimeout(function() {
                try {
                    if (rec.state === 'recording') {
                        rec.stop();
                    }
                } catch (e2) {
                    logEvent('noise_detected', 'warning');
                }
            }, 3500);
        } catch (e) {
            logEvent('noise_detected', 'warning');
        }
    };

    const onTabHidden = function(cfg) {
        if (!flag(cfg.settings.detecttabswitch) || state.stopping) {
            return;
        }
        const now = Date.now();
        if (now < state.tabCooldownUntil) {
            return;
        }
        state.tabCooldownUntil = now + 4000;

        logEvent('tab_hidden', 'warning').then(function(res) {
            const eid = (res && res.eventid) ? res.eventid : 0;
            const capture = async function() {
                if (state.stopping) {
                    return;
                }
                let screenFrame = null;
                if (state.screenStream) {
                    screenFrame = await grabFromStream(state.screenStream, state.screenVideo, 0.92);
                } else if (state.screenVideo) {
                    screenFrame = grabJpeg(state.screenVideo, 0.92);
                }
                if (screenFrame) {
                    await uploadEvidence('screengrab', screenFrame, eid ? '' : 'tab_hidden', 'warning', eid);
                }
                // Webcam still — always useful when screen grab is black/frozen.
                if (state.cameraStream || state.video) {
                    const cam = state.cameraStream
                        ? await grabFromStream(state.cameraStream, state.video, 0.92)
                        : grabJpeg(state.video, 0.92);
                    if (cam) {
                        await uploadEvidence('snapshot', cam, eid ? '' : 'tab_hidden', 'warning', eid);
                    }
                }
            };
            // Immediate + delayed (after OS paints the other surface).
            capture();
            window.setTimeout(capture, 500);
        });
    };

    const checkMultiMonitor = async function(cfg) {
        if (!flag(cfg.settings.blockmultimonitor) || state.stopping) {
            return;
        }
        try {
            if (window.getScreenDetails) {
                const details = await window.getScreenDetails();
                if ((details.screens || []).length > 1) {
                    logEvent('multi_monitor', 'danger');
                }
            } else if (window.screen && window.screen.isExtended) {
                logEvent('multi_monitor', 'danger');
            }
        } catch (e) {
            // Ignore.
        }
    };

    const heartbeat = function(cfg) {
        if (state.stopping) {
            return;
        }
        const jpg = grabJpeg(state.video);
        if (jpg) {
            uploadEvidence('snapshot', jpg, 'heartbeat', 'info').then(function(res) {
                if (res && res.trustscore != null) {
                    setScore(res.trustscore);
                }
            });
        } else {
            logEvent('heartbeat', 'info');
        }
    };

    const schedule = function(fn, ms) {
        const id = window.setInterval(fn, ms);
        state.timers.push(id);
        return id;
    };

    const stopTracks = function(stream) {
        if (!stream) {
            return;
        }
        try {
            stream.getTracks().forEach(function(t) {
                t.stop();
            });
        } catch (e) {
            // Ignore.
        }
    };

    const stop = function(options) {
        options = options || {};
        // Soft leave (back / reload / summary): keep server session so trust resumes.
        // Hard end only when the attempt is actually finished.
        const endServer = !!options.endServer;

        if (state.stopping) {
            return;
        }
        state.stopping = true;
        state.running = false;
        state.timers.forEach(function(id) {
            window.clearInterval(id);
        });
        state.timers = [];
        hideFullscreenGate();
        hideDeviceGate();

        const sid = state.sessionid;
        state.sessionid = 0;

        stopTracks(state.cameraStream);
        stopTracks(state.screenStream);
        state.cameraStream = null;
        state.screenStream = null;
        teardownNoise();

        const hud = document.getElementById('np-monitor-hud');
        const camWrap = document.getElementById('np-monitor-cam-wrap');
        const cam = document.getElementById('np-monitor-cam');
        const gate = document.getElementById('np-fs-gate');
        const deviceGate = document.getElementById('np-device-gate');
        if (hud) {
            hud.remove();
        }
        if (camWrap) {
            camWrap.remove();
        } else if (cam) {
            cam.remove();
        }
        if (gate) {
            gate.remove();
        }
        if (deviceGate) {
            deviceGate.remove();
        }
        state.video = null;
        state.deviceIssues = {camera: false, mic: false, screen: false};

        // Also remove start gate on stop.
        const startGate = document.getElementById('np-start-gate');
        if (startGate) {
            startGate.remove();
        }
        document.documentElement.classList.remove('np-attempt-gated');
        document.body.classList.remove('np-attempt-gated');
        document.body.classList.remove('np-fs-blocked');
        document.body.classList.remove('np-device-blocked');

        if (sid && endServer) {
            clearGateComplete(state.cfg);
            call('local_nexproctor_end_session', {sessionid: sid}).catch(function() {
                return null;
            });
        }
    };

    const setGateStep = function(id, status, detail) {
        const li = document.querySelector('#np-start-gate [data-step="' + id + '"]');
        if (!li) {
            return;
        }
        li.classList.remove('is-ok', 'is-bad', 'is-active', 'is-pending');
        li.classList.add(status === 'ok' ? 'is-ok' : (status === 'bad' ? 'is-bad' : (status === 'active' ? 'is-active' : 'is-pending')));
        const d = li.querySelector('.np-gate__detail');
        if (d && detail) {
            d.textContent = detail;
        }
        const steps = document.querySelectorAll('#np-start-gate .np-gate__step');
        let done = 0;
        steps.forEach(function(el) {
            if (el.classList.contains('is-ok')) {
                done++;
            }
        });
        const fill = document.querySelector('#np-start-gate #np-start-fill');
        if (fill && steps.length) {
            fill.style.width = Math.round((done / steps.length) * 100) + '%';
        }
    };

    const showStartGate = function(cfg) {
        document.documentElement.classList.add('np-attempt-gated');
        document.body.classList.add('np-attempt-gated');
        let gate = document.getElementById('np-start-gate');
        if (gate) {
            return gate;
        }
        const s = cfg.settings;
        const str = cfg.strings || {};
        const steps = [];
        if (flag(s.blockmultimonitor)) {
            steps.push(['monitor', str.checkMonitor || 'Single monitor']);
        }
        if (flag(s.requirecamera)) {
            steps.push(['camera', str.checkCamera || 'Camera']);
        }
        if (flag(s.requiremic) || flag(s.detectnoise)) {
            steps.push(['mic', str.checkMic || 'Microphone']);
        }
        if (flag(s.detectfaces) && (flag(s.requirecamera) || flag(s.detectfaces))) {
            steps.push(['face', str.checkFace || 'Face check']);
        }
        if (flag(s.requirescreenshare)) {
            steps.push(['screen', str.checkScreen || 'Screen share']);
        }
        if (flag(s.requirefullscreen)) {
            steps.push(['fullscreen', str.checkFullscreen || 'Fullscreen']);
        }

        gate = document.createElement('div');
        gate.id = 'np-start-gate';
        const dark = document.body.classList.contains('theme-dark')
            || document.body.classList.contains('dark-mode')
            || document.body.classList.contains('ll-arena-dark')
            || document.body.classList.contains('ll-arena-mode-dark')
            || document.documentElement.classList.contains('theme-dark')
            || document.documentElement.getAttribute('data-bs-theme') === 'dark';
        gate.className = 'np-start-gate' + (dark ? ' np-start-gate--dark' : ' np-start-gate--light');
        gate.innerHTML =
            '<div class="np-start-gate__card">' +
                '<div class="np-start-gate__brand">NexProctor</div>' +
                '<h2 class="np-start-gate__title">' + (str.gateTitle || 'Preparing secure attempt') + '</h2>' +
                '<p class="np-start-gate__sub">' + (str.gateSub || 'Enable required tracking before the assessment starts.') + '</p>' +
                '<div class="np-progress__bar"><div class="np-progress__fill" id="np-start-fill"></div></div>' +
                '<ul class="np-gate__steps">' +
                steps.map(function(pair) {
                    return '<li class="np-gate__step is-pending" data-step="' + pair[0] + '">' +
                        '<span class="np-gate__icon" aria-hidden="true"></span>' +
                        '<div><div class="np-gate__label">' + pair[1] + '</div>' +
                        '<div class="np-gate__detail"></div></div></li>';
                }).join('') +
                '</ul>' +
                '<div class="np-start-gate__preview" id="np-start-preview"></div>' +
                '<p class="np-gate__status" id="np-start-status"></p>' +
                '<button type="button" class="btn btn-primary" id="np-start-retry" hidden>' +
                    (str.retry || 'Fix & retry') +
                '</button>' +
            '</div>';
        (document.fullscreenElement || document.body).appendChild(gate);
        return gate;
    };

    const hideStartGate = function() {
        const gate = document.getElementById('np-start-gate');
        if (gate) {
            gate.remove();
        }
        document.documentElement.classList.remove('np-attempt-gated');
        document.body.classList.remove('np-attempt-gated');
    };

    const setStartStatus = function(msg, ok) {
        const el = document.getElementById('np-start-status');
        if (!el) {
            return;
        }
        el.textContent = msg || '';
        el.className = 'np-gate__status' + (ok === true ? ' is-ok' : (ok === false ? ' is-bad' : ''));
    };

    const ensureMonitorCheck = async function(cfg) {
        const str = cfg.strings || {};
        if (!flag(cfg.settings.blockmultimonitor)) {
            return;
        }
        let ok = true;
        try {
            if (window.getScreenDetails) {
                const details = await window.getScreenDetails();
                ok = (details.screens || []).length <= 1;
            } else if (window.screen && window.screen.isExtended) {
                ok = false;
            }
        } catch (e) {
            ok = true;
        }
        if (!ok) {
            throw new Error(str.multiMonitor || 'Multiple monitors detected');
        }
    };

    const attachStreamsFromFlow = async function(cfg, streams) {
        const s = cfg.settings;
        const str = cfg.strings || {};
        streams = streams || {};

        state.cameraStream = streams.cameraStream || null;
        state.screenStream = streams.screenStream || null;

        const needVideo = flag(s.requirecamera) || flag(s.detectfaces);
        const needAudio = flag(s.requiremic) || flag(s.detectnoise);

        if (state.cameraStream) {
            if (needVideo) {
                ensureHud(cfg);
                if (state.video) {
                    state.video.srcObject = state.cameraStream;
                    await state.video.play().catch(function() {
                        // Ignore.
                    });
                }
            }
            if (needAudio && !state.cameraStream.getAudioTracks().length) {
                throw new Error(str.needMic || 'Microphone required');
            }
            bindCameraTrackEnded(cfg);
        }

        if (state.screenStream) {
            if (!state.screenVideo) {
                state.screenVideo = document.createElement('video');
                state.screenVideo.autoplay = true;
                state.screenVideo.muted = true;
                state.screenVideo.playsInline = true;
                state.screenVideo.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0';
                mountRoot().appendChild(state.screenVideo);
            }
            state.screenVideo.srcObject = state.screenStream;
            await state.screenVideo.play().catch(function() {
                // Ignore.
            });
            bindScreenTrackEnded(cfg);
        }

        if (flag(s.requirefullscreen) && !document.fullscreenElement) {
            await enterFullscreen(cfg);
            if (!document.fullscreenElement) {
                throw new Error(str.needFullscreen || 'Fullscreen required');
            }
        }
        remountChrome();
    };

    const showResumeOverlay = function(msg) {
        let el = document.getElementById('np-resume-overlay');
        if (!el) {
            el = document.createElement('div');
            el.id = 'np-resume-overlay';
            el.className = 'np-flow-overlay';
            el.innerHTML =
                '<div class="np-flow-modal" role="status">' +
                    '<div class="np-flow-modal__body">' +
                        '<p class="np-flow-status" id="np-resume-msg"></p>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(el);
        }
        const m = el.querySelector('#np-resume-msg');
        if (m) {
            m.textContent = msg || 'Reconnecting proctoring…';
        }
        el.style.display = 'flex';
    };

    const hideResumeOverlay = function() {
        const el = document.getElementById('np-resume-overlay');
        if (el) {
            el.style.display = 'none';
        }
    };

    /**
     * Re-acquire camera/screen after reload without the full start wizard.
     */
    const quickRestoreStreams = async function(cfg) {
        const s = cfg.settings;
        const str = cfg.strings || {};
        const needVideo = flag(s.requirecamera) || flag(s.detectfaces);
        const needAudio = flag(s.requiremic) || flag(s.detectnoise);

        if (streamsReady(cfg)) {
            return;
        }

        showResumeOverlay(str.resuming || 'Reconnecting proctoring…');

        if ((needVideo || needAudio) && !trackLive(state.cameraStream, needVideo ? 'video' : 'audio')) {
            stopTracks(state.cameraStream);
            state.cameraStream = await navigator.mediaDevices.getUserMedia({
                video: needVideo ? {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 720}} : false,
                audio: needAudio ? {
                    echoCancellation: false,
                    noiseSuppression: false,
                    autoGainControl: true
                } : false
            });
            bindCameraTrackEnded(cfg);
        }

        if (flag(s.requirescreenshare) && !trackLive(state.screenStream, 'video')) {
            stopTracks(state.screenStream);
            try {
                state.screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {displaySurface: 'monitor', width: {ideal: 1920}, height: {ideal: 1080}},
                    audio: false,
                    preferCurrentTab: false,
                    selfBrowserSurface: 'exclude'
                });
            } catch (e1) {
                state.screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {displaySurface: 'monitor'},
                    audio: false
                });
            }
            bindScreenTrackEnded(cfg);
        }

        await attachStreamsFromFlow(cfg, {
            cameraStream: state.cameraStream,
            screenStream: state.screenStream
        });

        if (flag(s.requirefullscreen) && !document.fullscreenElement) {
            await enterFullscreen(cfg);
        }

        hideResumeOverlay();
    };

    const resumeMonitoring = async function(cfg) {
        document.documentElement.classList.remove('np-attempt-gated');
        document.body.classList.remove('np-attempt-gated');
        hideResumeOverlay();
        const flowOverlay = document.getElementById('np-flow-overlay');
        if (flowOverlay) {
            flowOverlay.remove();
        }
        const retryGate = document.getElementById('np-flow-retry-gate');
        if (retryGate) {
            retryGate.remove();
        }

        try {
            if (!state.sessionid) {
                const res = await call('local_nexproctor_start_session', {
                    cmid: cfg.cmid,
                    quizid: cfg.quizid,
                    attemptid: cfg.attemptid || 0
                });
                state.sessionid = res.sessionid;
                setScore(res.trustscore != null ? res.trustscore : 100);
            }

            if (!streamsReady(cfg)) {
                await quickRestoreStreams(cfg);
            } else {
                await attachStreamsFromFlow(cfg, {
                    cameraStream: state.cameraStream,
                    screenStream: state.screenStream
                });
            }

            if (!state.bound) {
                beginMonitoring(cfg);
            } else {
                ensureHud(cfg);
                if (state.cameraStream && state.video) {
                    state.video.srcObject = state.cameraStream;
                    state.video.play().catch(function() {
                        // Ignore.
                    });
                }
                remountChrome();
            }
        } catch (err) {
            hideResumeOverlay();
            Notification.exception(err);
        }
    };

    const beginMonitoring = function(cfg) {
        markGateComplete(cfg);
        hideStartGate();
        ensureHud(cfg);
        // Prefetch local face cascade while HUD mounts.
        if (flag(cfg.settings.detectfaces)) {
            FaceDetect.init().then(function(ok) {
                state.faceEngineReady = !!ok;
            });
        }
        // Re-bind camera to HUD video after gate preview.
        if (state.cameraStream && state.video) {
            state.video.srcObject = state.cameraStream;
            state.video.play().catch(function() {
                // Ignore.
            });
        }
        // Build noise analyser AFTER fullscreen/start-gate (fresh AudioContext).
        if (flag(cfg.settings.detectnoise) && state.cameraStream) {
            setupNoise(state.cameraStream, cfg);
        }
        remountChrome();
        bindEvents(cfg);

        const hb = Math.max(15, parseInt(cfg.settings.heartbeatsecs, 10) || 45) * 1000;
        schedule(function() {
            heartbeat(cfg);
        }, hb);
        schedule(function() {
            if (Math.random() < 0.35) {
                const jpg = grabJpeg(state.video);
                if (jpg) {
                    uploadEvidence('snapshot', jpg, 'random_snapshot', 'info');
                }
            }
        }, Math.round(hb * 0.7));
        schedule(function() {
            checkFaces(cfg);
        }, 2000);
        // Immediate face check so violations aren't delayed.
        window.setTimeout(function() {
            checkFaces(cfg);
        }, 1200);
        schedule(function() {
            checkMultiMonitor(cfg);
        }, 20000);
        // Detect stopped mic / screen / camera even if 'ended' was missed.
        schedule(function() {
            if (state.stopping || !state.running) {
                return;
            }
            const before = anyDeviceIssue();
            syncDeviceIssuesFromStreams(cfg);
            if (anyDeviceIssue()) {
                if (!before) {
                    if (state.deviceIssues.camera) {
                        logEvent('camera_lost', 'danger');
                    }
                    if (state.deviceIssues.mic) {
                        logEvent('mic_lost', 'warning');
                    }
                    if (state.deviceIssues.screen) {
                        logEvent('screenshare_lost', 'danger');
                    }
                }
                refreshDeviceGate(cfg);
            } else if (state.deviceGateShown) {
                hideDeviceGate();
            }
        }, 2500);
    };

    const runStartGate = async function(cfg) {
        document.documentElement.classList.add('np-attempt-gated');
        document.body.classList.add('np-attempt-gated');
        try {
            await ensureMonitorCheck(cfg);
            const streams = await StartFlow.run(cfg, {});
            await attachStreamsFromFlow(cfg, streams);
            hideStartGate();
            beginMonitoring(cfg);
        } catch (err) {
            hideStartGate();
            stopTracks(state.cameraStream);
            stopTracks(state.screenStream);
            state.cameraStream = null;
            state.screenStream = null;
            document.documentElement.classList.add('np-attempt-gated');
            document.body.classList.add('np-attempt-gated');
            const msg = (err && err.message) || 'Could not enable devices';
            let retry = document.getElementById('np-flow-retry-gate');
            if (!retry) {
                retry = document.createElement('div');
                retry.id = 'np-flow-retry-gate';
                retry.className = 'np-flow-overlay';
                retry.innerHTML =
                    '<div class="np-flow-modal" role="dialog" aria-modal="true">' +
                        '<header class="np-flow-modal__head">' +
                            '<h2 class="np-flow-modal__title">' + (cfg.strings.startAttempt || 'Start attempt') + '</h2>' +
                        '</header>' +
                        '<div class="np-flow-modal__body">' +
                            '<p class="np-flow-status is-bad" id="np-flow-retry-msg"></p>' +
                        '</div>' +
                        '<footer class="np-flow-modal__foot">' +
                            '<button type="button" class="btn btn-primary" id="np-flow-retry-btn">' +
                                (cfg.strings.retry || 'Try again') +
                            '</button>' +
                        '</footer>' +
                    '</div>';
                document.body.appendChild(retry);
            }
            retry.querySelector('#np-flow-retry-msg').textContent = msg;
            retry.style.display = 'flex';
            retry.querySelector('#np-flow-retry-btn').onclick = function() {
                retry.style.display = 'none';
                runStartGate(cfg);
            };
        }
    };

    const looksLikeFinish = function(form, submitter) {
        if (!form) {
            return false;
        }
        if (isCodingActionClick(submitter || form)) {
            return false;
        }
        if (form.id === 'frm-endattempt' || /endattempt|finishattempt/i.test(form.id || '')) {
            return true;
        }
        const action = (form.getAttribute('action') || form.action || '').toString();
        if (!/processattempt/i.test(action) && form.id !== 'responseform'
            && !form.classList.contains('ll-arena-responseform')) {
            return false;
        }
        try {
            const fd = new FormData(form);
            if (fd.get('finishattempt') || String(fd.get('timeup') || '') === '1') {
                return true;
            }
        } catch (e) {
            // Fall through.
        }
        if (submitter) {
            const name = (submitter.getAttribute('name') || '').toString();
            const value = (submitter.getAttribute('value') || submitter.textContent || '').toString();
            if (name === 'finishattempt' || /finishattempt|submitallandfinish|submit all and finish/i.test(name + ' ' + value)) {
                return true;
            }
        }
        return false;
    };

    const bindEvents = function(cfg) {
        if (state.bound) {
            return;
        }
        state.bound = true;

        document.addEventListener('visibilitychange', function() {
            if (state.stopping || !state.running) {
                return;
            }
            if (document.hidden) {
                onTabHidden(cfg);
            } else {
                logEvent('tab_visible', 'info');
                resumeNoiseAudio();
            }
        });
        // Alt-tab / focus loss — treat as tab switch (debounced inside onTabHidden).
        window.addEventListener('blur', function() {
            if (state.stopping || !state.running) {
                return;
            }
            if (flag(cfg.settings.detecttabswitch)) {
                onTabHidden(cfg);
            }
        });
        window.addEventListener('focus', function() {
            resumeNoiseAudio();
        });
        // User gesture unlocks suspended AudioContext after fullscreen.
        document.addEventListener('pointerdown', function(e) {
            resumeNoiseAudio();
            if (isCodingActionClick(e.target)) {
                armFullscreenGrace(4000);
            }
        }, true);
        document.addEventListener('keydown', resumeNoiseAudio, true);
        document.addEventListener('click', function(e) {
            if (isCodingActionClick(e.target)) {
                armFullscreenGrace(4000);
            }
        }, true);
        document.addEventListener('fullscreenchange', function() {
            onFullscreenChange(cfg);
        });

        document.addEventListener('submit', function(e) {
            if (looksLikeFinish(e.target, e.submitter)) {
                stop({endServer: true});
            }
        }, true);

        // LL Assessment finish uses fetch (no form submit) — end proctoring there too.
        document.addEventListener('ll-assessment-finish', function() {
            stop({endServer: true});
        });

        document.addEventListener('click', function(e) {
            const t = e.target && e.target.closest
                ? e.target.closest('a, button, input[type="submit"]')
                : null;
            if (!t) {
                return;
            }
            if (isCodingActionClick(t) || t.closest('.ll-cr-ide, .coderunner, .que.coderunner')) {
                return;
            }
            const href = (t.getAttribute('href') || '').toString();
            const name = (t.getAttribute('name') || '').toString();
            const text = (t.textContent || '').toLowerCase();
            if (name === 'finishattempt' || /finish attempt|submit all and finish|end test/i.test(text)) {
                stop({endServer: true});
                return;
            }
            // Leaving attempt (summary / review) — stop media only; keep trust session.
            if (/summary\.php|review\.php/i.test(href)) {
                stop({endServer: false});
            }
        }, true);

        // Do not stop on pagehide/beforeunload — Run/Submit and fullscreen
        // transitions can fire those while the attempt is still open.
        // Leaving attempt.php is handled by the URL poll below.

        // Soft-nav / SPA-style leave from attempt URL.
        schedule(function() {
            if (state.running && !isAttemptUrl()) {
                stop({endServer: false});
            }
        }, 1500);
    };

    const init = function(cfg) {
        cfg = cfg || {};
        cfg.settings = cfg.settings || {};
        cfg.strings = cfg.strings || {};
        // Normalize flags so "0"/"1" and 0/1 all work.
        Object.keys(cfg.settings).forEach(function(k) {
            if (k === 'heartbeatsecs') {
                cfg.settings[k] = parseInt(cfg.settings[k], 10) || 45;
            } else if (typeof cfg.settings[k] !== 'object') {
                cfg.settings[k] = flag(cfg.settings[k]) ? 1 : 0;
            }
        });
        state.cfg = cfg;

        if (!isAttemptUrl()) {
            return;
        }

        // Soft-nav replays Moodle footer AMD — never restart after gate completed.
        if (state.gateComplete || readGateComplete(cfg)) {
            state.gateComplete = true;
            if (state.running) {
                remountChrome();
                return;
            }
            state.running = true;
            state.stopping = false;
            resumeMonitoring(cfg);
            return;
        }

        if (state.running) {
            return;
        }
        state.running = true;
        state.stopping = false;

        // Hide quiz UI until stepped start flow completes (StartFlow overlay replaces old gate).
        document.documentElement.classList.add('np-attempt-gated');
        document.body.classList.add('np-attempt-gated');

        call('local_nexproctor_start_session', {
            cmid: cfg.cmid,
            quizid: cfg.quizid,
            attemptid: cfg.attemptid || 0
        }).then(async function(res) {
            if (!isAttemptUrl()) {
                stop();
                return;
            }
            state.sessionid = res.sessionid;
            setScore(res.trustscore != null ? res.trustscore : 100);
            await runStartGate(cfg);
        }).catch(Notification.exception);
    };

    return {init: init, stop: stop, resume: resumeMonitoring};
});
