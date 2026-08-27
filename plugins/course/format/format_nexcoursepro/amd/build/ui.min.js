/**
 * NexCoursePro learn shell — chrome, rail, search, in-pane activity swap.
 *
 * @module     format_nexcoursepro/ui
 * @copyright  2026 NexAcademy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'format_nexcoursepro/quizview', 'format_nexcoursepro/editor'],
function(Ajax, Notification, QuizView, Editor) {

    const railKey = (courseId) => 'format_nexcoursepro_rail_' + String(courseId || '0');
    const asideTabKey = (courseId) => 'format_nexcoursepro_aside_tab_' + String(courseId || '0');
    const MOBILE_MQ = window.matchMedia('(max-width: 767px)');

    const isMobileView = () => MOBILE_MQ.matches;

    const hideEl = (el) => {
        if (!el || el.classList.contains('nxpro-theme-hidden')) {
            return;
        }
        if (el.closest && el.closest('.nxpro-learn, .nxpro-core, [data-region="nxpro-learn"]')) {
            return;
        }
        el.classList.add('nxpro-theme-hidden');
        el.style.setProperty('display', 'none', 'important');
        el.style.setProperty('height', '0', 'important');
        el.style.setProperty('margin', '0', 'important');
        el.style.setProperty('padding', '0', 'important');
        el.style.setProperty('overflow', 'hidden', 'important');
    };

    const hideRemuiEnrollmentStrip = () => {
        Array.prototype.forEach.call(document.querySelectorAll('div, section, aside'), function(el) {
            if (el.closest && el.closest('.nxpro-learn, .nxpro-core, .nxpro-stats')) {
                return;
            }
            if (el.querySelector && el.querySelector('.nxpro-learn, .nxpro-stats, #region-main .nxpro-core')) {
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
            if (r.height < 40 || r.height > 220 || r.width < 200) {
                return;
            }
            if (el.children && el.children.length > 12) {
                return;
            }
            hideEl(el);
        });
    };

    const hideBreadcrumbAndUnenrol = () => {
        [
            '#page-navbar',
            '.breadcrumb-nav',
            'nav[aria-label="breadcrumb"]',
            '#page-header .breadcrumb',
            '#page-header .page-header-headings',
        ].forEach((sel) => {
            document.querySelectorAll(sel).forEach(hideEl);
        });

        Array.prototype.forEach.call(document.querySelectorAll('div, section, p, li, form, a'), function(el) {
            if (el.closest && el.closest('.nxpro-learn, .nxpro-core, .navbar, .primary-navigation')) {
                return;
            }
            if (el.querySelector && el.querySelector('.nxpro-learn, .nxpro-core, .navbar')) {
                return;
            }
            const text = (el.innerText || '').replace(/\s+/g, ' ').trim();
            if (!text) {
                return;
            }
            const isUnenrol =
                /Unenroll yourself/i.test(text) ||
                /Unenrol yourself/i.test(text) ||
                /View course enrolment page/i.test(text) ||
                (/enrolment page/i.test(text) && /unenroll|unenrol/i.test(text));
            if (!isUnenrol) {
                return;
            }
            const r = el.getBoundingClientRect();
            if (r.height < 16 || r.height > 200) {
                return;
            }
            let target = el;
            const parent = el.parentElement;
            if (parent && !parent.closest('.nxpro-learn, .navbar, .nxpro-core')) {
                const pr = parent.getBoundingClientRect();
                const ptext = (parent.innerText || '').replace(/\s+/g, ' ');
                if (pr.height > 0 && pr.height <= 200 &&
                    /Unenroll|Unenrol|enrolment page/i.test(ptext) &&
                    !parent.querySelector('.nxpro-learn, .navbar, .nxpro-core')) {
                    target = parent;
                }
            }
            hideEl(target);
        });

        const header = document.getElementById('page-header');
        if (header && !header.querySelector('.nxpro-learn, .nxpro-core')) {
            hideEl(header);
        }
    };

    /**
     * Hide RemUI Full-width / Box Content chrome from live RemUI DOM.
     * Do not hide used regions (e.g. side-bottom with Rating and Review).
     */
    const forceHideEdwiserEl = (el) => {
        if (!el) {
            return;
        }
        // Never hide course body or a region that still contains real blocks.
        if (el.id === 'region-main' || el.id === 'page' || el.id === 'topofscroll' ||
                el.id === 'nxpro-core' || (el.classList && (
                    el.classList.contains('course-content') ||
                    el.classList.contains('nxpro-core') ||
                    el.classList.contains('nxpro')
                ))) {
            return;
        }
        if (el.querySelector && el.querySelector('.course-content, .nxpro-core, [data-for="section"], .block:not(.block-add)')) {
            // Region wrappers that still have course content / real blocks.
            if (el.id === 'region-bottom-blocks' || el.id === 'block-region-side-bottom' ||
                    el.id === 'region-main' || el.classList.contains('main-inner')) {
                return;
            }
        }

        el.classList.add('nxpro-edwiser-chrome', 'nxpro-theme-hidden');
        el.setAttribute('hidden', 'hidden');
        el.setAttribute('aria-hidden', 'true');
        el.style.setProperty('display', 'none', 'important');
        el.style.setProperty('visibility', 'hidden', 'important');
        el.style.setProperty('height', '0', 'important');
        el.style.setProperty('max-height', '0', 'important');
        el.style.setProperty('min-height', '0', 'important');
        el.style.setProperty('margin', '0', 'important');
        el.style.setProperty('padding', '0', 'important');
        el.style.setProperty('overflow', 'hidden', 'important');
        el.style.setProperty('border', '0', 'important');
        el.style.setProperty('opacity', '0', 'important');
        el.style.setProperty('pointer-events', 'none', 'important');
    };

    const restoreProtectedShell = () => {
        document.querySelectorAll(
            '#region-main, #region-main-box, #topofscroll, .main-inner, .nxpro, ' +
            '.nxpro-core, #nxpro-core, .course-content, .secondary-navigation'
        ).forEach((el) => {
            if (!el) {
                return;
            }
            el.classList.remove('nxpro-edwiser-chrome', 'nxpro-theme-hidden');
            el.removeAttribute('hidden');
            el.removeAttribute('aria-hidden');
            ['display', 'visibility', 'height', 'max-height', 'min-height', 'margin',
                'padding', 'overflow', 'border', 'opacity', 'pointer-events'].forEach((prop) => {
                el.style.removeProperty(prop);
            });
        });

        // Restore side-bottom when RemUI marks it as used (has real blocks).
        if (document.body.classList.contains('used-region-side-bottom')) {
            document.querySelectorAll('#block-region-side-bottom, #region-bottom-blocks').forEach((el) => {
                if (!el) {
                    return;
                }
                el.classList.remove('nxpro-edwiser-chrome', 'nxpro-theme-hidden');
                el.removeAttribute('hidden');
                el.removeAttribute('aria-hidden');
                ['display', 'visibility', 'height', 'max-height', 'min-height', 'margin',
                    'padding', 'overflow', 'border', 'opacity', 'pointer-events'].forEach((prop) => {
                    el.style.removeProperty(prop);
                });
            });
        }
    };

    const hideEdwiserLayoutChrome = (cfg) => {
        restoreProtectedShell();

        // RemUI checkered label strips (these remain after aside is emptied).
        document.querySelectorAll(
            '.block-indicator, [id$="-blocks-indicator"], .block-indicator-text-wrapper'
        ).forEach((el) => forceHideEdwiserEl(el));

        // Empty named asides.
        const emptyAsideSels = [
            '#block-region-full-width-top',
            '#block-region-full-width-bottom',
            '#block-region-full-bottom',
            '#block-region-full-top',
            '#block-region-side-top',
            '#block-region-fullwidth-top',
            '#block-region-fullwidth-bottom',
            '[data-blockregion="full-width-top"]',
            '[data-blockregion="full-width-bottom"]',
            '[data-blockregion="full-bottom"]',
            '[data-blockregion="full-top"]',
            '[data-blockregion="side-top"]',
            '[data-blockregion="fullwidth-top"]',
            '[data-blockregion="fullwidth-bottom"]',
        ];
        emptyAsideSels.forEach((sel) => {
            document.querySelectorAll(sel).forEach((el) => forceHideEdwiserEl(el));
        });

        // Hide whole RemUI region sections when body marks them empty.
        if (document.body.classList.contains('empty-region-full-width-top')) {
            forceHideEdwiserEl(document.getElementById('region-fullwidthtop-blocks'));
        }
        if (document.body.classList.contains('empty-region-side-top')) {
            forceHideEdwiserEl(document.getElementById('region-top-blocks'));
        }
        if (document.body.classList.contains('empty-region-full-bottom')) {
            forceHideEdwiserEl(document.getElementById('region-fullwidthbottom-blocks'));
        }
        if (document.body.classList.contains('empty-region-side-bottom')) {
            forceHideEdwiserEl(document.getElementById('region-bottom-blocks'));
            forceHideEdwiserEl(document.getElementById('block-region-side-bottom'));
        }

        // Hide every RemUI "Add a block" control (region buttons + floating menu).
        // Course section "Add content / activity" stays — those are not .block-add.
        document.querySelectorAll(
            '.add_block_button, a.block-add, #add-block-float-menu, .floating-add-block-button'
        ).forEach((el) => forceHideEdwiserEl(el));

        // No right sidebar in edit (or learn) mode.
        if (document.body.classList.contains('nxpro-native-edit') ||
                document.body.classList.contains('nxpro-learn-page')) {
            document.querySelectorAll(
                '.drawer-right, #theme_remui-drawers-blocks, #block-region-side-pre, ' +
                '[data-blockregion="side-pre"], .drawer-toggler.drawer-right-toggle, ' +
                '.newrightsidebaricon-toggle'
            ).forEach((el) => forceHideEdwiserEl(el));
            document.body.classList.remove('show-drawer-right');
            const page = document.getElementById('page');
            if (page) {
                page.classList.remove('show-drawer-right');
            }
        }
    };

    const watchEdwiserLayoutChrome = (cfg) => {
        hideEdwiserLayoutChrome(cfg);
        if (document.documentElement.dataset.nxproEdwWatch === '1') {
            return;
        }
        document.documentElement.dataset.nxproEdwWatch = '1';
        [100, 400, 1000, 2000].forEach((ms) => {
            window.setTimeout(() => hideEdwiserLayoutChrome(cfg), ms);
        });
    };

    const keepEditModeVisible = () => {
        document.querySelectorAll('.editmode-switch-form, .editmode-switch').forEach((el) => {
            el.classList.remove('nxpro-theme-hidden');
            el.style.setProperty('display', 'flex', 'important');
            el.style.setProperty('visibility', 'visible', 'important');
            el.style.setProperty('height', 'auto', 'important');
            el.style.setProperty('max-height', 'none', 'important');
            el.style.setProperty('opacity', '1', 'important');
            el.style.setProperty('pointer-events', 'auto', 'important');
            el.removeAttribute('hidden');
            el.setAttribute('aria-hidden', 'false');
        });
    };

    /**
     * RemUI leaves .usermenu .dropdown.show in the markup; our earlier dropdown
     * CSS made that look open on load. Close it unless the toggle is expanded.
     */
    const closeStrayUserMenu = () => {
        document.querySelectorAll('.usermenu .dropdown').forEach((dd) => {
            const toggle = dd.querySelector('#user-menu-toggle, [data-toggle="dropdown"], [data-bs-toggle="dropdown"]');
            const menu = dd.querySelector('.dropdown-menu, #user-action-menu');
            const expanded = toggle && toggle.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                return;
            }
            dd.classList.remove('show');
            if (menu) {
                menu.classList.remove('show');
                menu.setAttribute('aria-hidden', 'true');
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    };

    /**
     * Restore a tab node that was inside the ⋯ dropdown back to a normal nav link.
     *
     * @param {HTMLElement} navNode
     * @param {boolean} isTabList
     */
    const restoreSecondaryTabLink = (navNode, isTabList) => {
        const link = navNode.querySelector('a.dropdown-item, a.nav-link, a');
        if (!link) {
            return;
        }
        link.classList.remove('dropdown-item');
        link.classList.add('nav-link');
        if (isTabList) {
            link.setAttribute('role', 'tab');
            if (link.classList.contains('active') || link.getAttribute('aria-current') === 'true') {
                link.setAttribute('aria-selected', 'true');
                link.removeAttribute('aria-current');
            }
        } else {
            link.setAttribute('role', 'menuitem');
        }
        navNode.classList.add('nav-item');
    };

    /**
     * Move a tab node into the ⋯ dropdown (Moodle moremenu shape).
     *
     * @param {HTMLElement} navNode
     * @param {HTMLElement} moreMenu
     * @param {HTMLElement|null} moreToggle
     */
    const moveSecondaryTabIntoMore = (navNode, moreMenu, moreToggle) => {
        const link = navNode.querySelector('a.nav-link, a.dropdown-item, a');
        if (!link) {
            return;
        }
        if (link.classList.contains('active') || link.getAttribute('aria-current') === 'true'
                || link.getAttribute('aria-selected') === 'true') {
            if (moreToggle) {
                moreToggle.classList.add('active');
                moreToggle.setAttribute('tabindex', '0');
            }
            link.setAttribute('aria-current', 'true');
            link.removeAttribute('aria-selected');
            link.setAttribute('tabindex', '-1');
        }
        link.classList.remove('nav-link');
        link.classList.add('dropdown-item');
        link.setAttribute('role', 'menuitem');
        navNode.setAttribute('data-forceintomoremenu', 'true');
        moreMenu.appendChild(navNode);
    };

    /**
     * Fill one tab row; leftover tabs go into ⋯. Clears Moodle "always force into more"
     * so as many tabs as fit stay visible first.
     */
    const fitSecondaryNavTabs = () => {
        if (!document.body.classList.contains('nxpro-native-edit')) {
            return;
        }
        document.querySelectorAll('.secondary-navigation').forEach((sec) => {
            const nav = sec.querySelector('.more-nav, .nav-tabs');
            if (!nav) {
                return;
            }
            const isTabList = nav.getAttribute('role') === 'tablist';
            const moreLi = nav.querySelector('.dropdownmoremenu');
            const moreMenu = moreLi && moreLi.querySelector('[data-region="moredropdown"]');
            const moreBtn = moreLi && moreLi.querySelector('[data-region="morebutton"]');
            const moreToggle = moreLi && moreLi.querySelector('.dropdown-toggle, [data-toggle="dropdown"], [data-bs-toggle="dropdown"]');

            // Pull everything into the main row first (including force-into-more items).
            if (moreMenu) {
                Array.from(moreMenu.children).forEach((child) => {
                    child.setAttribute('data-forceintomoremenu', 'false');
                    restoreSecondaryTabLink(child, isTabList);
                    nav.insertBefore(child, moreLi);
                });
            }
            Array.from(nav.children).forEach((child) => {
                if (!child.classList.contains('dropdownmoremenu')) {
                    child.setAttribute('data-forceintomoremenu', 'false');
                }
            });
            if (moreToggle) {
                moreToggle.classList.remove('active');
                moreToggle.setAttribute('tabindex', '-1');
            }

            if (!moreLi || !moreMenu) {
                sec.classList.remove('nxpro-tabs-overflow');
                return;
            }

            moreLi.classList.remove('d-none');
            moreLi.removeAttribute('hidden');
            moreLi.style.removeProperty('display');
            if (moreBtn) {
                moreBtn.classList.remove('d-none');
            }

            const styles = window.getComputedStyle(nav);
            const gap = parseFloat(styles.columnGap || styles.gap) || 0;
            const items = Array.from(nav.children).filter((li) => !li.classList.contains('dropdownmoremenu'));
            const avail = nav.clientWidth;
            let total = 0;
            items.forEach((item, i) => {
                total += item.offsetWidth + (i ? gap : 0);
            });

            // Everything fits on one row — hide ⋯.
            if (total <= avail + 1) {
                moreLi.classList.add('d-none');
                if (moreBtn) {
                    moreBtn.classList.add('d-none');
                }
                sec.classList.remove('nxpro-tabs-overflow');
                return;
            }

            const moreW = moreLi.offsetWidth || 40;
            let used = 0;
            let splitAt = 0;
            for (let i = 0; i < items.length; i++) {
                const w = items[i].offsetWidth + (i ? gap : 0);
                const remainingAfter = items.length - i - 1;
                const reserve = remainingAfter > 0 ? (moreW + gap) : 0;
                if (i > 0 && used + w + reserve > avail + 1) {
                    break;
                }
                // Always keep at least the first tab outside ⋯.
                if (i === 0 || used + w + reserve <= avail + 1) {
                    used += w;
                    splitAt = i + 1;
                } else {
                    break;
                }
            }
            if (splitAt < 1) {
                splitAt = 1;
            }

            items.slice(splitAt).forEach((item) => {
                moveSecondaryTabIntoMore(item, moreMenu, moreToggle);
            });

            moreLi.classList.remove('d-none');
            if (moreBtn) {
                moreBtn.classList.remove('d-none');
            }
            sec.classList.add('nxpro-tabs-overflow');
        });
    };

    /**
     * Late CSS so RemUI / format_nexcourse icon rules cannot win on specificity.
     */
    const ensureSecondaryNavIconKillCss = () => {
        let style = document.getElementById('nxpro-tab-icon-kill');
        if (!style) {
            style = document.createElement('style');
            style.id = 'nxpro-tab-icon-kill';
            (document.head || document.documentElement).appendChild(style);
        }
        style.textContent = [
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation a.nav-link::before,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation a.nav-link::after,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation a.dropdown-item::before,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation a.dropdown-item::after,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nexcourse-nav-icon,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nav-link > .icon,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nav-link > .edw-icon,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nav-link > i,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nav-link > svg,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .nav-link > img,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .dropdown-item > .icon,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .dropdown-item > .edw-icon,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .dropdown-item > i,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .dropdown-item > svg,',
            'html body.format-nexcoursepro.nxpro-native-edit .secondary-navigation .dropdown-item > img {',
            '  content: none !important;',
            '  display: none !important;',
            '  width: 0 !important;',
            '  height: 0 !important;',
            '  margin: 0 !important;',
            '  padding: 0 !important;',
            '  border: 0 !important;',
            '  background: none !important;',
            '  -webkit-mask-image: none !important;',
            '  mask-image: none !important;',
            '  visibility: hidden !important;',
            '  font-size: 0 !important;',
            '}',
        ].join('\n');
    };

    /**
     * Strip RemUI / format_nexcourse / Moodle icons from course tabs (keep ⋯ glyph).
     */
    const stripSecondaryNavTabIcons = () => {
        if (!document.body.classList.contains('nxpro-native-edit')) {
            return;
        }
        ensureSecondaryNavIconKillCss();
        document.querySelectorAll('.secondary-navigation').forEach((sec) => {
            sec.querySelectorAll(
                '.nav-item:not(.dropdownmoremenu) .nav-link, .dropdownmoremenu .dropdown-item'
            ).forEach((link) => {
                link.querySelectorAll(
                    '.nexcourse-nav-icon, .icon, .edw-icon, .fa, .fa-fw, i, svg, img'
                ).forEach((el) => el.remove());
                Array.from(link.children).forEach((child) => {
                    if (child.classList && (child.classList.contains('sr-only') ||
                            child.classList.contains('visually-hidden') ||
                            child.classList.contains('accesshide'))) {
                        return;
                    }
                    const text = (child.textContent || '').replace(/\s+/g, ' ').trim();
                    // Empty decorative spans (edw-icon shells) or leftover icon wrappers.
                    if (!text || child.classList.contains('nexcourse-nav-icon') ||
                            child.classList.contains('edw-icon') ||
                            child.classList.contains('icon')) {
                        child.remove();
                    }
                });
            });
        });
    };

    let secondaryNavFitTimer = 0;
    const scheduleFitSecondaryNavTabs = () => {
        window.clearTimeout(secondaryNavFitTimer);
        secondaryNavFitTimer = window.setTimeout(() => {
            fitSecondaryNavTabs();
            stripSecondaryNavTabIcons();
        }, 50);
    };

    const polishParticipantsPage = () => {
        const isParticipants = document.body.classList.contains('nxpro-participants')
            || document.body.id === 'page-user-index'
            || /\/user\/index\.php/i.test(window.location.pathname || '');
        if (!isParticipants) {
            return;
        }
        document.body.classList.add('nxpro-participants');
        if (!document.body.classList.contains('nxpro-native-edit')) {
            document.body.classList.add('nxpro-native-edit');
        }
    };

    const resolveCourseId = (cfg) => {
        const fromCfg = parseInt((cfg && cfg.courseid) || 0, 10);
        if (fromCfg > 0) {
            return fromCfg;
        }
        if (typeof M !== 'undefined' && M.cfg && M.cfg.courseId) {
            return parseInt(M.cfg.courseId, 10) || 0;
        }
        const m = (document.body.className || '').match(/(?:^|\s)course-(\d+)(?:\s|$)/);
        return m ? parseInt(m[1], 10) : 0;
    };

    const initEnrolRosterIfNeeded = (cfg) => {
        const isParticipants = document.body.classList.contains('nxpro-participants')
            || document.body.id === 'page-user-index'
            || /\/user\/index\.php/i.test(window.location.pathname || '');
        if (!isParticipants) {
            return;
        }
        const courseid = resolveCourseId(cfg);
        if (!courseid) {
            return;
        }
        require(['format_nexcoursepro/enrol_roster'], function(EnrolRoster) {
            try {
                EnrolRoster.init({courseid: courseid});
            } catch (e) { /* ignore */ }
        });
    };

    const polishNativeEditChrome = (cfg) => {
        closeStrayUserMenu();
        polishParticipantsPage();
        fitSecondaryNavTabs();
        stripSecondaryNavTabIcons();
        hideThemeChrome(cfg);
        hideEdwiserLayoutChrome(cfg);
        initEnrolRosterIfNeeded(cfg);
    };

    const hideThemeChrome = (cfg) => {
        if (!document.body.classList.contains('nxpro-learn-page') &&
            !document.body.classList.contains('nxpro-embed') &&
            !document.body.classList.contains('nxpro-native-edit') &&
            !document.body.classList.contains('nxpro-review-fullscreen') &&
            !(cfg && cfg.reviewFullscreen)) {
            return;
        }
        const kill = [
            '.edw-course-header',
            '.edwiser-course-header',
            '.remui-course-header',
            '.edw-course-header-stats',
            '.course-header-stats',
            '.header-course-stats',
            '.edw-course-status',
            '.course-header-container',
            '.page-context-header',
            '[data-region="edw-course-header"]',
            '[data-region="course-header"]',
            '#page-navbar',
            '.breadcrumb-nav',
            '.breadcrumb',
            'nav[aria-label="breadcrumb"]',
            '#page-header',
        ];
        // Full learn / embed chrome (drawers + footer).
        // Native edit: keep Course/Settings tabs; drop RemUI sidebars + Add-a-block chrome.
        if (document.body.classList.contains('nxpro-native-edit')) {
            kill.push(
                '.drawer-toggler',
                '.drawer-right',
                '#theme_remui-drawers-blocks',
                '#block-region-side-pre',
                '[data-blockregion="side-pre"]',
                '#add-block-float-menu',
                '.add_block_button',
                'a.block-add',
                '#wdm_course-stats',
                '.edw-stats-wrapper',
                '.header-enrolbtn-wrapper'
            );
        } else {
            kill.push(
                '.secondary-navigation',
                '.drawer-toggler',
                '#nav-drawer',
                '.drawer-left',
                '.drawer-right',
                '#page-footer',
                'footer#page-footer',
                '.toast-wrapper'
            );
        }
        if (document.body.classList.contains('nxpro-embed')
                || document.body.classList.contains('nxpro-review-fullscreen')
                || (cfg && cfg.reviewFullscreen)) {
            kill.push(
                '.navbar', 'nav.navbar', '.fixed-top', '.primary-navigation', '#nav-drawer',
                '.edw-header', '.edw-header-bar', '.edw-left-drawer', '.edw-right-drawer',
                '.tertiary-navigation', '.activity-navigation', '#block-region-side-pre',
                '#block-region-side-post', '.block-region'
            );
        }
        kill.forEach((sel) => {
            document.querySelectorAll(sel).forEach((el) => {
                if (el.closest && (el.closest('.editmode-switch-form') || el.closest('.editmode-switch') ||
                        el.closest('.navbar') || el.closest('nav.navbar'))) {
                    return;
                }
                if (el.classList && el.classList.contains('secondary-navigation') &&
                        el.querySelector('.editmode-switch-form, .editmode-switch')) {
                    Array.prototype.forEach.call(el.children, function(child) {
                        if (child.querySelector && child.querySelector('.editmode-switch-form, .editmode-switch')) {
                            return;
                        }
                        if (child.classList && (child.classList.contains('editmode-switch-form') ||
                                child.classList.contains('editmode-switch'))) {
                            return;
                        }
                        hideEl(child);
                    });
                    return;
                }
                hideEl(el);
            });
        });
        keepEditModeVisible();
        hideRemuiEnrollmentStrip();
        hideBreadcrumbAndUnenrol();
        hideEdwiserLayoutChrome(cfg);
        if (!document.body.classList.contains('nxpro-native-edit')) {
            document.body.classList.add('nxpro-fullscreen');
        }
    };

    const setRailCollapsed = (root, collapsed) => {
        root.classList.toggle('is-rail-collapsed', collapsed);
        document.body.classList.toggle('nxpro-rail-collapsed', collapsed);
        document.body.classList.toggle('nxpro-rail-open', !collapsed && isMobileView());
        root.querySelectorAll('[data-action="nxpro-toggle-rail"]').forEach((btn) => {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
        const backdrop = root.querySelector('[data-region="nxpro-rail-backdrop"]');
        if (backdrop) {
            const showBackdrop = isMobileView() && !collapsed;
            setHidden(backdrop, !showBackdrop);
            backdrop.setAttribute('aria-hidden', showBackdrop ? 'false' : 'true');
        }
        const courseId = root.getAttribute('data-courseid') || '0';
        if (!isMobileView()) {
            try {
                window.localStorage.setItem(railKey(courseId), collapsed ? '1' : '0');
            } catch (e) { /* ignore */ }
        }
    };

    const syncMobileMode = (root) => {
        const mobile = isMobileView();
        document.body.classList.toggle('nxpro-mobile', mobile);
        root.classList.toggle('nxpro-mobile', mobile);
        if (mobile) {
            setRailCollapsed(root, true);
        } else {
            const courseId = root.getAttribute('data-courseid') || '0';
            let collapsed = false;
            try {
                collapsed = window.localStorage.getItem(railKey(courseId)) === '1';
            } catch (e) { /* ignore */ }
            setRailCollapsed(root, collapsed);
        }
    };

    const initRail = (root) => {
        if (!root || root.dataset.nxproRailInit === '1') {
            return;
        }
        root.dataset.nxproRailInit = '1';

        syncMobileMode(root);

        const backdrop = root.querySelector('[data-region="nxpro-rail-backdrop"]');
        if (backdrop) {
            backdrop.addEventListener('click', () => setRailCollapsed(root, true));
        }

        root.querySelectorAll('[data-action="nxpro-toggle-rail"]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                setRailCollapsed(root, !root.classList.contains('is-rail-collapsed'));
            });
        });

        if (typeof MOBILE_MQ.addEventListener === 'function') {
            MOBILE_MQ.addEventListener('change', () => syncMobileMode(root));
        } else if (typeof MOBILE_MQ.addListener === 'function') {
            MOBILE_MQ.addListener(() => syncMobileMode(root));
        }
    };

    const initSearch = (root) => {
        const input = root.querySelector('[data-action="nxpro-search"]');
        if (!input) {
            return;
        }
        input.addEventListener('input', () => {
            const q = String(input.value || '').toLowerCase().trim();
            root.querySelectorAll('.nxpro-act').forEach((row) => {
                const hay = String(row.getAttribute('data-search') || '');
                const show = !q || hay.indexOf(q) !== -1;
                row.style.display = show ? '' : 'none';
                if (show && q) {
                    const details = row.closest('details');
                    if (details) {
                        details.open = true;
                    }
                }
            });
        });
    };

    const LB_PERPAGE = 25;

    const pageWindow = (current, pages) => {
        if (pages <= 7) {
            return Array.from({length: pages}, (_, i) => i);
        }
        const set = new Set([0, pages - 1, current]);
        for (let i = current - 1; i <= current + 1; i++) {
            if (i >= 0 && i < pages) {
                set.add(i);
            }
        }
        return Array.from(set).sort((a, b) => a - b);
    };

    const renderLeaderboardPager = (root, data) => {
        const pager = root.querySelector('[data-region="nxpro-lb-pager"]');
        if (!pager) {
            return;
        }
        const total = parseInt((data && data.total) || 0, 10) || 0;
        const page = parseInt((data && data.page) || 0, 10) || 0;
        const perpage = parseInt((data && data.perpage) || LB_PERPAGE, 10) || LB_PERPAGE;
        const pages = Math.max(1, Math.ceil(total / perpage));
        if (!total || pages <= 1) {
            setHidden(pager, true);
            pager.innerHTML = '';
            return;
        }
        const from = page * perpage + 1;
        const to = Math.min(total, (page + 1) * perpage);
        const nums = pageWindow(page, pages);
        let controls = '<button type="button" class="nxpro-lb-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>Prev</button>';
        let prev = null;
        nums.forEach((pn) => {
            if (prev !== null && pn > prev + 1) {
                controls += '<span class="nxpro-lb-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="nxpro-lb-pager__btn' +
                (pn === page ? ' is-active' : '') + '" data-page="' + pn + '"' +
                (pn === page ? ' aria-current="page"' : '') + '>' + (pn + 1) + '</button>';
            prev = pn;
        });
        controls += '<button type="button" class="nxpro-lb-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';
        setHidden(pager, false);
        pager.innerHTML =
            '<div class="nxpro-lb-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="nxpro-lb-pager__controls">' + controls + '</div>';
    };

    const renderAvatarChoices = (choices) => {
        return (choices || []).map((item) => {
            const locked = !item.unlocked;
            return '<button type="button" class="nxpro-lb-avatars__item' +
                (item.selected ? ' is-selected' : '') +
                (locked ? ' is-locked' : '') + '"' +
                ' data-action="nxpro-lb-pick-avatar" data-avatar="' + String(item.id) + '"' +
                (locked ? ' disabled data-required="' + String(item.requiredlevel) +
                    '" title="Reach level ' + String(item.requiredlevel) + ' to unlock"' :
                    ' title="Avatar ' + String(item.id) + '"') + '>' +
                '<img src="' + escapeHtml(item.url) + '" alt="" width="56" height="56">' +
                '</button>';
        }).join('');
    };

    const renderLeaderboardMe = (me) => {
        if (!me) {
            return '<p class="nxpro-aside__empty">No students to rank yet.</p>';
        }
        const handle = me.usernamehandle
            ? '<p class="nxpro-lb-profile__handle">' + escapeHtml(me.usernamehandle) + '</p>'
            : '';
        const levelBlock = me.showlevel
            ? '<div class="nxpro-lb-profile__level">' +
                '<img class="nxpro-lb-profile__level-ico" src="' + escapeHtml(me.levelicon || '') +
                    '" alt="" width="48" height="48">' +
                '<div class="nxpro-lb-profile__level-meta">' +
                    '<div class="nxpro-lb-profile__level-top">' +
                        '<span class="nxpro-lb-profile__label">' + escapeHtml(me.labellevel || 'Level') +
                            ' ' + escapeHtml(me.level != null ? me.level : 0) + '</span>' +
                        '<span class="nxpro-lb-profile__level-pct">' +
                            escapeHtml(me.levelpercent != null ? me.levelpercent : 0) + '%</span>' +
                    '</div>' +
                    '<div class="nxpro-lb-profile__bar" role="img" aria-label="' +
                        escapeHtml(me.levelpercent || 0) + '%">' +
                        '<span class="nxpro-lb-profile__bar-fill" style="width: ' +
                            (parseFloat(me.levelpercent) || 0) + '%;"></span>' +
                    '</div>' +
                    '<div class="nxpro-lb-profile__next">' + escapeHtml(me.nextlevel || '') + '</div>' +
                '</div></div>'
            : '';
        return '<div class="nxpro-lb-profile" data-region="nxpro-lb-profile">' +
            '<div class="nxpro-lb-profile__hero">' +
                '<button type="button" class="nxpro-lb-profile__avatar-btn" data-action="nxpro-lb-avatar" ' +
                    'title="' + escapeHtml(me.changeavatar || 'Change avatar') + '" aria-label="' +
                    escapeHtml(me.changeavatar || 'Change avatar') + '">' +
                    '<span class="nxpro-lb-profile__avatar-frame">' +
                        '<img class="nxpro-lb-profile__avatar" src="' + escapeHtml(me.avatarurl || '') +
                            '" alt="" width="140" height="140">' +
                    '</span>' +
                    '<span class="nxpro-lb-profile__avatar-hint">' +
                        escapeHtml(me.changeavatar || 'Change avatar') + '</span>' +
                '</button>' +
                '<p class="nxpro-lb-profile__user">' + escapeHtml(me.name) + '</p>' +
                handle +
            '</div>' +
            '<div class="nxpro-lb-profile__stats">' +
                '<div class="nxpro-lb-profile__stat">' +
                    '<img class="nxpro-lb-profile__ico" src="' + escapeHtml(me.scoreicon || '') +
                        '" alt="" width="48" height="48">' +
                    '<span class="nxpro-lb-profile__label">' + escapeHtml(me.labelscore || 'Score') + '</span>' +
                    '<strong class="nxpro-lb-profile__value">' + escapeHtml(me.score || me.grade || '—') + '</strong>' +
                '</div>' +
                '<div class="nxpro-lb-profile__stat">' +
                    '<img class="nxpro-lb-profile__ico" src="' + escapeHtml(me.rankicon || '') +
                        '" alt="" width="48" height="48">' +
                    '<span class="nxpro-lb-profile__label">' +
                        escapeHtml(me.labeloverall || 'Overall Ranking') + '</span>' +
                    '<strong class="nxpro-lb-profile__value">' +
                        escapeHtml(me.overallvalue || '—') + '</strong>' +
                '</div>' +
                '<div class="nxpro-lb-profile__stat">' +
                    '<img class="nxpro-lb-profile__ico" src="' + escapeHtml(me.collegeicon || '') +
                        '" alt="" width="48" height="48">' +
                    '<span class="nxpro-lb-profile__label">' +
                        escapeHtml(me.labelcollege || 'College Ranking') + '</span>' +
                    '<strong class="nxpro-lb-profile__value">' +
                        escapeHtml(me.collegevalue || '—') + '</strong>' +
                '</div>' +
            '</div>' +
            levelBlock +
            '<div class="nxpro-lb-avatars is-hidden" data-region="nxpro-lb-avatars" hidden>' +
                '<p class="nxpro-lb-avatars__title">' + escapeHtml(me.changeavatar || 'Change avatar') + '</p>' +
                '<div class="nxpro-lb-avatars__grid">' + renderAvatarChoices(me.avatarchoices) + '</div>' +
            '</div>' +
        '</div>';
    };

    const renderLeaderboardFilter = (root, data) => {
        const select = root.querySelector('[data-action="nxpro-lb-college"]');
        if (!select) {
            return;
        }
        const selected = String((data && data.institution) || '');
        const first = select.querySelector('option[value=""]');
        const allLabel = first ? first.textContent : 'All colleges';
        const options = ['<option value="">' + escapeHtml(allLabel) + '</option>'];
        (data && data.institutions ? data.institutions : []).forEach((item) => {
            const name = item && item.name != null ? String(item.name) : String(item || '');
            if (!name) {
                return;
            }
            options.push('<option value="' + escapeHtml(name) + '"' +
                (name === selected ? ' selected' : '') + '>' + escapeHtml(name) + '</option>');
        });
        select.innerHTML = options.join('');
        select.value = selected;
    };

    const renderLeaderboardRows = (data) => {
        if (!data || !data.available) {
            return '<p class="nxpro-lb-empty">' +
                escapeHtml((data && data.unavailablemessage) || 'Leaderboard is unavailable.') +
                '</p>';
        }
        const entries = data.entries || [];
        if (!entries.length) {
            return '<p class="nxpro-lb-empty">No students to rank yet.</p>';
        }
        const rows = entries.map((row) => {
            const you = row.isme ? ' <span class="nxpro-lb-you">You</span>' : '';
            const avatar = row.avatarurl
                ? '<img class="nxpro-lb-avatar" src="' + escapeHtml(row.avatarurl) +
                    '" alt="" width="28" height="28">'
                : '';
            const handle = row.username && row.username !== '—'
                ? '<span class="nxpro-lb-username">@' + escapeHtml(row.username) + '</span>'
                : '';
            return '<tr class="' + (row.isme ? 'nxpro-lb-current' : '') + '" data-userid="' +
                String(row.userid || '') + '"' + (row.isme ? ' aria-current="true"' : '') + '>' +
                '<td class="nxpro-lb-rank">' + escapeHtml(row.ranklabel || ('#' + row.rank)) + '</td>' +
                '<td class="nxpro-lb-student"><div class="nxpro-lb-who">' + avatar +
                    '<div class="nxpro-lb-who__text">' +
                    '<span class="nxpro-lb-name">' + escapeHtml(row.name) + you + '</span>' +
                    handle + '</div></div></td>' +
                '<td class="nxpro-lb-num">' + escapeHtml(row.grade) + '</td>' +
                '<td class="nxpro-lb-num">' + escapeHtml(row.percent) + '</td>' +
                '<td class="nxpro-lb-num nxpro-lb-level"><span class="nxpro-lb-level-pill">' +
                    escapeHtml(row.level != null ? row.level : 0) + '</span></td>' +
                '<td class="nxpro-lb-college">' + escapeHtml(row.institution || '—') + '</td>' +
            '</tr>';
        }).join('');
        return '<div class="nxpro-lb-table-wrap"><table class="nxpro-lb-table">' +
            '<colgroup>' +
                '<col class="nxpro-lb-col-rank">' +
                '<col class="nxpro-lb-col-name">' +
                '<col class="nxpro-lb-col-grade">' +
                '<col class="nxpro-lb-col-pct">' +
                '<col class="nxpro-lb-col-level">' +
                '<col class="nxpro-lb-col-college">' +
            '</colgroup>' +
            '<thead><tr>' +
                '<th scope="col" class="nxpro-lb-rank">Rank</th>' +
                '<th scope="col" class="nxpro-lb-student">Name</th>' +
                '<th scope="col" class="nxpro-lb-num">Grade</th>' +
                '<th scope="col" class="nxpro-lb-num">Grade %</th>' +
                '<th scope="col" class="nxpro-lb-num nxpro-lb-level">Level</th>' +
                '<th scope="col" class="nxpro-lb-college">College</th>' +
            '</tr></thead>' +
            '<tbody data-region="nxpro-lb-rows">' + rows + '</tbody>' +
            '</table></div>';
    };

    const applyLeaderboardData = (root, data) => {
        const boardBody = root.querySelector('[data-region="nxpro-lb-board-body"]');
        const meBody = root.querySelector('[data-region="nxpro-aside-lb-body"]');
        renderLeaderboardFilter(root, data);
        if (boardBody) {
            boardBody.innerHTML = renderLeaderboardRows(data);
        }
        if (meBody) {
            meBody.innerHTML = data && data.hasme ? renderLeaderboardMe(data.me) : renderLeaderboardMe(null);
        }
        renderLeaderboardPager(root, data);
        root._nxproLbState = {
            page: parseInt((data && data.page) || 0, 10) || 0,
            institution: String((data && data.institution) || ''),
            total: parseInt((data && data.total) || 0, 10) || 0,
        };
    };

    const fetchLeaderboard = (root, opts) => {
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        if (!courseId) {
            return Promise.resolve(null);
        }
        opts = opts || {};
        const state = root._nxproLbState || {};
        return Ajax.call([{
            methodname: 'format_nexcoursepro_get_leaderboard',
            args: {
                courseid: courseId,
                institution: String(opts.institution != null ? opts.institution : (state.institution || '')),
                page: parseInt(opts.page != null ? opts.page : (state.page || 0), 10) || 0,
                perpage: LB_PERPAGE,
            },
        }])[0];
    };

    const setAsideTab = (root, tab, opts) => {
        const next = tab === 'leaderboard' ? 'leaderboard' : 'content';
        const refresh = !!(opts && opts.refresh);
        opts = opts || {};
        root.classList.toggle('is-leaderboard', next === 'leaderboard');
        root.querySelectorAll('[data-action="nxpro-aside-tab"]').forEach((btn) => {
            const active = btn.getAttribute('data-tab') === next;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        const board = root.querySelector('[data-region="nxpro-leaderboard-view"]');
        const asideLb = root.querySelector('[data-region="nxpro-aside-leaderboard"]');
        const asideContent = root.querySelector('[data-region="nxpro-aside-content"]');
        if (board) {
            setHidden(board, next !== 'leaderboard');
        }
        if (asideLb) {
            setHidden(asideLb, next !== 'leaderboard');
        }
        if (asideContent) {
            setHidden(asideContent, next === 'leaderboard');
        }
        try {
            const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
            window.localStorage.setItem(asideTabKey(courseId), next);
        } catch (e) { /* ignore */ }
        if (next === 'leaderboard' && (refresh || !root.dataset.nxproLbLoaded)) {
            const boardBody = root.querySelector('[data-region="nxpro-lb-board-body"]');
            if (boardBody && !root.dataset.nxproLbLoaded) {
                boardBody.innerHTML = '<p class="nxpro-lb-empty">Loading leaderboard…</p>';
            }
            fetchLeaderboard(root, opts).then((data) => {
                if (!data) {
                    return;
                }
                applyLeaderboardData(root, data);
                root.dataset.nxproLbLoaded = '1';
            }).catch((err) => {
                Notification.exception(err);
            });
        }
    };

    const initAsideTabs = (root) => {
        if (!root || root.dataset.nxproAsideTabs === '1') {
            return;
        }
        root.dataset.nxproAsideTabs = '1';
        root._nxproLbState = {page: 0, institution: '', total: 0};

        root.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="nxpro-aside-tab"]');
            if (btn && root.contains(btn)) {
                e.preventDefault();
                setAsideTab(root, btn.getAttribute('data-tab') || 'content', {refresh: true, page: 0});
                return;
            }

            const pageBtn = e.target.closest('[data-region="nxpro-lb-pager"] [data-page]');
            if (pageBtn && root.contains(pageBtn) && !pageBtn.disabled && !pageBtn.classList.contains('is-active')) {
                e.preventDefault();
                const nextPage = parseInt(pageBtn.getAttribute('data-page'), 10);
                if (isNaN(nextPage) || nextPage < 0) {
                    return;
                }
                setAsideTab(root, 'leaderboard', {refresh: true, page: nextPage});
                return;
            }

            const avatarBtn = e.target.closest('[data-action="nxpro-lb-avatar"]');
            if (avatarBtn && root.contains(avatarBtn)) {
                e.preventDefault();
                const panel = root.querySelector('[data-region="nxpro-lb-avatars"]');
                if (panel) {
                    const open = panel.hasAttribute('hidden') || panel.classList.contains('is-hidden');
                    setHidden(panel, !open);
                }
                return;
            }

            const pick = e.target.closest('[data-action="nxpro-lb-pick-avatar"]');
            if (pick && root.contains(pick) && !pick.disabled) {
                e.preventDefault();
                const avatar = parseInt(pick.getAttribute('data-avatar') || '0', 10);
                const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
                if (!avatar || !courseId) {
                    return;
                }
                Ajax.call([{
                    methodname: 'format_nexcoursepro_set_avatar',
                    args: {courseid: courseId, avatar: avatar},
                }])[0].then((res) => {
                    if (!res || !res.ok) {
                        if (res && res.message) {
                            Notification.alert('Avatar', res.message);
                        }
                        return null;
                    }
                    const img = root.querySelector('.nxpro-lb-profile__avatar');
                    if (img && res.url) {
                        img.setAttribute('src', res.url);
                    }
                    root.querySelectorAll('[data-action="nxpro-lb-pick-avatar"]').forEach((el) => {
                        el.classList.toggle('is-selected',
                            parseInt(el.getAttribute('data-avatar') || '0', 10) === avatar);
                    });
                    const panel = root.querySelector('[data-region="nxpro-lb-avatars"]');
                    if (panel) {
                        setHidden(panel, true);
                    }
                    // Refresh board avatars for current user row.
                    setAsideTab(root, 'leaderboard', {refresh: true});
                    return null;
                }).catch((err) => Notification.exception(err));
            }
        });

        root.addEventListener('change', (e) => {
            const select = e.target.closest('[data-action="nxpro-lb-college"]');
            if (!select || !root.contains(select)) {
                return;
            }
            setAsideTab(root, 'leaderboard', {
                refresh: true,
                institution: select.value || '',
                page: 0,
            });
        });

        // Restore Leaderboard tab after reload when that was the last active tab.
        let savedTab = 'content';
        try {
            const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
            savedTab = window.localStorage.getItem(asideTabKey(courseId)) || 'content';
        } catch (e) { /* ignore */ }
        if (savedTab === 'leaderboard') {
            setAsideTab(root, 'leaderboard', {refresh: true, page: 0});
        }
    };

    const injectModBack = (cfg) => {
        if (!cfg || !cfg.modpage || cfg.embed || !cfg.backurl) {
            return;
        }
        if (document.querySelector('.nxpro-modbar')) {
            return;
        }
        const bar = document.createElement('div');
        bar.className = 'nxpro-modbar';
        bar.innerHTML = '<a class="nxpro-btn nxpro-btn--ghost" href="' +
            String(cfg.backurl).replace(/"/g, '&quot;') + '">← ' +
            String(cfg.backlabel || 'Back to course') + '</a>';
        const main = document.querySelector('#region-main') || document.body;
        main.insertBefore(bar, main.firstChild);
    };

    /**
     * Quiz attempt/summary "Back" points at mod/quiz/view.php by default.
     * In NexCoursePro, send learners to the course shell (with cmid) instead.
     *
     * @param {Object} cfg
     */
    const rewriteQuizAttemptBack = (cfg) => {
        if (!cfg || !cfg.backurl || cfg.embed) {
            return;
        }
        if (!cfg.modpage && !cfg.quizattemptback &&
                !document.body.classList.contains('nxpro-mod-page')) {
            return;
        }
        const courseBack = String(cfg.backurl);
        const isQuizView = (href) => /\/mod\/quiz\/view\.php(\?|$)/i.test(href || '');

        document.querySelectorAll(
            '.tertiary-navigation a[href*="/mod/quiz/view.php"], ' +
            '.tertiary-navigation a[href*="mod/quiz/view.php"], ' +
            'a.btn[href*="/mod/quiz/view.php"]'
        ).forEach((a) => {
            const href = a.getAttribute('href') || '';
            if (!isQuizView(href)) {
                return;
            }
            // Prefer the Moodle "Back" control; also catch obvious quiz-return labels.
            const label = String(a.textContent || '').trim().toLowerCase();
            const inTertiary = !!a.closest('.tertiary-navigation');
            if (inTertiary || label === 'back' || /back to (the )?quiz|return to quiz/.test(label)) {
                a.setAttribute('href', courseBack);
                a.setAttribute('data-nxpro-course-back', '1');
            }
        });
    };

    const initModPageBack = (cfg) => {
        injectModBack(cfg);
        rewriteQuizAttemptBack(cfg);
        [50, 200, 500, 1200].forEach((ms) => {
            window.setTimeout(() => rewriteQuizAttemptBack(cfg), ms);
        });
    };

    const lockPageScroll = () => {
        if (!document.body.classList.contains('nxpro-learn-page')) {
            return;
        }
        document.documentElement.classList.add('nxpro-scroll-locked');
        document.body.classList.add('nxpro-scroll-locked');
        document.documentElement.style.setProperty('overflow', 'hidden', 'important');
        document.body.style.setProperty('overflow', 'hidden', 'important');
        document.documentElement.style.setProperty('height', '100%', 'important');
        document.body.style.setProperty('height', '100%', 'important');

        [
            '#page',
            '#page-wrapper',
            '#page-content',
            '#topofscroll',
            '#region-main-box',
            '#region-main',
            '.main-inner',
        ].forEach((sel) => {
            document.querySelectorAll(sel).forEach((el) => {
                el.style.setProperty('overflow', 'hidden', 'important');
                el.style.setProperty('max-height', '100%', 'important');
            });
        });

        const stopPageWheel = (e) => {
            if (!document.body.classList.contains('nxpro-learn-page')) {
                return;
            }
            const inPane = e.target && e.target.closest &&
                e.target.closest('.nxpro-main, .nxpro-aside, .nxpro-rail-dock');
            if (!inPane) {
                e.preventDefault();
            }
        };
        if (!document.documentElement.dataset.nxproWheelLock) {
            document.documentElement.dataset.nxproWheelLock = '1';
            window.addEventListener('wheel', stopPageWheel, {passive: false});
        }
    };

    const measureNavOffset = () => {
        const bottoms = [];
        const page = document.getElementById('page');
        if (page) {
            const pt = parseFloat(window.getComputedStyle(page).paddingTop || '0');
            if (pt > 0) {
                bottoms.push(pt);
            }
        }
        const rootStyle = window.getComputedStyle(document.documentElement);
        [
            '--navbar-height',
            '--header-height',
            '--remui-header-height',
            '--jq-navbar-height',
        ].forEach((name) => {
            const n = parseFloat(rootStyle.getPropertyValue(name));
            if (n > 0) {
                bottoms.push(n);
            }
        });
        [
            'nav.navbar.fixed-top',
            'nav.navbar',
            'header.navbar',
            '.navbar.fixed-top',
            '.navbar',
            '.fixed-top',
            '.edw-header-top',
            '.edwiser-header',
            '#page-navbar-fixed',
            'header.fixed-top',
        ].forEach((sel) => {
            document.querySelectorAll(sel).forEach((el) => {
                if (!el || el.closest('.nxpro-learn, .nxpro')) {
                    return;
                }
                if (el.classList.contains('nxpro-theme-hidden')) {
                    return;
                }
                const st = window.getComputedStyle(el);
                if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) {
                    return;
                }
                const r = el.getBoundingClientRect();
                if (r.height < 36 || r.width < (window.innerWidth < 768 ? 80 : 160) || r.top > 16) {
                    return;
                }
                const pos = st.position;
                if (pos === 'fixed' || pos === 'sticky' || r.top <= 1) {
                    bottoms.push(r.bottom);
                }
            });
        });
        const raw = bottoms.length ? Math.max.apply(null, bottoms) : 80;
        // Sit flush under the site navbar (no extra gap line).
        return Math.max(48, Math.round(raw));
    };

    const syncNavOffset = () => {
        if (!document.body.classList.contains('nxpro-learn-page')) {
            return;
        }
        const offset = measureNavOffset();
        document.documentElement.style.setProperty('--nxpro-nav-offset', offset + 'px');
        document.body.style.setProperty('--nxpro-nav-offset', offset + 'px');
        lockPageScroll();
    };

    const setHidden = (el, hidden) => {
        if (!el) {
            return;
        }
        el.classList.toggle('is-hidden', !!hidden);
        if (hidden) {
            el.setAttribute('hidden', 'hidden');
        } else {
            el.removeAttribute('hidden');
        }
    };

    const paneCache = new Map();
    const inflightPane = new Map();
    const prefetchQueue = [];
    let prefetchRunning = false;
    let loadSeq = 0;
    let userLoadActive = false;

    const paneCacheKey = (courseId, cmid) => String(courseId || 0) + ':' + String(cmid || 0);

    /**
     * Sidebar DOM order — instant prev/next without waiting on the server.
     *
     * @param {HTMLElement} root
     * @return {Array<{id:number,name:string,viewurl:string}>}
     */
    const buildNavOrder = (root) => {
        const items = [];
        root.querySelectorAll('.nxpro-act[data-cmid]').forEach((row) => {
            const id = parseInt(row.getAttribute('data-cmid') || '0', 10);
            if (!id) {
                return;
            }
            const link = row.querySelector('[data-action="nxpro-nav"]');
            const nameEl = row.querySelector('.nxpro-act__name');
            items.push({
                id: id,
                name: nameEl ? nameEl.textContent.trim() : '',
                viewurl: link ? (link.getAttribute('href') || '') : '',
            });
        });
        return items;
    };

    /**
     * @param {Array} order
     * @param {number} cmid
     * @return {{prev:?object,next:?object,hasprev:boolean,hasnext:boolean}}
     */
    const navFromOrder = (order, cmid) => {
        const idx = order.findIndex((item) => item.id === cmid);
        return {
            prev: idx > 0 ? order[idx - 1] : null,
            next: (idx >= 0 && idx < order.length - 1) ? order[idx + 1] : null,
            hasprev: idx > 0,
            hasnext: idx >= 0 && idx < order.length - 1,
        };
    };

    /**
     * @param {object} data
     * @param {HTMLElement} root
     * @param {number} cmid
     * @return {object}
     */
    const mergeNavData = (data, root, cmid) => {
        const local = navFromOrder(buildNavOrder(root), cmid);
        data.hasprev = local.hasprev;
        data.hasnext = local.hasnext;
        data.prev = local.prev || data.prev || {id: 0, viewurl: '', name: ''};
        data.next = local.next || data.next || {id: 0, viewurl: '', name: ''};
        return data;
    };

    /**
     * @param {HTMLElement} root
     * @param {number} cmid
     */
    const applyOptimisticHeader = (root, cmid) => {
        const row = root.querySelector('.nxpro-act[data-cmid="' + cmid + '"]');
        if (!row) {
            return;
        }
        const nameEl = row.querySelector('.nxpro-act__name');
        const title = root.querySelector('[data-region="nxpro-title"]');
        if (title && nameEl) {
            title.textContent = nameEl.textContent.trim();
        }
        const section = root.querySelector('[data-region="nxpro-section"]');
        const details = row.closest('details');
        if (section && details) {
            const summary = details.querySelector('summary .nxpro-acc__title, summary');
            if (summary) {
                section.textContent = summary.textContent.trim();
                setHidden(section, !section.textContent);
            }
        }
    };

    /**
     * Single flight for pane WS — click and prefetch share the same request.
     * This is the main reason the same activity felt fast then slow.
     *
     * @param {HTMLElement} root
     * @param {number} cmid
     * @param {boolean} force
     * @return {Promise<object>}
     */
    const fetchPane = (root, cmid, force) => {
        cmid = parseInt(cmid || '0', 10);
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        const key = paneCacheKey(courseId, cmid);
        if (!force && paneCache.has(key)) {
            return Promise.resolve(paneCache.get(key));
        }
        if (!force && inflightPane.has(key)) {
            return inflightPane.get(key);
        }
        if (force) {
            paneCache.delete(key);
            inflightPane.delete(key);
        }
        const req = Ajax.call([{
            methodname: 'format_nexcoursepro_get_activity_pane',
            args: {courseid: courseId, cmid: cmid},
        }])[0].then((data) => {
            // Cache all kinds — completion for H5P is refreshed client-side after apply.
            paneCache.set(key, data);
            inflightPane.delete(key);
            return data;
        }).catch((err) => {
            inflightPane.delete(key);
            throw err;
        });
        inflightPane.set(key, req);
        return req;
    };

    const pumpPrefetchQueue = (root) => {
        if (prefetchRunning || userLoadActive || !prefetchQueue.length) {
            return;
        }
        const cmid = prefetchQueue.shift();
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        const key = paneCacheKey(courseId, cmid);
        if (!cmid || paneCache.has(key) || inflightPane.has(key)) {
            window.setTimeout(() => pumpPrefetchQueue(root), 0);
            return;
        }
        prefetchRunning = true;
        fetchPane(root, cmid, false).catch(() => {
            // Prefetch is best-effort.
        }).then(() => {
            prefetchRunning = false;
            // Yield so a click that arrived mid-prefetch can take the network.
            window.setTimeout(() => pumpPrefetchQueue(root), 40);
        });
    };

    /**
     * @param {HTMLElement} root
     * @param {number} cmid
     * @param {boolean} urgent Put at front of queue (hover / neighbors)
     */
    const enqueuePrefetch = (root, cmid, urgent) => {
        cmid = parseInt(cmid || '0', 10);
        if (!cmid) {
            return;
        }
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        const key = paneCacheKey(courseId, cmid);
        if (paneCache.has(key) || inflightPane.has(key)) {
            return;
        }
        const idx = prefetchQueue.indexOf(cmid);
        if (idx !== -1) {
            if (urgent) {
                prefetchQueue.splice(idx, 1);
                prefetchQueue.unshift(cmid);
            }
            pumpPrefetchQueue(root);
            return;
        }
        if (urgent) {
            prefetchQueue.unshift(cmid);
        } else {
            prefetchQueue.push(cmid);
        }
        // Cap queue so large courses do not stampede the server.
        while (prefetchQueue.length > 8) {
            prefetchQueue.pop();
        }
        pumpPrefetchQueue(root);
    };

    const prefetchNeighbors = (root, cmid) => {
        const order = buildNavOrder(root);
        const nav = navFromOrder(order, cmid);
        if (nav.next) {
            enqueuePrefetch(root, nav.next.id, true);
        }
        if (nav.prev) {
            enqueuePrefetch(root, nav.prev.id, true);
        }
        // Warm the next two beyond next — makes "Next" feel consistent.
        const idx = order.findIndex((item) => item.id === cmid);
        if (idx >= 0) {
            if (order[idx + 2]) {
                enqueuePrefetch(root, order[idx + 2].id, false);
            }
            if (order[idx + 3]) {
                enqueuePrefetch(root, order[idx + 3].id, false);
            }
        }
    };

    const initHoverPrefetch = (root) => {
        let hoverTimer = 0;
        let hoverCmid = 0;
        root.addEventListener('pointerenter', (e) => {
            const link = e.target.closest('[data-action="nxpro-nav"]');
            if (!link || !root.contains(link)) {
                return;
            }
            const cmid = parseInt(link.getAttribute('data-cmid') || '0', 10);
            if (!cmid || cmid === hoverCmid) {
                return;
            }
            hoverCmid = cmid;
            window.clearTimeout(hoverTimer);
            hoverTimer = window.setTimeout(() => {
                enqueuePrefetch(root, cmid, true);
            }, 70);
        }, true);
        root.addEventListener('pointerleave', (e) => {
            const link = e.target.closest('[data-action="nxpro-nav"]');
            if (!link || !root.contains(link)) {
                return;
            }
            if (parseInt(link.getAttribute('data-cmid') || '0', 10) === hoverCmid) {
                window.clearTimeout(hoverTimer);
                hoverCmid = 0;
            }
        }, true);
    };

    const deferQuizEnhance = (root) => {
        const run = () => {
            try {
                QuizView.enhance(root);
                QuizView.watchQuizModals(root);
            } catch (e) {
                // Keep raw Moodle HTML if enhance fails.
            }
        };
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, {timeout: 500});
        } else {
            window.requestAnimationFrame(() => window.setTimeout(run, 0));
        }
    };

    const escapeHtml = (s) => String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const markActive = (root, cmid) => {
        root.querySelectorAll('.nxpro-act').forEach((row) => {
            const id = parseInt(row.getAttribute('data-cmid') || '0', 10);
            const active = id === cmid;
            row.classList.toggle('is-active', active);
            if (active) {
                const details = row.closest('details');
                if (details) {
                    details.open = true;
                }
                try {
                    row.scrollIntoView({block: 'nearest', behavior: 'smooth'});
                } catch (e) { /* ignore */ }
            }
        });
    };

    const renderNavButtons = (root, data) => {
        const nav = root.querySelector('.nxpro-footer__nav');
        if (!nav) {
            return;
        }
        const prevLabel = 'Back';
        const nextLabel = 'Next Activity';
        let html = '';
        if (data.hasprev && data.prev && data.prev.id) {
            html += '<a class="nxpro-btn nxpro-btn--ghost" data-action="nxpro-nav" data-cmid="' +
                data.prev.id + '" href="' + escapeHtml(data.prev.viewurl) + '">' + prevLabel + '</a>';
        } else {
            html += '<span class="nxpro-btn nxpro-btn--ghost is-disabled" aria-disabled="true">' +
                prevLabel + '</span>';
        }
        if (data.hasnext && data.next && data.next.id) {
            html += '<a class="nxpro-btn nxpro-btn--solid" data-action="nxpro-nav" data-cmid="' +
                data.next.id + '" href="' + escapeHtml(data.next.viewurl) + '">' + nextLabel + '</a>';
        } else {
            html += '<span class="nxpro-btn nxpro-btn--solid is-disabled" aria-disabled="true">' +
                nextLabel + '</span>';
        }
        nav.innerHTML = html;
    };

    const renderMedia = (player, main) => {
        if (!player) {
            return;
        }
        let url = main.mediaurl || '';
        let kind = main.mediakind || '';
        // Safety: Drive / known hosts must never use <video src>.
        if (url && /drive\.google\.com|docs\.google\.com|youtube\.com|youtu\.be|vimeo\.com|loom\.com/i.test(url)
                && kind !== 'embed') {
            kind = 'embed';
            const drive = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
            if (drive) {
                url = 'https://drive.google.com/file/d/' + drive[1] + '/preview';
            }
        }
        player.setAttribute('data-mediaurl', url);
        player.setAttribute('data-mediakind', kind);
        if (!url) {
            player.innerHTML = '';
            return;
        }
        if (kind === 'video') {
            player.innerHTML = '<video class="nxpro-av__video" controls playsinline src="' +
                escapeHtml(url) + '"></video>';
            return;
        }
        if (kind === 'audio') {
            player.innerHTML = '<audio class="nxpro-av__audio" controls src="' +
                escapeHtml(url) + '"></audio>';
            return;
        }
        if (kind === 'embed') {
            player.innerHTML = '<div class="nxpro-av__embed"><iframe class="nxpro-av__iframe" src="' +
                escapeHtml(url) +
                '" title="Video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" ' +
                'allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
            return;
        }
        if (kind === 'external') {
            player.innerHTML = '<div class="nxpro-av__external"><p>This video opens in an external player.</p>' +
                '<a class="nxpro-av__cta" href="' + escapeHtml(url) +
                '" target="_blank" rel="noopener">Watch video</a></div>';
            return;
        }
        player.innerHTML = '';
    };

    /** @type {{root: HTMLElement|null, cmid: number, timer: number, checks: number}|null} */
    let h5pWatch = null;
    let h5pBootstrapSeq = 0;

    const stopH5pCompletionWatch = () => {
        if (h5pWatch && h5pWatch.timer) {
            window.clearInterval(h5pWatch.timer);
        }
        h5pWatch = null;
    };

    /**
     * Update hero score badge (obtained / max) when the activity has been graded.
     *
     * @param {HTMLElement} root
     * @param {object} payload
     */
    const applyActivityScore = (root, payload) => {
        if (!root || !payload) {
            return;
        }
        const score = root.querySelector('[data-region="nxpro-activity-score"]');
        const value = root.querySelector('[data-region="nxpro-activity-score-value"]');
        const show = !!payload.completed
            && !!(payload.hasactivitygrade || payload.hasgrade)
            && !!(payload.gradedisplay);
        if (value) {
            value.textContent = show ? String(payload.gradedisplay) : '';
        }
        if (score) {
            setHidden(score, !show);
        }
    };

    /**
     * Keep H5P header pills aligned with the stricter sidebar tick.
     *
     * @param {string} html
     * @param {boolean} completed
     * @return {string}
     */
    const normalizeH5pCompletionPills = (html, completed) => {
        if (completed || !html) {
            return html;
        }
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        tmp.querySelectorAll('.nxpro-completion-crit--done').forEach((pill) => {
            const text = (pill.textContent || '').toLowerCase();
            if (!text.includes('grade') && !text.includes('score') && !text.includes('passing')) {
                return;
            }
            pill.classList.remove('nxpro-completion-crit--done');
            const check = pill.querySelector('.nxpro-completion-crit__check');
            if (check) {
                check.remove();
            }
            const raw = (pill.textContent || '').trim();
            if (/^done:\s*/i.test(raw)) {
                pill.textContent = raw.replace(/^done:\s*/i, '');
            }
        });
        return tmp.innerHTML;
    };

    /**
     * Paint H5P completion from get_cm_progress (never stale pane HTML).
     *
     * @param {HTMLElement} root
     * @param {number} cmid
     * @param {object|null} paneData fallback pane payload when progress WS fails
     */
    const bootstrapH5pCompletion = (root, cmid, paneData) => {
        cmid = parseInt(cmid || '0', 10);
        if (!root || !cmid) {
            return;
        }
        const seq = ++h5pBootstrapSeq;
        const completion = root.querySelector('[data-region="nxpro-completion"]');
        const completionBody = root.querySelector('[data-region="nxpro-completion-body"]');
        if (completionBody) {
            completionBody.innerHTML = '';
        }
        if (completion) {
            completion.classList.remove('nxpro-completion-ready');
            setHidden(completion, true);
        }

        fetchCmProgress(root, cmid).then((progress) => {
            if (seq !== h5pBootstrapSeq) {
                return;
            }
            const pane = root.querySelector('[data-region="nxpro-pane"]');
            if (!pane || parseInt(pane.getAttribute('data-cmid') || '0', 10) !== cmid
                    || pane.getAttribute('data-kind') !== 'h5p') {
                return;
            }
            applyH5pProgressLive(root, cmid, progress);
        }).catch(() => {
            if (seq !== h5pBootstrapSeq) {
                return;
            }
            const pane = root.querySelector('[data-region="nxpro-pane"]');
            if (!pane || parseInt(pane.getAttribute('data-cmid') || '0', 10) !== cmid
                    || pane.getAttribute('data-kind') !== 'h5p') {
                return;
            }
            const main = (paneData && paneData.main) || {};
            const paneCompleted = !!main.completed;
            const html = normalizeH5pCompletionPills(main.completionhtml || '', paneCompleted);
            if (completionBody) {
                completionBody.innerHTML = html;
            }
            if (completion) {
                completion.classList.add('nxpro-completion-ready');
                setHidden(completion, !main.hascompletion);
            }
            applyActivityScore(root, {
                completed: !!main.completed,
                hasactivitygrade: !!main.hasactivitygrade,
                hasgrade: !!main.hasactivitygrade,
                gradedisplay: main.gradedisplay || '',
            });
            if (paneData && paneData.hasstats && paneData.stats) {
                applyStatsStrip(root, paneData.stats);
            }
        });
    };

    /**
     * Apply live H5P progress (rail + criteria + stats) without remounting the player.
     *
     * @param {HTMLElement} root
     * @param {number} cmid
     * @param {object} progress get_cm_progress payload
     */
    const applyH5pProgressLive = (root, cmid, progress) => {
        if (!root || !cmid || !progress) {
            return;
        }
        const serverCompleted = !!progress.completed;
        const criteriahtml = normalizeH5pCompletionPills(progress.completionhtml || '', serverCompleted);
        const pending = hasPendingCompletionCriteria(criteriahtml);
        const completed = serverCompleted && !pending;
        const failed = !!progress.failed && !completed;

        root.querySelectorAll('.nxpro-act[data-cmid="' + cmid + '"]').forEach((row) => {
            row.classList.toggle('is-done', completed);
            row.classList.toggle('is-failed', failed);
            const check = row.querySelector('.nxpro-act__check');
            if (check) {
                check.classList.toggle('is-done', completed);
                check.classList.toggle('is-failed', failed);
            }
        });

        const pane = root.querySelector('[data-region="nxpro-pane"]');
        const paneCmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
        const paneIsTargetH5p = !!pane && pane.getAttribute('data-kind') === 'h5p' && paneCmid === cmid;
        if (!paneIsTargetH5p) {
            return (completed || failed) && !pending;
        }

        const completion = root.querySelector('[data-region="nxpro-completion"]');
        const completionBody = root.querySelector('[data-region="nxpro-completion-body"]');
        if (completionBody && criteriahtml) {
            completionBody.innerHTML = criteriahtml;
        }
        if (completion) {
            completion.classList.add('nxpro-completion-ready');
            setHidden(completion, !progress.hascompletion);
        }

        applyActivityScore(root, progress);

        const status = root.querySelector('[data-region="nxpro-status"]');
        if (status) {
            setHidden(status, true);
        }

        if (progress.hasstats && progress.stats) {
            applyStatsStrip(root, progress.stats);
        }

        return (completed || failed) && !pending;
    };

    /**
     * Replace progress strip values from a fresh server stats payload.
     *
     * @param {HTMLElement} root
     * @param {object} stats
     */
    const applyStatsStrip = (root, stats) => {
        const host = root.querySelector('[data-region="nxpro-stats"]');
        if (!host || !stats) {
            return;
        }
        const pct = parseInt(stats.progresspct || 0, 10) || 0;
        const donut = host.querySelector('.nxpro-donut');
        const donutVal = host.querySelector('.nxpro-donut__value');
        const sub = host.querySelector('.nxpro-stats__progress-sub');
        if (donut) {
            donut.style.setProperty('--nxpro-donut-pct', String(pct));
            donut.setAttribute('aria-label', pct + '%');
        }
        if (donutVal) {
            donutVal.textContent = pct + '%';
        }
        if (sub && stats.activitydisplay) {
            sub.textContent = stats.activitydisplay;
        }
        const grid = host.querySelector('.nxpro-stats__grid');
        const items = stats.items || [];
        if (grid && items.length) {
            const existingKeys = Array.prototype.map.call(
                grid.querySelectorAll('.nxpro-stat'),
                (el) => {
                    const m = (el.className || '').match(/nxpro-stat--([a-z0-9_-]+)/i);
                    return m ? m[1] : '';
                }
            ).filter(Boolean).join('|');
            const nextKeys = items.map((item) => String(item.key || '')).join('|');
            if (existingKeys !== nextKeys) {
                grid.innerHTML = items.map((item) => {
                    const key = String(item.key || '').replace(/[^a-z0-9_-]/gi, '');
                    return '<div class="nxpro-stat nxpro-stat--' + key + '">' +
                        '<span class="nxpro-stat__icon" aria-hidden="true"></span>' +
                        '<div class="nxpro-stat__body">' +
                            '<strong class="nxpro-stat__value"></strong>' +
                            '<span class="nxpro-stat__label"></span>' +
                        '</div></div>';
                }).join('');
            }
        }
        items.forEach((item) => {
            const el = host.querySelector('.nxpro-stat--' + item.key);
            if (!el) {
                return;
            }
            const valueEl = el.querySelector('.nxpro-stat__value');
            const labelEl = el.querySelector('.nxpro-stat__label');
            if (valueEl) {
                valueEl.textContent = String(item.value == null ? '' : item.value);
            }
            if (labelEl && item.label != null) {
                labelEl.textContent = String(item.label);
            }
        });
    };

    /**
     * @param {string} html
     * @return {boolean}
     */
    const hasPendingCompletionCriteria = (html) => {
        if (!html) {
            return false;
        }
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return !!tmp.querySelector('.nxpro-completion-crit:not(.nxpro-completion-crit--done)');
    };

    /**
     * @param {HTMLElement} root
     * @param {number} cmid
     * @return {Promise<object>}
     */
    const fetchCmProgress = (root, cmid) => {
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        return Ajax.call([{
            methodname: 'format_nexcoursepro_get_cm_progress',
            args: {courseid: courseId, cmid: parseInt(cmid || '0', 10)},
        }])[0];
    };

    /**
     * Poll Moodle until H5P grade/completion is reflected in the shell.
     *
     * @param {HTMLElement} root
     * @param {number} cmid
     */
    const startH5pCompletionWatch = (root, cmid) => {
        cmid = parseInt(cmid || '0', 10);
        stopH5pCompletionWatch();
        if (!root || !cmid) {
            return;
        }
        h5pWatch = {root: root, cmid: cmid, timer: 0, checks: 0};

        const tick = () => {
            if (!h5pWatch || h5pWatch.cmid !== cmid) {
                return;
            }
            const pane = root.querySelector('[data-region="nxpro-pane"]');
            if (!pane || parseInt(pane.getAttribute('data-cmid') || '0', 10) !== cmid
                    || pane.getAttribute('data-kind') !== 'h5p') {
                stopH5pCompletionWatch();
                return;
            }
            h5pWatch.checks += 1;
            if (h5pWatch.checks > 90) {
                stopH5pCompletionWatch();
                return;
            }
            fetchCmProgress(root, cmid).then((progress) => {
                if (!h5pWatch || h5pWatch.cmid !== cmid) {
                    return;
                }
                const done = applyH5pProgressLive(root, cmid, progress);
                if (done) {
                    stopH5pCompletionWatch();
                }
            }).catch(() => {
                // Best-effort — keep polling.
            });
        };

        h5pWatch.timer = window.setInterval(tick, 1500);
        window.setTimeout(tick, 400);
    };

    /**
     * Same-origin: notify parent when Moodle posts H5P xAPI statements/states.
     *
     * @param {HTMLIFrameElement} iframe
     * @param {HTMLElement} root
     * @param {number} cmid
     */
    const hookH5pEmbedFrame = (iframe, root, cmid) => {
        if (!iframe || iframe.dataset.nxproH5pHooked === '1') {
            return;
        }
        iframe.dataset.nxproH5pHooked = '1';
        const inject = () => {
            try {
                const doc = iframe.contentDocument;
                if (!doc) {
                    return;
                }
                if (doc.documentElement.dataset.nxproH5pInject === '1') {
                    return;
                }
                doc.documentElement.dataset.nxproH5pInject = '1';
                const script = doc.createElement('script');
                script.textContent = '(function(){' +
                    'function notify(kind){' +
                    'try{window.parent.postMessage({nxproH5p:1,kind:kind},"*");}catch(e){}' +
                    '}' +
                    'function patch(){' +
                    'var c=window.H5PEmbedCommunicator;' +
                    'if(!c){setTimeout(patch,200);return;}' +
                    'if(c.__nxproPatched){return;}' +
                    'c.__nxproPatched=1;' +
                    'var post=c.post.bind(c);' +
                    'c.post=function(){notify("statement");return post.apply(c,arguments);};' +
                    'if(c.postState){var ps=c.postState.bind(c);' +
                    'c.postState=function(){notify("state");return ps.apply(c,arguments);};}' +
                    '}' +
                    'patch();' +
                    '})();';
                (doc.head || doc.documentElement).appendChild(script);
            } catch (e) {
                // Cross-origin or not ready.
            }
        };
        iframe.addEventListener('load', () => {
            inject();
            window.setTimeout(inject, 400);
            window.setTimeout(inject, 1200);
            if (root && cmid) {
                startH5pCompletionWatch(root, cmid);
            }
        });
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
            inject();
        }
    };

    /**
     * @param {*} data
     * @return {boolean}
     */
    const isH5pCompletionMessage = (data) => {
        if (!data || typeof data !== 'object') {
            return false;
        }
        if (data.nxproH5p) {
            return data.kind === 'statement' || data.kind === 'state' || !data.kind;
        }
        // Ignore resize handshake — it fires constantly and flooded polls.
        if (data.context === 'h5p') {
            return false;
        }
        const stmt = data.statement || data;
        const verb = stmt && stmt.verb && (stmt.verb.id || stmt.verb);
        if (!verb || typeof verb !== 'string') {
            return false;
        }
        const completed = /\/verbs\/(completed|answered)$/i.test(verb)
            || !!(stmt.result && stmt.result.completion);
        if (!completed) {
            return false;
        }
        const parents = stmt.context && stmt.context.contextActivities
            && stmt.context.contextActivities.parent;
        return !(parents && parents.length);
    };

    const initH5pCompletionListener = (root) => {
        if (!root || root.dataset.nxproH5pListen === '1') {
            return;
        }
        root.dataset.nxproH5pListen = '1';
        window.addEventListener('message', (event) => {
            if (!isH5pCompletionMessage(event.data)) {
                return;
            }
            const pane = root.querySelector('[data-region="nxpro-pane"]');
            if (!pane || pane.getAttribute('data-kind') !== 'h5p') {
                return;
            }
            const cmid = parseInt(pane.getAttribute('data-cmid') || '0', 10);
            if (!cmid) {
                return;
            }
            if (!h5pWatch || h5pWatch.cmid !== cmid) {
                startH5pCompletionWatch(root, cmid);
            }
            // Gradebook write can lag the xAPI response slightly.
            [500, 1200, 2500, 4500].forEach((ms) => {
                window.setTimeout(() => {
                    if (!h5pWatch || h5pWatch.cmid !== cmid) {
                        return;
                    }
                    fetchCmProgress(root, cmid).then((progress) => {
                        const done = applyH5pProgressLive(root, cmid, progress);
                        if (done) {
                            stopH5pCompletionWatch();
                        }
                    }).catch(() => { /* ignore */ });
                }, ms);
            });
        });
    };

    const chipHtml = (items) => {
        return (items || []).map((item) => {
            return '<div class="nxpro-av__chip"><span class="nxpro-av__chip-label">' +
                escapeHtml(item.label) + '</span><strong class="nxpro-av__chip-value">' +
                escapeHtml(item.value) + '</strong></div>';
        }).join('');
    };

    /**
     * Remove leftover Moodle quiz modals / backdrops that leak when swapping panes.
     *
     * @param {HTMLElement} root
     */
    const cleanupOverlayChrome = (root) => {
        try {
            QuizView.dismissPromotedModals();
        } catch (e) { /* ignore */ }
        if (root) {
            root.querySelectorAll('.modal, .modal-backdrop, .moodle-dialogue, .yui3-panel, ' +
                '[data-region="modal-container"] .modal').forEach((el) => {
                try {
                    el.remove();
                } catch (e) { /* ignore */ }
            });
        }
        document.querySelectorAll('body > .modal-backdrop, body > .modal.show, ' +
            '.modal-backdrop.show, .nxpro-quiz-modal, .nxpro-quiz-modal-backdrop').forEach((el) => {
            try {
                el.remove();
            } catch (e) { /* ignore */ }
        });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    };

    /**
     * @param {HTMLElement} root
     * @param {boolean} on
     */
    const setPaneLoading = (root, on) => {
        const pane = root.querySelector('[data-region="nxpro-pane"]');
        const loading = root.querySelector('[data-region="nxpro-loading"]');
        if (pane) {
            pane.classList.toggle('is-loading', !!on);
        }
        if (loading) {
            setHidden(loading, !on);
        }
        if (on) {
            cleanupOverlayChrome(root);
        }
    };

    const setQuizTab = (root, tab) => {
        const quiz = root.querySelector('[data-region="nxpro-quiz"]');
        if (!quiz) {
            return;
        }
        const which = tab === 'attempts' ? 'attempts' : 'overview';
        quiz.querySelectorAll('[data-action="nxpro-quiz-tab"]').forEach((btn) => {
            const active = btn.getAttribute('data-tab') === which;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        const overview = quiz.querySelector('[data-region="nxpro-quiz-overview"]');
        const attempts = quiz.querySelector('[data-region="nxpro-quiz-attempts"]');
        if (overview) {
            overview.classList.toggle('is-active', which === 'overview');
            if (which === 'overview') {
                overview.removeAttribute('hidden');
            } else {
                overview.setAttribute('hidden', 'hidden');
            }
        }
        if (attempts) {
            attempts.classList.toggle('is-active', which === 'attempts');
            if (which === 'attempts') {
                attempts.removeAttribute('hidden');
            } else {
                attempts.setAttribute('hidden', 'hidden');
            }
        }
    };

    const renderQuizPane = (root, main) => {
        const quiz = root.querySelector('[data-region="nxpro-quiz"]');
        if (!quiz) {
            return;
        }
        const show = !!main.hasquiztabs;
        setHidden(quiz, !show);
        if (!show) {
            return;
        }

        const intro = root.querySelector('[data-region="nxpro-quiz-intro"]');
        if (intro) {
            intro.innerHTML = main.hasquizintro ? (main.quizintro || '') : '';
            setHidden(intro, !main.hasquizintro);
        }

        const messages = root.querySelector('[data-region="nxpro-quiz-messages"]');
        if (messages) {
            const list = main.quizmessages || [];
            messages.innerHTML = list.map((m) => '<li>' + escapeHtml(m.text || '') + '</li>').join('');
            setHidden(messages, !list.length);
        }

        const actions = root.querySelector('[data-region="nxpro-quiz-actions"]');
        if (actions) {
            actions.innerHTML = main.hasquizactions ? (main.quizactionshtml || '') : '';
            // Keep visible until quizview enhance moves CTAs into Overview.
            setHidden(actions, !main.hasquizactions);
            actions.classList.toggle('nxpro-quiz__actions-src', !!main.hasquizactions);
        }

        const body = root.querySelector('[data-region="nxpro-quiz-body"]');
        if (body) {
            body.innerHTML = main.hasquizbody ? (main.quizbodyhtml || '') : '';
            setHidden(body, !main.hasquizbody);
        }

        const attemptsHost = root.querySelector('[data-region="nxpro-quiz-attempts-host"]');
        if (attemptsHost) {
            attemptsHost.innerHTML = '';
        }

        const countEl = root.querySelector('[data-region="nxpro-quiz-attempt-count"]');
        if (countEl) {
            const count = parseInt(main.quizattemptcount || 0, 10) || 0;
            countEl.textContent = String(count);
            setHidden(countEl, count < 1);
        }

        const list = root.querySelector('[data-region="nxpro-quiz-attempts-list"]');
        const emptyAttempts = root.querySelector('[data-region="nxpro-quiz-attempts-empty"]');
        if (list) {
            list.innerHTML = '';
        }
        if (emptyAttempts) {
            setHidden(emptyAttempts, !!main.hasquizattempts);
        }

        setQuizTab(root, 'overview');

        deferQuizEnhance(root);
    };

    const applyPane = (root, data) => {
        const main = data.main || {};
        const pane = root.querySelector('[data-region="nxpro-pane"]');
        const eyebrow = root.querySelector('[data-region="nxpro-eyebrow-text"]');
        const title = root.querySelector('[data-region="nxpro-title"]');
        const section = root.querySelector('[data-region="nxpro-section"]');
        const status = root.querySelector('[data-region="nxpro-status"]');
        const statusGrid = root.querySelector('[data-region="nxpro-status-grid"]');
        const cta = root.querySelector('[data-region="nxpro-cta"]');
        const secondary = root.querySelector('[data-region="nxpro-secondary"]');
        const media = root.querySelector('[data-region="nxpro-media"]');
        const player = root.querySelector('[data-region="nxpro-player"]');
        const htmlBox = root.querySelector('[data-region="nxpro-html"]');
        const empty = root.querySelector('[data-region="nxpro-empty"]');
        const loading = root.querySelector('[data-region="nxpro-loading"]');

        cleanupOverlayChrome(root);

        if (pane) {
            pane.setAttribute('data-cmid', String(main.cmid || data.cmid || 0));
            pane.setAttribute('data-kind', String(main.kind || 'activity'));
            pane.className = 'nxpro-av nxpro-av--' + String(main.kind || 'activity');
            pane.classList.remove('is-loading');
        }

        // Sync rail checkmark after view completion may have flipped.
        const doneCmid = parseInt(main.cmid || data.cmid || 0, 10);
        if (doneCmid > 0) {
            root.querySelectorAll('.nxpro-act[data-cmid="' + doneCmid + '"]').forEach((row) => {
                row.classList.toggle('is-done', !!main.completed);
                const check = row.querySelector('.nxpro-act__check');
                if (check) {
                    check.classList.toggle('is-done', !!main.completed);
                }
            });
        }
        if (eyebrow) {
            eyebrow.textContent = main.eyebrow || main.kindlabel || '';
        }
        if (title) {
            title.textContent = main.title || '';
        }
        if (section) {
            section.textContent = main.sectionlabel || '';
            setHidden(section, !main.sectionlabel);
        }

        if (statusGrid) {
            statusGrid.innerHTML = chipHtml(main.statusitems);
            setHidden(statusGrid, !(main.statusitems && main.statusitems.length));
        }
        if (status) {
            // Quiz uses chips inside the Overview tab instead.
            const hasChips = !!(main.statusitems && main.statusitems.length);
            const hasCta = !!(main.showcta || main.showsecondary);
            const showStatus = (hasChips || hasCta || !!main.hasstatus) && !main.hasquiztabs
                && (hasChips || hasCta);
            setHidden(status, !showStatus);
        }
        const completion = root.querySelector('[data-region="nxpro-completion"]');
        const completionBody = root.querySelector('[data-region="nxpro-completion-body"]');
        const isH5pPane = String(main.kind || '') === 'h5p';
        if (completionBody) {
            if (isH5pPane) {
                completionBody.innerHTML = '';
            } else {
                completionBody.innerHTML = main.completionhtml || '';
            }
        }
        if (completion) {
            // For quiz Overview, criteria are moved into the status band by quizview.
            const hideOuter = !main.hascompletion || !!main.hasquiztabs;
            if (isH5pPane) {
                completion.classList.remove('nxpro-completion-ready');
            }
            setHidden(completion, isH5pPane ? true : hideOuter);
        }
        applyActivityScore(root, {
            completed: !!main.completed,
            hasactivitygrade: !!main.hasactivitygrade,
            hasgrade: !!main.hasactivitygrade,
            gradedisplay: main.gradedisplay || '',
        });
        if (cta) {
            if (main.showcta && main.ctaurl) {
                cta.setAttribute('href', main.ctaurl);
                cta.textContent = main.ctalabel || 'Open';
                setHidden(cta, false);
            } else {
                setHidden(cta, true);
            }
        }
        if (secondary) {
            if (main.showsecondary && main.secondaryurl) {
                secondary.setAttribute('href', main.secondaryurl);
                secondary.textContent = main.secondarylabel || 'Open';
                setHidden(secondary, false);
            } else {
                setHidden(secondary, true);
            }
        }

        const hasHtml = !!main.hashhtml && !!main.html;
        const hasMedia = !!main.hasmedia && !!main.mediaurl;
        if (htmlBox) {
            htmlBox.innerHTML = hasHtml ? main.html : '';
            setHidden(htmlBox, !hasHtml);
        }
        if (media) {
            setHidden(media, !hasMedia);
        }
        renderMedia(player, main);
        renderQuizPane(root, main);

        // H5P: hide status band; completion badges come from get_cm_progress only.
        if (isH5pPane) {
            if (status) {
                setHidden(status, true);
            }
            initH5pCompletionListener(root);
            const cmid = parseInt(main.cmid || data.cmid || 0, 10);
            const iframe = player ? player.querySelector('iframe.nxpro-av__iframe') : null;
            if (iframe && cmid) {
                hookH5pEmbedFrame(iframe, root, cmid);
            }
            if (cmid) {
                bootstrapH5pCompletion(root, cmid, data);
            }
            if (hasMedia && cmid) {
                startH5pCompletionWatch(root, cmid);
            } else {
                stopH5pCompletionWatch();
            }
        } else {
            stopH5pCompletionWatch();
            h5pBootstrapSeq += 1;
        }

        if (empty) {
            setHidden(empty, !!(main.hasactivity || hasHtml || hasMedia || main.showcta || main.hasquiztabs));
        }
        if (loading) {
            setHidden(loading, true);
        }

        root.classList.toggle('has-av', !!main.hasactivity);
        root.classList.remove('has-embed');

        renderNavButtons(root, data);
        markActive(root, parseInt(data.cmid || main.cmid || 0, 10));

        const mainEl = root.querySelector('[data-region="nxpro-main"]');
        if (mainEl) {
            mainEl.scrollTop = 0;
        }
    };

    const pushHistory = (root, data) => {
        const courseId = root.getAttribute('data-courseid') || '0';
        const courseUrl = root.getAttribute('data-courseurl') ||
            ('/course/view.php?id=' + courseId);
        const cmid = data.cmid || (data.main && data.main.cmid) || 0;
        const section = (data.main && data.main.sectionnum) || 0;
        let url = courseUrl;
        try {
            const u = new URL(courseUrl, window.location.origin);
            u.searchParams.set('id', courseId);
            if (section > 0) {
                u.searchParams.set('section', String(section));
            } else {
                u.searchParams.delete('section');
            }
            if (cmid > 0) {
                u.searchParams.set('cmid', String(cmid));
            }
            url = u.pathname + u.search;
        } catch (e) {
            url = '/course/view.php?id=' + courseId + '&cmid=' + cmid;
        }
        try {
            window.history.pushState({nxpro: true, cmid: cmid}, '', url);
        } catch (e) { /* ignore */ }
    };

    let loadingCmid = 0;

    const finishLoad = (root, data, opts, seq) => {
        if (seq !== loadSeq) {
            return;
        }
        loadingCmid = 0;
        userLoadActive = false;
        const cmid = parseInt(data.cmid || (data.main && data.main.cmid) || '0', 10);
        applyPane(root, mergeNavData(data, root, cmid));
        if (!opts.replace) {
            pushHistory(root, data);
        }
        prefetchNeighbors(root, cmid);
        pumpPrefetchQueue(root);
    };

    const loadActivity = (root, cmid, opts) => {
        opts = opts || {};
        cmid = parseInt(cmid || '0', 10);
        if (!cmid) {
            return;
        }
        // Opening an activity leaves the board, but reload should still restore Content.
        if (root.classList.contains('is-leaderboard')) {
            setAsideTab(root, 'content');
        }
        const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
        const pane = root.querySelector('[data-region="nxpro-pane"]');
        const current = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
        if (cmid === current && !opts.force) {
            return;
        }
        if (loadingCmid === cmid && !opts.force) {
            return;
        }
        loadingCmid = cmid;
        userLoadActive = true;
        const seq = ++loadSeq;

        applyOptimisticHeader(root, cmid);
        markActive(root, cmid);
        renderNavButtons(root, mergeNavData({
            hasprev: false,
            hasnext: false,
            prev: {id: 0, viewurl: '', name: ''},
            next: {id: 0, viewurl: '', name: ''},
        }, root, cmid));

        const cacheKey = paneCacheKey(courseId, cmid);
        const cached = !opts.force && paneCache.has(cacheKey);
        if (!cached) {
            setPaneLoading(root, true);
        }

        fetchPane(root, cmid, !!opts.force).then((data) => {
            finishLoad(root, data, opts, seq);
        }).catch((err) => {
            if (seq !== loadSeq) {
                return;
            }
            loadingCmid = 0;
            userLoadActive = false;
            setPaneLoading(root, false);
            pumpPrefetchQueue(root);
            Notification.exception(err);
        });
    };

    const initQuizTabs = (root) => {
        root.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-action="nxpro-quiz-tab"]');
            if (!tab || !root.contains(tab)) {
                return;
            }
            e.preventDefault();
            setQuizTab(root, tab.getAttribute('data-tab') || 'overview');
        });
    };

    const initSpaNav = (root) => {
        root.addEventListener('click', (e) => {
            const link = e.target.closest('[data-action="nxpro-nav"]');
            if (!link || !root.contains(link)) {
                return;
            }
            const cmid = parseInt(link.getAttribute('data-cmid') || '0', 10);
            if (!cmid) {
                return;
            }
            e.preventDefault();
            loadActivity(root, cmid);
            if (isMobileView()) {
                setRailCollapsed(root, true);
            }
        });

        // Editor (delete / add) asks the shell to swap the left pane without a full reload.
        root.addEventListener('nxpro:navigate', (e) => {
            const detail = (e && e.detail) || {};
            const cmid = parseInt(detail.cmid || '0', 10);
            if (!cmid) {
                return;
            }
            if (detail.force) {
                const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
                const key = paneCacheKey(courseId, cmid);
                paneCache.delete(key);
                inflightPane.delete(key);
            }
            loadActivity(root, cmid, {force: !!detail.force, replace: !!detail.replace});
        });

        window.addEventListener('popstate', (e) => {
            let cmid = 0;
            if (e.state && e.state.nxpro && e.state.cmid) {
                cmid = parseInt(e.state.cmid, 10);
            } else {
                try {
                    cmid = parseInt(new URL(window.location.href).searchParams.get('cmid') || '0', 10);
                } catch (err) {
                    cmid = 0;
                }
            }
            if (cmid > 0) {
                loadActivity(root, cmid, {replace: true});
            }
        });
    };

    /**
     * Manual completion badge: Mark as completed / Completed (toggle).
     *
     * @param {HTMLElement} root
     */
    const initManualCompletion = (root) => {
        if (!root || root.dataset.nxproManualCompletion === '1') {
            return;
        }
        root.dataset.nxproManualCompletion = '1';

        const paintManualButton = (btn, completed) => {
            const done = !!completed;
            btn.classList.toggle('nxpro-completion-crit--done', done);
            btn.classList.toggle('nxpro-completion-crit--todo', !done);
            btn.setAttribute('data-completed', done ? '1' : '0');
            btn.setAttribute('aria-pressed', done ? 'true' : 'false');
            btn.title = done ? 'Mark as not complete' : 'Mark as completed';
            let check = btn.querySelector('.nxpro-completion-crit__check');
            let label = btn.querySelector('.nxpro-completion-crit__label');
            if (!label) {
                label = document.createElement('span');
                label.className = 'nxpro-completion-crit__label';
                btn.appendChild(label);
            }
            label.textContent = done ? 'Completed' : 'Mark as completed';
            if (done) {
                if (!check) {
                    check = document.createElement('span');
                    check.className = 'nxpro-completion-crit__check';
                    check.setAttribute('aria-hidden', 'true');
                    btn.insertBefore(check, label);
                }
            } else if (check) {
                check.remove();
            }
        };

        const buildManualCompletionHtml = (cmid, completed) => {
            const done = !!completed;
            const label = done ? 'Completed' : 'Mark as completed';
            const title = done ? 'Mark as not complete' : 'Mark as completed';
            const check = done
                ? '<span class="nxpro-completion-crit__check" aria-hidden="true"></span>'
                : '';
            return '<div class="nxpro-completion-crits" data-region="nxpro-completion-crits">'
                + '<button type="button" class="nxpro-completion-crit nxpro-completion-crit--manual '
                + (done ? 'nxpro-completion-crit--done' : 'nxpro-completion-crit--todo') + '"'
                + ' data-action="nxpro-manual-complete" data-cmid="' + cmid + '"'
                + ' data-completed="' + (done ? '1' : '0') + '"'
                + ' aria-pressed="' + (done ? 'true' : 'false') + '"'
                + ' title="' + title + '">'
                + check
                + '<span class="nxpro-completion-crit__label">' + label + '</span>'
                + '</button></div>';
        };

        const patchCachedPaneCompletion = (courseId, cmid, completed) => {
            const key = paneCacheKey(courseId, cmid);
            if (!paneCache.has(key)) {
                return;
            }
            const data = paneCache.get(key);
            if (!data || !data.main) {
                return;
            }
            data.main.completed = !!completed;
            data.main.hascompletion = true;
            data.main.completionhtml = buildManualCompletionHtml(cmid, completed);
            if (!completed) {
                data.main.hasactivitygrade = false;
                data.main.gradedisplay = '';
            }
            paneCache.set(key, data);
        };

        root.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="nxpro-manual-complete"]');
            if (!btn || !root.contains(btn) || btn.disabled) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            const cmid = parseInt(btn.getAttribute('data-cmid') || '0', 10);
            if (!cmid) {
                return;
            }
            const currentlyDone = btn.getAttribute('data-completed') === '1';
            const nextCompleted = !currentlyDone;

            // Optimistic UI — feel instant; revert if the server rejects.
            const syncUi = (completed) => {
                root.querySelectorAll('[data-action="nxpro-manual-complete"][data-cmid="' + cmid + '"]')
                    .forEach((el) => paintManualButton(el, completed));
                root.querySelectorAll('.nxpro-act[data-cmid="' + cmid + '"]').forEach((row) => {
                    row.classList.toggle('is-done', completed);
                    const check = row.querySelector('.nxpro-act__check');
                    if (check) {
                        check.classList.toggle('is-done', completed);
                    }
                });
            };
            syncUi(nextCompleted);
            btn.disabled = true;

            const courseId = parseInt(root.getAttribute('data-courseid') || '0', 10);
            // Keep pane cache warm — only patch completion so returning is instant.
            patchCachedPaneCompletion(courseId, cmid, nextCompleted);

            Ajax.call([{
                methodname: 'core_completion_update_activity_completion_status_manually',
                args: {cmid: cmid, completed: nextCompleted},
            }])[0].then(() => {
                // Success — cache already patched; no heavy progress rebuild.
            }).catch((err) => {
                syncUi(currentlyDone);
                patchCachedPaneCompletion(courseId, cmid, currentlyDone);
                Notification.exception(err);
            }).then(() => {
                btn.disabled = false;
                btn.classList.remove('is-busy');
                root.querySelectorAll('[data-action="nxpro-manual-complete"][data-cmid="' + cmid + '"]')
                    .forEach((el) => {
                        el.disabled = false;
                        el.classList.remove('is-busy');
                    });
            });
        });
    };

    const init = (cfg) => {
        cfg = cfg || {};

        if (document.documentElement.dataset.nxproUiInit === '1') {
            if (cfg.reviewFullscreen || document.body.classList.contains('nxpro-review-fullscreen')) {
                document.body.classList.add('nxpro-review-fullscreen', 'nxpro-fullscreen');
                hideThemeChrome(cfg);
                [50, 200, 500].forEach((ms) => window.setTimeout(() => hideThemeChrome(cfg), ms));
            } else if (cfg.modpage || cfg.quizattemptback) {
                initModPageBack(cfg);
            } else if (!cfg.embed && !document.body.classList.contains('nxpro-embed')) {
                hideThemeChrome(cfg);
            }
            return;
        }
        document.documentElement.dataset.nxproUiInit = '1';

        if (cfg.issiteadmin) {
            document.body.classList.add('nxpro-siteadmin');
        }

        if (cfg.embed || document.body.classList.contains('nxpro-embed')) {
            document.body.classList.add('nxpro-embed');
            hideThemeChrome(cfg);
            [50, 200, 500].forEach((ms) => window.setTimeout(() => hideThemeChrome(cfg), ms));
            return;
        }

        // Quiz review opened from Attempts — full-screen, no site chrome.
        if (cfg.reviewFullscreen || document.body.classList.contains('nxpro-review-fullscreen')) {
            document.body.classList.add('nxpro-review-fullscreen', 'nxpro-fullscreen');
            hideThemeChrome(cfg);
            [50, 200, 500, 1200].forEach((ms) => window.setTimeout(() => hideThemeChrome(cfg), ms));
            return;
        }

        // Quiz attempt / other activity pages: back must return to Pro course shell.
        if (cfg.modpage || cfg.quizattemptback || document.body.classList.contains('nxpro-mod-page')) {
            initModPageBack(cfg);
            return;
        }

        // Native Moodle course editor — hide RemUI header/stats/unenroll (+ Edwiser layout for non-admins).
        if (document.body.classList.contains('editing') ||
                document.body.classList.contains('nxpro-native-edit') ||
                !document.querySelector('[data-region="nxpro-learn"]')) {
            document.body.classList.remove('nxpro-fullscreen');
            document.body.classList.remove('nxpro-scroll-locked');
            document.documentElement.classList.remove('nxpro-scroll-locked');
            document.documentElement.style.removeProperty('overflow');
            document.body.style.removeProperty('overflow');
            document.documentElement.style.removeProperty('height');
            document.body.style.removeProperty('height');
            if (document.body.classList.contains('editing') ||
                    document.body.classList.contains('nxpro-native-edit')) {
                polishNativeEditChrome(cfg);
                watchEdwiserLayoutChrome(cfg);
                [50, 200, 500, 1200, 2500].forEach((ms) => window.setTimeout(() => {
                    polishNativeEditChrome(cfg);
                }, ms));
                if (!document.body.dataset.nxproTabsFitBound) {
                    document.body.dataset.nxproTabsFitBound = '1';
                    window.addEventListener('resize', scheduleFitSecondaryNavTabs);
                    if (window.MutationObserver) {
                        let iconPass = 0;
                        const iconObs = new MutationObserver((mutations) => {
                            const readded = mutations.some((m) => {
                                return Array.from(m.addedNodes || []).some((n) => {
                                    if (!n || n.nodeType !== 1) {
                                        return false;
                                    }
                                    return n.classList && (
                                        n.classList.contains('nexcourse-nav-icon') ||
                                        n.classList.contains('edw-icon') ||
                                        n.matches && n.matches('.secondary-navigation .nav-link .icon, .secondary-navigation .nav-link i')
                                    ) || (n.querySelector && n.querySelector('.nexcourse-nav-icon, .edw-icon'));
                                });
                            });
                            if (!readded || iconPass > 40) {
                                return;
                            }
                            iconPass += 1;
                            stripSecondaryNavTabIcons();
                        });
                        const sec = document.querySelector('.secondary-navigation');
                        if (sec) {
                            iconObs.observe(sec, {childList: true, subtree: true});
                        }
                        window.setTimeout(() => {
                            try {
                                iconObs.disconnect();
                            } catch (e) { /* ignore */ }
                        }, 8000);
                    }
                }
            } else {
                watchEdwiserLayoutChrome(cfg);
            }
            return;
        }

        hideThemeChrome(cfg);
        syncNavOffset();
        lockPageScroll();

        const root = document.querySelector('[data-region="nxpro-learn"]');
        if (root) {
            initRail(root);
            initSearch(root);
            initAsideTabs(root);
            initQuizTabs(root);
            initSpaNav(root);
            initManualCompletion(root);
            initHoverPrefetch(root);
            initH5pCompletionListener(root);
            try {
                Editor.init(root);
            } catch (e) { /* ignore */ }
            root.classList.add('has-av');
            window.setTimeout(() => {
                try {
                    QuizView.enhance(root);
                } catch (e) { /* ignore */ }
            }, 50);
            // Warm neighbors of the SSR-loaded activity so the first click is steadier.
            const pane = root.querySelector('[data-region="nxpro-pane"]');
            const startCmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
            const startKind = pane ? String(pane.getAttribute('data-kind') || '') : '';
            if (startKind === 'h5p' && startCmid > 0) {
                bootstrapH5pCompletion(root, startCmid, null);
                startH5pCompletionWatch(root, startCmid);
            }
            const warm = () => {
                if (startCmid > 0) {
                    prefetchNeighbors(root, startCmid);
                }
            };
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(warm, {timeout: 1200});
            } else {
                window.setTimeout(warm, 400);
            }
        }

        window.addEventListener('resize', syncNavOffset);
        [50, 200, 500, 1000, 2000].forEach((ms) => {
            window.setTimeout(() => {
                hideThemeChrome(cfg);
                syncNavOffset();
            }, ms);
        });
    };

    return {init: init};
});
