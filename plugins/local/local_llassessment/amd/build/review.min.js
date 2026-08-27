/**
 * Quiz review page chrome — matches arena visual language without fullscreen shell.
 *
 * @module     local_llassessment/review
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_llassessment/navigator', 'local_llassessment/mcq',
    'local_llassessment/sample_tests', 'local_llassessment/result_view'],
function(Navigator, Mcq, SampleTests, ResultView) {

    const resolveQuizViewUrl = function(config) {
        if (config && config.quizViewUrl) {
            return config.quizViewUrl;
        }
        if (config && config.cmid) {
            const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
            if (root) {
                return root + '/mod/quiz/view.php?id=' + encodeURIComponent(config.cmid);
            }
        }
        try {
            const u = new URL(window.location.href);
            const cmid = u.searchParams.get('cmid');
            if (cmid) {
                const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
                if (root) {
                    return root + '/mod/quiz/view.php?id=' + encodeURIComponent(cmid);
                }
            }
        } catch (e) {
            // Ignore.
        }
        const crumbs = document.querySelectorAll(
            '.breadcrumb a[href*="/mod/quiz/view.php"], .breadcrumb-item a[href*="/mod/quiz/view.php"]'
        );
        if (crumbs.length) {
            return crumbs[crumbs.length - 1].getAttribute('href') || '#';
        }
        return '#';
    };

    const resolveBackUrl = function(config) {
        const preferCourse = !!(config && (
            config.preferCourseBack === true || config.preferCourseBack === 1
            || config.preferCourseBack === '1'
            || config.courseFormat === 'nexcoursepro'
        )) || document.body.classList.contains('format-nexcoursepro')
            || document.documentElement.classList.contains('format-nexcoursepro');
        if (preferCourse) {
            if (config && config.courseUrl) {
                return String(config.courseUrl);
            }
            try {
                const root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot.replace(/\/$/, '') : '';
                const courseMatch = (document.body.className || '').match(/\bcourse-(\d+)\b/);
                const courseId = courseMatch ? courseMatch[1] : '';
                const cmid = (config && config.cmid) || '';
                if (root && courseId) {
                    return root + '/course/view.php?id=' + encodeURIComponent(courseId)
                        + (cmid ? ('&cmid=' + encodeURIComponent(cmid)) : '');
                }
            } catch (e) {
                // Fall through.
            }
        }
        return resolveQuizViewUrl(config || {});
    };

    const getQuestionNumber = function(que) {
        if (!que) {
            return '';
        }
        const data = que.getAttribute('data-ll-qnum');
        if (data) {
            return String(data);
        }
        const no = que.querySelector('.info h3.no, .info .no, .info h3, .qno, .info .accesshide');
        if (no) {
            const m = (no.textContent || '').match(/(\d+)/);
            if (m) {
                return m[1];
            }
        }
        // Moodle review often uses "Question X" in .info.
        const info = que.querySelector('.info');
        if (info) {
            const m2 = (info.textContent || '').match(/question\s+(\d+)/i);
            if (m2) {
                return m2[1];
            }
        }
        return '';
    };

    /**
     * @return {boolean}
     */
    const isShowAllReview = function() {
        try {
            const u = new URL(window.location.href);
            if (u.searchParams.get('showall') === '1') {
                return true;
            }
        } catch (e) {
            // Ignore.
        }
        return document.querySelectorAll('#region-main .que, .que').length > 1;
    };

    /**
     * Redirect once to review.php?showall=1 so every question is in the DOM.
     *
     * @return {boolean} true if a redirect was started
     */
    const ensureShowAll = function() {
        try {
            const u = new URL(window.location.href);
            if (u.pathname.indexOf('/mod/quiz/review.php') === -1
                && u.pathname.indexOf('review.php') === -1) {
                return false;
            }
            if (u.searchParams.get('showall') === '1') {
                return false;
            }
            // Already have multiple questions — nothing to do.
            if (document.querySelectorAll('#region-main .que, .que').length > 1) {
                return false;
            }
            if (!u.searchParams.get('attempt')) {
                return false;
            }
            u.searchParams.set('showall', '1');
            u.searchParams.delete('page');
            window.location.replace(u.toString());
            return true;
        } catch (e) {
            return false;
        }
    };

    /**
     * @return {Array<{idx: number, title: string, numbers: string[]}>}
     */
    const collectSectionsFromNav = function() {
        const panels = Array.prototype.slice.call(document.querySelectorAll('.ll-nav__section'));
        const tabs = Array.prototype.slice.call(document.querySelectorAll('.ll-nav__tab'));
        if (!panels.length) {
            return [];
        }
        return panels.map(function(panel, idx) {
            const tab = tabs[idx];
            const titleEl = panel.querySelector('.ll-nav__section-title');
            const title = (tab && tab.textContent)
                || (titleEl && titleEl.textContent)
                || ('Section ' + (idx + 1));
            const numbers = [];
            panel.querySelectorAll('.ll-nav__btn').forEach(function(btn) {
                const n = btn.getAttribute('data-ll-nav-num')
                    || ((btn.textContent || '').match(/\d+/) || [])[0];
                if (n) {
                    numbers.push(String(n));
                }
            });
            return {
                idx: idx,
                title: String(title).replace(/\s+/g, ' ').trim(),
                numbers: numbers,
                panel: panel
            };
        });
    };

    /**
     * @param {Element} btn
     * @return {Element|null}
     */
    const findQueForNavBtn = function(btn) {
        if (!btn) {
            return null;
        }
        const href = btn.getAttribute('href') || '';
        let hash = '';
        try {
            const u = new URL(href, window.location.origin);
            hash = (u.hash || '').replace(/^#/, '');
        } catch (e) {
            const m = href.match(/#(question-[\w-]+)/);
            if (m) {
                hash = m[1];
            }
        }
        if (hash) {
            const byId = document.getElementById(hash);
            if (byId && byId.classList.contains('que')) {
                return byId;
            }
            const bySel = document.querySelector('.que#' + hash.replace(/(:)/g, '\\$1'));
            if (bySel) {
                return bySel;
            }
        }
        const n = btn.getAttribute('data-ll-nav-num')
            || ((btn.textContent || '').match(/\d+/) || [])[0];
        if (!n) {
            return null;
        }
        const all = document.querySelectorAll('#region-main .que, .que');
        for (let i = 0; i < all.length; i++) {
            if (getQuestionNumber(all[i]) === String(n)) {
                return all[i];
            }
        }
        // Fallback: Nth question in document order (1-based display often matches order).
        const idx = parseInt(n, 10) - 1;
        if (!isNaN(idx) && idx >= 0 && idx < all.length) {
            return all[idx];
        }
        return null;
    };

    const tagQuestionsBySection = function(sections) {
        if (!sections.length) {
            return;
        }
        const byNum = {};
        sections.forEach(function(sec) {
            sec.numbers.forEach(function(n) {
                byNum[n] = sec.idx;
            });
        });
        document.querySelectorAll('#region-main .que, .que').forEach(function(que) {
            const n = getQuestionNumber(que);
            if (n && Object.prototype.hasOwnProperty.call(byNum, n)) {
                que.setAttribute('data-ll-section-idx', String(byNum[n]));
                que.setAttribute('data-ll-qnum', n);
            }
        });
        // Also map via navigator buttons → question nodes (more reliable on showall).
        sections.forEach(function(sec) {
            sec.panel.querySelectorAll('.ll-nav__btn').forEach(function(btn) {
                const que = findQueForNavBtn(btn);
                if (que) {
                    que.setAttribute('data-ll-section-idx', String(sec.idx));
                    const n = btn.getAttribute('data-ll-nav-num')
                        || ((btn.textContent || '').match(/\d+/) || [])[0];
                    if (n) {
                        que.setAttribute('data-ll-qnum', String(n));
                    }
                }
            });
        });
    };

    /**
     * On showall review, make nav buttons scroll to in-page questions.
     */
    const wireShowAllNavLinks = function() {
        if (!isShowAllReview()) {
            return;
        }
        document.querySelectorAll('.ll-nav__btn, #mod_quiz_navblock a.qnbutton').forEach(function(btn) {
            if (btn.getAttribute('data-ll-showall-wired') === '1') {
                return;
            }
            btn.setAttribute('data-ll-showall-wired', '1');
            btn.addEventListener('click', function(ev) {
                const que = findQueForNavBtn(btn);
                if (!que) {
                    return;
                }
                ev.preventDefault();
                const sec = que.getAttribute('data-ll-section-idx');
                if (sec !== null && sec !== '') {
                    selectSection(parseInt(sec, 10) || 0, {fromNav: true, scrollTo: null});
                }
                // Ensure this question is visible after section filter.
                que.classList.remove('ll-review-que--hidden');
                que.hidden = false;
                window.setTimeout(function() {
                    try {
                        que.scrollIntoView({behavior: 'smooth', block: 'start'});
                    } catch (e2) {
                        que.scrollIntoView(true);
                    }
                    que.classList.add('ll-review-que--flash');
                    window.setTimeout(function() {
                        que.classList.remove('ll-review-que--flash');
                    }, 1200);
                }, 30);
            });
        });
    };

    /**
     * Activate a section in both main tabs and navigator tabs; filter questions.
     *
     * @param {number} idx
     * @param {Object} [opts]
     */
    const selectSection = function(idx, opts) {
        opts = opts || {};
        const sections = collectSectionsFromNav();
        if (!sections.length || idx < 0 || idx >= sections.length) {
            return;
        }

        try {
            window.sessionStorage.setItem('ll_review_nav_tab', String(idx));
        } catch (e) {
            // Ignore.
        }

        // Navigator tabs + panels.
        document.querySelectorAll('.ll-nav__tab').forEach(function(t, tidx) {
            const on = tidx === idx;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.ll-nav__section').forEach(function(panel, pidx) {
            const on = pidx === idx;
            panel.classList.toggle('is-active', on);
            panel.hidden = !on;
        });

        // Main content tabs.
        document.querySelectorAll('.ll-review-tabs__tab').forEach(function(t, tidx) {
            const on = tidx === idx;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        // Re-tag in case questions loaded late.
        tagQuestionsBySection(sections);

        // Filter questions in the middle column — show ALL questions in this section.
        const allQues = document.querySelectorAll('#region-main .que, .que');
        let visibleCount = 0;
        let firstVisible = null;
        allQues.forEach(function(que) {
            let secIdx = que.getAttribute('data-ll-section-idx');
            if (secIdx === null || secIdx === '') {
                // Untagged: if only one section, show; else hide until tagged.
                const on = sections.length === 1;
                que.classList.toggle('ll-review-que--hidden', !on);
                que.hidden = !on;
                if (on) {
                    visibleCount += 1;
                    if (!firstVisible) {
                        firstVisible = que;
                    }
                }
                return;
            }
            const on = String(secIdx) === String(idx);
            que.classList.toggle('ll-review-que--hidden', !on);
            que.hidden = !on;
            if (on) {
                visibleCount += 1;
                if (!firstVisible) {
                    firstVisible = que;
                }
            }
        });

        // Moodle section headings in the page body (if present).
        document.querySelectorAll(
            '#region-main .mod_quiz-section-heading, #region-main h3.mod_quiz-section-heading'
        ).forEach(function(h) {
            h.classList.add('ll-cr-hidden');
        });

        const title = sections[idx].title;
        const cat = document.querySelector('.ll-nav [data-ll-nav="cat"]');
        if (cat) {
            cat.textContent = title;
        }
        const badge = document.querySelector('[data-ll-section-badge]');
        if (badge) {
            badge.textContent = title;
            badge.removeAttribute('hidden');
        }
        const mainLabel = document.querySelector('[data-ll-review-section-label]');
        if (mainLabel) {
            mainLabel.textContent = title;
        }

        document.querySelectorAll('.ll-review-stat').forEach(function(card) {
            const on = String(card.getAttribute('data-ll-section-idx')) === String(idx);
            card.classList.toggle('is-active', on);
        });

        // If this section has questions on the page, scroll to the first one when requested.
        if (opts.navigateFirst && !opts.fromNav && firstVisible) {
            window.setTimeout(function() {
                try {
                    firstVisible.scrollIntoView({behavior: 'smooth', block: 'start'});
                } catch (e3) {
                    firstVisible.scrollIntoView(true);
                }
            }, 40);
        }

        // Not on showall yet and no questions for this section → force showall (not page=N).
        if (opts.navigateFirst && !opts.fromNav && !visibleCount && !isShowAllReview()) {
            try {
                const u = new URL(window.location.href);
                u.searchParams.set('showall', '1');
                u.searchParams.delete('page');
                window.sessionStorage.setItem('ll_review_nav_tab', String(idx));
                window.location.href = u.toString();
            } catch (e4) {
                // Ignore.
            }
        }
    };

    const wireNavTabSync = function() {
        document.querySelectorAll('.ll-nav__tab').forEach(function(tab, idx) {
            if (tab.getAttribute('data-ll-synced') === '1') {
                return;
            }
            tab.setAttribute('data-ll-synced', '1');
            tab.addEventListener('click', function() {
                selectSection(idx, {fromNav: true});
            });
        });
    };

    /**
     * @param {Element} que
     * @return {{got: number, max: number}}
     */
    const parseQuestionMarks = function(que) {
        const chunks = [];
        que.querySelectorAll('.info .grade, .grade, .outcome').forEach(function(el) {
            chunks.push(el.textContent || '');
        });
        const text = chunks.join(' ') || '';
        let m = text.match(/marks?\s+for\s+this\s+submission:\s*([\d.]+)\s*\/\s*([\d.]+)/i);
        if (m) {
            return {got: parseFloat(m[1]) || 0, max: parseFloat(m[2]) || 0};
        }
        m = text.match(/mark[s]?\s*([\d.]+)\s*out of\s*([\d.]+)/i);
        if (m) {
            return {got: parseFloat(m[1]) || 0, max: parseFloat(m[2]) || 0};
        }
        m = text.match(/marked out of\s*([\d.]+)/i);
        if (m) {
            const max = parseFloat(m[1]) || 0;
            // "Marked out of X" with not answered / incorrect often means 0 earned.
            const got = /\bcorrect\b/i.test(que.className) ? max : 0;
            if (que.classList.contains('correct')) {
                return {got: max, max: max};
            }
            if (que.classList.contains('partiallycorrect')) {
                return {got: 0, max: max}; // unknown partial — leave 0 unless better match
            }
            return {got: 0, max: max};
        }
        m = text.match(/([\d.]+)\s*\/\s*([\d.]+)/);
        if (m) {
            return {got: parseFloat(m[1]) || 0, max: parseFloat(m[2]) || 0};
        }
        return {got: 0, max: 0};
    };

    const findQueByNumber = function(num) {
        const n = String(num);
        const all = document.querySelectorAll('#region-main .que, .que');
        for (let i = 0; i < all.length; i++) {
            if (getQuestionNumber(all[i]) === n) {
                return all[i];
            }
        }
        return null;
    };

    const findNavBtnByNumber = function(num) {
        const n = String(num);
        return document.querySelector('.ll-nav__btn[data-ll-nav-num="' + n + '"]');
    };

    const isBtnAttempted = function(btn) {
        if (!btn) {
            return false;
        }
        const cls = (btn.className || '').toLowerCase();
        if (/notyetanswered|notanswered|invalidanswer/.test(cls)) {
            return false;
        }
        return btn.classList.contains('is-answered')
            || btn.classList.contains('is-selected')
            || /\banswers?\b|\bcomplete\b|\bcorrect\b|\bincorrect\b|\bpartial\b|\bsubmitted\b/.test(cls);
    };

    const isBtnCorrect = function(btn) {
        if (!btn) {
            return false;
        }
        const cls = (btn.className || '').toLowerCase();
        return /\bcorrect\b/.test(cls) && !/\bincorrect\b/.test(cls) && !/\bpartiallycorrect\b/.test(cls);
    };

    /**
     * @param {Array} sections
     * @return {Array}
     */
    const computeSectionStats = function(sections) {
        return sections.map(function(sec) {
            let attempted = 0;
            let correct = 0;
            let got = 0;
            let max = 0;
            const total = sec.numbers.length;

            sec.numbers.forEach(function(num) {
                const que = findQueByNumber(num);
                const btn = findNavBtnByNumber(num);
                let qAttempted = false;
                let qCorrect = false;

                if (que) {
                    const marks = parseQuestionMarks(que);
                    got += marks.got;
                    max += marks.max;
                    qAttempted = !que.classList.contains('notyetanswered')
                        && !/notanswered/.test((que.className || '').toLowerCase());
                    // "Not answered" text in info.
                    const state = que.querySelector('.state, .info .state');
                    if (state && /not\s*answered/i.test(state.textContent || '')) {
                        qAttempted = false;
                    }
                    if (que.classList.contains('correct')) {
                        qCorrect = true;
                    } else if (que.classList.contains('incorrect') || que.classList.contains('partiallycorrect')) {
                        qAttempted = true;
                    }
                    if (marks.max > 0 && marks.got > 0) {
                        qAttempted = true;
                    }
                } else if (btn) {
                    qAttempted = isBtnAttempted(btn);
                    qCorrect = isBtnCorrect(btn);
                    // Fallback mark weight when question HTML is not on this page.
                    if (qCorrect) {
                        got += 1;
                        max += 1;
                    } else if (qAttempted) {
                        max += 1;
                    } else {
                        max += 1;
                    }
                } else {
                    max += 1;
                }

                if (qAttempted) {
                    attempted += 1;
                }
                if (qCorrect) {
                    correct += 1;
                }
            });

            const scorePct = max > 0 ? Math.round((got / max) * 100) : 0;
            const attemptPct = total > 0 ? Math.round((attempted / total) * 100) : 0;
            return {
                idx: sec.idx,
                title: sec.title,
                total: total,
                attempted: attempted,
                correct: correct,
                got: Math.round(got * 100) / 100,
                max: Math.round(max * 100) / 100,
                scorePct: scorePct,
                attemptPct: attemptPct
            };
        });
    };

    const ringSvg = function(pct, tone) {
        const r = 20;
        const c = 2 * Math.PI * r;
        const offset = c - (Math.min(100, Math.max(0, pct)) / 100) * c;
        return '<svg class="ll-review-stat__ring-svg" viewBox="0 0 48 48" width="56" height="56" aria-hidden="true">' +
            '<circle class="ll-review-stat__ring-bg" cx="24" cy="24" r="' + r + '" fill="none" stroke-width="5"/>' +
            '<circle class="ll-review-stat__ring-fg ll-review-stat__ring-fg--' + tone + '" cx="24" cy="24" r="' + r +
                '" fill="none" stroke-width="5" stroke-linecap="round" ' +
                'stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + offset.toFixed(2) + '" ' +
                'transform="rotate(-90 24 24)"/>' +
            '<text class="ll-review-stat__ring-text" x="24" y="24" text-anchor="middle" dominant-baseline="central">' +
                pct + '%' +
            '</text>' +
            '</svg>';
    };

    const formatMarks = function(got, max) {
        const g = (Math.round(got * 100) / 100).toString();
        const m = (Math.round(max * 100) / 100).toString();
        return g + ' / ' + m;
    };

    const injectSectionStats = function() {
        const sections = collectSectionsFromNav();
        if (!sections.length) {
            return;
        }
        tagQuestionsBySection(sections);
        const stats = computeSectionStats(sections);
        if (!stats.length) {
            return;
        }

        let overallGot = 0;
        let overallMax = 0;
        let overallAttempted = 0;
        let overallTotal = 0;
        let overallCorrect = 0;
        stats.forEach(function(s) {
            overallGot += s.got;
            overallMax += s.max;
            overallAttempted += s.attempted;
            overallTotal += s.total;
            overallCorrect += s.correct;
        });
        const overallPct = overallMax > 0 ? Math.round((overallGot / overallMax) * 100) : 0;

        let host = document.querySelector('.ll-review-stats');
        if (!host) {
            host = document.createElement('section');
            host.className = 'll-review-stats';
            host.setAttribute('aria-label', 'Section statistics');

            const main = document.getElementById('region-main')
                || document.querySelector('[role="main"]');
            if (!main) {
                return;
            }
            const summary = main.querySelector('.ll-review-summary');
            const tabs = main.querySelector('.ll-review-tabs');
            const firstQue = main.querySelector('.que');
            if (summary && summary.parentNode) {
                summary.parentNode.insertBefore(host, summary.nextSibling);
            } else if (tabs && tabs.parentNode) {
                tabs.parentNode.insertBefore(host, tabs);
            } else if (firstQue && firstQue.parentNode) {
                firstQue.parentNode.insertBefore(host, firstQue);
            } else {
                main.appendChild(host);
            }
        }

        let cards = '';
        stats.forEach(function(s) {
            const tone = s.scorePct >= 70 ? 'good' : (s.scorePct >= 40 ? 'mid' : 'low');
            cards +=
                '<button type="button" class="ll-review-stat" data-ll-section-idx="' + s.idx + '">' +
                    '<div class="ll-review-stat__top">' +
                        '<div class="ll-review-stat__meta">' +
                            '<div class="ll-review-stat__name"></div>' +
                            '<div class="ll-review-stat__marks">' +
                                '<span class="ll-review-stat__marks-label">Marks</span>' +
                                '<span class="ll-review-stat__marks-value">' + formatMarks(s.got, s.max) + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div class="ll-review-stat__ring" title="Score">' + ringSvg(s.scorePct, tone) + '</div>' +
                    '</div>' +
                    '<div class="ll-review-stat__row">' +
                        '<span>Attempted</span>' +
                        '<strong>' + s.attempted + ' / ' + s.total + '</strong>' +
                    '</div>' +
                    '<div class="ll-review-stat__bar" aria-hidden="true">' +
                        '<div class="ll-review-stat__bar-fill" style="width:' + s.attemptPct + '%"></div>' +
                    '</div>' +
                    '<div class="ll-review-stat__foot">' +
                        '<span>' + s.correct + ' correct</span>' +
                        '<span>' + s.attemptPct + '% attempted</span>' +
                    '</div>' +
                '</button>';
        });

        host.innerHTML =
            '<div class="ll-review-stats__head">' +
                '<div>' +
                    '<div class="ll-review-stats__eyebrow">Performance</div>' +
                    '<h2 class="ll-review-stats__title">Section-wise statistics</h2>' +
                '</div>' +
                '<div class="ll-review-stats__overall">' +
                    '<div class="ll-review-stats__overall-ring">' + ringSvg(overallPct, overallPct >= 70 ? 'good' : (overallPct >= 40 ? 'mid' : 'low')) + '</div>' +
                    '<div class="ll-review-stats__overall-text">' +
                        '<div class="ll-review-stats__overall-marks">' + formatMarks(overallGot, overallMax) + '</div>' +
                        '<div class="ll-review-stats__overall-sub">' +
                            overallAttempted + ' / ' + overallTotal + ' attempted · ' + overallCorrect + ' correct' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="ll-review-stats__grid">' + cards + '</div>';

        // Set section names safely (textContent).
        host.querySelectorAll('.ll-review-stat').forEach(function(card) {
            const idx = parseInt(card.getAttribute('data-ll-section-idx'), 10);
            const nameEl = card.querySelector('.ll-review-stat__name');
            if (nameEl && stats[idx]) {
                nameEl.textContent = stats[idx].title;
            }
            card.addEventListener('click', function() {
                selectSection(idx, {navigateFirst: true});
            });
        });
    };

    const injectMainSectionTabs = function() {
        const sections = collectSectionsFromNav();
        if (sections.length < 1) {
            return;
        }
        // Need at least one named section for tabs to be meaningful.
        const named = sections.filter(function(s) {
            return s.title && !/^section\s+\d+$/i.test(s.title);
        });
        if (!named.length && sections.length < 2) {
            return;
        }

        tagQuestionsBySection(sections);

        let host = document.querySelector('.ll-review-tabs');
        if (!host) {
            host = document.createElement('div');
            host.className = 'll-review-tabs';
            host.innerHTML =
                '<div class="ll-review-tabs__head">' +
                    '<div class="ll-review-tabs__label">Sections</div>' +
                    '<div class="ll-review-tabs__current" data-ll-review-section-label></div>' +
                '</div>' +
                '<div class="ll-review-tabs__list" role="tablist" aria-label="Review sections"></div>';

            const main = document.getElementById('region-main')
                || document.querySelector('[role="main"]');
            if (!main) {
                return;
            }
            const summary = main.querySelector('.ll-review-summary, .quizreviewsummary, table.quizreviewsummary');
            const firstQue = main.querySelector('.que');
            const anchor = firstQue || summary;
            if (anchor && anchor.parentNode) {
                if (summary && summary.classList.contains('ll-review-summary')) {
                    summary.parentNode.insertBefore(host, summary.nextSibling);
                } else if (firstQue) {
                    firstQue.parentNode.insertBefore(host, firstQue);
                } else {
                    main.appendChild(host);
                }
            } else {
                main.appendChild(host);
            }
        }

        const list = host.querySelector('.ll-review-tabs__list');
        if (!list) {
            return;
        }
        list.innerHTML = '';

        let activeIdx = 0;
        try {
            const stored = window.sessionStorage.getItem('ll_review_nav_tab');
            if (stored !== null && stored !== '') {
                const n = parseInt(stored, 10);
                if (!isNaN(n) && n >= 0 && n < sections.length) {
                    activeIdx = n;
                }
            }
        } catch (e) {
            // Ignore.
        }
        // Prefer section of first visible / current question.
        const currentQue = document.querySelector('.que.thispage, .que[data-ll-section-idx]');
        if (currentQue && currentQue.getAttribute('data-ll-section-idx')) {
            activeIdx = parseInt(currentQue.getAttribute('data-ll-section-idx'), 10) || activeIdx;
        }

        sections.forEach(function(sec, idx) {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'll-review-tabs__tab ll-nav__tab' + (idx === activeIdx ? ' is-active' : '');
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', idx === activeIdx ? 'true' : 'false');
            tab.textContent = sec.title;
            tab.addEventListener('click', function() {
                selectSection(idx, {navigateFirst: true});
            });
            list.appendChild(tab);
        });

        wireNavTabSync();
        wireShowAllNavLinks();
        selectSection(activeIdx, {fromNav: true});
    };

    const enhanceNavigator = function() {
        const block = document.getElementById('mod_quiz_navblock');
        if (!block) {
            return;
        }
        block.classList.add('ll-review-nav');
        if (block.querySelector('.ll-nav')) {
            Navigator.refresh(block);
            wireNavTabSync();
            return;
        }

        // Reuse attempt navigator enhancer: move Moodle chrome into a slot.
        let slot = block.querySelector('#ll-arena-sidebar-slot');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'll-arena-sidebar-slot';
            slot.className = 'll-arena__sidebar-slot';
            while (block.firstChild) {
                slot.appendChild(block.firstChild);
            }
            block.appendChild(slot);
        }

        slot.querySelectorAll(
            '.block-header, .block-header-wrapper, h4.block-header, .card-title, .title'
        ).forEach(function(el) {
            el.classList.add('ll-cr-hidden');
        });

        Navigator.enhance(block, {
            categoryLabel: 'Review',
            sectionTabs: true,
            attemptedOnlySelected: true
        });

        block.querySelectorAll('.ll-nav__btn, .qnbutton').forEach(function(btn) {
            const attempted = btn.classList.contains('is-answered')
                || btn.classList.contains('is-selected');
            btn.classList.toggle('is-selected', attempted);
            btn.classList.remove('is-current');
            btn.classList.remove('is-incorrect');
            const cls = (btn.className || '').toLowerCase();
            if (attempted && /\bcorrect\b/.test(cls) && !/\bincorrect\b/.test(cls)) {
                btn.classList.add('is-correct');
            } else {
                btn.classList.remove('is-correct');
            }
        });

        wireNavTabSync();
    };

    const injectReviewBar = function(config) {
        if (document.querySelector('.ll-review-bar')) {
            return;
        }
        const main = document.getElementById('region-main')
            || document.querySelector('[role="main"]')
            || document.querySelector('#region-main-box');
        if (!main) {
            return;
        }

        const cfg = config || {};
        const resolveQuizTitle = function() {
            if (cfg.quizName && String(cfg.quizName).trim()) {
                return String(cfg.quizName).trim();
            }
            // Prefer activity name from a quiz breadcrumb / link — not the course h1.
            const quizLink = document.querySelector(
                '.breadcrumb a[href*="/mod/quiz/view.php"], .breadcrumb-item a[href*="/mod/quiz/view.php"],' +
                ' a[href*="/mod/quiz/view.php"][data-region], #page-navbar a[href*="/mod/quiz/view.php"]'
            );
            if (quizLink) {
                const t = (quizLink.textContent || '').replace(/\s+/g, ' ').trim();
                if (t && !/^quiz$/i.test(t) && !/^home$/i.test(t)) {
                    return t;
                }
            }
            // region-main activity heading (often h2), skip generic "Review".
            const candidates = document.querySelectorAll(
                '#region-main h2, #region-main .page-header-headings h1, #region-main h1'
            );
            for (let i = 0; i < candidates.length; i++) {
                const t = (candidates[i].textContent || '').replace(/\s+/g, ' ').trim();
                if (!t || /^review$/i.test(t) || /^attempt review$/i.test(t)) {
                    continue;
                }
                // Skip if it looks like the course fullname from body classes context only — keep text.
                return t;
            }
            return 'Assessment review';
        };
        const quizname = resolveQuizTitle();

        const bar = document.createElement('div');
        bar.className = 'll-review-bar';
        bar.innerHTML =
            '<div class="ll-review-bar__left">' +
                '<div class="ll-review-bar__titles">' +
                    '<div class="ll-review-bar__eyebrow">Attempt review</div>' +
                    '<h1 class="ll-review-bar__title"></h1>' +
                '</div>' +
                '<span class="ll-arena__badge ll-arena__section" data-ll-section-badge hidden></span>' +
            '</div>' +
            '<div class="ll-review-bar__right">' +
                '<span class="ll-review-bar__status">Review</span>' +
            '</div>';

        bar.querySelector('.ll-review-bar__title').textContent = quizname;

        // Insert at top of main content.
        const first = main.firstElementChild;
        if (first) {
            main.insertBefore(bar, first);
        } else {
            main.appendChild(bar);
        }

        // Hide Moodle/RemUI page heading that often shows the course name.
        document.querySelectorAll(
            '#page-header h1, .page-header-headings h1, #region-main > h2:first-of-type'
        ).forEach(function(h) {
            const t = (h.textContent || '').replace(/\s+/g, ' ').trim();
            if (t && t !== quizname) {
                h.classList.add('ll-cr-hidden');
                h.setAttribute('hidden', 'hidden');
            }
        });
    };

    /**
     * Turn Moodle quizreviewsummary table into status badge + metric chips.
     */
    const enhanceAttemptSummary = function() {
        document.querySelectorAll('table.quizreviewsummary, .generaltable.quizreviewsummary').forEach(function(table) {
            if (!table || table.dataset.llReviewSummary === '1') {
                return;
            }
            // Already converted sibling.
            if (table.parentElement && table.parentElement.querySelector('.ll-review-summary__grid')) {
                table.dataset.llReviewSummary = '1';
                table.classList.add('ll-cr-hidden');
                table.setAttribute('hidden', 'hidden');
                return;
            }
            table.dataset.llReviewSummary = '1';

            const items = [];
            let statusText = '';
            table.querySelectorAll('tr').forEach(function(tr) {
                const th = tr.querySelector('th');
                const td = tr.querySelector('td');
                if (!th || !td) {
                    return;
                }
                const label = (th.textContent || '').replace(/\s+/g, ' ').trim();
                const value = (td.textContent || '').replace(/\s+/g, ' ').trim();
                if (!label && !value) {
                    return;
                }
                if (/^status$/i.test(label)) {
                    statusText = value;
                }
                items.push({label: label, valueHtml: td.innerHTML, value: value});
            });
            if (!items.length) {
                return;
            }

            let kind = 'muted';
            const st = (statusText || '').toLowerCase();
            if (/finished|submitted|complete|overdue/.test(st)) {
                kind = 'ok';
            } else if (/progress|in progress|ongoing|never submitted/.test(st)) {
                kind = 'warn';
            } else if (/abandon|not|fail/.test(st)) {
                kind = 'bad';
            }

            const card = document.createElement('div');
            card.className = 'll-review-summary ll-review-summary--cards';
            card.setAttribute('data-ll-review-summary-cards', '1');

            const head = document.createElement('div');
            head.className = 'll-review-summary__head';
            head.innerHTML =
                '<div class="ll-review-summary__head-text">' +
                    '<div class="ll-review-summary__eyebrow">Attempt overview</div>' +
                    '<div class="ll-review-summary__title">Your attempt</div>' +
                '</div>' +
                '<span class="ll-review-summary__badge ll-review-summary__badge--' + kind + '"></span>';
            const badge = head.querySelector('.ll-review-summary__badge');
            badge.textContent = statusText || '—';
            card.appendChild(head);

            const grid = document.createElement('div');
            grid.className = 'll-review-summary__grid';
            items.forEach(function(item) {
                if (/^status$/i.test(item.label)) {
                    return;
                }
                const chip = document.createElement('div');
                chip.className = 'll-review-summary__chip';
                if (/grade|marks|score/i.test(item.label)) {
                    chip.classList.add('ll-review-summary__chip--grade');
                }
                chip.innerHTML =
                    '<span class="ll-review-summary__chip-label"></span>' +
                    '<strong class="ll-review-summary__chip-value"></strong>';
                chip.querySelector('.ll-review-summary__chip-label').textContent = item.label;
                chip.querySelector('.ll-review-summary__chip-value').innerHTML = item.valueHtml;
                grid.appendChild(chip);
            });
            card.appendChild(grid);

            const existingWrap = table.closest('.ll-review-summary');
            if (existingWrap && !existingWrap.classList.contains('ll-review-summary--cards')) {
                existingWrap.className = 'll-review-summary ll-review-summary--cards';
                existingWrap.setAttribute('data-ll-review-summary-cards', '1');
                while (existingWrap.firstChild) {
                    existingWrap.removeChild(existingWrap.firstChild);
                }
                existingWrap.appendChild(head);
                existingWrap.appendChild(grid);
                table.remove();
            } else {
                table.parentNode.insertBefore(card, table);
                table.remove();
            }
        });
    };

    const syncSectionBadge = function() {
        const badge = document.querySelector('[data-ll-section-badge]');
        if (!badge) {
            return;
        }
        let name = '';
        const activeTab = document.querySelector(
            '.ll-review-tabs__tab.is-active, .ll-nav__tab.is-active'
        );
        if (activeTab) {
            name = (activeTab.textContent || '').replace(/\s+/g, ' ').trim();
        }
        if (!name) {
            const first = document.querySelector('.ll-nav__tab, .ll-nav__section-title');
            if (first) {
                name = (first.textContent || '').replace(/\s+/g, ' ').trim();
            }
        }
        if (name) {
            badge.textContent = name;
            badge.removeAttribute('hidden');
        } else {
            badge.textContent = '';
            badge.setAttribute('hidden', 'hidden');
        }
    };

    /**
     * Undo any previous attempt-style left/right split on review coding questions.
     *
     * @param {Element} que
     */
    const unwrapReviewSplit = function(que) {
        const content = que.querySelector(':scope > .content');
        if (!content) {
            return;
        }
        const wrap = content.querySelector('.ll-review-cr-wrap, .ll-arena-question-wrap');
        const split = content.querySelector('.ll-arena-split, .ll-review-cr-split');
        if (!wrap && !split) {
            return;
        }

        const body = document.createElement('div');
        body.className = 'll-review-cr-body';

        const stem = split && split.querySelector('.ll-arena-split__stem');
        const response = split && split.querySelector('.ll-arena-split__response');
        const existingTests = split && split.querySelector('.ll-review-tests');

        if (stem) {
            const problem = document.createElement('div');
            problem.className = 'll-review-cr-problem';
            while (stem.firstChild) {
                problem.appendChild(stem.firstChild);
            }
            body.appendChild(problem);
        }

        if (existingTests) {
            body.appendChild(existingTests);
        }

        if (response) {
            // Park feedback/results for testcase parsing.
            response.querySelectorAll(
                '.outcome, .specificfeedback, .coderunner-feedback, .coderunner-test-results, table.coderunner-test-results'
            ).forEach(function(node) {
                body.appendChild(node);
            });

            const codeSec = document.createElement('div');
            codeSec.className = 'll-review-cr-code';
            const editorPane = response.querySelector('.ll-cr-ide__editor, [data-panel="code"]');
            if (editorPane) {
                while (editorPane.firstChild) {
                    codeSec.appendChild(editorPane.firstChild);
                }
            } else {
                const answer = response.querySelector('.answer, .coderunner-answer, .ace_editor, textarea[name*="answer"]');
                if (answer) {
                    const block = answer.closest('.answer, .coderunner-answer, .ablock') || answer;
                    codeSec.appendChild(block);
                }
            }
            if (codeSec.childNodes.length) {
                const head = document.createElement('h3');
                head.className = 'll-review-cr-code__title';
                head.textContent = 'Your solution';
                codeSec.insertBefore(head, codeSec.firstChild);
                body.appendChild(codeSec);
            }
        }

        // If unwrap found nothing useful, keep original children.
        if (!body.childNodes.length && wrap) {
            while (wrap.firstChild) {
                body.appendChild(wrap.firstChild);
            }
        }

        const doomed = wrap || split;
        if (doomed && doomed.parentNode) {
            doomed.parentNode.replaceChild(body, doomed);
        }
    };

    /**
     * Insert full-width testcase review under the problem statement (normal flow).
     *
     * @param {Element} que
     */
    const insertTestCaseReview = function(que) {
        if (que.querySelector('.ll-review-tests[data-ll-ready="1"]')) {
            return;
        }

        const content = que.querySelector(':scope > .content') || que;
        let body = content.querySelector('.ll-review-cr-body');
        if (!body) {
            body = document.createElement('div');
            body.className = 'll-review-cr-body';
            // Move existing content children into normal body wrapper once.
            while (content.firstChild) {
                body.appendChild(content.firstChild);
            }
            content.appendChild(body);
        }

        // Ensure problem block wraps statement content.
        if (!body.querySelector('.ll-review-cr-problem')) {
            const problem = document.createElement('div');
            problem.className = 'll-review-cr-problem';
            const movers = [];
            Array.prototype.slice.call(body.childNodes).forEach(function(node) {
                if (node.nodeType !== 1) {
                    movers.push(node);
                    return;
                }
                if (node.classList.contains('ll-review-tests')
                    || node.classList.contains('ll-review-cr-code')
                    || node.matches('.outcome, .specificfeedback, .coderunner-test-results, .answer, .coderunner-answer, .im-controls, .ablock')) {
                    return;
                }
                // Keep formulation / qtext / samples in problem.
                if (node.matches('.formulation, .qtext, .ll-samples, .ll-samples-wrap, .coderunner-examples, .for-example-para')
                    || node.querySelector && node.querySelector('.qtext, .formulation')) {
                    movers.push(node);
                }
            });
            if (!movers.length) {
                // Fallback: first non-answer/non-outcome blocks.
                Array.prototype.slice.call(body.children).forEach(function(node) {
                    if (!node.matches('.outcome, .specificfeedback, .coderunner-test-results, .answer, .coderunner-answer, .ll-review-tests, .ll-review-cr-code')) {
                        movers.push(node);
                    }
                });
            }
            movers.forEach(function(node) {
                problem.appendChild(node);
            });
            if (problem.childNodes.length) {
                body.insertBefore(problem, body.firstChild);
            }
        }

        let testsHost = body.querySelector('.ll-review-tests');
        if (!testsHost) {
            testsHost = document.createElement('section');
            testsHost.className = 'll-review-tests is-collapsed';
            testsHost.innerHTML =
                '<button type="button" class="ll-review-tests__toggle" aria-expanded="false">' +
                    '<span class="ll-review-tests__toggle-text">Show test case review</span>' +
                    '<span class="ll-review-tests__toggle-meta" hidden></span>' +
                    '<span class="ll-review-tests__toggle-chevron" aria-hidden="true">▾</span>' +
                '</button>' +
                '<div class="ll-review-tests__panel" hidden>' +
                    '<div class="ll-review-tests__head">' +
                        '<h3 class="ll-review-tests__title">Test case review</h3>' +
                        '<p class="ll-review-tests__hint">Results for each visible test case</p>' +
                    '</div>' +
                    '<div class="ll-review-tests__list"></div>' +
                '</div>';
            const problem = body.querySelector('.ll-review-cr-problem');
            const code = body.querySelector('.ll-review-cr-code');
            if (problem && problem.nextSibling) {
                body.insertBefore(testsHost, problem.nextSibling);
            } else if (code) {
                body.insertBefore(testsHost, code);
            } else {
                body.appendChild(testsHost);
            }
        }

        const list = testsHost.querySelector('.ll-review-tests__list');
        const panel = testsHost.querySelector('.ll-review-tests__panel');
        const toggle = testsHost.querySelector('.ll-review-tests__toggle');
        if (!list) {
            return;
        }

        const tables = Array.prototype.slice.call(que.querySelectorAll(
            'table.coderunner-test-results, .coderunner-test-results table'
        ));
        let filled = false;
        let caseCount = 0;
        tables.forEach(function(table) {
            if (filled) {
                return;
            }
            try {
                const data = ResultView.parseResults(table, table.closest('.coderunner-test-results') || table);
                if (data && data.tests && data.tests.length) {
                    list.innerHTML = '';
                    list.appendChild(ResultView.buildExpandedList(data));
                    caseCount = data.tests.length;
                    filled = true;
                }
            } catch (e) {
                // Try next.
            }
        });
        if (!filled && !list.childNodes.length) {
            const note = document.createElement('div');
            note.className = 'll-review-tests__empty';
            note.textContent = 'No graded test results are available for this question.';
            list.appendChild(note);
        }

        const meta = testsHost.querySelector('.ll-review-tests__toggle-meta');
        if (meta && caseCount > 0) {
            meta.textContent = caseCount + ' case' + (caseCount === 1 ? '' : 's');
            meta.removeAttribute('hidden');
        }

        // Hide raw Moodle result tables once we've rendered cards (keep in DOM for parsing retries).
        tables.forEach(function(table) {
            const wrap = table.closest('.coderunner-test-results') || table;
            wrap.classList.add('ll-cr-hidden');
        });

        if (toggle && !toggle.dataset.llBound) {
            toggle.dataset.llBound = '1';
            toggle.addEventListener('click', function() {
                const open = testsHost.classList.toggle('is-open');
                testsHost.classList.toggle('is-collapsed', !open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                const label = toggle.querySelector('.ll-review-tests__toggle-text');
                if (label) {
                    label.textContent = open ? 'Hide test case review' : 'Show test case review';
                }
                if (panel) {
                    if (open) {
                        panel.removeAttribute('hidden');
                    } else {
                        panel.setAttribute('hidden', 'hidden');
                    }
                }
            });
        }

        // Collapsed by default — details only after click.
        testsHost.classList.add('is-collapsed');
        testsHost.classList.remove('is-open');
        if (panel) {
            panel.setAttribute('hidden', 'hidden');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            const label = toggle.querySelector('.ll-review-tests__toggle-text');
            if (label) {
                label.textContent = 'Show test case review';
            }
        }

        testsHost.setAttribute('data-ll-ready', '1');
    };

    /**
     * Keep answer/code as a normal full-width block under testcases.
     *
     * @param {Element} que
     */
    const polishReviewCodeBlock = function(que) {
        const body = que.querySelector('.ll-review-cr-body');
        if (!body) {
            return;
        }
        if (body.querySelector('.ll-review-cr-code')) {
            return;
        }
        const answer = body.querySelector('.answer, .coderunner-answer, .ace_editor, textarea[name*="answer"]');
        if (!answer) {
            return;
        }
        const block = answer.closest('.answer, .coderunner-answer, .ablock') || answer.parentElement;
        if (!block || block.classList.contains('ll-review-cr-problem')) {
            return;
        }
        const codeSec = document.createElement('div');
        codeSec.className = 'll-review-cr-code';
        const head = document.createElement('h3');
        head.className = 'll-review-cr-code__title';
        head.textContent = 'Your solution';
        codeSec.appendChild(head);
        codeSec.appendChild(block);
        body.appendChild(codeSec);
    };

    const enhanceCodeRunner = function() {
        const root = document.getElementById('region-main') || document;
        root.querySelectorAll('.que.coderunner').forEach(function(que) {
            que.classList.add('ll-review-que--coderunner');
            unwrapReviewSplit(que);
            insertTestCaseReview(que);
            polishReviewCodeBlock(que);
        });
        try {
            SampleTests.enhance(root);
        } catch (e) {
            // Ignore.
        }
    };

    const hideEmptyFeedback = function() {
        document.querySelectorAll(
            '.que .specificfeedback, .que .generalfeedback, .que .rightanswer, .que .feedback, .que .outcome'
        ).forEach(function(el) {
            const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            // Keep containers that still have meaningful child feedback text.
            if (!text) {
                el.classList.add('ll-feedback-empty', 'll-cr-hidden');
                el.setAttribute('hidden', 'hidden');
                el.style.display = 'none';
            } else {
                el.classList.remove('ll-feedback-empty', 'll-cr-hidden');
                el.removeAttribute('hidden');
                if (el.style.display === 'none') {
                    el.style.display = '';
                }
            }
        });
    };

    const polishQuestions = function() {
        document.querySelectorAll('.que').forEach(function(que) {
            que.classList.add('ll-review-que');
            const info = que.querySelector(':scope > .info');
            if (info) {
                info.classList.add('ll-review-que__info');
            }
            const content = que.querySelector(':scope > .content');
            if (content) {
                content.classList.add('ll-review-que__content');
            }
            // Soften teacher-only edit links visually; keep them usable.
            que.querySelectorAll('.editquestion, a[href*="question/bank/editquestion"]').forEach(function(a) {
                a.classList.add('ll-review-edit');
            });
        });

        hideEmptyFeedback();

        // Attempt overview: chips instead of Moodle summary table.
        enhanceAttemptSummary();
    };

    const enhanceMcqReadonly = function() {
        Mcq.enhance(document.getElementById('region-main') || document, {readonly: true});
        // Review answers are typically disabled — keep rows non-interactive.
        document.querySelectorAll('.ll-mcq-option').forEach(function(row) {
            row.classList.add('ll-mcq-option--readonly');
            row.setAttribute('tabindex', '-1');
            const input = row.querySelector('input[type="radio"], input[type="checkbox"]');
            if (input) {
                row.classList.toggle('is-selected', !!input.checked);
                if (input.checked) {
                    row.classList.add('is-chosen');
                }
            }
            // Moodle may mark correctness on the row / answer.
            if (row.classList.contains('correct') || row.querySelector('.correct')) {
                row.classList.add('is-correct');
            }
            if (row.classList.contains('incorrect') || row.querySelector('.incorrect')) {
                row.classList.add('is-incorrect');
            }
        });
    };

    const hideFinishReviewControls = function() {
        document.querySelectorAll(
            '.submitbtns, .mod_quiz-next-nav, .mod-quiz-review-nav, .activity-navigation'
        ).forEach(function(el) {
            el.classList.add('ll-cr-hidden');
            el.setAttribute('hidden', 'hidden');
            el.style.setProperty('display', 'none', 'important');
        });
        document.querySelectorAll('#region-main a, #region-main button, .submitbtns a').forEach(function(el) {
            const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (/^finish review$/i.test(t) || /^finish the review$/i.test(t)) {
                const wrap = el.closest('.submitbtns, .mod_quiz-next-nav, p, .singlebutton') || el;
                wrap.classList.add('ll-cr-hidden');
                wrap.setAttribute('hidden', 'hidden');
                wrap.style.setProperty('display', 'none', 'important');
            }
        });
    };

    const init = function(config) {
        config = config || {};
        if (window.__llReviewInitDone) {
            return;
        }
        // Load every question once, then filter by section tab.
        if (ensureShowAll()) {
            return;
        }
        document.body.classList.add('ll-arena-review', 'll-arena-boot');
        document.documentElement.classList.add('ll-arena-review', 'll-arena-boot');
        const preferCourse = !!(config.preferCourseBack === true || config.preferCourseBack === 1
            || config.preferCourseBack === '1'
            || config.courseFormat === 'nexcoursepro')
            || document.body.classList.contains('format-nexcoursepro')
            || document.documentElement.classList.contains('format-nexcoursepro')
            || document.body.classList.contains('nxpro-review-fullscreen');
        if (preferCourse) {
            document.body.classList.add('nxpro-review-fullscreen', 'nxpro-fullscreen');
            document.documentElement.classList.add('nxpro-review-fullscreen');
        }
        if (config.brandColor) {
            document.documentElement.style.setProperty('--ll-brand', config.brandColor);
            document.body.style.setProperty('--ll-brand', config.brandColor);
        }

        const run = function() {
            injectReviewBar(config);
            enhanceNavigator();
            polishQuestions();
            enhanceCodeRunner();
            injectSectionStats();
            injectMainSectionTabs();
            wireShowAllNavLinks();
            enhanceMcqReadonly();
            hideEmptyFeedback();
            hideFinishReviewControls();
            syncSectionBadge();
            window.__llReviewInitDone = true;
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
        window.setTimeout(run, 200);
        window.setTimeout(function() {
            enhanceNavigator();
            polishQuestions();
            enhanceCodeRunner();
            injectSectionStats();
            injectMainSectionTabs();
            wireShowAllNavLinks();
            enhanceMcqReadonly();
            hideEmptyFeedback();
            enhanceAttemptSummary();
            hideFinishReviewControls();
            syncSectionBadge();
        }, 800);
        window.setTimeout(function() {
            enhanceCodeRunner();
            try {
                SampleTests.enhance(document.getElementById('region-main') || document);
            } catch (e) {
                // Ignore.
            }
        }, 1600);
    };

    return {
        init: init
    };
});
