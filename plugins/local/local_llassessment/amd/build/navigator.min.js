/**
 * Question Navigator — coding-platform style sidebar matching the reference UI.
 *
 * @module     local_llassessment/navigator
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const NAV_COLLAPSE_KEY = 'll_arena_nav_collapsed';

    /**
     * @return {boolean}
     */
    const readCollapsed = function() {
        try {
            return window.localStorage.getItem(NAV_COLLAPSE_KEY) === '1';
        } catch (e) {
            return false;
        }
    };

    /**
     * @param {boolean} collapsed
     */
    const writeCollapsed = function(collapsed) {
        try {
            window.localStorage.setItem(NAV_COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            // Ignore.
        }
    };

    /**
     * @param {Element} sidebar
     * @param {boolean} collapsed
     */
    const applyCollapsed = function(sidebar, collapsed) {
        document.body.classList.toggle('ll-nav-collapsed', collapsed);
        if (sidebar) {
            sidebar.classList.toggle('is-collapsed', collapsed);
        }
        const collapseBtn = sidebar && sidebar.querySelector('.ll-nav__collapse');
        if (collapseBtn) {
            const span = collapseBtn.querySelector('span');
            if (span) {
                span.textContent = collapsed ? '‹' : '›';
            }
            collapseBtn.title = collapsed ? 'Expand navigator' : 'Collapse navigator';
            collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    };

    const isAnswered = function(btn) {
        const cls = (btn.className || '').toLowerCase();
        if (btn.classList.contains('is-answered')) {
            return true;
        }
        if (/notyetanswered|invalidanswer|requiresgrading/.test(cls)) {
            return false;
        }
        // Moodle: answers, answersaved, complete, correct, incorrect, partial, etc.
        return /\banswers?\b|\bcomplete\b|\bcorrect\b|\bincorrect\b|\bpartial\b|\bsubmitted\b/.test(cls)
            || btn.classList.contains('answers')
            || btn.classList.contains('answersaved');
    };

    /**
     * Current page has a filled response not yet reflected on the nav button.
     *
     * @return {boolean}
     */
    const currentPageHasAnswer = function() {
        const form = document.getElementById('responseform')
            || document.querySelector('#ll-arena-workspace form.ll-arena-responseform')
            || document.querySelector('form.ll-arena-responseform');
        if (!form) {
            return false;
        }
        form.querySelectorAll('.ace_editor').forEach(function(aceEl) {
            try {
                if (window.ace) {
                    const ed = window.ace.edit(aceEl);
                    if (ed && ed.getValue) {
                        const ta = aceEl.parentElement && aceEl.parentElement.querySelector('textarea');
                        if (ta) {
                            ta.value = ed.getValue();
                        }
                    }
                }
            } catch (e) {}
        });
        const inFlag = function(el) {
            return !!(el.closest && el.closest('.questionflag, .ll-arena-flag, .ll-arena-flag-source'));
        };
        const radios = form.querySelectorAll(
            '.answer input[type="radio"], .formulation input[type="radio"], input[type="radio"][name*="answer"]'
        );
        for (let i = 0; i < radios.length; i++) {
            if (radios[i].checked && !inFlag(radios[i])) {
                return true;
            }
        }
        const checks = form.querySelectorAll(
            '.answer input[type="checkbox"], input[type="checkbox"][name*="answer"]'
        );
        for (let j = 0; j < checks.length; j++) {
            if (checks[j].checked && !inFlag(checks[j])) {
                return true;
            }
        }
        const texts = form.querySelectorAll(
            'textarea[name*="answer"], input[type="text"][name*="answer"],' +
            ' .answer input[type="text"], .coderunner-answer textarea, .ll-cr-ide textarea'
        );
        for (let k = 0; k < texts.length; k++) {
            if (!inFlag(texts[k]) && (texts[k].value || '').trim() !== '') {
                return true;
            }
        }
        const selects = form.querySelectorAll('select[name*="answer"], .answer select');
        for (let s = 0; s < selects.length; s++) {
            const val = (selects[s].value || '').trim();
            if (val !== '' && val !== '0') {
                return true;
            }
        }
        return false;
    };

    const isCurrent = function(btn) {
        return btn.classList.contains('thispage')
            || btn.classList.contains('is-current')
            || btn.getAttribute('aria-current') === 'page';
    };

    const buttonLabel = function(btn) {
        let text = '';
        Array.prototype.forEach.call(btn.childNodes, function(n) {
            if (n.nodeType === 3) {
                text += n.textContent;
            } else if (n.nodeType === 1 && !n.classList.contains('accesshide')
                && !n.classList.contains('thispageholder')
                && !n.classList.contains('trafficlight')
                && !n.classList.contains('flagstate')) {
                if (!n.querySelector || !n.matches('.sr-only')) {
                    const t = (n.textContent || '').trim();
                    if (/^\d+$/.test(t)) {
                        text = t;
                    }
                }
            }
        });
        text = (text || btn.textContent || '').replace(/\s+/g, ' ').trim();
        const num = text.match(/\d+/);
        return num ? num[0] : text;
    };

    const isSectionHeading = function(el) {
        if (!el || el.nodeType !== 1) {
            return false;
        }
        return el.matches(
            'h2.mod_quiz-section-heading, h3.mod_quiz-section-heading, h4.mod_quiz-section-heading,' +
            ' .mod_quiz-section-heading, h3.sectionheading, .qnsectionheading, .sectionname'
        );
    };

    const isQnButton = function(el) {
        if (!el || el.nodeType !== 1) {
            return false;
        }
        return el.classList.contains('qnbutton') || el.classList.contains('ll-arena-qn')
            || el.matches('a.qnbutton, a.ll-arena-qn');
    };

    /**
     * Walk Moodle nav DOM in order and group buttons under section headings.
     *
     * @param {Element} slot
     * @return {Array<{title: string, buttons: Element[]}>}
     */
    const collectSections = function(slot) {
        const sections = [];
        let current = {title: '', buttons: []};

        const pushCurrent = function() {
            if (current.buttons.length || current.title) {
                sections.push(current);
            }
            current = {title: '', buttons: []};
        };

        const visit = function(node) {
            if (!node || node.nodeType !== 1) {
                return;
            }
            if (isSectionHeading(node)) {
                if (current.buttons.length || current.title) {
                    pushCurrent();
                }
                current.title = (node.textContent || '').replace(/\s+/g, ' ').trim();
                return;
            }
            if (isQnButton(node)) {
                current.buttons.push(node);
                return;
            }
            // Containers that may hold headings + buttons (do not descend into leftover chrome).
            if (node.matches(
                '.qn_buttons, .ll-arena-nav__grid, .content, .card-text, .edw-block-body,' +
                ' .block-body-wrapper, .card-body, [data-region="content"]'
            ) || (node.querySelector
                && node.querySelector('.qnbutton, .mod_quiz-section-heading')
                && !node.matches('.othernav, .ll-nav, .ll-nav__moodle-rest'))) {
                Array.prototype.forEach.call(node.children, visit);
            }
        };

        const grids = slot.querySelectorAll('.qn_buttons');
        if (grids.length) {
            grids.forEach(function(g) {
                Array.prototype.forEach.call(g.children, visit);
            });
        } else {
            Array.prototype.forEach.call(slot.children, visit);
        }
        pushCurrent();

        // Fallback: flat list of all buttons if walk found none.
        const totalBtns = sections.reduce(function(n, s) {
            return n + s.buttons.length;
        }, 0);
        if (!totalBtns) {
            const all = Array.prototype.slice.call(
                slot.querySelectorAll('a.qnbutton, .qnbutton, a.ll-arena-qn')
            );
            if (all.length) {
                return [{title: '', buttons: all}];
            }
        }
        return sections.filter(function(s) {
            return s.buttons.length > 0;
        });
    };

    /**
     * @param {Element} btn
     */
    const decorateButton = function(btn) {
        btn.classList.add('ll-nav__btn');
        btn.querySelectorAll('.thispageholder, .trafficlight, .flagstate, .accesshide').forEach(function(el) {
            el.classList.add('ll-nav-hide');
        });
        const label = buttonLabel(btn);
        btn.setAttribute('data-ll-nav-num', label);
        if (!btn.querySelector('.ll-nav__num')) {
            const numSpan = document.createElement('span');
            numSpan.className = 'll-nav__num';
            numSpan.textContent = label;
            Array.prototype.slice.call(btn.childNodes).forEach(function(n) {
                if (n.nodeType === 1) {
                    n.classList.add('ll-nav-hide');
                } else if (n.nodeType === 3) {
                    n.textContent = '';
                }
            });
            btn.appendChild(numSpan);
        }
        if (isCurrent(btn)) {
            btn.classList.add('is-current');
        }
        if (isAnswered(btn)) {
            btn.classList.add('is-answered');
        }
        if (!btn.querySelector('.ll-nav__check')) {
            const mark = document.createElement('span');
            mark.className = 'll-nav__check';
            mark.setAttribute('aria-hidden', 'true');
            mark.innerHTML =
                '<svg width="10" height="10" viewBox="0 0 12 12" fill="none">' +
                    '<path d="M2.5 6.2L4.8 8.5L9.5 3.5" stroke="currentColor" stroke-width="2" ' +
                    'stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>';
            btn.appendChild(mark);
        }
    };

    /**
     * @param {Element} sidebar
     * @param {Object} [opts]
     */
    const enhance = function(sidebar, opts) {
        opts = opts || {};
        if (!sidebar) {
            return;
        }
        if (sidebar.querySelector('.ll-nav')) {
            refresh(sidebar);
            return;
        }

        const slot = sidebar.querySelector('#ll-arena-sidebar-slot') || sidebar;
        const sections = collectSections(slot);
        if (!sections.length) {
            return;
        }

        const oldHead = sidebar.querySelector('.ll-arena__sidebar-head');
        if (oldHead) {
            oldHead.remove();
        }

        const nav = document.createElement('div');
        nav.className = 'll-nav';

        const head = document.createElement('div');
        head.className = 'll-nav__head';
        head.innerHTML =
            '<button type="button" class="ll-nav__collapse" title="Collapse navigator" aria-label="Collapse navigator">' +
                '<span aria-hidden="true">›</span>' +
            '</button>' +
            '<h2 class="ll-nav__title">Question Navigator</h2>';

        const card = document.createElement('div');
        card.className = 'll-nav__card';

        const summary = document.createElement('div');
        summary.className = 'll-nav__summary';
        summary.innerHTML =
            '<div class="ll-nav__summary-text">' +
                '<div class="ll-nav__cat" data-ll-nav="cat"></div>' +
                '<div class="ll-nav__answered" data-ll-nav="answered"></div>' +
            '</div>' +
            '<div class="ll-nav__ring" data-ll-nav="ring" aria-hidden="true"></div>';

        const sectionsHost = document.createElement('div');
        sectionsHost.className = 'll-nav__sections';
        sectionsHost.setAttribute('role', 'navigation');
        sectionsHost.setAttribute('aria-label', 'Questions by section');

        const namedSections = sections.filter(function(s) {
            return !!s.title;
        });
        const useSectionSplitters = namedSections.length > 0;
        const useSectionTabs = !!opts.sectionTabs && namedSections.length > 0;
        const attemptedOnlySelected = !!opts.attemptedOnlySelected;

        if (useSectionTabs) {
            nav.classList.add('ll-nav--section-tabs');
        }
        if (attemptedOnlySelected) {
            nav.classList.add('ll-nav--attempted-selected');
        }

        let tabsHost = null;
        if (useSectionTabs) {
            tabsHost = document.createElement('div');
            tabsHost.className = 'll-nav__tabs';
            tabsHost.setAttribute('role', 'tablist');
            tabsHost.setAttribute('aria-label', 'Sections');
            sectionsHost.classList.add('ll-nav__sections--tabs');
        }

        // Prefer tab that contains the current question; else restore last tab.
        let activeTabIdx = 0;
        let foundCurrent = false;
        if (useSectionTabs) {
            sections.forEach(function(sec, idx) {
                sec.buttons.forEach(function(btn) {
                    if (isCurrent(btn)) {
                        activeTabIdx = idx;
                        foundCurrent = true;
                    }
                });
            });
            if (!foundCurrent) {
                try {
                    const stored = window.sessionStorage.getItem('ll_review_nav_tab');
                    if (stored !== null && stored !== '') {
                        const n = parseInt(stored, 10);
                        if (!isNaN(n) && n >= 0 && n < sections.length) {
                            activeTabIdx = n;
                        }
                    }
                } catch (e) {
                    // Ignore.
                }
            }
        }

        sections.forEach(function(sec, idx) {
            const block = document.createElement('div');
            block.className = 'll-nav__section';
            block.setAttribute('data-ll-section-idx', String(idx));

            if (useSectionTabs) {
                const tabId = 'll-nav-tab-' + idx;
                const panelId = 'll-nav-panel-' + idx;
                const tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'll-nav__tab' + (idx === activeTabIdx ? ' is-active' : '');
                tab.setAttribute('role', 'tab');
                tab.setAttribute('id', tabId);
                tab.setAttribute('aria-controls', panelId);
                tab.setAttribute('aria-selected', idx === activeTabIdx ? 'true' : 'false');
                tab.textContent = sec.title || ('Section ' + (idx + 1));
                tab.addEventListener('click', function() {
                    sectionsHost.querySelectorAll('.ll-nav__section').forEach(function(panel, pidx) {
                        const on = pidx === idx;
                        panel.classList.toggle('is-active', on);
                        panel.hidden = !on;
                    });
                    tabsHost.querySelectorAll('.ll-nav__tab').forEach(function(t, tidx) {
                        const on = tidx === idx;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    try {
                        window.sessionStorage.setItem('ll_review_nav_tab', String(idx));
                    } catch (e2) {
                        // Ignore.
                    }
                    const cat = nav.querySelector('[data-ll-nav="cat"]');
                    if (cat) {
                        cat.textContent = sec.title || ('Section ' + (idx + 1));
                    }
                    const badge = document.querySelector('[data-ll-section-badge]');
                    if (badge) {
                        badge.textContent = sec.title || '';
                        if (sec.title) {
                            badge.removeAttribute('hidden');
                        }
                    }
                });
                tabsHost.appendChild(tab);

                block.id = panelId;
                block.setAttribute('role', 'tabpanel');
                block.setAttribute('aria-labelledby', tabId);
                block.classList.toggle('is-active', idx === activeTabIdx);
                block.hidden = idx !== activeTabIdx;
            } else if (useSectionSplitters) {
                const title = document.createElement('div');
                title.className = 'll-nav__section-title';
                title.textContent = sec.title || ('Section ' + (idx + 1));
                block.appendChild(title);
            }

            const grid = document.createElement('div');
            grid.className = 'll-nav__grid';
            if (sec.title) {
                grid.setAttribute('aria-label', sec.title);
            } else {
                grid.setAttribute('aria-label', 'Questions');
            }

            sec.buttons.forEach(function(btn) {
                decorateButton(btn);
                if (attemptedOnlySelected) {
                    // Review: only attempted questions look "selected".
                    btn.classList.remove('is-current');
                    btn.classList.toggle('is-selected', isAnswered(btn));
                    btn.classList.toggle('is-answered', isAnswered(btn));
                }
                grid.appendChild(btn);
            });

            block.appendChild(grid);
            sectionsHost.appendChild(block);
        });

        const progress = document.createElement('div');
        progress.className = 'll-nav__progress';
        progress.innerHTML =
            '<div class="ll-nav__progress-head">' +
                '<span>Overall Progress</span>' +
                '<span class="ll-nav__pct" data-ll-nav="pct">0%</span>' +
            '</div>' +
            '<div class="ll-nav__bar"><div class="ll-nav__bar-fill" data-ll-nav="bar"></div></div>' +
            '<div class="ll-nav__progress-foot">' +
                '<span data-ll-nav="answered-foot">0 answered</span>' +
                '<span data-ll-nav="total-foot">0 questions</span>' +
            '</div>';

        const leftovers = document.createElement('div');
        leftovers.className = 'll-nav__moodle-rest ll-cr-hidden';
        while (slot.firstChild) {
            leftovers.appendChild(slot.firstChild);
        }

        card.appendChild(summary);
        if (tabsHost) {
            card.appendChild(tabsHost);
        }
        card.appendChild(sectionsHost);
        card.appendChild(progress);
        nav.appendChild(head);
        nav.appendChild(card);
        nav.appendChild(leftovers);

        slot.appendChild(nav);

        const catEl = nav.querySelector('[data-ll-nav="cat"]');
        if (catEl) {
            if (useSectionTabs) {
                catEl.textContent = (sections[activeTabIdx] && sections[activeTabIdx].title)
                    || opts.categoryLabel
                    || 'Review';
            } else if (useSectionSplitters && namedSections.length > 1) {
                catEl.textContent = 'All sections';
            } else if (useSectionSplitters && namedSections.length === 1) {
                catEl.textContent = namedSections[0].title;
            } else {
                catEl.textContent = opts.categoryLabel || 'Questions';
            }
        }

        const collapseBtn = nav.querySelector('.ll-nav__collapse');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function() {
                const collapsed = !sidebar.classList.contains('is-collapsed');
                applyCollapsed(sidebar, collapsed);
                writeCollapsed(collapsed);
                try {
                    window.dispatchEvent(new Event('resize'));
                } catch (e) {
                    // Ignore.
                }
            });
        }

        // Restore collapse preference (survives soft-nav + full reload).
        // On mobile the sidebar is an off-canvas drawer — never apply the desktop rail collapse.
        let mobile = false;
        try {
            mobile = window.matchMedia('(max-width: 900px)').matches;
        } catch (e0) {
            mobile = window.innerWidth <= 900;
        }
        if (!mobile) {
            applyCollapsed(sidebar, readCollapsed());
        } else {
            applyCollapsed(sidebar, false);
            document.body.classList.remove('ll-nav-collapsed');
            sidebar.classList.remove('is-collapsed');
        }

        refresh(sidebar);
    };

    /**
     * Update counts / progress ring from button states.
     *
     * @param {Element} sidebar
     */
    const refresh = function(sidebar) {
        if (!sidebar) {
            return;
        }
        const nav = sidebar.querySelector('.ll-nav');
        if (!nav) {
            return;
        }
        const buttons = Array.prototype.slice.call(nav.querySelectorAll('.ll-nav__btn'));
        const total = buttons.length;
        let answered = 0;
        buttons.forEach(function(btn) {
            let answeredNow = isAnswered(btn);
            // Current question: count in-form answers before Moodle updates the nav.
            if (!answeredNow && isCurrent(btn) && currentPageHasAnswer()) {
                answeredNow = true;
            }
            btn.classList.toggle('is-answered', answeredNow);
            if (nav.classList.contains('ll-nav--attempted-selected')) {
                btn.classList.remove('is-current');
                btn.classList.toggle('is-selected', answeredNow);
            } else {
                btn.classList.toggle('is-current', isCurrent(btn));
            }
            if (answeredNow) {
                answered += 1;
            }
        });
        const pct = total ? Math.round((answered / total) * 100) : 0;

        const answeredEl = nav.querySelector('[data-ll-nav="answered"]');
        if (answeredEl) {
            answeredEl.textContent = answered + '/' + total + ' questions answered';
        }
        const pctEl = nav.querySelector('[data-ll-nav="pct"]');
        if (pctEl) {
            pctEl.textContent = pct + '%';
        }
        const bar = nav.querySelector('[data-ll-nav="bar"]');
        if (bar) {
            bar.style.width = pct + '%';
        }
        const footA = nav.querySelector('[data-ll-nav="answered-foot"]');
        if (footA) {
            footA.textContent = answered + ' answered';
        }
        const footT = nav.querySelector('[data-ll-nav="total-foot"]');
        if (footT) {
            footT.textContent = total + ' question' + (total === 1 ? '' : 's');
        }

        const ring = nav.querySelector('[data-ll-nav="ring"]');
        if (ring) {
            const r = 18;
            const c = 2 * Math.PI * r;
            const offset = c - (pct / 100) * c;
            ring.innerHTML =
                '<svg viewBox="0 0 44 44" width="52" height="52" aria-hidden="true">' +
                    '<circle class="ll-nav__ring-bg" cx="22" cy="22" r="' + r + '" fill="none" stroke-width="4"/>' +
                    '<circle class="ll-nav__ring-fg" cx="22" cy="22" r="' + r + '" fill="none" stroke-width="4" ' +
                        'stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + offset.toFixed(2) + '" ' +
                        'transform="rotate(-90 22 22)"/>' +
                    '<text class="ll-nav__ring-text" x="22" y="22" text-anchor="middle" dominant-baseline="central">' +
                        pct + '%' +
                    '</text>' +
                '</svg>';
        }
    };

    return {
        enhance: enhance,
        refresh: refresh,
        applyCollapsed: applyCollapsed,
        readCollapsed: readCollapsed
    };
});
