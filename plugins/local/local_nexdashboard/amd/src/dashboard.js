/**
 * Student dashboard — first UI + Phase 2 widgets + skeleton.
 *
 * @module     local_nexdashboard/dashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    let strings = {};
    let shareText = '';
    let analyticsState = {
        period: 'weekly',
        metric: 'xp',
        charts: null,
        totalXp: 0,
        totalSolved: 0,
        totalTimeMinutes: 0,
    };

    const metricLabel = (metric) => {
        if (metric === 'solved') {
            return strings.problemssolved || 'Problems Solved';
        }
        if (metric === 'time') {
            return strings.timespent || 'Time Spent';
        }
        return strings.xpearned || 'XP Earned';
    };

    const totalMetricLabel = (metric) => {
        if (metric === 'solved') {
            return strings.totalproblemssolved || 'Total Problems Solved';
        }
        if (metric === 'time') {
            return strings.totaltimespent || 'Total Time Spent';
        }
        return strings.totalxpearned || 'Total XP Earned';
    };

    const formatMetricValue = (metric, value) => {
        const n = Math.round(Number(value) || 0);
        if (metric === 'time') {
            // Same display as hero Learning time (shared minute proxy).
            const h = Math.floor(n / 60);
            const m = n % 60;
            return h + 'h ' + m + 'm';
        }
        return String(n);
    };

    const niceMax = (raw) => {
        const m = Math.max(0, Number(raw) || 0);
        if (m <= 0) {
            return 100;
        }
        const pad = m * 1.15;
        const mag = Math.pow(10, Math.floor(Math.log10(pad)));
        const norm = pad / mag;
        let nice;
        if (norm <= 1) {
            nice = 1;
        } else if (norm <= 2) {
            nice = 2;
        } else if (norm <= 5) {
            nice = 5;
        } else {
            nice = 10;
        }
        return nice * mag;
    };

    const getChartBundle = () => {
        const charts = analyticsState.charts || {};
        const period = charts[analyticsState.period] || charts.weekly || {};
        // Never fall back to XP for other metrics — that is what made Problems Solved /
        // Time Spent look like XP under the wrong labels.
        if (Object.prototype.hasOwnProperty.call(period, analyticsState.metric)) {
            return period[analyticsState.metric];
        }
        return null;
    };

    const renderAnalyticsMetrics = (root) => {
        const metric = analyticsState.metric || 'xp';
        const bundle = getChartBundle();
        const avg = bundle ? Math.round(Number(bundle.avg) || 0) : 0;
        const trend = bundle ? Number(bundle.trend) || 0 : 0;

        let total = 0;
        if (metric === 'solved') {
            total = analyticsState.totalSolved || 0;
        } else if (metric === 'time') {
            total = analyticsState.totalTimeMinutes || 0;
        } else {
            total = analyticsState.totalXp || 0;
        }

        root.find('[data-region="total-metric-label"]').text(totalMetricLabel(metric));
        root.find('[data-region="total-xp"]').text(formatMetricValue(metric, total));
        root.find('[data-region="avg-xp"]').text(formatMetricValue(metric, avg));
        root.find('[data-region="avg-label"]').text(
            (bundle && bundle.avgLabel) || strings.perweek || 'Per week'
        );
        root.find('[data-region="trend"]').text((trend > 0 ? '+' : '') + trend + '%');

        const hint = root.find('[data-region="xp-breakdown"]');
        if (metric === 'xp' && hint.text()) {
            hint.removeAttr('hidden');
        } else {
            hint.attr('hidden', true);
        }
    };

    const renderChart = (root) => {
        const host = root.find('[data-region="xp-chart"]');
        const bundle = getChartBundle();
        const pts = (bundle && bundle.series) || [];
        const label = metricLabel(analyticsState.metric);

        if (!pts.length) {
            host.html('<p class="nxd-empty">' + esc(strings.chartempty || 'No data for this period yet.') + '</p>');
            return;
        }

        const values = pts.map((p) => Number(p.value != null ? p.value : p.xp) || 0);
        const maxY = niceMax(Math.max(...values));
        const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => Math.round(maxY * t));

        const W = 640;
        const H = 200;
        const pad = {l: 36, r: 14, t: 14, b: 36};
        const iw = W - pad.l - pad.r;
        const ih = H - pad.t - pad.b;
        const n = pts.length;
        const xAt = (i) => pad.l + (n === 1 ? iw / 2 : (i / (n - 1)) * iw);
        const yAt = (v) => pad.t + ih - (Math.max(0, Math.min(maxY, v)) / maxY) * ih;

        const coords = pts.map((p, i) => ({
            x: xAt(i),
            y: yAt(values[i]),
            label: p.label,
            value: values[i],
        }));

        let path = '';
        coords.forEach((c, i) => {
            path += (i === 0 ? 'M' : 'L') + c.x.toFixed(1) + ' ' + c.y.toFixed(1) + ' ';
        });

        const grid = ticks.map((t) => {
            const y = yAt(t);
            return '<line class="nxd-linechart__grid" x1="' + pad.l + '" y1="' + y +
                '" x2="' + (W - pad.r) + '" y2="' + y + '"/>' +
                '<text class="nxd-linechart__ytick" x="' + (pad.l - 8) + '" y="' + (y + 4) +
                '" text-anchor="end">' + t + '</text>';
        }).join('');

        // Show fewer x labels when crowded.
        const step = n > 8 ? Math.ceil(n / 6) : 1;
        const xticks = coords.map((c, i) => {
            if (i % step !== 0 && i !== n - 1) {
                return '';
            }
            return '<text class="nxd-linechart__xtick" x="' + c.x + '" y="' + (H - 14) +
                '" text-anchor="middle">' + esc(c.label) + '</text>';
        }).join('');

        const dots = coords.map((c, i) =>
            '<circle class="nxd-linechart__dot" data-i="' + i + '" cx="' + c.x + '" cy="' + c.y +
            '" r="3.5" tabindex="0" role="img" aria-label="' + esc(c.label) + ', ' +
            esc(label) + ': ' + esc(formatMetricValue(analyticsState.metric, c.value)) + '"/>'
        ).join('');

        const hit = coords.map((c, i) =>
            '<circle class="nxd-linechart__hit" data-i="' + i + '" cx="' + c.x + '" cy="' + c.y +
            '" r="12"/>'
        ).join('');

        host.html(
            '<div class="nxd-linechart">' +
                '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" class="nxd-linechart__svg" aria-hidden="true">' +
                    grid +
                    '<path class="nxd-linechart__line" d="' + path.trim() + '" fill="none"/>' +
                    dots +
                    hit +
                    xticks +
                '</svg>' +
                '<div class="nxd-linechart__tooltip" data-region="chart-tooltip" hidden></div>' +
                '<div class="nxd-linechart__legend">' +
                    '<span class="nxd-linechart__legend-swatch"></span>' +
                    '<span>' + esc(label) + '</span>' +
                '</div>' +
            '</div>'
        );

        const tip = host.find('[data-region="chart-tooltip"]');
        const showTip = (i, evt) => {
            const c = coords[i];
            if (!c) {
                return;
            }
            tip.html(
                '<strong>' + esc(c.label) + '</strong>' +
                '<span>' + esc(label) + ': ' + esc(formatMetricValue(analyticsState.metric, c.value)) + '</span>'
            ).removeAttr('hidden');
            const box = host[0].getBoundingClientRect();
            const left = (evt && evt.clientX != null)
                ? (evt.clientX - box.left)
                : (c.x / W) * box.width;
            const top = (evt && evt.clientY != null)
                ? (evt.clientY - box.top - 12)
                : (c.y / H) * box.height - 12;
            tip.css({left: Math.max(8, Math.min(box.width - 140, left - 60)) + 'px', top: Math.max(4, top - 48) + 'px'});
            host.find('.nxd-linechart__dot').removeClass('is-active');
            host.find('.nxd-linechart__dot[data-i="' + i + '"]').addClass('is-active');
        };
        const hideTip = () => {
            tip.attr('hidden', true);
            host.find('.nxd-linechart__dot').removeClass('is-active');
        };

        host.find('.nxd-linechart__hit').on('mouseenter mousemove', function(e) {
            showTip(parseInt($(this).attr('data-i'), 10), e);
        });
        host.find('.nxd-linechart__dot').on('focus', function() {
            showTip(parseInt($(this).attr('data-i'), 10), null);
        });
        host.on('mouseleave', hideTip);
        host.find('.nxd-linechart__svg').on('blur', 'circle', hideTip);
    };

    const refreshAnalytics = (root) => {
        renderAnalyticsMetrics(root);
        renderChart(root);
    };

    const applyLabels = (root) => {
        root.find('[data-label]').each(function() {
            const key = $(this).attr('data-label');
            if (strings[key]) {
                $(this).text(strings[key]);
            }
        });
    };

    const setLoading = (root, loading) => {
        root.toggleClass('is-loading', !!loading);
        root.toggleClass('is-error', false);
        root.attr('aria-busy', loading ? 'true' : 'false');
        const skel = root.find('[data-region="skeleton"]');
        const content = root.find('[data-region="content"]');
        const err = root.find('[data-region="error"]');
        if (loading) {
            skel.attr('aria-hidden', 'false').removeAttr('hidden');
            content.attr('hidden', true);
            err.attr('hidden', true);
        } else {
            skel.attr('aria-hidden', 'true').attr('hidden', true);
            content.removeAttr('hidden');
            err.attr('hidden', true);
        }
    };

    const setError = (root, message) => {
        root.removeClass('is-loading').addClass('is-error');
        root.attr('aria-busy', 'false');
        root.find('[data-region="skeleton"]').attr('hidden', true).attr('aria-hidden', 'true');
        root.find('[data-region="content"]').attr('hidden', true);
        root.find('[data-region="error"]').removeAttr('hidden');
        root.find('[data-region="error-message"]').text(
            message || strings.loaderror || 'Could not load the dashboard.'
        );
    };

    const continueInitials = (title) => {
        const parts = String(title || '').trim().split(/\s+/).filter(Boolean);
        let out = '';
        parts.forEach((p) => {
            if (out.length >= 2) {
                return;
            }
            out += p.charAt(0).toUpperCase();
        });
        return out || 'C';
    };

    const continueTone = (i, source) => {
        if (source === 'practice') {
            return 'mint';
        }
        if (source === 'codelab') {
            return 'sky';
        }
        const tones = ['rose', 'sky', 'butter', 'mint'];
        return tones[i % tones.length];
    };

    const renderContinue = (root, cards) => {
        const grid = root.find('[data-region="continue-grid"]');
        if (!cards || !cards.length) {
            grid.html('<p class="nxd-empty">' + esc(strings.nocourses || 'Nothing to continue yet.') + '</p>');
            return;
        }
        const list = cards.slice(0, 5);
        grid.attr('data-count', String(list.length));
        const progressLabel = strings.progress || 'Progress';
        grid.html(list.map((c, i) => {
            const pct = Math.max(0, Math.min(100, Number(c.progress) || 0));
            const tone = continueTone(i, c.source || 'course');
            const initials = continueInitials(c.title);
            const badge = c.badge || c.source || 'Course';
            return '<article class="nxd-ccard nxd-ccard--' + esc(tone) + '">' +
                '<div class="nxd-ccard__main">' +
                    '<div class="nxd-ccard__content">' +
                        '<span class="nxd-ccard__badge">' + esc(badge) + '</span>' +
                        '<h3 class="nxd-ccard__title">' +
                            '<a href="' + esc(c.url) + '">' + esc(c.title) + '</a>' +
                        '</h3>' +
                        '<p class="nxd-ccard__summary">' + esc(c.subtitle || '') + '</p>' +
                        '<div class="nxd-ccard__progress">' +
                            '<div class="nxd-ccard__progress-top">' +
                                '<span>' + esc(progressLabel) + '</span>' +
                                '<strong>' + pct + '%</strong>' +
                            '</div>' +
                            '<div class="nxd-ccard__bar" role="progressbar" aria-valuenow="' + pct +
                                '" aria-valuemin="0" aria-valuemax="100">' +
                                '<span style="width:' + pct + '%"></span>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="nxd-ccard__art" aria-hidden="true">' +
                        '<span class="nxd-ccard__avatar">' + esc(initials) + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="nxd-ccard__foot">' +
                    '<div class="nxd-ccard__foot-text">' + esc(progressLabel) +
                        ': <strong>' + pct + '%</strong></div>' +
                    '<a class="nxd-ccard__btn" href="' + esc(c.url) + '">' +
                        esc(c.cta || 'Continue') + '</a>' +
                '</div>' +
                '</article>';
        }).join(''));
    };

    const renderStreakWeek = (root, days) => {
        const host = root.find('[data-region="streak-week"]');
        host.html((days || []).map((d) => {
            let cls = 'nxd-day';
            if (d.active) {
                cls += ' is-active';
            }
            if (d.isToday) {
                cls += ' is-today';
            }
            return '<div class="' + cls + '"><span>' + esc(d.label) + '</span></div>';
        }).join(''));
    };

    const renderGoal = (root, goal) => {
        if (!goal) {
            return;
        }
        const card = root.find('[data-region="goal-card"]');
        const label = goal.done ? (strings.goaldone || goal.label) : (goal.label || '');
        root.find('[data-region="goal-label"]').text(label);
        root.find('[data-region="goal-count"]').text(
            String(goal.current || 0) + '/' + String(goal.target || 5)
        );
        root.find('[data-region="goal-bar"]').css('width', Math.max(0, Math.min(100, goal.pct || 0)) + '%');
        card.toggleClass('is-done', !!goal.done);
        const choices = goal.choices || [3, 5, 7];
        root.find('[data-region="goal-choices"]').html(choices.map((n) => {
            const active = Number(n) === Number(goal.target) ? ' is-active' : '';
            return '<button type="button" class="nxd-goal__chip' + active + '" data-goal="' + n + '">' +
                n + '</button>';
        }).join(''));
    };

    const polarPoint = (index, count, radius, cx, cy) => {
        const angle = (-Math.PI / 2) + ((Math.PI * 2 * index) / Math.max(1, count));
        return {
            x: cx + (radius * Math.cos(angle)),
            y: cy + (radius * Math.sin(angle)),
        };
    };

    const skillTone = (pct) => {
        if (pct < 40) {
            return 'weak';
        }
        if (pct < 70) {
            return 'mid';
        }
        return 'strong';
    };

    const skillColors = ['#2563eb', '#7c3aed', '#0891b2', '#ea580c', '#16a34a', '#db2777'];

    const buildSkillRadarSvg = (skills) => {
        const n = Math.max(3, skills.length);
        const size = 240;
        const cx = size / 2;
        const cy = size / 2;
        const maxR = 78;
        const levels = [0.25, 0.5, 0.75, 1];
        let grids = '';
        levels.forEach((level) => {
            grids += '<circle class="nxd-radar__ring" cx="' + cx + '" cy="' + cy +
                '" r="' + (maxR * level).toFixed(1) + '"></circle>';
        });
        let axes = '';
        let valuePts = [];
        let markers = '';
        skills.forEach((s, i) => {
            const tip = polarPoint(i, n, maxR, cx, cy);
            const pct = Math.max(0, Math.min(100, Number(s.accuracy) || 0));
            const val = polarPoint(i, n, maxR * (pct / 100), cx, cy);
            valuePts.push(val.x.toFixed(1) + ',' + val.y.toFixed(1));
            axes += '<line class="nxd-radar__axis" x1="' + cx + '" y1="' + cy +
                '" x2="' + tip.x.toFixed(1) + '" y2="' + tip.y.toFixed(1) + '"></line>';
            markers += '<circle class="nxd-radar__node" cx="' + val.x.toFixed(1) + '" cy="' + val.y.toFixed(1) +
                '" r="4.5" style="--nxd-radar-color:' + skillColors[i % skillColors.length] + '"></circle>';
        });
        return '<svg class="nxd-radar__svg" viewBox="0 0 ' + size + ' ' + size +
            '" role="img" aria-label="' + esc(strings.skillmap || 'Skill focus') + '">' +
            '<defs>' +
                '<linearGradient id="nxd-radar-fill" x1="0%" y1="0%" x2="100%" y2="100%">' +
                    '<stop offset="0%" stop-color="#2563eb" stop-opacity="0.28"></stop>' +
                    '<stop offset="100%" stop-color="#7c3aed" stop-opacity="0.12"></stop>' +
                '</linearGradient>' +
            '</defs>' +
            grids + axes +
            '<polygon class="nxd-radar__area" points="' + valuePts.join(' ') + '"></polygon>' +
            markers +
            '<circle class="nxd-radar__hub" cx="' + cx + '" cy="' + cy + '" r="3"></circle>' +
            '</svg>';
    };

    const renderSkills = (root, skills) => {
        const host = root.find('[data-region="skills-list"]');
        if (!skills || !skills.length) {
            host.html('<p class="nxd-empty">' + esc(strings.skillmap_empty || '') + '</p>');
            return;
        }
        const list = skills.slice(0, 6);
        const legend = list.map((s, i) => {
            const pct = Math.max(0, Math.min(100, Number(s.accuracy) || 0));
            const tone = skillTone(pct);
            const color = skillColors[i % skillColors.length];
            return '<a class="nxd-radar__skill nxd-radar__skill--' + tone + '" href="' + esc(s.url) + '">' +
                '<span class="nxd-radar__swatch" style="background:' + color + '"></span>' +
                '<span class="nxd-radar__skill-name">' + esc(s.name) + '</span>' +
                '<span class="nxd-radar__skill-bar" aria-hidden="true"><i style="width:' + pct + '%"></i></span>' +
                '<span class="nxd-radar__skill-pct">' + pct + '%</span>' +
                '<span class="nxd-radar__skill-meta">' + esc(s.accepted) + '/' + esc(s.attempts) + '</span>' +
                '</a>';
        }).join('');

        host.html('<div class="nxd-radar nxd-radar--stack">' +
            '<div class="nxd-radar__chart">' + buildSkillRadarSvg(list) + '</div>' +
            '<div class="nxd-radar__legend">' + legend + '</div>' +
            '</div>');
    };

    const trackSegmentsHtml = (done, total) => {
        const maxVisual = 8;
        const t = Math.max(1, Number(total) || 1);
        const d = Math.max(0, Math.min(t, Number(done) || 0));
        const visual = Math.min(maxVisual, t);
        const filled = Math.round((d / t) * visual);
        let html = '';
        for (let i = 0; i < visual; i++) {
            html += '<i class="' + (i < filled ? 'is-done' : '') + '"></i>';
        }
        return html;
    };

    const renderTracks = (root, tracks) => {
        const card = root.find('[data-region="tracks-card"]');
        const host = root.find('[data-region="tracks-list"]');
        if (!tracks || !tracks.length) {
            if (!root.data('hascodelab')) {
                card.attr('hidden', true);
            } else {
                host.html('<p class="nxd-empty">' + esc(strings.tracks_empty || '') + '</p>');
            }
            return;
        }
        card.removeAttr('hidden');
        const tones = ['wrangling', 'eda', 'ml', 'nlp'];
        host.html('<div class="nxd-mtracks">' + tracks.map((t, i) => {
            const pct = Math.max(0, Math.min(100, Number(t.pct) || 0));
            const tone = tones.indexOf(t.key) >= 0 ? t.key : ('t' + (i % 4));
            const r = 18;
            const c = 2 * Math.PI * r;
            const offset = c * (1 - (pct / 100));
            return '<a class="nxd-mtrack nxd-mtrack--' + esc(tone) + '" href="' + esc(t.url) + '">' +
                '<div class="nxd-mtrack__ring" aria-hidden="true">' +
                    '<svg viewBox="0 0 44 44">' +
                    '<circle class="nxd-mtrack__ring-track" cx="22" cy="22" r="' + r + '"></circle>' +
                    '<circle class="nxd-mtrack__ring-fill" cx="22" cy="22" r="' + r +
                    '" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + offset.toFixed(1) +
                    '"></circle></svg>' +
                    '<span>' + pct + '%</span>' +
                '</div>' +
                '<div class="nxd-mtrack__body">' +
                    '<div class="nxd-mtrack__top">' +
                        '<strong>' + esc(t.label) + '</strong>' +
                        '<span>' + esc(t.done) + '/' + esc(t.total) + '</span>' +
                    '</div>' +
                    '<div class="nxd-mtrack__segments" aria-hidden="true">' +
                        trackSegmentsHtml(t.done, t.total) +
                    '</div>' +
                '</div></a>';
        }).join('') + '</div>');
    };

    const renderStuck = (root, stuck) => {
        const card = root.find('[data-region="stuck-card"]');
        const host = root.find('[data-region="stuck-list"]');
        if (!stuck || !stuck.length) {
            card.attr('hidden', true);
            host.empty();
            return;
        }
        card.removeAttr('hidden');
        host.html(stuck.map((s) => {
            return '<div class="nxd-stuck-item">' +
                '<strong>' + esc(s.title) + '</strong>' +
                '<span>' + esc(s.detail) + '</span>' +
                '<div class="nxd-stuck-item__actions">' +
                    '<a class="nxd-btn nxd-btn--primary nxd-btn--sm" href="' + esc(s.url) + '">' +
                        esc(s.cta || strings.retry || 'Retry') + '</a>' +
                    '<a class="nxd-link" href="' + esc(s.helpUrl || '#') + '">' +
                        esc(s.helpCta || strings.askforhelp || 'Ask for help') + '</a>' +
                '</div></div>';
        }).join(''));
    };

    const renderOnline = (root, online) => {
        const card = root.find('[data-region="online-card"]');
        if (!online || !online.enabled) {
            card.attr('hidden', true);
            return;
        }
        card.removeAttr('hidden');
        root.find('[data-region="online-count"]').text(String(online.count || 0));
        root.find('[data-region="online-period"]').text(
            online.period || strings.onlineperioddefault || ''
        );
        if (online.url) {
            root.find('[data-region="online-link"]').attr('href', online.url).removeAttr('hidden');
        } else {
            root.find('[data-region="online-link"]').attr('hidden', true);
        }
        const list = root.find('[data-region="online-list"]');
        const users = online.users || [];
        if (!users.length) {
            list.html('<p class="nxd-empty">' + esc(strings.onlineempty || 'No one online right now.') + '</p>');
            return;
        }
        list.html(users.map((u) => {
            const pic = u.haspicture && u.picture
                ? '<img class="nxd-online__pic" src="' + esc(u.picture) + '" alt="">'
                : '<span class="nxd-online__pic nxd-online__pic--empty" aria-hidden="true"></span>';
            const me = u.isMe ? ' is-me' : '';
            return '<a class="nxd-online__row' + me + '" href="' + esc(u.url) + '">' +
                pic +
                '<span class="nxd-online__body">' +
                    '<strong>' + esc(u.name) + '</strong>' +
                    '<em>' + esc(u.timeago) + '</em>' +
                '</span>' +
                '</a>';
        }).join(''));
    };

    const renderPeers = (root, peers, links) => {
        const card = root.find('[data-region="peers-card"]');
        if (!peers || !peers.enabled) {
            card.attr('hidden', true);
            return;
        }
        card.removeAttr('hidden');
        const title = peers.institution
            ? (strings.peers_college || '{$a} leaderboard').replace('{$a}', peers.institution)
            : (strings.peers_global || 'Overall leaderboard');
        root.find('[data-region="peers-title"]').text(title);
        const rankText = peers.rank
            ? (strings.yourrank || 'Your rank') + ': #' + peers.rank +
                (peers.total ? ' / ' + peers.total : '')
            : '';
        root.find('[data-region="peers-rank"]').text(rankText);
        root.find('[data-region="peers-link"]').attr(
            'href',
            peers.url || (links && links.overallLeaderboard) || '#'
        );
        root.find('[data-region="peers-list"]').html((peers.peers || []).map((p) => {
            return '<div class="nxd-peer' + (p.isMe ? ' is-me' : '') + '">' +
                '<span class="nxd-peer__rank">#' + esc(p.rank) + '</span>' +
                '<span class="nxd-peer__name">' + esc(p.name) + '</span>' +
                '<span class="nxd-peer__xp">' + esc(p.xp) + ' XP</span></div>';
        }).join(''));
    };

    const renderDeadlines = (root, deadlines, links) => {
        const host = root.find('[data-region="deadlines-list"]');
        root.find('[data-region="calendar-link"]').attr('href', (links && links.calendar) || '#');
        if (!deadlines || !deadlines.length) {
            host.html('<p class="nxd-empty">' + esc(strings.deadlines_empty || '') + '</p>');
            return;
        }
        host.html(deadlines.map((d) => {
            return '<a class="nxd-deadline" href="' + esc(d.url) + '">' +
                '<strong>' + esc(d.title) + '</strong>' +
                '<span>' + esc(d.when) + '</span></a>';
        }).join(''));
    };

    const activitySourceLabel = (source) => {
        if (source === 'codelab') {
            return strings.codelab || 'CodeLab';
        }
        if (source === 'course') {
            return strings.courses || 'Course';
        }
        return strings.practicelabel || strings.practice || 'Practice';
    };

    const renderActivity = (root, items) => {
        const host = root.find('[data-region="activity-list"]');
        if (!items || !items.length) {
            host.html('<p class="nxd-empty">' + esc(strings.recentactivity_empty || '') + '</p>');
            return;
        }
        host.html(items.map((a) => {
            const ok = !!a.ok;
            const source = a.source || 'practice';
            const statusLabel = ok
                ? (strings.activityok || 'Accepted')
                : (strings.activityfail || 'Incomplete');
            const metaParts = [activitySourceLabel(source), a.detail, a.when]
                .filter((part) => part && String(part).trim());
            const meta = metaParts.map((part) => esc(part)).join(
                '<span class="nxd-activity__sep" aria-hidden="true">·</span>'
            );
            return '<a class="nxd-activity__row' + (ok ? ' is-ok' : ' is-fail') +
                '" href="' + esc(a.url) + '">' +
                '<span class="nxd-activity__mark" aria-label="' + esc(statusLabel) + '">' +
                    (ok ? '✓' : '×') +
                '</span>' +
                '<span class="nxd-activity__body">' +
                    '<strong class="nxd-activity__title">' + esc(a.title) + '</strong>' +
                    (meta ? '<span class="nxd-activity__meta">' + meta + '</span>' : '') +
                '</span>' +
                '</a>';
        }).join(''));
    };

    const renderMonth = (root, month) => {
        if (!month) {
            return;
        }
        shareText = month.shareText || '';
        root.find('[data-region="month-label"]').text(month.label || '');
        root.find('[data-region="month-course-coding"]').text(String(month.courseCodingSolved || 0));
        root.find('[data-region="month-course-mcq"]').text(String(month.courseMcqCorrect || 0));
        root.find('[data-region="month-practice"]').text(String(month.practiceSolved || 0));
        root.find('[data-region="month-battles"]').text(String(month.battlesWon || 0));
        root.find('[data-region="month-interviews"]').text(String(month.interviewsCompleted || 0));
        root.find('[data-region="month-xp"]').text(String(month.xp || 0));
    };

    const render = (root, data) => {
        root.data('hascodelab', !!data.hasCodeLab);
        root.find('[data-region="greeting"]').text(data.greeting || '');
        root.find('[data-region="welcome"]').text(data.welcomeback || '');
        root.find('[data-region="tagline"]').text(data.tagline || '');
        if (data.learningTimePending || !data.learningTime) {
            setLearningTimeLoading(root, true);
        } else {
            setLearningTimeLoading(root, false);
            root.find('[data-region="learning-time"]').text(data.learningTime || '0h 0m');
        }
        root.find('[data-region="course-count"]').text(String(data.courseCount || 0));

        const next = data.nextAction || {};
        root.find('[data-region="hero-focus-title"]').text(next.title || '');
        root.find('[data-region="hero-focus-detail"]').text(next.detail || '');
        root.find('[data-region="hero-focus-cta"]').attr('href', next.url || '#').text(next.cta || 'Start');
        root.find('[data-region="view-all"]').attr('href', (data.links && data.links.mycourses) || '/my/courses.php');

        renderContinue(root, data.continueCards || []);
        renderGoal(root, data.goal || null);
        renderSkills(root, data.skills || []);
        renderTracks(root, data.tracks || []);
        renderStuck(root, data.stuck || []);
        renderPeers(root, data.peers || {}, data.links || {});
        renderOnline(root, data.onlineUsers || null);
        renderDeadlines(root, data.deadlines || [], data.links || {});
        renderActivity(root, data.recentActivity || []);
        renderMonth(root, data.monthSummary || null);

        const an = data.analytics || {};
        analyticsState.totalXp = an.totalXp || 0;
        analyticsState.totalSolved = an.totalSolved || 0;
        analyticsState.totalTimeMinutes = an.totalTimeMinutes || 0;
        analyticsState.charts = an.charts || null;
        if (an.courseGrades != null || an.practiceXp != null || an.codelabXp != null) {
            const hint = (strings.xphint || 'Course grades {$a->grades} · Practice {$a->practice} · CodeLab {$a->codelab}')
                .replace('{$a->grades}', String(an.courseGrades || 0))
                .replace('{$a->practice}', String(an.practiceXp || 0))
                .replace('{$a->codelab}', String(an.codelabXp || 0));
            root.find('[data-region="xp-breakdown"]').text(hint).attr('title', hint);
        }
        // Fallback if charts missing (older payload) — seed XP only; never invent solved/time from XP.
        if (!analyticsState.charts && an.series) {
            analyticsState.charts = {
                weekly: {
                    xp: {
                        series: (an.series || []).map((p) => ({label: p.label, value: Number(p.xp) || 0})),
                        avg: an.avgPerWeek || 0,
                        trend: an.trendPct || 0,
                        avgLabel: strings.perweek || 'Per week',
                    },
                },
            };
        }
        const periodEl = root.find('[data-region="analytics-period"]');
        const metricEl = root.find('[data-region="analytics-metric"]');
        if (periodEl.length) {
            analyticsState.period = periodEl.val() || 'weekly';
        }
        if (metricEl.length) {
            analyticsState.metric = metricEl.val() || 'xp';
        }
        refreshAnalytics(root);

        const st = data.streak || {};
        root.find('[data-region="streak-current"]').text(String(st.current || 0));
        root.find('[data-region="streak-longest"]').text(
            'Highest streak: ' + (st.longest || 0) + ' days'
        );
        root.find('[data-region="streak-hint"]').text(
            st.hint || strings.streakhint || strings.keepstreak || ''
        );
        renderStreakWeek(root, st.days || []);

        const pl = data.player || {};
        root.find('[data-region="stat-course-coding"]').text(String(pl.courseCodingSolved || 0));
        root.find('[data-region="stat-course-mcq"]').text(String(pl.courseMcqCorrect || 0));
        root.find('[data-region="stat-practice"]').text(String(pl.practiceSolved || 0));
        root.find('[data-region="stat-battles"]').text(String(pl.battlesWon || 0));
        const platTotal = pl.platformsTotal || 5;
        root.find('[data-region="stat-platforms"]').text(
            String(pl.platformsConnected || 0) + ' / ' + String(platTotal)
        );
        root.find('[data-region="stat-github"]').text(
            pl.githubConnected
                ? (strings.githubyes || 'Connected')
                : (strings.githubno || 'Not connected')
        );
        root.find('[data-region="stat-interviews"]').text(String(pl.interviewsTaken || 0));
        root.find('[data-region="stat-streak"]').text(String(pl.streak || 0));
        root.find('[data-region="hero-streak"]').text(String(pl.streak || 0));

        const links = data.links || {};
        let quick = '';
        if (data.hasPractice) {
            quick += '<a class="nxd-quick" href="' + esc(links.practice) + '">NexPractice</a>';
        }
        if (data.hasCodeLab) {
            quick += '<a class="nxd-quick" href="' + esc(links.codelab) + '">CodeLab</a>';
        }
        if (data.hasBattleGround) {
            quick += '<a class="nxd-quick" href="' + esc(links.battleground) + '">BattleGround</a>';
        }
        if (data.hasPortfolio) {
            quick += '<a class="nxd-quick" href="' + esc(links.portfolio) + '">Portfolio</a>';
        }
        if (data.hasInterview) {
            quick += '<a class="nxd-quick" href="' + esc(links.interview) + '">Interview</a>';
        }
        quick += '<a class="nxd-quick" href="' + esc(links.mycourses || '/my/courses.php') + '">Courses</a>';
        root.find('[data-region="quicklinks"]').html(quick);
    };

    /**
     * Skeleton state for hero Learning time (+ analytics Time metric when selected).
     *
     * @param {jQuery} root
     * @param {boolean} on
     */
    const setLearningTimeLoading = (root, on) => {
        root.toggleClass('nxd--time-loading', !!on);
        const hero = root.find('[data-region="learning-time"]');
        hero.toggleClass('nxd-skel-inline', !!on);
        if (on) {
            hero.text('');
            hero.attr('aria-busy', 'true');
        } else {
            hero.removeAttr('aria-busy');
        }
        const total = root.find('[data-region="total-xp"]');
        if (analyticsState.metric === 'time') {
            total.toggleClass('nxd-skel-inline', !!on);
            root.find('[data-region="analytics-total-metric"]').toggleClass('is-time-loading', !!on);
            if (on) {
                total.attr('aria-busy', 'true');
            } else {
                total.removeAttr('aria-busy');
            }
        } else {
            total.removeClass('nxd-skel-inline').removeAttr('aria-busy');
            root.find('[data-region="analytics-total-metric"]').removeClass('is-time-loading');
        }
        root.find('[data-region="xp-chart"]').toggleClass('nxd-chart--time-loading',
            !!on && analyticsState.metric === 'time');
    };

    /**
     * Apply deferred learning-time payload.
     *
     * @param {jQuery} root
     * @param {object} data
     */
    const applyLearningTime = (root, data) => {
        data = data || {};
        const minutes = Number(data.totalTimeMinutes) || 0;
        analyticsState.totalTimeMinutes = minutes;
        const charts = analyticsState.charts || {};
        ['daily', 'weekly', 'monthly'].forEach((period) => {
            if (!charts[period]) {
                charts[period] = {};
            }
            if (data.charts && data.charts[period]) {
                charts[period].time = data.charts[period];
            }
        });
        analyticsState.charts = charts;
        setLearningTimeLoading(root, false);
        root.find('[data-region="learning-time"]').text(data.learningTime || '0h 0m');
        refreshAnalytics(root);
    };

    /**
     * Fetch learning time after the main dashboard is visible.
     *
     * @param {jQuery} root
     */
    const loadLearningTime = (root) => {
        setLearningTimeLoading(root, true);
        Ajax.call([{methodname: 'local_nexdashboard_get_learning_time', args: {}}])[0]
            .then((data) => {
                applyLearningTime(root, data || {});
                return null;
            })
            .catch(() => {
                setLearningTimeLoading(root, false);
                root.find('[data-region="learning-time"]').text('0h 0m');
                analyticsState.totalTimeMinutes = 0;
                refreshAnalytics(root);
            });
    };

    const load = (root) => {
        setLoading(root, true);
        Ajax.call([{methodname: 'local_nexdashboard_get_dashboard', args: {}}])[0]
            .then((data) => {
                render(root, data || {});
                setLoading(root, false);
                // Defer expensive learning-time calc so the shell paints first.
                if (!data || data.learningTimePending || !data.learningTime) {
                    loadLearningTime(root);
                }
                return null;
            })
            .catch((err) => {
                setError(root, (err && err.message) || strings.loaderror);
            });
    };

    const markPrimaryNav = (which) => {
        const pathOf = (href) => {
            try {
                return new URL(href, window.location.origin).pathname.replace(/\/+$/, '') || '/';
            } catch (e) {
                return '';
            }
        };
        const isMatch = (href) => {
            const p = pathOf(href);
            if (which === 'dashboard') {
                return p === '/my' || p === '/my/index.php' || p.indexOf('/local/nexdashboard') === 0;
            }
            return p.indexOf('/my/courses') !== -1 || p.indexOf('/local/nexcourse') === 0;
        };
        const nodes = document.querySelectorAll(
            '.primary-navigation a, .moremenu a, .navbar .nav-link, .edw-nav a, [role="menubar"] a'
        );
        nodes.forEach((a) => {
            if (!isMatch(a.getAttribute('href') || '')) {
                return;
            }
            a.classList.add('active');
            a.setAttribute('aria-current', 'page');
            const item = a.closest('.nav-item, li');
            if (item) {
                item.classList.add('active');
            }
        });
    };

    const init = function(cfg) {
        strings = (cfg && cfg.strings) || {};
        const root = $('[data-region="nxd-dashboard"]');
        if (!root.length) {
            return;
        }
        markPrimaryNav('dashboard');
        applyLabels(root);
        root.find('[data-region="retry"]').on('click', function() {
            load(root);
        });
        root.on('change', '[data-region="analytics-period"], [data-region="analytics-metric"]', function() {
            analyticsState.period = root.find('[data-region="analytics-period"]').val() || 'weekly';
            analyticsState.metric = root.find('[data-region="analytics-metric"]').val() || 'xp';
            // Keep skeleton if Time Spent is selected while still loading.
            if (root.hasClass('nxd--time-loading') && analyticsState.metric === 'time') {
                setLearningTimeLoading(root, true);
            } else {
                root.find('[data-region="total-xp"]').removeClass('nxd-skel-inline').removeAttr('aria-busy');
                root.find('[data-region="analytics-total-metric"]').removeClass('is-time-loading');
                root.find('[data-region="xp-chart"]').removeClass('nxd-chart--time-loading');
            }
            refreshAnalytics(root);
        });
        root.on('click', '[data-goal]', function() {
            const target = parseInt($(this).attr('data-goal'), 10);
            if (![3, 5, 7].includes(target)) {
                return;
            }
            Ajax.call([{methodname: 'local_nexdashboard_set_goal', args: {target: target}}])[0]
                .then((goal) => {
                    renderGoal(root, goal || {});
                    return null;
                })
                .catch(Notification.exception);
        });
        root.find('[data-region="copy-summary"]').on('click', function() {
            const btn = $(this);
            const text = shareText || '';
            if (!text) {
                return;
            }
            const done = () => {
                btn.text(strings.copied || 'Copied');
                setTimeout(() => btn.text(strings.copysummary || 'Copy summary'), 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(() => {
                    window.prompt('Copy summary', text);
                });
            } else {
                window.prompt('Copy summary', text);
                done();
            }
        });
        load(root);
    };

    return {init};
});
