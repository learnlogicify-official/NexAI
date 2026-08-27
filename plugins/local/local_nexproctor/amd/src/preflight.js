/**
 * NexProctor preflight — fullscreen progressive loader before attempt starts.
 *
 * @module local_nexproctor/preflight
 */
define(['core/ajax', 'core/notification', 'local_nexproctor/facedetect'], function(Ajax, Notification, FaceDetect) {

    const state = {
        camera: null,
        mic: null,
        screen: null,
        faceOk: false,
        monitorOk: true,
        fullscreenOk: false,
        consent: false,
        busy: false
    };

    const setStatus = function(root, msg, ok) {
        const el = root.querySelector('#np-preflight-status');
        if (!el) {
            return;
        }
        el.textContent = msg;
        el.className = 'np-gate__status' + (ok === true ? ' is-ok' : (ok === false ? ' is-bad' : ''));
    };

    const setStep = function(root, id, status, detail) {
        const li = root.querySelector('[data-step="' + id + '"]');
        if (!li) {
            return;
        }
        li.classList.remove('is-ok', 'is-bad', 'is-active', 'is-pending');
        li.classList.add(status === 'ok' ? 'is-ok' : (status === 'bad' ? 'is-bad' : (status === 'active' ? 'is-active' : 'is-pending')));
        if (detail) {
            const d = li.querySelector('.np-gate__detail');
            if (d) {
                d.textContent = detail;
            }
        }
        refreshBar(root);
    };

    const refreshBar = function(root) {
        const steps = root.querySelectorAll('.np-gate__step');
        let done = 0;
        steps.forEach(function(li) {
            if (li.classList.contains('is-ok')) {
                done++;
            }
        });
        const pct = steps.length ? Math.round((done / steps.length) * 100) : 0;
        const fill = root.querySelector('#np-progress-fill');
        if (fill) {
            fill.style.width = pct + '%';
        }
    };

    const countFaces = async function(video) {
        try {
            const ok = await FaceDetect.init();
            if (!ok || !video) {
                return 1;
            }
            return await FaceDetect.countFaces(video);
        } catch (e) {
            return 1;
        }
    };

    const checkMonitors = async function() {
        try {
            if (window.getScreenDetails) {
                const details = await window.getScreenDetails();
                return (details.screens || []).length <= 1;
            }
            if (window.screen && typeof window.screen.isExtended === 'boolean') {
                return !window.screen.isExtended;
            }
        } catch (e) {
            // Allow.
        }
        return true;
    };

    const allReady = function(cfg) {
        const s = cfg.settings;
        let ready = state.consent;
        if (s.requirecamera) {
            ready = ready && !!state.camera;
        }
        if (s.requiremic) {
            ready = ready && !!state.mic;
        }
        if (s.requirescreenshare) {
            ready = ready && !!state.screen;
        }
        if (s.requirefullscreen) {
            ready = ready && state.fullscreenOk;
        }
        if (s.blockmultimonitor) {
            ready = ready && state.monitorOk;
        }
        if (s.detectfaces && s.requirecamera) {
            ready = ready && state.faceOk;
        }
        return !!ready;
    };

    const finishAndGo = function(cfg, root) {
        const startBtn = root.querySelector('#np-preflight-start');
        if (startBtn) {
            startBtn.disabled = true;
        }
        setStatus(root, cfg.strings.ready || 'All checks passed. Starting…', true);
        Ajax.call([{
            methodname: 'local_nexproctor_complete_preflight',
            args: {cmid: cfg.cmid, quizid: cfg.quizid}
        }])[0].then(function() {
            if (state.camera) {
                state.camera.getTracks().forEach(function(t) {
                    t.stop();
                });
            }
            if (state.screen) {
                state.screen.getTracks().forEach(function(t) {
                    t.stop();
                });
            }
            window.location.href = cfg.returnUrl;
        }).catch(Notification.exception);
    };

    const runChecks = async function(root, cfg) {
        if (state.busy) {
            return;
        }
        const s = cfg.settings;
        const wrap = root.querySelector('#np-preflight-preview-wrap');
        const retry = root.querySelector('#np-preflight-enable');
        state.busy = true;
        if (retry) {
            retry.hidden = true;
        }
        setStatus(root, cfg.strings.runningChecks || 'Enabling tracking…', null);

        try {
            // Consent first (must already be checked for auto-run, or pause).
            if (!state.consent) {
                setStep(root, 'consent', 'bad', cfg.strings.needConsent || 'Accept consent to continue');
                setStatus(root, cfg.strings.needConsent || 'Please accept consent.', false);
                if (retry) {
                    retry.hidden = false;
                    retry.textContent = cfg.strings.retry || 'Retry';
                }
                return;
            }
            setStep(root, 'consent', 'ok');

            if (s.blockmultimonitor) {
                setStep(root, 'monitor', 'active', 'Checking displays…');
                state.monitorOk = await checkMonitors();
                if (!state.monitorOk) {
                    setStep(root, 'monitor', 'bad', cfg.strings.multiMonitor);
                    setStatus(root, cfg.strings.multiMonitor, false);
                    throw new Error(cfg.strings.multiMonitor);
                }
                setStep(root, 'monitor', 'ok', 'Single display OK');
            }

            if (s.requirecamera || s.requiremic) {
                if (s.requirecamera) {
                    setStep(root, 'camera', 'active', 'Allow camera access…');
                }
                if (s.requiremic) {
                    setStep(root, 'mic', 'active', 'Allow microphone access…');
                }
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: !!s.requirecamera,
                    audio: !!s.requiremic
                });
                if (s.requirecamera) {
                    state.camera = stream;
                    let video = wrap.querySelector('video');
                    if (!video) {
                        video = document.createElement('video');
                        video.autoplay = true;
                        video.muted = true;
                        video.playsInline = true;
                        video.className = 'np-gate__video';
                        wrap.appendChild(video);
                    }
                    video.srcObject = stream;
                    await video.play();
                    setStep(root, 'camera', 'ok', 'Camera ready');
                }
                if (s.requiremic) {
                    if (!stream.getAudioTracks().length) {
                        setStep(root, 'mic', 'bad', cfg.strings.needMic);
                        throw new Error(cfg.strings.needMic);
                    }
                    state.mic = stream;
                    setStep(root, 'mic', 'ok', 'Microphone ready');
                }
            }

            if (s.detectfaces && s.requirecamera) {
                setStep(root, 'face', 'active', 'Checking face…');
                const video = wrap.querySelector('video');
                const n = video ? await countFaces(video) : 0;
                state.faceOk = n === 1;
                if (!state.faceOk) {
                    setStep(root, 'face', 'bad', cfg.strings.needFace);
                    throw new Error(cfg.strings.needFace);
                }
                setStep(root, 'face', 'ok', 'One face detected');
            } else {
                state.faceOk = true;
            }

            if (s.requirescreenshare) {
                setStep(root, 'screen', 'active', 'Share your entire screen…');
                state.screen = await navigator.mediaDevices.getDisplayMedia({
                    video: {displaySurface: 'monitor'},
                    audio: false
                });
                setStep(root, 'screen', 'ok', 'Screen share ready');
                state.screen.getVideoTracks().forEach(function(t) {
                    t.addEventListener('ended', function() {
                        state.screen = null;
                        setStep(root, 'screen', 'bad', cfg.strings.needScreen);
                        setStatus(root, cfg.strings.needScreen, false);
                        if (retry) {
                            retry.hidden = false;
                        }
                    });
                });
            }

            if (s.requirefullscreen) {
                setStep(root, 'fullscreen', 'active', 'Entering fullscreen…');
                if (!document.fullscreenElement) {
                    await document.documentElement.requestFullscreen();
                }
                state.fullscreenOk = !!document.fullscreenElement;
                if (!state.fullscreenOk) {
                    setStep(root, 'fullscreen', 'bad', cfg.strings.needFullscreen);
                    throw new Error(cfg.strings.needFullscreen);
                }
                setStep(root, 'fullscreen', 'ok', 'Fullscreen on');
            } else {
                state.fullscreenOk = true;
            }

            if (allReady(cfg)) {
                finishAndGo(cfg, root);
            }
        } catch (err) {
            const msg = (err && err.message) ? err.message : 'Permission denied';
            setStatus(root, msg, false);
            if (retry) {
                retry.hidden = false;
                retry.textContent = cfg.strings.retry || 'Fix & retry';
            }
        } finally {
            state.busy = false;
        }
    };

    const buildStepsHtml = function(cfg) {
        const s = cfg.settings;
        const str = cfg.strings;
        const steps = [];
        steps.push({id: 'consent', label: str.checkConsent || 'Consent'});
        if (s.blockmultimonitor) {
            steps.push({id: 'monitor', label: str.checkMonitor || 'Single monitor'});
        }
        if (s.requirecamera) {
            steps.push({id: 'camera', label: str.checkCamera || 'Camera'});
        }
        if (s.requiremic) {
            steps.push({id: 'mic', label: str.checkMic || 'Microphone'});
        }
        if (s.detectfaces && s.requirecamera) {
            steps.push({id: 'face', label: str.checkFace || 'Face check'});
        }
        if (s.requirescreenshare) {
            steps.push({id: 'screen', label: str.checkScreen || 'Screen share'});
        }
        if (s.requirefullscreen) {
            steps.push({id: 'fullscreen', label: str.checkFullscreen || 'Fullscreen'});
        }
        return steps.map(function(step) {
            return '<li class="np-gate__step is-pending" data-step="' + step.id + '">' +
                '<span class="np-gate__icon" aria-hidden="true"></span>' +
                '<div><div class="np-gate__label"></div><div class="np-gate__detail"></div></div>' +
                '</li>';
        }).join('').replace(/np-gate__label"><\/div>/g, function() {
            return '';
        }) || steps.map(function(step) {
            return '<li class="np-gate__step is-pending" data-step="' + step.id + '">' +
                '<span class="np-gate__icon" aria-hidden="true"></span>' +
                '<div><div class="np-gate__label">' + step.label + '</div><div class="np-gate__detail"></div></div>' +
                '</li>';
        }).join('');
    };

    const init = function(cfg) {
        const root = document.getElementById('np-preflight');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        cfg.settings = cfg.settings || {};
        cfg.strings = cfg.strings || {};

        document.documentElement.classList.add('np-preflight-fs');
        document.body.classList.add('np-preflight-fs');

        const host = root.querySelector('#np-preflight-progress');
        if (host) {
            const s = cfg.settings;
            const str = cfg.strings;
            const steps = [];
            steps.push({id: 'consent', label: str.checkConsent || 'Consent'});
            if (s.blockmultimonitor) {
                steps.push({id: 'monitor', label: str.checkMonitor || 'Single monitor'});
            }
            if (s.requirecamera) {
                steps.push({id: 'camera', label: str.checkCamera || 'Camera'});
            }
            if (s.requiremic) {
                steps.push({id: 'mic', label: str.checkMic || 'Microphone'});
            }
            if (s.detectfaces && s.requirecamera) {
                steps.push({id: 'face', label: str.checkFace || 'Face check'});
            }
            if (s.requirescreenshare) {
                steps.push({id: 'screen', label: str.checkScreen || 'Screen share'});
            }
            if (s.requirefullscreen) {
                steps.push({id: 'fullscreen', label: str.checkFullscreen || 'Fullscreen'});
            }
            host.innerHTML =
                '<div class="np-progress__bar"><div class="np-progress__fill" id="np-progress-fill"></div></div>' +
                '<ul class="np-gate__steps">' +
                steps.map(function(step) {
                    return '<li class="np-gate__step is-pending" data-step="' + step.id + '">' +
                        '<span class="np-gate__icon" aria-hidden="true"></span>' +
                        '<div><div class="np-gate__label">' + step.label + '</div>' +
                        '<div class="np-gate__detail"></div></div></li>';
                }).join('') +
                '</ul>';
        }

        // Hide unused continue; auto-continue after success.
        const startBtn = root.querySelector('#np-preflight-start');
        if (startBtn) {
            startBtn.hidden = true;
        }

        document.addEventListener('fullscreenchange', function() {
            state.fullscreenOk = !!document.fullscreenElement;
            if (cfg.settings.requirefullscreen) {
                setStep(root, 'fullscreen', state.fullscreenOk ? 'ok' : 'bad');
            }
        });

        const consent = root.querySelector('#np-consent');
        if (consent) {
            // Auto-check if configured; otherwise wait for user.
            if (cfg.autoConsent) {
                consent.checked = true;
                state.consent = true;
            }
            consent.addEventListener('change', function() {
                state.consent = !!consent.checked;
                setStep(root, 'consent', state.consent ? 'ok' : 'pending');
            });
            state.consent = !!consent.checked;
        }

        const retry = root.querySelector('#np-preflight-enable');
        if (retry) {
            retry.textContent = cfg.strings.startChecks || 'Start security checks';
            retry.addEventListener('click', function() {
                runChecks(root, cfg);
            });
        }

        // Auto-start after short beat when consent already given / autoConsent.
        window.setTimeout(function() {
            if (state.consent) {
                runChecks(root, cfg);
            } else {
                setStatus(root, cfg.strings.needConsent || 'Accept consent, then start checks.', false);
            }
        }, 400);
    };

    // Silence unused helper warning in some linters.
    void buildStepsHtml;

    return {init: init};
});
