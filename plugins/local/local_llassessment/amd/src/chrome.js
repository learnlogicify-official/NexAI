/**
 * Arena chrome polish — topbar progress, full-bleed footer, question status.
 *
 * @module     local_llassessment/chrome
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const ensureFooter = function() {
        const arena = document.getElementById('ll-arena');
        if (!arena) {
            return null;
        }
        let footer = document.getElementById('ll-arena-footer');
        if (!footer) {
            footer = document.createElement('footer');
            footer.id = 'll-arena-footer';
            footer.className = 'll-arena__footer';
            footer.innerHTML =
                '<div class="ll-arena__footer-side ll-arena__footer-side--prev" data-ll-foot="prev"></div>' +
                '<div class="ll-arena__footer-status" data-ll-foot="status">Question</div>' +
                '<div class="ll-arena__footer-side ll-arena__footer-side--next" data-ll-foot="next"></div>';
            arena.appendChild(footer);
        }
        return footer;
    };

    const findResponseForm = function() {
        return document.getElementById('responseform')
            || document.querySelector('#ll-arena-workspace form.ll-arena-responseform')
            || document.querySelector('#ll-arena-workspace form')
            || document.querySelector('form.ll-arena-responseform');
    };

    /**
     * Locate Moodle previous/next submit controls (any common markup).
     *
     * @param {HTMLFormElement} form
     * @param {string} which previous|next
     * @return {HTMLElement|null}
     */
    const findNavControl = function(form, which) {
        if (!form) {
            return null;
        }
        const nameExact = which === 'previous' ? 'previous' : 'next';
        const selectors = [
            'input[type="submit"][name="' + nameExact + '"]',
            'button[type="submit"][name="' + nameExact + '"]',
            'input[type="submit"][name*="' + nameExact + '"]',
            'button[type="submit"][name*="' + nameExact + '"]',
            'button[name="' + nameExact + '"]',
            'input[name="' + nameExact + '"]'
        ];
        let found = null;
        selectors.some(function(sel) {
            const nodes = form.querySelectorAll(sel);
            for (let i = 0; i < nodes.length; i++) {
                const el = nodes[i];
                if (el.closest('.ll-cr-ide__actions, .im-controls, .ll-cr-ide')) {
                    continue;
                }
                // Avoid matching hidden nextpage field.
                if (el.type === 'hidden') {
                    continue;
                }
                found = el;
                return true;
            }
            return false;
        });
        if (found) {
            return found;
        }

        // Fallback: label text / value.
        const all = form.querySelectorAll('input[type="submit"], button[type="submit"], button.btn');
        for (let i = 0; i < all.length; i++) {
            const el = all[i];
            if (el.closest('.ll-cr-ide__actions, .im-controls, .ll-cr-ide')) {
                continue;
            }
            const key = ((el.name || '') + ' ' + (el.value || '') + ' ' + (el.textContent || '')).toLowerCase();
            if (which === 'previous' && /previous|\bprev\b|‹|«/.test(key) && !/precheck/.test(key)) {
                return el;
            }
            if (which === 'next' && /\bnext\b|›|»/.test(key) && !/nextpage/.test(key)) {
                return el;
            }
        }
        return null;
    };

    /**
     * Current question index (1-based) and total from the navigator.
     *
     * @return {{idx: number, total: number, isFirst: boolean, isLast: boolean}}
     */
    const getQuestionPosition = function() {
        const all = Array.prototype.slice.call(document.querySelectorAll(
            '.ll-nav__btn, .ll-nav .qnbutton, .ll-arena-sidebar-slot .qnbutton'
        ));
        // Prefer our enhanced nav buttons only once.
        const buttons = document.querySelectorAll('.ll-nav__btn');
        const list = buttons.length ? Array.prototype.slice.call(buttons) : all;
        const total = list.length || 0;
        let idx = 1;
        const current = document.querySelector(
            '.ll-nav__btn.is-current, .ll-nav__btn.thispage, .qnbutton.thispage'
        );
        if (current && total) {
            const n = current.getAttribute('data-ll-nav-num')
                || (current.textContent || '').replace(/\D/g, '');
            if (n) {
                idx = parseInt(n, 10) || 1;
            } else {
                const pos = list.indexOf(current);
                if (pos >= 0) {
                    idx = pos + 1;
                }
            }
        } else {
            const title = document.querySelector('.ll-arena-qhead__title');
            const m = title && (title.textContent || '').match(/(\d+)/);
            if (m) {
                idx = parseInt(m[1], 10) || 1;
            }
            // thispage hidden field is 0-based page index in Moodle.
            const form = findResponseForm();
            const pageField = form && (form.querySelector('#followingpage') || form.querySelector('input[name="thispage"]'));
            if (pageField && pageField.defaultValue !== '' && total) {
                // Prefer navigator when available; page field is fallback only.
            }
        }
        if (!total) {
            return {idx: idx, total: idx, isFirst: idx <= 1, isLast: true};
        }
        return {
            idx: idx,
            total: total,
            isFirst: idx <= 1,
            isLast: idx >= total
        };
    };

    /**
     * Build a visible footer control. Keeps the real Moodle control in the form
     * (hidden) so soft-nav / FormData stay reliable, and proxies the click.
     *
     * @param {HTMLElement|null} realBtn
     * @param {string} which
     * @param {string} label
     * @param {boolean} forceDisable
     * @return {HTMLButtonElement}
     */
    const makeProxy = function(realBtn, which, label, forceDisable) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'll-arena__navbtn ll-arena__navbtn--' + (which === 'previous' ? 'prev' : 'next');
        btn.setAttribute('data-ll-nav-proxy', which);
        btn.textContent = label;

        const unavailable = !realBtn;
        const moodleDisabled = !!(realBtn && (realBtn.disabled
            || realBtn.getAttribute('aria-disabled') === 'true'
            || realBtn.classList.contains('disabled')));
        const disabled = unavailable || moodleDisabled || !!forceDisable;

        if (disabled) {
            btn.disabled = true;
            btn.classList.add('ll-arena__navbtn--ghost');
            if (forceDisable && which === 'previous') {
                btn.title = 'You are on the first question';
            } else if (forceDisable && which === 'next') {
                btn.title = 'You are on the last question';
            } else {
                btn.title = unavailable ? 'Not available' : 'Not available on this page';
            }
        }

        if (realBtn) {
            // Park the real control in the form, visually hidden.
            realBtn.classList.add('ll-cr-hidden', 'll-arena__nav-source');
            realBtn.setAttribute('data-ll-nav-source', which);
            // Clicks are handled by soft_nav via [data-ll-nav-proxy].
        }

        return btn;
    };

    /**
     * Pull Moodle previous/next into the arena footer and restyle.
     */
    const mountFooter = function() {
        const footer = ensureFooter();
        if (!footer) {
            return;
        }
        const prevHost = footer.querySelector('[data-ll-foot="prev"]');
        const nextHost = footer.querySelector('[data-ll-foot="next"]');
        const status = footer.querySelector('[data-ll-foot="status"]');
        if (!prevHost || !nextHost) {
            return;
        }

        const form = findResponseForm();
        if (!form) {
            return;
        }

        // Ensure nav wrappers exist for discovery after soft-nav.
        form.querySelectorAll('.submitbtns').forEach(function(btns) {
            if (!btns.closest('.ll-arena-nav-buttons') && !btns.closest('.ll-cr-ide')) {
                const navWrap = document.createElement('div');
                navWrap.className = 'll-arena-nav-buttons';
                btns.parentNode.insertBefore(navWrap, btns);
                navWrap.appendChild(btns);
            }
        });

        let prevBtn = findNavControl(form, 'previous');
        let nextBtn = findNavControl(form, 'next');

        // Also accept controls already parked from a prior mount.
        if (!prevBtn) {
            prevBtn = form.querySelector('[data-ll-nav-source="previous"]');
        }
        if (!nextBtn) {
            nextBtn = form.querySelector('[data-ll-nav-source="next"]');
        }

        const pos = getQuestionPosition();

        prevHost.innerHTML = '';
        nextHost.innerHTML = '';
        prevHost.appendChild(makeProxy(prevBtn, 'previous', '‹  Previous', pos.isFirst));
        nextHost.appendChild(makeProxy(nextBtn, 'next', 'Next  ›', pos.isLast));

        // Hide empty Moodle nav strips in the workspace.
        form.querySelectorAll('.ll-arena-nav-buttons, .submitbtns').forEach(function(el) {
            if (el.closest('.ll-cr-ide')) {
                return;
            }
            el.classList.add('ll-cr-hidden');
        });

        updateQuestionStatus(status, pos);
    };

    const updateQuestionStatus = function(statusEl, pos) {
        const el = statusEl || document.querySelector('[data-ll-foot="status"]');
        if (!el) {
            return;
        }
        pos = pos || getQuestionPosition();
        el.textContent = 'Question ' + pos.idx + ' of ' + (pos.total || pos.idx);
    };

    /**
     * Sync topbar badge with the current question's section name.
     */
    const syncSectionBadge = function() {
        const badge = document.querySelector('[data-ll-section-badge], .ll-arena__section');
        if (!badge) {
            return;
        }
        let name = '';
        const current = document.querySelector(
            '.ll-nav__btn.is-current, .ll-nav__btn.thispage, .qnbutton.thispage, .qnbutton.is-current'
        );
        if (current) {
            const section = current.closest('.ll-nav__section');
            const title = section && section.querySelector('.ll-nav__section-title');
            if (title) {
                name = (title.textContent || '').replace(/\s+/g, ' ').trim();
            }
        }
        // Fallback: first section title if current not found yet.
        if (!name) {
            const first = document.querySelector('.ll-nav__section-title');
            if (first) {
                name = (first.textContent || '').replace(/\s+/g, ' ').trim();
            }
        }
        if (name) {
            badge.textContent = name;
            badge.removeAttribute('hidden');
            badge.classList.remove('ll-cr-hidden');
            badge.setAttribute('title', name);
        } else {
            badge.textContent = '';
            badge.setAttribute('hidden', 'hidden');
        }
    };

    /**
     * Sync topbar progress from navigator answered ratio.
     */
    const syncTopProgress = function() {
        const fill = document.querySelector('[data-ll-top-progress-fill]');
        const label = document.querySelector('[data-ll-top-progress-label]');
        const buttons = document.querySelectorAll('.ll-nav__btn');
        if (!fill && !label) {
            return;
        }
        let answered = 0;
        const total = buttons.length;
        buttons.forEach(function(btn) {
            if (btn.classList.contains('is-answered')
                || /\banswers?\b|\bcomplete\b|\bcorrect\b|\bincorrect\b/.test(btn.className)) {
                answered += 1;
            }
        });
        const pct = total ? Math.round((answered / total) * 100) : 0;
        if (fill) {
            fill.style.width = pct + '%';
        }
        if (label) {
            label.textContent = pct + '%';
        }
    };

    /**
     * Restyle Moodle timer host. Hide entirely when the quiz is untimed.
     * Never show Moodle's Hide/Show toggle.
     */
    const polishTimer = function() {
        const host = document.getElementById('ll-arena-timer-host');
        if (!host) {
            return;
        }
        const wrapper = host.querySelector('#quiz-timer-wrapper')
            || document.getElementById('quiz-timer-wrapper');
        if (!wrapper) {
            host.classList.add('ll-cr-hidden');
            host.setAttribute('hidden', 'hidden');
            host.innerHTML = '';
            return;
        }
        if (!host.contains(wrapper)) {
            host.appendChild(wrapper);
        }
        host.classList.remove('ll-cr-hidden');
        host.removeAttribute('hidden');
        host.classList.add('ll-arena__timer-chip');

        // Strip Hide/Show controls — always show the clock when timed.
        host.querySelectorAll('#toggle-timer, .toggle-timer, button[id*="toggle-timer"]').forEach(function(el) {
            el.classList.add('ll-cr-hidden');
            el.setAttribute('hidden', 'hidden');
            el.style.display = 'none';
        });
        const timeLeft = host.querySelector('#quiz-time-left, #quiz-timer');
        if (timeLeft) {
            timeLeft.removeAttribute('hidden');
            timeLeft.style.display = '';
        }

        if (!host.querySelector('.ll-arena__timer-icon')) {
            const icon = document.createElement('span');
            icon.className = 'll-arena__timer-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '⏱';
            host.insertBefore(icon, host.firstChild);
        }
    };

    const refresh = function() {
        mountFooter();
        polishTimer();
        syncTopProgress();
        syncSectionBadge();
        updateQuestionStatus();
    };

    return {
        mountFooter: mountFooter,
        syncTopProgress: syncTopProgress,
        syncSectionBadge: syncSectionBadge,
        updateQuestionStatus: updateQuestionStatus,
        polishTimer: polishTimer,
        refresh: refresh
    };
});
