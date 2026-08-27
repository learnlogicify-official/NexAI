/**
 * Soft-navigate quiz attempts: Run / Submit / next / previous / question nav
 * without a full page refresh. Shows scoped loaders while processattempt runs.
 *
 * @module     local_llassessment/soft_nav
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_llassessment/split_pane', 'local_llassessment/coderunner_layout',
    'local_llassessment/sample_tests', 'local_llassessment/navigator', 'local_llassessment/chrome',
    'local_llassessment/mcq'],
function(SplitPane, CodeRunnerLayout, SampleTests, Navigator, Chrome, Mcq) {

    let busy = false;
    let lastSubmitter = null;
    let bound = false;

    const loaderEl = function() {
        let el = document.getElementById('ll-arena-loader');
        if (el) {
            return el;
        }
        el = document.createElement('div');
        el.id = 'll-arena-loader';
        el.className = 'll-arena-loader';
        el.hidden = true;
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('aria-busy', 'false');
        el.innerHTML =
            '<div class="ll-arena-loader__backdrop"></div>' +
            '<div class="ll-arena-loader__card" role="status">' +
                '<div class="ll-arena-loader__spinner" aria-hidden="true"></div>' +
                '<div class="ll-arena-loader__text">Loading…</div>' +
            '</div>';
        const arena = document.getElementById('ll-arena') || document.body;
        arena.appendChild(el);
        return el;
    };

    const showLoader = function(message) {
        const el = loaderEl();
        const text = el.querySelector('.ll-arena-loader__text');
        if (text) {
            text.textContent = message || 'Loading…';
        }
        el.hidden = false;
        el.setAttribute('aria-busy', 'true');
        document.body.classList.add('ll-arena-loading');
    };

    const hideLoader = function() {
        const el = document.getElementById('ll-arena-loader');
        if (el) {
            el.hidden = true;
            el.setAttribute('aria-busy', 'false');
        }
        document.body.classList.remove('ll-arena-loading');
    };

    const loaderMessageFor = function(btn) {
        if (!btn) {
            return 'Loading…';
        }
        const key = ((btn.name || '') + ' ' + (btn.value || '') + ' ' + (btn.textContent || '')
            + ' ' + (btn.className || '')).toLowerCase();
        if (/precheck|ll-cr-btn--run|▶|\brun\b/.test(key) && !/submit/.test(key)) {
            return 'Running sample tests…';
        }
        if ((/check/.test(key) && !/precheck/.test(key)) || /ll-cr-btn--submit|✈|\bsubmit\b/.test(key)) {
            return 'Submitting answer…';
        }
        if (/previous/.test(key)) {
            return 'Loading previous question…';
        }
        if (/next/.test(key)) {
            return 'Loading next question…';
        }
        return 'Loading question…';
    };

    const coderunnerPanelFor = function(btn, form) {
        const explicit = form && form.getAttribute && form.getAttribute('data-ll-cr-action');
        if (explicit === 'run') {
            return 'sample';
        }
        if (explicit === 'submit') {
            return 'results';
        }
        if (!btn) {
            return '';
        }
        const key = ((btn.name || '') + ' ' + (btn.value || '') + ' ' + (btn.textContent || '')
            + ' ' + (btn.className || '')).toLowerCase();
        if (/precheck|ll-cr-btn--run|▶|\brun\b/.test(key) && !/submit/.test(key)) {
            return 'sample';
        }
        if ((/check/.test(key) && !/precheck/.test(key)) || /ll-cr-btn--submit|✈|\bsubmit\b/.test(key)) {
            return 'results';
        }
        return '';
    };

    const showScopedLoader = function(form, submitter) {
        const message = loaderMessageFor(submitter);
        const panel = coderunnerPanelFor(submitter, form);
        const split = (submitter && submitter.closest && submitter.closest('.ll-arena-split--coderunner'))
            || (form && form.querySelector('.ll-arena-split--coderunner'));
        const panels = split && split._llCrPanels;
        if (panel && panels && typeof panels.setLoading === 'function') {
            if (typeof panels.activate === 'function') {
                panels.activate(panel);
            }
            panels.setLoading(panel, message);
            return {kind: 'panel', panels: panels};
        }
        showLoader(message);
        return {kind: 'global', panels: null};
    };

    const hideScopedLoader = function(state) {
        if (state && state.kind === 'panel' && state.panels && typeof state.panels.clearLoading === 'function') {
            state.panels.clearLoading();
            return;
        }
        hideLoader();
    };

    /**
     * Flush Ace / CodeMirror buffers into underlying textareas before serialize.
     *
     * @param {HTMLFormElement} form
     */
    const syncEditors = function(form) {
        if (!form) {
            return;
        }
        form.querySelectorAll('.ace_editor').forEach(function(aceEl) {
            try {
                if (window.ace) {
                    const ed = window.ace.edit(aceEl);
                    if (ed) {
                        ed.blur();
                        const session = ed.getSession && ed.getSession();
                        const val = ed.getValue ? ed.getValue() : (session && session.getValue && session.getValue());
                        if (typeof val === 'string') {
                            const ta = aceEl.parentElement
                                && aceEl.parentElement.querySelector('textarea');
                            const fallback = form.querySelector('textarea[name*="answer"]');
                            const target = ta || fallback;
                            if (target) {
                                target.value = val;
                            }
                        }
                    }
                }
            } catch (e) {
                // Ignore.
            }
        });
        form.querySelectorAll('.CodeMirror').forEach(function(cmEl) {
            try {
                if (cmEl.CodeMirror && cmEl.CodeMirror.save) {
                    cmEl.CodeMirror.save();
                }
            } catch (e) {
                // Ignore.
            }
        });
    };

    const isAttemptUrl = function(url) {
        return /\/mod\/quiz\/attempt\.php/i.test(url || '');
    };

    const shouldHardNavigate = function(url, doc) {
        if (!url || !isAttemptUrl(url)) {
            return true;
        }
        if (!doc) {
            return true;
        }
        const form = doc.getElementById('responseform')
            || (doc.querySelector('.que') && doc.querySelector('.que').closest('form'));
        if (!form) {
            return true;
        }
        // Summary / finish / access gate pages often still mention attempt in query — require .que.
        if (!doc.querySelector('.que')) {
            return true;
        }
        return false;
    };

    /**
     * Wrap Moodle questions the same way arena.js does on first paint.
     *
     * @param {HTMLFormElement} form
     */
    const prepareForm = function(form) {
        if (!form) {
            return;
        }
        form.id = form.id || 'responseform';
        form.classList.add('ll-arena-responseform');
        Array.prototype.slice.call(form.querySelectorAll('.que')).forEach(function(que) {
            if (que.closest('.ll-arena-question-wrap')) {
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'll-arena-question-wrap';
            const qtypeMatch = (que.className || '').match(/\bque\s+([a-z0-9_]+)/i);
            wrap.setAttribute('data-qtype', qtypeMatch ? qtypeMatch[1].toLowerCase() : 'unknown');
            const idMatch = (que.id || '').match(/(\d+)\s*$/);
            wrap.setAttribute('data-slot', idMatch ? idMatch[1] : '');
            que.parentNode.insertBefore(wrap, que);
            wrap.appendChild(que);
        });
        form.querySelectorAll('.submitbtns').forEach(function(btns) {
            if (!btns.closest('.ll-arena-nav-buttons')) {
                const navWrap = document.createElement('div');
                navWrap.className = 'll-arena-nav-buttons';
                btns.parentNode.insertBefore(navWrap, btns);
                navWrap.appendChild(btns);
            }
        });
    };

    /**
     * Re-run Moodle AMD boot snippets that initialise CodeRunner / filters for the new HTML.
     *
     * @param {Document} doc
     */
    const replayRelevantScripts = function(doc) {
        if (!doc || typeof window.require !== 'function') {
            return;
        }
        const interesting = /filter_|media_videojs|core_form/i;
        const skip = /local_nexproctor\/monitor|local_nexproctor\/preflight|qtype_coderunner|userinterfacewrapper/i;
        Array.prototype.forEach.call(doc.querySelectorAll('script'), function(script) {
            if (script.src) {
                return;
            }
            const text = (script.textContent || '').trim();
            if (!text || !/require\s*\(/.test(text) || !interesting.test(text) || skip.test(text)) {
                return;
            }
            try {
                // Moodle footer AMD calls — safe enough in same-origin attempt HTML.
                // eslint-disable-next-line no-new-func
                Function(text)();
            } catch (e) {
                // Duplicate init or missing module — ignore.
            }
        });
    };

    const enhanceNavigator = function() {
        const sidebar = document.getElementById('ll-arena-sidebar');
        const slot = document.getElementById('ll-arena-sidebar-slot');
        if (!sidebar || !slot) {
            return;
        }
        slot.querySelectorAll(
            '.block-header, .block-header-wrapper, h4.block-header, .card-title'
        ).forEach(function(el) {
            el.remove();
        });
        slot.querySelectorAll('.qn_buttons').forEach(function(grid) {
            grid.classList.add('ll-arena-nav__grid');
        });
        slot.querySelectorAll('.qnbutton').forEach(function(btn) {
            btn.classList.add('ll-arena-qn');
            if (btn.classList.contains('thispage')) {
                btn.classList.add('is-current');
            }
        });
        slot.querySelectorAll('.endtestlink').forEach(function(a) {
            a.style.display = 'none';
        });
        // Rebuild only when Moodle buttons are still outside our chrome.
        // Never delete .ll-nav while it still owns the only copies of .qnbutton.
        if (sidebar.querySelector('.ll-nav') && !slot.querySelector('.qn_buttons, .qnbutton:not(.ll-nav__btn)')) {
            Navigator.refresh(sidebar);
            return;
        }
        const old = sidebar.querySelector('.ll-nav');
        if (old && slot.querySelector('.qnbutton:not(.ll-nav__btn), .qn_buttons .qnbutton')) {
            old.remove();
        }
        Navigator.enhance(sidebar, {
            categoryLabel: 'Programming Challenges'
        });
    };

    const rehydrate = function() {
        SplitPane.init();
        document.querySelectorAll('.ll-arena-split--coderunner').forEach(function(split) {
            try {
                CodeRunnerLayout.enhance(split);
            } catch (e) {
                // Ignore.
            }
        });
        SampleTests.enhance(document.getElementById('ll-arena') || document);
        Mcq.enhance(document.getElementById('ll-arena') || document);
        enhanceNavigator();
        try {
            document.querySelectorAll('.ll-arena-split').forEach(function(split) {
                SplitPane.applySplitPct(split);
            });
            const mobile = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
            if (!mobile) {
                Navigator.applyCollapsed(
                    document.getElementById('ll-arena-sidebar'),
                    Navigator.readCollapsed()
                );
            } else if (typeof window.__llSyncMobileChrome === 'function') {
                window.__llSyncMobileChrome();
            }
            if (mobile && typeof window.__llCloseMobileDrawer === 'function') {
                window.__llCloseMobileDrawer();
            }
        } catch (e) {}
        const scrub = function() {
            document.querySelectorAll('.ll-arena-split__stem').forEach(function(stem) {
                try {
                    SplitPane.scrubFlagsFromStem(stem, stem.closest('.ll-arena-question-wrap'));
                } catch (e) {}
            });
        };
        scrub();
        window.setTimeout(function() {
            document.querySelectorAll('.ll-arena-split--coderunner').forEach(function(split) {
                try {
                    CodeRunnerLayout.enhance(split);
                } catch (e) {}
            });
            SampleTests.enhance(document.getElementById('ll-arena') || document);
            Mcq.enhance(document.getElementById('ll-arena') || document);
            try {
                document.querySelectorAll('.ll-arena-split').forEach(function(split) {
                    SplitPane.applySplitPct(split);
                });
            } catch (e2) {}
            scrub();
            try {
                Chrome.refresh();
            } catch (e) {}
            try {
                window.dispatchEvent(new Event('resize'));
            } catch (e) {}
        }, 250);
        window.setTimeout(scrub, 800);
        try {
            Chrome.refresh();
        } catch (e) {}
    };

    const destroyAce = function(root) {
        const scope = root || document;
        if (!window.ace) {
            return;
        }
        scope.querySelectorAll('.ace_editor').forEach(function(el) {
            try {
                const ed = window.ace.edit(el);
                if (ed && typeof ed.destroy === 'function') {
                    ed.destroy();
                }
            } catch (e) {
                // Ignore.
            }
        });
    };

    const answerTextareas = function(root) {
        return Array.prototype.filter.call(
            (root || document).querySelectorAll('textarea[name*="answer"]'),
            function(ta) {
                if (!ta || ta.classList.contains('ace_text-input')) {
                    return false;
                }
                const wrap = ta.closest('.que.coderunner, .ll-arena-split--coderunner, .ll-arena-question-wrap');
                return !!wrap;
            }
        );
    };

    const aceModeForLang = function(lang) {
        const raw = String(lang || '').toLowerCase().trim();
        const map = {
            python: 'python', python3: 'python', python2: 'python', pypy3: 'python',
            java: 'java', javascript: 'javascript', js: 'javascript', nodejs: 'javascript',
            cpp: 'c_cpp', 'c++': 'c_cpp', c: 'c_cpp',
            csharp: 'csharp', cs: 'csharp', 'c#': 'csharp',
            php: 'php', ruby: 'ruby', go: 'golang', rust: 'rust',
            kotlin: 'kotlin', swift: 'swift', typescript: 'typescript',
            sql: 'sql', matlab: 'matlab', octave: 'matlab'
        };
        if (map[raw]) {
            return map[raw];
        }
        return raw.replace(/\d+$/, '') || 'text';
    };

    const langFromTextarea = function(ta) {
        if (!ta) {
            return '';
        }
        // CodeRunner's userinterfacewrapper uses data-lang as the Ace language
        // (overwriting any lang inside data-params). Prefer that first.
        const dataLang = ta.getAttribute('data-lang');
        if (dataLang && String(dataLang).trim()) {
            return String(dataLang).trim();
        }
        try {
            const params = JSON.parse(ta.getAttribute('data-params') || '{}');
            if (params && params.lang) {
                return String(params.lang);
            }
        } catch (e) {}
        const scope = ta.closest('form, .que, .ll-arena-split, .ll-cr-ide') || document;
        const sel = scope.querySelector('select[name*="language"], select[name*="coderunner_language"]');
        if (sel && sel.value) {
            return String(sel.value);
        }
        return '';
    };

    /**
     * Ace mode files load relative to basePath. Soft-nav can run before Ace
     * has inferred it from the script tag — without it, setMode is a no-op.
     *
     * @return {string}
     */
    const ensureAceBasePath = function() {
        if (!window.ace || !window.ace.config) {
            return '';
        }
        let base = '';
        try {
            base = window.ace.config.get('basePath') || '';
        } catch (e0) {}
        if (base) {
            return base;
        }
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src || '';
            let m = src.match(/^(.*\/ace)\/ace(?:\.min)?\.js(?:\?|$)/i);
            if (!m && /coderunner\/ace\/ace\.js/i.test(src)) {
                m = [src, src.replace(/\/ace\.js.*$/i, '')];
            }
            if (m && m[1]) {
                base = m[1];
                break;
            }
        }
        if (base) {
            try {
                window.ace.config.set('basePath', base);
            } catch (e1) {}
        }
        return base;
    };

    const loadAceModule = function(name) {
        return new Promise(function(resolve) {
            let done = false;
            const ok = function(mod) {
                if (done) {
                    return;
                }
                done = true;
                resolve(mod || null);
            };
            window.setTimeout(function() {
                ok(null);
            }, 6000);
            ensureAceBasePath();
            if (!window.ace) {
                ok(null);
                return;
            }
            try {
                const loaded = window.ace.require(name);
                if (loaded) {
                    ok(loaded);
                    return;
                }
            } catch (e0) {}
            try {
                if (window.ace.config && typeof window.ace.config.loadModule === 'function') {
                    window.ace.config.loadModule(name, function(mod) {
                        ok(mod || null);
                    });
                    return;
                }
            } catch (e1) {}
            ok(null);
        });
    };

    const ensureAcePacks = function() {
        ensureAceBasePath();
        return Promise.all([
            loadAceModule('ace/ext/language_tools'),
            loadAceModule('ace/ext/modelist')
        ]);
    };

    const warmAceLanguage = function(lang) {
        const modeName = aceModeForLang(lang);
        const jobs = [ensureAcePacks()];
        if (modeName && modeName !== 'text') {
            jobs.push(loadAceModule('ace/mode/' + modeName));
        }
        return Promise.all(jobs);
    };

    const warmAceFromRoot = function(root) {
        ensureAceBasePath();
        const areas = answerTextareas(root || document);
        const jobs = [ensureAcePacks()];
        areas.forEach(function(ta) {
            jobs.push(warmAceLanguage(langFromTextarea(ta)));
        });
        ['python', 'java', 'c_cpp', 'javascript'].forEach(function(mode) {
            jobs.push(loadAceModule('ace/mode/' + mode));
        });
        // Prefetch CodeRunner Ace UI so the first soft-nav is not cold.
        jobs.push(new Promise(function(resolve) {
            if (typeof window.require !== 'function') {
                resolve();
                return;
            }
            try {
                window.require(['qtype_coderunner/ui_ace', 'qtype_coderunner/userinterfacewrapper'],
                    function() {
                        resolve();
                    },
                    function() {
                        resolve();
                    });
            } catch (e) {
                resolve();
            }
        }));
        return Promise.all(jobs);
    };

    const aceSessionModeId = function(ed) {
        try {
            const session = ed && ed.session;
            if (!session) {
                return '';
            }
            return String(session.$modeId || (session.getMode() && session.getMode().$id) || '')
                .toLowerCase();
        } catch (e) {
            return '';
        }
    };

    const aceHasLanguageMode = function(ed) {
        const id = aceSessionModeId(ed);
        return !!id && id !== 'ace/mode/text' && id !== 'ace/mode/plain_text' && id !== 'text';
    };

    const applyAceBehaviours = function(ed) {
        if (!ed) {
            return;
        }
        try {
            ed.setOptions({
                behavioursEnabled: true,
                wrapBehavioursEnabled: true,
                useSoftTabs: true,
                tabSize: 4,
                newLineMode: 'unix'
            });
            ed.setBehavioursEnabled(true);
            ed.commands.bindKeys({'Tab': 'indent', 'Shift-Tab': 'outdent'});
        } catch (e1) {}
        try {
            const tools = window.ace.require('ace/ext/language_tools');
            if (tools) {
                ed.setOptions({
                    enableBasicAutocompletion: true,
                    enableLiveAutocompletion: true
                });
            }
        } catch (e2) {}
    };

    /**
     * Resolve Ace mode path the same way CodeRunner's ui_ace.findMode does.
     *
     * @param {string} lang
     * @return {string} e.g. ace/mode/python
     */
    const resolveAceModePath = function(lang) {
        const modeName = aceModeForLang(lang);
        if (!modeName || modeName === 'text') {
            return '';
        }
        try {
            const modelist = window.ace.require('ace/ext/modelist');
            if (modelist) {
                const nameMap = {octave: 'matlab', nodejs: 'javascript', 'c#': 'cs', pypy3: 'python'};
                let language = String(lang || '').toLowerCase();
                if (nameMap[language]) {
                    language = nameMap[language];
                }
                const candidates = [language, language.replace(/\d+$/, ''), modeName];
                for (let i = 0; i < candidates.length; i++) {
                    const candidate = candidates[i];
                    const filename = 'input.' + candidate;
                    const result = modelist.modesByName[candidate]
                        || modelist.modesByName[candidate.toLowerCase()]
                        || modelist.getModeForPath(filename)
                        || modelist.getModeForPath(filename.toLowerCase());
                    if (result && result.mode && result.name !== 'text') {
                        return result.mode;
                    }
                }
            }
        } catch (e) {}
        return 'ace/mode/' + modeName;
    };

    /**
     * Wait until Ace has loaded the language mode pack (highlighter + bracket behaviours).
     *
     * @param {Object} ed
     * @param {HTMLTextAreaElement|null} ta
     * @return {Promise}
     */
    const ensureAceLanguageReady = function(ed, ta) {
        if (!ed || !window.ace) {
            return Promise.resolve();
        }
        ensureAceBasePath();
        const lang = langFromTextarea(ta);
        const modePath = resolveAceModePath(lang);

        return ensureAcePacks().then(function() {
            applyAceBehaviours(ed);
            if (!modePath) {
                return null;
            }
            return loadAceModule(modePath).then(function(mod) {
                return new Promise(function(resolve) {
                    let done = false;
                    const finish = function() {
                        if (done) {
                            return;
                        }
                        done = true;
                        applyAceBehaviours(ed);
                        try {
                            ed.resize(true);
                        } catch (e3) {}
                        resolve(mod);
                    };
                    window.setTimeout(finish, 4000);
                    try {
                        if (mod && mod.Mode) {
                            ed.session.setMode(new mod.Mode());
                            finish();
                            return;
                        }
                    } catch (e0) {}
                    try {
                        ed.session.setMode(modePath, finish);
                    } catch (e1) {
                        try {
                            ed.session.setMode(modePath);
                        } catch (e2) {}
                        finish();
                    }
                });
            });
        }).catch(function() {
            applyAceBehaviours(ed);
            if (modePath) {
                try {
                    ed.session.setMode(modePath);
                } catch (e) {}
            }
        });
    };

    const resizeAceIn = function(root) {
        if (!root || !window.ace) {
            return;
        }
        root.querySelectorAll('.ace_editor').forEach(function(aceEl) {
            try {
                const ed = window.ace.edit(aceEl);
                if (ed) {
                    ed.resize(true);
                }
            } catch (err) {}
        });
    };

    const bootAceFallback = function(ta) {
        if (!ta || !window.ace) {
            return Promise.resolve();
        }
        ensureAceBasePath();
        const parent = ta.parentElement;
        if (parent && parent.querySelector('.ace_editor')) {
            try {
                return ensureAceLanguageReady(window.ace.edit(parent.querySelector('.ace_editor')), ta);
            } catch (e0) {
                return Promise.resolve();
            }
        }
        const wrap = document.getElementById(ta.id + '_wrapper');
        const hostParent = wrap || parent;
        if (!hostParent) {
            return Promise.resolve();
        }
        const host = document.createElement('div');
        host.className = 'ace_editor';
        host.style.width = '100%';
        host.style.height = '100%';
        host.style.minHeight = '280px';
        hostParent.appendChild(host);
        try {
            const ed = window.ace.edit(host);
            ed.session.setValue(ta.value || '');
            ed.session.on('change', function() {
                ta.value = ed.getValue();
            });
            ta.style.display = 'none';
            return ensureAceLanguageReady(ed, ta);
        } catch (e) {
            host.remove();
            return Promise.resolve();
        }
    };

    const requireAmd = function(modules) {
        return new Promise(function(resolve, reject) {
            if (typeof window.require !== 'function') {
                reject(new Error('no require'));
                return;
            }
            try {
                window.require(modules, function() {
                    resolve(Array.prototype.slice.call(arguments));
                }, function(err) {
                    reject(err || new Error('amd load failed'));
                });
            } catch (e) {
                reject(e);
            }
        });
    };

    const mountCodeRunnerAce = function(root) {
        const areas = answerTextareas(root);
        if (!areas.length) {
            return Promise.resolve();
        }
        areas.forEach(function(ta, i) {
            if (!ta.id) {
                ta.id = 'll-cr-answer-' + Date.now() + '-' + i;
            }
        });

        const langJobs = areas.map(function(ta) {
            return warmAceLanguage(langFromTextarea(ta));
        });

        return Promise.all(langJobs).then(function() {
            return requireAmd(['qtype_coderunner/ui_ace', 'qtype_coderunner/userinterfacewrapper'])
                .catch(function() {
                    return requireAmd(['qtype_coderunner/userinterfacewrapper']).then(function(args) {
                        return [null, args[0]];
                    });
                });
        }).then(function(mods) {
            const ui = mods && (mods[1] || mods[0]);
            if (ui && typeof ui.newUiWrapper === 'function') {
                areas.forEach(function(ta) {
                    try {
                        if (ta.current_ui_wrapper) {
                            return;
                        }
                        ui.newUiWrapper('ace', ta.id);
                    } catch (e) {}
                });
            } else {
                return Promise.all(areas.map(bootAceFallback));
            }

            return new Promise(function(resolve) {
                let settled = false;
                const finish = function() {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    const el = root && root.querySelector('.ace_editor');
                    let ready = Promise.resolve();
                    if (el && window.ace) {
                        try {
                            ready = ensureAceLanguageReady(window.ace.edit(el), areas[0]);
                        } catch (e) {}
                    } else {
                        ready = Promise.all(areas.map(bootAceFallback));
                    }
                    ready.then(function() {
                        resizeAceIn(root);
                        resolve();
                    }, function() {
                        resizeAceIn(root);
                        resolve();
                    });
                };
                const waitForAce = function(n) {
                    const el = root && root.querySelector('.ace_editor');
                    if (el && window.ace) {
                        let ed = null;
                        try {
                            ed = window.ace.edit(el);
                        } catch (eEd) {}
                        // Give CodeRunner's async ui_ace constructor time before finishing.
                        if (ed && (aceHasLanguageMode(ed) || n > 8)) {
                            finish();
                            return;
                        }
                    }
                    // Do not invent a bare editor while CodeRunner is still loading.
                    if (n > 50) {
                        Promise.all(areas.map(bootAceFallback)).then(finish, finish);
                        return;
                    }
                    window.setTimeout(function() {
                        waitForAce(n + 1);
                    }, 100);
                };
                waitForAce(0);
                window.setTimeout(function() {
                    if (!settled) {
                        finish();
                    }
                }, 10000);
            });
        }).catch(function() {
            return Promise.all(areas.map(bootAceFallback));
        });
    };

    const waitForAceThenEnhance = function(root) {
        const areas = answerTextareas(root);
        const el = root && root.querySelector('.ace_editor');
        let ready = Promise.resolve();
        if (el && window.ace) {
            try {
                ready = ensureAceLanguageReady(window.ace.edit(el), areas[0] || null);
            } catch (e) {}
        }
        // Wrap IDE chrome only AFTER language packs are applied — early wrap on
        // the first soft-nav was racing CodeRunner's Ace init.
        return ready.then(function() {
            rehydrate();
            // Reinforce mode after chrome wrap; don't block the loader on this.
            window.setTimeout(function() {
                const aceEl = root && root.querySelector('.ace_editor');
                if (aceEl && window.ace) {
                    try {
                        ensureAceLanguageReady(window.ace.edit(aceEl), answerTextareas(root)[0] || null)
                            .then(function() {
                                resizeAceIn(root);
                            });
                    } catch (e2) {}
                }
                try {
                    window.dispatchEvent(new Event('resize'));
                } catch (e3) {}
            }, 250);
        });
    };

    const sliceAttemptHtml = function(html) {
        if (!html || html.length < 20000) {
            return html;
        }
        let start = html.search(/<form\b[^>]*\bid\s*=\s*["']responseform["']/i);
        if (start < 0) {
            start = html.search(/<div\b[^>]*class="[^"]*\bque\b/i);
        }
        if (start < 0) {
            return html.slice(0, 400000);
        }
        let chunk = html.slice(start, start + 450000);
        const formEnd = chunk.search(/<\/form>/i);
        if (formEnd > 0) {
            chunk = chunk.slice(0, formEnd + 7);
        }
        return chunk;
    };

    const parseAttemptDoc = function(html) {
        const sliced = sliceAttemptHtml(html);
        return new DOMParser().parseFromString(sliced, 'text/html');
    };

    const copyHiddenInputs = function(fromForm, toForm) {
        if (!fromForm || !toForm) {
            return;
        }
        Array.prototype.forEach.call(fromForm.querySelectorAll('input[type="hidden"]'), function(src) {
            const name = src.getAttribute('name');
            if (!name || /answer/i.test(name)) {
                return;
            }
            let dest = null;
            try {
                dest = toForm.querySelector('input[type="hidden"][name="' + CSS.escape(name) + '"]');
            } catch (e) {
                dest = toForm.querySelector('input[type="hidden"][name="' + name.replace(/"/g, '') + '"]');
            }
            if (dest) {
                dest.value = src.value;
            }
        });
    };

    /**
     * Run/Submit on the same question: keep Ace + IDE, only swap results.
     *
     * @param {string} html
     * @param {string} finalUrl
     * @param {string} panel 'sample' | 'results'
     * @return {boolean}
     */
    const patchSameQuestion = function(html, finalUrl, panel) {
        const live = findResponseForm();
        const split = live && live.querySelector('.ll-arena-split--coderunner');
        if (!live || !split || !split._llCrPanels) {
            return false;
        }
        let doc;
        try {
            doc = parseAttemptDoc(html);
        } catch (e) {
            return false;
        }
        let newForm = doc.getElementById('responseform') || doc.querySelector('form');
        if (!newForm) {
            const que = doc.querySelector('.que');
            newForm = que ? que.closest('form') : null;
        }
        const liveQue = live.querySelector('.que');
        const newQue = (newForm && newForm.querySelector('.que')) || doc.querySelector('.que');
        if (!newQue) {
            return false;
        }
        if (liveQue && liveQue.id && newQue.id && liveQue.id !== newQue.id) {
            return false;
        }

        if (newForm) {
            copyHiddenInputs(newForm, live);
        }

        const sels = '.outcome, .specificfeedback, .coderunner-feedback, .coderunner-test-results';
        const roots = [];
        newQue.querySelectorAll(sels).forEach(function(node) {
            if (roots.some(function(p) { return p.contains(node); })) {
                return;
            }
            roots.push(node);
        });
        roots.forEach(function(node) {
            try {
                CodeRunnerLayout.ingest(split, document.importNode(node, true));
            } catch (err) {
                // Keep the editor even if one result block fails to render.
            }
        });
        if (panel === 'sample' || panel === 'results') {
            CodeRunnerLayout.activate(split, panel);
        }
        return true;
    };

    /**
     * Swap workspace + sidebar from a fetched attempt document.
     *
     * @param {string} html
     * @param {string} finalUrl
     * @param {Element|null} [submitter]
     * @return {boolean} false when caller should hard-navigate
     */
    const applyDocument = function(html, finalUrl, submitter) {
        const panel = coderunnerPanelFor(submitter, findResponseForm());
        if (panel) {
            try {
                patchSameQuestion(html, finalUrl, panel);
            } catch (e) {
                // Keep the live editor; results just may not update.
            }
            return true;
        }
        // Question change: parse the full page so the navigator and complete
        // question HTML are present. Slicing is only used for Run/Submit.
        const doc = new DOMParser().parseFromString(html, 'text/html');
        if (shouldHardNavigate(finalUrl, doc)) {
            return false;
        }

        let newForm = doc.getElementById('responseform');
        if (!newForm) {
            const que = doc.querySelector('.que');
            newForm = que ? que.closest('form') : null;
        }
        if (!newForm) {
            return false;
        }

        prepareForm(newForm);
        const importedForm = document.importNode(newForm, true);

        const workspace = document.getElementById('ll-arena-workspace');
        if (!workspace) {
            return false;
        }
        destroyAce(workspace);
        workspace.innerHTML = '';
        workspace.appendChild(importedForm);

        const slot = document.getElementById('ll-arena-sidebar-slot');
        const newNav = doc.getElementById('mod_quiz_navblock');
        if (slot && newNav) {
            slot.innerHTML = '';
            const importedNav = document.importNode(newNav, true);
            importedNav.classList.add('ll-arena-nav-relocated');
            slot.appendChild(importedNav);
        }

        const endLink = doc.querySelector('#mod_quiz_navblock .endtestlink, .othernav .endtestlink, a.endtestlink');
        const finish = document.querySelector('.ll-arena__finish');
        if (finish) {
            let href = endLink ? (endLink.getAttribute('href') || '') : '';
            if (!href || !/[?&]attempt=\d+/i.test(href)) {
                try {
                    const u = new URL(finalUrl || window.location.href, window.location.origin);
                    const attemptid = Number(u.searchParams.get('attempt') || 0);
                    const cmid = Number(u.searchParams.get('cmid') || 0);
                    if (attemptid) {
                        const base = u.pathname.replace(/[^/]+$/, '');
                        const s = new URL(base + 'summary.php', u.origin);
                        s.searchParams.set('attempt', String(attemptid));
                        if (cmid) {
                            s.searchParams.set('cmid', String(cmid));
                        }
                        href = s.toString();
                    }
                } catch (e) {
                    // keep href
                }
            }
            if (href) {
                finish.setAttribute('href', href);
            }
        }

        const titleEl = doc.querySelector('#region-main h1, #page-header h1, .page-header-headings h1, h1');
        const arenaTitle = document.querySelector('.ll-arena__title');
        if (arenaTitle && titleEl) {
            arenaTitle.textContent = titleEl.textContent.trim();
        }

        try {
            window.history.pushState({llSoftNav: true}, '', finalUrl);
        } catch (e) {
            // Ignore.
        }

        replayRelevantScripts(doc);
        SplitPane.init();
        try {
            document.querySelectorAll('.ll-arena-split').forEach(function(split) {
                SplitPane.applySplitPct(split);
            });
        } catch (e) {}
        // Mount Ace + language packs first, then wrap IDE chrome.
        // Callers keep the opaque loader up until this promise resolves so the
        // raw Moodle/CodeRunner UI never flashes through.
        return mountCodeRunnerAce(workspace).then(function() {
            return waitForAceThenEnhance(workspace);
        }).then(function() {
            return true;
        }, function() {
            try {
                rehydrate();
            } catch (e) {}
            return true;
        });
    };

    const hardGo = function(url) {
        hideLoader();
        busy = false;
        window.location.href = url;
    };

    /**
     * @param {HTMLFormElement} form
     * @param {Element|null} submitter
     * @return {Promise<void>}
     */
    const softPost = function(form, submitter) {
        if (busy || !form) {
            return Promise.resolve();
        }
        busy = true;
        const loadingState = showScopedLoader(form, submitter);
        syncEditors(form);

        const action = form.getAttribute('action') || window.location.href;
        const fd = new FormData(form);
        if (submitter && submitter.name) {
            fd.set(submitter.name, submitter.value != null ? submitter.value : '');
        }

        return fetch(action, {
            method: (form.method || 'POST').toUpperCase(),
            body: fd,
            credentials: 'same-origin',
            redirect: 'follow',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        }).then(function(res) {
            const finalUrl = res.url || action;
            if (!res.ok) {
                hardGo(finalUrl);
                return null;
            }
            return res.text().then(function(html) {
                return {html: html, url: finalUrl};
            });
        }).then(function(payload) {
            if (!payload) {
                return;
            }
            let ok = true;
            try {
                ok = applyDocument(payload.html, payload.url, submitter);
            } catch (e) {
                ok = coderunnerPanelFor(submitter, form) ? true : false;
            }
            return Promise.resolve(ok).then(function(result) {
                if (result === false) {
                    hardGo(payload.url);
                    return;
                }
                hideScopedLoader(loadingState);
                busy = false;
                lastSubmitter = null;
            });
        }).catch(function() {
            hideScopedLoader(loadingState);
            busy = false;
            const panel = coderunnerPanelFor(submitter, form);
            if (panel) {
                return;
            }
            try {
                HTMLFormElement.prototype.submit.call(form);
            } catch (e) {
                window.location.reload();
            }
        });
    };

    const softGet = function(url) {
        if (busy) {
            return Promise.resolve();
        }
        busy = true;
        showLoader('Loading question…');
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            redirect: 'follow',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        }).then(function(res) {
            const finalUrl = res.url || url;
            if (!res.ok) {
                hardGo(finalUrl);
                return null;
            }
            return res.text().then(function(html) {
                return {html: html, url: finalUrl};
            });
        }).then(function(payload) {
            if (!payload) {
                return;
            }
            const ok = applyDocument(payload.html, payload.url);
            return Promise.resolve(ok).then(function(result) {
                if (result === false) {
                    hardGo(payload.url);
                    return;
                }
                hideLoader();
                busy = false;
            });
        }).catch(function() {
            hardGo(url);
        });
    };

    const findResponseForm = function() {
        return document.getElementById('responseform')
            || document.querySelector('#ll-arena-workspace form')
            || document.querySelector('form.ll-arena-responseform');
    };

    /**
     * Moodle's M.mod_quiz.nav keeps a YUI Node of the *original* #responseform.
     * After soft-nav that node is detached, so .qnbutton clicks go silent.
     * Drive navigation ourselves against the live form.
     *
     * @param {string|number} pageno
     * @param {string} [hash] e.g. #question-1-2
     */
    const navToPage = function(pageno, hash) {
        const form = findResponseForm();
        if (!form || busy) {
            return;
        }
        const following = form.querySelector('#followingpage')
            || form.querySelector('input[name="thispage"]');
        if (following) {
            following.value = String(pageno);
        }
        if (hash) {
            let action = form.getAttribute('action') || window.location.href;
            action = action.replace(/#.*$/, '') + hash;
            form.setAttribute('action', action);
        }
        // Do not include next/previous as submitter — server uses followingpage.
        softPost(form, null);
    };

    /**
     * @param {Element} btn
     * @return {boolean}
     */
    const handleQnButton = function(btn) {
        if (!btn || btn.classList.contains('thispage') || btn.classList.contains('is-current')) {
            return false;
        }
        if (btn.classList.contains('disabled') || btn.getAttribute('aria-disabled') === 'true') {
            return false;
        }
        const href = btn.getAttribute('href') || '';
        if (!href || href === '#') {
            return false;
        }
        const pageMatch = href.match(/[?&]page=(\d+)/);
        const pageno = pageMatch ? pageMatch[1] : '0';
        const qMatch = href.match(/#(question-\d+-\d+|q\d+)/);
        navToPage(pageno, qMatch ? qMatch[0] : '');
        return true;
    };

    const bind = function() {
        if (bound) {
            return;
        }
        bound = true;

        // Remember which submit control started the post (FormData omits others).
        document.addEventListener('click', function(ev) {
            const t = ev.target;
            if (!t || !t.closest) {
                return;
            }
            // Finish assessment stays a hard navigation.
            if (t.closest('.ll-arena__finish, .endtestlink')) {
                return;
            }

            // Own Question Navigator clicks (capture) so Moodle's stale-form handler cannot win.
            const qn = t.closest('a.qnbutton, .ll-nav__btn.qnbutton, a.ll-arena-qn, .ll-nav .qnbutton');
            if (qn && qn.closest('#ll-arena, #mod_quiz_navblock, .ll-nav')) {
                ev.preventDefault();
                ev.stopPropagation();
                if (typeof ev.stopImmediatePropagation === 'function') {
                    ev.stopImmediatePropagation();
                }
                handleQnButton(qn);
                return;
            }

            // Footer Previous/Next proxies (real Moodle controls stay hidden in the form).
            const proxy = t.closest('#ll-arena-footer [data-ll-nav-proxy]');
            if (proxy) {
                ev.preventDefault();
                ev.stopPropagation();
                if (typeof ev.stopImmediatePropagation === 'function') {
                    ev.stopImmediatePropagation();
                }
                if (proxy.disabled || proxy.classList.contains('ll-arena__navbtn--ghost')) {
                    return;
                }
                const which = proxy.getAttribute('data-ll-nav-proxy');
                const form = findResponseForm();
                const real = form && (form.querySelector('[data-ll-nav-source="' + which + '"]')
                    || form.querySelector('input[name="' + which + '"], button[name="' + which + '"]'));
                if (form && real) {
                    softPost(form, real);
                }
                return;
            }

            // Legacy: real submit controls moved into the footer.
            const footBtn = t.closest('#ll-arena-footer input[type="submit"], #ll-arena-footer button[type="submit"]');
            if (footBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                if (typeof ev.stopImmediatePropagation === 'function') {
                    ev.stopImmediatePropagation();
                }
                const form = findResponseForm();
                if (form) {
                    softPost(form, footBtn);
                }
                return;
            }

            const btn = t.closest('input[type="submit"], button[type="submit"], button.btn[name]');
            const form = findResponseForm();
            if (btn && form && form.contains(btn)) {
                lastSubmitter = btn;
            }
        }, true);

        document.addEventListener('submit', function(ev) {
            const form = ev.target;
            if (!form || (form.id !== 'responseform' && !form.classList.contains('ll-arena-responseform'))) {
                return;
            }
            if (busy) {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
            // Time-up / finish must hard-navigate.
            const timeup = form.querySelector('input[name="timeup"]');
            if (timeup && String(timeup.value) === '1') {
                return;
            }
            const submitter = ev.submitter || lastSubmitter;
            const key = submitter
                ? ((submitter.name || '') + ' ' + (submitter.value || '')).toLowerCase()
                : '';
            if (/finishattempt|submitall|timeup/.test(key)) {
                return;
            }

            ev.preventDefault();
            ev.stopPropagation();
            softPost(form, submitter);
        }, true);

        window.addEventListener('popstate', function(ev) {
            if (ev.state && ev.state.llSoftNav) {
                softGet(window.location.href);
            }
        });
    };

    const init = function() {
        if (!document.getElementById('ll-arena')) {
            return;
        }
        loaderEl();
        bind();
        // Prefetch Ace language packs while the first question is open so the
        // first soft-nav question change already has highlighting / brackets.
        window.setTimeout(function() {
            try {
                warmAceFromRoot(document.getElementById('ll-arena-workspace') || document);
            } catch (e) {}
        }, 400);
    };

    return {
        init: init,
        rehydrate: rehydrate,
        softGet: softGet
    };
});
