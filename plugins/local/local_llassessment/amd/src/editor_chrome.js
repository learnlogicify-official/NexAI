/**
 * NexEditor-style chrome: Ace fill + status bar + settings.
 * Careful not to break Ace's own gutter/scroller layout.
 *
 * @module     local_llassessment/editor_chrome
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const STORAGE_KEY = 'llassessment.nexeditor.v3';

    const defaults = {
        theme: 'light',
        fontSize: 14,
        tabSize: 4
    };

    const loadPrefs = function() {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return Object.assign({}, defaults);
            }
            return Object.assign({}, defaults, JSON.parse(raw));
        } catch (e) {
            return Object.assign({}, defaults);
        }
    };

    const savePrefs = function(prefs) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        } catch (e) {
            // Ignore.
        }
    };

    const findAce = function(root) {
        if (!window.ace) {
            return null;
        }
        const el = root.querySelector('.ace_editor');
        if (!el) {
            return null;
        }
        try {
            return window.ace.edit(el);
        } catch (e) {
            return null;
        }
    };

    /**
     * Keep Ace gutter and code scroller aligned (no overlay).
     *
     * @param {Object} aceEditor
     */
    const syncGutter = function(aceEditor) {
        if (!aceEditor || !aceEditor.renderer) {
            return;
        }
        try {
            aceEditor.setOption('showGutter', true);
            aceEditor.renderer.setShowGutter(true);
            aceEditor.resize(true);
            if (typeof aceEditor.renderer.$updateGutterWidth === 'function') {
                aceEditor.renderer.$updateGutterWidth();
            }
            aceEditor.renderer.updateFull(true);

            const gutter = aceEditor.renderer.$gutter;
            const scroller = aceEditor.renderer.$scroller;
            if (gutter && scroller) {
                const w = Math.ceil(gutter.getBoundingClientRect().width || gutter.offsetWidth || 0);
                if (w > 0) {
                    scroller.style.left = w + 'px';
                    aceEditor.renderer.$gutterWidth = w;
                    // Print margin / content padding track gutter too.
                    if (aceEditor.renderer.$horizScroll) {
                        aceEditor.renderer.$horizScroll = false;
                    }
                }
            }
        } catch (e) {
            // ignore
        }
    };

    /**
     * Size Ace via normal block flow (NOT position:absolute on .ace_editor).
     *
     * @param {Element} stage
     * @param {Object|null} aceEditor
     */
    const forceAceFill = function(stage, aceEditor) {
        if (!stage) {
            return;
        }

        stage.style.position = 'relative';
        stage.style.width = '100%';
        stage.style.height = '100%';
        stage.style.minHeight = '280px';
        stage.style.flex = '1 1 auto';
        stage.style.overflow = 'hidden';

        const wrappers = stage.querySelectorAll(
            '.coderunner-answer, .ablock, .answer, .prompt, .ace-editor, .ui_wrapper'
        );
        wrappers.forEach(function(w) {
            w.style.position = 'relative';
            w.style.width = '100%';
            w.style.height = '100%';
            w.style.minHeight = '280px';
            w.style.margin = '0';
            w.style.padding = '0';
            w.style.border = '0';
            w.style.boxSizing = 'border-box';
            w.style.display = 'block';
        });

        const aceEl = stage.querySelector('.ace_editor');
        if (aceEl) {
            aceEl.style.position = 'relative';
            aceEl.style.inset = '';
            aceEl.style.top = '';
            aceEl.style.right = '';
            aceEl.style.bottom = '';
            aceEl.style.left = '';
            aceEl.style.width = '100%';
            aceEl.style.height = '100%';
            aceEl.style.minHeight = '280px';
            aceEl.style.minWidth = '0';
            aceEl.style.boxSizing = 'border-box';
        }

        stage.querySelectorAll('textarea').forEach(function(ta) {
            if (ta.classList.contains('ace_text-input')) {
                return;
            }
            if (ta.name && /answer/i.test(ta.name)) {
                ta.classList.add('ll-nex-hidden-textarea');
                ta.setAttribute('aria-hidden', 'true');
            }
        });

        if (aceEditor) {
            syncGutter(aceEditor);
        }
    };

    const applyPrefs = function(prefs, aceEditor, shell) {
        shell.classList.toggle('ll-nex--dark', prefs.theme === 'dark');
        shell.classList.toggle('ll-nex--light', prefs.theme !== 'dark');
        shell.style.setProperty('--ll-nex-font', prefs.fontSize + 'px');

        if (!aceEditor) {
            return;
        }
        try {
            aceEditor.setOptions({
                fontSize: prefs.fontSize + 'px',
                tabSize: prefs.tabSize,
                useSoftTabs: true,
                showGutter: true,
                showPrintMargin: false,
                highlightActiveLine: true,
                highlightGutterLine: true,
                displayIndentGuides: true,
                scrollPastEnd: 0.2,
                animatedScroll: true,
                fixedWidthGutter: false,
                behavioursEnabled: true,
                wrapBehavioursEnabled: true
            });
            try {
                aceEditor.setBehavioursEnabled(true);
                aceEditor.commands.bindKeys({'Tab': 'indent', 'Shift-Tab': 'outdent'});
            } catch (eTab) {}
        } catch (eOpts) {}
        try {
            const themeName = prefs.theme === 'dark' ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome';
            try {
                aceEditor.setTheme(themeName);
            } catch (e1) {
                try {
                    aceEditor.setTheme(prefs.theme === 'dark' ? 'ace/theme/monokai' : 'ace/theme/textmate');
                } catch (e2) {
                    // Keep CodeRunner theme.
                }
            }
            aceEditor.renderer.setShowGutter(true);
            try {
                aceEditor.setShowFoldWidgets(true);
            } catch (e3) {
                // older Ace
            }
            syncGutter(aceEditor);
        } catch (e) {
            // Ace not ready.
        }
    };

    /**
     * @param {Element} editorPane
     * @param {Element} [langRoot]
     * @param {Object} [opts]
     * @return {Element}
     */
    const enhance = function(editorPane, langRoot, opts) {
        opts = opts || {};
        if (!editorPane || editorPane.querySelector('.ll-nex')) {
            return editorPane && editorPane.querySelector('.ll-nex');
        }

        const prefs = loadPrefs();

        const shell = document.createElement('div');
        shell.className = 'll-nex' + (prefs.theme === 'dark' ? ' ll-nex--dark' : ' ll-nex--light');

        const stage = document.createElement('div');
        stage.className = 'll-nex__stage';

        while (editorPane.firstChild) {
            stage.appendChild(editorPane.firstChild);
        }

        const status = document.createElement('div');
        status.className = 'll-nex__status';
        status.innerHTML =
            '<div class="ll-nex__status-left">' +
                '<span class="ll-nex__stat" data-nex="cursor">Ln 1 : Col 1</span>' +
                '<span class="ll-nex__pill" data-nex="lang">' +
                    '<span class="ll-nex__dot ll-nex__dot--blue"></span>' +
                    '<span data-nex="lang-label">Code</span>' +
                '</span>' +
            '</div>' +
            '<div class="ll-nex__status-right">' +
                '<span class="ll-nex__stat" data-nex="spaces">Spaces: 4</span>' +
                '<span class="ll-nex__stat" data-nex="fontsize">14px</span>' +
                '<span class="ll-nex__pill" data-nex="theme-pill">' +
                    '<span class="ll-nex__dot ll-nex__dot--violet"></span>' +
                    '<span data-nex="theme-label">Light</span>' +
                '</span>' +
            '</div>';

        const panel = document.createElement('div');
        panel.className = 'll-nex__settings';
        panel.hidden = true;
        panel.innerHTML =
            '<div class="ll-nex__seg" role="group" aria-label="Theme">' +
                '<button type="button" class="ll-nex__seg-btn" data-theme="dark">☾ Dark</button>' +
                '<button type="button" class="ll-nex__seg-btn is-active" data-theme="light">☀ Light</button>' +
            '</div>' +
            '<div class="ll-nex__field">' +
                '<div class="ll-nex__field-label">Font Size</div>' +
                '<div class="ll-nex__field-row">' +
                    '<input type="range" min="11" max="22" step="1" data-nex="font-range" />' +
                    '<div class="ll-nex__stepper">' +
                        '<button type="button" data-nex="font-dec">−</button>' +
                        '<span data-nex="font-val">14px</span>' +
                        '<button type="button" data-nex="font-inc">+</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="ll-nex__field">' +
                '<div class="ll-nex__field-label">Tab Size</div>' +
                '<div class="ll-nex__field-row">' +
                    '<input type="range" min="2" max="8" step="1" data-nex="tab-range" />' +
                    '<div class="ll-nex__stepper">' +
                        '<button type="button" data-nex="tab-dec">−</button>' +
                        '<span data-nex="tab-val">4 spaces</span>' +
                        '<button type="button" data-nex="tab-inc">+</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        const settingsBtn = document.createElement('button');
        settingsBtn.type = 'button';
        settingsBtn.className = 'll-nex__icon-btn ll-nex__icon-btn--toolbar';
        settingsBtn.title = 'Editor settings';
        settingsBtn.setAttribute('aria-haspopup', 'dialog');
        settingsBtn.setAttribute('aria-expanded', 'false');
        settingsBtn.innerHTML = '<span aria-hidden="true">▦</span>';

        shell.appendChild(stage);
        shell.appendChild(status);
        shell.appendChild(panel);
        editorPane.appendChild(shell);

        if (opts.settingsHost) {
            opts.settingsHost.appendChild(settingsBtn);
        }

        const els = {
            cursor: status.querySelector('[data-nex="cursor"]'),
            langLabel: status.querySelector('[data-nex="lang-label"]'),
            spaces: status.querySelector('[data-nex="spaces"]'),
            fontsize: status.querySelector('[data-nex="fontsize"]'),
            themeLabel: status.querySelector('[data-nex="theme-label"]'),
            fontRange: panel.querySelector('[data-nex="font-range"]'),
            fontVal: panel.querySelector('[data-nex="font-val"]'),
            tabRange: panel.querySelector('[data-nex="tab-range"]'),
            tabVal: panel.querySelector('[data-nex="tab-val"]')
        };

        const readLangLabel = function() {
            if (!langRoot) {
                return '';
            }
            const btn = langRoot.querySelector('.ll-lang__btn');
            if (btn && btn.title) {
                return btn.title;
            }
            const name = langRoot.querySelector('.ll-lang__name');
            const ver = langRoot.querySelector('.ll-lang__ver');
            const n = name ? name.textContent.trim() : '';
            const v = ver ? ver.textContent.trim() : '';
            if (n && v) {
                return n + ' ' + v;
            }
            return n || v;
        };

        const syncStatus = function() {
            els.spaces.textContent = 'Spaces: ' + prefs.tabSize;
            els.fontsize.textContent = prefs.fontSize + 'px';
            els.themeLabel.textContent = prefs.theme === 'dark' ? 'Dark' : 'Light';
            els.fontRange.value = String(prefs.fontSize);
            els.fontVal.textContent = prefs.fontSize + 'px';
            els.tabRange.value = String(prefs.tabSize);
            els.tabVal.textContent = prefs.tabSize + ' spaces';
            panel.querySelectorAll('.ll-nex__seg-btn').forEach(function(btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-theme') === prefs.theme);
            });
            const label = readLangLabel();
            if (label) {
                els.langLabel.textContent = label;
            }
        };

        let aceEditor = null;

        const refresh = function() {
            applyPrefs(prefs, aceEditor, shell);
            forceAceFill(stage, aceEditor);
            syncStatus();
            savePrefs(prefs);
        };

        const bindAce = function() {
            aceEditor = findAce(stage);
            if (!aceEditor) {
                forceAceFill(stage, null);
                return false;
            }
            forceAceFill(stage, aceEditor);
            applyPrefs(prefs, aceEditor, shell);

            const updateCursor = function() {
                try {
                    const pos = aceEditor.getCursorPosition();
                    els.cursor.textContent = 'Ln ' + (pos.row + 1) + ' : Col ' + (pos.column + 1);
                } catch (e) {
                    // ignore
                }
            };
            try {
                aceEditor.selection.off('changeCursor', updateCursor);
            } catch (e0) {
                // ignore
            }
            aceEditor.selection.on('changeCursor', updateCursor);
            aceEditor.session.on('change', updateCursor);
            updateCursor();
            return true;
        };

        if (!bindAce()) {
            [80, 250, 600, 1200, 2500, 4000].forEach(function(ms) {
                window.setTimeout(bindAce, ms);
            });
        }

        const onResize = function() {
            forceAceFill(stage, aceEditor || findAce(stage));
        };
        window.addEventListener('resize', onResize);
        if (window.ResizeObserver) {
            try {
                const ro = new ResizeObserver(function() {
                    onResize();
                });
                ro.observe(shell);
                ro.observe(stage);
            } catch (e) {
                // ignore
            }
        }

        if (langRoot) {
            langRoot.addEventListener('click', function() {
                window.setTimeout(syncStatus, 50);
            });
            const sel = langRoot.querySelector('select');
            if (sel) {
                sel.addEventListener('change', function() {
                    window.setTimeout(function() {
                        syncStatus();
                        bindAce();
                    }, 80);
                });
            }
        }

        const openSettings = function() {
            panel.hidden = false;
            settingsBtn.setAttribute('aria-expanded', 'true');
            shell.classList.add('is-settings-open');
        };
        const closeSettings = function() {
            panel.hidden = true;
            settingsBtn.setAttribute('aria-expanded', 'false');
            shell.classList.remove('is-settings-open');
        };

        settingsBtn.addEventListener('click', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            if (panel.hidden) {
                openSettings();
            } else {
                closeSettings();
            }
        });

        document.addEventListener('click', function(ev) {
            if (!panel.contains(ev.target) && ev.target !== settingsBtn && !settingsBtn.contains(ev.target)) {
                closeSettings();
            }
        });

        panel.querySelectorAll('.ll-nex__seg-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                prefs.theme = btn.getAttribute('data-theme') || 'light';
                refresh();
            });
        });

        const bump = function(key, delta, min, max) {
            prefs[key] = Math.max(min, Math.min(max, prefs[key] + delta));
            refresh();
        };

        els.fontRange.addEventListener('input', function() {
            prefs.fontSize = parseInt(els.fontRange.value, 10) || 14;
            refresh();
        });
        els.tabRange.addEventListener('input', function() {
            prefs.tabSize = parseInt(els.tabRange.value, 10) || 4;
            refresh();
        });
        panel.querySelector('[data-nex="font-dec"]').addEventListener('click', function() {
            bump('fontSize', -1, 11, 22);
        });
        panel.querySelector('[data-nex="font-inc"]').addEventListener('click', function() {
            bump('fontSize', 1, 11, 22);
        });
        panel.querySelector('[data-nex="tab-dec"]').addEventListener('click', function() {
            bump('tabSize', -1, 2, 8);
        });
        panel.querySelector('[data-nex="tab-inc"]').addEventListener('click', function() {
            bump('tabSize', 1, 2, 8);
        });

        syncStatus();
        window.setTimeout(onResize, 200);
        return shell;
    };

    return {
        enhance: enhance,
        forceAceFill: forceAceFill
    };
});
