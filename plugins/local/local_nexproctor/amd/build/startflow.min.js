/**
 * NexProctor stepped start flow — permissions, webcam preview, setup, fullscreen.
 *
 * @module local_nexproctor/startflow
 */
define(['local_nexproctor/facedetect'], function(FaceDetect) {

    const flag = function(v) {
        return v === true || v === 1 || v === '1';
    };

    /**
     * Sample face count over several frames (reduces false "no face").
     *
     * @param {HTMLVideoElement} video
     * @param {number} samples
     * @return {Promise<number>} best stable count (0, 1, or 2+)
     */
    const verifyFace = async function(video, samples) {
        samples = samples || 5;
        if (!video) {
            return 0;
        }
        await FaceDetect.init();
        // Let auto-exposure settle.
        await new Promise(function(r) {
            window.setTimeout(r, 700);
        });
        const counts = {0: 0, 1: 0, 2: 0};
        for (let i = 0; i < samples; i++) {
            const n = await FaceDetect.countFaces(video);
            const k = n >= 2 ? 2 : n;
            counts[k]++;
            await new Promise(function(r) {
                window.setTimeout(r, 350);
            });
        }
        if (counts[1] >= 2) {
            return 1;
        }
        if (counts[0] >= Math.ceil(samples * 0.6)) {
            return 0;
        }
        if (counts[2] >= 2) {
            return 2;
        }
        return 1;
    };

    const permissionItems = function(cfg) {
        const s = cfg.settings || {};
        const items = [];
        if (flag(s.requirescreenshare)) {
            items.push('screen');
        }
        if (flag(s.requiremic) || flag(s.detectnoise)) {
            items.push('mic');
        }
        if (flag(s.requirecamera) || flag(s.detectfaces)) {
            items.push('camera');
        }
        return items;
    };

    const animateSetup = function(root, str, onDone) {
        const bar = root.querySelector('#np-flow-setup-bar');
        const pct = root.querySelector('#np-flow-setup-pct');
        const msg = root.querySelector('#np-flow-setup-msg');
        if (msg) {
            msg.textContent = str.setupWait ||
                'Please wait for up to a minute for the system to be set up.';
        }
        let p = 0;
        const tick = function() {
            p = Math.min(100, p + (p < 70 ? 8 : 4));
            if (bar) {
                bar.style.width = p + '%';
            }
            if (pct) {
                pct.textContent = p + '%';
            }
            if (p >= 100) {
                window.setTimeout(onDone, 350);
                return;
            }
            window.setTimeout(tick, p < 70 ? 120 : 180);
        };
        tick();
    };

    /**
     * Run the full stepped start wizard.
     *
     * @param {Object} cfg
     * @param {Object} hooks acquireStreams(cfg), enterFullscreen(cfg)
     * @return {Promise<Object>} {cameraStream, screenStream}
     */
    const run = function(cfg, hooks) {
        cfg = cfg || {};
        cfg.settings = cfg.settings || {};
        cfg.strings = cfg.strings || {};
        hooks = hooks || {};

        return new Promise(function(resolve, reject) {
            const str = cfg.strings;
            const s = cfg.settings;
            let consent1 = false;
            let consent2 = false;
            let cameraStream = null;
            let screenStream = null;

            const overlay = document.createElement('div');
            overlay.id = 'np-flow-overlay';
            overlay.className = 'np-flow-overlay';
            overlay.innerHTML =
                '<div class="np-flow-modal" role="dialog" aria-modal="true">' +
                    '<header class="np-flow-modal__head">' +
                        '<h2 class="np-flow-modal__title" id="np-flow-title"></h2>' +
                        '<button type="button" class="np-flow-modal__close" id="np-flow-cancel" aria-label="Cancel">&times;</button>' +
                    '</header>' +
                    '<div class="np-flow-modal__body" id="np-flow-body"></div>' +
                    '<footer class="np-flow-modal__foot" id="np-flow-foot"></footer>' +
                '</div>';
            (document.fullscreenElement || document.body).appendChild(overlay);

            const body = overlay.querySelector('#np-flow-body');
            const foot = overlay.querySelector('#np-flow-foot');
            const title = overlay.querySelector('#np-flow-title');

            const cleanup = function() {
                overlay.remove();
            };

            const fail = function(err) {
                cleanup();
                reject(err);
            };

            overlay.querySelector('#np-flow-cancel').addEventListener('click', function() {
                fail(new Error(str.cancelled || 'Cancelled'));
            });

            const showPermissions = function() {
                title.textContent = str.startAttempt || 'Start attempt';
                const items = permissionItems(cfg);
                const list = items.map(function(key, i) {
                    const label = key === 'screen' ? (str.permScreen || 'Screen')
                        : key === 'mic' ? (str.permMic || 'Microphone')
                            : (str.permCamera || 'Camera');
                    return '<li><span class="np-flow-perm__num">' + (i + 1) + '</span> ' + label + '</li>';
                }).join('');
                body.innerHTML =
                    '<p class="np-flow-intro">' + (str.permIntro ||
                        'You will need to grant access to the following to attempt this quiz:') + '</p>' +
                    '<ol class="np-flow-perm">' + list + '</ol>' +
                    '<label class="np-flow-consent">' +
                        '<input type="checkbox" id="np-flow-consent1"> ' +
                        '<span>' + (str.consentPermissions ||
                            'I consent to granting access to the above permissions.') + '</span>' +
                    '</label>';
                foot.innerHTML =
                    '<button type="button" class="btn btn-secondary" id="np-flow-back" hidden>Cancel</button>' +
                    '<button type="button" class="btn btn-primary" id="np-flow-next" disabled>' +
                        (str.continueBtn || 'Continue') +
                    '</button>';
                const cb = body.querySelector('#np-flow-consent1');
                const next = foot.querySelector('#np-flow-next');
                cb.addEventListener('change', function() {
                    consent1 = !!cb.checked;
                    next.disabled = !consent1;
                });
                next.addEventListener('click', function() {
                    if (!consent1) {
                        return;
                    }
                    showWebcam();
                });
            };

            const showWebcam = function() {
                const needCam = flag(s.requirecamera) || flag(s.detectfaces);
                if (!needCam && !flag(s.requiremic)) {
                    showSetup();
                    return;
                }
                title.textContent = str.webcamTitle || 'Allow your webcam to continue';
                body.innerHTML =
                    '<p class="np-flow-webcam-lead"><strong>* ' + (str.webcamLead ||
                        'Allow your webcam to continue') + '</strong></p>' +
                    '<p class="np-flow-webcam-sub">' + (str.webcamSub ||
                        'This exam requires webcam access. (Please allow webcam access).') + '</p>' +
                    '<div class="np-flow-preview" id="np-flow-preview">' +
                        '<video id="np-flow-video" autoplay muted playsinline></video>' +
                    '</div>' +
                    '<label class="np-flow-consent">' +
                        '<input type="checkbox" id="np-flow-consent2"> ' +
                        '<span>' + (str.consentValidation ||
                            'I agree with the validation process.') + '</span>' +
                    '</label>' +
                    '<p class="np-flow-status" id="np-flow-status"></p>';
                foot.innerHTML =
                    '<button type="button" class="btn btn-secondary" id="np-flow-back">' +
                        (str.back || 'Back') + '</button>' +
                    '<button type="button" class="btn btn-primary" id="np-flow-next" disabled>' +
                        (str.startAttempt || 'Start attempt') +
                    '</button>';

                const status = body.querySelector('#np-flow-status');
                const video = body.querySelector('#np-flow-video');
                const cb = body.querySelector('#np-flow-consent2');
                const next = foot.querySelector('#np-flow-next');
                foot.querySelector('#np-flow-back').addEventListener('click', showPermissions);

                cb.addEventListener('change', function() {
                    consent2 = !!cb.checked;
                    next.disabled = !consent2;
                });

                const needVideo = flag(s.requirecamera) || flag(s.detectfaces);
                const needAudio = flag(s.requiremic) || flag(s.detectnoise);
                status.textContent = str.requestingAv || 'Requesting camera / microphone…';

                navigator.mediaDevices.getUserMedia({
                    video: needVideo ? {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 720}} : false,
                    audio: needAudio ? {echoCancellation: false, noiseSuppression: false, autoGainControl: true} : false
                }).then(function(stream) {
                    cameraStream = stream;
                    if (needVideo && video) {
                        video.srcObject = stream;
                        return video.play();
                    }
                }).then(function() {
                    status.textContent = '';
                    status.className = 'np-flow-status is-ok';
                }).catch(function(err) {
                    status.textContent = (err && err.message) || str.needCamera || 'Camera required';
                    status.className = 'np-flow-status is-bad';
                });

                next.addEventListener('click', async function() {
                    if (!consent2) {
                        return;
                    }
                    next.disabled = true;
                    if (flag(s.detectfaces) && needVideo) {
                        status.textContent = str.checkingFace || 'Checking face…';
                        status.className = 'np-flow-status';
                        const n = await FaceDetect.verifySingleFace(video, 5);
                        if (n !== 1) {
                            status.textContent = n === 0
                                ? (str.needFace || 'Exactly one face must be visible.')
                                : (str.multiFace || 'Multiple faces detected.');
                            status.className = 'np-flow-status is-bad';
                            next.disabled = false;
                            return;
                        }
                        status.textContent = str.faceOk || 'Face verified.';
                        status.className = 'np-flow-status is-ok';
                    }
                    showSetup();
                });
            };

            const showSetup = function() {
                title.textContent = str.setupTitle || 'Setting up';
                body.innerHTML =
                    '<div class="np-flow-setup">' +
                        '<div class="np-flow-setup__pct" id="np-flow-setup-pct">0%</div>' +
                        '<div class="np-flow-setup__bar-wrap">' +
                            '<div class="np-flow-setup__bar" id="np-flow-setup-bar"></div>' +
                        '</div>' +
                        '<p class="np-flow-setup__msg" id="np-flow-setup-msg"></p>' +
                    '</div>';
                foot.innerHTML = '';
                animateSetup(body, str, function() {
                    acquireScreen();
                });
            };

            const acquireScreen = function() {
                if (!flag(s.requirescreenshare)) {
                    showFullscreenStep();
                    return;
                }
                title.textContent = str.shareScreenTitle || 'Share your screen';
                body.innerHTML =
                    '<p class="np-flow-intro">' + (str.shareScreenIntro ||
                        'Choose <strong>Entire screen</strong> in the browser dialog and click Share.') + '</p>' +
                    '<p class="np-flow-status" id="np-flow-status">' + (str.shareScreenHint ||
                        'Waiting for screen share…') + '</p>';
                foot.innerHTML = '';
                navigator.mediaDevices.getDisplayMedia({
                    video: {displaySurface: 'monitor', width: {ideal: 1920}, height: {ideal: 1080}},
                    audio: false,
                    preferCurrentTab: false,
                    selfBrowserSurface: 'exclude'
                }).then(function(stream) {
                    try {
                        const track = stream.getVideoTracks()[0];
                        const settings = track && track.getSettings ? track.getSettings() : {};
                        if (settings.displaySurface && settings.displaySurface !== 'monitor') {
                            stream.getTracks().forEach(function(t) {
                                t.stop();
                            });
                            throw new Error(str.needScreen || 'Share entire screen, not a window or tab.');
                        }
                    } catch (e) {
                        if (e && /entire screen/i.test(e.message || '')) {
                            throw e;
                        }
                    }
                    screenStream = stream;
                    showFullscreenStep();
                }).catch(function(err) {
                    body.querySelector('#np-flow-status').textContent =
                        (err && err.message) || str.needScreen || 'Screen share required';
                    body.querySelector('#np-flow-status').className = 'np-flow-status is-bad';
                    foot.innerHTML =
                        '<button type="button" class="btn btn-primary" id="np-flow-retry">' +
                            (str.retry || 'Try again') + '</button>';
                    foot.querySelector('#np-flow-retry').addEventListener('click', acquireScreen);
                });
            };

            const showFullscreenStep = function() {
                if (!flag(s.requirefullscreen)) {
                    finish();
                    return;
                }
                title.textContent = str.fsModalTitle || 'Full Screen Mode';
                body.innerHTML =
                    '<p class="np-flow-fs-body">' + (str.fsModalBody ||
                        'You must take this test in full screen mode. Do not exit or escape this mode during the test.') + '</p>';
                foot.innerHTML =
                    '<button type="button" class="btn btn-primary np-flow-fs-btn" id="np-flow-fs">' +
                        (str.fsModalBtn || 'Go Full Screen') +
                    '</button>';
                foot.querySelector('#np-flow-fs').addEventListener('click', function() {
                    const el = document.documentElement;
                    const req = el.requestFullscreen || el.webkitRequestFullscreen;
                    if (!req) {
                        finish();
                        return;
                    }
                    Promise.resolve(req.call(el)).then(function() {
                        if (document.fullscreenElement) {
                            finish();
                        }
                    }).catch(function() {
                        body.innerHTML += '<p class="np-flow-status is-bad">' +
                            (str.needFullscreen || 'Could not enter fullscreen.') + '</p>';
                    });
                });
            };

            const finish = function() {
                cleanup();
                resolve({
                    cameraStream: cameraStream,
                    screenStream: screenStream
                });
            };

            showPermissions();
        });
    };

    return {
        run: run,
        verifyFace: verifyFace,
        flag: flag
    };
});
