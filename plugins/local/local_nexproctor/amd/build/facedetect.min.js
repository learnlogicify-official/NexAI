/**
 * NexProctor face engine — MediaPipe Face Detector (BlazeFace short-range).
 *
 * Loads locally shipped @mediapipe/tasks-vision wasm + model (no third-party API).
 * Same public API as before: init / detect / countFaces.
 *
 * @module local_nexproctor/facedetect
 */
define(['core/config'], function(Config) {

    const state = {
        ready: false,
        loading: null,
        detector: null,
        lastCount: 1,
        lastFaces: [],
        history: [],
        lastTs: 0,
        lastVideoTime: -1
    };

    const HISTORY_LEN = 5;
    // MediaPipe scores are 0..1 — tuned for typical laptop webcams (avoid false "no face").
    const PRESENCE_SCORE = 0.38;
    const MULTI_SCORE = 0.65;

    const assetBase = function() {
        return Config.wwwroot + '/local/nexproctor/js/mediapipe';
    };

    const mergeNearby = function(faces) {
        if (faces.length < 2) {
            return faces;
        }
        const sorted = faces.slice().sort(function(a, b) {
            return b.score - a.score;
        });
        const kept = [];
        sorted.forEach(function(f) {
            const cx = f.x + f.width / 2;
            const cy = f.y + f.height / 2;
            const dup = kept.some(function(k) {
                const kx = k.x + k.width / 2;
                const ky = k.y + k.height / 2;
                const dist = Math.hypot(cx - kx, cy - ky);
                const lim = 0.55 * Math.max(f.width, k.width);
                return dist < lim;
            });
            if (!dup) {
                kept.push(f);
            }
        });
        return kept;
    };

    const stabilize = function(raw) {
        state.history.push(raw);
        if (state.history.length > HISTORY_LEN) {
            state.history.shift();
        }
        const counts = {0: 0, 1: 0, 2: 0};
        state.history.forEach(function(n) {
            const k = n >= 2 ? 2 : n;
            counts[k]++;
        });
        if (counts[1] >= Math.ceil(state.history.length / 2)) {
            return 1;
        }
        if (counts[2] >= 3) {
            return 2;
        }
        if (counts[0] >= 5) {
            return 0;
        }
        return state.lastCount;
    };

    const nextTimestamp = function() {
        let ts = performance.now();
        if (ts <= state.lastTs) {
            ts = state.lastTs + 1;
        }
        state.lastTs = ts;
        return ts;
    };

    /**
     * Load MediaPipe FaceDetector from plugin assets.
     * @return {Promise<boolean>}
     */
    const init = function() {
        if (state.ready && state.detector) {
            return Promise.resolve(true);
        }
        if (state.loading) {
            return state.loading;
        }

        const base = assetBase();
        state.loading = import(base + '/vision_bundle.mjs')
            .then(function(visionMod) {
                const FilesetResolver = visionMod.FilesetResolver;
                const FaceDetector = visionMod.FaceDetector;
                if (!FilesetResolver || !FaceDetector) {
                    throw new Error('MediaPipe FaceDetector export missing');
                }
                return FilesetResolver.forVisionTasks(base + '/wasm').then(function(fileset) {
                    return FaceDetector.createFromOptions(fileset, {
                        baseOptions: {
                            modelAssetPath: base + '/blaze_face_short_range.tflite',
                            delegate: 'GPU'
                        },
                        runningMode: 'VIDEO',
                        minDetectionConfidence: PRESENCE_SCORE,
                        minSuppressionThreshold: 0.3
                    });
                });
            })
            .then(function(detector) {
                state.detector = detector;
                state.ready = true;
                state.loading = null;
                return true;
            })
            .catch(function(err) {
                // Retry CPU delegate if GPU init failed.
                // eslint-disable-next-line no-console
                console.warn('NexProctor MediaPipe GPU init failed, retrying CPU', err);
                const base = assetBase();
                return import(base + '/vision_bundle.mjs')
                    .then(function(visionMod) {
                        return visionMod.FilesetResolver.forVisionTasks(base + '/wasm')
                            .then(function(fileset) {
                                return visionMod.FaceDetector.createFromOptions(fileset, {
                                    baseOptions: {
                                        modelAssetPath: base + '/blaze_face_short_range.tflite',
                                        delegate: 'CPU'
                                    },
                                    runningMode: 'VIDEO',
                                    minDetectionConfidence: PRESENCE_SCORE,
                                    minSuppressionThreshold: 0.3
                                });
                            });
                    })
                    .then(function(detector) {
                        state.detector = detector;
                        state.ready = true;
                        state.loading = null;
                        return true;
                    })
                    .catch(function(err2) {
                        state.loading = null;
                        state.ready = false;
                        // eslint-disable-next-line no-console
                        console.warn('NexProctor MediaPipe face init failed', err2);
                        return false;
                    });
            });

        return state.loading;
    };

    /**
     * @param {HTMLVideoElement|HTMLCanvasElement} video
     * @return {Promise<{count: number, faces: Array}>}
     */
    const detect = async function(video) {
        await init();
        if (!state.ready || !state.detector || !video || !video.videoWidth) {
            return {count: state.lastCount, faces: state.lastFaces.slice()};
        }

        // Skip duplicate video frames.
        if (typeof video.currentTime === 'number' && video.currentTime === state.lastVideoTime) {
            return {count: state.lastCount, faces: state.lastFaces.slice()};
        }
        if (typeof video.currentTime === 'number') {
            state.lastVideoTime = video.currentTime;
        }

        let result;
        try {
            result = state.detector.detectForVideo(video, nextTimestamp());
        } catch (e) {
            // eslint-disable-next-line no-console
            console.warn('NexProctor MediaPipe detect failed', e);
            return {count: state.lastCount, faces: state.lastFaces.slice()};
        }

        const vw = video.videoWidth || 1;
        const vh = video.videoHeight || 1;
        const frameArea = vw * vh;
        const dets = (result && result.detections) ? result.detections : [];

        let faces = [];
        dets.forEach(function(d) {
            const box = d.boundingBox || {};
            const score = (d.categories && d.categories[0] && d.categories[0].score != null)
                ? d.categories[0].score
                : (d.categories && d.categories[0] && d.categories[0].confidence) || 0;
            const x = box.originX != null ? box.originX : (box.x || 0);
            const y = box.originY != null ? box.originY : (box.y || 0);
            const w = box.width || 0;
            const h = box.height || 0;
            if (score < PRESENCE_SCORE || w <= 0 || h <= 0) {
                return;
            }
            const areaFrac = (w * h) / frameArea;
            // Drop tiny background faces (e.g. on a second monitor).
            if (areaFrac < 0.012 || Math.min(w, h) < 0.05 * Math.min(vw, vh)) {
                return;
            }
            faces.push({
                x: x,
                y: y,
                width: w,
                height: h,
                score: score
            });
        });

        faces = mergeNearby(faces);

        let count = faces.length;
        if (count >= 2) {
            const strong = faces.filter(function(f) {
                const areaFrac = (f.width * f.height) / frameArea;
                return f.score >= MULTI_SCORE && areaFrac >= 0.04;
            });
            strong.sort(function(a, b) {
                return b.score - a.score;
            });
            if (strong.length < 2) {
                count = 1;
                faces = faces.slice(0, 1);
            } else {
                const a = strong[0];
                const b = strong[1];
                const ax = a.x + a.width / 2;
                const ay = a.y + a.height / 2;
                const bx = b.x + b.width / 2;
                const by = b.y + b.height / 2;
                const sep = Math.hypot(ax - bx, ay - by);
                if (sep < 0.35 * Math.min(vw, vh)) {
                    count = 1;
                    faces = [a];
                } else {
                    count = 2;
                    faces = strong.slice(0, 2);
                }
            }
        } else if (count === 1) {
            faces = faces.slice(0, 1);
        }

        count = stabilize(count);
        state.lastFaces = faces;
        state.lastCount = count;
        return {count: count, faces: faces};
    };

    /**
     * @param {HTMLVideoElement} video
     * @return {Promise<number>}
     */
    const countFaces = async function(video) {
        const res = await detect(video);
        return res.count;
    };

    /**
     * Multi-frame face verification for preflight (reduces false negatives).
     *
     * @param {HTMLVideoElement} video
     * @param {number} samples
     * @return {Promise<number>}
     */
    const verifySingleFace = async function(video, samples) {
        samples = samples || 5;
        if (!video) {
            return 0;
        }
        await init();
        await new Promise(function(r) {
            window.setTimeout(r, 600);
        });
        const counts = {0: 0, 1: 0, 2: 0};
        for (let i = 0; i < samples; i++) {
            const n = await countFaces(video);
            counts[n >= 2 ? 2 : n]++;
            await new Promise(function(r) {
                window.setTimeout(r, 300);
            });
        }
        if (counts[1] >= 2) {
            return 1;
        }
        if (counts[0] >= Math.ceil(samples * 0.55)) {
            return 0;
        }
        if (counts[2] >= 2) {
            return 2;
        }
        return 1;
    };

    return {
        init: init,
        detect: detect,
        countFaces: countFaces,
        verifySingleFace: verifySingleFace
    };
});
