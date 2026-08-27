/**
 * Mission catalog (NexPractice-style list chrome) with pagination.
 *
 * @module     local_nexcodelab/catalog
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const PERPAGE = 12;

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const statusMeta = {
        completed: {label: 'Completed', action: 'Open again', btn: 'ncl-action--done'},
        inprogress: {label: 'In Progress', action: 'Continue', btn: 'ncl-action--progress'},
        notstarted: {label: 'Not Started', action: 'Start', btn: 'ncl-action--solve'}
    };

    let state = {page: 0};

    const trackLabel = (t) => {
        const map = {wrangling: 'Wrangling', eda: 'EDA', ml: 'ML', nlp: 'NLP'};
        return map[t] || t;
    };

    const renderHeader = (root, st, counts) => {
        const total = (counts && counts.all != null) ? counts.all : (st.total || 0);
        const solved = st.solved || 0;
        const pct = total > 0 ? Math.round((solved / total) * 100) : 0;
        const rank = st.rank && st.xp > 0 ? String(st.rank) : 'N/A';

        const donut = root.find('[data-region="donut"]');
        donut.css('--ncl-donut-pct', pct);
        root.find('[data-region="donut-value"]').text(pct + '%');

        root.find('[data-stat="solved"]').text(solved + ' / ' + total);
        root.find('[data-stat="streak"]').text(String(st.streak || 0));
        root.find('[data-stat="xp"]').text(String(st.xp || 0));
        root.find('[data-stat="rank"]').text(rank);

        if (counts) {
            root.find('[data-hstat="completed"]').text(String(counts.completed || 0));
            root.find('[data-hstat="inprogress"]').text(String(counts.inprogress || 0));
            root.find('[data-hstat="notstarted"]').text(String(counts.notstarted || 0));
            root.find('[data-hstat="total"]').text(String(counts.all || 0));
        }
    };

    const renderCards = (root, missions) => {
        if (!missions || !missions.length) {
            root.find('[data-region="grid"]').html(
                '<p class="ncl-empty">No missions match your filters.</p>'
            );
            return;
        }
        const html = missions.map((m) => {
            const meta = statusMeta[m.userstatus] || statusMeta.notstarted;
            const steps = (m.passedsteps || 0) + ' / ' + (m.stepcount || 0) + ' steps';
            const num = m.number != null ? m.number : m.id;
            return '<article class="ncl-pcard ncl-pcard--' + esc(m.userstatus) + '">' +
                '<div class="ncl-pcard__left">' +
                    '<div class="ncl-pcard__num">' + esc(num) + '</div>' +
                    '<div class="ncl-trackbadge">' + esc(trackLabel(m.track)) + '</div>' +
                '</div>' +
                '<div class="ncl-pcard__body">' +
                    '<div class="ncl-pcard__topline">' +
                        '<h3 class="ncl-pcard__title"><a href="' + esc(m.url) + '">' + esc(m.name) + '</a></h3>' +
                        '<span class="ncl-statuspill ncl-statuspill--' + esc(m.userstatus) + '">' +
                            esc(meta.label) + '</span>' +
                    '</div>' +
                    '<p class="ncl-pcard__scenario">' + esc(m.scenario || '') + '</p>' +
                    '<div class="ncl-pcard__meta">' +
                        '<span><span class="ncl-ico ncl-ico--clock" aria-hidden="true"></span> ' +
                            esc(m.estimateminutes || 30) + ' min</span>' +
                        '<span>' + esc(steps) + '</span>' +
                        '<a class="ncl-action ' + meta.btn + '" href="' + esc(m.url) + '">' +
                            esc(meta.action) + '</a>' +
                    '</div>' +
                '</div>' +
                '</article>';
        }).join('');
        root.find('[data-region="grid"]').html(html);
    };

    const pageWindow = (page, pages) => {
        const set = new Set([0, pages - 1, page]);
        for (let i = page - 2; i <= page + 2; i++) {
            if (i >= 0 && i < pages) {
                set.add(i);
            }
        }
        return Array.from(set).sort((a, b) => a - b);
    };

    const renderPager = (root, total, page, perpage) => {
        const pager = root.find('[data-region="pager"]');
        const pages = Math.max(1, Math.ceil((total || 0) / perpage));
        if (!total || pages <= 1) {
            pager.attr('hidden', true).empty();
            return;
        }
        const from = page * perpage + 1;
        const to = Math.min(total, (page + 1) * perpage);
        const nums = pageWindow(page, pages);
        let controls = '<button type="button" class="ncl-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>Prev</button>';
        let prev = null;
        nums.forEach((n) => {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="ncl-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="ncl-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="ncl-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';

        pager.removeAttr('hidden').html(
            '<div class="ncl-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="ncl-pager__controls">' + controls + '</div>'
        );
    };

    const load = (root) => {
        const search = root.find('[data-filter="search"]').val() || '';
        const track = root.find('[data-filter="track"]').val() || '';
        const userstatus = root.find('[data-filter="userstatus"]').val() || 'all';

        Ajax.call([{
            methodname: 'local_nexcodelab_get_missions',
            args: {
                search: search,
                track: track,
                userstatus: userstatus,
                page: state.page,
                perpage: PERPAGE
            }
        }])[0].then((data) => {
            const total = data.total || 0;
            const pages = Math.max(1, Math.ceil(total / PERPAGE));
            if (state.page > pages - 1) {
                state.page = Math.max(0, pages - 1);
                if (total > 0) {
                    load(root);
                    return null;
                }
            }
            const counts = data.counts || {};
            renderHeader(root, data.stats || {}, counts);
            root.find('[data-count="all"]').text('(' + (counts.all || 0) + ')');
            root.find('[data-count="completed"]').text('(' + (counts.completed || 0) + ')');
            root.find('[data-count="inprogress"]').text('(' + (counts.inprogress || 0) + ')');
            root.find('[data-count="notstarted"]').text('(' + (counts.notstarted || 0) + ')');
            root.find('[data-region="found-count"]').text(
                (data.total || 0) + ' Missions Found'
            );
            renderCards(root, data.missions || []);
            renderPager(root, total, state.page, PERPAGE);
            return null;
        }).catch(Notification.exception);
    };

    const resetPageAndLoad = (root) => {
        state.page = 0;
        load(root);
    };

    const init = function() {
        const root = $('[data-region="ncl-catalog"]');
        if (!root.length) {
            return;
        }

        let timer = null;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(() => resetPageAndLoad(root), 160);
        };

        root.on('input', '[data-filter="search"]', schedule);

        root.on('click', '[data-track]', function(e) {
            e.preventDefault();
            root.find('[data-region="track-pills"] [data-track]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="track"]').val($(this).attr('data-track') || '');
            resetPageAndLoad(root);
        });

        root.on('click', '[data-region="status-tabs"] [data-status]', function(e) {
            e.preventDefault();
            root.find('[data-region="status-tabs"] [data-status]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="userstatus"]').val($(this).attr('data-status') || 'all');
            resetPageAndLoad(root);
        });

        root.on('click', '[data-region="pager"] [data-page]', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled')) {
                return;
            }
            const next = parseInt($(this).attr('data-page'), 10);
            if (isNaN(next) || next < 0) {
                return;
            }
            state.page = next;
            load(root);
            const anchor = root.find('[data-region="found-count"]')[0];
            if (anchor && typeof anchor.scrollIntoView === 'function') {
                anchor.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });

        $(document).on('keydown.nclsearch', function(e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                const input = root.find('[data-filter="search"]');
                if (input.length) {
                    e.preventDefault();
                    input.trigger('focus');
                }
            }
        });

        load(root);
    };

    return {init};
});
