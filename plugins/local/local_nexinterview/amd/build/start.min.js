define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    const cleanText = (text, max) => {
        let s = String(text || '')
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
            .replace(/[\uD800-\uDFFF]/g, '');
        if (max && s.length > max) {
            s = s.slice(0, max);
        }
        return s;
    };

    const callExtract = (text, pdfbase64) => Ajax.call([{
        methodname: 'local_nexinterview_extract_resume',
        args: {
            text: cleanText(text, 16000),
            draftitemid: 0,
            pdfbase64: pdfbase64 || ''
        }
    }])[0];

    const arrayBufferToBase64 = (buffer) => {
        const u8 = new Uint8Array(buffer);
        const chunk = 0x8000;
        let binary = '';
        for (let i = 0; i < u8.length; i += chunk) {
            binary += String.fromCharCode.apply(null, u8.subarray(i, i + chunk));
        }
        return btoa(binary);
    };

    const loadPdfJs = () => new Promise(function(resolve, reject) {
        if (window.pdfjsLib) {
            resolve(window.pdfjsLib);
            return;
        }
        const existing = document.querySelector('script[data-nxi-pdfjs]');
        if (existing) {
            existing.addEventListener('load', function() {
                if (window.pdfjsLib) {
                    resolve(window.pdfjsLib);
                } else {
                    reject(new Error('pdf.js failed to load'));
                }
            });
            existing.addEventListener('error', reject);
            return;
        }
        const s = document.createElement('script');
        s.src = PDFJS_CDN;
        s.async = true;
        s.setAttribute('data-nxi-pdfjs', '1');
        s.onload = function() {
            if (!window.pdfjsLib) {
                reject(new Error('pdf.js unavailable'));
                return;
            }
            try {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
            } catch (e) { /* ignore */ }
            resolve(window.pdfjsLib);
        };
        s.onerror = function() {
            reject(new Error('Could not load PDF reader'));
        };
        document.head.appendChild(s);
    });

    const extractPdfClient = (arrayBuffer) => loadPdfJs().then(function(pdfjsLib) {
        return pdfjsLib.getDocument({data: arrayBuffer}).promise.then(function(pdf) {
            const pages = [];
            const maxPages = Math.min(pdf.numPages || 0, 12);
            let chain = Promise.resolve();
            for (let i = 1; i <= maxPages; i++) {
                chain = chain.then(function() {
                    return pdf.getPage(i).then(function(page) {
                        return page.getTextContent().then(function(content) {
                            const line = (content.items || []).map(function(it) {
                                return String(it.str || '');
                            }).join(' ');
                            pages.push(line);
                        });
                    });
                });
            }
            return chain.then(function() {
                return cleanText(pages.join('\n'), 16000).trim();
            });
        });
    });

    const looksLikeResume = (text, minChars) => {
        const clean = cleanText(text, 16000).trim();
        const letters = (clean.match(/[A-Za-z]/g) || []).length;
        const ratio = letters / Math.max(1, clean.length);
        return clean.length >= minChars && letters >= 40 && ratio >= 0.28;
    };

    const setDevice = (root, key, ok, label) => {
        const li = root.querySelector('[data-device="' + key + '"]');
        if (!li) {
            return;
        }
        li.classList.toggle('is-ok', !!ok);
        li.classList.toggle('is-bad', !ok);
        const em = li.querySelector('[data-label]');
        if (em) {
            em.textContent = label;
        }
    };

    const setStep = (root, step) => {
        root.querySelectorAll('[data-region="steps"] .nxi-steps__item').forEach(function(el) {
            const n = Number(el.getAttribute('data-step') || 0);
            el.classList.toggle('is-active', n === step);
            el.classList.toggle('is-done', n < step);
        });
    };

    const init = (cfg) => {
        const root = document.querySelector('[data-region="nxi-start"]');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        let resumeText = '';
        let mediaStream = null;
        let droppedFile = null;

        const resumeStep = root.querySelector('[data-region="step-resume"]');
        const deviceStep = root.querySelector('[data-region="step-devices"]');
        const hint = root.querySelector('[data-region="resume-hint"]');
        const enterBtn = root.querySelector('[data-action="enter-room"]');
        const video = root.querySelector('[data-region="setup-video"]');
        const drop = root.querySelector('[data-region="resume-drop"]');
        const fileInput = root.querySelector('[data-region="resume-file"]');
        const fileName = root.querySelector('[data-region="resume-filename"]');
        const toDevicesBtn = root.querySelector('[data-action="to-devices"]');

        const showHint = (msg, isError) => {
            if (!hint) {
                return;
            }
            if (!msg) {
                hint.hidden = true;
                hint.textContent = '';
                hint.classList.remove('is-error', 'is-busy');
                return;
            }
            hint.hidden = false;
            hint.textContent = msg;
            hint.classList.toggle('is-error', !!isError);
            hint.classList.toggle('is-busy', !isError);
        };

        const showFileName = (name) => {
            if (!fileName) {
                return;
            }
            if (name) {
                fileName.hidden = false;
                fileName.textContent = name;
                if (drop) {
                    drop.classList.add('has-file');
                }
            } else {
                fileName.hidden = true;
                fileName.textContent = '';
                if (drop) {
                    drop.classList.remove('has-file');
                }
            }
        };

        const pickFile = (file) => {
            if (!file) {
                return;
            }
            droppedFile = file;
            showFileName(file.name);
            showHint('');
        };

        if (drop && fileInput) {
            // Keep the transparent input on top so native browse always works.
            fileInput.style.zIndex = '5';
            drop.addEventListener('click', function(ev) {
                if (ev.target === fileInput || fileInput.contains(ev.target)) {
                    return;
                }
                try {
                    fileInput.click();
                } catch (e) { /* ignore */ }
            });
            drop.addEventListener('dragover', function(ev) {
                ev.preventDefault();
                drop.classList.add('is-drag');
            });
            drop.addEventListener('dragleave', function() {
                drop.classList.remove('is-drag');
            });
            drop.addEventListener('drop', function(ev) {
                ev.preventDefault();
                drop.classList.remove('is-drag');
                const files = ev.dataTransfer && ev.dataTransfer.files;
                if (files && files[0]) {
                    pickFile(files[0]);
                }
            });
            fileInput.addEventListener('change', function() {
                const f = fileInput.files && fileInput.files[0];
                if (f) {
                    pickFile(f);
                }
            });
        }

        const unlockAudio = () => {
            try {
                const silent = new Audio(
                    'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA'
                );
                silent.volume = 0.01;
                silent.play().catch(function() { /* ignore */ });
            } catch (e) {
                // ignore
            }
        };

        const finish = (text) => {
            resumeText = cleanText(text, 16000).trim();
            const minChars = Math.max(40, parseInt(cfg.minResumeChars, 10) || 40);
            if (!looksLikeResume(resumeText, minChars)) {
                showHint(
                    (cfg.strings && cfg.strings.needresume) ||
                    'Could not read enough text from that file. Try another PDF or paste the resume text.',
                    true
                );
                return false;
            }
            showHint('');
            resumeStep.hidden = true;
            deviceStep.hidden = false;
            setStep(root, 2);
            navigator.mediaDevices.getUserMedia({audio: true, video: true}).then(function(stream) {
                mediaStream = stream;
                if (video) {
                    video.srcObject = stream;
                }
                setDevice(root, 'mic', true, 'Ready');
                setDevice(root, 'cam', true, 'Ready');
                setDevice(root, 'spk', true, 'Ready');
                if (enterBtn) {
                    enterBtn.disabled = false;
                }
            }).catch(function(err) {
                const warn = root.querySelector('[data-region="device-warn"]');
                if (warn) {
                    warn.hidden = false;
                    warn.textContent = err.message || 'Permission denied';
                }
                setDevice(root, 'mic', false, 'Blocked');
                setDevice(root, 'cam', false, 'Blocked');
                if (enterBtn) {
                    enterBtn.disabled = false;
                }
            });
            return true;
        };

        const readFileAsArrayBuffer = (file) => new Promise(function(resolve, reject) {
            const reader = new FileReader();
            reader.onerror = function() {
                reject(new Error('Could not read that file.'));
            };
            reader.onload = function() {
                resolve(reader.result);
            };
            reader.readAsArrayBuffer(file);
        });

        const readFileAsText = (file) => new Promise(function(resolve, reject) {
            const reader = new FileReader();
            reader.onerror = function() {
                reject(new Error('Could not read that file.'));
            };
            reader.onload = function() {
                resolve(String(reader.result || ''));
            };
            reader.readAsText(file);
        });

        const processPdf = (file, pasted) => {
            showHint('Reading PDF…');
            return readFileAsArrayBuffer(file).then(function(buf) {
                // Prefer browser extraction — avoids posting multi-MB base64 to Moodle.
                return extractPdfClient(buf).then(function(clientText) {
                    const merged = cleanText((pasted + '\n' + clientText).trim(), 16000);
                    if (looksLikeResume(merged, Math.max(40, parseInt(cfg.minResumeChars, 10) || 40))) {
                        return {text: merged, via: 'client'};
                    }
                    // Fallback: small PDFs can still go through the Moodle extractor.
                    if (file.size <= 900000) {
                        showHint('Trying server extract…');
                        const b64 = arrayBufferToBase64(buf);
                        return callExtract(pasted, b64).then(function(resp) {
                            return {text: (resp && resp.text) || pasted || clientText || '', via: 'server'};
                        });
                    }
                    return {text: merged || clientText || pasted, via: 'client'};
                }).catch(function() {
                    if (file.size > 900000) {
                        throw new Error(
                            'Could not read that PDF in the browser. Paste the resume text, or use a smaller text-based PDF.'
                        );
                    }
                    showHint('Trying server extract…');
                    const b64 = arrayBufferToBase64(buf);
                    return callExtract(pasted, b64).then(function(resp) {
                        return {text: (resp && resp.text) || pasted || '', via: 'server'};
                    });
                });
            });
        };

        if (toDevicesBtn) {
            toDevicesBtn.addEventListener('click', function() {
                unlockAudio();
                const pasted = (root.querySelector('[data-region="resume-text"]').value || '').trim();
                const file = (fileInput && fileInput.files && fileInput.files[0]) || droppedFile;
                const btn = this;
                btn.disabled = true;
                showHint('');

                const done = function() {
                    btn.disabled = false;
                };

                const fail = function(err) {
                    const msg = (err && (err.message || err.error || err.debuginfo)) ||
                        'Could not upload the resume. Try paste text instead.';
                    showHint(String(msg), true);
                    try { Notification.exception(err); } catch (e) { /* ignore */ }
                };

                if (!file && !pasted) {
                    showHint('Upload a PDF/TXT resume or paste the text first.', true);
                    done();
                    return;
                }

                if (file) {
                    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '');
                    const work = isPdf
                        ? processPdf(file, pasted)
                        : readFileAsText(file).then(function(txt) {
                            const merged = (pasted + '\n' + txt).trim();
                            return callExtract(merged, '').then(function(resp) {
                                return {text: (resp && resp.text) || merged, via: 'text'};
                            }).catch(function() {
                                return {text: merged, via: 'text'};
                            });
                        });

                    work.then(function(result) {
                        finish((result && result.text) || pasted);
                    }).catch(fail).then(done, done);
                    return;
                }

                callExtract(pasted, '').then(function(resp) {
                    finish((resp && resp.text) || pasted);
                }).catch(fail).then(done, done);
            });
        }

        root.querySelector('[data-action="back-resume"]').addEventListener('click', function() {
            deviceStep.hidden = true;
            resumeStep.hidden = false;
            setStep(root, 1);
        });

        root.querySelector('[data-action="enter-room"]').addEventListener('click', function() {
            unlockAudio();
            if (!looksLikeResume(resumeText, Math.max(40, parseInt(cfg.minResumeChars, 10) || 40))) {
                showHint('Resume text is missing. Go back and upload or paste it again.', true);
                return;
            }
            const persist = function(store) {
                try {
                    store.setItem('nxi_resume', resumeText);
                    store.setItem('nxi_track', cfg.track || 'sde_intern');
                    store.setItem('nxi_topics', cfg.topics || '');
                    store.setItem('nxi_problemid', String(cfg.problemid || 0));
                    store.setItem('nxi_interviewerid', String(cfg.interviewerid || 0));
                } catch (e) {
                    // ignore quota / private mode
                }
            };
            persist(window.sessionStorage);
            persist(window.localStorage);
            if (mediaStream) {
                mediaStream.getTracks().forEach(function(t) {
                    t.stop();
                });
            }
            const url = new URL(cfg.roomurl, window.location.origin);
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url.pathname;
            const add = function(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value == null ? '' : String(value);
                form.appendChild(input);
            };
            // Keep query params from roomurl (e.g. course activity ?id=cmid).
            url.searchParams.forEach(function(value, key) {
                add(key, value);
            });
            add('track', cfg.track || 'sde_intern');
            add('start', '1');
            add('resume', resumeText);
            if (cfg.interviewerid) {
                add('interviewerid', cfg.interviewerid);
            }
            document.body.appendChild(form);
            form.submit();
        });

        setStep(root, 1);
    };

    return {init: init};
});
