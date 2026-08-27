/**
 * Assessment arena bootstrap for RemUI / any theme.
 * Builds full-bleed shell from classic Moodle quiz attempt DOM when needed.
 *
 * @module     local_llassessment/arena
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_llassessment/split_pane', 'local_llassessment/coderunner_layout', 'local_llassessment/sample_tests',
    'local_llassessment/navigator', 'local_llassessment/soft_nav', 'local_llassessment/chrome',
    'local_llassessment/submit_modal', 'local_llassessment/mcq'],
function(SplitPane, CodeRunnerLayout, SampleTests, Navigator, SoftNav, Chrome, SubmitModal, Mcq) {

    /**
     * Build arena chrome around classic quiz attempt markup.
     *
     * @param {Object} config
     */
    const buildShellFromClassic = function(config) {
        if (document.getElementById('ll-arena')) {
            return;
        }

        // Moodle quiz attempt form — fall back to first form wrapping .que
        let form = document.getElementById('responseform');
        if (!form) {
            const que = document.querySelector('.que');
            form = que ? que.closest('form') : null;
        }
        if (!form) {
            return;
        }

        document.body.classList.add('ll-arena-attempt');
        document.documentElement.classList.add('ll-arena-attempt');

        const titleEl = document.querySelector('#region-main h1, #page-header h1, .page-header-headings h1, h1');
        const quizname = titleEl ? titleEl.textContent.trim() : document.title;

        /**
         * True when this quiz lives in a NexCoursePro course (prefer course shell back).
         *
         * @return {boolean}
         */
        const wantsCourseBack = function() {
            if (config && (config.preferCourseBack === true || config.preferCourseBack === 1
                    || config.preferCourseBack === '1')) {
                return true;
            }
            if (config && config.courseFormat === 'nexcoursepro') {
                return true;
            }
            try {
                if (document.body.classList.contains('format-nexcoursepro')
                        || document.documentElement.classList.contains('format-nexcoursepro')) {
                    return true;
                }
            } catch (e) {
                // Ignore.
            }
            return false;
        };

        /**
         * Resolve THIS quiz's view.php URL only — never grab a random quiz link from the page.
         *
         * @return {string}
         */
        const resolveQuizViewUrl = function() {
            if (config.quizViewUrl) {
                return config.quizViewUrl;
            }

            const pickCmid = function() {
                if (config.cmid) {
                    return String(config.cmid);
                }
                try {
                    const u = new URL(window.location.href);
                    if (u.searchParams.get('cmid')) {
                        return u.searchParams.get('cmid');
                    }
                } catch (e) {
                    // Ignore.
                }
                // Navigator / processattempt links for the current attempt often include cmid.
                const scoped = document.querySelector(
                    '#mod_quiz_navblock a[href*="cmid="], #responseform a[href*="cmid="],' +
                    ' a.qnbutton[href*="cmid="], a.endtestlink[href*="cmid="]'
                );
                if (scoped) {
                    try {
                        const href = new URL(scoped.getAttribute('href'), window.location.origin);
                        if (href.searchParams.get('cmid')) {
                            return href.searchParams.get('cmid');
                        }
                    } catch (e2) {
                        // Ignore.
                    }
                }
                const bodyCm = document.body && (
                    document.body.getAttribute('data-cmid')
                    || (document.body.className || '').match(/\bcmid-(\d+)/)
                );
                if (typeof bodyCm === 'string' && bodyCm) {
                    return bodyCm;
                }
                if (bodyCm && bodyCm[1]) {
                    return bodyCm[1];
                }
                return '';
            };

            const cmid = pickCmid();
            if (cmid) {
                try {
                    document.body.setAttribute('data-ll-cmid', String(cmid));
                } catch (e0) {
                    // Ignore.
                }
                try {
                    const path = (window.location.pathname || '').replace(/attempt\.php.*$/, 'view.php');
                    if (/\/mod\/quiz\/view\.php$/.test(path)) {
                        return path + '?id=' + encodeURIComponent(cmid);
                    }
                } catch (e3) {
                    // Ignore.
                }
                // Fall through to absolute moodle path if pathname rewrite failed.
                const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                if (root) {
                    return root.replace(/\/$/, '') + '/mod/quiz/view.php?id=' + encodeURIComponent(cmid);
                }
            }

            // Last safe DOM fallback: breadcrumb's quiz view link (current activity crumb).
            const crumbs = document.querySelectorAll(
                '.breadcrumb a[href*="/mod/quiz/view.php"], .breadcrumb-item a[href*="/mod/quiz/view.php"],' +
                ' nav[aria-label="breadcrumb"] a[href*="/mod/quiz/view.php"]'
            );
            if (crumbs.length) {
                return crumbs[crumbs.length - 1].getAttribute('href') || '';
            }

            // Tertiary "Back" only if it clearly points at quiz view.
            const tertiary = document.querySelector('.tertiary-navigation a[href*="/mod/quiz/view.php"]');
            if (tertiary) {
                return tertiary.getAttribute('href') || '';
            }

            return '';
        };

        const viewUrl = resolveQuizViewUrl() || '#';
        // NexCoursePro: arena Back returns to the course shell, not quiz view.php.
        const preferCourseBack = wantsCourseBack();
        let courseUrl = (config && config.courseUrl) ? String(config.courseUrl) : '';
        // Build course URL from cmid when PHP did not supply one.
        if (preferCourseBack && !courseUrl) {
            try {
                const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
                const courseMatch = (document.body.className || '').match(/\bcourse-(\d+)\b/);
                const courseId = courseMatch ? courseMatch[1] : '';
                const cmid = config.cmid || (function() {
                    try {
                        return new URL(window.location.href).searchParams.get('cmid') || '';
                    } catch (e) {
                        return '';
                    }
                })();
                if (root && courseId) {
                    courseUrl = root + '/course/view.php?id=' + encodeURIComponent(courseId)
                        + (cmid ? ('&cmid=' + encodeURIComponent(cmid)) : '');
                }
            } catch (e2) {
                // Ignore.
            }
        }
        const backUrl = (preferCourseBack && courseUrl) ? courseUrl : viewUrl;
        const backLabel = preferCourseBack
            ? ((config && config.backToCourseLabel) || 'Back to course')
            : 'Back to quiz';
        const endLink = document.querySelector('#mod_quiz_navblock .endtestlink, .othernav .endtestlink, a.endtestlink');
        /**
         * Always prefer a summary/review URL that includes attempt=.
         * Falling back to quiz view (no attempt) caused Moodle
         * "required parameter (attempt) was missing" after submit.
         */
        const resolveSummaryUrl = function() {
            const fromEnd = endLink ? (endLink.getAttribute('href') || '') : '';
            if (fromEnd && /[?&]attempt=\d+/i.test(fromEnd)) {
                return fromEnd;
            }
            let attemptid = 0;
            let cmid = 0;
            try {
                const u = new URL(window.location.href);
                attemptid = Number(u.searchParams.get('attempt') || 0);
                cmid = Number(u.searchParams.get('cmid') || 0);
            } catch (e) {
                // ignore
            }
            if (!attemptid) {
                const form = document.getElementById('responseform');
                const inp = form && form.querySelector('input[name="attempt"]');
                if (inp && inp.value) {
                    attemptid = Number(inp.value) || 0;
                }
                const cm = form && form.querySelector('input[name="cmid"]');
                if (cm && cm.value && !cmid) {
                    cmid = Number(cm.value) || 0;
                }
            }
            if (attemptid) {
                try {
                    const base = window.location.pathname.replace(/[^/]+$/, '');
                    const u = new URL(base + 'summary.php', window.location.origin);
                    u.searchParams.set('attempt', String(attemptid));
                    if (cmid) {
                        u.searchParams.set('cmid', String(cmid));
                    }
                    return u.toString();
                } catch (e2) {
                    return 'summary.php?attempt=' + encodeURIComponent(attemptid)
                        + (cmid ? ('&cmid=' + encodeURIComponent(cmid)) : '');
                }
            }
            return fromEnd || viewUrl;
        };
        const summaryUrl = resolveSummaryUrl();
        const safeBack = String(backUrl).replace(/"/g, '&quot;');
        const safeBackLabel = String(backLabel).replace(/"/g, '&quot;');
        const safeSummary = String(summaryUrl).replace(/"/g, '&quot;');

        const arena = document.createElement('div');
        arena.id = 'll-arena';
        arena.className = 'll-arena ll-arena--' + (config.colorMode || 'light');
        arena.setAttribute('data-region', 'll-arena');
        // Post-submit landing: course shell with this quiz active (cmid).
        if (courseUrl) {
            arena.setAttribute('data-ll-course-url', courseUrl);
        }
        if (config.cmid) {
            arena.setAttribute('data-ll-cmid', String(config.cmid));
        }
        // Allocated max marks per slot (never earned score).
        if (config.slotMarks && typeof config.slotMarks === 'object') {
            try {
                arena.setAttribute('data-ll-slot-marks', JSON.stringify(config.slotMarks));
            } catch (eMarks) {
                // Ignore.
            }
        }
        if (config.brandColor) {
            arena.style.setProperty('--ll-brand', config.brandColor);
        }

        arena.innerHTML =
            '<header class="ll-arena__topbar" role="banner">' +
                '<button type="button" class="ll-arena__menu-toggle" id="ll-arena-menu-toggle" ' +
                    'aria-label="Open menu" aria-expanded="false" aria-controls="ll-arena-sidebar" title="Menu">' +
                    '<span class="ll-arena__menu-icon" aria-hidden="true"></span>' +
                '</button>' +
                '<div class="ll-arena__topbar-left" id="ll-arena-topbar-left">' +
                    '<a class="ll-arena__back" href="' + safeBack + '" title="' + safeBackLabel +
                        '" aria-label="' + safeBackLabel + '">' +
                        '<span class="ll-arena__back-icon" aria-hidden="true">←</span>' +
                        '<span class="ll-arena__back-text">Back</span>' +
                    '</a>' +
                    '<span class="ll-arena__live" title="Live assessment">' +
                        '<span class="ll-arena__live-dot" aria-hidden="true"></span>LIVE' +
                    '</span>' +
                    '<h1 class="ll-arena__title"></h1>' +
                    '<span class="ll-arena__badge ll-arena__section" data-ll-section-badge hidden></span>' +
                '</div>' +
                '<div class="ll-arena__topbar-right" id="ll-arena-topbar-right">' +
                    '<div class="ll-arena__top-progress" title="Overall progress">' +
                        '<div class="ll-arena__top-progress-track">' +
                            '<div class="ll-arena__top-progress-fill" data-ll-top-progress-fill></div>' +
                        '</div>' +
                        '<span class="ll-arena__top-progress-label" data-ll-top-progress-label>0%</span>' +
                    '</div>' +
                    '<div id="ll-arena-timer-host" class="ll-arena__timer-host"></div>' +
                    '<button type="button" class="ll-arena__theme-toggle" id="ll-arena-theme-toggle" ' +
                        'title="Toggle light / dark mode" aria-label="Toggle light / dark mode">' +
                        '<span class="ll-arena__theme-icon ll-arena__theme-icon--sun" aria-hidden="true">☀</span>' +
                        '<span class="ll-arena__theme-icon ll-arena__theme-icon--moon" aria-hidden="true">☾</span>' +
                    '</button>' +
                    '<a class="ll-arena__finish btn" href="' + safeSummary + '">' +
                        '<span class="ll-arena__finish-icon" aria-hidden="true">✓</span> ' +
                        (config.finishLabel || 'Submit Assessment') +
                    '</a>' +
                '</div>' +
            '</header>' +
            '<div class="ll-arena__body">' +
                '<div class="ll-arena__drawer-backdrop" id="ll-arena-drawer-backdrop" hidden></div>' +
                '<aside class="ll-arena__sidebar" id="ll-arena-sidebar" ' +
                    'aria-label="' + (config.questionsLabel || 'Question Navigator') + '">' +
                    '<div class="ll-arena__drawer-chrome" id="ll-arena-drawer-chrome">' +
                        '<div class="ll-arena__drawer-chrome-bar">' +
                            '<span class="ll-arena__drawer-chrome-title">Menu</span>' +
                            '<button type="button" class="ll-arena__drawer-close" id="ll-arena-drawer-close" ' +
                                'aria-label="Close menu" title="Close">✕</button>' +
                        '</div>' +
                        '<div class="ll-arena__drawer-chrome-slot" id="ll-arena-drawer-chrome-slot"></div>' +
                    '</div>' +
                    '<div id="ll-arena-sidebar-slot" class="ll-arena__sidebar-slot"></div>' +
                '</aside>' +
                '<main class="ll-arena__main" id="ll-arena-main">' +
                    '<div class="ll-arena__workspace" id="ll-arena-workspace"></div>' +
                '</main>' +
            '</div>';

        arena.querySelector('.ll-arena__title').textContent = quizname;

        // Collect notices (access warnings) before moving the form.
        const workspace = arena.querySelector('#ll-arena-workspace');
        const regionMain = document.getElementById('region-main') || form.parentElement;
        const notices = [];
        if (regionMain) {
            regionMain.querySelectorAll('.alert, .notification, .notifyproblem, .notifysuccess').forEach(function(n) {
                if (!form.contains(n)) {
                    notices.push(n);
                }
            });
        }

        document.body.appendChild(arena);

        notices.forEach(function(n) {
            const wrap = document.createElement('div');
            wrap.className = 'll-arena__notices';
            wrap.appendChild(n);
            workspace.parentElement.insertBefore(wrap, workspace);
        });

        // Wrap questions for split-pane, then move the whole form into the arena.
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

        workspace.appendChild(form);
        document.body.classList.add('has-ll-arena');
    };

    /**
     * Move Moodle quiz nav block into the arena sidebar slot.
     */
    const relocateNav = function() {
        const slot = document.getElementById('ll-arena-sidebar-slot');
        if (!slot) {
            return;
        }
        const block = document.getElementById('mod_quiz_navblock');
        if (!block) {
            return;
        }
        const arenaNav = block.querySelector('.ll-arena-nav');
        // Avoid wiping if already relocated into slot.
        if (slot.contains(block) || (arenaNav && slot.contains(arenaNav))) {
            return;
        }
        slot.innerHTML = '';
        if (arenaNav) {
            slot.appendChild(arenaNav);
        } else {
            // Move the whole block so Moodle nav JS keeps working.
            slot.appendChild(block);
            block.classList.add('ll-arena-nav-relocated');
        }
    };

    /**
     * Style classic qnbutton grid inside relocated nav when theme sidebar template was not used.
     */
    const enhanceClassicNavButtons = function() {
        const sidebar = document.getElementById('ll-arena-sidebar');
        const slot = document.getElementById('ll-arena-sidebar-slot');
        if (!sidebar || !slot) {
            return;
        }
        // Remove duplicate / RemUI block titles.
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
            if (btn.classList.contains('flagged')) {
                btn.classList.add('is-flagged');
            }
        });
        slot.querySelectorAll('.endtestlink').forEach(function(a) {
            a.style.display = 'none';
        });

        Navigator.enhance(sidebar, {
            categoryLabel: 'Programming Challenges'
        });
    };

    const relocateTimer = function() {
        const host = document.getElementById('ll-arena-timer-host');
        if (!host) {
            return;
        }
        const wrapper = document.getElementById('quiz-timer-wrapper');
        // Untimed quizzes have no Moodle timer — hide the host completely.
        if (!wrapper) {
            host.classList.add('ll-cr-hidden');
            host.setAttribute('hidden', 'hidden');
            host.innerHTML = '';
            return;
        }
        host.classList.remove('ll-cr-hidden');
        host.removeAttribute('hidden');
        if (!host.contains(wrapper)) {
            host.appendChild(wrapper);
        }
        // Never show Moodle's Hide/Show timer toggle.
        wrapper.querySelectorAll('#toggle-timer, .toggle-timer, button[id*="toggle-timer"]').forEach(function(el) {
            el.classList.add('ll-cr-hidden');
            el.setAttribute('hidden', 'hidden');
            el.style.display = 'none';
        });
        // Ensure the countdown itself is visible.
        const timeLeft = wrapper.querySelector('#quiz-time-left, #quiz-timer');
        if (timeLeft) {
            timeLeft.removeAttribute('hidden');
            timeLeft.style.display = '';
        }
    };

    const initThemeToggle = function(initialMode) {
        const body = document.body;
        const html = document.documentElement;
        const key = 'llassessment-arena-dark';

        const apply = function(dark) {
            body.classList.toggle('ll-arena-dark', dark);
            // RemUI / Bootstrap-friendly dark mode hooks.
            body.classList.toggle('theme-dark', dark);
            body.classList.toggle('darkmode', dark);
            body.classList.toggle('dark-mode', dark);
            html.classList.toggle('theme-dark', dark);
            html.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
            const arena = document.getElementById('ll-arena');
            if (arena) {
                arena.classList.toggle('ll-arena--dark', dark);
                arena.classList.toggle('ll-arena--light', !dark);
            }
            try {
                window.localStorage.setItem(key, dark ? '1' : '0');
                window.localStorage.setItem('darkMode', dark ? 'true' : 'false');
                window.localStorage.setItem('remui_darkmode', dark ? '1' : '0');
            } catch (e) {
                // Ignore.
            }
            const btn = document.getElementById('ll-arena-theme-toggle');
            if (btn) {
                btn.classList.toggle('is-dark', dark);
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                btn.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
            }
        };

        let dark = false;
        try {
            const stored = window.localStorage.getItem(key);
            if (stored !== null) {
                dark = stored === '1';
            } else {
                const remui = window.localStorage.getItem('remui_darkmode')
                    || window.localStorage.getItem('darkMode');
                if (remui === '1' || remui === 'true') {
                    dark = true;
                } else if (body.classList.contains('theme-dark')
                    || body.classList.contains('darkmode')
                    || html.getAttribute('data-bs-theme') === 'dark') {
                    dark = true;
                } else if (initialMode === 'dark') {
                    dark = true;
                } else if (initialMode === 'auto' && window.matchMedia) {
                    dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }
            }
        } catch (e) {
            dark = initialMode === 'dark';
        }
        apply(dark);

        const btn = document.getElementById('ll-arena-theme-toggle');
        if (btn) {
            btn.addEventListener('click', function() {
                const next = !body.classList.contains('ll-arena-dark');
                apply(next);
                // If RemUI exposes its own toggle, nudge it to stay in sync.
                const remuiBtn = document.querySelector(
                    '.js-darkmode, #dark-mode-toggle, [data-action="toggle-darkmode"], .dark-mode-toggle'
                );
                if (remuiBtn && remuiBtn !== btn) {
                    const remuiIsDark = body.classList.contains('theme-dark')
                        || body.classList.contains('darkmode');
                    if (remuiIsDark !== next) {
                        try { remuiBtn.click(); } catch (err) {}
                    }
                }
            });
        }
    };

    const MOBILE_MQ = '(max-width: 900px)';

    /**
     * @return {boolean}
     */
    const isMobileLayout = function() {
        try {
            return window.matchMedia(MOBILE_MQ).matches;
        } catch (e) {
            return window.innerWidth <= 900;
        }
    };

    /**
     * @param {boolean} open
     */
    const setMobileDrawerOpen = function(open) {
        const body = document.body;
        const toggle = document.getElementById('ll-arena-menu-toggle');
        const backdrop = document.getElementById('ll-arena-drawer-backdrop');
        body.classList.toggle('ll-mobile-drawer-open', !!open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            toggle.title = open ? 'Close menu' : 'Menu';
        }
        if (backdrop) {
            backdrop.hidden = !open;
        }
    };

    /**
     * Park desktop header chrome into the left drawer on mobile; restore on desktop.
     */
    const syncMobileChromePlacement = function() {
        const topLeft = document.getElementById('ll-arena-topbar-left');
        const topRight = document.getElementById('ll-arena-topbar-right');
        const drawerSlot = document.getElementById('ll-arena-drawer-chrome-slot');
        const topbar = document.querySelector('#ll-arena .ll-arena__topbar');
        if (!topLeft || !topRight || !drawerSlot || !topbar) {
            return;
        }

        const progress = topRight.querySelector('.ll-arena__top-progress')
            || drawerSlot.querySelector('.ll-arena__top-progress');
        const theme = document.getElementById('ll-arena-theme-toggle');
        const finish = topRight.querySelector('.ll-arena__finish')
            || drawerSlot.querySelector('.ll-arena__finish');
        const timer = document.getElementById('ll-arena-timer-host');

        if (isMobileLayout()) {
            if (topLeft.parentElement !== drawerSlot) {
                drawerSlot.appendChild(topLeft);
            }
            // Keep LIVE on the slim top bar for glanceability.
            const live = topLeft.querySelector('.ll-arena__live')
                || topbar.querySelector(':scope > .ll-arena__live');
            if (live) {
                const menu = document.getElementById('ll-arena-menu-toggle');
                if (menu && menu.nextSibling) {
                    topbar.insertBefore(live, menu.nextSibling);
                } else {
                    topbar.insertBefore(live, topRight);
                }
            }
            if (progress && progress.parentElement !== drawerSlot) {
                drawerSlot.appendChild(progress);
            }
            if (theme && theme.parentElement !== drawerSlot) {
                drawerSlot.appendChild(theme);
            }
            if (finish && finish.parentElement !== drawerSlot) {
                drawerSlot.appendChild(finish);
            }
            if (timer && timer.parentElement !== topRight) {
                topRight.appendChild(timer);
            }
            setMobileDrawerOpen(false);
            document.body.classList.add('ll-mobile-layout');
            document.body.classList.remove('ll-nav-collapsed');
            const menuBtn = document.getElementById('ll-arena-menu-toggle');
            const drawerChromeEl = document.getElementById('ll-arena-drawer-chrome');
            if (menuBtn) {
                menuBtn.removeAttribute('hidden');
                menuBtn.removeAttribute('aria-hidden');
            }
            if (drawerChromeEl) {
                drawerChromeEl.removeAttribute('hidden');
                drawerChromeEl.removeAttribute('aria-hidden');
            }
            const sidebar = document.getElementById('ll-arena-sidebar');
            if (sidebar) {
                sidebar.classList.remove('is-collapsed');
            }
        } else {
            document.body.classList.remove('ll-mobile-layout');
            setMobileDrawerOpen(false);
            // Hard-hide mobile-only chrome on desktop (Moodle 5.2 / RemUI overrides).
            const menu = document.getElementById('ll-arena-menu-toggle');
            const drawerChrome = document.getElementById('ll-arena-drawer-chrome');
            if (menu) {
                menu.setAttribute('hidden', 'hidden');
                menu.setAttribute('aria-hidden', 'true');
            }
            if (drawerChrome) {
                drawerChrome.setAttribute('hidden', 'hidden');
                drawerChrome.setAttribute('aria-hidden', 'true');
            }
            if (topLeft.parentElement !== topbar) {
                const menu = document.getElementById('ll-arena-menu-toggle');
                if (menu && menu.nextSibling) {
                    topbar.insertBefore(topLeft, menu.nextSibling);
                } else {
                    topbar.insertBefore(topLeft, topRight);
                }
            }
            // Restore LIVE into top-left cluster.
            const live = topbar.querySelector(':scope > .ll-arena__live');
            if (live && topLeft && !topLeft.contains(live)) {
                const back = topLeft.querySelector('.ll-arena__back');
                if (back && back.nextSibling) {
                    topLeft.insertBefore(live, back.nextSibling);
                } else {
                    topLeft.insertBefore(live, topLeft.firstChild);
                }
            }
            if (progress && progress.parentElement !== topRight) {
                topRight.insertBefore(progress, timer || null);
            }
            if (timer && timer.parentElement !== topRight) {
                topRight.appendChild(timer);
            }
            if (theme && theme.parentElement !== topRight) {
                topRight.appendChild(theme);
            }
            if (finish && finish.parentElement !== topRight) {
                topRight.appendChild(finish);
            }
            try {
                Navigator.applyCollapsed(
                    document.getElementById('ll-arena-sidebar'),
                    Navigator.readCollapsed()
                );
            } catch (e2) {
                // Ignore.
            }
        }
    };

    /**
     * Wire hamburger / backdrop / close for the mobile left drawer.
     */
    const initMobileDrawer = function() {
        if (window.__llMobileDrawerBound) {
            syncMobileChromePlacement();
            return;
        }
        window.__llMobileDrawerBound = true;

        const toggle = document.getElementById('ll-arena-menu-toggle');
        const backdrop = document.getElementById('ll-arena-drawer-backdrop');
        const closeBtn = document.getElementById('ll-arena-drawer-close');

        const close = function() {
            setMobileDrawerOpen(false);
        };

        if (toggle) {
            toggle.addEventListener('click', function() {
                if (!isMobileLayout()) {
                    return;
                }
                setMobileDrawerOpen(!document.body.classList.contains('ll-mobile-drawer-open'));
            });
        }
        if (backdrop) {
            backdrop.addEventListener('click', close);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }

        document.addEventListener('click', function(ev) {
            if (!isMobileLayout() || !document.body.classList.contains('ll-mobile-drawer-open')) {
                return;
            }
            const t = ev.target;
            if (!t || !t.closest) {
                return;
            }
            if (t.closest('.ll-nav__btn, a.qnbutton, a.ll-arena-qn')) {
                window.setTimeout(close, 50);
            }
        }, true);

        document.addEventListener('keydown', function(ev) {
            if (ev.key === 'Escape' && document.body.classList.contains('ll-mobile-drawer-open')) {
                close();
            }
        });

        try {
            const mq = window.matchMedia(MOBILE_MQ);
            const onChange = function() {
                syncMobileChromePlacement();
            };
            if (mq.addEventListener) {
                mq.addEventListener('change', onChange);
            } else if (mq.addListener) {
                mq.addListener(onChange);
            }
        } catch (e) {
            // Ignore.
        }

        syncMobileChromePlacement();
        window.__llCloseMobileDrawer = close;
        window.__llSyncMobileChrome = syncMobileChromePlacement;
    };

    const applyBrand = function(brandColor) {
        const arena = document.getElementById('ll-arena');
        if (arena && brandColor) {
            arena.style.setProperty('--ll-brand', brandColor);
        }
        document.documentElement.style.setProperty('--ll-brand', brandColor || '#0f766e');
    };

    /**
     * Keep arena Back pointed at the course shell for NexCoursePro.
     *
     * @param {Object} config
     */
    const enforceCourseBack = function(config) {
        config = config || {};
        const prefer = config.preferCourseBack === true || config.preferCourseBack === 1
            || config.preferCourseBack === '1'
            || config.courseFormat === 'nexcoursepro'
            || document.body.classList.contains('format-nexcoursepro')
            || document.documentElement.classList.contains('format-nexcoursepro');
        if (!prefer) {
            return;
        }
        let courseUrl = config.courseUrl ? String(config.courseUrl) : '';
        if (!courseUrl) {
            try {
                const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
                const courseMatch = (document.body.className || '').match(/\bcourse-(\d+)\b/);
                const courseId = courseMatch ? courseMatch[1] : '';
                let cmid = config.cmid || '';
                if (!cmid) {
                    try {
                        cmid = new URL(window.location.href).searchParams.get('cmid') || '';
                    } catch (e0) {
                        cmid = '';
                    }
                }
                if (root && courseId) {
                    courseUrl = root + '/course/view.php?id=' + encodeURIComponent(courseId)
                        + (cmid ? ('&cmid=' + encodeURIComponent(cmid)) : '');
                }
            } catch (e) {
                return;
            }
        }
        if (!courseUrl) {
            return;
        }
        const label = config.backToCourseLabel || 'Back to course';
        document.querySelectorAll('a.ll-arena__back').forEach(function(a) {
            a.setAttribute('href', courseUrl);
            a.setAttribute('title', label);
            a.setAttribute('aria-label', label);
            a.setAttribute('data-nxpro-course-back', '1');
        });
        document.querySelectorAll('.tertiary-navigation a[href*="/mod/quiz/view.php"]').forEach(function(a) {
            a.setAttribute('href', courseUrl);
            a.setAttribute('data-nxpro-course-back', '1');
        });
    };

    /**
     * @param {Object} config
     */
    const init = function(config) {
        config = config || {};
        const run = function() {
            if (window.__llArenaInitDone) {
                enforceCourseBack(config);
                return;
            }
            buildShellFromClassic(config);
            if (!document.getElementById('ll-arena')) {
                return;
            }
            window.__llArenaInitDone = true;
            enforceCourseBack(config);
            applyBrand(config.brandColor || '#0f766e');
            initThemeToggle(config.colorMode || 'light');
            initMobileDrawer();
            if (!isMobileLayout()) {
                try {
                    Navigator.applyCollapsed(
                        document.getElementById('ll-arena-sidebar'),
                        Navigator.readCollapsed()
                    );
                } catch (e) {}
            }
            relocateTimer();
            relocateNav();
            enhanceClassicNavButtons();
            SplitPane.init();
            try {
                document.querySelectorAll('.ll-arena-split').forEach(function(split) {
                    SplitPane.applySplitPct(split);
                });
            } catch (e2) {}
            CodeRunnerLayout.init();
            SampleTests.enhance(document.getElementById('ll-arena') || document);
            Mcq.enhance(document.getElementById('ll-arena') || document);
            SoftNav.init();
            Chrome.refresh();
            SubmitModal.init();
            syncMobileChromePlacement();
            enforceCourseBack(config);
            window.setTimeout(function() {
                CodeRunnerLayout.init();
                SampleTests.enhance(document.getElementById('ll-arena') || document);
                Mcq.enhance(document.getElementById('ll-arena') || document);
                relocateNav();
                enhanceClassicNavButtons();
                try {
                    document.querySelectorAll('.ll-arena-split').forEach(function(split) {
                        SplitPane.applySplitPct(split);
                    });
                    if (!isMobileLayout()) {
                        Navigator.applyCollapsed(
                            document.getElementById('ll-arena-sidebar'),
                            Navigator.readCollapsed()
                        );
                    }
                } catch (e3) {}
                Chrome.refresh();
                syncMobileChromePlacement();
                enforceCourseBack(config);
            }, 400);
            window.setTimeout(function() {
                SampleTests.enhance(document.getElementById('ll-arena') || document);
                Mcq.enhance(document.getElementById('ll-arena') || document);
                enhanceClassicNavButtons();
                Chrome.refresh();
                syncMobileChromePlacement();
                enforceCourseBack(config);
            }, 1200);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
        window.setTimeout(run, 50);
        window.setTimeout(run, 300);
        window.setTimeout(run, 1000);
    };

    return {
        init: init,
        enforceCourseBack: enforceCourseBack
    };
});
