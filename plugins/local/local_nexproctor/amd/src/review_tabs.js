/**
 * Review page tabs: Overview | Proctoring summary (AutoProctor-style).
 *
 * @module local_nexproctor/review_tabs
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    const call = function(method, args) {
        return Ajax.call([{methodname: method, args: args}])[0];
    };

    const esc = function(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };

    const findSummary = function() {
        return document.querySelector(
            '[data-ll-review-summary-cards], .ll-review-summary--cards, .ll-review-summary,' +
            ' .generaltable.quizreviewsummary, table.quizreviewsummary'
        );
    };

    const wrapOverview = function() {
        let summary = findSummary();
        if (!summary) {
            return null;
        }
        if (summary.classList.contains('quizreviewsummary') || summary.tagName === 'TABLE') {
            const card = summary.closest('.ll-review-summary');
            if (card) {
                summary = card;
            }
        }

        let shell = document.getElementById('np-review-shell');
        if (shell) {
            return shell;
        }

        shell = document.createElement('div');
        shell.id = 'np-review-shell';
        shell.className = 'np-review-shell';
        shell.innerHTML =
            '<div class="np-review-tabs" role="tablist">' +
                '<button type="button" class="np-review-tabs__tab is-active" role="tab" data-np-tab="overview" aria-selected="true"></button>' +
                '<button type="button" class="np-review-tabs__tab" role="tab" data-np-tab="proctoring" aria-selected="false"></button>' +
            '</div>' +
            '<div class="np-review-panels">' +
                '<div class="np-review-panel is-active" data-np-panel="overview" role="tabpanel"></div>' +
                '<div class="np-review-panel" data-np-panel="proctoring" role="tabpanel" hidden></div>' +
            '</div>';

        const parent = summary.parentNode;
        parent.insertBefore(shell, summary);
        shell.querySelector('[data-np-panel="overview"]').appendChild(summary);
        return shell;
    };

    /**
     * Hard-hide quiz review chrome when Proctoring Summary is active.
     * CSS alone loses to arena `display: grid !important` on .que.
     */
    const PROCTOR_HIDE_SEL = [
        '.ll-review-stats',
        '.ll-review-tabs',
        '.ll-review-section-stats',
        '.mod_quiz-section-heading',
        'h3.mod_quiz-section-heading',
        '#region-main .que',
        '.que.ll-review-que',
        '.ll-arena__sidebar',
        '#mod_quiz_navblock',
        '.ll-nav',
        '.ll-arena__nav',
        '.mod-quiz-review-nav',
        '.qn_buttons',
        '.submitbtns',
        '.mod_quiz-next-nav'
    ].join(',');

    const setProctoringOnly = function(on) {
        document.body.classList.toggle('np-proctoring-only', on);
        document.documentElement.classList.toggle('np-proctoring-only', on);
        document.querySelectorAll(PROCTOR_HIDE_SEL).forEach(function(el) {
            // Never hide our own shell / tabs / proctoring panel.
            if (el.closest && el.closest('#np-review-shell')) {
                return;
            }
            if (on) {
                if (!el.hasAttribute('data-np-prev-display')) {
                    el.setAttribute('data-np-prev-display', el.style.display || '');
                }
                el.style.setProperty('display', 'none', 'important');
                el.setAttribute('data-np-proctor-hidden', '1');
            } else if (el.getAttribute('data-np-proctor-hidden') === '1') {
                const prev = el.getAttribute('data-np-prev-display');
                el.removeAttribute('data-np-proctor-hidden');
                el.removeAttribute('data-np-prev-display');
                if (prev) {
                    el.style.display = prev;
                } else {
                    el.style.removeProperty('display');
                }
            }
        });
    };

    const activate = function(shell, name) {
        shell.querySelectorAll('.np-review-tabs__tab').forEach(function(tab) {
            const on = tab.getAttribute('data-np-tab') === name;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        shell.querySelectorAll('.np-review-panel').forEach(function(panel) {
            const on = panel.getAttribute('data-np-panel') === name;
            panel.classList.toggle('is-active', on);
            if (on) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', 'hidden');
            }
        });
        // Proctoring tab: only the proctoring report — no sections / questions / nav.
        setProctoringOnly(name === 'proctoring');
    };

    /** Monochrome line icons (stroke = currentColor). */
    const svgIcon = function(name) {
        const common = ' class="np-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" ' +
            'fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"';
        const paths = {
            noise: '<path d="M11 5 6 9H3v6h3l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 6a8.5 8.5 0 0 1 0 12"/>',
            tabs: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M9 4v5"/>',
            fullscreen: '<path d="M8 3H3v5"/><path d="M16 3h5v5"/><path d="M8 21H3v-5"/><path d="M16 21h5v-5"/>',
            noface: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M4 4l16 16"/>',
            multiface: '<circle cx="9" cy="8" r="3"/><circle cx="16" cy="9" r="2.5"/><path d="M3 19a6 6 0 0 1 12 0"/><path d="M14 19a5 5 0 0 1 7 0"/>',
            monitor: '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
            camera: '<path d="M4 8h3l2-2h6l2 2h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
            mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/>',
            eye: '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            attention: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>',
            folder: '<path d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/><path d="M3 7V5a2 2 0 0 1 2-2h4l2 2"/>',
            dot: '<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>'
        };
        const body = paths[name] || paths.dot;
        return '<svg' + common + '>' + body + '</svg>';
    };

    const iconFor = function(type) {
        const map = {
            noise_detected: 'noise',
            tab_hidden: 'tabs',
            fullscreen_exit: 'fullscreen',
            no_face: 'noface',
            multi_face: 'multiface',
            multi_monitor: 'monitor',
            random_snapshot: 'camera',
            looking_away: 'eye',
            camera_lost: 'camera',
            mic_lost: 'mic',
            screenshare_lost: 'monitor'
        };
        return svgIcon(map[type] || 'dot');
    };

    const toneFor = function(type) {
        // Kept for class hooks; visuals are monochrome.
        const map = {
            noise_detected: 'slate',
            tab_hidden: 'slate',
            fullscreen_exit: 'slate',
            no_face: 'slate',
            multi_face: 'slate',
            multi_monitor: 'slate',
            random_snapshot: 'slate',
            looking_away: 'slate'
        };
        return map[type] || 'slate';
    };

    const gaugeSvg = function(score) {
        const s = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
        const r = 54;
        const c = 2 * Math.PI * r;
        const offset = c - (s / 100) * c;
        const color = s >= 80 ? '#22c55e' : (s >= 50 ? '#84cc16' : '#ef4444');
        return (
            '<svg class="np-gauge" viewBox="0 0 140 140" aria-hidden="true">' +
                '<circle class="np-gauge__track" cx="70" cy="70" r="' + r + '" />' +
                '<circle class="np-gauge__value" cx="70" cy="70" r="' + r + '" ' +
                    'stroke="' + color + '" stroke-dasharray="' + c + '" stroke-dashoffset="' + offset + '" />' +
            '</svg>'
        );
    };

    const evidenceHtml = function(ev) {
        const bits = [];
        // Support multi-evidence later via primary fields; prefer typed media.
        if (ev.evidenceurl && (ev.evidencetype === 'audioclip' || (ev.evidencemime || '').indexOf('audio/') === 0)) {
            bits.push('<audio class="np-ev-audio" controls preload="metadata" src="' + esc(ev.evidenceurl) + '"></audio>');
        } else if (ev.evidenceurl && (
            ev.evidencetype === 'snapshot'
            || ev.evidencetype === 'screengrab'
            || (ev.evidencemime || '').indexOf('image/') === 0
        )) {
            bits.push(
                '<a href="' + esc(ev.evidenceurl) + '" target="_blank" rel="noopener">' +
                '<img class="np-ev-thumb" src="' + esc(ev.evidenceurl) + '" alt="Evidence" loading="lazy" />' +
                '</a>'
            );
        }
        if (!bits.length) {
            return '<div class="np-ev-missing">' +
                '<span class="np-ev-missing__icon" aria-hidden="true">' + svgIcon('folder') + '</span>' +
                '<span>This evidence has not been captured.</span>' +
                '</div>';
        }
        return bits.join('');
    };

    const renderProctoring = function(panel, data, strings, shell) {
        if (!data || !data.found) {
            panel.innerHTML = '<p class="np-review-empty">' + esc(strings.noSession || 'No proctoring data.') + '</p>';
            return;
        }

        const t = data.tracking || {};
        const c = data.counts || {};
        const trackBits = [
            t.camera ? '<span class="np-track is-on" title="Webcam">' + svgIcon('camera') + '</span>' : '',
            t.mic ? '<span class="np-track is-on" title="Microphone">' + svgIcon('mic') + '</span>' : '',
            t.screen ? '<span class="np-track is-on" title="Screen">' + svgIcon('monitor') + '</span>' : '',
            t.tab ? '<span class="np-track is-on" title="Tab switching">' + svgIcon('tabs') + '</span>' : '',
            t.fullscreen ? '<span class="np-track is-on" title="Fullscreen">' + svgIcon('fullscreen') + '</span>' : '',
            t.attention ? '<span class="np-track is-on" title="Attention">' + svgIcon('attention') + '</span>' : ''
        ].join('');

        let html = '<div class="np-ap">';
        html += '<div class="np-ap__header">';
        html += '<div class="np-ap__identity">' +
            '<div class="np-ap__name">' + esc((data.fullname || '').toUpperCase()) + '</div>' +
            '<div class="np-ap__email">' + esc(data.email || '') + '</div>' +
            '</div>';
        html += '<div class="np-ap__gauge-wrap">' +
            gaugeSvg(data.trustscore) +
            '<div class="np-ap__gauge-label"><strong>' + esc(data.trustscore) + '%</strong> ' +
            esc(strings.trustscore || 'TRUST SCORE') + '</div>' +
            '</div>';
        html += '<div class="np-ap__meta">' +
            '<div><span>Started</span><strong>' + esc(data.startedstr || '—') + '</strong></div>' +
            '<div><span>Submitted</span><strong>' + esc(data.endedstr || '—') + '</strong></div>' +
            '<div class="np-ap__tracking"><span>Tracking</span><div class="np-ap__tracks">' + trackBits + '</div></div>' +
            '<div><span>Device, Browser</span><strong>' + esc(data.device || 'Desktop') +
            (data.browser ? ', ' + esc(data.browser) : '') + '</strong></div>' +
            '</div>';
        html += '</div>';

        html += '<div class="np-ap__cards">';
        const cards = [
            {key: 'noise', label: 'Noise Detected', val: c.noise, icon: 'noise'},
            {key: 'tab', label: 'Tab Switched', val: c.tab, icon: 'tabs'},
            {key: 'fullscreen', label: 'Exited Fullscreen', val: c.fullscreen, icon: 'fullscreen'},
            {key: 'noface', label: 'No Face Detected', val: c.noface, icon: 'noface'},
            {key: 'multiface', label: 'Multiple Faces', val: c.multiface, icon: 'multiface'},
            {key: 'multimonitor', label: 'Multiple Monitors', val: (c.multimonitor > 0 ? 'Yes' : 'No'), icon: 'monitor'}
        ];
        cards.forEach(function(card) {
            html += '<div class="np-ap-card">' +
                '<div class="np-ap-card__icon">' + svgIcon(card.icon) + '</div>' +
                '<div class="np-ap-card__val">' + esc(card.val) + '</div>' +
                '<div class="np-ap-card__label">' + esc(card.label) + '</div>' +
                '</div>';
        });
        html += '</div>';

        html += '<div class="np-ap__toolbar">' +
            '<label class="np-ap__filter"><span>All Events</span>' +
            '<select data-np-filter>' +
            '<option value="all">All Events</option>' +
            '<option value="noise_detected">Noise</option>' +
            '<option value="tab_hidden">Tab switch</option>' +
            '<option value="fullscreen_exit">Fullscreen</option>' +
            '<option value="no_face">No face</option>' +
            '<option value="multi_face">Multiple faces</option>' +
            '<option value="random_snapshot">Random photo</option>' +
            '</select></label>' +
            '</div>';

        html += '<div class="np-ap__list" data-np-event-list>';
        if (!data.events || !data.events.length) {
            html += '<p class="np-review-empty">No events recorded.</p>';
        } else {
            data.events.forEach(function(ev) {
                html += '<div class="np-ap-row" data-type="' + esc(ev.eventtype) + '">' +
                    '<div class="np-ap-row__type">' +
                    '<span class="np-ap-row__icon np-tone-' + toneFor(ev.eventtype) + '">' + iconFor(ev.eventtype) + '</span>' +
                    '<span class="np-ap-row__label">' + esc(ev.label || ev.eventtype) + '</span>' +
                    '</div>' +
                    '<div class="np-ap-row__time">' + esc(ev.timestr) + '</div>' +
                    '<div class="np-ap-row__evidence">' + evidenceHtml(ev) + '</div>' +
                    '</div>';
            });
        }
        html += '</div></div>';

        panel.innerHTML = html;

        const filter = panel.querySelector('[data-np-filter]');
        if (filter) {
            filter.addEventListener('change', function() {
                const v = filter.value;
                panel.querySelectorAll('.np-ap-row').forEach(function(row) {
                    row.hidden = !(v === 'all' || row.getAttribute('data-type') === v);
                });
            });
        }
    };

    const init = function(cfg) {
        cfg = cfg || {};
        const strings = cfg.strings || {};
        if (!cfg.attemptid) {
            return;
        }

        const tryMount = function(attempt) {
            const shell = wrapOverview();
            if (!shell) {
                if (attempt < 25) {
                    window.setTimeout(function() {
                        tryMount(attempt + 1);
                    }, 200);
                }
                return;
            }

            shell.querySelector('[data-np-tab="overview"]').textContent = strings.overview || 'Test Summary';
            shell.querySelector('[data-np-tab="proctoring"]').textContent = strings.proctoring || 'Proctoring Summary';

            shell.querySelector('[data-np-tab="overview"]').addEventListener('click', function() {
                activate(shell, 'overview');
            });
            shell.querySelector('[data-np-tab="proctoring"]').addEventListener('click', function() {
                activate(shell, 'proctoring');
            });

            const panel = shell.querySelector('[data-np-panel="proctoring"]');
            panel.innerHTML = '<p class="np-review-empty">Loading…</p>';

            call('local_nexproctor_get_session_summary', {
                cmid: cfg.cmid,
                attemptid: cfg.attemptid
            }).then(function(data) {
                renderProctoring(panel, data, strings, shell);
            }).catch(function(err) {
                panel.innerHTML = '<p class="np-review-empty">Could not load proctoring summary.</p>';
                Notification.exception(err);
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                tryMount(0);
            });
        } else {
            tryMount(0);
        }
    };

    return {init: init};
});
