define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const fmtTime = (sec) => {
        sec = Math.max(0, Math.floor(Number(sec) || 0));
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + ':' + String(s).padStart(2, '0');
    };

    const storeGet = (key) => {
        try {
            return window.sessionStorage.getItem(key) || window.localStorage.getItem(key) || '';
        } catch (e) {
            return '';
        }
    };

    const storeSet = (key, value) => {
        try {
            window.sessionStorage.setItem(key, value);
            window.localStorage.setItem(key, value);
        } catch (e) { /* ignore */ }
    };

    const storeClear = (key) => {
        try {
            window.sessionStorage.removeItem(key);
            window.localStorage.removeItem(key);
        } catch (e) { /* ignore */ }
    };

    const persistSessionId = (sid) => {
        if (sid) {
            storeSet('nxi_sessionid', String(sid));
        }
    };

    const clearPersistedSessionId = () => {
        storeClear('nxi_sessionid');
    };

    const LANG_KW = {
        python: 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|False|finally|for|from|global|if|import|in|is|lambda|None|nonlocal|not|or|pass|raise|return|True|try|while|with|yield|print',
        javascript: 'async|await|break|case|catch|class|const|continue|default|delete|do|else|export|extends|false|finally|for|function|if|import|in|instanceof|let|new|null|return|static|super|switch|this|throw|true|try|typeof|var|void|while|yield',
        java: 'abstract|boolean|break|byte|case|catch|char|class|const|continue|default|do|double|else|enum|extends|final|finally|float|for|if|implements|import|instanceof|int|interface|long|new|package|private|protected|public|return|short|static|super|switch|this|throw|throws|try|void|while|true|false|null',
        cpp: 'auto|bool|break|case|catch|char|class|const|continue|default|delete|do|double|else|enum|float|for|if|int|long|namespace|new|private|protected|public|return|short|sizeof|static|struct|switch|template|this|throw|try|typedef|typename|using|virtual|void|while|true|false',
        sql: 'select|from|where|insert|update|delete|join|left|right|inner|outer|group|by|order|limit|and|or|not|null|as|on|into|values|create|table'
    };

    const detectLang = (hint, code) => {
        const h = String(hint || '').toLowerCase();
        if (/py|python/.test(h)) {
            return 'python';
        }
        if (/js|javascript|node|ts|typescript/.test(h)) {
            return 'javascript';
        }
        if (/java/.test(h)) {
            return 'java';
        }
        if (/c\+\+|cpp|c$|cxx/.test(h)) {
            return 'cpp';
        }
        if (/sql/.test(h)) {
            return 'sql';
        }
        const c = String(code || '');
        if (/^\s*def\s+|^\s*class\s+\w+\s*:/m.test(c)) {
            return 'python';
        }
        if (/\bfunction\s+\w+\s*\(|\bconst\s+\w+\s*=|=>/.test(c)) {
            return 'javascript';
        }
        if (/\bpublic\s+(static\s+)?(class|void|int)/.test(c)) {
            return 'java';
        }
        if (/#include|std::/.test(c)) {
            return 'cpp';
        }
        return 'python';
    };

    const highlightCode = (code, lang) => {
        let s = esc(code);
        if (lang === 'python' || lang === 'sql') {
            s = s.replace(/(^|[^:])(#.*)$/gm, '$1<span class="nxi-tok-cmt">$2</span>');
        } else {
            s = s.replace(/(\/\/[^\n]*)/g, '<span class="nxi-tok-cmt">$1</span>');
        }
        s = s.replace(/("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')/g, '<span class="nxi-tok-str">$1</span>');
        s = s.replace(/\b(\d+\.?\d*)\b/g, '<span class="nxi-tok-num">$1</span>');
        const kw = LANG_KW[lang] || LANG_KW.python;
        s = s.replace(new RegExp('\\b(' + kw + ')\\b', 'g'), '<span class="nxi-tok-kw">$1</span>');
        s = s.replace(/\b([A-Za-z_]\w*)(?=\s*\()/g, '<span class="nxi-tok-fn">$1</span>');
        return s;
    };

    const dedent = (code) => {
        const lines = String(code || '').replace(/\t/g, '    ').replace(/\r\n/g, '\n').split('\n');
        while (lines.length && !lines[0].trim()) {
            lines.shift();
        }
        while (lines.length && !lines[lines.length - 1].trim()) {
            lines.pop();
        }
        let min = Infinity;
        lines.forEach(function(l) {
            if (!l.trim()) {
                return;
            }
            min = Math.min(min, (l.match(/^ +/) || [''])[0].length);
        });
        if (!isFinite(min)) {
            min = 0;
        }
        return lines.map(function(l) {
            return l.slice(min);
        }).join('\n');
    };

    const looksCodeLine = (line) => {
        const t = String(line || '');
        if (!t.trim()) {
            return false;
        }
        return /^\s*(def |async def |class \w+|function |#include|public |private |protected )/.test(t)
            || (/[{};]\s*$/.test(t) && /[()=]/.test(t) && /^\s{2,}/.test(t));
    };

    const extractSnippets = (raw) => {
        const snippets = [];
        let rest = String(raw || '');
        rest = rest.replace(/```([A-Za-z0-9_+-]*)[ \t]*\r?\n?([\s\S]*?)```/g, function(_, lang, body) {
            const code = dedent(body);
            if (code.trim()) {
                snippets.push({lang: detectLang(lang, code), code: code});
            }
            return '\n';
        });
        rest = rest.replace(/```([A-Za-z0-9_+-]*)[ \t]*\r?\n?([\s\S]*)$/g, function(_, lang, body) {
            const code = dedent(body);
            if (code.trim()) {
                snippets.push({lang: detectLang(lang, code), code: code});
            }
            return '\n';
        });
        if (!snippets.length) {
            const lines = rest.split('\n');
            const kept = [];
            let i = 0;
            while (i < lines.length) {
                if (looksCodeLine(lines[i])) {
                    const start = i;
                    i += 1;
                    while (i < lines.length && (looksCodeLine(lines[i]) || !String(lines[i]).trim())) {
                        i += 1;
                    }
                    let end = i;
                    while (end > start && !String(lines[end - 1]).trim()) {
                        end -= 1;
                    }
                    const block = lines.slice(start, end).join('\n');
                    const nonempty = block.split('\n').filter(function(l) {
                        return l.trim();
                    });
                    if (nonempty.length >= 2 || /def |class |function |#include/.test(block)) {
                        snippets.push({lang: detectLang('', block), code: dedent(block)});
                    } else {
                        kept.push.apply(kept, lines.slice(start, i));
                    }
                    continue;
                }
                kept.push(lines[i]);
                i += 1;
            }
            if (snippets.length) {
                rest = kept.join('\n');
            }
        }
        return {
            prose: rest.replace(/\n{3,}/g, '\n\n').trim(),
            snippets: snippets
        };
    };

    const stripForSpeech = (text) => {
        const parsed = extractSnippets(text);
        let spoken = parsed.prose;
        if (parsed.snippets.length) {
            spoken = (spoken ? spoken + ' ' : '') + 'Look at the code snippet on your screen.';
        }
        return spoken
            .replace(/`([^`]+)`/g, '$1')
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/\*([^*]+)\*/g, '$1')
            .replace(/#{1,6}\s*/g, '')
            .replace(/\n+/g, '. ')
            .replace(/\s+/g, ' ')
            .trim();
    };

        const isWeakUtterance = (text) => {
        const clean = String(text || '').trim().replace(/\s+/g, ' ');
        if (clean.length < 12) {
            return true;
        }
        // Common TTS-echo / hallucination phrases when the student is silent.
        if (/^(um+|uh+|ah+|ok|okay|hmm+|mhm+|huh|yes|yeah|yep|no|nope|thanks|thank you|sure|right|alright|hello|hi|hey)[.!,?\s]*$/i.test(clean)) {
            return true;
        }
        if (/^(thank you|thanks|okay sure|ok sure|all right|you'?re welcome|nice to meet you)[.!,?\s]*$/i.test(clean)) {
            return true;
        }
        // Mic/TTS bleed that becomes "you you you you…"
        if (/^(you[\s,]+)+you[.!,?\s]*$/i.test(clean) || /^(you\s+){3,}you[.!,?\s]*$/i.test(clean)) {
            return true;
        }
        const words = clean.match(/[A-Za-z0-9_]+/g) || [];
        if (words.length < 4) {
            return true;
        }
        // Reject filler-heavy noise ("okay okay um yes").
        const fillers = /^(um+|uh+|ah+|hm+|hmm+|er+|oh+|ok|okay|yes|yeah|no|mhm+|huh|what|thanks|thank|you)$/i;
        const content = words.filter(function(w) { return !fillers.test(w); });
        return content.length < 3;
    };

    const errText = (err) => {
        if (!err) {
            return 'Unknown error';
        }
        if (typeof err === 'string') {
            return err;
        }
        return err.message || err.error || err.debuginfo || JSON.stringify(err).slice(0, 200);
    };

    const call = (cfg, action, extra) => {
        extra = extra || {};
        const safe = function(v, max) {
            let s = String(v == null ? '' : v);
            // Strip unpaired surrogates / control chars that break Moodle PARAM_RAW UTF-8 checks.
            s = s.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
                .replace(/[\uD800-\uDFFF]/g, '');
            if (max && s.length > max) {
                s = s.slice(0, max);
            }
            return s;
        };
        const toB64Resume = function(v) {
            const s = safe(v, 16000);
            if (!s) {
                return '';
            }
            try {
                // ASCII base64 avoids Moodle rejecting non-UTF8 resume scrapes.
                return 'b64:' + btoa(unescape(encodeURIComponent(s)));
            } catch (e) {
                return safe(s, 16000);
            }
        };
        const args = {
            action: safe(action || '', 40),
            sessionid: safe(extra.sessionid || cfg.sessionid || '', 80),
            message: safe(extra.message || '', 8000),
            code: safe(extra.code || '', 200000),
            mode: safe(extra.mode || 'sample', 40),
            roletrack: safe(extra.roletrack || cfg.roletrack || 'sde_intern', 40),
            topics: safe(extra.topics || cfg.topics || '', 500),
            resume: toB64Resume(extra.resume || cfg.resume || ''),
            problemid: parseInt(extra.problemid || cfg.problemid || 0, 10) || 0,
            audio: extra.audio || '',
            durationsec: Number(extra.durationsec || 0) || 0,
            interviewerid: parseInt(extra.interviewerid || cfg.interviewerid || 0, 10) || 0
        };
        // Course activity sessions go through mod_nexinterview_proxy with cmid + duration.
        if (cfg.cmid) {
            args.cmid = parseInt(cfg.cmid, 10) || 0;
        }
        if (cfg.durationminutes) {
            args.durationminutes = parseInt(cfg.durationminutes, 10) || 0;
        }
        return Ajax.call([{
            methodname: cfg.methodname || 'local_nexinterview_proxy',
            args: args
        }])[0].then(function(resp) {
            return JSON.parse(resp.payload || '{}');
        });
    };

    const createEngine = (root, cfg) => {
        const lang = cfg.voicelang || 'en-IN';
        const statusEl = root.querySelector('[data-region="status"]');
        const captionEl = root.querySelector('[data-region="caption"]');
        const youSaidEl = root.querySelector('[data-region="yousaid"]');
        const chatEl = root.querySelector('[data-region="chat"]');
        const snippetStage = root.querySelector('[data-region="snippet-stage"]');
        const historyEl = root.querySelector('[data-region="history"]');
        const turnEl = root.querySelector('[data-region="turn"]');
        const signalEl = root.querySelector('[data-region="signal"]');
        const eqCanvas = root.querySelector('[data-region="eq"]');
        const voiceViz = root.querySelector('[data-region="voice-viz"]');
        let lastChatAiText = '';
        let streamCaptionActive = false;
        let thinkingEl = null;
        let mutedMic = false;
        let camOn = true;
        let mediaStream = null;
        let recognition = null;
        let listening = false;
        let aiSpeaking = false;
        let lastSpokenSeq = 0;
        const spokenNorms = [];
        let silenceTimer = null;
        let pendingFinal = '';
        let currentAudio = null;
        let audioObjectUrl = null;
        let audioCtx = null;
        let analyser = null;
        let mediaSource = null;
        let eqRaf = null;
        let eqData = null;
        let ampSmooth = 0.05;
        let vizHot = false;
        let eqTapGain = null;
        let ideReady = false;
        let mountedProblemId = 0;
        let lastTurns = [];
        let mediaRecorder = null;
        let recordedChunks = [];
        let useWhisper = true;
        let preferRealtimeSpeak = true; // Prefer Realtime voice; fall back to HD TTS
        let useRealtime = !!cfg.realtime; // enabled from Moodle unless admin disables
        let pc = null;
        let dc = null;
        let remoteAudio = null;
        let realtimeReady = false;
        let realtimeConnecting = false;
        let awaitingEngineReply = false;
        let speechStartedAt = 0;
        let editorLocked = false;
        let gladiaWs = null;
        let gladiaReady = false;
        let gladiaConnecting = false;
        let gladiaFailed = false;
        let gladiaSending = false;
        let gladiaSampleRate = 16000;
        let gladiaProcessor = null;
        let gladiaMicSource = null;
        let gladiaGain = null;
        let gladiaPartial = '';
        let gladiaLastFinalId = '';
        let gladiaSpeechStartedAt = 0;
        let pendingSubmitTimer = null;
        let pendingSubmitText = '';
        let speakDone = null; // resolve early on barge-in
        let speakGen = 0;
        let captionTimer = null;
        let bargeHoldTimer = null;
        let bargeHoldStartedAt = 0;
        let bargeHoldStartText = '';
        let aiSpeakStartedAt = 0;
        let lastAiSpokenText = '';
        let lastAiSpeakEndedAt = 0;
        let postSpeakTimer = null;
        let pendingSubmitStartedAt = 0;
        // Listening window generation: STT captured while AI speaks / thinks must never
        // become the answer to the *next* question after the interviewer finishes.
        let sttListenGen = 0;
        let sttOpenGen = -1;
        let bargeInActive = false;
        let supervisorTimer = null;
        let lastSttActivityAt = 0;
        let lastListenStartedAt = 0;
        let listenVoiceMs = 0;
        let pendingHoldStartedAt = 0;
        let missedHintTimer = null;
        const GRACE_MS = 1800;
        const GRACE_HARD_MS = 4500;
        // Short settle only — long windows used to swallow answers that started
        // immediately after the interviewer stopped talking.
        const POST_SPEAK_SETTLE_MS = 700;
        // Discard delayed STT finals that arrive after AI TTS (speaker bleed).
        const ECHO_BLACKOUT_MS = 900;
        // Held utterances wait for the blackout / thinking window instead of being dropped.
        const HOLD_RETRY_MS = 220;
        const HOLD_MAX_MS = 20000;
        // How long a "listening" state may produce zero transcript before we rebuild it.
        const STT_STALL_MS = 20000;
        // Barge-in kept for Web Speech only; Gladia PCM is hard-muted during AI speech.
        const BARGE_MIN_CHARS = 42;
        const BARGE_MIN_WORDS = 7;
        const BARGE_FINAL_WORDS = 8;
        const BARGE_HOLD_MS = 1400;
        const BARGE_GUARD_MS = 2200;
        const BARGE_GROW_CHARS = 12;
        const SILENCE_END_MS = 2200;
        const MIN_VOICE_MS_BEFORE_SUBMIT = 400;

        // Status under the mic was removed — turn badge + captions carry state.
        const setStatus = (msg) => {
            if (statusEl) {
                statusEl.textContent = msg || '';
                statusEl.hidden = true;
            }
        };

        const setTurnState = (turn) => {
            const next = turn || 'idle';
            root.dataset.turn = next;
            root.classList.toggle('is-ai-speaking', next === 'agent');
            root.classList.toggle('is-user-turn', next === 'user');
            root.classList.toggle('is-thinking', next === 'thinking');
            if (voiceViz) {
                voiceViz.classList.toggle('is-speaking', next === 'agent');
            }
            if (signalEl) {
                signalEl.classList.toggle('is-active', next === 'agent');
            }
            if (turnEl) {
                turnEl.dataset.turn = next;
                const labels = {
                    agent: 'NexAI speaking',
                    user: 'Your turn — listening',
                    thinking: 'Thinking…',
                    idle: 'Ready',
                };
                turnEl.textContent = labels[next] || 'Ready';
            }
        };

        const setSpeakingUi = (on) => {
            if (on) {
                vizHot = true;
                setTurnState('agent');
                startDome();
                return;
            }
            if (!aiSpeaking) {
                vizHot = false;
            }
            if (awaitingEngineReply) {
                setTurnState('thinking');
            } else if (!mutedMic) {
                setTurnState('user');
            } else {
                setTurnState('idle');
            }
        };

        const b64ToBlob = (b64, contentType) => {
            const bin = atob(b64);
            const len = bin.length;
            const bytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) {
                bytes[i] = bin.charCodeAt(i);
            }
            return new Blob([bytes], {type: contentType || 'audio/mpeg'});
        };

        const unlockSpeech = () => {
            try {
                const silent = new Audio(
                    'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA'
                );
                silent.volume = 0.01;
                silent.play().catch(function() { /* ignore */ });
            } catch (e) {
                // ignore
            }
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
            } catch (e2) {
                // ignore
            }
            if (window.speechSynthesis) {
                try {
                    window.speechSynthesis.cancel();
                    const warm = new SpeechSynthesisUtterance(' ');
                    warm.volume = 0;
                    window.speechSynthesis.speak(warm);
                    window.speechSynthesis.cancel();
                } catch (e3) {
                    // ignore
                }
            }
        };

        const stopEq = () => {
            startDome();
        };

        /**
         * Live amplitude 0..1. vizHot stays true for the whole spoken turn —
         * realtime used to flip aiSpeaking off on response.done while audio
         * was still playing, which is why the texture died after the intro.
         */
        const readAmp = () => {
            const hot = vizHot || aiSpeaking || (root.dataset.turn === 'agent');
            let target = 0.06;
            if (hot) {
                let level = 0;
                if (analyser) {
                    if (!eqData || eqData.length !== analyser.frequencyBinCount) {
                        eqData = new Uint8Array(analyser.frequencyBinCount);
                    }
                    analyser.getByteFrequencyData(eqData);
                    let sum = 0;
                    const n = eqData.length;
                    for (let i = 0; i < n; i++) {
                        sum += eqData[i];
                    }
                    level = sum / Math.max(1, n) / 90;
                }
                if (level < 0.08) {
                    const tt = Date.now() / 1000;
                    level = 0.48 + 0.26 * Math.sin(tt * 5.4) + 0.14 * Math.sin(tt * 12.2);
                }
                target = Math.max(0.32, Math.min(1, level));
            }
            ampSmooth += (target - ampSmooth) * (target > ampSmooth ? 0.32 : 0.14);
            return ampSmooth;
        };

        const drawEqFrame = () => {
            if (!eqCanvas) {
                eqRaf = null;
                return;
            }
            eqRaf = requestAnimationFrame(drawEqFrame);
            const ctx = eqCanvas.getContext('2d');
            if (!ctx) {
                return;
            }
            const w = eqCanvas.width;
            const h = eqCanvas.height;
            ctx.clearRect(0, 0, w, h);

            const amp = readAmp();
            const t = Date.now() / 1000;
            const hot = vizHot || aiSpeaking || (root.dataset.turn === 'agent');

            const glow = ctx.createRadialGradient(w / 2, h, 0, w / 2, h, h * 1.2);
            if (hot) {
                glow.addColorStop(0, 'rgba(45, 212, 191,' + (0.18 + amp * 0.22) + ')');
                glow.addColorStop(0.5, 'rgba(20, 130, 130,' + (0.06 + amp * 0.08) + ')');
            } else {
                glow.addColorStop(0, 'rgba(148, 163, 184, .08)');
                glow.addColorStop(0.5, 'rgba(100, 116, 139, .03)');
            }
            glow.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.fillStyle = glow;
            ctx.fillRect(0, 0, w, h);

            const cols = 88;
            const grains = 8;
            for (let c = 0; c < cols; c++) {
                const cx = (c + 0.5) / cols;
                const dome = Math.pow(Math.max(0, Math.cos((cx - 0.5) * Math.PI)), 1.45);
                if (dome <= 0.004) {
                    continue;
                }
                const wave =
                    Math.sin(cx * 8 + t * 1.7) * 0.5 +
                    Math.sin(cx * 17 - t * 1.2) * 0.3 +
                    Math.sin(cx * 31 + t * 2.3) * 0.2;
                const colH = h * dome * (0.2 + amp * 0.78) * (0.78 + 0.28 * wave);
                const x0 = cx * w;
                for (let g = 0; g < grains; g++) {
                    const gt = (g + 0.35) / grains;
                    const jx = Math.sin(c * 11.1 + g * 7.3 + t * 1.4) * (w / cols) * 1.4;
                    const y = h - gt * colH - Math.abs(Math.cos(c * 3.2 + g + t * 1.8)) * 2;
                    if (y < 0 || y > h) {
                        continue;
                    }
                    const fade = Math.pow(1 - gt, 1.55);
                    const alpha = (hot ? 0.12 + amp * 0.64 : 0.14) * fade;
                    if (alpha <= 0.014) {
                        continue;
                    }
                    const size = 0.85 + fade * (hot ? 1.7 + amp * 1.5 : 1.05);
                    ctx.beginPath();
                    if (hot && gt < 0.24 && amp > 0.5) {
                        ctx.fillStyle = 'rgba(209, 250, 229,' + Math.min(0.88, alpha + 0.14) + ')';
                    } else if (hot) {
                        ctx.fillStyle = 'rgba(45, 212, 191,' + alpha + ')';
                    } else {
                        ctx.fillStyle = 'rgba(148, 163, 184,' + alpha + ')';
                    }
                    ctx.arc(x0 + jx, y, size, 0, Math.PI * 2);
                    ctx.fill();
                }
            }
        };

        const startDome = () => {
            if (!eqRaf && eqCanvas) {
                drawEqFrame();
            }
        };

        const attachAnalyser = (node) => {
            if (!node) {
                return;
            }
            if (!analyser) {
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = 256;
                analyser.smoothingTimeConstant = 0.8;
                eqTapGain = audioCtx.createGain();
                eqTapGain.gain.value = 0;
                analyser.connect(eqTapGain);
                eqTapGain.connect(audioCtx.destination);
            }
            try { node.connect(analyser); } catch (eC) { /* already connected */ }
        };

        const ensureAudioCtx = () => {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            return audioCtx;
        };

        const hookAnalyser = (audioEl) => {
            try {
                ensureAudioCtx();
                if (!audioEl._nxiSource) {
                    audioEl._nxiSource = audioCtx.createMediaElementSource(audioEl);
                    audioEl._nxiSource.connect(audioCtx.destination);
                }
                mediaSource = audioEl._nxiSource;
                attachAnalyser(mediaSource);
                startDome();
            } catch (e) {
                // Visuals fall back to the synthetic amplitude.
            }
            setSpeakingUi(true);
        };

        /**
         * Realtime voice arrives as a MediaStream — tap the stream so the grain
         * field tracks speech. Analyser is wired to a silent gain so the graph runs.
         */
        const hookAnalyserStream = (stream) => {
            try {
                if (!stream) {
                    return;
                }
                ensureAudioCtx();
                if (!stream._nxiTap) {
                    stream._nxiTap = audioCtx.createMediaStreamSource(stream);
                }
                attachAnalyser(stream._nxiTap);
                startDome();
            } catch (e) {
                // ignore — synthetic amplitude keeps the texture alive
            }
        };

        const stopCaptionReveal = () => {
            if (captionTimer) {
                clearInterval(captionTimer);
                captionTimer = null;
            }
        };

        const stopTTS = () => {
            stopCaptionReveal();
            stopEq();
            if (currentAudio) {
                try {
                    currentAudio.pause();
                    currentAudio.ontimeupdate = null;
                    currentAudio.onended = null;
                    currentAudio.onerror = null;
                    currentAudio.src = '';
                } catch (e) {
                    // ignore
                }
                currentAudio = null;
                mediaSource = null;
            }
            if (audioObjectUrl) {
                try {
                    URL.revokeObjectURL(audioObjectUrl);
                } catch (e2) {
                    // ignore
                }
                audioObjectUrl = null;
            }
            if (window.speechSynthesis) {
                window.speechSynthesis.cancel();
            }
            aiSpeaking = false;
        };

        const captionUnits = (text) => {
            return extractSnippets(text).prose.split(/\s+/).filter(Boolean).map(function(w) {
                return {type: 'word', value: w};
            });
        };

        const mountSnippet = (snippets) => {
            if (!snippetStage) {
                return;
            }
            if (!snippets || !snippets.length) {
                snippetStage.hidden = true;
                snippetStage.innerHTML = '';
                return;
            }
            snippetStage.hidden = false;
            snippetStage.innerHTML = snippets.map(function(sn) {
                const lang = sn.lang || 'code';
                return '<div class="nxi-snippet-wrap">' +
                    '<p class="nxi-snippet__label">' + esc(lang) + '</p>' +
                    '<pre class="nxi-snippet" data-lang="' + esc(lang) + '"><code>' +
                    highlightCode(sn.code, lang) + '</code></pre></div>';
            }).join('');
        };

        const scrollChat = () => {
            if (chatEl) {
                chatEl.scrollTop = chatEl.scrollHeight;
            }
        };

        const appendChatBubble = (role, text) => {
            if (!chatEl) {
                return;
            }
            const clean = String(text || '').trim();
            if (!clean) {
                return;
            }
            // Avoid duplicate consecutive AI bubbles (e.g. replay).
            if (role === 'assistant' && clean === lastChatAiText) {
                return;
            }
            if (role === 'assistant') {
                lastChatAiText = clean;
            }
            const el = document.createElement('div');
            el.className = 'nxi-bubble nxi-bubble--' + (role === 'assistant' ? 'ai' : 'you');
            const who = document.createElement('span');
            who.className = 'nxi-bubble__who';
            who.textContent = role === 'assistant' ? 'NexAI' : 'You';
            const body = document.createElement('div');
            body.className = 'nxi-bubble__text';
            body.textContent = clean;
            el.appendChild(who);
            el.appendChild(body);
            chatEl.appendChild(el);
            scrollChat();
        };

        const hideThinking = () => {
            if (thinkingEl && thinkingEl.parentNode) {
                thinkingEl.parentNode.removeChild(thinkingEl);
            }
            thinkingEl = null;
        };

        const showThinking = () => {
            if (!chatEl || thinkingEl) {
                return;
            }
            const el = document.createElement('div');
            el.className = 'nxi-bubble nxi-bubble--ai nxi-bubble--thinking';
            el.innerHTML = '<span class="nxi-bubble__who">NexAI</span>' +
                '<span class="nxi-dots"><i></i><i></i><i></i></span>';
            chatEl.appendChild(el);
            thinkingEl = el;
            scrollChat();
        };

        const setStreamCaptionVisible = (on) => {
            if (on) {
                hideThinking();
            }
            if (!captionEl) {
                return;
            }
            captionEl.hidden = !on;
            streamCaptionActive = !!on;
            if (!on) {
                captionEl.innerHTML = '';
            }
        };

        const revealCaption = (fullText, progress, gen) => {
            if (gen != null && gen !== speakGen) {
                return;
            }
            if (!captionEl) {
                return;
            }
            const parsed = extractSnippets(fullText);
            const prose = parsed.prose || String(fullText || '').trim();
            const p = Math.min(1, Math.max(0, progress == null ? 1 : Number(progress)));
            // Code cards appear only once speech has meaningfully started.
            if (p >= 0.08) {
                mountSnippet(parsed.snippets);
            }
            const words = prose.split(/\s+/).filter(Boolean);
            if (!words.length && !parsed.snippets.length) {
                captionEl.innerHTML = '<span class="nxi-caption__placeholder">NexAI is speaking…</span>';
                setStreamCaptionVisible(true);
                return;
            }
            if (!words.length) {
                captionEl.innerHTML = '';
                return;
            }
            setStreamCaptionVisible(true);
            const n = Math.max(p > 0 ? 1 : 0, Math.ceil(words.length * p));
            const shown = words.slice(0, Math.min(words.length, n)).join(' ');
            const typing = p < 0.995;
            captionEl.innerHTML = shown
                ? ('<div class="nxi-caption__live' + (typing ? '' : ' nxi-caption__live--done') +
                    '"><span class="nxi-caption__text">' + esc(shown) + '</span></div>')
                : '<span class="nxi-caption__placeholder">NexAI is speaking…</span>';
        };

        const finalizeAiChat = (fullText) => {
            stopCaptionReveal();
            hideThinking();
            setStreamCaptionVisible(false);
            const parsed = extractSnippets(fullText);
            const prose = (parsed.prose || String(fullText || '')).trim();
            if (prose) {
                appendChatBubble('assistant', prose);
            }
            mountSnippet(parsed.snippets);
        };

        const startCaptionReveal = (fullText, durationMs, gen) => {
            stopCaptionReveal();
            const words = captionUnits(fullText);
            if (!words.length) {
                revealCaption(fullText, 1, gen);
                return;
            }
            const start = Date.now();
            const dur = Math.max(900, durationMs || Math.min(32000, 450 + words.length * 330));
            revealCaption(fullText, 0.02, gen);
            captionTimer = setInterval(function() {
                if (gen != null && gen !== speakGen) {
                    stopCaptionReveal();
                    return;
                }
                const p = Math.min(1, (Date.now() - start) / dur);
                revealCaption(fullText, p, gen);
                if (p >= 1) {
                    stopCaptionReveal();
                }
            }, 40);
        };

        const pushHistory = (turns) => {
            // Hydrate chat from prior turns (resume / mid-session). Skip the last
            // assistant line — that one is spoken first, then finalized into chat.
            if (!chatEl) {
                return;
            }
            const items = (turns || []).filter(function(t) {
                return t.role === 'assistant' || t.role === 'student';
            });
            if (!items.length) {
                return;
            }
            let list = items;
            const last = items[items.length - 1];
            if (last && last.role === 'assistant') {
                list = items.slice(0, -1);
            }
            if (!list.length || chatEl.childElementCount > 0) {
                return;
            }
            list.forEach(function(t) {
                appendChatBubble(t.role === 'assistant' ? 'assistant' : 'you', t.content);
            });
        };


        const sendRealtimeEvent = (ev) => {
            if (!dc || dc.readyState !== 'open') {
                return false;
            }
            try {
                dc.send(JSON.stringify(ev));
                return true;
            } catch (e) {
                return false;
            }
        };

        const interruptRealtime = () => {
            sendRealtimeEvent({type: 'response.cancel'});
            aiSpeaking = false;
            vizHot = false;
            setSpeakingUi(false);
        };

        const speakViaRealtime = (clean, displayText, gen) => new Promise(function(resolve) {
            if (!realtimeReady || !clean) {
                resolve(false);
                return;
            }
            const captionSrc = displayText || clean;
            const myGen = gen != null ? gen : speakGen;
            aiSpeaking = true;
            vizHot = true;
            setSpeakingUi(true);
            startDome();
            try {
                if (remoteAudio && remoteAudio.srcObject) {
                    hookAnalyserStream(remoteAudio.srcObject);
                }
            } catch (eTap) { /* synthetic amp still runs */ }
            setStreamCaptionVisible(false);
            let captionStarted = false;
            let settled = false;
            const finish = function(ok) {
                if (settled) {
                    return;
                }
                settled = true;
                stopCaptionReveal();
                if (myGen !== speakGen) {
                    resolve(false);
                    return;
                }
                if (!ok) {
                    // HD TTS fallback still has to speak this turn — stay "speaking"
                    // so the grain texture and mic gating keep running.
                    resolve(false);
                    return;
                }
                aiSpeaking = false;
                setSpeakingUi(false);
                resolve(true);
            };
            const beginCaption = function() {
                if (captionStarted || myGen !== speakGen) {
                    return;
                }
                captionStarted = true;
                const words = captionUnits(captionSrc);
                startCaptionReveal(captionSrc, Math.min(32000, 900 + words.length * 340), myGen);
            };
            const onMsg = function(ev) {
                let data = null;
                try { data = JSON.parse(ev.data); } catch (e) { return; }
                const typ = data && data.type;
                if (typ === 'output_audio_buffer.started' || typ === 'response.output_audio.delta' ||
                        typ === 'response.audio.delta') {
                    setSpeakingUi(true);
                    beginCaption();
                }
                // Finish only when playback stops — response.done fires while audio
                // is still coming out of the speaker, which killed the texture.
                if (typ === 'output_audio_buffer.stopped' || typ === 'response.output_audio.done') {
                    dc.removeEventListener('message', onMsg);
                    finish(true);
                }
                if (typ === 'error') {
                    dc.removeEventListener('message', onMsg);
                    finish(false);
                }
            };
            dc.addEventListener('message', onMsg);
            const ok = sendRealtimeEvent({
                type: 'response.create',
                response: {
                    modalities: ['audio', 'text'],
                    instructions:
                        'You are a calm, professional interviewer. Speak the following line warmly ' +
                        'at a natural conversational pace (not rushed). Stop when finished. ' +
                        'Do not add greetings, questions, or commentary beyond this exact text:\n\n' + clean
                }
            });
            if (!ok) {
                finish(false);
                return;
            }
            // If audio events are delayed, start caption after a short delay — never at t=0 with full text.
            setTimeout(function() {
                if (!settled && myGen === speakGen) {
                    beginCaption();
                }
            }, 450);
            setTimeout(function() {
                if (!settled) {
                    try { dc.removeEventListener('message', onMsg); } catch (e2) {}
                    finish(true);
                }
            }, Math.min(90000, 6000 + Math.max(clean.split(/\s+/).length, 1) * 420));
        });

        const connectRealtime = () => {
            // Realtime is used for AI speech (Chakra-like voice). Mic STT stays Gladia/Whisper.
            // Default on when cfg.realtime is true; falls back to HD TTS if token/WebRTC fails.
            if (cfg.realtime === false || cfg.realtime === 0 || cfg.realtime === '0') {
                useRealtime = false;
                return Promise.resolve(false);
            }
            useRealtime = true;
            if (realtimeReady || realtimeConnecting) {
                return Promise.resolve(realtimeReady);
            }
            if (!cfg.sessionid || !window.RTCPeerConnection) {
                useRealtime = false;
                return Promise.resolve(false);
            }
            realtimeConnecting = true;
            return call(cfg, 'realtime_token', {sessionid: cfg.sessionid}).then(function(parsed) {
                const token = (parsed && parsed.realtime) || {};
                if (!token.ok || !token.value) {
                    throw new Error(token.error || 'realtime_token_failed');
                }
                pc = new RTCPeerConnection();
                remoteAudio = document.createElement('audio');
                remoteAudio.autoplay = true;
                remoteAudio.setAttribute('playsinline', 'true');
                document.body.appendChild(remoteAudio);
                pc.ontrack = function(e) {
                    remoteAudio.srcObject = e.streams[0];
                    // Tap the stream (not the element) so the grain texture tracks
                    // realtime speech instead of reading silence.
                    try { hookAnalyserStream(e.streams[0]); } catch (errA) { /* ignore */ }
                };
                if (mediaStream) {
                    mediaStream.getAudioTracks().forEach(function(track) {
                        pc.addTrack(track, mediaStream);
                    });
                }
                dc = pc.createDataChannel('oai-events');
                dc.addEventListener('open', function() {
                    // Explicitly enable input transcription (required for completed events).
                    sendRealtimeEvent({
                        type: 'session.update',
                        session: {
                            type: 'realtime',
                            input_audio_transcription: {model: 'whisper-1'},
                            turn_detection: {
                                type: 'server_vad',
                                create_response: false,
                                interrupt_response: true,
                                silence_duration_ms: 2000,
                                threshold: 0.55
                            }
                        }
                    });
                    realtimeReady = true;
                    preferRealtimeSpeak = cfg.realtimeSpeak !== false && cfg.realtimeSpeak !== 0 &&
                        cfg.realtimeSpeak !== '0';
                    realtimeConnecting = false;
                });
                dc.addEventListener('message', function(ev) {
                    let data = null;
                    try { data = JSON.parse(ev.data); } catch (e) { return; }
                    const typ = data && data.type;
                    if (typ === 'input_audio_buffer.speech_started') {
                        speechStartedAt = Date.now();
                        // Only barge-in if AI has been speaking for a bit (avoid mic echo blips).
                        if (aiSpeaking && preferRealtimeSpeak &&
                                (Date.now() - aiSpeakStartedAt) > BARGE_GUARD_MS) {
                            interruptRealtime();
                        }
                    }
                    if (typ === 'conversation.item.input_audio_transcription.completed') {
                        // Gladia/Whisper remain primary STT — ignore realtime transcripts for submit
                        // to avoid double answers / echo. Keep for future dual-path if needed.
                        speechStartedAt = 0;
                    }
                    if (typ === 'error') {
                        try { console.warn('Realtime error', data); } catch (e2) {}
                    }
                });
                return pc.createOffer().then(function(offer) {
                    return pc.setLocalDescription(offer).then(function() {
                        return fetch('https://api.openai.com/v1/realtime/calls', {
                            method: 'POST',
                            body: offer.sdp,
                            headers: {
                                Authorization: 'Bearer ' + token.value,
                                'Content-Type': 'application/sdp'
                            }
                        });
                    });
                }).then(function(resp) {
                    if (!resp.ok) {
                        return resp.text().then(function(body) {
                            throw new Error('WebRTC SDP failed: ' + resp.status + ' ' + body.slice(0, 180));
                        });
                    }
                    return resp.text();
                }).then(function(answerSdp) {
                    return pc.setRemoteDescription({type: 'answer', sdp: answerSdp});
                }).then(function() {
                    realtimeConnecting = false;
                    return true;
                });
            }).catch(function(err) {
                realtimeConnecting = false;
                useRealtime = false;
                realtimeReady = false;
                preferRealtimeSpeak = false;
                try { console.warn('NexInterview realtime failed — using HD TTS', err); } catch (e) {}
                return false;
            });
        };

        const speakCloudSynced = (clean, gen, displayText) => call(cfg, 'tts', {
            sessionid: cfg.sessionid || '',
            message: clean
        }).then(function(parsed) {
            if (gen != null && gen !== speakGen) {
                return;
            }
            const tts = (parsed && parsed.tts) || {};
            if (!tts.ok || !tts.audio_base64) {
                throw new Error(tts.error || 'Cloud voice unavailable');
            }
            const captionSrc = displayText || clean;
            return new Promise(function(resolve, reject) {
                if (gen != null && gen !== speakGen) {
                    resolve();
                    return;
                }
                const blob = b64ToBlob(tts.audio_base64, tts.content_type || 'audio/mpeg');
                if (audioObjectUrl) {
                    URL.revokeObjectURL(audioObjectUrl);
                }
                audioObjectUrl = URL.createObjectURL(blob);
                const audio = new Audio(audioObjectUrl);
                audio.crossOrigin = 'anonymous';
                audio.volume = 1;
                currentAudio = audio;
                aiSpeaking = true;
                setSpeakingUi(true);
                hookAnalyser(audio);
                startDome();
                stopCaptionReveal();
                const startedAt = Date.now();
                const words = captionUnits(captionSrc);
                const estimatedMs = Math.max(1200, Math.min(45000, 500 + words.length * 340));
                const sync = function() {
                    if (gen != null && gen !== speakGen) {
                        stopCaptionReveal();
                        try { audio.ontimeupdate = null; } catch (eSync) { /* ignore */ }
                        return;
                    }
                    let p = 0;
                    if (audio.duration && isFinite(audio.duration) && audio.duration > 0.25) {
                        p = audio.currentTime / audio.duration;
                    } else {
                        p = (Date.now() - startedAt) / estimatedMs;
                    }
                    revealCaption(captionSrc, Math.min(0.98, Math.max(0, p)), gen);
                };
                revealCaption(captionSrc, 0.02, gen);
                captionTimer = setInterval(sync, 40);
                audio.ontimeupdate = sync;
                audio.onended = function() {
                    if (gen != null && gen !== speakGen) {
                        resolve();
                        return;
                    }
                    stopCaptionReveal();
                    try { audio.ontimeupdate = null; } catch (eEnd) { /* ignore */ }
                    resolve();
                };
                audio.onerror = function() {
                    stopCaptionReveal();
                    reject(new Error('Audio playback failed'));
                };
                const p = audio.play();
                if (p && typeof p.then === 'function') {
                    p.catch(reject);
                }
            });
        });

        const speakBrowserFallback = (clean, displayText, gen) => new Promise(function(resolve) {
            const captionSrc = displayText || clean;
            const myGen = gen != null ? gen : speakGen;
            aiSpeaking = true;
            setSpeakingUi(true);
            startDome();
            const words = captionUnits(captionSrc);
            startCaptionReveal(captionSrc, Math.min(32000, 700 + words.length * 320), myGen);
            if (!window.speechSynthesis) {
                setSpeakingUi(false);
                resolve();
                return;
            }
            const utter = new SpeechSynthesisUtterance(clean);
            utter.lang = lang;
            utter.rate = 1.02;
            utter.onend = function() {
                if (myGen !== speakGen) {
                    resolve();
                    return;
                }
                stopCaptionReveal();
                setSpeakingUi(false);
                resolve();
            };
            utter.onerror = function() {
                if (myGen !== speakGen) {
                    resolve();
                    return;
                }
                stopCaptionReveal();
                setSpeakingUi(false);
                resolve();
            };
            window.speechSynthesis.speak(utter);
        });

        const showYouSaid = (text, isPartial) => {
            // HackerRank-style: never show live mic transcript. Final user text goes to chat.
            if (isPartial) {
                return;
            }
            if (youSaidEl) {
                youSaidEl.hidden = true;
                youSaidEl.textContent = '';
            }
        };

        const inPostSpeakSettle = () => {
            return lastAiSpeakEndedAt > 0 && (Date.now() - lastAiSpeakEndedAt) < POST_SPEAK_SETTLE_MS;
        };

        const inEchoBlackout = () => {
            return lastAiSpeakEndedAt > 0 && (Date.now() - lastAiSpeakEndedAt) < ECHO_BLACKOUT_MS;
        };

        const clearSttBuffers = () => {
            if (pendingSubmitTimer) {
                clearTimeout(pendingSubmitTimer);
                pendingSubmitTimer = null;
            }
            pendingSubmitText = '';
            pendingSubmitStartedAt = 0;
            pendingHoldStartedAt = 0;
            gladiaPartial = '';
            gladiaLastFinalId = '';
            pendingFinal = '';
            listenVoiceMs = 0;
            showYouSaid('', false);
        };

        const closeSttWindow = () => {
            sttListenGen += 1;
            sttOpenGen = -1;
            clearSttBuffers();
        };

        const openSttWindow = () => {
            sttOpenGen = sttListenGen;
            clearSttBuffers();
        };

        const sttWindowOpen = () => {
            return sttOpenGen >= 0 && sttOpenGen === sttListenGen;
        };

        /**
         * Tell the candidate when a transcript was discarded, so a swallowed answer
         * never looks like a dead interviewer.
         */
        const noteMissedUtterance = (text) => {
            if (!String(text || '').trim() || aiSpeaking || awaitingEngineReply) {
                return;
            }
            setTurnState('user');
            if (turnEl) {
                turnEl.textContent = "Didn't catch that — say it again";
            }
            if (missedHintTimer) {
                clearTimeout(missedHintTimer);
            }
            missedHintTimer = setTimeout(function() {
                missedHintTimer = null;
                if (!aiSpeaking && !awaitingEngineReply && turnEl) {
                    setTurnState(mutedMic ? 'idle' : 'user');
                }
            }, 4000);
        };

        const scheduleListenAfterSpeak = () => {
            if (postSpeakTimer) {
                clearTimeout(postSpeakTimer);
                postSpeakTimer = null;
            }
            const run = function() {
                postSpeakTimer = null;
                if (root._redirecting || root._wrapPlaying) {
                    return;
                }
                if (aiSpeaking || awaitingEngineReply || mutedMic) {
                    return;
                }
                // Keep leftover speech only after a real barge-in. Otherwise delayed STT
                // from the previous turn / AI playback becomes the *next* answer.
                const leftover = String(pendingSubmitText || '').trim();
                if (bargeInActive && leftover && sttWindowOpen()) {
                    bargeInActive = false;
                    if (looksLikeAiEcho(leftover, true) || isWeakUtterance(leftover)) {
                        clearSttBuffers();
                    } else {
                        cancelPendingSubmit();
                        pendingSubmitTimer = setTimeout(flushPendingSubmit, 60);
                        return;
                    }
                } else {
                    bargeInActive = false;
                    clearSttBuffers();
                }
                if (inEchoBlackout()) {
                    postSpeakTimer = setTimeout(run, Math.max(80, ECHO_BLACKOUT_MS - (Date.now() - lastAiSpeakEndedAt)));
                    return;
                }
                openSttWindow();
                startListen();
            };
            waitUntilQuiet().then(function() {
                if (postSpeakTimer) {
                    clearTimeout(postSpeakTimer);
                }
                postSpeakTimer = setTimeout(run, POST_SPEAK_SETTLE_MS);
            });
        };

        const blobToB64 = (blob) => new Promise(function(resolve, reject) {
            const reader = new FileReader();
            reader.onloadend = function() {
                const res = String(reader.result || '');
                const b64 = res.indexOf(',') >= 0 ? res.split(',')[1] : res;
                resolve(b64 || '');
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });

        const floatTo16BitPCM = (float32) => {
            const out = new Int16Array(float32.length);
            for (let i = 0; i < float32.length; i++) {
                const s = Math.max(-1, Math.min(1, float32[i]));
                out[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
            }
            return out;
        };

        const downsampleTo16k = (float32, inputRate) => {
            if (!inputRate || inputRate === 16000) {
                return float32;
            }
            const ratio = inputRate / 16000;
            const newLen = Math.max(1, Math.floor(float32.length / ratio));
            const result = new Float32Array(newLen);
            let offsetResult = 0;
            let offsetBuffer = 0;
            while (offsetResult < result.length) {
                const nextOffsetBuffer = Math.floor((offsetResult + 1) * ratio);
                let accum = 0;
                let count = 0;
                for (let i = offsetBuffer; i < nextOffsetBuffer && i < float32.length; i++) {
                    accum += float32[i];
                    count++;
                }
                result[offsetResult] = count ? (accum / count) : 0;
                offsetResult++;
                offsetBuffer = nextOffsetBuffer;
            }
            return result;
        };

        const stopGladiaAudio = () => {
            gladiaSending = false;
            if (gladiaProcessor) {
                try {
                    if (gladiaProcessor._nxiTracks) {
                        gladiaProcessor._nxiTracks.getTracks().forEach(function(t) {
                            try { t.stop(); } catch (eT) { /* ignore */ }
                        });
                        gladiaProcessor._nxiTracks = null;
                    }
                } catch (eTracks) { /* ignore */ }
                try { gladiaProcessor.disconnect(); } catch (e) { /* ignore */ }
                gladiaProcessor.onaudioprocess = null;
                gladiaProcessor = null;
            }
            if (gladiaMicSource) {
                try { gladiaMicSource.disconnect(); } catch (e2) { /* ignore */ }
                gladiaMicSource = null;
            }
            if (gladiaGain) {
                try { gladiaGain.disconnect(); } catch (e3) { /* ignore */ }
                gladiaGain = null;
            }
        };

        const closeGladia = () => {
            cancelPendingSubmit();
            pendingSubmitText = '';
            stopGladiaAudio();
            if (gladiaWs) {
                try {
                    if (gladiaWs.readyState === 1) {
                        gladiaWs.send(JSON.stringify({type: 'stop_recording'}));
                    }
                } catch (e) { /* ignore */ }
                try { gladiaWs.close(); } catch (e2) { /* ignore */ }
            }
            gladiaWs = null;
            gladiaReady = false;
        };

        const isSubstantialSpeech = (text) => {
            const clean = String(text || '').trim();
            if (clean.length < BARGE_MIN_CHARS) {
                return false;
            }
            const words = clean.split(/\s+/).filter(Boolean);
            if (words.length < BARGE_MIN_WORDS) {
                return false;
            }
            // Ignore filler-heavy noise bursts.
            const fillers = /^(um+|uh+|ah+|hm+|hmm+|er+|oh+|ok|okay|yes|yeah|no|mhm+|huh|what)[.!,?\s]*$/i;
            const contentWords = words.filter(function(w) { return !fillers.test(w); });
            if (contentWords.length < 5) {
                return false;
            }
            return true;
        };

        const looksLikeAiEcho = (text, hot) => {
            const clean = String(text || '').trim().toLowerCase().replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ');
            const ai = String(lastAiSpokenText || '').trim().toLowerCase().replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ');
            if (!clean || !ai || clean.length < 8) {
                return false;
            }
            const words = clean.split(/\s+/).filter(Boolean);
            if (!words.length) {
                return false;
            }
            let hits = 0;
            for (let i = 0; i < words.length; i++) {
                if (words[i].length < 3) {
                    continue;
                }
                if (ai.indexOf(words[i]) >= 0) {
                    hits++;
                }
            }
            const meaningful = words.filter(function(w) { return w.length >= 3; });
            if (!meaningful.length) {
                return false;
            }
            // "hot" = AI audio is still playing / just stopped, so bleed is likely.
            // Otherwise be strict: candidates legitimately reuse the question's words,
            // and a loose threshold silently swallowed real answers.
            const limit = hot ? 0.55 : 0.82;
            if ((hits / meaningful.length) >= limit) {
                return true;
            }
            // Short phrase fully contained in the AI line.
            if (clean.length >= 12 && clean.length <= 80 && ai.indexOf(clean) >= 0) {
                return true;
            }
            return false;
        };

        /**
         * Gate STT before it becomes a student turn (typed answers bypass this).
         */
        const shouldAcceptSttUtterance = (text) => {
            const clean = String(text || '').trim();
            if (!clean) {
                return false;
            }
            if (!sttWindowOpen()) {
                return false;
            }
            if (aiSpeaking || awaitingEngineReply || mutedMic) {
                return false;
            }
            if (isWeakUtterance(clean)) {
                return false;
            }
            if (looksLikeAiEcho(clean, inEchoBlackout())) {
                return false;
            }
            // Require some real mic energy unless the transcript is clearly long.
            const words = clean.match(/[A-Za-z0-9_]+/g) || [];
            if (listenVoiceMs < MIN_VOICE_MS_BEFORE_SUBMIT && words.length < 6 && clean.length < 32) {
                return false;
            }
            return true;
        };

        const clearBargeHold = () => {
            if (bargeHoldTimer) {
                clearTimeout(bargeHoldTimer);
                bargeHoldTimer = null;
            }
            bargeHoldStartedAt = 0;
            bargeHoldStartText = '';
        };

        const cancelPendingSubmit = () => {
            if (pendingSubmitTimer) {
                clearTimeout(pendingSubmitTimer);
                pendingSubmitTimer = null;
            }
        };

        const liveCaptionFrom = (committed, partial) => {
            const a = String(committed || '').trim();
            const b = String(partial || '').trim();
            if (!a) {
                return b;
            }
            if (!b) {
                return a;
            }
            if (b.indexOf(a) === 0) {
                return b;
            }
            if (a.indexOf(b) === 0) {
                return a;
            }
            const aTail = a.slice(-Math.min(28, a.length)).toLowerCase();
            const bLow = b.toLowerCase();
            const idx = aTail ? bLow.indexOf(aTail) : -1;
            if (idx >= 0) {
                return (a + b.slice(idx + aTail.length)).replace(/\s+/g, ' ').trim();
            }
            return (a + ' ' + b).replace(/\s+/g, ' ').trim();
        };

        const schedulePendingFlush = () => {
            cancelPendingSubmit();
            if (!pendingSubmitText) {
                pendingSubmitStartedAt = 0;
                return;
            }
            if (!pendingSubmitStartedAt) {
                pendingSubmitStartedAt = Date.now();
            }
            const elapsed = Date.now() - pendingSubmitStartedAt;
            const wait = Math.max(0, Math.min(GRACE_MS, GRACE_HARD_MS - elapsed));
            pendingSubmitTimer = setTimeout(flushPendingSubmit, wait);
        };

        const bargeInInterrupt = () => {
            if (!aiSpeaking) {
                return;
            }
            bargeInActive = true;
            // Keep the current listen window so barge-in speech can become the answer.
            if (!sttWindowOpen()) {
                openSttWindow();
            }
            clearBargeHold();
            speakGen += 1;
            try { interruptRealtime(); } catch (e) { /* ignore */ }
            stopTTS();
            setSpeakingUi(false);
            setStatus('Interrupted — listening…');
            if (typeof speakDone === 'function') {
                const done = speakDone;
                speakDone = null;
                done();
            }
        };

        /**
         * Interrupt only on clear, sustained student speech — not coughs / mic bleed.
         */
        const maybeBargeIn = (text, isFinal) => {
            if (!aiSpeaking || mutedMic || root._redirecting || root._wrapPlaying) {
                clearBargeHold();
                return;
            }
            if ((Date.now() - aiSpeakStartedAt) < BARGE_GUARD_MS) {
                return;
            }
            if (looksLikeAiEcho(text)) {
                clearBargeHold();
                return;
            }
            if (!isSubstantialSpeech(text)) {
                clearBargeHold();
                return;
            }

            const words = String(text || '').trim().split(/\s+/).filter(Boolean);

            // Prefer finals: only cut if the utterance is clearly a full student turn.
            if (isFinal) {
                if (words.length >= BARGE_FINAL_WORDS && String(text).trim().length >= BARGE_MIN_CHARS) {
                    clearBargeHold();
                    bargeInInterrupt();
                } else {
                    clearBargeHold();
                }
                return;
            }

            // Partials: must stay substantial AND grow for BARGE_HOLD_MS.
            if (!bargeHoldTimer) {
                bargeHoldStartText = String(text || '').trim();
                bargeHoldStartedAt = Date.now();
                bargeHoldTimer = setTimeout(function() {
                    bargeHoldTimer = null;
                    const nowText = String(gladiaPartial || '').trim();
                    const grew = nowText.length >= (bargeHoldStartText.length + BARGE_GROW_CHARS);
                    if (
                        aiSpeaking &&
                        !root._redirecting &&
                        isSubstantialSpeech(nowText) &&
                        !looksLikeAiEcho(nowText) &&
                        grew
                    ) {
                        bargeInInterrupt();
                    }
                    bargeHoldStartText = '';
                    bargeHoldStartedAt = 0;
                }, BARGE_HOLD_MS);
                return;
            }
            // Reset hold if speech stalled (no growth) — likely noise loop.
            const cur = String(text || '').trim();
            if (cur.length < bargeHoldStartText.length + 3 && (Date.now() - bargeHoldStartedAt) > 900) {
                clearBargeHold();
            }
        };

        const flushPendingSubmit = () => {
            pendingSubmitTimer = null;
            const final = String(pendingSubmitText || '').trim();
            if (!final) {
                pendingSubmitText = '';
                pendingSubmitStartedAt = 0;
                pendingHoldStartedAt = 0;
                gladiaPartial = '';
                if (!awaitingEngineReply && !mutedMic && !aiSpeaking && !inEchoBlackout()) {
                    startListen();
                }
                return;
            }
            if (mutedMic) {
                return;
            }
            // Never apply speech captured while the engine is thinking / AI is talking
            // to the *next* question. Hold only during the short echo blackout.
            if (aiSpeaking || awaitingEngineReply || !sttWindowOpen()) {
                clearSttBuffers();
                return;
            }
            if (inEchoBlackout()) {
                if (!pendingHoldStartedAt) {
                    pendingHoldStartedAt = Date.now();
                }
                if (Date.now() - pendingHoldStartedAt < HOLD_MAX_MS) {
                    pendingSubmitTimer = setTimeout(flushPendingSubmit, HOLD_RETRY_MS);
                    return;
                }
                clearSttBuffers();
                if (!aiSpeaking && !awaitingEngineReply) {
                    startListen();
                }
                return;
            }
            pendingHoldStartedAt = 0;
            if (!shouldAcceptSttUtterance(final)) {
                clearSttBuffers();
                noteMissedUtterance(final);
                if (!mutedMic) {
                    startListen();
                }
                return;
            }
            pendingSubmitText = '';
            pendingSubmitStartedAt = 0;
            gladiaPartial = '';
            stopGladiaAudio();
            listening = false;
            submitUtterance(final, gladiaSpeechStartedAt
                ? (Date.now() - gladiaSpeechStartedAt) / 1000
                : 0);
        };

        const queueFinalSubmit = (text) => {
            const piece = String(text || '').trim();
            if (!piece) {
                return;
            }
            // Drop delayed finals that belong to a closed listen window.
            if (!sttWindowOpen() || aiSpeaking || awaitingEngineReply) {
                return;
            }
            if (pendingSubmitText) {
                if (piece.indexOf(pendingSubmitText) === 0) {
                    pendingSubmitText = piece;
                } else if (pendingSubmitText.indexOf(piece) === 0) {
                    // shorter duplicate — keep longer
                } else {
                    pendingSubmitText = liveCaptionFrom(pendingSubmitText, piece);
                }
            } else {
                pendingSubmitText = piece;
                pendingSubmitStartedAt = Date.now();
            }
            showYouSaid(pendingSubmitText, false);
            schedulePendingFlush();
        };

        const handleGladiaMessage = (raw) => {
            let msg;
            try {
                msg = JSON.parse(raw);
            } catch (e) {
                return;
            }
            if (!msg || !msg.type) {
                return;
            }
            // Speech activity while AI talks → barge-in (partials handle the text path).
            if (msg.type === 'lifecycle' || msg.type === 'speech_start' ||
                    (msg.type === 'audio_chunk' && false)) {
                return;
            }
            if (msg.type !== 'transcript' || !msg.data) {
                return;
            }
            const utterance = msg.data.utterance || {};
            const text = String(utterance.text || '').trim();
            if (!text) {
                return;
            }
            lastSttActivityAt = Date.now();
            // Hard ignore while AI speaks, thinking, or echo blackout — no delayed
            // finals from the previous turn leaking into the next answer.
            if (aiSpeaking || awaitingEngineReply || mutedMic || inEchoBlackout() || !sttWindowOpen()) {
                gladiaPartial = '';
                return;
            }
            if (!msg.data.is_final) {
                gladiaPartial = text;
                showYouSaid(liveCaptionFrom(pendingSubmitText, text), true);
                // Never cancel a pending flush without rescheduling — that left us
                // "listening forever" after the user finished speaking.
                if (pendingSubmitText) {
                    const merged = liveCaptionFrom(pendingSubmitText, text);
                    if (merged.length > pendingSubmitText.length + 1) {
                        // Still talking — soft-extend grace (hard cap still applies).
                        schedulePendingFlush();
                    }
                }
                return;
            }
            const tid = String(msg.data.id || text);
            if (tid && tid === gladiaLastFinalId && pendingSubmitTimer) {
                // Duplicate final while waiting — keep current grace.
                return;
            }
            gladiaLastFinalId = tid;
            gladiaPartial = '';
            if (looksLikeAiEcho(text, inPostSpeakSettle())) {
                return;
            }
            if (isWeakUtterance(text)) {
                // Short fragments still count when they extend an answer in progress.
                if (pendingSubmitText) {
                    queueFinalSubmit(text);
                }
                return;
            }
            queueFinalSubmit(text);
        };

        const startGladiaAudio = () => {
            if (!gladiaReady || !gladiaWs || gladiaWs.readyState !== 1 || !mediaStream) {
                return false;
            }
            if (gladiaSending) {
                return true;
            }
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
                const trackStream = new MediaStream(mediaStream.getAudioTracks().map(function(t) {
                    return t.clone();
                }));
                gladiaMicSource = audioCtx.createMediaStreamSource(trackStream);
                gladiaProcessor = audioCtx.createScriptProcessor(4096, 1, 1);
                gladiaGain = audioCtx.createGain();
                gladiaGain.gain.value = 0;
                gladiaMicSource.connect(gladiaProcessor);
                gladiaProcessor.connect(gladiaGain);
                gladiaGain.connect(audioCtx.destination);
                gladiaSending = true;
                gladiaSpeechStartedAt = Date.now();
                gladiaProcessor.onaudioprocess = function(ev) {
                    if (!gladiaSending || !gladiaWs || gladiaWs.readyState !== 1) {
                        return;
                    }
                    // Hard-mute PCM while the interviewer speaks and for the short audio
                    // tail after it. The settle window stays open so an answer that
                    // starts immediately is still captured.
                    if (mutedMic || awaitingEngineReply || aiSpeaking || inEchoBlackout()) {
                        return;
                    }
                    const input = ev.inputBuffer.getChannelData(0);
                    // Track mic energy so silence cannot become a student turn.
                    let peak = 0;
                    for (let i = 0; i < input.length; i += 8) {
                        const a = Math.abs(input[i]);
                        if (a > peak) {
                            peak = a;
                        }
                    }
                    if (peak > 0.02) {
                        listenVoiceMs += (ev.inputBuffer.duration || 0.02) * 1000;
                    }
                    const down = downsampleTo16k(input, audioCtx.sampleRate);
                    const pcm = floatTo16BitPCM(down);
                    try {
                        gladiaWs.send(pcm.buffer);
                    } catch (eSend) {
                        // ignore transient send errors
                    }
                };
                // Keep a reference so GC doesn't stop the cloned tracks mid-session.
                gladiaProcessor._nxiTracks = trackStream;
                return true;
            } catch (e) {
                try { console.warn('Gladia audio capture failed', e); } catch (e2) {}
                stopGladiaAudio();
                return false;
            }
        };

        const ensureGladia = () => {
            if (gladiaFailed) {
                return Promise.resolve(false);
            }
            if (gladiaReady && gladiaWs && gladiaWs.readyState === 1) {
                return Promise.resolve(true);
            }
            if (gladiaConnecting) {
                return Promise.resolve(false);
            }
            if (!cfg.sessionid) {
                return Promise.resolve(false);
            }
            gladiaConnecting = true;
            return call(cfg, 'gladia_live', {sessionid: cfg.sessionid}).then(function(parsed) {
                const live = (parsed && parsed.gladia) || {};
                if (!live.ok || !live.url) {
                    throw new Error(live.error || 'gladia_live_failed');
                }
                gladiaSampleRate = Number(live.sample_rate) || 16000;
                return new Promise(function(resolve) {
                    let settled = false;
                    const ws = new WebSocket(live.url);
                    ws.binaryType = 'arraybuffer';
                    gladiaWs = ws;
                    ws.onopen = function() {
                        gladiaReady = true;
                        gladiaConnecting = false;
                        settled = true;
                        resolve(true);
                    };
                    ws.onmessage = function(ev) {
                        handleGladiaMessage(ev.data);
                    };
                    ws.onerror = function() {
                        gladiaConnecting = false;
                        if (!settled) {
                            settled = true;
                            resolve(false);
                        }
                    };
                    ws.onclose = function() {
                        gladiaReady = false;
                        gladiaWs = null;
                        stopGladiaAudio();
                        if (!settled) {
                            settled = true;
                            resolve(false);
                        }
                    };
                    window.setTimeout(function() {
                        if (!settled) {
                            settled = true;
                            gladiaConnecting = false;
                            resolve(!!gladiaReady);
                        }
                    }, 8000);
                });
            }).catch(function(err) {
                gladiaConnecting = false;
                gladiaFailed = true;
                gladiaReady = false;
                try { console.warn('Gladia live unavailable — falling back to Whisper', err); } catch (e) {}
                return false;
            });
        };

        const flushWhisper = () => {
            if (!recordedChunks.length) {
                return Promise.resolve('');
            }
            const blob = new Blob(recordedChunks, {type: recordedChunks[0].type || 'audio/webm'});
            recordedChunks = [];
            if (blob.size < 400) {
                return Promise.resolve('');
            }
            setStatus('Transcribing…');
            return blobToB64(blob).then(function(b64) {
                return call(cfg, 'stt', {
                    sessionid: cfg.sessionid || '',
                    audio: b64,
                    message: 'audio.webm'
                }).then(function(parsed) {
                    const stt = (parsed && parsed.stt) || {};
                    if (!stt.ok || !stt.text) {
                        throw new Error(stt.error || 'stt_failed');
                    }
                    return String(stt.text || '').trim();
                });
            });
        };

        const stopRecorder = () => {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                try { mediaRecorder.stop(); } catch (e) { /* ignore */ }
            }
        };

        const startWhisperListen = () => {
            if (mutedMic || aiSpeaking || listening || awaitingEngineReply) {
                return;
            }
            if (!mediaStream || !window.MediaRecorder) {
                startBrowserListen();
                return;
            }
            listening = true;
            setStatus("I'm listening… speak now");
            recordedChunks = [];
            speechStartedAt = Date.now();

            try {
                const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                    ? 'audio/webm;codecs=opus'
                    : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');
                const recStream = new MediaStream(mediaStream.getAudioTracks().map(function(t) {
                    return t.clone();
                }));
                mediaRecorder = mime
                    ? new MediaRecorder(recStream, {mimeType: mime})
                    : new MediaRecorder(recStream);
                mediaRecorder.ondataavailable = function(ev) {
                    if (ev.data && ev.data.size > 0) {
                        recordedChunks.push(ev.data);
                    }
                };
                mediaRecorder.onstop = function() {
                    recStream.getTracks().forEach(function(t) {
                        try { t.stop(); } catch (eS) { /* ignore */ }
                    });
                    const dur = speechStartedAt ? (Date.now() - speechStartedAt) / 1000 : 0;
                    listening = false;
                    flushWhisper().then(function(text) {
                        if (text && shouldAcceptSttUtterance(text)) {
                            submitUtterance(text, dur);
                        } else if (!aiSpeaking && !mutedMic && !awaitingEngineReply) {
                            setStatus("Didn't catch that — speak again, or type below");
                            setTimeout(startListen, 700);
                        }
                    }).catch(function(err) {
                        try { console.warn('Whisper STT failed', err); } catch (eW) {}
                        setStatus('Transcription failed — type your answer below');
                        setTimeout(function() {
                            if (!aiSpeaking && !mutedMic && !awaitingEngineReply) {
                                startListen();
                            }
                        }, 1200);
                    });
                };
                mediaRecorder.start(200);

                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
                const vadStream = new MediaStream(mediaStream.getAudioTracks().map(function(t) {
                    return t.clone();
                }));
                const micSource = audioCtx.createMediaStreamSource(vadStream);
                const micAnalyser = audioCtx.createAnalyser();
                micAnalyser.fftSize = 512;
                micSource.connect(micAnalyser);
                let silentFor = 0;
                let startedVoice = false;
                const cleanupVad = function() {
                    try { micSource.disconnect(); } catch (eD) { /* ignore */ }
                    vadStream.getTracks().forEach(function(t) {
                        try { t.stop(); } catch (eT) { /* ignore */ }
                    });
                };
                const vad = function() {
                    if (!listening || !mediaRecorder || mediaRecorder.state !== 'recording') {
                        cleanupVad();
                        return;
                    }
                    const data = new Uint8Array(micAnalyser.frequencyBinCount);
                    micAnalyser.getByteFrequencyData(data);
                    let sum = 0;
                    for (let i = 0; i < data.length; i++) {
                        sum += data[i];
                    }
                    const level = sum / Math.max(1, data.length);
                    if (level > 12) {
                        startedVoice = true;
                        silentFor = 0;
                        listenVoiceMs += 80;
                    } else if (startedVoice) {
                        silentFor += 80;
                    }
                    if (startedVoice && silentFor >= SILENCE_END_MS) {
                        cleanupVad();
                        stopRecorder();
                        return;
                    }
                    silenceTimer = setTimeout(vad, 80);
                };
                setTimeout(function() {
                    if (mediaRecorder && mediaRecorder.state === 'recording') {
                        cleanupVad();
                        stopRecorder();
                    }
                }, 18000);
                silenceTimer = setTimeout(vad, 80);
            } catch (e) {
                listening = false;
                useWhisper = false;
                startBrowserListen();
            }
        };

        const startListen = () => {
            if (mutedMic || awaitingEngineReply || aiSpeaking || inPostSpeakSettle() || inEchoBlackout()) {
                if ((inPostSpeakSettle() || inEchoBlackout()) && !postSpeakTimer && !mutedMic) {
                    scheduleListenAfterSpeak();
                }
                return;
            }
            if (!sttWindowOpen()) {
                openSttWindow();
            }
            lastListenStartedAt = Date.now();
            listenVoiceMs = 0;
            if (!gladiaFailed) {
                ensureGladia().then(function(ok) {
                    if (mutedMic || awaitingEngineReply || aiSpeaking ||
                            inPostSpeakSettle() || inEchoBlackout()) {
                        return;
                    }
                    if (ok && startGladiaAudio()) {
                        listening = true;
                        useWhisper = false;
                        setTurnState('user');
                        setStatus("I'm listening…");
                        return;
                    }
                    if (listening) {
                        return;
                    }
                    if (useWhisper) {
                        startWhisperListen();
                    } else {
                        startBrowserListen();
                    }
                });
                return;
            }
            if (listening) {
                return;
            }
            if (useWhisper) {
                startWhisperListen();
            } else {
                startBrowserListen();
            }
        };

        const startBrowserListen = () => {
            if (mutedMic || aiSpeaking || !SpeechRecognition) {
                return;
            }
            const rec = ensureRecognition();
            if (!rec) {
                return;
            }
            try {
                pendingFinal = '';
                rec.start();
                listening = true;
                setStatus("I'm listening…");
            } catch (e) {
                // already started
            }
        };

        const stopListen = () => {
            clearTimeout(silenceTimer);
            silenceTimer = null;
            cancelPendingSubmit();
            // Always pause Gladia PCM when leaving listen — never stream AI TTS into STT.
            stopGladiaAudio();
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                try { mediaRecorder.stop(); } catch (e) {}
            }
            if (recognition && listening) {
                try { recognition.stop(); } catch (e2) {}
            }
            listening = false;
        };

        const submitUtterance = (text, durationSec) => {
            const msg = (text || '').trim();
            if (!msg) {
                startListen();
                return;
            }
            if (aiSpeaking) {
                bargeInInterrupt();
            }
            // Do not queue speech for the *next* question while the engine is still
            // answering the previous one (that is how cut-off tails become wrong answers).
            if (awaitingEngineReply) {
                return;
            }
            cancelPendingSubmit();
            pendingSubmitText = '';
            pendingHoldStartedAt = 0;
            closeSttWindow();
            appendChatBubble('you', msg);
            showYouSaid('', false);
            awaitingEngineReply = true;
            stopGladiaAudio();
            listening = false;
            setTurnState('thinking');
            showThinking();
            setStatus('Thinking…');
            const watchdog = setTimeout(function() {
                if (!awaitingEngineReply) {
                    return;
                }
                awaitingEngineReply = false;
                hideThinking();
                setStatus('No response — try speaking or type below');
                setSpeakingUi(false);
                openSttWindow();
                startListen();
            }, 25000);
            call(cfg, 'message', {
                message: msg,
                durationsec: durationSec || 0
            }).then(function(parsed) {
                awaitingEngineReply = false;
                clearTimeout(watchdog);
                if (typeof cfg.onSession === 'function') {
                    cfg.onSession(parsed.session || parsed);
                }
            }).catch(function(err) {
                awaitingEngineReply = false;
                clearTimeout(watchdog);
                hideThinking();
                setStatus(errText(err));
                setSpeakingUi(false);
                openSttWindow();
                startListen();
            });
        };

        const ensureRecognition = () => {
            if (!SpeechRecognition) {
                return null;
            }
            if (recognition) {
                return recognition;
            }
            recognition = new SpeechRecognition();
            recognition.lang = lang;
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.onresult = function(ev) {
                let interim = '';
                for (let i = ev.resultIndex; i < ev.results.length; i++) {
                    const piece = ev.results[i][0].transcript || '';
                    if (ev.results[i].isFinal) {
                        pendingFinal += piece + ' ';
                    } else {
                        interim += piece;
                    }
                }
                const live = (pendingFinal + ' ' + interim).trim();
                if (live) {
                    lastSttActivityAt = Date.now();
                }
                if (live && aiSpeaking && !mutedMic) {
                    maybeBargeIn(live, !interim);
                }
                if (live && !aiSpeaking && !mutedMic) {
                    showYouSaid(live, !!interim);
                }
                clearTimeout(silenceTimer);
                silenceTimer = setTimeout(function() {
                    const text = pendingFinal.trim();
                    pendingFinal = '';
                    if (text && shouldAcceptSttUtterance(text)) {
                        submitUtterance(text);
                    } else if (text) {
                        showYouSaid('', false);
                    }
                }, SILENCE_END_MS);
            };
            recognition.onerror = function() {
                listening = false;
            };
            recognition.onend = function() {
                listening = false;
                if (!aiSpeaking && !mutedMic) {
                    setTimeout(startListen, 280);
                }
            };
            return recognition;
        };

        const speak = (text) => new Promise(function(resolve) {
            const original = String(text || '');
            const clean = stripForSpeech(original);
            if (!clean) {
                resolve();
                return;
            }
            stopTTS();
            let settled = false;
            const afterSpeak = function() {
                if (settled) {
                    return;
                }
                settled = true;
                speakDone = null;
                aiSpeaking = false;
                vizHot = false;
                lastAiSpeakEndedAt = Date.now();
                awaitingEngineReply = false;
                finalizeAiChat(original);
                clearSttBuffers();
                stopGladiaAudio();
                stopEq();
                setSpeakingUi(false);
                setStatus("I'm listening…");
                scheduleListenAfterSpeak();
                resolve();
            };
            speakDone = afterSpeak;
            speakGen += 1;
            const myGen = speakGen;
            showYouSaid('', false);
            setStreamCaptionVisible(false);
            setSpeakingUi(true);
            // Do not stream the mic into STT while the interviewer is talking.
            stopListen();
            clearBargeHold();
            bargeInActive = false;
            closeSttWindow();
            stopGladiaAudio();
            aiSpeakStartedAt = Date.now();
            lastAiSpokenText = clean;
            aiSpeaking = true;
            vizHot = true;
            startDome();
            // Prefer Realtime voice when connected; HD TTS is the reliable fallback.
            const tryRealtime = (preferRealtimeSpeak && realtimeReady)
                ? speakViaRealtime(clean, original, myGen)
                : Promise.resolve(false);
            tryRealtime.then(function(ok) {
                if (settled || myGen !== speakGen) {
                    return null;
                }
                if (ok) {
                    afterSpeak();
                    return null;
                }
                return speakCloudSynced(clean, myGen, original).then(function() {
                    if (!settled && myGen === speakGen) {
                        afterSpeak();
                    }
                }).catch(function(err) {
                    if (settled || myGen !== speakGen) {
                        return;
                    }
                    try { console.warn('TTS failed', err); } catch (e) {}
                    return speakBrowserFallback(clean, original, myGen).then(function() {
                        if (!settled && myGen === speakGen) {
                            afterSpeak();
                        }
                    });
                });
            });
        });

        const turnNorm = (text) => extractSnippets(text).prose
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const isRepeatTurn = (text) => {
            const n = turnNorm(text);
            if (!n || n.length < 28) {
                return false;
            }
            const head = n.slice(0, 140);
            return spokenNorms.some(function(prev) {
                if (!prev) {
                    return false;
                }
                if (prev === n || prev.slice(0, 140) === head) {
                    return true;
                }
                if (prev.length > 48 && n.indexOf(prev.slice(0, 72)) !== -1) {
                    return true;
                }
                if (n.length > 48 && prev.indexOf(n.slice(0, 72)) !== -1) {
                    return true;
                }
                return false;
            });
        };

        const waitUntilQuiet = () => new Promise(function(resolve) {
            const started = Date.now();
            const tick = window.setInterval(function() {
                const playing = !!(currentAudio && !currentAudio.ended && currentAudio.paused === false);
                const synth = !!(window.speechSynthesis && window.speechSynthesis.speaking);
                if ((!aiSpeaking && !playing && !synth) || Date.now() - started > 180000) {
                    window.clearInterval(tick);
                    resolve();
                }
            }, 120);
        });

        const speakLatest = (turns, opts) => {
            lastTurns = turns || [];
            pushHistory(lastTurns);
            const assistants = lastTurns.filter(function(t) {
                return t.role === 'assistant';
            });
            if (!assistants.length) {
                if (!(opts && opts.force)) {
                    startListen();
                }
                return Promise.resolve();
            }
            const last = assistants[assistants.length - 1];
            const already = (last.seq || 0) <= lastSpokenSeq;
            const stillPlaying = aiSpeaking ||
                !!(currentAudio && !currentAudio.ended && currentAudio.paused === false);
            const replay = !!(opts && opts.force);
            if (already && !(replay && !stillPlaying)) {
                if (!stillPlaying && !listening && !mutedMic && !replay) {
                    startListen();
                }
                return waitUntilQuiet();
            }
            if (!replay && isRepeatTurn(last.content)) {
                lastSpokenSeq = Math.max(lastSpokenSeq, last.seq || lastSpokenSeq);
                if (!stillPlaying && !listening && !mutedMic) {
                    startListen();
                }
                return waitUntilQuiet();
            }
            lastSpokenSeq = last.seq || lastSpokenSeq + 1;
            const norm = turnNorm(last.content);
            if (norm && spokenNorms.indexOf(norm) === -1) {
                spokenNorms.push(norm);
                if (spokenNorms.length > 24) {
                    spokenNorms.shift();
                }
            }
            return speak(last.content).then(function() {
                return waitUntilQuiet();
            });
        };

        const forceReplay = () => {
            unlockSpeech();
            const assistants = (lastTurns || []).filter(function(t) {
                return t.role === 'assistant';
            });
            if (!assistants.length) {
                return;
            }
            lastSpokenSeq = Math.max(0, (assistants[assistants.length - 1].seq || 1) - 1);
            speakLatest(assistants);
        };

        const requestMedia = () => {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return Promise.resolve(null);
            }
            return navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                },
                video: true
            })
                .then(function(stream) {
                    mediaStream = stream;
                    const vid = root.querySelector('[data-region="live-video"]');
                    if (vid) {
                        vid.srcObject = stream;
                    }
                    return stream;
                }).catch(function() {
                    return null;
                });
        };

        const toggleMic = () => {
            mutedMic = !mutedMic;
            const btn = root.querySelector('[data-action="toggle-mic"]');
            if (btn) {
                btn.classList.toggle('is-on', !mutedMic);
                btn.classList.toggle('is-off', mutedMic);
            }
            if (mediaStream) {
                mediaStream.getAudioTracks().forEach(function(t) {
                    t.enabled = !mutedMic;
                });
            }
            if (mutedMic) {
                cancelPendingSubmit();
                pendingSubmitText = '';
                stopGladiaAudio();
                stopListen();
                setStatus('Mic muted');
            } else if (!aiSpeaking || (gladiaReady && !gladiaFailed)) {
                startListen();
            }
        };

        const toggleCam = () => {
            camOn = !camOn;
            const btn = root.querySelector('[data-action="toggle-cam"]');
            if (btn) {
                btn.classList.toggle('is-on', camOn);
                btn.classList.toggle('is-off', !camOn);
            }
            if (mediaStream) {
                mediaStream.getVideoTracks().forEach(function(t) {
                    t.enabled = camOn;
                });
            }
        };

        const mountIde = (problemId, force) => {
            const host = root.querySelector('[data-region="ide-host"]');
            problemId = parseInt(problemId, 10) || 0;
            if (!host || !problemId) {
                return;
            }
            if (ideReady && mountedProblemId === problemId && !force) {
                return;
            }
            if (!cfg.hasLearnlogic) {
                setStatus('NexPractice missing — coding UI unavailable');
                return;
            }
            host.hidden = false;
            root.classList.add('is-coding');
            const body = root.querySelector('[data-region="live-body"]');
            if (body) {
                body.classList.add('is-coding');
            }
            const videoWrap = root.querySelector('[data-region="video-wrap"]');
            if (videoWrap) {
                videoWrap.classList.add('is-pip');
            }
            document.body.classList.add('ll-ide-attempt', 'll-np-battle', 'll-np-interview', 'nxi-coding');

            const bootIde = function() {
                require(['local_learnlogic/problem'], function(Problem) {
                    const ide = host.querySelector('[data-region="ll-ide"]');
                    if (ide) {
                        ide.classList.add('ll-np--battle', 'll-np--interview');
                        ide.dataset.problemid = String(problemId);
                        const brand = ide.querySelector('.ll-np__brand');
                        if (brand) {
                            brand.textContent = 'NexInterview';
                            brand.setAttribute('href', cfg.huburl || '#');
                        }
                    }
                    Problem.init({
                        problemId: problemId,
                        listUrl: cfg.huburl,
                        canAttempt: !!cfg.canAttempt,
                        aceBaseUrl: cfg.aceBaseUrl || '',
                        interview: {
                            sessionId: cfg.sessionid || '',
                            onResult: function(judge) {
                                const passed = parseInt(judge && (judge.passed != null ? judge.passed : 0), 10) || 0;
                                const total = parseInt(judge && (judge.total != null ? judge.total : 0), 10) || 0;
                                const allPassed = !!(judge && (judge.allPassed || (total > 0 && passed >= total)));
                                call(cfg, 'coding_result', {
                                    sessionid: cfg.sessionid || '',
                                    problemid: problemId,
                                    mode: allPassed ? 'allpassed' : 'failed',
                                    message: String(passed) + '/' + String(total)
                                }).then(function(parsed) {
                                    if (parsed.extra && parsed.extra.moodle_problem_id) {
                                        cfg.problemid = parsed.extra.moodle_problem_id;
                                    }
                                    if (typeof cfg.onSession === 'function') {
                                        cfg.onSession(parsed.session || parsed);
                                    }
                                }).catch(function(err) {
                                    try { console.warn('coding_result failed', err); } catch (e) {}
                                });
                            }
                        }
                    });
                    ideReady = true;
                    mountedProblemId = problemId;
                    if (ide) {
                        ide.style.position = 'absolute';
                        ide.style.top = '0';
                        ide.style.right = '0';
                        ide.style.bottom = '0';
                        ide.style.left = '0';
                        ide.style.width = '100%';
                        ide.style.height = '100%';
                        ide.style.zIndex = '5';
                        ide.style.pointerEvents = 'auto';
                    }
                    setTimeout(function() {
                        try { window.dispatchEvent(new Event('resize')); } catch (eR) {}
                        try {
                            if (window.ace) {
                                document.querySelectorAll('.ace_editor').forEach(function(node) {
                                    window.ace.edit(node).resize();
                                });
                            }
                        } catch (eAce) { /* ignore */ }
                        setEditorLocked(editorLocked);
                    }, 250);
                }, function() {
                    setStatus('Could not load NexPractice IDE module');
                });
            };

            if (ideReady && mountedProblemId !== problemId) {
                mountedProblemId = problemId;
                cfg.problemid = problemId;
                require(['local_learnlogic/problem'], function(Problem) {
                    if (typeof Problem.reloadProblem === 'function') {
                        Problem.reloadProblem(problemId, {
                            interview: {
                                sessionId: cfg.sessionid || '',
                                onResult: function(judge) {
                                    const passed = parseInt(judge && (judge.passed != null ? judge.passed : 0), 10) || 0;
                                    const total = parseInt(judge && (judge.total != null ? judge.total : 0), 10) || 0;
                                    const allPassed = !!(judge && (judge.allPassed || (total > 0 && passed >= total)));
                                    call(cfg, 'coding_result', {
                                        sessionid: cfg.sessionid || '',
                                        problemid: problemId,
                                        mode: allPassed ? 'allpassed' : 'failed',
                                        message: String(passed) + '/' + String(total)
                                    }).then(function(parsed) {
                                        if (parsed.extra && parsed.extra.moodle_problem_id) {
                                            cfg.problemid = parsed.extra.moodle_problem_id;
                                        }
                                        if (typeof cfg.onSession === 'function') {
                                            cfg.onSession(parsed.session || parsed);
                                        }
                                    }).catch(function(err) {
                                        try { console.warn('coding_result failed', err); } catch (e) {}
                                    });
                                }
                            }
                        });
                        setEditorLocked(true);
                        setStatus('New problem loaded — explain your approach first');
                    } else {
                        ideReady = false;
                        mountedProblemId = 0;
                        bootIde();
                    }
                });
                return;
            }
            bootIde();

            if (!host._nxiSnapTimer) {
                host._nxiSnapTimer = window.setInterval(function() {
                    if (!cfg.sessionid || !ideReady || editorLocked) {
                        return;
                    }
                    let code = '';
                    try {
                        if (window.ace) {
                            const editors = document.querySelectorAll('.ace_editor');
                            if (editors.length) {
                                const ed = window.ace.edit(editors[0]);
                                code = ed.getValue();
                            }
                        }
                    } catch (e) {
                        // ignore
                    }
                    if (!code) {
                        const ta = host.querySelector('[data-region="editor"]');
                        code = ta ? ta.value : '';
                    }
                    if (code && code.length > 20) {
                        call(cfg, 'snapshot', {code: code}).then(function(parsed) {
                            if (typeof cfg.onSession === 'function') {
                                cfg.onSession(parsed.session || parsed);
                            }
                        }).catch(function() { /* ignore */ });
                    }
                }, 12000);
            }
        };

        const setEditorLocked = (locked) => {
            editorLocked = !!locked;
            document.body.classList.toggle('nxi-ide-locked', editorLocked);
            const host = root.querySelector('[data-region="ide-host"]');
            const stack = document.querySelector('.ll-np__editor-stack');
            if (stack && !stack.querySelector('[data-region="nxi-lock"]')) {
                stack.style.position = 'relative';
                const ov = document.createElement('div');
                ov.className = 'nxi-ide-lock';
                ov.dataset.region = 'nxi-lock';
                ov.innerHTML = '<div class="nxi-ide-lock__card">' +
                    '<p>Explain your approach first</p>' +
                    '<p class="nxi-ide-lock__sub">NexAI unlocks the editor when your plan is solid.</p>' +
                    '</div>';
                stack.appendChild(ov);
            }
            try {
                if (window.ace) {
                    document.querySelectorAll('.ace_editor').forEach(function(node) {
                        window.ace.edit(node).setReadOnly(editorLocked);
                    });
                }
            } catch (eAce) { /* ignore */ }
            const ta = host ? host.querySelector('[data-region="editor"]') : null;
            if (ta) {
                ta.readOnly = editorLocked;
            }
        };

        /**
         * Voice liveness supervisor.
         *
         * Every leg of the loop (Gladia socket, MediaRecorder, SpeechRecognition,
         * engine reply) can stall without throwing, which leaves the room in a
         * dead state where nothing listens and nothing speaks. This detects that
         * and recovers instead of waiting for the student to reload.
         */
        const startSupervisor = () => {
            if (supervisorTimer) {
                return;
            }
            supervisorTimer = window.setInterval(function() {
                if (root._redirecting || root._wrapPlaying || root.dataset.phase !== 'live') {
                    return;
                }
                if (mutedMic || aiSpeaking || awaitingEngineReply) {
                    return;
                }

                const idleFor = Date.now() - Math.max(lastSttActivityAt, lastListenStartedAt);

                // A finished answer must never sit unsent.
                if (pendingSubmitText && !pendingSubmitTimer) {
                    flushPendingSubmit();
                    return;
                }

                // Gladia said it was streaming but the socket died underneath us.
                if (gladiaSending && (!gladiaWs || gladiaWs.readyState !== 1)) {
                    stopGladiaAudio();
                    listening = false;
                    startListen();
                    return;
                }

                // Nothing is capturing: no recogniser, no recorder, no PCM pump.
                if (!listening) {
                    if (idleFor > 3000) {
                        startListen();
                    }
                    return;
                }

                // Listening, but the transcriber has gone quiet for too long.
                if (idleFor > STT_STALL_MS) {
                    if (gladiaSending || gladiaReady) {
                        stopGladiaAudio();
                        gladiaReady = false;
                        try {
                            if (gladiaWs) {
                                gladiaWs.close();
                            }
                        } catch (eClose) { /* ignore */ }
                        gladiaWs = null;
                    }
                    listening = false;
                    lastListenStartedAt = Date.now();
                    startListen();
                }
            }, 2500);
        };

        const stopSupervisor = () => {
            if (supervisorTimer) {
                window.clearInterval(supervisorTimer);
                supervisorTimer = null;
            }
        };

        return {
            unlockSpeech: unlockSpeech,
            requestMedia: requestMedia,
            connectRealtime: connectRealtime,
            ensureGladia: ensureGladia,
            closeGladia: closeGladia,
            speakLatest: speakLatest,
            waitUntilQuiet: waitUntilQuiet,
            forceReplay: forceReplay,
            startListen: startListen,
            stopListen: stopListen,
            submitUtterance: submitUtterance,
            stopTTS: stopTTS,
            toggleMic: toggleMic,
            toggleCam: toggleCam,
            mountIde: mountIde,
            startDome: startDome,
            startSupervisor: startSupervisor,
            stopSupervisor: stopSupervisor,
            setStatus: setStatus,
            setEditorLocked: setEditorLocked
        };
    };

    const setWrapOverlay = (root, on, title, sub) => {
        const el = root.querySelector('[data-region="wrapup"]');
        if (!el) {
            return;
        }
        el.hidden = !on;
        if (!on) {
            return;
        }
        const titleEl = el.querySelector('[data-region="wrapup-title"]');
        const subEl = el.querySelector('[data-region="wrapup-sub"]');
        if (titleEl && title) {
            titleEl.textContent = title;
        }
        if (subEl && sub) {
            subEl.textContent = sub;
        }
    };

    const applySession = (root, state, cfg, engine) => {
        root._session = state;
        cfg._stage = state.stage;
        const stageEl = root.querySelector('[data-region="stage"]');
        const timerEl = root.querySelector('[data-region="timer"]');
        if (stageEl) {
            stageEl.textContent = (state.stage || '').toUpperCase();
        }
        if (timerEl) {
            timerEl.textContent = fmtTime(state.seconds_remaining);
            root._seconds = state.seconds_remaining;
            const wrap = root.querySelector('[data-region="timer-wrap"]');
            if (wrap) {
                const left = Number(state.seconds_remaining || 0);
                wrap.classList.toggle('is-warn', left > 60 && left <= 300);
                wrap.classList.toggle('is-low', left > 0 && left <= 60);
            }
        }

        if (root._redirecting) {
            return;
        }

        const showCode = !!(state.ui && state.ui.show_editor);
        const pid = (state.moodle_problem_id || (state.ui && state.ui.moodle_problem_id) || cfg.problemid || 0) | 0;
        if (showCode && pid) {
            engine.mountIde(pid, !!(state.ui && state.ui.remount_ide));
        }
        engine.setEditorLocked(!!(state.ui && state.ui.editor_locked));

        if (state.status === 'completed') {
            if (root._wrapPlaying) {
                return;
            }
            root._wrapPlaying = true;
            clearPersistedSessionId();
            setWrapOverlay(
                root,
                true,
                'Generating your feedback…',
                'NexAI is giving a short verbal wrap-up, then your report opens automatically.'
            );
            engine.stopListen();
            if (typeof engine.stopSupervisor === 'function') {
                try { engine.stopSupervisor(); } catch (eS) { /* ignore */ }
            }
            if (typeof engine.closeGladia === 'function') {
                try { engine.closeGladia(); } catch (eG) { /* ignore */ }
            }
            const url = cfg.feedbackurl + (cfg.feedbackurl.indexOf('?') >= 0 ? '&' : '?') +
                'sessionid=' + encodeURIComponent(state.session_id);
            const go = function() {
                root._redirecting = true;
                window.location.href = url;
            };
            const speakP = engine.speakLatest(state.turns || [], {force: true});
            const fallback = window.setTimeout(go, 180000);
            Promise.resolve(speakP).then(function() {
                return engine.waitUntilQuiet ? engine.waitUntilQuiet() : null;
            }).then(function() {
                window.clearTimeout(fallback);
                window.setTimeout(go, 1500);
            }).catch(function() {
                window.clearTimeout(fallback);
                window.setTimeout(go, 1500);
            });
            return;
        }

        engine.speakLatest(state.turns || []);
    };

    const showGateError = (root, msg) => {
        const el = root.querySelector('[data-region="gate-error"]');
        if (el) {
            el.hidden = false;
            el.textContent = msg;
        }
    };

    const enterLive = (root) => {
        const gate = root.querySelector('[data-region="gate"]');
        const live = root.querySelector('[data-region="live"]');
        if (gate) {
            gate.hidden = true;
        }
        if (live) {
            live.hidden = false;
        }
        root.dataset.phase = 'live';
    };

    const init = (cfg) => {
        const root = document.querySelector('[data-region="nxi-room"]');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        try {
            if (!cfg.resume) {
                cfg.resume = storeGet('nxi_resume');
            }
            if (!cfg.roletrack) {
                cfg.roletrack = storeGet('nxi_track') || 'sde_intern';
            }
            if (!cfg.topics) {
                cfg.topics = storeGet('nxi_topics') ||
                    'arrays,strings,hashmap,stacks,complexity';
            }
            if (!cfg.problemid) {
                cfg.problemid = parseInt(storeGet('nxi_problemid') || '0', 10) || 0;
            }
            if (!cfg.interviewerid) {
                cfg.interviewerid = parseInt(storeGet('nxi_interviewerid') || '0', 10) || 0;
            }
            if (!cfg.sessionid) {
                const savedSid = storeGet('nxi_sessionid');
                if (savedSid && !cfg.start) {
                    cfg.sessionid = savedSid;
                    cfg.resuming = true;
                }
            }
        } catch (e) {
            // ignore
        }

        document.body.classList.add('nxi-fs-active');
        const engine = createEngine(root, cfg);
        cfg.onSession = function(state) {
            applySession(root, state, cfg, engine);
        };
        try { engine.startDome(); } catch (e0) {}

        const typeForm = root.querySelector('[data-region="type-form"]');
        const typeInput = root.querySelector('[data-region="type-input"]');
        if (typeForm && typeInput) {
            typeForm.addEventListener('submit', function(ev) {
                ev.preventDefault();
                const msg = String(typeInput.value || '').trim();
                if (!msg) {
                    return;
                }
                typeInput.value = '';
                engine.submitUtterance(msg, 0);
            });
        }

        const bindLiveControls = () => {
            const mic = root.querySelector('[data-action="toggle-mic"]');
            const cam = root.querySelector('[data-action="toggle-cam"]');
            const replay = root.querySelector('[data-action="replay"]');
            const end = root.querySelector('[data-action="end"]');
            if (mic) {
                mic.addEventListener('click', engine.toggleMic);
            }
            if (cam) {
                cam.addEventListener('click', engine.toggleCam);
            }
            if (replay) {
                replay.addEventListener('click', function() {
                    engine.forceReplay();
                });
            }
            if (end) {
                end.addEventListener('click', function() {
                    if (!cfg.sessionid) {
                        window.location.href = cfg.huburl;
                        return;
                    }
                    if (!window.confirm('End the interview now? NexAI will wrap up if you confirm.')) {
                        return;
                    }
                    end.disabled = true;
                    setWrapOverlay(
                        root,
                        true,
                        'Wrapping up the interview…',
                        'Scoring your answers and building the feedback report.'
                    );
                    call(cfg, 'end', {}).then(function(parsed) {
                        const state = parsed.session || parsed;
                        applySession(root, state, cfg, engine);
                    }).catch(function(err) {
                        end.disabled = false;
                        setWrapOverlay(root, false);
                        Notification.exception(err);
                    });
                });
            }
        };
        bindLiveControls();

        const beginBtn = root.querySelector('[data-action="begin"]');
        const beginIdle = beginBtn
            ? (beginBtn.getAttribute('data-label-idle') || beginBtn.textContent || 'Start interview')
            : 'Start interview';
        const beginBusy = beginBtn
            ? (beginBtn.getAttribute('data-label-busy') || (cfg.resuming ? 'Continuing…' : 'Starting…'))
            : 'Starting…';
        const startSession = () => {
            if (cfg.serviceConfigured === false) {
                showGateError(root, 'Interview service is not configured. Ask an admin to set Service URL and shared secret.');
                return;
            }
            beginBtn.disabled = true;
            beginBtn.textContent = beginBusy;
            // Critical: unlock audio inside this click.
            engine.unlockSpeech();
            enterLive(root);
            engine.setStatus(cfg.resuming || cfg.sessionid ? 'Rejoining…' : 'Starting…');
            try { engine.startSupervisor(); } catch (eSup) { /* ignore */ }

            engine.requestMedia().then(function() {
                const boot = cfg.start || !cfg.sessionid;
                if (boot) {
                    return call(cfg, 'start', {
                        roletrack: cfg.roletrack,
                        topics: cfg.topics,
                        resume: cfg.resume,
                        problemid: cfg.problemid,
                        interviewerid: cfg.interviewerid || 0
                    }).then(function(parsed) {
                        const state = parsed.session || parsed;
                        if (!state || !state.session_id) {
                            throw new Error('Start returned empty session');
                        }
                        cfg.sessionid = state.session_id;
                        persistSessionId(cfg.sessionid);
                        if (parsed.extra && parsed.extra.moodle_problem_id) {
                            cfg.problemid = parsed.extra.moodle_problem_id;
                        }
                        return engine.connectRealtime().then(function() {
                            return engine.ensureGladia();
                        }).then(function() {
                            applySession(root, state, cfg, engine);
                        });
                    });
                }
                persistSessionId(cfg.sessionid);
                return call(cfg, 'get', {sessionid: cfg.sessionid}).then(function(parsed) {
                    return engine.connectRealtime().then(function() {
                        return engine.ensureGladia();
                    }).then(function() {
                        applySession(root, parsed.session || parsed, cfg, engine);
                    });
                });
            }).catch(function(err) {
                const msg = errText(err);
                engine.setStatus(msg);
                showGateError(root, msg);
                const gate = root.querySelector('[data-region="gate"]');
                const live = root.querySelector('[data-region="live"]');
                if (gate) {
                    gate.hidden = false;
                }
                if (live) {
                    live.hidden = true;
                }
                beginBtn.disabled = false;
                beginBtn.textContent = beginIdle;
                Notification.exception(err);
            });
        };

        if (beginBtn) {
            beginBtn.addEventListener('click', startSession);
        } else {
            // Fallback if template is stale.
            startSession();
        }

        window.setInterval(function() {
            if (root._seconds == null) {
                return;
            }
            root._seconds = Math.max(0, (root._seconds | 0) - 1);
            const timerEl = root.querySelector('[data-region="timer"]');
            if (timerEl) {
                timerEl.textContent = fmtTime(root._seconds);
            }
            const wrap = root.querySelector('[data-region="timer-wrap"]');
            if (wrap) {
                const left = root._seconds | 0;
                wrap.classList.toggle('is-warn', left > 60 && left <= 300);
                wrap.classList.toggle('is-low', left > 0 && left <= 60);
            }
        }, 1000);

        window.setInterval(function() {
            if (!cfg.sessionid || root._redirecting) {
                return;
            }
            call(cfg, 'get', {}).then(function(parsed) {
                applySession(root, parsed.session || parsed, cfg, engine);
            }).catch(function() { /* ignore */ });
        }, 14000);

        document.addEventListener('click', function(ev) {
            if (!document.body.classList.contains('nxi-ide-locked')) {
                return;
            }
            const hit = ev.target && ev.target.closest &&
                ev.target.closest('[data-action="run"], [data-action="submit"], [data-action="run-custom"]');
            if (hit) {
                ev.preventDefault();
                ev.stopPropagation();
            }
        }, true);
    };

    return {init: init};
});
