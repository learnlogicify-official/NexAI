/**
 * Review & Submit Assessment modal — replaces Moodle summary page confirm UX.
 *
 * @module     local_llassessment/submit_modal
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    let bound = false;
    let open = false;

    const findForm = function() {
        return document.getElementById('responseform')
            || document.querySelector('#ll-arena-workspace form.ll-arena-responseform')
            || document.querySelector('form.ll-arena-responseform');
    };

    /**
     * Sync Ace editors into their textareas before reading answers.
     *
     * @param {HTMLFormElement|null} form
     */
    const syncEditors = function(form) {
        if (!form) {
            return;
        }
        form.querySelectorAll('.ace_editor').forEach(function(aceEl) {
            try {
                if (window.ace) {
                    const ed = window.ace.edit(aceEl);
                    if (ed && ed.getValue) {
                        const val = ed.getValue();
                        const ta = aceEl.parentElement && aceEl.parentElement.querySelector('textarea');
                        const fallback = form.querySelector('textarea[name*="answer"]');
                        const target = ta || fallback;
                        if (target) {
                            target.value = val;
                        }
                    }
                }
            } catch (e) {}
        });
    };

    /**
     * Whether a nav button is already marked answered by Moodle.
     *
     * @param {Element} btn
     * @return {boolean}
     */
    const isNavAnswered = function(btn) {
        if (!btn) {
            return false;
        }
        if (btn.classList.contains('is-answered')) {
            return true;
        }
        const cls = (btn.className || '').toLowerCase();
        if (/notyetanswered|invalidanswer|requiresgrading/.test(cls)) {
            return false;
        }
        return /\banswers?\b|\bcomplete\b|\bcorrect\b|\bincorrect\b|\bpartial\b|\bsubmitted\b/.test(cls)
            || btn.classList.contains('answers')
            || btn.classList.contains('answersaved');
    };

    /**
     * True when the current page form has a filled response for this question root.
     * Covers answers typed/selected on the current question that Moodle has not
     * yet reflected on the navigator (until Next / autosave).
     *
     * @param {Element} root
     * @return {boolean}
     */
    const hasFilledResponse = function(root) {
        if (!root) {
            return false;
        }
        const inFlag = function(el) {
            return !!(el.closest && el.closest('.questionflag, .ll-arena-flag, .ll-arena-flag-source'));
        };

        const radios = root.querySelectorAll(
            '.answer input[type="radio"], .formulation input[type="radio"], .ablock input[type="radio"],' +
            ' .que input[type="radio"][name*="answer"]'
        );
        for (let i = 0; i < radios.length; i++) {
            if (radios[i].checked && !inFlag(radios[i])) {
                return true;
            }
        }

        const checks = root.querySelectorAll(
            '.answer input[type="checkbox"], .formulation input[type="checkbox"],' +
            ' .que input[type="checkbox"][name*="answer"]'
        );
        for (let j = 0; j < checks.length; j++) {
            if (checks[j].checked && !inFlag(checks[j])) {
                return true;
            }
        }

        const texts = root.querySelectorAll(
            'textarea[name*="answer"], input[type="text"][name*="answer"],' +
            ' input[type="number"][name*="answer"], .answer input[type="text"],' +
            ' .answer textarea, .coderunner-answer textarea, .ll-cr-ide textarea'
        );
        for (let k = 0; k < texts.length; k++) {
            if (inFlag(texts[k])) {
                continue;
            }
            if ((texts[k].value || '').trim() !== '') {
                return true;
            }
        }

        const selects = root.querySelectorAll('select[name*="answer"], .answer select, .formulation select');
        for (let s = 0; s < selects.length; s++) {
            const sel = selects[s];
            if (inFlag(sel)) {
                continue;
            }
            const val = (sel.value || '').trim();
            if (val !== '' && val !== '0') {
                return true;
            }
        }

        // Ace (after syncEditors).
        const aceAreas = root.querySelectorAll('.ace_editor');
        for (let a = 0; a < aceAreas.length; a++) {
            try {
                if (window.ace) {
                    const ed = window.ace.edit(aceAreas[a]);
                    if (ed && ed.getValue && String(ed.getValue()).trim() !== '') {
                        return true;
                    }
                }
            } catch (e) {}
        }

        return false;
    };

    /**
     * Question number from Moodle info / nav data attribute.
     *
     * @param {Element} que
     * @return {string}
     */
    const queNumber = function(que) {
        if (!que) {
            return '';
        }
        const no = que.querySelector('.info h3.no, .info .no, .ll-arena-qhead__title, .qno');
        if (no) {
            const m = (no.textContent || '').match(/(\d+)/);
            if (m) {
                return m[1];
            }
        }
        const idm = (que.id || '').match(/(\d+)/);
        return idm ? idm[1] : '';
    };

    const collectStats = function() {
        const buttons = Array.prototype.slice.call(document.querySelectorAll('.ll-nav__btn'));
        const total = buttons.length;
        let answered = 0;
        let flagged = 0;
        const answeredNums = {};

        buttons.forEach(function(btn) {
            if (isNavAnswered(btn)) {
                answered += 1;
                const n = btn.getAttribute('data-ll-nav-num')
                    || ((btn.textContent || '').match(/(\d+)/) || [])[1];
                if (n) {
                    answeredNums[String(n)] = true;
                }
            }
            if (btn.classList.contains('is-flagged') || btn.classList.contains('flagged')) {
                flagged += 1;
            }
        });

        // Current page: count in-progress answers not yet saved to the navigator.
        const form = findForm();
        if (form) {
            syncEditors(form);
            const ques = form.querySelectorAll('.que, .ll-arena-question-wrap');
            const roots = ques.length ? Array.prototype.slice.call(ques) : [form];
            roots.forEach(function(root) {
                if (!hasFilledResponse(root)) {
                    return;
                }
                const num = queNumber(root);
                if (num && answeredNums[num]) {
                    return;
                }
                // Prefer matching nav button; if current page has no number, bump once.
                if (num) {
                    const btn = document.querySelector('.ll-nav__btn[data-ll-nav-num="' + num + '"]');
                    if (btn && isNavAnswered(btn)) {
                        return;
                    }
                    answered += 1;
                    answeredNums[num] = true;
                    if (btn) {
                        btn.classList.add('is-answered');
                    }
                } else {
                    const current = document.querySelector(
                        '.ll-nav__btn.is-current, .ll-nav__btn.thispage'
                    );
                    if (current && isNavAnswered(current)) {
                        return;
                    }
                    answered += 1;
                    if (current) {
                        current.classList.add('is-answered');
                        const cn = current.getAttribute('data-ll-nav-num');
                        if (cn) {
                            answeredNums[cn] = true;
                        }
                    }
                }
            });
        }

        if (total > 0) {
            answered = Math.min(answered, total);
        }

        // Also count left-panel flag toggles if navigator missed some.
        if (!flagged) {
            flagged = document.querySelectorAll('.ll-arena-flag.is-flagged').length;
        }
        return {
            total: total,
            answered: answered,
            unanswered: Math.max(0, total - answered),
            flagged: flagged
        };
    };

    const ensureModal = function() {
        let modal = document.getElementById('ll-submit-modal');
        if (modal) {
            return modal;
        }
        modal = document.createElement('div');
        modal.id = 'll-submit-modal';
        modal.className = 'll-submit-modal';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'll-submit-modal-title');
        modal.innerHTML =
            '<div class="ll-submit-modal__backdrop" data-ll-submit="cancel"></div>' +
            '<div class="ll-submit-modal__card">' +
                '<h2 class="ll-submit-modal__title" id="ll-submit-modal-title">Review &amp; Submit Assessment</h2>' +
                '<div class="ll-submit-modal__stats">' +
                    '<div class="ll-submit-modal__stat">' +
                        '<div class="ll-submit-modal__stat-label">Answered</div>' +
                        '<div class="ll-submit-modal__stat-value ll-submit-modal__stat-value--ok" data-ll-stat="answered">0</div>' +
                    '</div>' +
                    '<div class="ll-submit-modal__stat">' +
                        '<div class="ll-submit-modal__stat-label">Unanswered</div>' +
                        '<div class="ll-submit-modal__stat-value ll-submit-modal__stat-value--bad" data-ll-stat="unanswered">0</div>' +
                    '</div>' +
                    '<div class="ll-submit-modal__stat">' +
                        '<div class="ll-submit-modal__stat-label">Flagged for Review</div>' +
                        '<div class="ll-submit-modal__stat-value ll-submit-modal__stat-value--flag" data-ll-stat="flagged">0</div>' +
                    '</div>' +
                '</div>' +
                '<p class="ll-submit-modal__hint">You can still go back and review your answers before final submission.</p>' +
                '<div class="ll-submit-modal__actions">' +
                    '<button type="button" class="ll-submit-modal__btn ll-submit-modal__btn--cancel" data-ll-submit="cancel">Cancel</button>' +
                    '<button type="button" class="ll-submit-modal__btn ll-submit-modal__btn--confirm" data-ll-submit="confirm">Confirm Submit</button>' +
                '</div>' +
            '</div>';
        const arena = document.getElementById('ll-arena') || document.body;
        arena.appendChild(modal);
        return modal;
    };

    const paintStats = function(modal) {
        const stats = collectStats();
        const a = modal.querySelector('[data-ll-stat="answered"]');
        const u = modal.querySelector('[data-ll-stat="unanswered"]');
        const f = modal.querySelector('[data-ll-stat="flagged"]');
        if (a) {
            a.textContent = String(stats.answered);
        }
        if (u) {
            u.textContent = String(stats.unanswered);
        }
        if (f) {
            f.textContent = String(stats.flagged);
        }
    };

    const openModal = function() {
        const modal = ensureModal();
        paintStats(modal);
        modal.hidden = false;
        open = true;
        document.body.classList.add('ll-submit-modal-open');
        const confirmBtn = modal.querySelector('[data-ll-submit="confirm"]');
        if (confirmBtn) {
            confirmBtn.focus();
        }
    };

    const closeModal = function() {
        const modal = document.getElementById('ll-submit-modal');
        if (modal) {
            modal.hidden = true;
        }
        open = false;
        document.body.classList.remove('ll-submit-modal-open');
    };

    const showBusy = function(message) {
        let el = document.getElementById('ll-arena-loader');
        if (!el) {
            return;
        }
        const text = el.querySelector('.ll-arena-loader__text');
        if (text) {
            text.textContent = message || 'Submitting assessment…';
        }
        el.hidden = false;
        document.body.classList.add('ll-arena-loading');
    };

    /**
     * Resolve attempt id from form / URL / navigator links.
     *
     * @param {HTMLFormElement|null} form
     * @return {number}
     */
    const resolveAttemptId = function(form) {
        if (form) {
            const inp = form.querySelector('input[name="attempt"]');
            if (inp && inp.value) {
                return Number(inp.value) || 0;
            }
        }
        try {
            const u = new URL(window.location.href);
            const a = Number(u.searchParams.get('attempt') || 0);
            if (a) {
                return a;
            }
        } catch (e) {
            // ignore
        }
        const link = document.querySelector(
            'a.endtestlink[href*="attempt="], a.qnbutton[href*="attempt="], #mod_quiz_navblock a[href*="attempt="]'
        );
        if (link) {
            try {
                return Number(new URL(link.href, window.location.origin).searchParams.get('attempt') || 0);
            } catch (e2) {
                // ignore
            }
        }
        return 0;
    };

    /**
     * @param {HTMLFormElement|null} form
     * @return {number}
     */
    const resolveCmid = function(form) {
        if (form) {
            const inp = form.querySelector('input[name="cmid"]');
            if (inp && inp.value) {
                return Number(inp.value) || 0;
            }
        }
        try {
            const u = new URL(window.location.href);
            const c = Number(u.searchParams.get('cmid') || 0);
            if (c) {
                return c;
            }
        } catch (e) {
            // ignore
        }
        return 0;
    };

    /**
     * Build a Moodle quiz URL that always carries attempt (+ cmid when known).
     *
     * @param {string} path summary.php | review.php | processattempt.php
     * @param {number} attemptid
     * @param {number} cmid
     * @return {string}
     */
    const buildQuizUrl = function(path, attemptid, cmid) {
        try {
            const u = new URL(path, window.location.origin);
            // Keep same /mod/quiz/ base as current page when path is relative.
            if (path.indexOf('/') === 0 || path.indexOf('http') === 0) {
                // absolute-ish
            } else {
                const base = window.location.pathname.replace(/[^/]+$/, '');
                u.pathname = base + path.replace(/^\.\//, '');
            }
            if (attemptid) {
                u.searchParams.set('attempt', String(attemptid));
            }
            if (cmid) {
                u.searchParams.set('cmid', String(cmid));
            }
            return u.toString();
        } catch (e) {
            let q = 'attempt=' + encodeURIComponent(attemptid || '');
            if (cmid) {
                q += '&cmid=' + encodeURIComponent(cmid);
            }
            return path + (path.indexOf('?') >= 0 ? '&' : '?') + q;
        }
    };

    /**
     * Ensure processattempt action URL + FormData both include attempt.
     *
     * @param {HTMLFormElement} form
     * @param {FormData} fd
     * @param {number} attemptid
     * @param {number} cmid
     * @return {string} action URL
     */
    const prepareFinishPost = function(form, fd, attemptid, cmid) {
        if (attemptid) {
            fd.set('attempt', String(attemptid));
        }
        if (cmid) {
            fd.set('cmid', String(cmid));
        }
        fd.set('finishattempt', '1');
        fd.set('timeup', '0');
        fd.delete('next');
        fd.delete('previous');

        let action = form.getAttribute('action') || window.location.href;
        try {
            const u = new URL(action, window.location.origin);
            if (attemptid && !u.searchParams.get('attempt')) {
                u.searchParams.set('attempt', String(attemptid));
            }
            if (cmid && !u.searchParams.get('cmid')) {
                u.searchParams.set('cmid', String(cmid));
            }
            // Prefer processattempt.php for finish posts.
            if (!/processattempt\.php/i.test(u.pathname)) {
                u.pathname = u.pathname.replace(/attempt\.php$/i, 'processattempt.php');
                if (!/processattempt\.php/i.test(u.pathname)) {
                    const base = window.location.pathname.replace(/[^/]+$/, '');
                    u.pathname = base + 'processattempt.php';
                }
            }
            action = u.toString();
        } catch (e) {
            // keep action
        }
        return action;
    };

    /**
     * Course shell URL with this quiz active (cmid) — preferred after submit.
     *
     * @param {number} cmid
     * @return {string}
     */
    const resolveCourseLandingUrl = function(cmid) {
        const arena = document.getElementById('ll-arena');
        if (arena) {
            const tagged = arena.getAttribute('data-ll-course-url') || '';
            if (tagged && /course\/view\.php/i.test(tagged)) {
                try {
                    const u = new URL(tagged, window.location.origin);
                    if (cmid && !u.searchParams.get('cmid')) {
                        u.searchParams.set('cmid', String(cmid));
                    }
                    return u.toString();
                } catch (e) {
                    return tagged;
                }
            }
        }
        const back = document.querySelector('#ll-arena .ll-arena__back, a.ll-arena__back');
        if (back) {
            const href = back.getAttribute('href') || '';
            if (href && href !== '#' && /course\/view\.php/i.test(href)) {
                try {
                    const u = new URL(href, window.location.origin);
                    if (cmid && !u.searchParams.get('cmid')) {
                        u.searchParams.set('cmid', String(cmid));
                    }
                    return u.toString();
                } catch (e2) {
                    return href;
                }
            }
        }
        try {
            const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
            const courseMatch = (document.body.className || '').match(/\bcourse-(\d+)\b/);
            const courseId = courseMatch ? courseMatch[1] : '';
            if (root && courseId) {
                return root + '/course/view.php?id=' + encodeURIComponent(courseId)
                    + (cmid ? ('&cmid=' + encodeURIComponent(cmid)) : '');
            }
        } catch (e3) {
            // ignore
        }
        return '';
    };

    /**
     * Pick a safe landing URL. Prefer course (quiz active) over review/summary.
     *
     * @param {string} candidate
     * @param {number} attemptid
     * @param {number} cmid
     * @return {string}
     */
    const safeLandingUrl = function(candidate, attemptid, cmid) {
        const courseUrl = resolveCourseLandingUrl(cmid);
        if (courseUrl) {
            return courseUrl;
        }
        const reviewFallback = buildQuizUrl('review.php', attemptid, cmid);
        const summaryFallback = buildQuizUrl('summary.php', attemptid, cmid);
        if (!candidate) {
            return attemptid ? reviewFallback : window.location.href;
        }
        try {
            const u = new URL(candidate, window.location.origin);
            const path = u.pathname || '';
            // Error / missing-param pages — never navigate there.
            if (/required parameter|error\/index|admin\/index/i.test(u.href + path)) {
                return attemptid ? reviewFallback : summaryFallback;
            }
            if (/\/course\/view\.php/i.test(path)) {
                if (cmid && !u.searchParams.get('cmid')) {
                    u.searchParams.set('cmid', String(cmid));
                }
                return u.toString();
            }
            if (/\/mod\/quiz\/(summary|review|view)\.php/i.test(path)
                    || /\/mod\/quiz\/processattempt\.php/i.test(path)) {
                if (attemptid && !u.searchParams.get('attempt')
                        && !/\/mod\/quiz\/view\.php/i.test(path)) {
                    u.searchParams.set('attempt', String(attemptid));
                }
                if (cmid && !u.searchParams.get('cmid') && !/\/mod\/quiz\/view\.php/i.test(path)) {
                    u.searchParams.set('cmid', String(cmid));
                }
                // After finish, prefer review over hanging on processattempt.
                if (/processattempt\.php/i.test(path) && attemptid) {
                    return reviewFallback;
                }
                return u.toString();
            }
            // Unknown page — if we have attempt, go to review.
            return attemptid ? reviewFallback : candidate;
        } catch (e) {
            return attemptid ? reviewFallback : candidate;
        }
    };

    /**
     * Finish the attempt: save current page answers + finishattempt=1.
     *
     * @param {string} [summaryUrl]
     */
    const confirmFinish = function(summaryUrl) {
        closeModal();
        const form = findForm();
        showBusy('Submitting assessment…');

        const attemptid = resolveAttemptId(form);
        const cmid = resolveCmid(form);

        if (!form) {
            window.location.href = safeLandingUrl(summaryUrl, attemptid, cmid);
            return;
        }

        if (!attemptid) {
            // Cannot finish without attempt — fall back to summary/review if possible.
            window.location.href = safeLandingUrl(summaryUrl, 0, cmid);
            return;
        }

        syncEditors(form);
        const fd = new FormData(form);
        const action = prepareFinishPost(form, fd, attemptid, cmid);

        // Notify proctoring to end (fetch does not fire a form submit event).
        try {
            document.dispatchEvent(new CustomEvent('ll-assessment-finish', {
                detail: {attemptid: attemptid, cmid: cmid}
            }));
        } catch (e) {
            // ignore
        }

        fetch(action, {
            method: (form.method || 'POST').toUpperCase(),
            body: fd,
            credentials: 'same-origin',
            redirect: 'follow',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        }).then(function(res) {
            const landed = safeLandingUrl(res && res.url ? res.url : '', attemptid, cmid);
            window.location.href = landed || safeLandingUrl(summaryUrl, attemptid, cmid);
        }).catch(function() {
            // Last resort: classic form POST with attempt on the action URL.
            try {
                form.setAttribute('action', action);
                let inp = form.querySelector('input[name="attempt"]');
                if (!inp) {
                    inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'attempt';
                    form.appendChild(inp);
                }
                inp.value = String(attemptid);
                let fin = form.querySelector('input[name="finishattempt"]');
                if (!fin) {
                    fin = document.createElement('input');
                    fin.type = 'hidden';
                    fin.name = 'finishattempt';
                    form.appendChild(fin);
                }
                fin.value = '1';
                HTMLFormElement.prototype.submit.call(form);
            } catch (e2) {
                window.location.href = safeLandingUrl(summaryUrl, attemptid, cmid);
            }
        });
    };

    const bind = function() {
        if (bound) {
            return;
        }
        bound = true;

        document.addEventListener('click', function(ev) {
            const t = ev.target;
            if (!t || !t.closest) {
                return;
            }

            const finish = t.closest('.ll-arena__finish, a.endtestlink');
            if (finish && document.getElementById('ll-arena')) {
                ev.preventDefault();
                ev.stopPropagation();
                const href = finish.getAttribute('href') || '';
                ensureModal().setAttribute('data-ll-summary-url', href);
                openModal();
                return;
            }

            const action = t.closest('[data-ll-submit]');
            if (!action || !document.getElementById('ll-submit-modal')) {
                return;
            }
            const kind = action.getAttribute('data-ll-submit');
            if (kind === 'cancel') {
                ev.preventDefault();
                closeModal();
                return;
            }
            if (kind === 'confirm') {
                ev.preventDefault();
                const modal = document.getElementById('ll-submit-modal');
                const url = modal && modal.getAttribute('data-ll-summary-url');
                confirmFinish(url);
            }
        }, true);

        document.addEventListener('keydown', function(ev) {
            if (open && ev.key === 'Escape') {
                closeModal();
            }
        });
    };

    const init = function() {
        if (!document.getElementById('ll-arena')) {
            return;
        }
        ensureModal();
        bind();
    };

    return {
        init: init,
        open: openModal,
        close: closeModal
    };
});
