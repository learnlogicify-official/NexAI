/**
 * CodeRunner IDE chrome — Code / Sample Tests / Results tabs + styled outcomes.
 *
 * @module     local_llassessment/coderunner_layout
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_llassessment/result_view', 'local_llassessment/editor_chrome', 'local_llassessment/sample_tests',
    'local_llassessment/custom_test'],
function(ResultView, EditorChrome, SampleTests, CustomTest) {

    const INTENT_PREFIX = 'll-cr-intent:';

    const intentKey = function(split) {
        const que = split && (split.closest('.que') || split);
        const id = (que && (que.id || que.getAttribute('data-region'))) || '';
        return INTENT_PREFIX + (id || (window.location.pathname + window.location.search));
    };

    const storeIntent = function(split, kind) {
        try {
            window.sessionStorage.setItem(intentKey(split), kind);
        } catch (e) {}
    };

    const consumeIntent = function(split) {
        try {
            const key = intentKey(split);
            const kind = window.sessionStorage.getItem(key);
            if (kind) {
                window.sessionStorage.removeItem(key);
            }
            return kind;
        } catch (e) {
            return null;
        }
    };

    const alreadyWrapped = function(responseHost) {
        return !!responseHost.querySelector('.ll-cr-ide');
    };

    const findEditor = function(root) {
        return root.querySelector('.ace_editor')
            || root.querySelector('textarea[name*="answer"]')
            || root.querySelector('.CodeMirror')
            || root.querySelector('textarea');
    };

    let ingestLock = 0;

    /**
     * @param {Object} panels
     * @param {Element} node
     */
    const ingestFeedback = function(panels, node) {
        if (!node || !panels || ingestLock) {
            return;
        }
        if (node.closest && node.closest('.ll-cr-ide')) {
            return;
        }
        ingestLock++;
        try {
            if (typeof panels.clearLoading === 'function') {
                panels.clearLoading();
            }
            ResultView.render(node, panels);
            const form = panels.sampleHost && panels.sampleHost.closest && panels.sampleHost.closest('form');
            if (form) {
                form.removeAttribute('data-ll-cr-action');
            }
            panels.preferSample = false;
            panels.autoSwitch = false;
        } finally {
            ingestLock--;
        }
    };

    /**
     * Classify a Run/Submit (Precheck/Check) control.
     *
     * @param {Element} btn
     * @return {string|null} 'run' | 'submit' | null
     */
    const classifyAction = function(btn) {
        if (!btn) {
            return null;
        }
        const label = ((btn.name || '') + ' ' + (btn.value || '') + ' ' + (btn.textContent || '')
            + ' ' + (btn.className || '')).toLowerCase();
        // Prefer name/class: values are rewritten to "▶ Run" / "✈ Submit".
        if (/precheck/.test(label) || /\bll-cr-btn--run\b/.test(label)
            || (/▶/.test(label) && /\brun\b/.test(label))
            || (/\brun\b/.test(label) && !/\bruntime\b/.test(label) && !/submit/.test(label))) {
            return 'run';
        }
        if ((/check/.test(label) && !/precheck/.test(label))
            || /\bll-cr-btn--submit\b/.test(label)
            || /✈/.test(label)
            || /\bsubmit\b/.test(label)) {
            return 'submit';
        }
        return null;
    };

    /**
     * Track whether the student clicked Precheck vs Check.
     * Persists intent so a full page reload still opens the right tab.
     *
     * @param {Element} actions
     * @param {Object} panels
     * @param {Element} split
     */
    const bindRunHints = function(actions, panels, split) {
        if (!actions || !panels) {
            return;
        }
        const apply = function(kind) {
            const form = split && split.closest && split.closest('form');
            if (form) {
                form.setAttribute('data-ll-cr-action', kind || '');
            }
            if (kind === 'run') {
                panels.preferSample = true;
                panels.autoSwitch = true;
                storeIntent(split, 'run');
                // Switch immediately so the student sees Sample Tests while waiting.
                if (typeof panels.activate === 'function') {
                    panels.activate('sample');
                }
            } else if (kind === 'submit') {
                panels.preferSample = false;
                panels.autoSwitch = true;
                storeIntent(split, 'submit');
                if (typeof panels.activate === 'function') {
                    panels.activate('results');
                }
            }
        };
        actions.addEventListener('click', function(ev) {
            const t = ev.target;
            if (!t || !t.closest) {
                return;
            }
            const btn = t.closest('input[type="submit"], button, .btn');
            if (!btn || !actions.contains(btn)) {
                return;
            }
            apply(classifyAction(btn));
        }, true);
    };

    /**
     * Parse "Java OpenJDK 13.0.1" / "C (GCC 9.2.0)" into {name, version}.
     *
     * @param {string} text
     * @return {{name: string, version: string}}
     */
    const parseLangLabel = function(text) {
        text = (text || '').replace(/\s+/g, ' ').trim();
        const paren = text.match(/^(.+?)\s*\((.+)\)\s*$/);
        if (paren) {
            return {name: paren[1].trim(), version: paren[2].trim()};
        }
        // "Java OpenJDK 13.0.1" / "Python 3.8.1"
        const parts = text.split(' ');
        if (parts.length >= 2) {
            return {name: parts[0], version: parts.slice(1).join(' ')};
        }
        return {name: text || 'Language', version: ''};
    };

    /**
     * NexEditor-style language picker wrapping a native <select>.
     *
     * @param {HTMLSelectElement} select
     * @return {Element}
     */
    const buildLangPicker = function(select) {
        const root = document.createElement('div');
        root.className = 'll-lang';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'll-lang__btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');

        const btnMain = document.createElement('span');
        btnMain.className = 'll-lang__btn-main';
        const btnName = document.createElement('span');
        btnName.className = 'll-lang__name';
        const btnVer = document.createElement('span');
        btnVer.className = 'll-lang__ver';
        btnMain.appendChild(btnName);
        btnMain.appendChild(document.createTextNode(' '));
        btnMain.appendChild(btnVer);

        const chevron = document.createElement('span');
        chevron.className = 'll-lang__chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.innerHTML = '▾';

        btn.appendChild(btnMain);
        btn.appendChild(chevron);

        const menu = document.createElement('div');
        menu.className = 'll-lang__menu';
        menu.hidden = true;

        const searchWrap = document.createElement('div');
        searchWrap.className = 'll-lang__search-wrap';
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'll-lang__search';
        search.placeholder = 'Search languages...';
        search.setAttribute('autocomplete', 'off');
        searchWrap.appendChild(search);

        const grid = document.createElement('div');
        grid.className = 'll-lang__grid';
        grid.setAttribute('role', 'listbox');

        menu.appendChild(searchWrap);
        menu.appendChild(grid);

        select.classList.add('ll-cr-hidden');
        root.appendChild(btn);
        root.appendChild(menu);
        root.appendChild(select);

        const syncButton = function() {
            const opt = select.options[select.selectedIndex];
            const parsed = parseLangLabel(opt ? opt.text : '');
            btnName.textContent = parsed.name;
            if (parsed.version) {
                btnVer.textContent = '(' + parsed.version + ')';
                btnVer.hidden = false;
            } else {
                btnVer.textContent = '';
                btnVer.hidden = true;
            }
            btn.title = opt ? opt.text : '';
        };

        const renderGrid = function(filter) {
            grid.innerHTML = '';
            const q = (filter || '').toLowerCase();
            Array.prototype.forEach.call(select.options, function(opt, idx) {
                const label = opt.text || opt.value;
                if (q && label.toLowerCase().indexOf(q) === -1) {
                    return;
                }
                const parsed = parseLangLabel(label);
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'll-lang__item';
                if (idx === select.selectedIndex) {
                    item.classList.add('is-selected');
                }
                item.setAttribute('role', 'option');
                item.setAttribute('data-index', String(idx));

                const name = document.createElement('span');
                name.className = 'll-lang__item-name';
                name.textContent = parsed.name;
                const ver = document.createElement('span');
                ver.className = 'll-lang__item-ver';
                ver.textContent = parsed.version || label;

                item.appendChild(name);
                item.appendChild(ver);
                if (idx === select.selectedIndex) {
                    const check = document.createElement('span');
                    check.className = 'll-lang__check';
                    check.setAttribute('aria-hidden', 'true');
                    check.textContent = '✓';
                    item.appendChild(check);
                }

                item.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    select.selectedIndex = idx;
                    // Fire change so CodeRunner multilanguage / Ace switch runs.
                    try {
                        select.dispatchEvent(new Event('change', {bubbles: true}));
                    } catch (e) {
                        const evt = document.createEvent('HTMLEvents');
                        evt.initEvent('change', true, false);
                        select.dispatchEvent(evt);
                    }
                    syncButton();
                    closeMenu();
                });
                grid.appendChild(item);
            });
            if (!grid.childNodes.length) {
                const empty = document.createElement('div');
                empty.className = 'll-lang__empty';
                empty.textContent = 'No languages found';
                grid.appendChild(empty);
            }
        };

        const openMenu = function() {
            menu.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            root.classList.add('is-open');
            renderGrid(search.value);
            window.setTimeout(function() {
                search.focus();
            }, 0);
        };

        const closeMenu = function() {
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            root.classList.remove('is-open');
            search.value = '';
        };

        btn.addEventListener('click', function(ev) {
            ev.preventDefault();
            if (menu.hidden) {
                openMenu();
            } else {
                closeMenu();
            }
        });

        search.addEventListener('input', function() {
            renderGrid(search.value);
        });

        document.addEventListener('click', function(ev) {
            if (!root.contains(ev.target)) {
                closeMenu();
            }
        });

        select.addEventListener('change', syncButton);
        syncButton();
        return root;
    };


    /**
     * @param {Element} split
     * @return {boolean|undefined}
     */
    const enhance = function(split) {
        if (!split.classList.contains('ll-arena-split--coderunner')) {
            return;
        }
        const responseHost = split.querySelector('.ll-arena-split__response');
        const stemHost = split.querySelector('.ll-arena-split__stem');
        if (!responseHost || alreadyWrapped(responseHost)) {
            return watchFeedback(split);
        }

        if (stemHost) {
            Array.prototype.slice.call(responseHost.children).forEach(function(child) {
                if (child.matches && child.matches('.coderunner-examples, .for-example-para, .qtext, .ll-samples-wrap')) {
                    stemHost.appendChild(child);
                }
            });
        }

        const editor = findEditor(responseHost);
        if (!editor) {
            return false;
        }

        const ide = document.createElement('div');
        ide.className = 'll-cr-ide';

        const toolbar = document.createElement('div');
        toolbar.className = 'll-cr-ide__toolbar';

        const title = document.createElement('div');
        title.className = 'll-cr-ide__title';
        title.innerHTML = '<span class="ll-cr-ide__title-icon" aria-hidden="true">{ }</span>' +
            '<span class="ll-cr-ide__title-text">Your Solution</span>';

        const langWrap = document.createElement('div');
        langWrap.className = 'll-cr-ide__lang';
        const actions = document.createElement('div');
        actions.className = 'll-cr-ide__actions';

        const body = document.createElement('div');
        body.className = 'll-cr-ide__body';

        const tabs = document.createElement('div');
        tabs.className = 'll-cr-ide__tabs';
        const makeTab = function(id, label, active) {
            const t = document.createElement('button');
            t.type = 'button';
            t.className = 'll-cr-ide__tab' + (active ? ' is-active' : '');
            t.textContent = label;
            t.setAttribute('data-tab', id);
            return t;
        };
        const tabCode = makeTab('code', 'Code', true);
        const tabCases = makeTab('cases', 'Test Cases', false);
        const tabSample = makeTab('sample', 'Sample Tests', false);
        const tabResults = makeTab('results', 'Hidden Tests', false);
        const tabCustom = makeTab('custom', 'Custom Test', false);
        tabs.appendChild(tabCode);
        tabs.appendChild(tabCases);
        tabs.appendChild(tabSample);
        tabs.appendChild(tabResults);
        tabs.appendChild(tabCustom);

        const editorPane = document.createElement('div');
        editorPane.className = 'll-cr-ide__editor';
        editorPane.setAttribute('data-panel', 'code');

        const panels = document.createElement('div');
        panels.className = 'll-cr-ide__panels';
        panels.hidden = true;

        const panelCases = document.createElement('div');
        panelCases.className = 'll-cr-ide__panel ll-cr-ide__panel--cases';
        panelCases.setAttribute('data-panel', 'cases');
        panelCases.hidden = true;
        panelCases.innerHTML = '<span class="ll-cr-placeholder">Loading sample test cases…</span>';

        const panelSample = document.createElement('div');
        panelSample.className = 'll-cr-ide__panel';
        panelSample.setAttribute('data-panel', 'sample');
        panelSample.hidden = true;
        panelSample.innerHTML = '<span class="ll-cr-placeholder">Run <strong>Precheck</strong> to see sample test results here.</span>';

        const panelResults = document.createElement('div');
        panelResults.className = 'll-cr-ide__panel';
        panelResults.setAttribute('data-panel', 'results');
        panelResults.hidden = true;
        panelResults.innerHTML = '<span class="ll-cr-placeholder">Run <strong>Check</strong> to see hidden test results here.</span>';

        const setPanelLoading = function(name, message) {
            const host = name === 'sample' ? panelSample : (name === 'results' ? panelResults : null);
            if (!host) {
                return;
            }
            host.innerHTML = '';
            const loading = document.createElement('div');
            loading.className = 'll-cr-panel-loader';
            loading.innerHTML =
                '<div class="ll-cr-panel-loader__spinner" aria-hidden="true"></div>' +
                '<div class="ll-cr-panel-loader__text">' + (message || 'Loading…') + '</div>';
            host.appendChild(loading);
        };

        const clearPanelLoading = function() {
            [panelSample, panelResults].forEach(function(host) {
                const loading = host.querySelector('.ll-cr-panel-loader');
                if (loading) {
                    loading.remove();
                }
            });
        };

        const panelCustom = document.createElement('div');
        panelCustom.className = 'll-cr-ide__panel ll-cr-ide__panel--custom';
        panelCustom.setAttribute('data-panel', 'custom');
        panelCustom.hidden = true;
        CustomTest.mount(panelCustom, split);

        panels.appendChild(panelCases);
        panels.appendChild(panelSample);
        panels.appendChild(panelResults);
        panels.appendChild(panelCustom);

        const fillCasesTab = function() {
            const que = split.closest('.que') || document;
            SampleTests.fillHost(panelCases, que);
        };

        const activate = function(name) {
            tabs.querySelectorAll('.ll-cr-ide__tab').forEach(function(t) {
                t.classList.toggle('is-active', t.getAttribute('data-tab') === name);
            });
            const showCode = name === 'code';
            editorPane.hidden = !showCode;
            panels.hidden = showCode;
            panelCases.hidden = name !== 'cases';
            panelSample.hidden = name !== 'sample';
            panelResults.hidden = name !== 'results';
            panelCustom.hidden = name !== 'custom';
            if (name === 'cases') {
                fillCasesTab();
            }
            if (name === 'custom') {
                CustomTest.mount(panelCustom, split);
            }
            if (showCode) {
                window.setTimeout(function() {
                    try { window.dispatchEvent(new Event('resize')); } catch (e) {}
                }, 30);
            }
        };
        tabCode.addEventListener('click', function() { activate('code'); });
        tabCases.addEventListener('click', function() { activate('cases'); });
        tabSample.addEventListener('click', function() { activate('sample'); });
        tabResults.addEventListener('click', function() { activate('results'); });
        tabCustom.addEventListener('click', function() { activate('custom'); });

        responseHost.querySelectorAll('.answerprompt, .penaltyregime').forEach(function(el) {
            el.classList.add('ll-cr-hidden');
        });
        responseHost.querySelectorAll('select').forEach(function(el) {
            if (el.closest('.ll-arena-nav-buttons')) { return; }
            langWrap.appendChild(buildLangPicker(el));
        });
        responseHost.querySelectorAll('.prompt').forEach(function(el) {
            if (!el.querySelector('textarea, .ace_editor')) {
                el.classList.add('ll-cr-hidden');
            }
        });
        responseHost.querySelectorAll('.im-controls').forEach(function(el) {
            if (el.closest('.ll-arena-nav-buttons') || el.closest('.submitbtns')) { return; }
            actions.appendChild(el);
        });

        // Restyle Precheck/Check labels to Run / Submit (keep Moodle name/value).
        actions.querySelectorAll('input[type="submit"], button').forEach(function(btn) {
            const key = ((btn.name || '') + ' ' + (btn.value || '') + ' ' + (btn.textContent || '')).toLowerCase();
            if (/precheck/.test(key)) {
                btn.classList.add('ll-cr-btn', 'll-cr-btn--run');
                if (btn.tagName === 'INPUT') {
                    btn.value = '▶  Run';
                } else {
                    btn.textContent = '▶  Run';
                }
            } else if (/check|submit/.test(key) && !/precheck/.test(key)) {
                btn.classList.add('ll-cr-btn', 'll-cr-btn--submit');
                if (btn.tagName === 'INPUT') {
                    btn.value = '✈  Submit';
                } else {
                    btn.textContent = '✈  Submit';
                }
            }
        });

        toolbar.appendChild(title);
        toolbar.appendChild(langWrap);
        toolbar.appendChild(actions);

        const editorBox = editor.closest('.coderunner-answer, .ablock, .answer') || editor.parentElement;
        if (editorBox && responseHost.contains(editorBox) && !editorBox.matches('form, .ll-cr-ide')) {
            editorPane.appendChild(editorBox);
        } else {
            editorPane.appendChild(editor);
        }

        const panelApi = {
            sampleHost: panelSample,
            resultsHost: panelResults,
            activate: activate,
            preferSample: false,
            autoSwitch: false,
            setLoading: setPanelLoading,
            clearLoading: clearPanelLoading
        };
        bindRunHints(actions, panelApi, split);

        // Survive CodeRunner's full-page Precheck/Check reload.
        const savedIntent = consumeIntent(split);
        if (savedIntent === 'run') {
            panelApi.preferSample = true;
            panelApi.autoSwitch = true;
        } else if (savedIntent === 'submit') {
            panelApi.preferSample = false;
            panelApi.autoSwitch = true;
        }

        const pendingFeedback = [];
        ['.outcome', '.specificfeedback', '.coderunner-feedback', '.coderunner-test-results'].forEach(function(sel) {
            responseHost.querySelectorAll(sel).forEach(function(node) { pendingFeedback.push(node); });
        });

        Array.prototype.slice.call(responseHost.childNodes).forEach(function(node) {
            if (node.nodeType !== 1) {
                if (node.parentNode === responseHost) { responseHost.removeChild(node); }
                return;
            }
            if (node.matches('.qtext, .coderunner-examples, .for-example-para, .ll-samples-wrap')) {
                if (stemHost) { stemHost.appendChild(node); }
                return;
            }
            if (node.classList.contains('ll-cr-hidden') || pendingFeedback.indexOf(node) !== -1) {
                return;
            }
            if (node.matches('.outcome, .specificfeedback, .coderunner-feedback, .coderunner-test-results')) {
                return;
            }
            editorPane.appendChild(node);
        });

        // Wrap Ace after all editor nodes are collected.
        EditorChrome.enhance(editorPane, langWrap.querySelector('.ll-lang') || langWrap, {
            settingsHost: actions
        });

        body.appendChild(tabs);
        body.appendChild(editorPane);
        body.appendChild(panels);
        ide.appendChild(toolbar);
        ide.appendChild(body);
        responseHost.querySelectorAll('.ll-cr-hidden').forEach(function(el) { ide.appendChild(el); });
        responseHost.appendChild(ide);

        const shouldAutoSwitch = !!panelApi.autoSwitch;
        const preferSample = !!panelApi.preferSample;
        pendingFeedback.forEach(function(node) { ingestFeedback(panelApi, node); });
        // After Run/Submit rebuilds, open results; otherwise stay on Code.
        if (shouldAutoSwitch) {
            activate(preferSample ? 'sample' : 'results');
        } else {
            activate('code');
        }
        if (savedIntent === 'run' || savedIntent === 'submit') {
            const hintTab = tabs.querySelector('[data-tab="' + (preferSample ? 'sample' : 'results') + '"]');
            if (hintTab) {
                hintTab.classList.add('has-update');
            }
        }
        window.setTimeout(fillCasesTab, 200);
        window.setTimeout(fillCasesTab, 800);
        split._llCrPanels = panelApi;
        watchFeedback(split);

        window.setTimeout(function() {
            try { window.dispatchEvent(new Event('resize')); } catch (e) {}
            if (window.ace) {
                responseHost.querySelectorAll('.ace_editor').forEach(function(aceEl) {
                    try {
                        const ed = window.ace.edit(aceEl);
                        if (ed) { ed.resize(); }
                    } catch (err) {}
                });
            }
        }, 150);
        return true;
    };

    const watchFeedback = function(split) {
        split._llCrWatching = true;
        return true;
    };

    const init = function() {
        const tryEnhance = function() {
            document.querySelectorAll('.ll-arena-split--coderunner').forEach(enhance);
        };
        tryEnhance();
        [100, 400, 1000, 2000, 3500].forEach(function(ms) {
            window.setTimeout(tryEnhance, ms);
        });
    };

    return {
        init: init,
        enhance: enhance,
        ingest: function(split, node) {
            if (!split || !split._llCrPanels) {
                return;
            }
            ingestFeedback(split._llCrPanels, node);
        },
        activate: function(split, name) {
            if (split && split._llCrPanels && typeof split._llCrPanels.activate === 'function') {
                split._llCrPanels.activate(name);
            }
        }
    };
});
