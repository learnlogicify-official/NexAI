/**
 * Mission lab bench — Stacks-style studio layout + RemUI theme sync.
 *
 * @module     local_nexcodelab/mission
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const THEME_KEY = 'nexcodelab.np.theme.v1';
    const SPLIT_KEY = 'nexcodelab.mission.split.v2';
    const SIDEBAR_KEY = 'nexcodelab.mission.sidebar.v1';

    const THEME_SUN =
        '<svg viewBox="0 0 16 16" width="14" height="14" focusable="false" aria-hidden="true">' +
        '<circle cx="8" cy="8" r="3.2" fill="currentColor"/>' +
        '<path fill="currentColor" d="M7.35 1.2h1.3v1.7H7.35zm0 11.9h1.3v1.7H7.35zM1.2 7.35h1.7v1.3H1.2zm11.9 0h1.7v1.3h-1.7zM3.05 3.05l1.2 1.2-.92.92-1.2-1.2zm8.7 8.7l1.2 1.2-.92.92-1.2-1.2zm1.2-8.7-.92.92-1.2-1.2.92-.92zM4.25 11.75l-.92.92-1.2-1.2.92-.92z"/>' +
        '</svg>';
    const THEME_MOON =
        '<svg viewBox="0 0 16 16" width="14" height="14" focusable="false" aria-hidden="true">' +
        '<path fill="currentColor" d="M12.8 9.55A5.6 5.6 0 0 1 6.45 3.2a.55.55 0 0 0-.72-.66 6.5 6.5 0 1 0 8.73 8.73.55.55 0 0 0-.66-.72z"/>' +
        '</svg>';

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    let state = {
        mission: null,
        stepId: 0,
        activePath: 'main.py',
        ace: null,
        aceReady: false,
        csvRaw: false,
        originals: {},
        cfg: {},
        busy: false,
        draftTimer: null,
        theme: 'light',
        split: {left: 36, files: 36, console: 30},
        list: [],
        listFilter: {search: '', track: ''}
    };

    const trackLabel = (t) => {
        const map = {wrangling: 'Wrangling', eda: 'EDA', ml: 'ML', nlp: 'NLP'};
        return map[t] || t || '';
    };

    const resizeAce = () => {
        if (!state.ace) {
            return;
        }
        try {
            state.ace.resize(true);
        } catch (e) { /* ignore */ }
    };

    const readSplitPrefs = () => {
        try {
            const raw = window.localStorage.getItem(SPLIT_KEY);
            if (!raw) {
                return Object.assign({}, state.split);
            }
            const parsed = JSON.parse(raw);
            return {
                left: Number(parsed.left) || 36,
                files: Number(parsed.files) || 36,
                console: Number(parsed.console) || 30
            };
        } catch (e) {
            return Object.assign({}, state.split);
        }
    };

    const writeSplitPrefs = (prefs) => {
        state.split = prefs;
        try {
            window.localStorage.setItem(SPLIT_KEY, JSON.stringify(prefs));
        } catch (e) { /* ignore */ }
    };

    const applySplit = (root, prefs) => {
        const left = Math.min(52, Math.max(22, prefs.left));
        const cons = Math.min(55, Math.max(16, prefs.console));
        const next = {left: left, files: prefs.files || 34, console: cons};
        writeSplitPrefs(next);

        const body = root.find('[data-region="split"]')[0];
        const rightPane = root.find('[data-region="right-pane"]')[0];
        if (body) {
            body.style.setProperty('grid-template-columns',
                'minmax(260px, ' + left + '%) 10px minmax(0, 1fr)');
        }
        if (rightPane) {
            rightPane.style.setProperty('grid-template-rows',
                'minmax(0, 1fr) 10px minmax(110px, ' + cons + '%)');
        }
        root[0].style.setProperty('--sb-left', left + '%');
        root[0].style.setProperty('--sb-console', cons + '%');
        resizeAce();
    };

    const bindResizers = (root) => {
        applySplit(root, readSplitPrefs());

        const startDrag = (kind, ev) => {
            ev.preventDefault();
            const prefs = Object.assign({}, state.split);
            const split = root.find('[data-region="split"]')[0];
            const rightPane = root.find('[data-region="right-pane"]')[0];
            const splitRect = split.getBoundingClientRect();
            document.body.classList.add(
                'ncl-ide-resizing',
                kind === 'col' ? 'ncl-ide-resizing-col' : 'ncl-ide-resizing-row'
            );

            const onMove = (e) => {
                const pt = e.touches ? e.touches[0] : e;
                if (kind === 'col') {
                    prefs.left = ((pt.clientX - splitRect.left) / splitRect.width) * 100;
                } else {
                    const rect = rightPane.getBoundingClientRect();
                    prefs.console = ((rect.bottom - pt.clientY) / rect.height) * 100;
                }
                applySplit(root, prefs);
            };
            const onUp = () => {
                document.body.classList.remove(
                    'ncl-ide-resizing',
                    'ncl-ide-resizing-col',
                    'ncl-ide-resizing-row'
                );
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
        $(window).on('resize.nclmission', () => resizeAce());
    };

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

    const applyTheme = (root) => {
        const dark = state.theme === 'dark';
        root.toggleClass('ncl-bench--dark', dark);
        root.attr('data-theme', dark ? 'dark' : 'light');
        document.body.classList.toggle('ncl-ide-dark', dark);
        document.documentElement.classList.toggle('ncl-ide-dark', dark);
        document.body.setAttribute('data-ncl-theme', dark ? 'dark' : 'light');
        syncRemuiDark(dark);

        const strings = state.cfg.strings || {};
        const label = dark ? (strings.dark || 'Dark') : (strings.light || 'Light');
        root.find('[data-region="theme-label"]').text(label);
        root.find('.ncl-np__theme-ico').html(dark ? THEME_MOON : THEME_SUN);
        root.find('[data-action="toggle-theme"]')
            .attr('aria-pressed', dark ? 'true' : 'false')
            .attr('title', 'Theme: ' + label)
            .toggleClass('is-dark', dark);

        if (state.ace) {
            try {
                state.ace.setTheme(dark ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome');
                root.find('[data-region="ace"], .ncl-bench__ace-stage')
                    .css('background', dark ? '#1e1e1e' : '#ffffff');
            } catch (e) { /* ignore */ }
        }
        try {
            window.localStorage.setItem(THEME_KEY, state.theme);
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
        }
        root.find('[data-region="editor"]').val(code || '');
    };

    const fileByPath = (path) => (state.mission.files || []).find((f) => f.path === path);
    const codeFile = () => (state.mission.files || []).find((f) => f.role === 'code') || fileByPath('main.py');
    const dataFile = () => (state.mission.files || []).find((f) => f.role === 'data') || fileByPath('data.csv');
    const briefFile = () => (state.mission.files || []).find((f) => f.role === 'brief') || fileByPath('BRIEF.md');
    const currentStep = () => (state.mission.steps || []).find((s) => Number(s.id) === Number(state.stepId));

    const renderBriefHtml = (root) => {
        const f = briefFile();
        if (!f) {
            root.find('[data-region="brief"]').empty();
            return;
        }
        let md = f.content || '';
        md = md
            .replace(/^### (.*)$/gm, '<h3>$1</h3>')
            .replace(/^## (.*)$/gm, '<h2>$1</h2>')
            .replace(/^# (.*)$/gm, '<h2>$1</h2>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/^\|(.+)\|$/gm, (line) => {
                return line;
            });
        // Simple table support: consecutive | lines
        const lines = md.split('\n');
        let html = '';
        let inTable = false;
        lines.forEach((line) => {
            const trimmed = line.trim();
            if (trimmed.startsWith('|') && trimmed.endsWith('|')) {
                const cells = trimmed.split('|').slice(1, -1).map((c) => c.trim());
                if (cells.every((c) => /^:?-+:?$/.test(c))) {
                    return;
                }
                if (!inTable) {
                    html += '<table class="ncl-bench__mdtable"><tbody>';
                    inTable = true;
                }
                const tag = html.indexOf('<th>') === -1 && html.endsWith('<tbody>') ? 'th' : 'td';
                // first row as header
                const isFirst = /<tbody>$/.test(html);
                html += '<tr>' + cells.map((c) =>
                    '<' + (isFirst ? 'th' : 'td') + '>' + c + '</' + (isFirst ? 'th' : 'td') + '>'
                ).join('') + '</tr>';
                return;
            }
            if (inTable) {
                html += '</tbody></table>';
                inTable = false;
            }
            if (trimmed === '') {
                html += '<p></p>';
            } else if (/^<[hH][1-3]>/.test(trimmed) || /^<table/.test(trimmed)) {
                html += trimmed;
            } else if (/^\d+\.\s/.test(trimmed)) {
                html += '<p class="ncl-bench__oli">' + trimmed + '</p>';
            } else {
                html += '<p>' + trimmed + '</p>';
            }
        });
        if (inTable) {
            html += '</tbody></table>';
        }
        root.find('[data-region="brief"]').html(html);
    };

    const updateStepNav = (root) => {
        const steps = state.mission.steps || [];
        const idx = steps.findIndex((s) => Number(s.id) === Number(state.stepId));
        const prev = idx > 0 ? steps[idx - 1] : null;
        const next = idx >= 0 && idx < steps.length - 1 ? steps[idx + 1] : null;
        const prevBtn = root.find('[data-action="prev-step"]');
        const nextBtn = root.find('[data-action="next-step"]');
        prevBtn.prop('disabled', !prev || !!prev.locked);
        nextBtn.prop('disabled', !next || !!next.locked);
        if (state.mission && state.mission.name) {
            const step = currentStep();
            const crumb = state.mission.name + (step ? ' › ' + step.title : '');
            root.find('[data-region="crumb"]').text(crumb);
        }
    };

    const renderSteps = (root) => {
        const steps = state.mission.steps || [];
        const passed = steps.filter((s) => s.passed).length;
        root.find('[data-region="steps-sub"]').text(passed + ' / ' + steps.length);
        root.find('[data-region="steps-list"]').html(steps.map((s) => {
            let cls = 'ncl-bench__stepchip';
            if (s.passed) {
                cls += ' is-passed';
            }
            if (s.locked) {
                cls += ' is-locked';
            }
            if (Number(s.id) === Number(state.stepId)) {
                cls += ' is-active';
            }
            return '<button type="button" class="' + cls + '" data-stepid="' + esc(s.id) + '"' +
                (s.locked ? ' disabled' : '') + ' role="listitem" title="' + esc(s.title) + ' · ' + esc(s.xp) + ' XP">' +
                '<span class="ncl-bench__stepchip-num">' + (s.passed ? '✓' : esc(s.number)) + '</span>' +
                '<span class="ncl-bench__stepchip-title">' + esc(s.title) + '</span>' +
                '<span class="ncl-bench__stepchip-xp">' + esc(s.xp) + ' XP</span>' +
                '</button>';
        }).join(''));
        const step = currentStep();
        if (step) {
            root.find('[data-region="docs-path"]').text(
                'Step ' + (step.number || '') + ' · ' + (step.title || '')
            );
            root.find('[data-region="stepcopy"]').html(
                '<h3>' + esc(step.title) + '</h3>' + (step.instructions || '')
            );
            const hintBtn = root.find('[data-action="hint"]');
            const hintEl = root.find('[data-region="hint"]');
            if (step.hint) {
                hintBtn.removeAttr('hidden');
                hintEl.attr('hidden', true).text(step.hint);
            } else {
                hintBtn.attr('hidden', true);
                hintEl.attr('hidden', true).text('');
            }
        }
        updateStepNav(root);
    };

    const renderTabs = (root) => {
        const files = state.mission.files || [];
        root.find('[data-region="filetabs"]').html(files.map((f) => {
            const icon = f.role === 'data' ? '▦' : (f.role === 'brief' ? '☰' : '{}');
            const roleLabel = f.role === 'data' ? 'data' : (f.role === 'brief' ? 'brief' : 'code');
            return '<button type="button" class="ncl-bench__filetab' +
                (f.path === state.activePath ? ' is-active' : '') +
                '" data-path="' + esc(f.path) + '" role="tab" aria-selected="' +
                (f.path === state.activePath ? 'true' : 'false') + '" title="' + esc(roleLabel) + '">' +
                '<span class="ncl-bench__filetab-ico" aria-hidden="true">' + icon + '</span>' +
                '<span class="ncl-bench__filetab-name">' + esc(f.path) + '</span>' +
                '</button>';
        }).join(''));
    };

    const showPane = (root, role) => {
        root.find('[data-pane]').attr('hidden', true);
        if (role === 'brief') {
            root.find('[data-pane="brief"]').removeAttr('hidden');
        } else if (role === 'data') {
            root.find('[data-pane="data"]').removeAttr('hidden');
        } else {
            root.find('[data-pane="code"]').removeAttr('hidden');
            setTimeout(resizeAce, 40);
        }
    };

    const renderCsv = (root) => {
        const preview = state.mission.csvpreview || {headers: [], rows: [], rowcount: 0, colcount: 0};
        root.find('[data-region="shape"]').text(
            preview.rowcount + ' rows · ' + preview.colcount + ' cols'
        );
        const df = dataFile();
        root.find('[data-region="csv-raw"]').text(df ? df.content : '');
        let table = '<table class="ncl-bench__table"><thead><tr>';
        (preview.headers || []).forEach((h) => {
            table += '<th>' + esc(h) + '</th>';
        });
        table += '</tr></thead><tbody>';
        (preview.rows || []).forEach((r) => {
            table += '<tr>';
            (r.cells || []).forEach((c) => {
                table += '<td>' + esc(c) + '</td>';
            });
            table += '</tr>';
        });
        table += '</tbody></table>';
        root.find('[data-region="csv-table"]').html(table);
        root.find('[data-region="csv-table"]').toggle(!state.csvRaw);
        if (state.csvRaw) {
            root.find('[data-region="csv-raw"]').removeAttr('hidden');
        } else {
            root.find('[data-region="csv-raw"]').attr('hidden', true);
        }
    };

    const openFile = (root, path) => {
        state.activePath = path;
        const f = fileByPath(path);
        renderTabs(root);
        if (!f) {
            return;
        }
        if (f.role === 'brief') {
            renderBriefHtml(root);
            showPane(root, 'brief');
        } else if (f.role === 'data') {
            renderCsv(root);
            showPane(root, 'data');
        } else {
            setCode(root, f.content || '');
            showPane(root, 'code');
        }
    };

    const selectStep = (root, stepId) => {
        state.stepId = stepId;
        renderSteps(root);
        ensureStepSignature(root, stepId);
        const cf = codeFile();
        if (cf && state.activePath !== (dataFile() && dataFile().path)) {
            openFile(root, cf.path);
        } else if (cf) {
            openFile(root, cf.path);
        }
    };

    const ensureStepSignature = (root, stepId) => {
        const step = (state.mission.steps || []).find((s) => Number(s.id) === Number(stepId));
        const cf = codeFile();
        if (!step || !cf || !step.fn || !step.signature) {
            return;
        }
        const code = getCode(root) || cf.content || '';
        const re = new RegExp('^\\s*def\\s+' + step.fn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\(', 'm');
        if (re.test(code)) {
            return;
        }
        let next = code;
        if (!/import\s+pandas/i.test(next)) {
            next = 'import pandas as pd\n' + next;
        }
        if (next && !/\n$/.test(next)) {
            next += '\n';
        }
        next += '\n' + String(step.signature).replace(/\s+$/, '') + '\n';
        cf.content = next;
        if (state.activePath === cf.path) {
            setCode(root, next);
        }
        scheduleSave(root);
    };

    const scheduleSave = (root) => {
        clearTimeout(state.draftTimer);
        state.draftTimer = setTimeout(() => {
            const cf = codeFile();
            if (!cf || !state.mission) {
                return;
            }
            const code = getCode(root);
            cf.content = code;
            Ajax.call([{
                methodname: 'local_nexcodelab_save_workspace',
                args: {missionid: state.mission.id, path: cf.path, content: code}
            }])[0].catch(() => null);
        }, 700);
    };

    const bootAce = (root) => {
        const host = root.find('[data-region="ace"]');
        const ta = root.find('[data-region="editor"]');
        if (!window.ace) {
            ta.removeAttr('hidden');
            host.attr('hidden', true);
            return;
        }
        try {
            if (state.cfg.aceBaseUrl) {
                window.ace.config.set('basePath', state.cfg.aceBaseUrl);
            }
            ta.attr('hidden', true);
            host.removeAttr('hidden').empty();
            const el = document.createElement('div');
            el.className = 'ncl-bench__ace-inner';
            host[0].appendChild(el);
            const ed = window.ace.edit(el);
            ed.setOptions({
                fontSize: 14,
                showPrintMargin: false,
                tabSize: 4,
                useSoftTabs: true,
                wrap: false
            });
            ed.session.setMode('ace/mode/python');
            ed.setTheme(state.theme === 'dark' ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome');
            ed.session.on('change', () => {
                ta.val(ed.getValue());
                scheduleSave(root);
            });
            ed.selection.on('changeCursor', () => {
                try {
                    const pos = ed.getCursorPosition();
                    root.find('[data-region="cursor"]').text(
                        'Ln ' + (pos.row + 1) + ', Col ' + (pos.column + 1)
                    );
                } catch (e) { /* ignore */ }
            });
            state.ace = ed;
            state.aceReady = true;
            applyTheme(root);
            setTimeout(resizeAce, 60);
        } catch (e) {
            state.ace = null;
            state.aceReady = false;
            ta.removeAttr('hidden');
            host.attr('hidden', true);
        }
    };

    const setCheckLabel = (btn, text) => {
        const ico = btn.find('[aria-hidden="true"]').first();
        btn.empty();
        if (ico.length) {
            btn.append(ico);
        } else {
            btn.append($('<span aria-hidden="true">▷</span>'));
        }
        btn.append(document.createTextNode(' ' + text));
    };

    const reload = (root) => {
        Ajax.call([{
            methodname: 'local_nexcodelab_get_mission',
            args: {missionid: state.cfg.missionId}
        }])[0].then((data) => {
            state.mission = data;
            state.originals = {};
            (data.files || []).forEach((f) => {
                state.originals[f.path] = f.seedcontent != null ? f.seedcontent : f.content;
            });
            if (!state.stepId) {
                state.stepId = data.currentstepid;
            }
            root.find('[data-region="title"]').text(data.name || '');
            renderBriefHtml(root);
            renderSteps(root);
            ensureStepSignature(root, state.stepId);
            const prefer = codeFile() ? codeFile().path : (data.files[0] && data.files[0].path);
            openFile(root, state.activePath && fileByPath(state.activePath) ? state.activePath : prefer);
            if (state.list && state.list.length) {
                renderSidebar(root);
            }
            return null;
        }).catch(Notification.exception);
    };

    const checkStep = (root) => {
        if (state.busy || !state.cfg.canAttempt) {
            return;
        }
        const step = currentStep();
        if (!step || step.locked) {
            root.find('[data-region="status"]').text(state.cfg.strings.steplocked || 'Locked');
            return;
        }
        state.busy = true;
        const btn = root.find('[data-action="check"]');
        btn.prop('disabled', true);
        setCheckLabel(btn, state.cfg.strings.checking || 'Checking…');

        const cf = codeFile();
        const code = getCode(root);
        if (cf) {
            cf.content = code;
        }
        // Ensure code pane is active when checking.
        if (cf) {
            openFile(root, cf.path);
        }
        Ajax.call([{
            methodname: 'local_nexcodelab_check_step',
            args: {missionid: state.mission.id, stepid: state.stepId, code: code}
        }])[0].then((res) => {
            state.busy = false;
            btn.prop('disabled', false);
            setCheckLabel(btn, state.cfg.strings.checkstep || 'Check step');
            const ok = !!res.passed;
            root.find('[data-region="outcome"]')
                .toggleClass('is-ok', ok)
                .toggleClass('is-fail', !ok);
            root.find('[data-region="status"]')
                .toggleClass('is-pass', ok)
                .toggleClass('is-fail', !ok)
                .text(ok
                    ? (state.cfg.strings.steppassed || 'Passed')
                    : (res.message || state.cfg.strings.stepfailed || 'Failed'));
            let out = res.output || '';
            if (res.expected) {
                out += '\n\nExpected:\n' + res.expected;
            }
            if (res.actual) {
                out += '\n\nGot:\n' + res.actual;
            }
            root.find('[data-region="output"]').text(out);
            if (ok && res.xpAwarded) {
                toastXp(root, res.xpAwarded);
            }
            if (res.missionCompleted) {
                root.find('[data-region="status"]').append(
                    ' — ' + (state.cfg.strings.missioncomplete || 'Mission complete')
                );
            }
            reload(root);
            return null;
        }).catch((err) => {
            state.busy = false;
            btn.prop('disabled', false);
            setCheckLabel(btn, state.cfg.strings.checkstep || 'Check step');
            Notification.exception(err);
        });
    };

    const toastXp = (root, xp) => {
        const amount = Number(xp) || 0;
        if (amount <= 0 || !root || !root.length) {
            return;
        }
        const template = state.cfg.strings.xpearned || '+{$a} XP earned!';
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
            '<div class="ncl-np__toast-title">' +
                esc(state.cfg.strings.steppassed || state.cfg.strings.accepted || 'Accepted') +
            '</div>' +
            '<div class="ncl-np__toast-msg">' + esc(msg) + '</div>' +
            '</div>' +
            '<button type="button" class="ncl-np__toast-close" aria-label="Close">×</button>' +
            '</div>'
        );
        host.append(el);
        window.requestAnimationFrame(() => el.addClass('is-visible'));

        const remove = () => {
            el.removeClass('is-visible').addClass('is-leaving');
            window.setTimeout(() => el.remove(), 220);
        };
        el.find('.ncl-np__toast-close').on('click', remove);
        window.setTimeout(remove, 4200);
    };

    const setSidebarOpen = (root, open) => {
        root.toggleClass('ncl-bench--sidebar-open', open);
        root.toggleClass('ncl-bench--sidebar-closed', !open);
        root.find('[data-action="toggle-sidebar"]').attr('aria-expanded', open ? 'true' : 'false');
        try {
            window.localStorage.setItem(SIDEBAR_KEY, open ? '1' : '0');
        } catch (e) { /* ignore */ }
        setTimeout(resizeAce, 60);
    };

    const renderSidebar = (root) => {
        let items = state.list.slice();
        const q = (state.listFilter.search || '').toLowerCase().trim();
        const track = state.listFilter.track || '';
        if (q) {
            items = items.filter((m) => {
                const hay = ((m.name || '') + ' ' + (m.scenario || '') + ' ' + (m.track || '')).toLowerCase();
                return hay.indexOf(q) !== -1;
            });
        }
        if (track) {
            items = items.filter((m) => m.track === track);
        }
        root.find('[data-region="sidebar-count"]').text(
            items.length + ' ' + (state.cfg.strings.missions || 'missions')
        );
        const currentId = state.mission
            ? state.mission.id
            : (state.cfg.missionId || root.attr('data-missionid'));
        const html = items.map((m) => {
            const active = Number(m.id) === Number(currentId);
            const status = m.userstatus || 'notstarted';
            return '<a class="ncl-bench__mlist-item' +
                (active ? ' is-active' : '') +
                (status === 'completed' ? ' is-completed' : '') +
                '" href="' + esc(m.url) + '" data-missionid="' + esc(m.id) + '">' +
                '<span class="ncl-bench__mlist-num">' + esc(m.number != null ? m.number : m.id) + '</span>' +
                '<span class="ncl-bench__mlist-name">' + esc(m.name) + '</span>' +
                '<span class="ncl-bench__mlist-track ncl-bench__mlist-track--' + esc(m.track || '') + '">' +
                esc(trackLabel(m.track)) + '</span></a>';
        }).join('');
        root.find('[data-region="sidebar-list"]').html(
            html || '<p class="ncl-bench__msidebar-empty">' +
                esc(state.cfg.strings.nomatch || 'No missions match.') + '</p>'
        );
    };

    const loadSidebar = (root) => {
        Ajax.call([{
            methodname: 'local_nexcodelab_get_missions',
            args: {
                search: '',
                track: '',
                userstatus: 'all',
                page: 0,
                perpage: 200
            }
        }])[0].then((data) => {
            state.list = data.missions || [];
            renderSidebar(root);
            return null;
        }).catch(() => null);
    };

    const init = function(cfg) {
        state.cfg = cfg || {};
        const root = $('[data-region="ncl-bench"]');
        if (!root.length) {
            return;
        }
        document.body.classList.add('has-ncl-ide');

        // Always follow RemUI (site) theme so light/dark stay in sync.
        state.theme = detectRemuiDark() ? 'dark' : 'light';
        applyTheme(root);

        // Follow RemUI theme changes from elsewhere.
        try {
            window.addEventListener('storage', function(ev) {
                if (ev.key === 'remui_darkmode' || ev.key === 'darkMode') {
                    state.theme = detectRemuiDark() ? 'dark' : 'light';
                    applyTheme(root);
                }
            });
        } catch (e) { /* ignore */ }

        bindResizers(root);
        bootAce(root);
        setTimeout(resizeAce, 80);

        let sidebarOpen = false;
        try {
            sidebarOpen = window.localStorage.getItem(SIDEBAR_KEY) === '1';
        } catch (e) { /* ignore */ }
        setSidebarOpen(root, sidebarOpen);
        loadSidebar(root);

        root.on('click', '[data-action="toggle-sidebar"]', function(e) {
            e.preventDefault();
            setSidebarOpen(root, !root.hasClass('ncl-bench--sidebar-open'));
        });
        root.on('input', '[data-region="sidebar-search"]', function() {
            state.listFilter.search = $(this).val() || '';
            renderSidebar(root);
        });
        root.on('click', '[data-region="sidebar-tracks"] [data-track]', function(e) {
            e.preventDefault();
            root.find('[data-region="sidebar-tracks"] [data-track]').removeClass('is-active');
            $(this).addClass('is-active');
            state.listFilter.track = $(this).attr('data-track') || '';
            renderSidebar(root);
        });

        root.on('click', '[data-action="toggle-theme"]', function(e) {
            e.preventDefault();
            state.theme = state.theme === 'dark' ? 'light' : 'dark';
            applyTheme(root);
        });

        root.on('click', '[data-path]', function(e) {
            e.preventDefault();
            const cf = codeFile();
            if (cf && state.activePath === cf.path) {
                cf.content = getCode(root);
            }
            openFile(root, $(this).attr('data-path'));
        });
        root.on('click', '[data-stepid]', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled')) {
                return;
            }
            selectStep(root, parseInt($(this).attr('data-stepid'), 10));
        });
        root.on('click', '[data-action="prev-step"]', function(e) {
            e.preventDefault();
            const steps = state.mission.steps || [];
            const idx = steps.findIndex((s) => Number(s.id) === Number(state.stepId));
            if (idx > 0 && !steps[idx - 1].locked) {
                selectStep(root, steps[idx - 1].id);
            }
        });
        root.on('click', '[data-action="next-step"]', function(e) {
            e.preventDefault();
            const steps = state.mission.steps || [];
            const idx = steps.findIndex((s) => Number(s.id) === Number(state.stepId));
            if (idx >= 0 && idx < steps.length - 1 && !steps[idx + 1].locked) {
                selectStep(root, steps[idx + 1].id);
            }
        });
        root.on('click', '[data-action="check"]', function(e) {
            e.preventDefault();
            checkStep(root);
        });
        root.on('click', '[data-action="hint"]', function(e) {
            e.preventDefault();
            root.find('[data-region="hint"]').removeAttr('hidden');
        });
        root.on('click', '[data-action="toggle-csv"]', function(e) {
            e.preventDefault();
            state.csvRaw = !state.csvRaw;
            $(this).text(state.csvRaw
                ? (state.cfg.strings.tablecsv || 'Table')
                : (state.cfg.strings.rawcsv || 'Raw'));
            renderCsv(root);
        });
        root.on('click', '[data-action="reset"]', function(e) {
            e.preventDefault();
            const cf = codeFile();
            if (!cf) {
                return;
            }
            const original = state.originals[cf.path] || '';
            cf.content = original;
            openFile(root, cf.path);
            setCode(root, original);
            scheduleSave(root);
        });
        root.on('input', '[data-region="editor"]', () => scheduleSave(root));
        reload(root);
    };

    return {init};
});
