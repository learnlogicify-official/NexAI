define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    const call = (method, args) => Ajax.call([{methodname: method, args: args}])[0];

    const loadScript = (src) => new Promise(function(resolve, reject) {
        if (document.querySelector('script[src="' + src + '"]')) {
            resolve();
            return;
        }
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = function() { resolve(); };
        s.onerror = function() { reject(new Error('Failed to load ' + src)); };
        document.head.appendChild(s);
    });

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const modeFor = (path) => {
        if (/\.jsx?$/i.test(path)) {
            return 'javascript';
        }
        if (/\.tsx?$/i.test(path)) {
            return 'typescript';
        }
        if (/\.css$/i.test(path)) {
            return 'css';
        }
        if (/\.html?$/i.test(path)) {
            return 'html';
        }
        if (/\.json$/i.test(path)) {
            return 'json';
        }
        if (/\.md$/i.test(path)) {
            return 'markdown';
        }
        return 'plaintext';
    };

    const extClass = (name) => {
        const m = /\.([a-z0-9]+)$/i.exec(name || '');
        const ext = ((m && m[1]) || '').toLowerCase();
        if (ext === 'js' || ext === 'jsx') {
            return 'nxs-ide__ficon--js';
        }
        if (ext === 'ts' || ext === 'tsx') {
            return 'nxs-ide__ficon--ts';
        }
        if (ext === 'css') {
            return 'nxs-ide__ficon--css';
        }
        if (ext === 'html' || ext === 'htm') {
            return 'nxs-ide__ficon--html';
        }
        if (ext === 'json') {
            return 'nxs-ide__ficon--json';
        }
        if (ext === 'md') {
            return 'nxs-ide__ficon--md';
        }
        return '';
    };

    const buildTree = (paths) => {
        const rootNode = {dirs: {}, files: {}};
        paths.forEach(function(p) {
            const parts = String(p).split('/').filter(Boolean);
            let cur = rootNode;
            parts.forEach(function(part, i) {
                if (i === parts.length - 1) {
                    cur.files[part] = p;
                } else {
                    if (!cur.dirs[part]) {
                        cur.dirs[part] = {dirs: {}, files: {}};
                    }
                    cur = cur.dirs[part];
                }
            });
        });
        return rootNode;
    };

    const mdToHtml = (src) => {
        let text = String(src == null ? '' : src).replace(/\r\n/g, '\n');
        const blocks = [];
        text = text.replace(/```([\w-]*)\n([\s\S]*?)```/g, function(_, lang, code) {
            const i = blocks.length;
            blocks.push('<pre class="nxs-md__pre"><code class="nxs-md__code">' + esc(code.replace(/\n$/, '')) + '</code></pre>');
            return '\n%%BLK' + i + '%%\n';
        });
        const lines = text.split('\n');
        const out = [];
        let listType = '';
        const closeList = () => {
            if (listType) {
                out.push(listType === 'ol' ? '</ol>' : '</ul>');
                listType = '';
            }
        };
        const inline = (s) => esc(s)
            .replace(/`([^`]+)`/g, '<code class="nxs-md__inline">$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>');

        lines.forEach(function(line) {
            const blk = line.match(/^%%BLK(\d+)%%$/);
            if (blk) {
                closeList();
                out.push(blocks[Number(blk[1])] || '');
                return;
            }
            if (/^\s*---+\s*$/.test(line)) {
                closeList();
                out.push('<hr class="nxs-md__hr" />');
                return;
            }
            const h = /^(#{1,3})\s+(.+)$/.exec(line);
            if (h) {
                closeList();
                const lvl = h[1].length;
                out.push('<h' + lvl + ' class="nxs-md__h' + lvl + '">' + inline(h[2]) + '</h' + lvl + '>');
                return;
            }
            const ul = /^\s*[-*]\s+(.+)$/.exec(line);
            if (ul) {
                if (listType !== 'ul') {
                    closeList();
                    out.push('<ul class="nxs-md__list">');
                    listType = 'ul';
                }
                out.push('<li>' + inline(ul[1]) + '</li>');
                return;
            }
            const ol = /^\s*\d+\.\s+(.+)$/.exec(line);
            if (ol) {
                if (listType !== 'ol') {
                    closeList();
                    out.push('<ol class="nxs-md__list">');
                    listType = 'ol';
                }
                out.push('<li>' + inline(ol[1]) + '</li>');
                return;
            }
            if (!String(line).trim()) {
                closeList();
                return;
            }
            closeList();
            out.push('<p class="nxs-md__p">' + inline(line) + '</p>');
        });
        closeList();
        return out.join('\n');
    };

    const init = (cfg) => {
        const root = document.querySelector('[data-region="nxs-studio"]');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        document.body.classList.add('has-nxs-ide');

        const state = {
            files: {},
            steps: [],
            activePath: '',
            activestep: 0,
            completed: {},
            runtime: cfg.runtime || 'static',
            editor: null,
            editorApi: null,
            saveTimer: null,
            wc: null,
            wcReady: false,
            previewUrl: '',
            bootPromise: null,
            theme: 'dark',
            _aceReady: false,
            openFolders: {},
            openTabs: [],
            docView: true,
            sidebarView: 'files',
            sidebarOpen: true,
            sidebarWidth: 280,
        };

        const el = {
            title: root.querySelector('[data-region="mission-title"]'),
            files: root.querySelector('[data-region="file-list"]'),
            fileCount: root.querySelector('[data-region="file-count"]'),
            steps: root.querySelector('[data-region="step-list"]'),
            stepsSub: root.querySelector('[data-region="steps-sub"]'),
            stepChip: root.querySelector('[data-region="step-chip"]'),
            stepsBadge: root.querySelector('[data-region="steps-badge"]'),
            stepsProgressBar: root.querySelector('[data-region="steps-progress-bar"]'),
            stepTitle: null,
            stepInstructions: null,
            brief: root.querySelector('[data-region="brief"]'),
            tabs: root.querySelector('[data-region="tabs"]'),
            breadcrumb: root.querySelector('[data-region="breadcrumb"]'),
            editor: root.querySelector('[data-region="editor"]'),
            stepDoc: root.querySelector('[data-region="step-doc"]'),
            preview: root.querySelector('[data-region="preview"]'),
            previewUrlEl: root.querySelector('[data-region="preview-url"]'),
            termOut: root.querySelector('[data-region="terminal-output"]'),
            termInput: root.querySelector('[data-region="term-input"]'),
            termPrompt: root.querySelector('[data-region="term-prompt"]'),
            termTabs: root.querySelector('[data-region="term-tabs"]'),
            termForm: root.querySelector('[data-region="term-form"]'),
            termView: root.querySelector('[data-region="panel-terminal"]'),
            problems: root.querySelector('[data-region="problems"]'),
            status: root.querySelector('[data-region="status"]'),
            themeLabel: root.querySelector('[data-region="theme-label"]'),
            sidebar: root.querySelector('[data-region="sidebar"]'),
            previewPane: root.querySelector('[data-region="preview-pane"]'),
            termPane: root.querySelector('[data-region="term-pane"]'),
            workspace: root.querySelector('[data-region="workspace"]'),
            center: root.querySelector('[data-region="center"]'),
            rail: root.querySelector('.nxs-ide__rail'),
        };

        // —— VS Code–like terminal sessions ——
        let termSeq = 0;
        const makeTerm = () => {
            termSeq += 1;
            return {
                id: 't' + termSeq,
                name: 'bash',
                cwd: '/',
                lines: [],
                history: [],
                histIdx: -1,
                busy: false,
            };
        };
        state.terminals = [makeTerm()];
        state.activeTermId = state.terminals[0].id;
        state.termCollapsed = false;
        state.termHeight = 220;

        const activeTerm = () => {
            return state.terminals.find(function(t) { return t.id === state.activeTermId; }) || state.terminals[0];
        };

        const promptFor = (term) => {
            const cwd = !term.cwd || term.cwd === '/' ? '~' : term.cwd.replace(/^\//, '');
            return 'nexstack:' + cwd + '$';
        };

        const renderPrompt = () => {
            const term = activeTerm();
            if (el.termPrompt && term) {
                el.termPrompt.textContent = promptFor(term);
            }
        };

        const paintTerm = () => {
            const term = activeTerm();
            if (!el.termOut || !term) {
                return;
            }
            el.termOut.innerHTML = term.lines.map(function(line) {
                const cls = line.cls ? ' nxs-ide__term-line--' + line.cls : '';
                return '<div class="nxs-ide__term-line' + cls + '">' + esc(line.text) + '</div>';
            }).join('');
            el.termOut.scrollTop = el.termOut.scrollHeight;
            renderPrompt();
        };

        const renderTermTabs = () => {
            if (!el.termTabs) {
                return;
            }
            el.termTabs.innerHTML = state.terminals.map(function(t, i) {
                const active = t.id === state.activeTermId ? ' is-active' : '';
                return '<button type="button" class="nxs-ide__term-tab' + active +
                    '" data-term="' + t.id + '" role="tab">' +
                    esc(t.name) + (state.terminals.length > 1 ? ' · ' + (i + 1) : '') +
                    (state.terminals.length > 1
                        ? ' <span class="nxs-ide__term-tab-x" data-term-close="' + t.id + '" title="Kill">×</span>'
                        : '') +
                    '</button>';
            }).join('');
        };

        const termWrite = (text, cls, termId) => {
            const term = state.terminals.find(function(t) {
                return t.id === (termId || state.activeTermId);
            }) || activeTerm();
            if (!term) {
                return;
            }
            String(text == null ? '' : text).split(/\r?\n/).forEach(function(chunk, idx, arr) {
                if (chunk === '' && idx === arr.length - 1) {
                    return;
                }
                term.lines.push({text: chunk, cls: cls || ''});
            });
            if (term.lines.length > 2000) {
                term.lines = term.lines.slice(-1500);
            }
            if (term.id === state.activeTermId) {
                paintTerm();
            }
        };

        const termLog = (line) => {
            termWrite(line, '');
        };

        const clearActiveTerm = () => {
            const term = activeTerm();
            if (!term) {
                return;
            }
            term.lines = [];
            paintTerm();
            termWrite('Terminal cleared', 'muted');
        };

        const killActiveTerm = () => {
            if (state.terminals.length <= 1) {
                clearActiveTerm();
                termWrite('Ready.', 'muted');
                return;
            }
            const id = state.activeTermId;
            state.terminals = state.terminals.filter(function(t) { return t.id !== id; });
            state.activeTermId = state.terminals[state.terminals.length - 1].id;
            renderTermTabs();
            paintTerm();
        };

        const newTerminal = () => {
            const t = makeTerm();
            state.terminals.push(t);
            state.activeTermId = t.id;
            renderTermTabs();
            paintTerm();
            termWrite('NexStack terminal — type `help` for commands.', 'muted');
            if (el.termInput) {
                el.termInput.focus();
            }
        };

        const listAtCwd = (cwd) => {
            const prefix = cwd === '/' ? '' : cwd.replace(/^\//, '') + '/';
            const dirs = {};
            const files = [];
            Object.keys(state.files).forEach(function(p) {
                if (prefix && p.indexOf(prefix) !== 0) {
                    return;
                }
                if (!prefix && p.indexOf('/') === -1) {
                    files.push(p);
                    return;
                }
                const rest = prefix ? p.slice(prefix.length) : p;
                if (!rest) {
                    return;
                }
                if (rest.indexOf('/') === -1) {
                    files.push(rest);
                } else {
                    dirs[rest.split('/')[0]] = true;
                }
            });
            return {dirs: Object.keys(dirs).sort(), files: files.sort()};
        };

        const resolvePath = (cwd, target) => {
            if (!target || target === '.') {
                return cwd || '/';
            }
            if (target === '~' || target === '/') {
                return '/';
            }
            let parts = (cwd === '/' ? [] : cwd.replace(/^\//, '').split('/'));
            if (target.charAt(0) === '/') {
                parts = [];
                target = target.replace(/^\//, '');
            }
            String(target).split('/').forEach(function(seg) {
                if (!seg || seg === '.') {
                    return;
                }
                if (seg === '..') {
                    parts.pop();
                } else {
                    parts.push(seg);
                }
            });
            return parts.length ? '/' + parts.join('/') : '/';
        };

        const pathExists = (abs) => {
            if (abs === '/') {
                return true;
            }
            const clean = abs.replace(/^\//, '');
            if (Object.prototype.hasOwnProperty.call(state.files, clean)) {
                return 'file';
            }
            const prefix = clean + '/';
            const asDir = Object.keys(state.files).some(function(p) {
                return p.indexOf(prefix) === 0;
            });
            return asDir ? 'dir' : false;
        };

        const runLocalCommand = (term, raw) => {
            const line = String(raw || '').trim();
            if (!line) {
                return Promise.resolve();
            }
            const parts = line.match(/(?:[^\s"']+|"[^"]*"|'[^']*')+/g) || [];
            const args = parts.map(function(p) {
                return p.replace(/^["']|["']$/g, '');
            });
            const cmd = args[0];
            const rest = args.slice(1);

            if (cmd === 'help') {
                termWrite([
                    'Built-in commands:',
                    '  help                 Show this help',
                    '  clear                Clear the terminal',
                    '  pwd                  Print working directory',
                    '  ls [path]            List project files',
                    '  tree                 Show folder tree',
                    '  cd <path>            Change directory',
                    '  cat <file>           Print a file',
                    '  echo <text>          Print text',
                    '  open <file>          Open file in editor',
                    '  preview              Refresh live preview',
                    '  boot                 Boot WebContainer (Node missions)',
                    '  npm / node / npx     Available after WebContainer boot',
                    '',
                    'Tip: ↑ / ↓ for command history · Enter to run',
                ].join('\n'), 'muted');
                return Promise.resolve();
            }
            if (cmd === 'clear') {
                term.lines = [];
                paintTerm();
                return Promise.resolve();
            }
            if (cmd === 'pwd') {
                termWrite(term.cwd === '/' ? '/' : term.cwd);
                return Promise.resolve();
            }
            if (cmd === 'echo') {
                termWrite(rest.join(' '));
                return Promise.resolve();
            }
            if (cmd === 'ls') {
                const target = resolvePath(term.cwd, rest[0] || '.');
                const kind = pathExists(target);
                if (!kind) {
                    termWrite('ls: ' + (rest[0] || target) + ': No such file or directory', 'err');
                    return Promise.resolve();
                }
                if (kind === 'file') {
                    termWrite(target.replace(/^\//, '').split('/').pop());
                    return Promise.resolve();
                }
                const listing = listAtCwd(target);
                const out = listing.dirs.map(function(d) { return d + '/'; })
                    .concat(listing.files);
                termWrite(out.length ? out.join('  ') : '');
                return Promise.resolve();
            }
            if (cmd === 'tree') {
                const paths = Object.keys(state.files).sort();
                if (!paths.length) {
                    termWrite('(empty project)', 'muted');
                    return Promise.resolve();
                }
                termWrite('.\n' + paths.map(function(p) { return '├── ' + p; }).join('\n'));
                return Promise.resolve();
            }
            if (cmd === 'cd') {
                const target = resolvePath(term.cwd, rest[0] || '/');
                const kind = pathExists(target);
                if (target !== '/' && kind !== 'dir') {
                    termWrite('cd: not a directory: ' + (rest[0] || target), 'err');
                    return Promise.resolve();
                }
                term.cwd = target;
                renderPrompt();
                return Promise.resolve();
            }
            if (cmd === 'cat') {
                if (!rest[0]) {
                    termWrite('cat: missing file operand', 'err');
                    return Promise.resolve();
                }
                const target = resolvePath(term.cwd, rest[0]);
                const rel = target.replace(/^\//, '');
                if (!Object.prototype.hasOwnProperty.call(state.files, rel)) {
                    termWrite('cat: ' + rest[0] + ': No such file', 'err');
                    return Promise.resolve();
                }
                termWrite(state.files[rel]);
                return Promise.resolve();
            }
            if (cmd === 'open') {
                if (!rest[0]) {
                    termWrite('open: missing file', 'err');
                    return Promise.resolve();
                }
                const target = resolvePath(term.cwd, rest[0]).replace(/^\//, '');
                if (!Object.prototype.hasOwnProperty.call(state.files, target)) {
                    termWrite('open: ' + rest[0] + ': No such file', 'err');
                    return Promise.resolve();
                }
                openFile(target);
                termWrite('Opened ' + target, 'ok');
                return Promise.resolve();
            }
            if (cmd === 'preview') {
                refreshStaticPreview();
                termWrite('Preview refreshed', 'ok');
                return Promise.resolve();
            }
            if (cmd === 'boot') {
                return bootRuntime();
            }

            // WebContainer passthrough when available (any other shell command).
            if (state.wc && state.wcReady) {
                return runWcCommand(term, args);
            }

            if (cmd === 'npm' || cmd === 'npx' || cmd === 'node' || cmd === 'yarn' || cmd === 'pnpm') {
                termWrite(cmd + ': WebContainer not running. Use `boot` on a Node/Vite mission first.', 'err');
                return Promise.resolve();
            }

            termWrite(cmd + ': command not found. Type `help`.', 'err');
            return Promise.resolve();
        };

        const runWcCommand = (term, args) => {
            if (!state.wc) {
                termWrite('WebContainer not ready', 'err');
                return Promise.resolve();
            }
            term.busy = true;
            setStatus('Running…');
            return state.wc.spawn(args[0], args.slice(1), {
                cwd: term.cwd === '/' ? '/' : term.cwd,
            }).then(function(proc) {
                proc.output.pipeTo(new WritableStream({
                    write: function(chunk) {
                        termWrite(String(chunk).replace(/\n$/, ''), '', term.id);
                    }
                })).catch(function() {});
                return proc.exit;
            }).then(function(code) {
                term.busy = false;
                setStatus(code === 0 ? 'Ready' : 'Exit ' + code);
                if (code !== 0) {
                    termWrite('Process exited with code ' + code, 'err');
                }
            }).catch(function(err) {
                term.busy = false;
                setStatus('Ready');
                termWrite(String(err && err.message ? err.message : err), 'err');
            });
        };

        const submitCommand = (raw) => {
            const term = activeTerm();
            if (!term || term.busy) {
                return;
            }
            const line = String(raw || '');
            termWrite(promptFor(term) + ' ' + line, 'cmd');
            if (line.trim()) {
                term.history.push(line);
                if (term.history.length > 100) {
                    term.history.shift();
                }
            }
            term.histIdx = term.history.length;
            if (el.termInput) {
                el.termInput.value = '';
            }
            return runLocalCommand(term, line).then(function() {
                renderPrompt();
            });
        };

        const setStatus = (msg) => {
            if (el.status) {
                el.status.textContent = msg;
            }
        };

        const problemsLog = (line) => {
            if (!el.problems) {
                return;
            }
            el.problems.textContent += String(line) + '\n';
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

        // Follow Edwiser RemUI light/dark on load.
        state.theme = detectRemuiDark() ? 'dark' : 'light';

        const applyTheme = () => {
            const dark = state.theme === 'dark';
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
            document.body.classList.toggle('nxs-ide-dark', dark);
            document.body.classList.toggle('nxs-ide-light', !dark);
            syncRemuiDark(dark);
            if (el.themeLabel) {
                el.themeLabel.textContent = dark ? 'Dark' : 'Light';
            }
            if (state.editorApi && state.editorApi._ace) {
                state.editorApi._ace.setTheme(
                    dark ? 'ace/theme/tomorrow_night' : 'ace/theme/textmate'
                );
            }
        };

        const persistSidebar = () => {
            try {
                window.localStorage.setItem('nexstack.studio.sidebar', JSON.stringify({
                    open: state.sidebarOpen,
                    view: state.sidebarView,
                    width: state.sidebarWidth,
                }));
            } catch (e) { /* ignore */ }
        };

        const restoreSidebar = () => {
            try {
                const raw = window.localStorage.getItem('nexstack.studio.sidebar');
                if (!raw) {
                    return;
                }
                const saved = JSON.parse(raw);
                if (typeof saved.open === 'boolean') {
                    state.sidebarOpen = saved.open;
                }
                if (saved.view === 'files' || saved.view === 'steps' || saved.view === 'brief') {
                    state.sidebarView = saved.view;
                }
                if (typeof saved.width === 'number' && saved.width >= 160 && saved.width <= 480) {
                    state.sidebarWidth = saved.width;
                }
            } catch (e) { /* ignore */ }
        };

        /**
         * VS Code activity-bar behavior:
         * - click active view while open  → collapse sidebar
         * - click again / another view   → open (and switch view)
         */
        const applySidebarChrome = () => {
            root.classList.toggle('nxs-ide--sidebar-collapsed', !state.sidebarOpen);
            if (el.sidebar) {
                if (state.sidebarOpen) {
                    el.sidebar.style.width = state.sidebarWidth + 'px';
                    el.sidebar.removeAttribute('aria-hidden');
                } else {
                    el.sidebar.setAttribute('aria-hidden', 'true');
                }
            }
            const grip = root.querySelector('[data-resize="sidebar"]');
            if (grip) {
                grip.hidden = !state.sidebarOpen;
            }
            root.querySelectorAll('.nxs-ide__railbtn').forEach(function(btn) {
                const name = btn.getAttribute('data-sidebar');
                const active = state.sidebarOpen && name === state.sidebarView;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            ['files', 'steps', 'brief'].forEach(function(key) {
                const pane = root.querySelector('[data-region="panel-' + key + '"]');
                if (!pane) {
                    return;
                }
                const on = state.sidebarOpen && key === state.sidebarView;
                pane.classList.toggle('is-active', on);
                if (on) {
                    pane.removeAttribute('hidden');
                } else {
                    pane.setAttribute('hidden', 'hidden');
                }
            });
            persistSidebar();
            if (state.editor && state.editor.resize) {
                setTimeout(function() { state.editor.resize(); }, 40);
            }
        };

        const showSidebar = (name, forceOpen) => {
            if (!name) {
                return;
            }
            if (forceOpen) {
                state.sidebarView = name;
                state.sidebarOpen = true;
            } else if (state.sidebarOpen && state.sidebarView === name) {
                // Same icon again → close (VS Code toggle).
                state.sidebarOpen = false;
            } else {
                state.sidebarView = name;
                state.sidebarOpen = true;
            }
            applySidebarChrome();
        };

        const setSidebarOpen = (open) => {
            state.sidebarOpen = !!open;
            applySidebarChrome();
        };

        const expandTermPane = () => {
            if (!el.termPane || !state.termCollapsed) {
                return;
            }
            state.termCollapsed = false;
            el.termPane.classList.remove('is-collapsed');
            el.termPane.style.height = (state.termHeight || 220) + 'px';
        };

        const toggleTermPane = () => {
            if (!el.termPane) {
                return;
            }
            if (state.termCollapsed) {
                expandTermPane();
                showBottom('terminal');
                if (el.termInput) {
                    el.termInput.focus();
                }
                return;
            }
            const h = parseInt(el.termPane.style.height, 10);
            if (!isNaN(h) && h > 40) {
                state.termHeight = h;
            }
            state.termCollapsed = true;
            el.termPane.classList.add('is-collapsed');
            el.termPane.style.height = '36px';
        };

        const showBottom = (name) => {
            expandTermPane();
            root.querySelectorAll('.nxs-ide__paneltab').forEach(function(btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-bottom') === name);
            });
            if (el.termView) {
                el.termView.classList.toggle('is-active', name === 'terminal');
                if (name === 'terminal') {
                    el.termView.removeAttribute('hidden');
                } else {
                    el.termView.setAttribute('hidden', 'hidden');
                }
            }
            if (el.problems) {
                el.problems.classList.toggle('is-active', name === 'problems');
                if (name === 'problems') {
                    el.problems.removeAttribute('hidden');
                } else {
                    el.problems.setAttribute('hidden', 'hidden');
                }
            }
            if (name === 'terminal' && el.termInput) {
                window.setTimeout(function() {
                    el.termInput.focus();
                }, 0);
            }
        };

        const flushEditor = () => {
            if (state.editor && state.activePath && !state.docView) {
                state.files[state.activePath] = state.editor.getValue();
            }
        };

        const filesForPersist = () => {
            const out = {};
            Object.keys(state.files).forEach(function(path) {
                out[path] = state.files[path];
            });
            return out;
        };

        const buildStepMarkdown = () => {
            const total = state.steps.length;
            const idx = state.activestep || 0;
            const step = state.steps[idx];
            const mission = (el.title && el.title.textContent) ? el.title.textContent.trim() : 'Mission';
            if (!step) {
                return '# ' + mission + '\n\n_No steps are defined for this mission yet._\n';
            }
            const done = !!state.completed[idx];
            const title = String(step.title || ('Step ' + (idx + 1))).trim();
            const body = String(step.instructions || step.summary || step.brief || '').trim();
            const status = done ? 'Done' : 'In progress';
            let md = '# Step ' + (idx + 1) + ' of ' + total + '\n\n';
            md += '## ' + title + '\n\n';
            if (body) {
                md += body + '\n\n';
            } else {
                md += '_No description for this step._\n\n';
            }
            md += '---\n\n';
            md += '- **Mission:** ' + mission + '\n';
            md += '- **Status:** ' + status + '\n';
            return md;
        };

        const setCenterView = (mode) => {
            state.docView = mode === 'doc';
            if (el.stepDoc) {
                el.stepDoc.classList.toggle('is-active', state.docView);
                if (state.docView) {
                    el.stepDoc.removeAttribute('hidden');
                } else {
                    el.stepDoc.setAttribute('hidden', 'hidden');
                }
            }
            if (el.editor) {
                if (state.docView) {
                    el.editor.setAttribute('hidden', 'hidden');
                } else {
                    el.editor.removeAttribute('hidden');
                }
            }
            if (!state.docView && state.editor && state.editor.resize) {
                setTimeout(function() { state.editor.resize(); }, 40);
            }
            if (state.editorApi && state.editorApi._ace) {
                try {
                    state.editorApi._ace.setReadOnly(false);
                } catch (e) { /* ignore */ }
            }
            renderTabs();
            renderBreadcrumb();
        };

        const syncStepDoc = (opts) => {
            opts = opts || {};
            const md = buildStepMarkdown();
            if (el.stepDoc) {
                el.stepDoc.innerHTML = '<div class="nxs-md">' + mdToHtml(md) + '</div>';
            }
            if (opts.open) {
                setCenterView('doc');
            } else {
                renderTabs();
                if (state.docView) {
                    renderBreadcrumb();
                }
            }
        };

        const scheduleSave = () => {
            clearTimeout(state.saveTimer);
            state.saveTimer = setTimeout(function() {
                flushEditor();
                call('local_nexstack_save_workspace', {
                    missionid: cfg.missionid,
                    filesjson: JSON.stringify(filesForPersist())
                }).catch(function() { /* ignore */ });
            }, 900);
        };

        const renderTreeNode = (node, depth, prefix) => {
            let html = '';
            Object.keys(node.dirs).sort().forEach(function(name) {
                const folderKey = (prefix ? prefix + '/' : '') + name;
                const isOpen = state.openFolders[folderKey] !== false; // default open
                if (state.openFolders[folderKey] === undefined) {
                    state.openFolders[folderKey] = true;
                }
                html += '<div class="nxs-ide__tree-group' + (isOpen ? ' is-open' : '') + '" data-folder-key="' + esc(folderKey) + '">' +
                    '<button type="button" class="nxs-ide__tree-folder" data-folder="' + esc(folderKey) + '" style="--depth:' + depth + '">' +
                    '<span class="nxs-ide__chev"></span>' +
                    '<span class="nxs-ide__ficon nxs-ide__ficon--folder"></span>' +
                    '<span class="nxs-ide__fname">' + esc(name) + '</span></button>' +
                    '<div class="nxs-ide__tree-children">' +
                    renderTreeNode(node.dirs[name], depth + 1, folderKey) +
                    '</div></div>';
            });
            Object.keys(node.files).sort().forEach(function(name) {
                const full = node.files[name];
                const active = full === state.activePath ? ' is-active' : '';
                html += '<button type="button" class="nxs-ide__tree-file' + active +
                    '" data-path="' + esc(full) + '" style="--depth:' + depth + '">' +
                    '<span class="nxs-ide__chev" style="visibility:hidden"></span>' +
                    '<span class="nxs-ide__ficon ' + extClass(name) + '"></span>' +
                    '<span class="nxs-ide__fname">' + esc(name) + '</span></button>';
            });
            return html;
        };

        const renderFiles = () => {
            if (!el.files) {
                return;
            }
            const paths = Object.keys(state.files).sort();
            if (el.fileCount) {
                el.fileCount.textContent = paths.length + ' files';
            }
            if (!paths.length) {
                el.files.innerHTML = '<div class="nxs-ide__empty">No files in this project</div>';
                return;
            }
            el.files.innerHTML = renderTreeNode(buildTree(paths), 0, '');
        };

        const renderTabs = () => {
            if (!el.tabs) {
                return;
            }
            state.openTabs = state.openTabs.filter(function(p) {
                return Object.prototype.hasOwnProperty.call(state.files, p);
            });
            const stepTab = '<div class="nxs-ide__tab nxs-ide__tab--step' +
                (state.docView ? ' is-active' : '') +
                '" role="tab" data-action="open-step-doc" title="Current step description" tabindex="0">' +
                '<span class="nxs-ide__ficon nxs-ide__ficon--md"></span>' +
                '<span class="nxs-ide__tab-name">Step brief</span>' +
                '</div>';
            const fileTabs = state.openTabs.map(function(p) {
                const active = (!state.docView && p === state.activePath) ? ' is-active' : '';
                const base = p.split('/').pop();
                return '<div class="nxs-ide__tab' + active + '" role="tab" data-path="' + esc(p) +
                    '" title="' + esc(p) + '" tabindex="0">' +
                    '<span class="nxs-ide__ficon ' + extClass(base) + '"></span>' +
                    '<span class="nxs-ide__tab-name">' + esc(base) + '</span>' +
                    '<button type="button" class="nxs-ide__tab-close" data-close-tab="' + esc(p) +
                    '" title="Close" aria-label="Close ' + esc(base) + '">×</button>' +
                    '</div>';
            }).join('');
            el.tabs.innerHTML = stepTab + fileTabs;
        };

        const closeTab = (path) => {
            const idx = state.openTabs.indexOf(path);
            if (idx === -1) {
                return;
            }
            flushEditor();
            state.openTabs.splice(idx, 1);
            if (state.activePath === path && !state.docView) {
                const next = state.openTabs[idx] || state.openTabs[idx - 1] || state.openTabs[0] || '';
                if (next) {
                    openFile(next);
                    return;
                }
                state.activePath = '';
                setCenterView('doc');
                return;
            }
            renderTabs();
        };

        const renderBreadcrumb = () => {
            if (!el.breadcrumb) {
                return;
            }
            if (state.docView) {
                const idx = (state.activestep || 0) + 1;
                const total = state.steps.length || 1;
                const step = state.steps[state.activestep] || {};
                el.breadcrumb.innerHTML =
                    '<span class="nxs-ide__crumb">Step brief</span>' +
                    '<span class="nxs-ide__crumb-sep">›</span>' +
                    '<span class="nxs-ide__crumb is-file">' +
                    esc('Step ' + idx + ' / ' + total + (step.title ? ' — ' + step.title : '')) +
                    '</span>';
                return;
            }
            if (!state.activePath) {
                el.breadcrumb.innerHTML = '';
                return;
            }
            const parts = state.activePath.split('/');
            el.breadcrumb.innerHTML = parts.map(function(part, i) {
                const isLast = i === parts.length - 1;
                return '<span class="nxs-ide__crumb' + (isLast ? ' is-file' : '') + '">' +
                    esc(part) + '</span>' +
                    (isLast ? '' : '<span class="nxs-ide__crumb-sep">›</span>');
            }).join('');
        };

        const renderSteps = () => {
            if (!el.steps) {
                return;
            }
            const total = state.steps.length;
            const current = Math.min((state.activestep || 0) + 1, Math.max(total, 1));
            const doneCount = Object.keys(state.completed).filter(function(k) {
                return state.completed[k];
            }).length;
            const pct = total ? Math.round((doneCount / total) * 100) : 0;

            if (el.stepsSub) {
                el.stepsSub.textContent = doneCount + ' / ' + total + ' done';
            }
            if (el.stepChip) {
                el.stepChip.textContent = total ? (current + ' / ' + total) : '—';
            }
            if (el.stepsProgressBar) {
                el.stepsProgressBar.style.width = pct + '%';
            }
            if (el.stepsBadge) {
                const remaining = Math.max(0, total - doneCount);
                if (remaining > 0) {
                    el.stepsBadge.textContent = String(remaining);
                    el.stepsBadge.removeAttribute('hidden');
                } else if (total > 0) {
                    el.stepsBadge.textContent = '✓';
                    el.stepsBadge.removeAttribute('hidden');
                } else {
                    el.stepsBadge.setAttribute('hidden', 'hidden');
                }
            }

            el.steps.innerHTML = state.steps.map(function(step, idx) {
                const done = !!state.completed[idx];
                const active = idx === state.activestep;
                const brief = String(step.instructions || step.summary || step.brief || '')
                    .replace(/[#>*_`\-]+/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                const excerpt = brief.length > 110 ? brief.slice(0, 107) + '…' : brief;
                const status = done ? 'Done' : (active ? 'Current' : 'Up next');
                const cls = 'nxs-ide__stepcard' +
                    (done ? ' is-done' : '') +
                    (active ? ' is-active' : '');
                const mark = done ? '✓' : String(idx + 1);
                return '<article class="' + cls + '" data-step="' + idx + '" role="button" tabindex="0">' +
                    '<div class="nxs-ide__stepcard-rail" aria-hidden="true"></div>' +
                    '<div class="nxs-ide__stepcard-mark">' + mark + '</div>' +
                    '<div class="nxs-ide__stepcard-body">' +
                        '<div class="nxs-ide__stepcard-top">' +
                            '<h3 class="nxs-ide__stepcard-title">' +
                                esc(step.title || ('Step ' + (idx + 1))) +
                            '</h3>' +
                            '<span class="nxs-ide__stepcard-status">' + status + '</span>' +
                        '</div>' +
                        (excerpt
                            ? '<p class="nxs-ide__stepcard-brief">' + esc(excerpt) + '</p>'
                            : '') +
                        (active
                            ? '<div class="nxs-ide__stepcard-actions">' +
                                '<button type="button" class="nxs-ide__btn nxs-ide__btn--primary" data-action="check-step">' +
                                'Check step</button>' +
                              '</div>'
                            : '') +
                    '</div>' +
                '</article>';
            }).join('');
            syncStepDoc();
        };

        const openFile = (path) => {
            flushEditor();
            if (!Object.prototype.hasOwnProperty.call(state.files, path)) {
                return;
            }
            state.activePath = path;
            if (state.openTabs.indexOf(path) === -1) {
                state.openTabs.push(path);
            }
            const parts = path.split('/');
            let acc = '';
            parts.slice(0, -1).forEach(function(part) {
                acc = acc ? acc + '/' + part : part;
                state.openFolders[acc] = true;
            });
            setCenterView('file');
            renderFiles();
            renderTabs();
            renderBreadcrumb();
            if (state.editor) {
                state.editor.setMode(path);
                state.editor.setValue(state.files[path] || '');
            }
        };

        const bindFallbackEditor = () => {
            let ta = root.querySelector('[data-region="editor-fallback"]');
            if (!ta && el.editor) {
                el.editor.innerHTML = '';
                ta = document.createElement('textarea');
                ta.className = 'nxs-ide__fallback';
                ta.setAttribute('data-region', 'editor-fallback');
                el.editor.appendChild(ta);
            }
            if (!ta) {
                return null;
            }
            ta.hidden = false;
            state.editorApi = {
                getValue: function() { return ta.value; },
                setValue: function(v) { ta.value = v || ''; },
                setMode: function() {},
                resize: function() {},
            };
            state.editor = state.editorApi;
            ta.addEventListener('input', function() {
                scheduleSave();
                if (state.runtime === 'static') {
                    clearTimeout(state._previewTimer);
                    state._previewTimer = setTimeout(refreshStaticPreview, 600);
                }
            });
            return state.editorApi;
        };

        const ensureEditor = () => {
            if (!state.editorApi) {
                bindFallbackEditor();
            }
            if (state._aceReady) {
                return Promise.resolve(state.editorApi);
            }
            const attachAce = function() {
                if (!window.ace || !el.editor) {
                    return state.editorApi;
                }
                if (cfg.aceBaseUrl) {
                    try { window.ace.config.set('basePath', cfg.aceBaseUrl); } catch (e) {}
                }
                const current = state.editorApi ? state.editorApi.getValue() : '';
                let host = el.editor.querySelector('[data-region="ace-host"]');
                if (!host) {
                    host = document.createElement('div');
                    host.setAttribute('data-region', 'ace-host');
                    host.style.cssText = 'position:absolute;inset:0;';
                    el.editor.appendChild(host);
                }
                const ta = root.querySelector('[data-region="editor-fallback"]');
                if (ta) {
                    ta.hidden = true;
                }
                const editor = window.ace.edit(host);
                editor.setTheme(state.theme === 'dark' ? 'ace/theme/tomorrow_night' : 'ace/theme/textmate');
                editor.session.setUseSoftTabs(true);
                editor.session.setTabSize(2);
                editor.setOptions({fontSize: '13px', showPrintMargin: false});
                editor.setValue(current || '', -1);
                editor.session.on('change', function() {
                    if (ta) {
                        ta.value = editor.getValue();
                    }
                    scheduleSave();
                    if (state.runtime === 'static') {
                        clearTimeout(state._previewTimer);
                        state._previewTimer = setTimeout(refreshStaticPreview, 600);
                    }
                });
                state.editorApi = {
                    getValue: function() { return editor.getValue(); },
                    setValue: function(v) {
                        editor.setValue(v || '', -1);
                        if (ta) {
                            ta.value = v || '';
                        }
                    },
                    setMode: function(path) {
                        const map = {
                            javascript: 'ace/mode/javascript',
                            typescript: 'ace/mode/typescript',
                            css: 'ace/mode/css',
                            html: 'ace/mode/html',
                            json: 'ace/mode/json',
                            markdown: 'ace/mode/markdown',
                            plaintext: 'ace/mode/text',
                        };
                        editor.session.setMode(map[modeFor(path)] || 'ace/mode/text');
                    },
                    resize: function() { try { editor.resize(true); } catch (e2) {} },
                    _ace: editor,
                };
                state.editor = state.editorApi;
                state._aceReady = true;
                setTimeout(function() { state.editorApi.resize(); }, 80);
                return state.editorApi;
            };

            if (window.ace) {
                try { attachAce(); } catch (e) { termLog('Ace skipped: ' + e.message); }
                return Promise.resolve(state.editorApi);
            }
            return loadScript(cfg.aceUrl || 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ace.js')
                .then(function() {
                    try { attachAce(); } catch (e) {}
                    return state.editorApi;
                })
                .catch(function() { return state.editorApi; });
        };

        const buildStaticHtml = () => {
            flushEditor();
            let html = state.files['index.html'] || '<!DOCTYPE html><html><body><p>Add index.html</p></body></html>';
            const css = state.files['styles.css'] || state.files['src/styles.css'] || '';
            const js = state.files['app.js'] || '';
            if (css && html.indexOf('styles.css') !== -1) {
                html = html.replace(
                    /<link[^>]+href=["']styles\.css["'][^>]*>/i,
                    '<style>' + css + '</style>'
                );
            }
            if (js && html.indexOf('app.js') !== -1) {
                html = html.replace(
                    /<script[^>]+src=["']app\.js["'][^>]*>\s*<\/script>/i,
                    '<script>' + js + '<\/script>'
                );
            }
            return html;
        };

        const refreshStaticPreview = () => {
            if (!el.preview) {
                return;
            }
            const html = buildStaticHtml();
            const blob = new Blob([html], {type: 'text/html'});
            const url = URL.createObjectURL(blob);
            if (state.previewUrl) {
                try { URL.revokeObjectURL(state.previewUrl); } catch (e) {}
            }
            state.previewUrl = url;
            el.preview.src = url;
            if (el.previewUrlEl) {
                el.previewUrlEl.textContent = 'preview://static';
            }
            setStatus('Preview live');
        };

        const runFileChecks = (checks) => {
            const results = [];
            (checks || []).forEach(function(c) {
                if (c.type === 'file_includes') {
                    const body = state.files[c.path] || '';
                    const ok = body.indexOf(c.needle) !== -1;
                    results.push({ok: ok, msg: (ok ? 'OK' : 'Missing') + ' "' + c.needle + '" in ' + c.path});
                } else if (c.type === 'runtime' && c.assert === 'webcontainer_ready') {
                    const ok = !!state.wcReady;
                    results.push({ok: ok, msg: ok ? 'WebContainer ready' : 'WebContainer not ready'});
                } else if (c.type === 'dom') {
                    results.push({ok: null, check: c});
                }
            });
            return results;
        };

        const runDomChecks = (pending) => {
            return new Promise(function(resolve) {
                if (!pending.length || !el.preview) {
                    resolve([]);
                    return;
                }
                setTimeout(function() {
                    const out = [];
                    try {
                        const doc = el.preview.contentDocument;
                        pending.forEach(function(item) {
                            const c = item.check;
                            const node = doc ? doc.querySelector(c.selector) : null;
                            const ok = c.assert === 'exists' ? !!node : false;
                            out.push({ok: ok, msg: (ok ? 'OK' : 'Missing DOM') + ' ' + c.selector});
                        });
                    } catch (err) {
                        pending.forEach(function() {
                            out.push({ok: false, msg: 'DOM check blocked'});
                        });
                    }
                    resolve(out);
                }, 200);
            });
        };

        const checkCurrentStep = () => {
            flushEditor();
            const step = state.steps[state.activestep];
            if (!step) {
                return;
            }
            setStatus('Checking…');
            showBottom('terminal');
            if (el.problems) {
                el.problems.textContent = '';
            }
            if (state.runtime === 'static') {
                refreshStaticPreview();
            }
            const partial = runFileChecks(step.checks || []);
            const domPending = partial.filter(function(r) { return r.ok === null; });
            const known = partial.filter(function(r) { return r.ok !== null; });
            runDomChecks(domPending).then(function(domResults) {
                const all = known.concat(domResults);
                const passed = all.length > 0 && all.every(function(r) { return r.ok; });
                all.forEach(function(r) {
                    const line = (r.ok ? '✓ ' : '✗ ') + r.msg;
                    termLog(line);
                    if (!r.ok) {
                        problemsLog(line);
                    }
                });
                if (passed) {
                    state.completed[state.activestep] = true;
                    termLog('Step ' + (state.activestep + 1) + ' passed');
                } else {
                    termLog('Step ' + (state.activestep + 1) + ' not yet complete');
                    showBottom('problems');
                }
                const csv = Object.keys(state.completed).filter(function(k) {
                    return state.completed[k];
                }).join(',');
                call('local_nexstack_check_step', {
                    missionid: cfg.missionid,
                    stepid: state.activestep,
                    passed: passed,
                    completedcsv: csv,
                    detail: JSON.stringify(all)
                }).then(function(resp) {
                    if (resp.passed) {
                        state.activestep = resp.activestep;
                    }
                    renderSteps();
                    setStatus(passed ? 'Step passed' : 'Keep going');
                }).catch(Notification.exception);
            });
        };

        const bootSandbox = () => {
            if (state.bootPromise) {
                return state.bootPromise;
            }
            if (!cfg.sandbox || state.runtime !== 'webcontainer') {
                termLog('Remote sandbox is not enabled for this mission.');
                return Promise.resolve(false);
            }
            setStatus('Booting sandbox…');
            showBottom('terminal');
            termLog('Starting remote Docker sandbox (npm install + dev server)…');
            flushEditor();
            state.bootPromise = call('local_nexstack_sandbox_session', {
                action: 'boot',
                missionid: cfg.missionid,
                sessionid: '',
                filesjson: JSON.stringify(filesForPersist()),
                cmd: '',
                argsjson: '[]'
            }).then(function(resp) {
                if (resp.logs) {
                    String(resp.logs).split(/\r?\n/).forEach(function(line) {
                        if (line) {
                            termLog(line);
                        }
                    });
                }
                if (resp.error) {
                    throw new Error(resp.error);
                }
                if (!resp.ok && resp.status !== 'running' && resp.status !== 'starting') {
                    throw new Error(resp.status || 'Sandbox failed');
                }
                state.sandboxId = resp.sessionid;
                state.wcReady = true;
                state.wc = {
                    _sandbox: true,
                    spawn: function(cmd, args) {
                        return {
                            output: {pipeTo: function() { return Promise.resolve(); }},
                            exit: call('local_nexstack_sandbox_session', {
                                action: 'exec',
                                missionid: cfg.missionid,
                                sessionid: state.sandboxId,
                                filesjson: '{}',
                                cmd: cmd,
                                argsjson: JSON.stringify(args || [])
                            }).then(function(r) {
                                if (r.logs) {
                                    termLog(r.logs);
                                }
                                return r.exitcode;
                            })
                        };
                    }
                };
                if (resp.previewurl) {
                    termLog('Preview ready on ' + resp.previewurl);
                    if (el.preview) {
                        el.preview.src = resp.previewurl;
                    }
                    if (el.previewUrlEl) {
                        el.previewUrlEl.textContent = resp.previewurl;
                    }
                }
                setStatus('Sandbox · ' + (resp.status || 'running'));
                return true;
            }).catch(function(err) {
                termLog('Sandbox error: ' + (err && err.message ? err.message : err));
                termLog('Enable sandbox in Site admin → NexStack and start sandbox-server (see repo sandbox-server/README.md).');
                setStatus('Sandbox failed');
                state.bootPromise = null;
                return false;
            });
            return state.bootPromise;
        };

        const bootRuntime = () => {
            if (state.runtime !== 'webcontainer') {
                termLog('This mission uses static preview — no sandbox boot needed.');
                return Promise.resolve(false);
            }
            if (cfg.sandbox) {
                return bootSandbox();
            }
            if (cfg.webcontainers) {
                return bootWebContainer();
            }
            termLog('No runtime configured. Enable remote sandbox in NexStack settings.');
            setStatus('Sandbox not configured');
            return Promise.resolve(false);
        };

        const bootWebContainer = () => {
            if (state.bootPromise) {
                return state.bootPromise;
            }
            if (!cfg.webcontainers || state.runtime !== 'webcontainer') {
                termLog('WebContainers disabled for this mission.');
                return Promise.resolve(false);
            }
            setStatus('Booting WebContainer…');
            showBottom('terminal');
            termLog('Starting isolated WebContainer frame (COOP/COEP)…');

            const pending = {};
            let reqSeq = 0;
            const nextId = () => {
                reqSeq += 1;
                return 'r' + reqSeq;
            };
            const waitMsg = (type, id, timeoutMs) => new Promise(function(resolve, reject) {
                const key = type + ':' + (id || '');
                const timer = setTimeout(function() {
                    delete pending[key];
                    reject(new Error('Timed out waiting for ' + type));
                }, timeoutMs || 120000);
                pending[key] = function(payload) {
                    clearTimeout(timer);
                    delete pending[key];
                    resolve(payload);
                };
            });

            const ensureHost = () => {
                if (state.wcHost && !state.wcHost.closed) {
                    return Promise.resolve(state.wcHost);
                }
                if (!cfg.wcframeurl) {
                    return Promise.reject(new Error('WC frame URL missing'));
                }

                const isIsolatedPayload = function(data) {
                    const iso = data && data.iso ? data.iso : data;
                    return !!(iso && iso.sab && iso.coi);
                };

                const waitReady = function(getWin, label, timeoutMs, isPopup) {
                    return new Promise(function(resolve, reject) {
                        let settled = false;
                        const finish = function(err, win) {
                            if (settled) {
                                return;
                            }
                            settled = true;
                            window.removeEventListener('message', onReady);
                            clearInterval(closedPoll);
                            if (err) {
                                reject(err);
                            } else {
                                resolve(win);
                            }
                        };
                        const onReady = function(ev) {
                            if (ev.origin !== window.location.origin) {
                                return;
                            }
                            const data = ev.data || {};
                            if (data.channel !== 'nxs-wc') {
                                return;
                            }
                            if (data.type === 'frame-ready' || data.type === 'pong') {
                                if (!isIsolatedPayload(data)) {
                                    const iso = (data && data.iso) || {};
                                    finish(new Error(
                                        label + ' loaded but is not cross-origin isolated' +
                                        ' (coi=' + !!iso.coi + ', sab=' + !!iso.sab + ')'
                                    ));
                                    return;
                                }
                                finish(null, getWin());
                            }
                        };
                        window.addEventListener('message', onReady);
                        const closedPoll = setInterval(function() {
                            if (!isPopup) {
                                return;
                            }
                            try {
                                const w = getWin();
                                if (!w || w.closed) {
                                    finish(new Error('WC popup was closed or blocked'));
                                }
                            } catch (e) {
                                finish(new Error('WC popup was closed or blocked'));
                            }
                        }, 400);
                        setTimeout(function() {
                            try {
                                const w = getWin();
                                if (w) {
                                    w.postMessage({channel: 'nxs-wc', type: 'ping'}, window.location.origin);
                                }
                            } catch (e) { /* ignore */ }
                        }, 300);
                        setTimeout(function() {
                            finish(new Error('Timed out waiting for ' + label));
                        }, timeoutMs || 15000);
                    });
                };

                const tryIframe = function() {
                    termLog('Trying isolated iframe (COOP/COEP credentialless)…');
                    const iframe = document.createElement('iframe');
                    iframe.setAttribute('title', 'NexStack WebContainer');
                    iframe.setAttribute('aria-hidden', 'true');
                    iframe.setAttribute('allow', 'cross-origin-isolated; autoplay');
                    iframe.allow = 'cross-origin-isolated; autoplay';
                    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;opacity:0;pointer-events:none;';
                    document.body.appendChild(iframe);
                    state.wcFrame = iframe;
                    iframe.src = cfg.wcframeurl + (cfg.wcframeurl.indexOf('?') === -1 ? '?' : '&') +
                        't=' + Date.now();
                    return waitReady(function() {
                        return iframe.contentWindow;
                    }, 'WC iframe', 12000, false).then(function(win) {
                        state.wcHost = win;
                        state.wcHostMode = 'iframe';
                        termLog('Isolated iframe ready.');
                        return win;
                    }).catch(function(err) {
                        try {
                            iframe.remove();
                        } catch (e2) { /* ignore */ }
                        state.wcFrame = null;
                        throw err;
                    });
                };

                const tryPopup = function() {
                    termLog('Iframe isolation failed — opening WC popup window…');
                    termLog('Allow popups for this site if the browser blocks the window.');
                    const url = cfg.wcframeurl +
                        (cfg.wcframeurl.indexOf('?') === -1 ? '?' : '&') +
                        'popup=1&t=' + Date.now();
                    const popup = window.open(url, 'nxs-wc-host', 'popup=yes,width=520,height=360');
                    if (!popup) {
                        return Promise.reject(new Error('Popup blocked — allow popups, then click Boot again'));
                    }
                    try {
                        popup.focus();
                    } catch (e) { /* ignore */ }
                    state.wcPopup = popup;
                    return waitReady(function() {
                        return popup;
                    }, 'WC popup', 25000, true).then(function(win) {
                        state.wcHost = win;
                        state.wcHostMode = 'popup';
                        termLog('Isolated popup ready.');
                        return win;
                    });
                };

                return tryIframe().catch(function(iframeErr) {
                    termLog(String(iframeErr && iframeErr.message ? iframeErr.message : iframeErr));
                    return tryPopup();
                });
            };

            const onBridgeMessage = function(ev) {
                if (ev.origin !== window.location.origin) {
                    return;
                }
                const data = ev.data || {};
                if (data.channel !== 'nxs-wc') {
                    return;
                }
                if (data.type === 'spawn-out' && data.text != null) {
                    termLog(String(data.text).replace(/\n$/, ''));
                    return;
                }
                if (data.type === 'server-ready') {
                    termLog('Preview ready on ' + data.url);
                    state.wcReady = true;
                    if (el.preview) {
                        el.preview.src = data.url;
                    }
                    if (el.previewUrlEl) {
                        el.previewUrlEl.textContent = data.url;
                    }
                    setStatus('WebContainer · ' + data.port);
                    return;
                }
                const keyOk = data.type + ':' + (data.id || '');
                if (pending[keyOk]) {
                    pending[keyOk](data);
                    return;
                }
                if (data.type === 'boot-err' && pending['boot-ok:' + (data.id || '')]) {
                    pending['boot-ok:' + (data.id || '')](data);
                }
            };
            window.addEventListener('message', onBridgeMessage);
            state.wcBridgeHandler = onBridgeMessage;
            state.wcPending = pending;

            const postToHost = (msg) => {
                if (!state.wcHost) {
                    throw new Error('WC host not ready');
                }
                state.wcHost.postMessage(Object.assign({channel: 'nxs-wc'}, msg), window.location.origin);
            };

            state.bootPromise = ensureHost().then(function() {
                flushEditor();
                const id = nextId();
                termLog('Mounting project into WebContainer…');
                postToHost({type: 'boot', id: id, files: filesForPersist()});
                return waitMsg('boot-ok', id, 180000).then(function(payload) {
                    if (payload && payload.type === 'boot-err') {
                        throw new Error(payload.message || 'Boot failed');
                    }
                    state.wc = {
                        _bridge: true,
                        spawn: function(cmd, args, opts) {
                            const sid = nextId();
                            postToHost({
                                type: 'spawn',
                                id: sid,
                                cmd: cmd,
                                args: args || [],
                                cwd: (opts && opts.cwd) || '/'
                            });
                            return {
                                output: {
                                    pipeTo: function() {
                                        return Promise.resolve();
                                    }
                                },
                                exit: waitMsg('spawn-exit', sid, 600000).then(function(p) {
                                    if (p.message && p.code !== 0) {
                                        termLog(String(p.message), 'err');
                                    }
                                    return p.code;
                                })
                            };
                        }
                    };
                    termLog('$ npm install');
                    const install = state.wc.spawn('npm', ['install'], {cwd: '/'});
                    return install.exit.then(function(code) {
                        if (code !== 0) {
                            throw new Error('npm install failed (' + code + ')');
                        }
                        termLog('$ npm run dev');
                        state.wc.spawn('npm', ['run', 'dev'], {cwd: '/'});
                        return true;
                    });
                });
            }).catch(function(err) {
                termLog('WebContainer error: ' + (err && err.message ? err.message : err));
                termLog('Check /local/nexstack/wcframe.php?popup=1 in a new tab — it must say “Isolated OK”.');
                termLog('If not, your reverse proxy is stripping COOP/COEP; whitelist that path.');
                setStatus('WC failed');
                state.bootPromise = null;
                return false;
            });
            return state.bootPromise;
        };

        const applyMissionData = (data) => {
            if (!data) {
                return;
            }
            if (el.title && data.name) {
                el.title.textContent = data.name;
            }
            if (el.brief && (data.briefmd || data.summary)) {
                el.brief.textContent = data.briefmd || data.summary || '';
            }
            state.runtime = data.runtime || state.runtime;
            if (typeof data.activestep === 'number') {
                state.activestep = data.activestep;
            }
            var nextSteps = null;
            try {
                if (typeof data.stepsjson === 'string' && data.stepsjson !== '') {
                    nextSteps = JSON.parse(data.stepsjson);
                } else if (Array.isArray(data.steps)) {
                    nextSteps = data.steps;
                }
            } catch (e) {
                nextSteps = null;
            }
            if (Array.isArray(nextSteps) && nextSteps.length) {
                state.steps = nextSteps;
            }
            state.completed = {};
            (String(data.completedcsv || '').split(',')).forEach(function(s) {
                if (s !== '') {
                    state.completed[parseInt(s, 10)] = true;
                }
            });
            var nextFiles = {};
            (data.files || []).forEach(function(f) {
                if (f && f.path) {
                    nextFiles[f.path] = f.content || '';
                }
            });
            if (Object.keys(nextFiles).length) {
                state.files = nextFiles;
                state.openTabs = state.openTabs.filter(function(p) {
                    return Object.prototype.hasOwnProperty.call(state.files, p);
                });
            }
            renderSteps();
            renderFiles();
            renderTabs();
        };

        const bindResizers = () => {
            const start = function(split, mode) {
                split.classList.add('is-dragging');
                const kind = mode === 'terminal' ? 'row' : 'col';
                document.body.classList.add('nxs-ide-resizing', 'nxs-ide-resizing-' + kind);
                const onMove = function(ev) {
                    if (mode === 'sidebar' && el.sidebar && el.workspace && state.sidebarOpen) {
                        const rect = el.workspace.getBoundingClientRect();
                        const railW = (el.rail && el.rail.offsetWidth) || 52;
                        const w = Math.min(480, Math.max(160, ev.clientX - rect.left - railW));
                        el.sidebar.style.width = w + 'px';
                        state.sidebarWidth = w;
                        persistSidebar();
                    } else if (mode === 'preview' && el.previewPane && el.workspace) {
                        const rect = el.workspace.getBoundingClientRect();
                        const w = Math.min(rect.width * 0.7, Math.max(200, rect.right - ev.clientX));
                        el.previewPane.style.width = w + 'px';
                    } else if (mode === 'terminal' && el.termPane && el.center) {
                        if (state.termCollapsed) {
                            state.termCollapsed = false;
                            el.termPane.classList.remove('is-collapsed');
                        }
                        const rect = el.center.getBoundingClientRect();
                        const h = Math.min(rect.height * 0.55, Math.max(80, rect.bottom - ev.clientY));
                        el.termPane.style.height = h + 'px';
                        state.termHeight = h;
                        if (state.editor && state.editor.resize) {
                            state.editor.resize();
                        }
                    }
                };
                const onUp = function() {
                    split.classList.remove('is-dragging');
                    document.body.classList.remove('nxs-ide-resizing', 'nxs-ide-resizing-col', 'nxs-ide-resizing-row');
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    if (state.editor && state.editor.resize) {
                        state.editor.resize();
                    }
                };
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            };
            root.querySelectorAll('[data-resize]').forEach(function(split) {
                split.addEventListener('mousedown', function(ev) {
                    ev.preventDefault();
                    start(split, split.getAttribute('data-resize'));
                });
            });
        };

        bindFallbackEditor();
        bindResizers();
        restoreSidebar();
        applyTheme();
        applySidebarChrome();
        renderTermTabs();
        paintTerm();
        termWrite('NexStack terminal — type `help` for commands.', 'muted');
        showBottom('terminal');

        // Keep in sync if RemUI theme changes in another tab/window.
        window.addEventListener('storage', function(ev) {
            if (ev.key !== 'remui_darkmode' && ev.key !== 'darkMode') {
                return;
            }
            const next = detectRemuiDark() ? 'dark' : 'light';
            if (next !== state.theme) {
                state.theme = next;
                applyTheme();
            }
        });

        if (el.termForm) {
            el.termForm.addEventListener('submit', function(ev) {
                ev.preventDefault();
                submitCommand(el.termInput ? el.termInput.value : '');
            });
        }
        if (el.termInput) {
            el.termInput.addEventListener('keydown', function(ev) {
                const term = activeTerm();
                if (!term) {
                    return;
                }
                if (ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    if (!term.history.length) {
                        return;
                    }
                    if (term.histIdx < 0) {
                        term.histIdx = term.history.length;
                    }
                    term.histIdx = Math.max(0, term.histIdx - 1);
                    el.termInput.value = term.history[term.histIdx] || '';
                } else if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    if (!term.history.length) {
                        return;
                    }
                    term.histIdx = Math.min(term.history.length, term.histIdx + 1);
                    el.termInput.value = term.histIdx >= term.history.length
                        ? ''
                        : (term.history[term.histIdx] || '');
                } else if (ev.key === 'l' && (ev.ctrlKey || ev.metaKey)) {
                    ev.preventDefault();
                    clearActiveTerm();
                } else if (ev.key === 'c' && (ev.ctrlKey || ev.metaKey) && !el.termInput.value) {
                    // Soft interrupt when idle (no running process kill yet).
                    termWrite('^C', 'muted');
                }
            });
        }
        if (el.termOut) {
            el.termOut.addEventListener('click', function() {
                if (el.termInput) {
                    el.termInput.focus();
                }
            });
        }

        root.addEventListener('click', function(ev) {
            const closeTerm = ev.target.closest('[data-term-close]');
            if (closeTerm && root.contains(closeTerm)) {
                ev.preventDefault();
                ev.stopPropagation();
                const cid = closeTerm.getAttribute('data-term-close');
                if (cid === state.activeTermId) {
                    killActiveTerm();
                } else {
                    state.terminals = state.terminals.filter(function(t) { return t.id !== cid; });
                    renderTermTabs();
                }
                return;
            }
            const termTab = ev.target.closest('[data-term]');
            if (termTab && root.contains(termTab) && !ev.target.closest('[data-term-close]')) {
                state.activeTermId = termTab.getAttribute('data-term');
                renderTermTabs();
                paintTerm();
                showBottom('terminal');
                return;
            }
            const closeTabBtn = ev.target.closest('[data-close-tab]');
            if (closeTabBtn && root.contains(closeTabBtn)) {
                ev.preventDefault();
                ev.stopPropagation();
                closeTab(closeTabBtn.getAttribute('data-close-tab'));
                return;
            }
            const folderBtn = ev.target.closest('[data-folder]');
            if (folderBtn && root.contains(folderBtn)) {
                const key = folderBtn.getAttribute('data-folder');
                state.openFolders[key] = !state.openFolders[key];
                renderFiles();
                return;
            }
            const sideBtn = ev.target.closest('[data-sidebar]');
            if (sideBtn && root.contains(sideBtn)) {
                showSidebar(sideBtn.getAttribute('data-sidebar'));
                return;
            }
            const bottomBtn = ev.target.closest('[data-bottom]');
            if (bottomBtn && root.contains(bottomBtn)) {
                showBottom(bottomBtn.getAttribute('data-bottom'));
                return;
            }
            const actionEarly = ev.target.closest('[data-action]');
            if (actionEarly && root.contains(actionEarly)) {
                const earlyName = actionEarly.getAttribute('data-action');
                if (earlyName === 'check-step') {
                    checkCurrentStep();
                    return;
                }
            }
            const stepBtn = ev.target.closest('[data-step]');
            if (stepBtn && root.contains(stepBtn)) {
                state.activestep = parseInt(stepBtn.getAttribute('data-step'), 10) || 0;
                renderSteps();
                showSidebar('steps', true);
                syncStepDoc({open: true});
                return;
            }
            const fileBtn = ev.target.closest('[data-path]');
            if (fileBtn && root.contains(fileBtn)) {
                openFile(fileBtn.getAttribute('data-path'));
                return;
            }
            const action = ev.target.closest('[data-action]');
            if (!action || !root.contains(action)) {
                return;
            }
            const name = action.getAttribute('data-action');
            if (name === 'check-step') {
                checkCurrentStep();
            } else if (name === 'open-steps') {
                showSidebar('steps', true);
                syncStepDoc({open: true});
            } else if (name === 'open-step-doc') {
                syncStepDoc({open: true});
            } else if (name === 'refresh-preview') {
                if (state.runtime === 'webcontainer' && state.wcReady) {
                    setStatus('HMR preview live');
                } else {
                    refreshStaticPreview();
                }
            } else if (name === 'boot-wc') {
                bootRuntime();
            } else if (name === 'toggle-theme') {
                state.theme = state.theme === 'dark' ? 'light' : 'dark';
                applyTheme();
            } else if (name === 'toggle-sidebar') {
                setSidebarOpen(!state.sidebarOpen);
            } else if (name === 'term-new' || name === 'term-split') {
                showBottom('terminal');
                newTerminal();
            } else if (name === 'term-clear') {
                showBottom('terminal');
                clearActiveTerm();
            } else if (name === 'term-kill') {
                showBottom('terminal');
                killActiveTerm();
            } else if (name === 'term-toggle') {
                toggleTermPane();
            }
        });

        setStatus('Loading…');
        const readBootstrap = function() {
            const node = document.getElementById('nxs-bootstrap');
            if (!node || !node.textContent) {
                return null;
            }
            try {
                return JSON.parse(node.textContent);
            } catch (e) {
                return null;
            }
        };

        const startWith = function(data) {
            applyMissionData(data);
            const prefer = ['src/App.jsx', 'src/main.jsx', 'index.html', 'app.js'];
            let codeFile = '';
            prefer.forEach(function(p) {
                if (!codeFile && state.files[p] !== undefined) {
                    codeFile = p;
                }
            });
            if (!codeFile) {
                codeFile = Object.keys(state.files).sort()[0] || '';
            }
            return ensureEditor().then(function() {
                if (codeFile) {
                    if (state.openTabs.indexOf(codeFile) === -1) {
                        state.openTabs.push(codeFile);
                    }
                }
                syncStepDoc({open: true});
                if (state.runtime === 'static') {
                    refreshStaticPreview();
                    setStatus('Ready');
                    return;
                }
                if (cfg.sandbox || cfg.webcontainers) {
                    termLog('Node sandbox mission — starting remote sandbox…');
                    return bootRuntime();
                }
                termLog('Node mission detected, but sandbox is disabled in settings.');
                setStatus('Sandbox not configured');
            });
        };

        const boot = readBootstrap();
        if (boot && (boot.files || boot.stepsjson)) {
            startWith(boot).catch(function(err) {
                termLog('Boot error: ' + (err && err.message ? err.message : err));
                setStatus('Boot error');
            });
        } else {
            call('local_nexstack_get_mission', {missionid: cfg.missionid}).then(function(data) {
                return startWith(data);
            }).catch(function(err) {
                Notification.exception(err);
                setStatus('Load failed');
                bindFallbackEditor();
            });
        }
    };

    return {init: init};
});
