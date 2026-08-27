/**
 * Coding portfolio dashboard.
 *
 * @module     local_nexportfolio/dashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    const PLATFORM_META = {
        leetcode: {color: '#FFA116', label: 'LeetCode'},
        codechef: {color: '#5B4638', label: 'CodeChef'},
        codeforces: {color: '#1F8ACB', label: 'Codeforces'},
        geeksforgeeks: {color: '#2F8D46', label: 'GeeksforGeeks'},
        codingninjas: {color: '#FC4F41', label: 'Coding Ninjas'}
    };

    const pad = (n) => (n < 10 ? '0' + n : String(n));
    const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());

    const formatWhen = (ts, neverStr) => {
        if (!ts) {
            return neverStr;
        }
        try {
            return new Date(ts * 1000).toLocaleString();
        } catch (e) {
            return neverStr;
        }
    };

    const heatLevel = (count) => {
        if (!count) {
            return 0;
        }
        if (count === 1) {
            return 1;
        }
        if (count <= 3) {
            return 2;
        }
        if (count <= 6) {
            return 3;
        }
        return 4;
    };

    const escapeHtml = (s) => {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const parseDetail = (p) => {
        try {
            return JSON.parse(p.datajson || '{}') || {};
        } catch (e) {
            return {};
        }
    };

    const renderHeatmap = (root, points, strings) => {
        strings = strings || {};
        const platformLabels = strings.heatmapPlatforms || {};
        const map = {};
        const breakdownMap = {};
        (points || []).forEach((p) => {
            if (!p || !p.date) {
                return;
            }
            map[p.date] = parseInt(p.count, 10) || 0;
            breakdownMap[p.date] = p.breakdown || {};
        });

        const wrap = root.closest('.np-heatmap-wrap');
        let tooltip = wrap ? wrap.querySelector('[data-region="heatmap-tooltip"]') : null;
        if (wrap && !tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'np-heat-tooltip';
            tooltip.setAttribute('data-region', 'heatmap-tooltip');
            tooltip.hidden = true;
            wrap.appendChild(tooltip);
        }

        const formatHeatDate = (key) => {
            try {
                const d = new Date(key + 'T12:00:00');
                return d.toLocaleDateString(undefined, {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            } catch (e) {
                return key;
            }
        };

        const showTooltip = (cell, date, count) => {
            if (!tooltip) {
                return;
            }
            const bd = breakdownMap[date] || {};
            const title = document.createElement('div');
            title.className = 'np-heat-tooltip__title';
            title.textContent = formatHeatDate(date);
            tooltip.replaceChildren(title);

            const total = document.createElement('div');
            total.className = 'np-heat-tooltip__total';
            total.textContent = count > 0
                ? ((strings.heatmap_tooltip_total || 'Total') + ': ' + count)
                : (strings.heatmap_tooltip_none || 'No activity');
            tooltip.appendChild(total);

            if (count > 0) {
                const list = document.createElement('ul');
                list.className = 'np-heat-tooltip__list';
                Object.keys(bd).sort().forEach((key) => {
                    const n = parseInt(bd[key], 10) || 0;
                    if (n <= 0) {
                        return;
                    }
                    const li = document.createElement('li');
                    const label = platformLabels[key] || key;
                    const unit = key === 'github'
                        ? (strings.heatmap_github_unit || 'contributions')
                        : (strings.heatmap_coding_unit || 'activities');
                    li.textContent = label + ': ' + n + ' ' + unit;
                    list.appendChild(li);
                });
                if (list.childElementCount) {
                    tooltip.appendChild(list);
                }
            }

            tooltip.hidden = false;
            const rect = cell.getBoundingClientRect();
            const wrapRect = wrap.getBoundingClientRect();
            let left = rect.left - wrapRect.left + (rect.width / 2);
            let top = rect.top - wrapRect.top - 8;
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
            tooltip.style.transform = 'translate(-50%, -100%)';
        };

        const hideTooltip = () => {
            if (tooltip) {
                tooltip.hidden = true;
            }
        };

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let start = new Date(today);
        start.setDate(start.getDate() - 364);
        while (start.getDay() !== 0) {
            start.setDate(start.getDate() - 1);
        }

        const inner = document.createElement('div');
        inner.className = 'np-heatmap__inner';

        const wdays = document.createElement('div');
        wdays.className = 'np-heatmap__wdays';
        ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach((label) => {
            const span = document.createElement('span');
            span.textContent = label;
            wdays.appendChild(span);
        });
        inner.appendChild(wdays);

        const monthsWrap = document.createElement('div');
        monthsWrap.className = 'np-heatmap__months';

        const grid = document.createElement('div');
        grid.className = 'np-heatmap__grid';

        let cursor = new Date(start);
        let lastMonth = -1;

        while (cursor <= today) {
            const week = document.createElement('div');
            week.className = 'np-heatmap__week';
            const weekStart = new Date(cursor);
            const month = weekStart.getMonth();
            const label = document.createElement('div');
            label.className = 'np-heatmap__mlabel';
            if (month !== lastMonth) {
                label.textContent = weekStart.toLocaleString(undefined, {month: 'short'});
                lastMonth = month;
            } else {
                label.textContent = '';
            }
            week.appendChild(label);
            for (let i = 0; i < 7; i++) {
                const cell = document.createElement('span');
                const key = ymd(cursor);
                const count = map[key] || 0;
                const level = cursor > today ? -1 : heatLevel(count);
                cell.className = 'np-heat' + (level >= 0 ? ' np-heat--' + level : ' np-heat--empty');
                cell.setAttribute('tabindex', '0');
                cell.setAttribute('role', 'gridcell');
                cell.setAttribute('aria-label', formatHeatDate(key) + ', ' + count);
                cell.addEventListener('mouseenter', () => showTooltip(cell, key, count));
                cell.addEventListener('mouseleave', hideTooltip);
                cell.addEventListener('focus', () => showTooltip(cell, key, count));
                cell.addEventListener('blur', hideTooltip);
                week.appendChild(cell);
                cursor.setDate(cursor.getDate() + 1);
            }
            grid.appendChild(week);
        }
        monthsWrap.appendChild(grid);
        inner.appendChild(monthsWrap);
        root.innerHTML = '';
        root.appendChild(inner);
        if (wrap && tooltip) {
            wrap.appendChild(tooltip);
        }
    };

    const sparklineSvg = (history, color) => {
        const pts = (history || []).map((h) => Number(h.rating)).filter((n) => !isNaN(n) && n > 0);
        if (pts.length < 2) {
            return '<div class="np-sparkline np-sparkline--empty"></div>';
        }
        const w = 120;
        const h = 44;
        const min = Math.min.apply(null, pts);
        const max = Math.max.apply(null, pts);
        const span = Math.max(1, max - min);
        const coords = pts.map((v, i) => {
            const x = (i / (pts.length - 1)) * (w - 4) + 2;
            const y = h - 4 - ((v - min) / span) * (h - 8);
            return x.toFixed(1) + ',' + y.toFixed(1);
        });
        const line = coords.join(' ');
        const area = '2,' + (h - 2) + ' ' + line + ' ' + (w - 2) + ',' + (h - 2);
        return '' +
            '<svg class="np-sparkline" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" aria-hidden="true">' +
            '<polygon fill="rgba(15,23,42,0.08)" points="' + area + '"></polygon>' +
            '<polyline fill="none" stroke="' + escapeHtml(color) + '" stroke-width="2" points="' + line + '"></polyline>' +
            '</svg>';
    };

    const difficultyBars = (easy, medium, hard, total, strings) => {
        const rows = [
            {key: 'easy', label: strings.easy, count: easy, color: '#22c55e'},
            {key: 'medium', label: strings.medium, count: medium, color: '#f59e0b'},
            {key: 'hard', label: strings.hard, count: hard, color: '#ef4444'}
        ];
        const denom = Math.max(total, easy + medium + hard, 1);
        return rows.map((r) => {
            const pct = Math.min(100, Math.round((r.count / denom) * 100));
            return '' +
                '<div class="np-diff">' +
                '<div class="np-diff__row"><span>' + escapeHtml(r.label) + '</span><strong>' + r.count + '</strong></div>' +
                '<div class="np-diff__track"><span style="width:' + pct + '%;background:' + r.color + '"></span></div>' +
                '</div>';
        }).join('');
    };

    const emhFromPlatform = (p) => {
        const detail = parseDetail(p);
        const diff = detail.problemsByDifficulty || {};
        return {
            easy: Number(diff.easy || 0),
            medium: Number(diff.medium || 0),
            hard: Number(diff.hard || 0)
        };
    };

    const problemsOverviewCard = (platforms, data, strings) => {
        const connected = (platforms || []).filter((p) => p.connected);
        let easy = 0;
        let medium = 0;
        let hard = 0;
        let streak = 0;
        let maxstreak = 0;
        let activedays = 0;
        let year = new Date().getFullYear();
        connected.forEach((p) => {
            const e = emhFromPlatform(p);
            easy += e.easy;
            medium += e.medium;
            hard += e.hard;
            streak = Math.max(streak, Number(p.streak || 0));
            maxstreak = Math.max(maxstreak, Number(p.maxstreak || 0));
            activedays = Math.max(activedays, Number(p.activedays || 0));
            const detail = parseDetail(p);
            if (detail.stats && detail.stats.activeYear) {
                year = detail.stats.activeYear;
            }
        });
        const emhTotal = easy + medium + hard;
        const total = Number(data.totalsolved || 0) || emhTotal;
        const others = Math.max(0, total - emhTotal);

        const diffHtml = difficultyBars(easy, medium, hard, Math.max(total, 1), strings) +
            (others > 0
                ? '<div class="np-diff">' +
                    '<div class="np-diff__row"><span>' + escapeHtml(strings.others || 'Others') +
                    '</span><strong>' + others + '</strong></div>' +
                    '<div class="np-diff__track"><span style="width:' +
                    Math.min(100, Math.round((others / Math.max(total, 1)) * 100)) +
                    '%;background:#60a5fa"></span></div></div>'
                : '');

        return '' +
            '<article class="np-panel np-panel--problems">' +
            '<header class="np-panel__head">' +
            '<h3 class="np-panel__title">' + escapeHtml(strings.problemssolved) + '</h3>' +
            '<span class="np-pill">' + total + ' ' + escapeHtml(strings.totalproblems) + '</span>' +
            '</header>' +
            '<div class="np-panel__stats">' +
            '<div><span>' + escapeHtml(strings.currentstreak) + '</span><strong>' + streak + 'd</strong></div>' +
            '<div><span>' + escapeHtml(strings.maxstreak) + '</span><strong>' + maxstreak + 'd</strong></div>' +
            '<div><span>' + escapeHtml(strings.activedays) + ' (' + year + ')</span><strong>' + activedays + '</strong></div>' +
            '</div>' +
            '<div class="np-panel__body">' +
            '<div class="np-ring">' +
            '<div class="np-ring__value">' + total + '</div>' +
            '<div class="np-ring__label">' + escapeHtml(strings.problemssolvedshort) + '</div>' +
            '</div>' +
            '<div class="np-diff-list">' + diffHtml + '</div>' +
            '</div>' +
            '</article>';
    };

    const collectContestHistory = (platforms) => {
        const items = [];
        (platforms || []).filter((p) => p.connected).forEach((p) => {
            const detail = parseDetail(p);
            const history = Array.isArray(detail.contestHistory) ? detail.contestHistory : [];
            const meta = PLATFORM_META[p.platform] || {label: p.label};
            history.forEach((c) => {
                items.push({
                    platform: p.platform,
                    platformLabel: p.label || meta.label,
                    name: c.name || 'Contest',
                    date: c.date || '',
                    rank: c.rank,
                    rating: c.rating,
                    problemsSolved: c.problemsSolved,
                    totalProblems: c.totalProblems
                });
            });
        });
        items.sort((a, b) => String(b.date).localeCompare(String(a.date)));
        return items;
    };

    const contestsParticipationCard = (platforms, data, strings) => {
        const history = collectContestHistory(platforms);
        const total = Number(data.totalcontests || 0) || history.length;
        const rows = history.length
            ? history.map((c) => {
                const hasSolved = c.problemsSolved != null && c.totalProblems != null;
                const solved = hasSolved ? (c.problemsSolved + '/' + c.totalProblems) : '';
                return '' +
                    '<li class="np-contest">' +
                    '<div class="np-contest__main">' +
                    '<div class="np-contest__name">' + escapeHtml(c.platformLabel) + ': ' +
                    escapeHtml(c.name) + '</div>' +
                    '<div class="np-contest__meta">' +
                    '<span class="np-contest__rank">' + escapeHtml(strings.rank) + ': ' +
                    escapeHtml(String(c.rank != null && c.rank !== '' ? c.rank : '—')) + '</span>' +
                    '<span class="np-contest__rating">' + escapeHtml(strings.rating) + ': ' +
                    escapeHtml(String(c.rating != null ? Math.round(c.rating) : '—')) + '</span>' +
                    (hasSolved
                        ? '<span class="np-contest__solved">' + escapeHtml(strings.solved) + ': ' +
                            escapeHtml(solved) + '</span>'
                        : '') +
                    '</div></div>' +
                    '<div class="np-contest__date">' + escapeHtml(c.date || '') + '</div>' +
                    '</li>';
            }).join('')
            : '<li class="np-contest np-contest--empty">' + escapeHtml(strings.nocontests) + '</li>';

        return '' +
            '<article class="np-panel np-panel--contests">' +
            '<header class="np-panel__head">' +
            '<h3 class="np-panel__title">' + escapeHtml(strings.contestparticipation) + '</h3>' +
            '<span class="np-pill np-pill--warm">' + total + ' ' +
            escapeHtml(strings.contestsjoined) + '</span>' +
            '</header>' +
            '<ul class="np-contest-list">' + rows + '</ul>' +
            '</article>';
    };

    const ratingCardHtml = (p, strings, canManage) => {
        const meta = PLATFORM_META[p.platform] || {color: '#334155', label: p.label};
        const detail = parseDetail(p);
        const err = p.lasterror ? '<div class="np-card__error">' + escapeHtml(p.lasterror) + '</div>' : '';
        const note = p.note ? '<div class="np-card__note">' + escapeHtml(p.note) + '</div>' : '';
        const refreshBtn = canManage && p.connected
            ? '<button type="button" class="btn btn-sm btn-outline-primary" data-action="refresh" data-platform="' +
                p.platform + '">' + escapeHtml(strings.refresh) + '</button>'
            : '';
        const rating = p.rating ? Math.round(p.rating) : '—';
        const detailRanks = (() => {
            const global = detail.globalRank || detail.global_rank || '';
            const country = detail.countryRank || detail.country_rank || '';
            if (global || country) {
                const bits = [];
                if (global) {
                    bits.push(escapeHtml(strings.globalrank || 'Global') + ': ' + escapeHtml(String(global)));
                }
                if (country) {
                    bits.push(escapeHtml(strings.countryrank || 'Country') + ': ' + escapeHtml(String(country)));
                }
                return bits.join(' · ');
            }
            return escapeHtml(String(p.ranktext || '—'));
        })();
        const spark = sparklineSvg(detail.ratingHistory || [], meta.color);
        const emh = emhFromPlatform(p);
        const emhSum = emh.easy + emh.medium + emh.hard;
        const diffMini = (p.connected && emhSum > 0)
            ? '<div class="np-rating-card__diff">' + difficultyBars(emh.easy, emh.medium, emh.hard, Math.max(p.totalsolved || emhSum, 1), strings) + '</div>'
            : '';

        return '' +
            '<article class="np-rating-card np-rating-card--' + p.platform +
            (p.connected ? '' : ' np-rating-card--idle') +
            '" style="--np-accent:' + meta.color + '">' +
            '<header class="np-rating-card__head">' +
            '<div><div class="np-rating-card__name">' + escapeHtml(p.label || meta.label) + '</div>' +
            '<div class="np-rating-card__handle">' + (p.connected ? '@' + escapeHtml(p.handle) : 'Not connected') + '</div></div>' +
            refreshBtn +
            '</header>' +
            (p.connected
                ? '<div class="np-rating-card__body">' +
                    '<div class="np-rating-card__metrics">' +
                    '<div class="np-rating-card__rating" style="color:' + meta.color + '">' + rating + '</div>' +
                    '<div class="np-rating-card__rank">' + detailRanks + '</div>' +
                    '<div class="np-rating-card__contests">' + (p.contests || 0) + ' ' + escapeHtml(strings.contests) +
                    ' · ' + (p.totalsolved || 0) + ' ' + escapeHtml(strings.solved) + '</div>' +
                    '</div>' + spark +
                    '</div>' +
                    diffMini +
                    '<div class="np-rating-card__meta">' + escapeHtml(strings.lastfetched) + ': ' +
                    escapeHtml(formatWhen(p.lastfetch, strings.never)) + '</div>' + note + err
                : '<p class="np-card__empty">' + escapeHtml(strings.noconnections) + '</p>') +
            '</article>';
    };

    const renderPlatforms = (platformsEl, data, strings, canManage) => {
        const platforms = data.platforms || [];
        const connected = platforms.filter((p) => p.connected);
        const parts = [];

        if (connected.length) {
            parts.push('<div class="np-lc-overview">' +
                problemsOverviewCard(platforms, data, strings) +
                contestsParticipationCard(platforms, data, strings) +
                '</div>');
        }

        parts.push('<section class="np-ratings">');
        parts.push('<header class="np-ratings__head"><h3 class="np-section-title">' +
            escapeHtml(strings.platformratings) + '</h3>' +
            '<span class="np-pill np-pill--soft">' + connected.length + ' ' +
            escapeHtml(strings.platforms) + '</span></header>');
        parts.push('<div class="np-ratings__grid">');
        parts.push(platforms.map((p) => ratingCardHtml(p, strings, canManage)).join(''));
        parts.push('</div></section>');

        platformsEl.innerHTML = parts.join('');
    };

    const renderProjects = (wrap, listEl, projects, strings) => {
        if (!wrap || !listEl) {
            return;
        }
        const items = projects || [];
        if (!items.length) {
            wrap.hidden = true;
            listEl.innerHTML = '';
            return;
        }
        wrap.hidden = false;
        listEl.innerHTML = items.map((p) => {
            let topics = [];
            let languages = [];
            try {
                topics = JSON.parse(p.topics || '[]');
            } catch (e) {
                topics = [];
            }
            try {
                languages = JSON.parse(p.languages || '[]');
            } catch (e2) {
                languages = [];
            }
            const stack = languages.length
                ? languages.map((l) => l.name + (l.pct ? ' (' + l.pct + '%)' : '')).join(', ')
                : (p.primary_language || '');
            const topicHtml = topics.length
                ? '<div class="np-project__topics">' + topics.map((t) =>
                    '<span class="np-tag">' + escapeHtml(t) + '</span>').join('') + '</div>'
                : '';
            const badges = [
                p.is_fork ? '<span class="np-badge np-badge--muted">' + escapeHtml(strings.project_fork) + '</span>' : '',
                p.visibility === 'private'
                    ? '<span class="np-badge np-badge--warn">' + escapeHtml(strings.project_private) + '</span>' : ''
            ].join('');
            const when = p.lastpush ? formatWhen(p.lastpush, strings.never) : '';
            const bodyText = (p.description || p.readme || '').trim();
            const descHtml = bodyText
                ? '<div class="np-project__desc">' + escapeHtml(bodyText) + '</div>'
                : '<div class="np-project__desc np-project__desc--empty">' +
                    escapeHtml(strings.project_no_description || 'No README found for this repository.') + '</div>';
            return '' +
                '<article class="np-project">' +
                '<header class="np-project__head">' +
                '<h4 class="np-project__title"><a href="' + escapeHtml(p.url) + '" target="_blank" rel="noopener">' +
                escapeHtml(p.fullname || p.name) + '</a></h4>' +
                '<div class="np-project__badges">' + badges + '</div>' +
                '</header>' +
                descHtml +
                '<div class="np-project__meta">' +
                '<span>★ ' + (p.stars || 0) + ' ' + escapeHtml(strings.project_stars) + '</span>' +
                '<span>⑂ ' + (p.forks || 0) + ' ' + escapeHtml(strings.project_forks) + '</span>' +
                (when ? '<span>' + escapeHtml(strings.project_updated) + ': ' + escapeHtml(when) + '</span>' : '') +
                '</div>' +
                (stack ? '<div class="np-project__stack"><strong>' + escapeHtml(strings.project_stack) +
                    ':</strong> ' + escapeHtml(stack) + '</div>' : '') +
                topicHtml +
                '</article>';
        }).join('');
    };

    const fmtCount = (n) => {
        const v = Number(n) || 0;
        try {
            return v.toLocaleString();
        } catch (e) {
            return String(v);
        }
    };

    const githubMetric = (value, label, extraClass, hint) => {
        return '<div class="np-gh__metric' + (extraClass ? ' ' + extraClass : '') + '">' +
            '<strong>' + fmtCount(value) + '</strong>' +
            '<span>' + escapeHtml(label) + '</span>' +
            (hint ? '<em>' + escapeHtml(hint) + '</em>' : '') +
            '</div>';
    };

    const renderGithubStats = (wrap, github, strings) => {
        if (!wrap) {
            return;
        }
        const gh = github || {};
        if (!gh.enabled || !gh.connected || !gh.login) {
            wrap.hidden = true;
            wrap.innerHTML = '';
            return;
        }
        const stats = gh.stats || {};
        const profileUrl = gh.profileurl || stats.profileurl || ('https://github.com/' + gh.login);
        const displayName = stats.name || ('@' + gh.login);
        const metaBits = [];
        if (stats.company) {
            metaBits.push(stats.company);
        }
        if (stats.location) {
            metaBits.push(stats.location);
        }
        let joined = '';
        if (stats.createdat) {
            try {
                joined = new Date(stats.createdat * 1000).toLocaleDateString(undefined, {
                    month: 'short',
                    year: 'numeric'
                });
            } catch (e) {
                joined = '';
            }
        }
        const joinedText = joined
            ? String(strings.github_joined || 'Joined {$a}').replace('{$a}', joined)
            : '';
        const avatar = gh.avatarurl
            ? '<img class="np-gh__avatar" src="' + escapeHtml(gh.avatarurl) + '" alt="" width="56" height="56">'
            : '';
        const activity = stats.hasgraphql
            ? '<div class="np-gh__activity">' +
                githubMetric(stats.commitsyear, strings.github_commits || 'Commits') +
                githubMetric(stats.prsyear, strings.github_prs || 'Pull requests') +
                githubMetric(stats.issuesyear, strings.github_issues || 'Issues') +
                githubMetric(stats.reviewsyear, strings.github_reviews || 'Reviews') +
                '</div>'
            : '';

        wrap.hidden = false;
        wrap.innerHTML =
            '<article class="np-panel np-panel--github">' +
            '<header class="np-gh__head">' +
            '<div class="np-gh__identity">' +
            avatar +
            '<div>' +
            '<h3 class="np-panel__title">' + escapeHtml(strings.github_stats_title || 'GitHub') + '</h3>' +
            '<div class="np-gh__login">@' + escapeHtml(gh.login) +
            (stats.name && stats.name !== gh.login ? ' · ' + escapeHtml(displayName) : '') +
            '</div>' +
            (metaBits.length ? '<div class="np-gh__meta-line">' + escapeHtml(metaBits.join(' · ')) + '</div>' : '') +
            '</div></div>' +
            '<a class="nxf-btn nxf-btn--sm" href="' + escapeHtml(profileUrl) + '" target="_blank" rel="noopener">' +
            escapeHtml(strings.github_viewprofile || 'View on GitHub') + '</a>' +
            '</header>' +
            (stats.bio ? '<p class="np-gh__bio">' + escapeHtml(stats.bio) + '</p>' : '') +
            '<div class="np-gh__metrics">' +
            githubMetric(
                stats.contributionsyear,
                strings.github_contributions || 'Contributions',
                'np-gh__metric--lead',
                strings.github_contributions_hint || 'Last 12 months'
            ) +
            githubMetric(stats.publicrepos, strings.github_repos || 'Repositories') +
            githubMetric(stats.followers, strings.github_followers || 'Followers') +
            githubMetric(stats.following, strings.github_following || 'Following') +
            githubMetric(stats.starsreceived, strings.github_stars_received || 'Stars') +
            githubMetric(stats.forksreceived, strings.github_forks_received || 'Forks') +
            githubMetric(stats.gists, strings.github_gists || 'Gists') +
            githubMetric(stats.contributedto, strings.github_contributed_to || 'Contributed to') +
            '</div>' +
            activity +
            (joinedText ? '<p class="np-gh__joined">' + escapeHtml(joinedText) + '</p>' : '') +
            '</article>';
    };

    const loadPortfolio = (root, cfg) => {
        return Ajax.call([{
            methodname: 'local_nexportfolio_get_portfolio',
            args: {}
        }])[0].then((data) => {
            const empty = root.querySelector('[data-region="empty"]');
            const platformsEl = root.querySelector('[data-region="platforms"]');
            const githubEl = root.querySelector('[data-region="github-stats"]');
            const projectsWrap = root.querySelector('[data-region="projects-wrap"]');
            const projectsEl = root.querySelector('[data-region="projects"]');
            const heatWrap = root.querySelector('[data-region="heatmap-wrap"]');
            const heat = root.querySelector('[data-region="heatmap"]');
            const refreshAll = root.querySelector('[data-action="refresh-all"]');

            const connected = (data.platforms || []).filter((p) => p.connected);
            const hasGithub = !!(data.github && data.github.enabled && data.github.connected && data.github.login);
            const totalPlatforms = (data.platforms || []).length || 5;
            const pct = totalPlatforms > 0
                ? Math.round((connected.length / totalPlatforms) * 100)
                : 0;

            const headerEl = root.querySelector('[data-region="nxf-header"]') || root;
            const setText = (sel, value) => {
                const el = headerEl.querySelector(sel) || root.querySelector(sel);
                if (el) {
                    el.textContent = String(value);
                }
            };
            const donut = root.querySelector('[data-region="donut"]');
            if (donut) {
                donut.style.setProperty('--nxf-donut-pct', String(pct));
            }
            setText('[data-region="donut-value"]', pct + '%');
            setText('[data-stat="solved"]', data.totalsolved || 0);
            setText('[data-stat="streak"]', data.currentstreak || 0);
            setText('[data-stat="contests"]', data.totalcontests || 0);
            setText('[data-stat="platforms"]', connected.length + ' / ' + totalPlatforms);
            setText('[data-stat="projects"]', data.projectcount || 0);
            setText('[data-hstat="solved"]', data.totalsolved || 0);
            setText('[data-hstat="contests"]', data.totalcontests || 0);
            setText('[data-hstat="projects"]', data.projectcount || 0);
            setText('[data-hstat="streak"]', data.currentstreak || 0);
            setText('[data-hstat="maxstreak"]', data.maxstreak || 0);

            if (!connected.length && !(data.projects || []).length && !hasGithub) {
                let heatPreview = [];
                try {
                    heatPreview = JSON.parse(data.mergedheatmap || '[]');
                } catch (e) {
                    heatPreview = [];
                }
                if (!heatPreview.length) {
                    if (empty) {
                        empty.hidden = false;
                    }
                    if (platformsEl) {
                        platformsEl.innerHTML = '';
                    }
                    if (githubEl) {
                        githubEl.hidden = true;
                        githubEl.innerHTML = '';
                    }
                    if (projectsWrap) {
                        projectsWrap.hidden = true;
                    }
                    if (heatWrap) {
                        heatWrap.hidden = true;
                    }
                    if (refreshAll) {
                        refreshAll.disabled = true;
                    }
                    return data;
                }
            }

            if (empty) {
                empty.hidden = true;
            }
            if (platformsEl && connected.length) {
                renderPlatforms(platformsEl, data, cfg.strings, cfg.canManage);
            } else if (platformsEl) {
                platformsEl.innerHTML = '';
            }
            renderGithubStats(githubEl, data.github, cfg.strings);
            renderProjects(projectsWrap, projectsEl, data.projects, cfg.strings);
            let points = [];
            try {
                points = JSON.parse(data.mergedheatmap || '[]');
            } catch (e) {
                points = [];
            }
            if (heatWrap && heat) {
                heatWrap.hidden = points.length === 0;
                if (points.length) {
                    renderHeatmap(heat, points, cfg.strings);
                } else {
                    heat.innerHTML = '';
                }
            }
            if (refreshAll) {
                refreshAll.disabled = !cfg.canManage;
            }
            return data;
        }).catch(Notification.exception);
    };

    const refreshOne = (platform, btn, root, cfg) => {
        if (btn) {
            btn.disabled = true;
            btn.textContent = cfg.strings.refreshing;
        }
        return Ajax.call([{
            methodname: 'local_nexportfolio_refresh_platform',
            args: {platform: platform, force: true}
        }])[0].then(() => loadPortfolio(root, cfg)).catch(Notification.exception).finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.textContent = cfg.strings.refresh;
            }
        });
    };

    const refreshAll = async (root, cfg, btn) => {
        if (btn) {
            btn.disabled = true;
            btn.textContent = cfg.strings.refreshing;
        }
        try {
            const data = await Ajax.call([{
                methodname: 'local_nexportfolio_get_portfolio',
                args: {}
            }])[0];
            const list = (data.platforms || []).filter((p) => p.connected);
            for (let i = 0; i < list.length; i++) {
                await Ajax.call([{
                    methodname: 'local_nexportfolio_refresh_platform',
                    args: {platform: list[i].platform, force: true}
                }])[0];
            }
            await loadPortfolio(root, cfg);
        } catch (e) {
            Notification.exception(e);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = cfg.strings.refreshall;
            }
        }
    };

    const init = (cfg) => {
        const root = document.getElementById('np-dashboard');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        cfg.strings = cfg.strings || {};

        root.addEventListener('click', (e) => {
            const one = e.target.closest('[data-action="refresh"]');
            if (one) {
                refreshOne(one.getAttribute('data-platform'), one, root, cfg);
                return;
            }
            const all = e.target.closest('[data-action="refresh-all"]');
            if (all) {
                refreshAll(root, cfg, all);
            }
        });

        loadPortfolio(root, cfg);
    };

    return {init: init};
});
