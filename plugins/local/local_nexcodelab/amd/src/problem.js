/**
 * NexCodeLab IDE.
 *
 * @module     local_nexcodelab/problem
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const SPLIT_KEY = 'nexcodelab.np.split.v2';
    const THEME_KEY = 'nexcodelab.np.theme.v1';
    const SIDEBAR_KEY = 'nexcodelab.np.sidebar.v1';

    const MODE_MAP = {
        python3: 'python', python: 'python', java: 'java', cpp: 'c_cpp', 'c++': 'c_cpp',
        c: 'c_cpp', javascript: 'javascript', js: 'javascript', nodejs: 'javascript',
        php: 'php', csharp: 'csharp', cs: 'csharp', ruby: 'ruby', go: 'golang',
        rust: 'rust', kotlin: 'kotlin', swift: 'swift', typescript: 'typescript',
        sql: 'sql', matlab: 'matlab', octave: 'matlab'
    };

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    let state = {
        problem: null,
        language: 'python3',
        strings: {},
        draftTimer: null,
        ace: null,
        aceReady: false,
        sampleIndex: 0,
        theme: 'light',
        list: [],
        listFilter: {search: '', difficulty: ''},
        cfg: {},
        busy: false
    };

    const readPrefs = () => {
        try {
            const raw = window.localStorage.getItem(SPLIT_KEY);
            if (raw) {
                return Object.assign({left: 44, bottom: 34}, JSON.parse(raw));
            }
        } catch (e) { /* ignore */ }
        return {left: 44, bottom: 34};
    };

    const writePrefs = (prefs) => {
        try {
            window.localStorage.setItem(SPLIT_KEY, JSON.stringify(prefs));
        } catch (e) { /* ignore */ }
    };

    const getCode = (root) => {
        if (state.aceReady && state.ace) {
            return state.ace.getValue();
        }
        return root.find('[data-region="editor"]').val() || '';
    };

    const setCode = (root, code) => {
        if (state.aceReady && state.ace) {
            state.ace.setValue(code || '', -1);
            return;
        }
        root.find('[data-region="editor"]').val(code || '');
    };

    const aceModeFor = (lang) => {
        const key = String(lang || '').toLowerCase().trim();
        const mapped = MODE_MAP[key] || key.replace(/\d+$/, '');
        return 'ace/mode/' + mapped;
    };

    const updateCursor = (root) => {
        if (!state.ace) {
            return;
        }
        const pos = state.ace.getCursorPosition();
        root.find('[data-region="cursor"]').text('Ln ' + (pos.row + 1) + ', Col ' + (pos.column + 1));
    };

    const THEME_SUN =
        '<svg viewBox="0 0 16 16" width="14" height="14" focusable="false" aria-hidden="true">' +
        '<circle cx="8" cy="8" r="3.2" fill="currentColor"/>' +
        '<path fill="currentColor" d="M7.35 1.2h1.3v1.7H7.35zm0 11.9h1.3v1.7H7.35zM1.2 7.35h1.7v1.3H1.2zm11.9 0h1.7v1.3h-1.7zM3.05 3.05l1.2 1.2-.92.92-1.2-1.2zm8.7 8.7l1.2 1.2-.92.92-1.2-1.2zm1.2-8.7-.92.92-1.2-1.2.92-.92zM4.25 11.75l-.92.92-1.2-1.2.92-.92z"/>' +
        '</svg>';
    const THEME_MOON =
        '<svg viewBox="0 0 16 16" width="14" height="14" focusable="false" aria-hidden="true">' +
        '<path fill="currentColor" d="M12.8 9.55A5.6 5.6 0 0 1 6.45 3.2a.55.55 0 0 0-.72-.66 6.5 6.5 0 1 0 8.73 8.73.55.55 0 0 0-.66-.72z"/>' +
        '</svg>';

    const detectRemuiDark = () => {
        try {
            const body = document.body;
            const html = document.documentElement;
            if (body.classList.contains('theme-dark')
                || body.classList.contains('dark-mode')
                || body.classList.contains('darkmode')
                || html.classList.contains('theme-dark')
                || html.getAttribute('data-bs-theme') === 'dark') {
                return true;
            }
            const remui = window.localStorage.getItem('remui_darkmode')
                || window.localStorage.getItem('darkMode');
            return remui === '1' || remui === 'true';
        } catch (e) {
            return false;
        }
    };

    const syncRemuiDark = (dark) => {
        const body = document.body;
        const html = document.documentElement;
        body.classList.toggle('theme-dark', dark);
        body.classList.toggle('dark-mode', dark);
        body.classList.toggle('darkmode', dark);
        html.classList.toggle('theme-dark', dark);
        html.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        try {
            window.localStorage.setItem('remui_darkmode', dark ? '1' : '0');
            window.localStorage.setItem('darkMode', dark ? 'true' : 'false');
        } catch (e) { /* ignore */ }
    };

    const neutralizeStatementInk = (root, dark) => {
        if (!dark) {
            return;
        }
        const host = root.find('[data-region="statement"]')[0];
        if (!host) {
            return;
        }
        host.querySelectorAll('[style*="color"], [style*="Color"]').forEach((el) => {
            const style = el.getAttribute('style') || '';
            const next = style.replace(/color\s*:\s*([^;]+);?/gi, (full, raw) => {
                const val = String(raw).trim().toLowerCase();
                const darkInk = /^(#0{3,6}|#111|#222|#333|#000000|#0f172a|#111827|#1e293b|#020617|black|rgb\(\s*0\s*,\s*0\s*,\s*0\s*\))$/
                    .test(val.replace(/\s+/g, ''));
                // Also catch near-black rgb values.
                const rgb = val.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
                const nearBlack = rgb && Number(rgb[1]) < 40 && Number(rgb[2]) < 40 && Number(rgb[3]) < 40;
                return (darkInk || nearBlack) ? '' : full;
            }).replace(/;\s*;/g, ';').replace(/^\s*;\s*|\s*;\s*$/g, '').trim();
            if (next) {
                el.setAttribute('style', next);
            } else {
                el.removeAttribute('style');
            }
        });
    };

    const applyTheme = (root) => {
        if (!root || !root.length) {
            return;
        }
        const dark = state.theme === 'dark';
        root.toggleClass('ncl-np--dark', dark);
        root.attr('data-theme', dark ? 'dark' : 'light');
        document.body.classList.toggle('ncl-ide-dark', dark);
        document.documentElement.classList.toggle('ncl-ide-dark', dark);
        document.body.setAttribute('data-ncl-theme', dark ? 'dark' : 'light');
        syncRemuiDark(dark);

        const label = dark ? (state.strings.dark || 'Dark') : (state.strings.light || 'Light');
        root.find('[data-region="theme-label"]').text(label);
        root.find('.ncl-np__theme-ico').html(dark ? THEME_MOON : THEME_SUN);
        root.find('[data-action="toggle-theme"]')
            .attr('aria-pressed', dark ? 'true' : 'false')
            .attr('title', 'Theme: ' + label)
            .toggleClass('is-dark', dark);

        neutralizeStatementInk(root, dark);

        if (state.ace) {
            try {
                // RemUI-aligned Ace themes (tomorrow_night matches slate dark UI).
                state.ace.setTheme(dark ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome');
                const host = root.find('[data-region="ace"], .ncl-np__ace-stage');
                host.css('background', dark ? '#1f2937' : '#ffffff');
            } catch (e) { /* ignore */ }
        }
        try {
            window.localStorage.setItem(THEME_KEY, state.theme);
        } catch (e) { /* ignore */ }
        setTimeout(resizeAce, 40);
    };

    const clearPanelFocus = (root) => {
        root.removeClass('ncl-np--focus-desc ncl-np--focus-right ncl-np--focus-editor');
        root.find('[data-action="focus-desc"], [data-action="focus-right"], [data-action="focus-editor"]')
            .removeClass('is-active').attr('aria-pressed', 'false');
    };

    const togglePanelFocus = (root, mode) => {
        const cls = 'ncl-np--focus-' + mode;
        const wasOn = root.hasClass(cls);
        clearPanelFocus(root);
        if (!wasOn) {
            root.addClass(cls);
            root.find('[data-action="focus-' + mode + '"]')
                .addClass('is-active').attr('aria-pressed', 'true');
            if (mode === 'editor') {
                setSidebarOpen(root, false);
            }
        }
        setTimeout(resizeAce, 60);
    };

    const exitBrowserFullscreen = () => {
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (!exit || (!document.fullscreenElement && !document.webkitFullscreenElement)) {
            return Promise.resolve();
        }
        try {
            return Promise.resolve(exit.call(document)).catch(() => null);
        } catch (e) {
            return Promise.resolve();
        }
    };

    const clearUiLocks = (root) => {
        if (root && root.length) {
            clearPanelFocus(root);
        }
        document.body.classList.remove('ncl-ide-resizing', 'ncl-ide-resizing-col', 'ncl-ide-resizing-row');
    };

    const goToList = (root, href) => {
        const url = href
            || (state.cfg && state.cfg.listUrl)
            || (root.find('[data-action="go-list"]').attr('href'))
            || '/local/nexcodelab/index.php';
        clearUiLocks(root);
        // Fullscreen often swallows or delays normal <a> navigation — exit first, then go.
        let navigated = false;
        const navigate = () => {
            if (navigated) {
                return;
            }
            navigated = true;
            window.location.assign(url);
        };
        exitBrowserFullscreen().then(navigate);
        window.setTimeout(navigate, 200);
    };

    const toggleBrowserFullscreen = () => {
        const el = document.documentElement;
        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
            const req = el.requestFullscreen || el.webkitRequestFullscreen;
            if (req) {
                req.call(el).catch(() => null);
            }
            return;
        }
        exitBrowserFullscreen();
    };

    const syncFullscreenButtons = (root) => {
        const on = !!(document.fullscreenElement || document.webkitFullscreenElement);
        root.find('[data-action="focus-page"]')
            .toggleClass('is-active', on)
            .attr('aria-pressed', on ? 'true' : 'false')
            .attr('title', on
                ? (state.strings.fsexit || 'Exit fullscreen')
                : (state.strings.fspage || 'Fullscreen page'));
    };

    const resizeAce = () => {
        if (state.aceReady && state.ace) {
            try {
                state.ace.resize(true);
            } catch (e) { /* ignore */ }
        }
    };

    const initAce = (root, cfg) => {
        const ta = root.find('[data-region="editor"]');
        const aceHost = root.find('[data-region="ace"]');
        if (!window.ace) {
            ta.removeAttr('hidden');
            aceHost.attr('hidden', true);
            return;
        }
        try {
            if (cfg.aceBaseUrl) {
                window.ace.config.set('basePath', cfg.aceBaseUrl);
            }
            try {
                window.ace.require('ace/ext/language_tools');
            } catch (e1) { /* optional */ }

            ta.attr('hidden', true);
            aceHost.removeAttr('hidden').empty();
            const inner = document.createElement('div');
            inner.className = 'ncl-np__ace-inner';
            aceHost[0].appendChild(inner);

            const editor = window.ace.edit(inner);
            editor.setOptions({
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                fontSize: 14,
                showPrintMargin: false,
                tabSize: 4,
                useSoftTabs: true,
                wrap: false,
                newLineMode: 'unix'
            });
            editor.$blockScrolling = Infinity;
            editor.session.setValue(ta.val() || '');
            editor.session.setMode(aceModeFor(state.language));
            editor.session.on('change', () => {
                ta.val(editor.getValue());
                scheduleDraft(root);
            });
            editor.selection.on('changeCursor', () => updateCursor(root));
            editor.selection.on('changeSelection', () => updateCursor(root));

            state.ace = editor;
            state.aceReady = true;
            applyTheme(root);
            updateCursor(root);
            aceHost.css({position: 'relative', width: '100%', height: '100%', minHeight: '160px'});
            inner.style.width = '100%';
            inner.style.height = '100%';
            editor.resize(true);
        } catch (err) {
            state.ace = null;
            state.aceReady = false;
            ta.removeAttr('hidden');
            aceHost.attr('hidden', true);
        }
    };

    const codeForLang = (lang) => {
        const p = state.problem;
        if (!p) {
            return '';
        }
        const drafts = {};
        (p.drafts || []).forEach((d) => {
            drafts[d.language] = d.code;
        });
        if (drafts[lang]) {
            return drafts[lang];
        }
        const L = (p.languages || []).find((x) => x.language === lang);
        return L ? (L.preload || '') : '';
    };

    const saveDraft = (root) => {
        if (!state.problem) {
            return;
        }
        Ajax.call([{
            methodname: 'local_nexcodelab_save_draft',
            args: {problemid: state.problem.id, language: state.language, code: getCode(root)}
        }])[0].catch(() => null);
    };

    const scheduleDraft = (root) => {
        clearTimeout(state.draftTimer);
        state.draftTimer = setTimeout(() => saveDraft(root), 800);
    };

    const ioCard = (label, body) =>
        '<div class="ncl-np__iocard">' +
        '<div class="ncl-np__iocard-head">' +
        '<span>' + esc(label) + '</span>' +
        '<button type="button" class="ncl-np__copy" data-copy="' + esc(body) + '" title="Copy">⧉</button>' +
        '</div>' +
        '<pre class="ncl-np__iocard-pre">' + esc(body) + '</pre></div>';

    const renderSampleView = (root) => {
        const samples = (state.problem && state.problem.samples) || [];
        const s = state.strings;
        const i = Math.min(state.sampleIndex, Math.max(0, samples.length - 1));
        state.sampleIndex = samples.length ? i : 0;

        root.find('[data-region="sample-chips"]').html(
            samples.map((sample, idx) =>
                '<button type="button" class="ncl-np__chip' + (idx === state.sampleIndex ? ' is-active' : '') +
                '" data-sample-idx="' + idx + '">' +
                (idx === state.sampleIndex ? '<span class="ncl-np__chip-check">✓</span> ' : '') +
                'Test ' + (idx + 1) + '</button>'
            ).join('')
        );

        if (!samples.length) {
            root.find('[data-region="sample-view"]').html(
                '<p class="ncl-np__empty">' + esc(s.nosamples || 'No sample tests.') + '</p>'
            );
            return;
        }
        const sample = samples[state.sampleIndex];
        root.find('[data-region="sample-view"]').html(
            '<div class="ncl-np__iocards">' +
            ioCard(s.input || 'Input', sample.stdin) +
            ioCard(s.output || 'Output', sample.expected) +
            '</div>' +
            (sample.explanation
                ? '<div class="ncl-np__explain"><strong>' + esc(s.explanation || 'Explanation') +
                    ':</strong> ' + esc(sample.explanation) + '</div>'
                : '')
        );
    };

    const renderExamplesInDesc = (root) => {
        const samples = (state.problem && state.problem.samples) || [];
        const s = state.strings;
        // Avoid duplicating if statement HTML already embeds examples heavily.
        if (!samples.length) {
            root.find('[data-region="examples"]').empty();
            return;
        }
        const html = samples.map((sample, idx) =>
            '<article class="ncl-np__example">' +
            '<h3 class="ncl-np__example-title">Example ' + (idx + 1) + ':</h3>' +
            '<div class="ncl-np__iocards">' +
            ioCard(s.input || 'Input', sample.stdin) +
            ioCard(s.output || 'Output', sample.expected) +
            '</div>' +
            (sample.explanation
                ? '<div class="ncl-np__explain"><strong>' + esc(s.explanation || 'Explanation') +
                    ':</strong> ' + esc(sample.explanation) + '</div>'
                : '') +
            '</article>'
        ).join('');
        root.find('[data-region="examples"]').html(html);
    };

    const renderResults = (root, payload) => {
        const s = state.strings;
        const results = payload.results || [];
        let html = '';
        if (payload.statusLabel || payload.allPassed || payload.message) {
            const ok = !!payload.allPassed;
            html += '<div class="ncl-np__outcome ' + (ok ? 'is-ok' : 'is-fail') + '">' +
                esc(ok ? (s.accepted || 'Accepted') : (payload.statusLabel || s.wronganswer || 'Wrong Answer'));
            if (payload.passed != null) {
                html += ' <span class="ncl-np__pass">' + esc(payload.passed) + ' / ' + esc(payload.total) + '</span>';
            }
            html += '</div>';
        }
        if (payload.message) {
            html += '<div class="ncl-np__err">' + esc(payload.message) + '</div>';
        }
        results.forEach((r, idx) => {
            const empty = !(r.actual && String(r.actual).length);
            html += '<article class="ncl-np__result ' + (r.isCorrect ? 'is-ok' : 'is-fail') + '">' +
                '<header>Case ' + (idx + 1) + (r.display === 'hidden' ? ' (hidden)' : '') + '</header>' +
                (r.display !== 'hidden'
                    ? ('<div class="ncl-np__iocards">' +
                        ioCard(s.input || 'Input', r.input) +
                        ioCard(s.expected || 'Expected', r.expected) +
                        '</div>')
                    : '') +
                '<div class="ncl-np__iocard"><div class="ncl-np__iocard-head">' +
                esc(empty ? (s.nooutput || 'No Output') : (s.youroutput || 'Your Output')) +
                '</div><pre class="ncl-np__iocard-pre' + (empty ? ' is-empty' : '') + '">' +
                (empty ? esc(s.nooutput || 'No Output') : esc(r.actual)) + '</pre></div>' +
                (r.stderr ? '<pre class="ncl-np__iocard-pre">' + esc(r.stderr) + '</pre>' : '') +
                '</article>';
        });
        const panel = root.find('[data-region="results"]');
        panel.html(html || '<p class="ncl-np__empty">No results.</p>');
        root.find('[data-right]').removeClass('is-active');
        root.find('[data-right="results"]').addClass('is-active');
        root.find('[data-region="samples"], [data-region="hidden"], [data-region="custom"]').attr('hidden', true);
        panel.removeAttr('hidden');
    };

    const loadSubs = (root) => {
        Ajax.call([{
            methodname: 'local_nexcodelab_get_submissions',
            args: {problemid: state.problem.id, page: 0, perpage: 30}
        }])[0].then((data) => {
            const rows = (data.submissions || []).map((row) =>
                '<tr><td>' + esc(row.timestr) + '</td><td>' + esc(row.status) + '</td><td>' +
                esc(row.passed) + '/' + esc(row.total) + '</td><td>' + esc(row.language) + '</td></tr>'
            ).join('');
            root.find('[data-region="subs"]').html(
                rows
                    ? '<table class="ncl-np__table"><thead><tr><th>When</th><th>Status</th><th>Tests</th><th>Lang</th></tr></thead><tbody>' +
                        rows + '</tbody></table>'
                    : '<p class="ncl-np__empty">No submissions yet.</p>'
            );
            return null;
        }).catch(Notification.exception);
    };

    const diffLabel = (d) => {
        const map = {easy: 'EASY', medium: 'MEDIUM', hard: 'HARD', veryhard: 'VERY HARD'};
        return map[d] || String(d || '').toUpperCase();
    };

    const renderSidebar = (root) => {
        let items = state.list.slice();
        const q = (state.listFilter.search || '').toLowerCase();
        const diff = state.listFilter.difficulty || '';
        if (q) {
            items = items.filter((p) => (p.name || '').toLowerCase().indexOf(q) !== -1);
        }
        if (diff) {
            items = items.filter((p) => p.difficulty === diff);
        }
        root.find('[data-region="sidebar-count"]').text(items.length + ' total problems');
        const currentId = state.problem ? state.problem.id : state.cfg.problemId;
        root.find('[data-region="sidebar-list"]').html(
            items.map((p, idx) =>
                '<a class="ncl-np__plist-item' + (Number(p.id) === Number(currentId) ? ' is-active' : '') +
                '" href="' + esc(p.url || (state.cfg.listUrl.replace(/index\.php.*/, 'problem.php?id=') + p.id)) +
                '" data-problemid="' + esc(p.id) + '">' +
                '<span class="ncl-np__plist-num">' + esc(p.number || (idx + 1)) + '</span>' +
                '<span class="ncl-np__plist-name">' + esc(p.name) + '</span>' +
                '<span class="ncl-np__plist-diff ncl-np__plist-diff--' + esc(p.difficulty) + '">' +
                esc(diffLabel(p.difficulty)) + '</span></a>'
            ).join('') || '<p class="ncl-np__empty">No problems.</p>'
        );
    };

    const loadSidebar = (root) => {
        Ajax.call([{
            methodname: 'local_nexcodelab_get_problems',
            args: {search: '', difficulty: '', userstatus: 'all', tagid: 0, page: 0, perpage: 200}
        }])[0].then((data) => {
            state.list = data.problems || [];
            renderSidebar(root);
            return null;
        }).catch(() => null);
    };

    const syncLangSelects = (root) => {
        const sel = root.find('[data-region="language"]');
        sel.empty();
        (state.problem.languages || []).forEach((l) => {
            sel.append($('<option/>').val(l.language).text(l.language));
        });
        sel.val(state.language);
        root.find('[data-region="status-lang"]').text(state.language);
    };

    const activateRightTab = (root, tab) => {
        root.find('[data-right]').removeClass('is-active');
        root.find('[data-right="' + tab + '"]').addClass('is-active');
        root.find('[data-region="samples"], [data-region="hidden"], [data-region="custom"], [data-region="results"]')
            .attr('hidden', true);
        root.find('[data-region="' + tab + '"]').removeAttr('hidden');
    };

    const setPanelLoader = (root, which, on) => {
        const loader = root.find('[data-region="' + which + '-loader"]');
        const body = root.find('[data-region="' + which + '"]');
        if (on) {
            activateRightTab(root, which);
            loader.removeAttr('hidden');
            body.addClass('is-loading');
        } else {
            loader.attr('hidden', true);
            body.removeClass('is-loading');
        }
    };

    const toastXp = (root, xp) => {
        const amount = Number(xp) || 0;
        if (amount <= 0 || !root || !root.length) {
            return;
        }
        const template = state.strings.xpearned || '+{$a} XP earned!';
        const msg = String(template)
            .replace(/\{\$a\}/g, String(amount))
            .replace(/\{xp\}/g, String(amount));

        const host = root.find('[data-region="toasts"]');
        if (!host.length) {
            Notification.addNotification({message: msg, type: 'success'});
            return;
        }

        const id = 'ncl-toast-' + Date.now();
        const el = $(
            '<div class="ncl-np__toast ncl-np__toast--xp" id="' + id + '" role="status">' +
            '<div class="ncl-np__toast-ico" aria-hidden="true">✦</div>' +
            '<div class="ncl-np__toast-body">' +
            '<div class="ncl-np__toast-title">' + esc(state.strings.accepted || 'Accepted') + '</div>' +
            '<div class="ncl-np__toast-msg">' + esc(msg) + '</div>' +
            '</div>' +
            '<button type="button" class="ncl-np__toast-close" aria-label="Close">×</button>' +
            '</div>'
        );
        host.append(el);
        // Trigger enter animation on next frame.
        window.requestAnimationFrame(() => el.addClass('is-visible'));

        const remove = () => {
            el.removeClass('is-visible').addClass('is-leaving');
            window.setTimeout(() => el.remove(), 220);
        };
        el.find('.ncl-np__toast-close').on('click', remove);
        window.setTimeout(remove, 4200);
    };

    const icoThumb = () =>
        '<svg class="ncl-np__ico" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M6.2 14H3.5a1.2 1.2 0 0 1-1.2-1.2V7.5A1.2 1.2 0 0 1 3.5 6.3h2.05l.55-2.75A1.6 1.6 0 0 1 7.65 2.2h.2c.55 0 1 .45 1 1V5h2.7c.9 0 1.55.85 1.35 1.7l-1.05 4.2A1.6 1.6 0 0 1 10.5 12.2H7.8"/>' +
        '</svg>';

    const icoRate = () =>
        '<svg class="ncl-np__ico" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">' +
        '<circle cx="8" cy="8" r="6.25" fill="none" stroke="currentColor" stroke-width="1.4"/>' +
        '<path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M8 4.4v3.7l2.4 1.4"/>' +
        '</svg>';

    const fillProblem = (root, p) => {
        const s = state.strings;
        root.find('[data-region="title"]').text(p.name || '');
        const acceptance = (p.acceptance != null ? Number(p.acceptance).toFixed(1) : '0.0') + '%';
        const tags = (p.tags || []).map((t) =>
            '<span class="ncl-np__tag">' + esc(t.name) + '</span>'
        ).join('');
        root.find('[data-region="meta"]').html(
            '<span class="ncl-np__diff ncl-np__diff--' + esc(p.difficulty) + '">' + esc(p.difficulty) + '</span>' +
            '<span class="ncl-np__stat" title="Solvers">' + icoThumb() +
                '<strong>' + esc(p.solvers || 0) + '</strong></span>' +
            '<span class="ncl-np__stat" title="Success rate">' + icoRate() +
                '<strong>' + esc(acceptance) + '</strong></span>' +
            (tags ? '<span class="ncl-np__tags">' + tags + '</span>' : '')
        );
        root.find('[data-region="statement"]').html(
            '<div class="ncl-np__qtext">' + (p.statement || '') + '</div>'
        );
        // CR question text often already embeds Example/Testcase blocks — avoid duplicating.
        const stmt = String(p.statement || '');
        const embedded = /example|testcase/i.test(stmt) && stmt.length > 160;
        if (embedded) {
            root.find('[data-region="examples"]').empty();
        } else {
            renderExamplesInDesc(root);
        }
        renderSampleView(root);
        const hidden = Number(p.hiddenCount || 0);
        root.find('[data-region="hidden-msg"]').text(
            hidden > 0
                ? (hidden + ' hidden testcase' + (hidden === 1 ? '' : 's') + ' (revealed after Submit).')
                : (s.nohidden || 'No hidden testcases.')
        );
        syncLangSelects(root);
        setCode(root, codeForLang(state.language));
        renderSidebar(root);
        document.title = (p.name || 'Problem') + ' · NexCodeLab';
    };

    const applySplit = (root, prefs) => {
        const left = Math.min(62, Math.max(30, prefs.left));
        const bottom = Math.min(55, Math.max(20, prefs.bottom));
        root.find('[data-region="left-pane"]').css({
            flex: '0 0 ' + left + '%',
            width: left + '%',
            maxWidth: left + '%'
        });
        root.find('[data-region="bottom"]').css({
            flex: '0 0 ' + bottom + '%',
            height: bottom + '%',
            maxHeight: bottom + '%'
        });
        writePrefs({left: left, bottom: bottom});
        resizeAce();
    };

    const bindResizers = (root) => {
        const prefs = readPrefs();
        applySplit(root, prefs);

        const startDrag = (kind, ev) => {
            ev.preventDefault();
            const split = root.find('[data-region="split"]')[0];
            const rect = split.getBoundingClientRect();
            document.body.classList.add('ncl-ide-resizing', kind === 'col' ? 'ncl-ide-resizing-col' : 'ncl-ide-resizing-row');

            const onMove = (e) => {
                const pt = e.touches ? e.touches[0] : e;
                if (kind === 'col') {
                    prefs.left = ((pt.clientX - rect.left) / rect.width) * 100;
                } else {
                    const rightH = root.find('[data-region="right-pane"]')[0].getBoundingClientRect().height;
                    prefs.bottom = ((rect.bottom - pt.clientY) / rightH) * 100;
                }
                applySplit(root, prefs);
            };
            const onUp = () => {
                document.body.classList.remove('ncl-ide-resizing', 'ncl-ide-resizing-col', 'ncl-ide-resizing-row');
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('touchend', onUp);
                window.removeEventListener('blur', onUp);
                resizeAce();
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, {passive: false});
            document.addEventListener('touchend', onUp);
            window.addEventListener('blur', onUp);
        };

        root.on('mousedown', '[data-action="resize-col"]', (e) => startDrag('col', e));
        root.on('mousedown', '[data-action="resize-row"]', (e) => startDrag('row', e));
        root.on('touchstart', '[data-action="resize-col"]', (e) => startDrag('col', e));
        root.on('touchstart', '[data-action="resize-row"]', (e) => startDrag('row', e));
        $(window).on('resize.llnp', () => resizeAce());
    };

    const setSidebarOpen = (root, open) => {
        root.toggleClass('ncl-np--sidebar-open', open);
        root.toggleClass('ncl-np--sidebar-closed', !open);
        try {
            window.localStorage.setItem(SIDEBAR_KEY, open ? '1' : '0');
        } catch (e) { /* ignore */ }
        setTimeout(resizeAce, 60);
    };

    const bind = (root, cfg) => {
        root.on('click', '[data-action="toggle-sidebar"]', () => {
            setSidebarOpen(root, !root.hasClass('ncl-np--sidebar-open'));
        });

        root.on('click', '[data-left]', function() {
            const tab = $(this).data('left');
            root.find('[data-left]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-region="desc"], [data-region="solution"], [data-region="subs"], [data-region="discussion"]')
                .attr('hidden', true);
            root.find('[data-region="' + tab + '"]').removeAttr('hidden');
            if (tab === 'subs') {
                loadSubs(root);
            }
        });

        root.on('click', '[data-right]', function() {
            const tab = $(this).data('right');
            root.find('[data-right]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-region="samples"], [data-region="hidden"], [data-region="custom"], [data-region="results"]')
                .attr('hidden', true);
            root.find('[data-region="' + tab + '"]').removeAttr('hidden');
        });

        root.on('click', '[data-sample-idx]', function() {
            state.sampleIndex = parseInt($(this).attr('data-sample-idx'), 10) || 0;
            renderSampleView(root);
        });

        root.on('click', '[data-copy]', function(e) {
            e.preventDefault();
            const text = $(this).attr('data-copy') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).catch(() => null);
            }
        });

        root.on('input', '[data-region="sidebar-search"]', function() {
            state.listFilter.search = $(this).val() || '';
            renderSidebar(root);
        });

        root.on('click', '[data-diff]', function() {
            root.find('[data-diff]').removeClass('is-active');
            $(this).addClass('is-active');
            state.listFilter.difficulty = $(this).attr('data-diff') || '';
            renderSidebar(root);
        });

        const onLangChange = function() {
            state.language = $(this).val();
            root.find('[data-region="language"]').val(state.language);
            setCode(root, codeForLang(state.language));
            root.find('[data-region="status-lang"]').text(state.language);
            if (state.ace) {
                try {
                    state.ace.session.setMode(aceModeFor(state.language));
                } catch (e) { /* ignore */ }
            }
        };
        root.on('change', '[data-region="language"]', onLangChange);
        root.on('input', '[data-region="editor"]', () => scheduleDraft(root));

        root.on('click', '[data-action="focus-page"]', (e) => {
            e.preventDefault();
            toggleBrowserFullscreen();
        });
        root.on('click', '[data-action="focus-desc"]', (e) => {
            e.preventDefault();
            togglePanelFocus(root, 'desc');
        });
        root.on('click', '[data-action="focus-right"]', (e) => {
            e.preventDefault();
            togglePanelFocus(root, 'right');
        });
        root.on('click', '[data-action="focus-editor"]', (e) => {
            e.preventDefault();
            togglePanelFocus(root, 'editor');
        });
        root.on('click', '[data-action="go-list"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            goToList(root, $(this).attr('href'));
        });

        // Sidebar problem links must also leave fullscreen cleanly.
        root.on('click', 'a.ncl-np__plist-item', function(e) {
            const href = $(this).attr('href');
            if (!href || href === '#') {
                return;
            }
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                e.preventDefault();
                clearUiLocks(root);
                exitBrowserFullscreen().then(() => {
                    window.location.assign(href);
                });
                window.setTimeout(() => window.location.assign(href), 200);
            }
        });

        const onFsChange = () => {
            syncFullscreenButtons(root);
            setTimeout(resizeAce, 80);
        };
        document.addEventListener('fullscreenchange', onFsChange);
        document.addEventListener('webkitfullscreenchange', onFsChange);

        $(document).on('keydown.llnpfs', (e) => {
            if (e.key === 'Escape' && root.is('.ncl-np--focus-desc, .ncl-np--focus-right, .ncl-np--focus-editor')) {
                clearPanelFocus(root);
                setTimeout(resizeAce, 50);
            }
        });

        const run = (mode) => {
            if (!cfg.canAttempt) {
                Notification.addNotification({message: 'No attempt permission', type: 'error'});
                return;
            }
            if (state.busy) {
                return;
            }
            state.busy = true;

            const runBtn = root.find('[data-action="run"]');
            const submitBtn = root.find('[data-action="submit"]');
            const customBtn = root.find('[data-action="run-custom"]');
            const actionBtns = root.find('[data-action="run"], [data-action="run-custom"], [data-action="submit"]');
            const prevRun = runBtn.html();
            const prevSubmit = submitBtn.html();
            const prevCustom = customBtn.html();

            actionBtns.addClass('is-busy').attr('aria-busy', 'true');
            if (mode === 'submit') {
                submitBtn.html(esc(state.strings.submitting || 'Submitting…'));
                setPanelLoader(root, 'hidden', true);
            } else {
                runBtn.html(esc(state.strings.running || 'Running…'));
                customBtn.html(esc(state.strings.running || 'Running…'));
                setPanelLoader(root, 'samples', true);
            }

            const done = () => {
                state.busy = false;
                actionBtns.removeClass('is-busy').attr('aria-busy', 'false');
                runBtn.html(prevRun);
                submitBtn.html(prevSubmit);
                customBtn.html(prevCustom);
                setPanelLoader(root, 'samples', false);
                setPanelLoader(root, 'hidden', false);
            };

            const args = {
                problemid: state.problem.id,
                language: state.language,
                code: getCode(root),
                mode: mode === 'custom' ? 'custom' : 'sample',
                stdin: root.find('[data-region="custom-stdin"]').val() || '',
                expected: root.find('[data-region="custom-expected"]').val() || ''
            };
            const method = mode === 'submit' ? 'local_nexcodelab_submit_code' : 'local_nexcodelab_run_code';
            const callArgs = mode === 'submit'
                ? {problemid: args.problemid, language: args.language, code: args.code}
                : args;

            Promise.resolve(Ajax.call([{methodname: method, args: callArgs}])[0])
                .then((payload) => {
                    renderResults(root, payload);
                    if (mode === 'submit') {
                        loadSubs(root);
                        toastXp(root, payload && payload.xpAwarded);
                    }
                    return null;
                })
                .catch((err) => {
                    Notification.exception(err);
                    return null;
                })
                .then(done, done);
        };

        root.on('click', '[data-action="run"]', () => run('sample'));
        root.on('click', '[data-action="run-custom"]', () => run('custom'));
        root.on('click', '[data-action="submit"]', () => run('submit'));

        bindResizers(root);
    };

    const init = function(cfg) {
        cfg = cfg || {};
        state.cfg = cfg;
        state.strings = cfg.strings || {};
        try {
            const stored = window.localStorage.getItem(THEME_KEY);
            if (stored === 'dark' || stored === 'light') {
                state.theme = stored;
            } else {
                state.theme = detectRemuiDark() ? 'dark' : 'light';
            }
        } catch (e) {
            state.theme = detectRemuiDark() ? 'dark' : 'light';
        }

        const root = $('[data-region="ncl-ide"]');
        if (!root.length) {
            return;
        }
        document.body.classList.add('has-ncl-ide');

        // Apply saved theme immediately so the shell paints correctly before Ajax.
        applyTheme(root);

        // Theme toggle must work even while problem data is still loading.
        root.off('click.lltheme').on('click.lltheme', '[data-action="toggle-theme"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            state.theme = state.theme === 'dark' ? 'light' : 'dark';
            applyTheme(root);
            // Do not programmatically click RemUI's toggle — it can steal focus / overlay the IDE.
        });

        // Sidebar closed by default; only open if user previously opened it.
        let sidebarOpen = false;
        try {
            sidebarOpen = window.localStorage.getItem(SIDEBAR_KEY) === '1';
        } catch (e) { /* ignore */ }
        setSidebarOpen(root, sidebarOpen);

        const problemId = cfg.problemId || parseInt(root.data('problemid'), 10);
        const start = Date.now();
        const boot = () => {
            if (cfg.aceBaseUrl && !window.ace && (Date.now() - start) < 2500) {
                window.setTimeout(boot, 50);
                return;
            }
            Ajax.call([{
                methodname: 'local_nexcodelab_get_problem',
                args: {problemid: problemId}
            }])[0].then((p) => {
                state.problem = p;
                state.language = p.defaultlanguage || ((p.languages[0] || {}).language) || 'python3';
                fillProblem(root, p);
                bind(root, cfg);
                initAce(root, cfg);
                applyTheme(root);
                loadSidebar(root);
                setTimeout(resizeAce, 100);
                return null;
            }).catch(Notification.exception);
        };
        boot();
    };

    return {init};
});
