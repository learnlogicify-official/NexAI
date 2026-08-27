/**
 * Collapsible left sidebar for RemUI (Nex product nav + utilities).
 *
 * @module     local_nexnavbar/sidebar
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const STORAGE_KEY = 'nxn_sidebar_collapsed';

    const ICONS = {
        dashboard: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
        course: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        practice: '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        codelab: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
        battle: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 17.5L3 6V3h3l11.5 11.5"/><path d="M13 19l6-6"/><path d="M16 16l4 4"/><path d="M19 21l2-2"/></svg>',
        interview: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>',
        reports: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 3 5-6"/></svg>',
        portfolio: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
        profile: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        messages: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        bell: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        settings: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
        logout: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>',
    };

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const readCollapsed = (fallback) => {
        try {
            const v = window.localStorage.getItem(STORAGE_KEY);
            if (v === '1') {
                return true;
            }
            if (v === '0') {
                return false;
            }
        } catch (e) {
            // Ignore.
        }
        return !!fallback;
    };

    const writeCollapsed = (collapsed) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            // Ignore.
        }
    };

    const iconHtml = (key) => ICONS[key] || ICONS.dashboard;

    const buildNav = (items) => {
        const parts = ['<nav class="nxn-sidebar__nav" aria-label="Nex">'];
        (items || []).forEach((item) => {
            const active = item.active ? ' is-active' : '';
            parts.push(
                '<a class="nxn-sidebar__link' + active + '" href="' + esc(item.url) + '"' +
                ' data-key="' + esc(item.key) + '"' +
                ' data-tip="' + esc(item.label) + '"' +
                ' title="' + esc(item.label) + '">' +
                '<span class="nxn-sidebar__ico">' + iconHtml(item.icon) + '</span>' +
                '<span class="nxn-sidebar__label">' + esc(item.label) + '</span>' +
                '</a>'
            );
        });
        parts.push('</nav>');
        return parts.join('');
    };

    const buildSidebar = (cfg) => {
        const s = cfg.strings || {};
        const u = cfg.user || {};
        const el = document.createElement('aside');
        el.id = 'nxn-sidebar';
        el.className = 'nxn-sidebar';
        el.setAttribute('aria-label', 'Nex navigation');

        el.innerHTML = [
            '<div class="nxn-sidebar__inner">',
            '  <a class="nxn-sidebar__profile" href="' + esc(u.profileurl) + '" data-tip="' + esc(s.profile || 'Profile') + '">',
            '    <img class="nxn-sidebar__avatar" src="' + esc(u.avatar) + '" alt="">',
            '    <span class="nxn-sidebar__who">',
            '      <strong class="nxn-sidebar__name">' + esc(u.fullname) + '</strong>',
            '      <span class="nxn-sidebar__email">' + esc(u.email) + '</span>',
            '    </span>',
            '    <span class="nxn-sidebar__profile-chev" aria-hidden="true">' + ICONS.chevron + '</span>',
            '  </a>',
            '  <button type="button" class="nxn-sidebar__toggle" aria-label="' + esc(s.toggle || 'Toggle') + '">',
            ICONS.chevron,
            '  </button>',
            '  <div class="nxn-sidebar__divider"></div>',
            buildNav(cfg.items),
            '  <div class="nxn-sidebar__spacer"></div>',
            '  <div class="nxn-sidebar__divider"></div>',
            '  <div class="nxn-sidebar__foot">',
            '    <div class="nxn-sidebar__utils" data-region="nxn-utils"></div>',
            '    <a class="nxn-sidebar__link" href="' + esc(cfg.messagesurl) + '" data-tip="' + esc(s.messages) + '" title="' + esc(s.messages) + '">',
            '      <span class="nxn-sidebar__ico">' + ICONS.messages + '</span>',
            '      <span class="nxn-sidebar__label">' + esc(s.messages) + '</span>',
            '    </a>',
            '    <a class="nxn-sidebar__link" href="' + esc(cfg.settingsurl) + '" data-tip="' + esc(s.settings) + '" title="' + esc(s.settings) + '">',
            '      <span class="nxn-sidebar__ico">' + ICONS.settings + '</span>',
            '      <span class="nxn-sidebar__label">' + esc(s.settings) + '</span>',
            '    </a>',
            '    <a class="nxn-sidebar__link nxn-sidebar__link--danger" href="' + esc(cfg.logouturl) + '" data-tip="' + esc(s.logout) + '" title="' + esc(s.logout) + '">',
            '      <span class="nxn-sidebar__ico">' + ICONS.logout + '</span>',
            '      <span class="nxn-sidebar__label">' + esc(s.logout) + '</span>',
            '    </a>',
            '  </div>',
            '</div>',
        ].join('');

        return el;
    };

    const findHeader = () => {
        return document.querySelector('.edw-header')
            || document.querySelector('header.navbar.fixed-top')
            || document.querySelector('nav.navbar.fixed-top')
            || document.querySelector('#page-wrapper > nav.navbar');
    };

    const relocateUtilities = (sidebar) => {
        const slot = sidebar.querySelector('[data-region="nxn-utils"]');
        if (!slot) {
            return;
        }
        const header = findHeader();
        if (!header) {
            return;
        }

        const selectors = [
            '.popover-region',
            '[data-region="popover-region-messages"]',
            '[data-region="popover-region-notifications"]',
            '#nav-notification-popover-container',
            '#nav-message-popover-container',
            '.usermenu',
            '#usernavigation .usermenu',
        ];

        const moved = new Set();
        selectors.forEach((sel) => {
            header.querySelectorAll(sel).forEach((node) => {
                if (moved.has(node) || slot.contains(node)) {
                    return;
                }
                // Prefer keeping RemUI message/notification popovers; skip duplicate usermenu text.
                if (node.classList.contains('usermenu')) {
                    return;
                }
                moved.add(node);
                const wrap = document.createElement('div');
                wrap.className = 'nxn-sidebar__util';
                wrap.appendChild(node);
                slot.appendChild(wrap);
            });
        });
    };

    const applyCollapsed = (collapsed, strings) => {
        document.body.classList.toggle('nxn-collapsed', collapsed);
        const btn = document.querySelector('.nxn-sidebar__toggle');
        if (btn) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.setAttribute('title', collapsed
                ? (strings.expand || 'Expand')
                : (strings.collapse || 'Collapse'));
        }
        writeCollapsed(collapsed);
        // Wait a frame so width transition / class applies, then measure.
        window.requestAnimationFrame(function() {
            syncPageOffset();
        });
    };

    /**
     * Measure the fixed sidebar and push page content clear of it.
     * Inline !important beats full-bleed plugin CSS (e.g. nxd-fullwidth padding:0).
     */
    const syncPageOffset = () => {
        const sidebar = document.getElementById('nxn-sidebar');
        const mobile = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
        const collapsed = document.body.classList.contains('nxn-collapsed');

        let offset = 0;
        if (!(mobile && collapsed) && sidebar) {
            const rect = sidebar.getBoundingClientRect();
            // right edge of sidebar + small gap
            offset = Math.max(0, Math.ceil(rect.right + 12));
        }

        document.documentElement.style.setProperty('--nxn-offset', offset + 'px');
        document.body.style.setProperty('--nxn-offset', offset + 'px');

        const targets = [
            document.getElementById('page'),
            document.getElementById('page-wrapper'),
            document.getElementById('page-content'),
            document.getElementById('topofscroll'),
            document.getElementById('region-main-box'),
            document.querySelector('.main-inner'),
        ];
        targets.forEach(function(el) {
            if (!el) {
                return;
            }
            el.style.setProperty('padding-left', offset + 'px', 'important');
            el.style.setProperty('margin-left', '0px', 'important');
            el.style.setProperty('box-sizing', 'border-box', 'important');
        });
    };

    const init = (cfg) => {
        if (!cfg || document.getElementById('nxn-sidebar')) {
            return;
        }

        document.body.classList.add('nxn-sidebar-enabled');

        const collapsed = readCollapsed(!!cfg.collapsed);
        const sidebar = buildSidebar(cfg);
        document.body.appendChild(sidebar);

        // Hide top chrome after sidebar exists.
        document.body.classList.add('nxn-ready');
        applyCollapsed(collapsed, cfg.strings || {});

        relocateUtilities(sidebar);
        syncPageOffset();

        const toggle = sidebar.querySelector('.nxn-sidebar__toggle');
        if (toggle) {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const next = !document.body.classList.contains('nxn-collapsed');
                applyCollapsed(next, cfg.strings || {});
            });
        }

        // Mobile: start collapsed under 900px unless user expanded.
        if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
            if (window.localStorage.getItem(STORAGE_KEY) === null) {
                applyCollapsed(true, cfg.strings || {});
            }
        }

        window.addEventListener('resize', function() {
            syncPageOffset();
        });
    };

    return {init: init};
});
