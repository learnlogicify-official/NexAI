/**
 * NexCourse UI — secondary nav, RemUI duplicate hide, subsection tabs, chrome header.
 *
 * @module format_nexcourse/ui
 */
define(['core/ajax'], function(Ajax) {

    const ICON_RULES = [
        {re: /course\/view\.php/i, key: 'course', labels: ['course']},
        {re: /course\/edit\.php/i, key: 'settings', labels: ['settings', 'edit settings']},
        {re: /user\/index\.php/i, key: 'users', labels: ['participants']},
        {re: /grade\//i, key: 'grades', labels: ['grades']},
        {re: /course\/resources|activities/i, key: 'activities', labels: ['activities']},
        {re: /competency/i, key: 'competencies', labels: ['competencies']},
        {re: /badge/i, key: 'badges', labels: ['badges']},
        {re: /report\//i, key: 'reports', labels: ['reports']},
        {re: /question\//i, key: 'questions', labels: ['question bank', 'questions']},
        {re: /enrol\//i, key: 'enrol', labels: ['enrolment methods', 'enrolled users']},
    ];

    let pendingHeaderHtml = '';

    const detectIcon = function(link) {
        const href = (link.getAttribute('href') || '').toLowerCase();
        const text = (link.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        for (let i = 0; i < ICON_RULES.length; i++) {
            const rule = ICON_RULES[i];
            if (rule.re.test(href)) {
                return rule.key;
            }
            for (let j = 0; j < rule.labels.length; j++) {
                if (text === rule.labels[j]) {
                    return rule.key;
                }
            }
        }
        return 'generic';
    };

    const protectSel = '.nx-header, .nx-section, .nexcourse-layout, .navbar, #nav-drawer, .block, ' +
        '.secondary-navigation, #region-main .course-content, .nx-activity-list, #nx-chrome-mount, ' +
        '.nx-edit-tools';

    const isProtected = function(el) {
        return !!(el && el.closest && el.closest(protectSel));
    };

    const hideEl = function(el) {
        if (!el || isProtected(el)) {
            return;
        }
        if (el.querySelector && el.querySelector('.nx-header, .nx-section, #region-main, .nexcourse, .secondary-navigation, .navbar')) {
            return;
        }
        el.classList.add('nexcourse-theme-header-hidden');
    };

    /**
     * Lift editing controls (e.g. Bulk actions) out of the theme's course header.
     *
     * The theme renders them inside the banner we hide, so they are moved into a
     * toolbar above our own header instead of being lost.
     */
    const rescueEditTools = function() {
        if (!document.body.classList.contains('editing')) {
            return;
        }
        const ours = document.querySelector('.nx-header');
        if (!ours || !ours.parentNode) {
            return;
        }

        const skip = '.nx-edit-tools, .nx-header, .nx-section, .navbar, #nav-drawer, ' +
            '.secondary-navigation, .block, #region-main';
        const found = [];
        Array.prototype.forEach.call(document.querySelectorAll('a, button'), function(el) {
            if (el.closest(skip)) {
                return;
            }
            const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            const attrs = [
                el.getAttribute('data-action') || '',
                el.getAttribute('data-region') || '',
                el.getAttribute('href') || '',
                el.className || '',
            ].join(' ');
            if (/^bulk actions?$/i.test(text) || /bulkedit|bulk-edit|bulkaction/i.test(attrs)) {
                found.push(el);
            }
        });
        if (!found.length) {
            return;
        }

        let bar = document.querySelector('.nx-edit-tools');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'nx-edit-tools';
            ours.parentNode.insertBefore(bar, ours);
        }
        found.forEach(function(el) {
            bar.appendChild(el);
        });
    };

    const hideThemeDuplicates = function() {
        try {
            const ours = document.querySelector('.nx-header');
            const titleEl = ours ? ours.querySelector('.nx-header__title') : null;
            const title = titleEl ? titleEl.textContent.replace(/\s+/g, ' ').trim() : '';
            const ourTop = ours ? ours.getBoundingClientRect().top : null;

            [
                '.edw-course-header',
                '.edwiser-course-header',
                '.remui-course-header',
                '.edw-course-header-stats',
                '.course-header-stats',
                '.header-course-stats',
                '.edw-course-status',
                '.page-context-header',
                '[data-region="edw-course-header"]',
                '[data-region="course-header"]',
            ].forEach(function(sel) {
                document.querySelectorAll(sel).forEach(hideEl);
            });

            Array.prototype.forEach.call(document.querySelectorAll('div'), function(el) {
                if (isProtected(el)) {
                    return;
                }
                if (el.querySelector('.nx-header, .nx-section, #region-main, .secondary-navigation')) {
                    return;
                }
                const text = (el.innerText || '').replace(/\s+/g, ' ');
                if (!/Enrolled Students/i.test(text) || !/Yet to Start/i.test(text)) {
                    return;
                }
                if (!/(Students Completed|In Progress)/i.test(text)) {
                    return;
                }
                const r = el.getBoundingClientRect();
                if (r.height < 45 || r.height > 200 || r.width < 220) {
                    return;
                }
                if (ourTop !== null && r.top > ourTop + 40) {
                    return;
                }
                hideEl(el);
                const prev = el.previousElementSibling;
                if (prev) {
                    const pr = prev.getBoundingClientRect();
                    if (pr.height > 90 && pr.height < 520 && !isProtected(prev)) {
                        hideEl(prev);
                    }
                }
            });

            if (ours && title) {
                Array.prototype.forEach.call(document.querySelectorAll('div, section, header'), function(el) {
                    if (isProtected(el) || el === ours || (el.contains && el.contains(ours))) {
                        return;
                    }
                    if (el.querySelector('.nx-header, .nx-section, #region-main, .secondary-navigation')) {
                        return;
                    }
                    const r = el.getBoundingClientRect();
                    if (ourTop !== null && r.bottom > ourTop - 2) {
                        return;
                    }
                    if (r.height < 100 || r.height > 520 || r.width < 240) {
                        return;
                    }
                    const text = (el.innerText || '').replace(/\s+/g, ' ');
                    if (text.indexOf(title) === -1) {
                        return;
                    }
                    if (/Course Progress/i.test(text) && /\bXP\b/i.test(text) && /Level\s*\d+/i.test(text)) {
                        return;
                    }
                    const style = window.getComputedStyle(el);
                    const bgImg = style.backgroundImage || '';
                    const hasImg = bgImg && bgImg !== 'none';
                    const rgb = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/.exec(style.backgroundColor || '');
                    let dark = false;
                    if (rgb) {
                        dark = (parseInt(rgb[1], 10) + parseInt(rgb[2], 10) + parseInt(rgb[3], 10)) / 3 < 150;
                    }
                    const hasGrad = !!el.querySelector('[style*="linear-gradient"], [style*="background"]');
                    if (dark || hasImg || hasGrad) {
                        hideEl(el);
                    }
                });
            }

            if (ours) {
                let prev = ours.previousElementSibling;
                while (prev) {
                    if (!isProtected(prev) && prev.getBoundingClientRect().height > 48) {
                        hideEl(prev);
                    }
                    prev = prev.previousElementSibling;
                }
            }

            const pageHeader = document.getElementById('page-header');
            if (pageHeader && !pageHeader.querySelector('.navbar, .primary-navigation, .edw-header-top')) {
                Array.prototype.forEach.call(pageHeader.children, hideEl);
                let any = false;
                Array.prototype.forEach.call(pageHeader.children, function(c) {
                    if (!c.classList.contains('nexcourse-theme-header-hidden')
                            && window.getComputedStyle(c).display !== 'none'
                            && c.getBoundingClientRect().height > 2) {
                        any = true;
                    }
                });
                if (!any) {
                    pageHeader.classList.add('nexcourse-space-collapsed');
                }
            }
        } catch (e) {
            if (window.console && console.warn) {
                console.warn('format_nexcourse hideThemeDuplicates', e);
            }
        }
    };

    const enhanceSecondaryNav = function() {
        try {
            document.querySelectorAll('.secondary-navigation').forEach(function(root) {
                root.classList.add('nexcourse-secondary');
                root.querySelectorAll('.nav-link').forEach(function(link) {
                    if (!link.closest('.secondary-navigation') || link.closest('.navbar, .primary-navigation')) {
                        return;
                    }
                    if (link.querySelector('.nexcourse-nav-icon')) {
                        return;
                    }
                    if (link.querySelector('i, .icon, .fa, .edw-icon, svg, img')) {
                        link.classList.add('nexcourse-nav-link');
                        return;
                    }
                    const label = (link.textContent || '').replace(/\s+/g, ' ').trim();
                    if (!label || link.classList.contains('dropdown-toggle')) {
                        return;
                    }
                    const icon = document.createElement('span');
                    icon.className = 'nexcourse-nav-icon nexcourse-nav-icon--' + detectIcon(link);
                    icon.setAttribute('aria-hidden', 'true');
                    link.classList.add('nexcourse-nav-link');
                    link.insertBefore(icon, link.firstChild);
                });
            });
        } catch (e) {
            // ignore
        }
    };

    const normalizeActiveTab = function() {
        try {
            document.querySelectorAll('.secondary-navigation').forEach(function(root) {
                const links = root.querySelectorAll('.nav-link');
                if (!links.length) {
                    return;
                }
                let active = null;
                links.forEach(function(link) {
                    if (link.getAttribute('aria-current') === 'page') {
                        active = link;
                    }
                });
                if (!active) {
                    links.forEach(function(link) {
                        if (link.classList.contains('active')
                                || (link.parentElement && link.parentElement.classList.contains('active'))) {
                            active = link;
                        }
                    });
                }
                links.forEach(function(link) {
                    const isActive = (link === active);
                    link.classList.toggle('active', isActive);
                    if (isActive) {
                        link.setAttribute('aria-current', 'page');
                        if (link.parentElement) {
                            link.parentElement.classList.add('active');
                        }
                    } else {
                        link.removeAttribute('aria-current');
                        if (link.parentElement) {
                            link.parentElement.classList.remove('active');
                        }
                    }
                });
            });
        } catch (e) {
            // ignore
        }
    };

    /**
     * Insert header HTML into the DOM if missing.
     *
     * @param {string} html
     * @return {Element|null}
     */
    const materializeHeader = function(html) {
        let header = document.querySelector('.nx-header');
        if (header) {
            return header;
        }
        const raw = (html || pendingHeaderHtml || '').trim();
        if (!raw) {
            const mount = document.getElementById('nx-chrome-mount');
            if (mount) {
                header = mount.querySelector('.nx-header');
                if (header) {
                    return header;
                }
            }
            return null;
        }
        const wrap = document.createElement('div');
        wrap.innerHTML = raw;
        header = wrap.querySelector('.nx-header') || wrap.firstElementChild;
        if (!header) {
            return null;
        }
        // Park temporarily; placeCourseHeader moves it.
        const mount = document.getElementById('nx-chrome-mount') || document.createElement('div');
        if (!mount.id) {
            mount.id = 'nx-chrome-mount';
            mount.className = 'nx-chrome-mount';
            const secondary = document.querySelector('.secondary-navigation');
            const host = (secondary && secondary.parentNode)
                || document.getElementById('region-main')
                || document.getElementById('page-content')
                || document.body;
            if (secondary && secondary.parentNode) {
                secondary.parentNode.insertBefore(mount, secondary);
            } else {
                host.insertBefore(mount, host.firstChild);
            }
        }
        mount.innerHTML = '';
        mount.appendChild(header);
        return header;
    };

    const placeCourseHeader = function() {
        // Prefer existing header, then mount, then <template id="nx-chrome-src">.
        let header = document.querySelector('.nx-header');
        const mount = document.getElementById('nx-chrome-mount');
        const tpl = document.getElementById('nx-chrome-src');

        if (!header && mount) {
            header = mount.querySelector('.nx-header');
        }
        if (!header && tpl) {
            const node = tpl.content
                ? tpl.content.querySelector('.nx-header')
                : null;
            if (node) {
                header = node.cloneNode(true);
            } else if (tpl.innerHTML && /nx-header/.test(tpl.innerHTML)) {
                const wrap = document.createElement('div');
                wrap.innerHTML = tpl.innerHTML;
                header = wrap.querySelector('.nx-header');
            }
        }
        if (!header && pendingHeaderHtml) {
            header = materializeHeader(pendingHeaderHtml);
        }
        if (!header) {
            return false;
        }

        if (mount && mount.contains(header)) {
            mount.parentNode.insertBefore(header, mount);
        }
        // Remove helpers once header is live.
        if (mount && mount.parentNode) {
            mount.remove();
        }

        document.body.classList.add('format-nexcourse');
        document.body.classList.add('nx-course-chrome');

        const secondary = document.querySelector('.secondary-navigation');
        if (secondary && secondary.parentNode) {
            if (header.nextElementSibling !== secondary) {
                secondary.parentNode.insertBefore(header, secondary);
            }
            header.dataset.placed = '1';
            header.hidden = false;
            header.style.display = '';
            header.style.visibility = 'visible';
            return true;
        }

        // Tabs not ready — park above main content, keep retrying.
        header.dataset.placed = '0';
        const main = document.getElementById('region-main')
            || document.getElementById('page-content')
            || document.querySelector('#topofscroll');
        if (main && main.parentNode) {
            try {
                main.parentNode.insertBefore(header, main);
            } catch (e) {
                // ignore
            }
        }
        return false;
    };

    const initSectionTabs = function() {
        document.querySelectorAll('[data-region="nx-section-tabs"]').forEach(function(root) {
            if (root.dataset.nxTabsReady === '1') {
                return;
            }
            root.dataset.nxTabsReady = '1';

            const activate = function(id) {
                root.querySelectorAll('[data-nx-tab]').forEach(function(tab) {
                    const on = tab.getAttribute('data-nx-tab') === id;
                    tab.classList.toggle('is-active', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                    tab.setAttribute('tabindex', on ? '0' : '-1');
                });
                root.querySelectorAll('[data-nx-panel]').forEach(function(panel) {
                    const on = panel.getAttribute('data-nx-panel') === id;
                    panel.classList.toggle('is-active', on);
                    panel.hidden = !on;
                    panel.style.display = on ? 'block' : 'none';
                });
            };

            const active = root.querySelector('.nx-section__tab.is-active, [data-nx-tab][aria-selected="true"]');
            if (active) {
                activate(active.getAttribute('data-nx-tab'));
            }

            root.addEventListener('click', function(e) {
                const tab = e.target.closest('[data-nx-tab]');
                if (!tab || !root.contains(tab)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                activate(tab.getAttribute('data-nx-tab'));
            });
        });
    };

    const setCompletionState = function(button, completed) {
        const row = button.closest('.nx-activity');
        const label = button.querySelector('.nx-completion-btn__label');
        button.dataset.completed = completed ? '1' : '0';
        button.setAttribute('aria-pressed', completed ? 'true' : 'false');
        button.classList.toggle('is-done', completed);
        if (label) {
            label.textContent = completed ?
                (button.dataset.labelDone || '') : (button.dataset.labelMark || '');
        }
        if (row) {
            row.classList.toggle('is-complete', completed);
        }
    };

    /**
     * Manual completion toggle for quiz rows.
     *
     * Calls the same web service core uses, so the completion state is written
     * to the backend and reflected in reports and course progress.
     */
    const initManualCompletion = function() {
        document.querySelectorAll('[data-action="nx-completion-toggle"]').forEach(function(button) {
            if (button.dataset.nxReady === '1') {
                return;
            }
            button.dataset.nxReady = '1';

            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();

                const cmid = parseInt(button.dataset.cmid || '0', 10);
                if (!cmid || button.disabled) {
                    return;
                }
                const target = button.dataset.completed !== '1';

                button.disabled = true;
                button.classList.add('is-busy');

                const settle = function(state) {
                    document.querySelectorAll(
                        '[data-action="nx-completion-toggle"][data-cmid="' + cmid + '"]'
                    ).forEach(function(peer) {
                        setCompletionState(peer, state);
                        peer.disabled = false;
                        peer.classList.remove('is-busy');
                    });
                };

                Ajax.call([{
                    methodname: 'core_completion_update_activity_completion_status_manually',
                    args: {cmid: cmid, completed: target}
                }])[0].then(function(response) {
                    settle(response && response.status === false ? !target : target);
                    return response;
                }, function() {
                    settle(!target);
                });
            });
        });
    };

    const initModuleAccordions = function() {
        document.querySelectorAll('[data-region="nx-module-acc"]').forEach(function(root) {
            if (root.dataset.nxAccReady === '1') {
                return;
            }
            root.dataset.nxAccReady = '1';

            // Restore last expand/collapse state for this course (localStorage).
            restoreModuleAccordionState(root);

            // When a module opens, refresh submodule tabs; always persist state.
            root.addEventListener('toggle', function(e) {
                const details = e.target;
                if (!details || details.tagName !== 'DETAILS' || !details.classList.contains('nexcourse-acc')) {
                    return;
                }
                saveModuleAccordionState();
                if (!details.open) {
                    return;
                }
                details.querySelectorAll('[data-region="nx-section-tabs"]').forEach(function(tabs) {
                    delete tabs.dataset.nxTabsReady;
                });
                initSectionTabs();
            }, true);
        });
    };

    /**
     * Course id for accordion persistence (per-course localStorage).
     *
     * @return {string}
     */
    const getCourseId = function() {
        if (typeof M !== 'undefined' && M.cfg && M.cfg.courseId) {
            return String(M.cfg.courseId);
        }
        const body = document.body;
        if (body && body.dataset && body.dataset.courseid) {
            return String(body.dataset.courseid);
        }
        const match = ((body && body.className) || '').match(/(?:^|\s)course-(\d+)(?:\s|$)/);
        return match ? match[1] : '0';
    };

    /**
     * @return {string}
     */
    const moduleAccStorageKey = function() {
        return 'format_nexcourse_acc_' + getCourseId();
    };

    /**
     * @return {Object|null} map of pane id → open boolean
     */
    const loadModuleAccordionState = function() {
        try {
            const raw = window.localStorage.getItem(moduleAccStorageKey());
            if (!raw) {
                return null;
            }
            const data = JSON.parse(raw);
            return (data && typeof data === 'object' && !Array.isArray(data)) ? data : null;
        } catch (e) {
            return null;
        }
    };

    /**
     * Persist current open/closed state of all module accordions.
     */
    const saveModuleAccordionState = function() {
        const state = {};
        let count = 0;
        document.querySelectorAll('[data-region="nx-module-acc"] details.nexcourse-acc').forEach(function(details) {
            const id = details.id;
            if (!id) {
                return;
            }
            state[id] = !!details.open;
            count++;
        });
        if (!count) {
            return;
        }
        try {
            window.localStorage.setItem(moduleAccStorageKey(), JSON.stringify(state));
        } catch (e) {
            // private mode / quota — ignore
        }
    };

    /**
     * Apply saved open/closed state over the server default.
     *
     * @param {Element} root
     */
    const restoreModuleAccordionState = function(root) {
        const state = loadModuleAccordionState();
        if (!state || !root) {
            return;
        }
        let applied = false;
        root.querySelectorAll('details.nexcourse-acc').forEach(function(details) {
            const id = details.id;
            if (!id || !Object.prototype.hasOwnProperty.call(state, id)) {
                return;
            }
            details.open = !!state[id];
            applied = true;
        });
        if (applied) {
            initSectionTabs();
        }
    };

    /**
     * Toggle all NexCourse module <details> accordions.
     *
     * @param {boolean} expand
     */
    const setModuleAccordionsOpen = function(expand) {
        document.querySelectorAll('[data-region="nx-module-acc"] details.nexcourse-acc').forEach(function(details) {
            if (expand) {
                if (!details.open) {
                    details.open = true;
                }
            } else if (details.open) {
                details.open = false;
            }
        });
        saveModuleAccordionState();
        if (expand) {
            initSectionTabs();
        }
    };

    /**
     * Wire Expand all / Collapse all on the course page module list.
     */
    const initModuleExpandCollapse = function() {
        document.querySelectorAll('[data-region="nx-acc-tools"]').forEach(function(tools) {
            if (tools.dataset.nxToolsReady === '1') {
                return;
            }
            tools.dataset.nxToolsReady = '1';
            tools.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-action]');
                if (!btn || !tools.contains(btn)) {
                    return;
                }
                const action = btn.getAttribute('data-action');
                if (action === 'nx-expand-all') {
                    e.preventDefault();
                    setModuleAccordionsOpen(true);
                } else if (action === 'nx-collapse-all') {
                    e.preventDefault();
                    setModuleAccordionsOpen(false);
                }
            });
        });
    };

    const runChrome = function() {
        initModuleAccordions();
        initModuleExpandCollapse();
        initSectionTabs();
        try {
            initManualCompletion();
        } catch (e) {
            // ignore
        }
        try {
            placeCourseHeader();
        } catch (e) {
            // ignore
        }
        try {
            rescueEditTools();
        } catch (e) {
            // ignore
        }
        hideThemeDuplicates();
        enhanceSecondaryNav();
        normalizeActiveTab();
    };

    /**
     * @param {Object|string} [config] optional {headerHtml} or raw HTML string
     */
    const init = function(config) {
        if (typeof config === 'string') {
            pendingHeaderHtml = config;
        } else if (config && config.headerHtml) {
            pendingHeaderHtml = config.headerHtml;
        }
        runChrome();
        window.setTimeout(runChrome, 50);
        window.setTimeout(runChrome, 200);
        window.setTimeout(runChrome, 600);
        window.setTimeout(runChrome, 1200);
        window.setTimeout(runChrome, 2200);
        window.setTimeout(runChrome, 4000);

        // RemUI often paints secondary nav late — watch and place header when it appears.
        if (window.MutationObserver && !document.documentElement.dataset.nxChromeObs) {
            document.documentElement.dataset.nxChromeObs = '1';
            const obs = new MutationObserver(function() {
                if (document.querySelector('.secondary-navigation') && !document.querySelector('.nx-header[data-placed="1"]')) {
                    runChrome();
                }
            });
            obs.observe(document.body, {childList: true, subtree: true});
            window.setTimeout(function() {
                try { obs.disconnect(); } catch (e) {}
            }, 12000);
        }
    };

    /**
     * Late injection from PHP footer/top-of-body when header HTML is ready.
     *
     * @param {string} html
     */
    const ensureHeader = function(html) {
        if (html) {
            pendingHeaderHtml = html;
        }
        runChrome();
        window.setTimeout(runChrome, 100);
        window.setTimeout(runChrome, 500);
    };

    /**
     * Read header from <template id="nx-chrome-src"> or #nx-chrome-mount.
     */
    const ensureFromTemplate = function() {
        runChrome();
        window.setTimeout(runChrome, 100);
        window.setTimeout(runChrome, 500);
    };

    return {
        init: init,
        ensureHeader: ensureHeader,
        ensureFromTemplate: ensureFromTemplate
    };
});
