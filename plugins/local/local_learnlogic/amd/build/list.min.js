/**
 * NexPractice problem list.
 *
 * @module     local_learnlogic/list
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const PERPAGE = 20;

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const statusMeta = {
        completed: {label: 'Completed', action: 'Solve Again', btn: 'll-action--done'},
        inprogress: {label: 'In Progress', action: 'Continue', btn: 'll-action--progress'},
        battled: {label: 'Battled', action: 'Solve', btn: 'll-action--battle'},
        notstarted: {label: 'Not Started', action: 'Solve', btn: 'll-action--solve'}
    };

    const statusMetaFor = function(root, status) {
        const base = statusMeta[status] || statusMeta.notstarted;
        if (status === 'battled') {
            return {
                label: root.attr('data-label-battled') || base.label,
                action: root.attr('data-label-battled-action') || base.action,
                btn: base.btn,
            };
        }
        return base;
    };

    let state = {page: 0};
    const selectedTagIds = new Set();
    const selectedCompanyIds = new Set();

    const diffLabel = (d) => {
        const map = {easy: 'Easy', medium: 'Medium', hard: 'Hard', veryhard: 'Very Hard'};
        return map[d] || d;
    };

    const renderHeader = (root, st, counts) => {
        const total = (counts && counts.all != null) ? counts.all : (st.total || 0);
        const solved = st.solved || 0;
        const pct = total > 0 ? Math.round((solved / total) * 100) : 0;
        const rank = st.rank && st.xp > 0 ? String(st.rank) : 'N/A';

        const donut = root.find('[data-region="donut"]');
        donut.css('--ll-donut-pct', pct);
        root.find('[data-region="donut-value"]').text(pct + '%');

        root.find('[data-stat="solved"]').text(solved + ' / ' + total);
        root.find('[data-stat="streak"]').text(String(st.streak || 0));
        root.find('[data-stat="xp"]').text(String(st.xp || 0));
        root.find('[data-stat="rank"]').text(rank);

        if (counts) {
            root.find('[data-hstat="completed"]').text(String(counts.completed || 0));
            root.find('[data-hstat="inprogress"]').text(String(counts.inprogress || 0));
            root.find('[data-hstat="battled"]').text(String(counts.battled || 0));
            root.find('[data-hstat="notstarted"]').text(String(counts.notstarted || 0));
            root.find('[data-hstat="total"]').text(String(counts.all || 0));
        }
    };

    const renderTags = (root, tags, selectedIds) => {
        const popular = (tags || []).slice(0, 8);
        const rest = (tags || []).slice(8);
        const isActive = (id) => selectedIds.has(String(id));
        const chip = (t) =>
            '<button type="button" class="ll-tagchip' + (isActive(t.id) ? ' is-active' : '') +
            '" data-tagid="' + esc(t.id) + '">' + esc(t.name) +
            ' <span class="ll-tagchip__n">(' + esc(t.count || 0) + ')</span></button>';

        root.find('[data-region="popular-tags"]').html(
            popular.map((t) => chip(t)).join('')
        );
        root.find('[data-region="tag-cloud"]').html(
            rest.map((t) => chip(t)).join('') ||
            '<span class="ll-muted">No more tags</span>'
        );
        root.find('[data-action="clear-tag-filters"]').prop('hidden', selectedIds.size === 0);
    };

    const renderCompanies = (root, companies, selectedIds) => {
        const list = companies || [];
        const host = root.find('[data-region="popular-companies"]');
        if (!host.length) {
            return;
        }
        if (!list.length) {
            root.find('.ll-populartags--companies, [data-region="company-cloud"]').attr('hidden', true);
            return;
        }
        root.find('.ll-populartags--companies').removeAttr('hidden');
        const popular = list.slice(0, 8);
        const rest = list.slice(8);
        const isActive = (id) => selectedIds.has(String(id));
        const chip = (t) =>
            '<button type="button" class="ll-tagchip ll-tagchip--company' +
            (isActive(t.id) ? ' is-active' : '') +
            '" data-companyid="' + esc(t.id) + '">' + esc(t.name) +
            ' <span class="ll-tagchip__n">(' + esc(t.count || 0) + ')</span></button>';

        host.html(popular.map((t) => chip(t)).join(''));
        root.find('[data-region="company-cloud"]').html(
            rest.map((t) => chip(t)).join('') ||
            '<span class="ll-muted">No more companies</span>'
        );
        root.find('[data-action="clear-company-filters"]').prop('hidden', selectedIds.size === 0);
    };

    const renderCards = (root, problems) => {
        if (!problems || !problems.length) {
            root.find('[data-region="grid"]').html(
                '<p class="ll-empty">No problems match your filters.</p>'
            );
            return;
        }
        const html = problems.map((p) => {
            const meta = statusMetaFor(root, p.userstatus);
            const tags = (p.tags || []).slice(0, 4).map((t) =>
                '<span class="ll-ptag">' + esc(t.name) + '</span>'
            ).join('');
            const companies = (p.companies || []).slice(0, 3).map((t) =>
                '<span class="ll-ptag ll-ptag--company">' + esc(t.name) + '</span>'
            ).join('');
            const battledBadge = (p.battled && p.userstatus === 'completed')
                ? '<span class="ll-battlebadge" title="' + esc(root.attr('data-label-battled') || 'Battled') +
                    '">' + esc(root.attr('data-label-battled') || 'Battled') + '</span>'
                : '';
            return '<article class="ll-pcard ll-pcard--' + esc(p.userstatus) + '">' +
                '<div class="ll-pcard__left">' +
                    '<div class="ll-pcard__num">' + esc(p.number || p.id) + '</div>' +
                    '<div class="ll-diffbadge ll-diffbadge--' + esc(p.difficulty) + '">' +
                        esc(diffLabel(p.difficulty)) + '</div>' +
                '</div>' +
                '<div class="ll-pcard__body">' +
                    '<div class="ll-pcard__topline">' +
                        '<h3 class="ll-pcard__title"><a href="' + esc(p.url) + '">' + esc(p.name) + '</a></h3>' +
                        '<span class="ll-statuspill ll-statuspill--' + esc(p.userstatus) + '">' +
                            esc(meta.label) + '</span>' +
                        battledBadge +
                    '</div>' +
                    '<div class="ll-pcard__tags">' + tags + companies + '</div>' +
                    '<div class="ll-pcard__meta">' +
                        '<span><span class="ll-ico ll-ico--users" aria-hidden="true"></span> ' +
                            esc(p.solvers || 0) + '</span>' +
                        '<span><span class="ll-ico ll-ico--pct" aria-hidden="true"></span> ' +
                            esc(p.acceptance || 0) + '%</span>' +
                        '<span><span class="ll-ico ll-ico--clock" aria-hidden="true"></span> ' +
                            esc(p.estimateminutes || 15) + ' min</span>' +
                        '<a class="ll-action ' + meta.btn + '" href="' + esc(p.url) + '">' +
                            esc(meta.action) + '</a>' +
                    '</div>' +
                '</div>' +
                '</article>';
        }).join('');
        root.find('[data-region="grid"]').html(html);
    };

    const pageWindow = (current, pages) => {
        if (pages <= 7) {
            return Array.from({length: pages}, (_, i) => i);
        }
        const set = new Set([0, pages - 1, current]);
        for (let i = current - 1; i <= current + 1; i++) {
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
        let controls = '<button type="button" class="ll-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>Prev</button>';
        let prev = null;
        nums.forEach((n) => {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="ll-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="ll-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="ll-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';

        pager.removeAttr('hidden').html(
            '<div class="ll-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="ll-pager__controls">' + controls + '</div>'
        );
    };

    const load = (root) => {
        const search = root.find('[data-filter="search"]').val() || '';
        const difficulty = root.find('[data-filter="difficulty"]').val() || '';
        const userstatus = root.find('[data-filter="userstatus"]').val() || 'all';
        const tagids = [...selectedTagIds].map((id) => parseInt(id, 10)).filter((id) => id > 0);
        const companyids = [...selectedCompanyIds].map((id) => parseInt(id, 10)).filter((id) => id > 0);

        Ajax.call([{
            methodname: 'local_learnlogic_get_problems',
            args: {
                search: search,
                difficulty: difficulty,
                userstatus: userstatus,
                tagid: 0,
                tagids: tagids,
                companyids: companyids,
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
            renderHeader(root, data.stats || {}, data.counts || {});
            const c = data.counts || {};
            root.find('[data-count="all"]').text('(' + (c.all || 0) + ')');
            root.find('[data-count="completed"]').text('(' + (c.completed || 0) + ')');
            root.find('[data-count="inprogress"]').text('(' + (c.inprogress || 0) + ')');
            root.find('[data-count="battled"]').text('(' + (c.battled || 0) + ')');
            root.find('[data-count="notstarted"]').text('(' + (c.notstarted || 0) + ')');
            root.find('[data-region="found-count"]').text(
                (data.total || 0) + ' Problems Found'
            );
            renderTags(root, data.tags || [], selectedTagIds);
            renderCompanies(root, data.companies || [], selectedCompanyIds);
            renderCards(root, data.problems || []);
            renderPager(root, total, state.page, PERPAGE);
            return null;
        }).catch(Notification.exception);
    };

    const resetAndLoad = (root) => {
        state.page = 0;
        load(root);
    };

    const init = function() {
        const root = $('[data-region="ll-list"]');
        if (!root.length) {
            return;
        }

        let timer = null;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(() => resetAndLoad(root), 180);
        };

        root.on('input', '[data-filter="search"]', schedule);

        root.on('click', '[data-diff]', function(e) {
            e.preventDefault();
            const diff = $(this).attr('data-diff') || '';
            root.find('[data-diff]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="difficulty"]').val(diff);
            resetAndLoad(root);
        });

        root.on('click', '[data-status]', function(e) {
            e.preventDefault();
            const status = $(this).attr('data-status') || 'all';
            root.find('[data-status]').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="userstatus"]').val(status);
            resetAndLoad(root);
        });

        root.on('click', '[data-tagid]', function(e) {
            e.preventDefault();
            const id = String($(this).attr('data-tagid') || '');
            if (!id) {
                return;
            }
            if (selectedTagIds.has(id)) {
                selectedTagIds.delete(id);
            } else {
                selectedTagIds.add(id);
            }
            resetAndLoad(root);
        });

        root.on('click', '[data-companyid]', function(e) {
            e.preventDefault();
            const id = String($(this).attr('data-companyid') || '');
            if (!id) {
                return;
            }
            if (selectedCompanyIds.has(id)) {
                selectedCompanyIds.delete(id);
            } else {
                selectedCompanyIds.add(id);
            }
            resetAndLoad(root);
        });

        root.on('click', '[data-action="clear-tag-filters"]', function(e) {
            e.preventDefault();
            selectedTagIds.clear();
            resetAndLoad(root);
        });

        root.on('click', '[data-action="clear-company-filters"]', function(e) {
            e.preventDefault();
            selectedCompanyIds.clear();
            resetAndLoad(root);
        });

        root.on('click', '[data-action="toggle-tags"]', function(e) {
            e.preventDefault();
            const cloud = root.find('[data-region="tag-cloud"]');
            const open = cloud.attr('hidden') !== undefined;
            if (open) {
                cloud.removeAttr('hidden');
                $(this).text('Hide Tags');
            } else {
                cloud.attr('hidden', true);
                $(this).text('Show All Tags');
            }
        });

        root.on('click', '[data-action="toggle-companies"]', function(e) {
            e.preventDefault();
            const cloud = root.find('[data-region="company-cloud"]');
            const open = cloud.attr('hidden') !== undefined;
            if (open) {
                cloud.removeAttr('hidden');
                $(this).text('Hide Companies');
            } else {
                cloud.attr('hidden', true);
                $(this).text('Show All Companies');
            }
        });

        root.on('click', '[data-region="pager"] [data-page]', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled') || $(this).hasClass('is-active')) {
                return;
            }
            const next = parseInt($(this).attr('data-page'), 10);
            if (isNaN(next) || next < 0) {
                return;
            }
            state.page = next;
            load(root);
            const anchor = root.find('[data-region="grid"]')[0];
            if (anchor && anchor.scrollIntoView) {
                anchor.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });

        $(document).on('keydown.llsearch', function(e) {
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
