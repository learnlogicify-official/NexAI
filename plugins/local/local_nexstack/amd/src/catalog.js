/**
 * NexStack mission catalog (NexCodeLab-style list chrome).
 *
 * @module     local_nexstack/catalog
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    const PERPAGE = 12;

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const statusMeta = {
        completed: {label: 'Completed', action: 'Open again', btn: 'nxs-action--done'},
        inprogress: {label: 'In Progress', action: 'Continue', btn: 'nxs-action--progress'},
        notstarted: {label: 'Not Started', action: 'Start', btn: 'nxs-action--solve'}
    };

    const normalizeStatus = (s) => {
        if (s === 'completed') {
            return 'completed';
        }
        if (s === 'inprogress' || s === 'in_progress') {
            return 'inprogress';
        }
        return 'notstarted';
    };

    const trackLabel = (t) => {
        const map = {web: 'Web', frontend: 'Frontend', fullstack: 'Full stack'};
        return map[t] || (t ? String(t) : 'Mission');
    };

    const state = {page: 0, missions: []};

    const readBootstrap = () => {
        const node = document.getElementById('nxs-catalog-data');
        if (!node || !node.textContent) {
            return [];
        }
        try {
            const data = JSON.parse(node.textContent);
            return Array.isArray(data) ? data : [];
        } catch (e) {
            return [];
        }
    };

    const countsOf = (list) => {
        const counts = {all: list.length, completed: 0, inprogress: 0, notstarted: 0};
        list.forEach(function(m) {
            const st = normalizeStatus(m.userstatus || m.status);
            counts[st] = (counts[st] || 0) + 1;
        });
        return counts;
    };

    const filtered = (root) => {
        const search = String((root.querySelector('[data-filter="search"]') || {}).value || '')
            .trim().toLowerCase();
        const track = String((root.querySelector('[data-filter="track"]') || {}).value || '');
        const userstatus = String((root.querySelector('[data-filter="userstatus"]') || {}).value || 'all');

        return state.missions.filter(function(m) {
            const st = normalizeStatus(m.userstatus || m.status);
            if (track && m.track !== track) {
                return false;
            }
            if (userstatus !== 'all' && st !== userstatus) {
                return false;
            }
            if (!search) {
                return true;
            }
            const hay = ((m.name || '') + ' ' + (m.summary || '') + ' ' + (m.track || '')).toLowerCase();
            return hay.indexOf(search) !== -1;
        });
    };

    const renderHeader = (root, counts) => {
        const total = counts.all || 0;
        const solved = counts.completed || 0;
        const pct = total > 0 ? Math.round((solved / total) * 100) : 0;
        const donut = root.querySelector('[data-region="donut"]');
        if (donut) {
            donut.style.setProperty('--nxs-donut-pct', String(pct));
        }
        const donutVal = root.querySelector('[data-region="donut-value"]');
        if (donutVal) {
            donutVal.textContent = pct + '%';
        }
        const setStat = (key, text) => {
            const el = root.querySelector('[data-stat="' + key + '"]');
            if (el) {
                el.textContent = text;
            }
        };
        setStat('solved', solved + ' / ' + total);
        setStat('steps', String(state.missions.reduce(function(sum, m) {
            return sum + (m.completedcount || 0);
        }, 0)));
        setStat('runtime', String(state.missions.filter(function(m) {
            return m.runtime === 'webcontainer';
        }).length));
        setStat('open', String(counts.inprogress || 0));

        const setH = (key, val) => {
            const el = root.querySelector('[data-hstat="' + key + '"]');
            if (el) {
                el.textContent = String(val);
            }
        };
        setH('completed', counts.completed || 0);
        setH('inprogress', counts.inprogress || 0);
        setH('notstarted', counts.notstarted || 0);
        setH('total', counts.all || 0);
    };

    const renderCards = (root, missions) => {
        const grid = root.querySelector('[data-region="grid"]');
        if (!grid) {
            return;
        }
        if (!missions.length) {
            grid.innerHTML = '<p class="nxs-empty">No missions match your filters.</p>';
            return;
        }
        grid.innerHTML = missions.map(function(m, i) {
            const st = normalizeStatus(m.userstatus || m.status);
            const meta = statusMeta[st] || statusMeta.notstarted;
            const steps = (m.completedcount || 0) + ' / ' + (m.stepcount || 0) + ' steps';
            const num = m.number != null ? m.number : (i + 1);
            const mins = m.estimatedmins || m.estimateminutes || 30;
            const runtime = m.runtime === 'webcontainer' ? 'WebContainer' : 'Static';
            return '<article class="nxs-pcard nxs-pcard--' + esc(st) + '">' +
                '<div class="nxs-pcard__left">' +
                    '<div class="nxs-pcard__num">' + esc(num) + '</div>' +
                    '<div class="nxs-trackbadge">' + esc(trackLabel(m.track)) + '</div>' +
                    '<div class="nxs-diffbadge nxs-diffbadge--' + esc(m.difficulty || 'easy') + '">' +
                        esc(String(m.difficulty || 'easy')) +
                    '</div>' +
                '</div>' +
                '<div class="nxs-pcard__body">' +
                    '<div class="nxs-pcard__topline">' +
                        '<h3 class="nxs-pcard__title"><a href="' + esc(m.url) + '">' + esc(m.name) + '</a></h3>' +
                        '<span class="nxs-statuspill nxs-statuspill--' + esc(st) + '">' +
                            esc(meta.label) + '</span>' +
                    '</div>' +
                    '<p class="nxs-pcard__scenario">' + esc(m.summary || '') + '</p>' +
                    '<div class="nxs-pcard__meta">' +
                        '<span>' + esc(mins) + ' min</span>' +
                        '<span>' + esc(steps) + '</span>' +
                        '<span>' + esc(runtime) + '</span>' +
                        '<a class="nxs-action ' + meta.btn + '" href="' + esc(m.url) + '">' +
                            esc(meta.action) + '</a>' +
                    '</div>' +
                '</div>' +
            '</article>';
        }).join('');
    };

    const pageWindow = (page, pages) => {
        const set = {};
        [0, pages - 1, page].forEach(function(n) {
            if (n >= 0 && n < pages) {
                set[n] = true;
            }
        });
        for (let i = page - 2; i <= page + 2; i++) {
            if (i >= 0 && i < pages) {
                set[i] = true;
            }
        }
        return Object.keys(set).map(Number).sort(function(a, b) { return a - b; });
    };

    const renderPager = (root, total, page, perpage) => {
        const pager = root.querySelector('[data-region="pager"]');
        if (!pager) {
            return;
        }
        const pages = Math.max(1, Math.ceil((total || 0) / perpage));
        if (!total || pages <= 1) {
            pager.setAttribute('hidden', 'hidden');
            pager.innerHTML = '';
            return;
        }
        const from = page * perpage + 1;
        const to = Math.min(total, (page + 1) * perpage);
        const nums = pageWindow(page, pages);
        let controls = '<button type="button" class="nxs-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>Prev</button>';
        let prev = null;
        nums.forEach(function(n) {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="nxs-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="nxs-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="nxs-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';

        pager.removeAttribute('hidden');
        pager.innerHTML =
            '<div class="nxs-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="nxs-pager__controls">' + controls + '</div>';
    };

    const refresh = (root) => {
        const allCounts = countsOf(state.missions);
        renderHeader(root, allCounts);

        root.querySelectorAll('[data-count]').forEach(function(el) {
            const key = el.getAttribute('data-count');
            el.textContent = '(' + (allCounts[key] || 0) + ')';
        });

        const list = filtered(root);
        const total = list.length;
        const pages = Math.max(1, Math.ceil(total / PERPAGE));
        if (state.page > pages - 1) {
            state.page = Math.max(0, pages - 1);
        }
        const slice = list.slice(state.page * PERPAGE, state.page * PERPAGE + PERPAGE);
        const found = root.querySelector('[data-region="found-count"]');
        if (found) {
            found.textContent = total + ' Missions Found';
        }
        renderCards(root, slice);
        renderPager(root, total, state.page, PERPAGE);
    };

    const init = function() {
        const root = document.querySelector('[data-region="nxs-catalog"]');
        if (!root) {
            return;
        }
        state.missions = readBootstrap().map(function(m, i) {
            m.userstatus = normalizeStatus(m.userstatus || m.status);
            m.number = i + 1;
            return m;
        });

        let timer = null;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(function() {
                state.page = 0;
                refresh(root);
            }, 140);
        };

        const search = root.querySelector('[data-filter="search"]');
        if (search) {
            search.addEventListener('input', schedule);
        }

        root.addEventListener('click', function(ev) {
            const trackBtn = ev.target.closest('[data-track]');
            if (trackBtn && root.contains(trackBtn) && trackBtn.closest('[data-region="track-pills"]')) {
                ev.preventDefault();
                root.querySelectorAll('[data-region="track-pills"] [data-track]').forEach(function(b) {
                    b.classList.toggle('is-active', b === trackBtn);
                });
                const trackInput = root.querySelector('[data-filter="track"]');
                if (trackInput) {
                    trackInput.value = trackBtn.getAttribute('data-track') || '';
                }
                state.page = 0;
                refresh(root);
                return;
            }
            const statusBtn = ev.target.closest('[data-status]');
            if (statusBtn && root.contains(statusBtn) && statusBtn.closest('[data-region="status-tabs"]')) {
                ev.preventDefault();
                root.querySelectorAll('[data-region="status-tabs"] [data-status]').forEach(function(b) {
                    b.classList.toggle('is-active', b === statusBtn);
                });
                const statusInput = root.querySelector('[data-filter="userstatus"]');
                if (statusInput) {
                    statusInput.value = statusBtn.getAttribute('data-status') || 'all';
                }
                state.page = 0;
                refresh(root);
                return;
            }
            const pageBtn = ev.target.closest('[data-page]');
            if (pageBtn && root.contains(pageBtn) && !pageBtn.disabled) {
                ev.preventDefault();
                const next = parseInt(pageBtn.getAttribute('data-page'), 10);
                if (!isNaN(next)) {
                    state.page = Math.max(0, next);
                    refresh(root);
                    root.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
        });

        document.addEventListener('keydown', function(ev) {
            if ((ev.metaKey || ev.ctrlKey) && String(ev.key).toLowerCase() === 'k') {
                if (!root.contains(document.activeElement) && search) {
                    ev.preventDefault();
                    search.focus();
                } else if (search && root.contains(document.activeElement)) {
                    ev.preventDefault();
                    search.focus();
                }
            }
        });

        refresh(root);
    };

    return {init: init};
});
