/**
 * Overall student leaderboard.
 *
 * @module     local_nexdashboard/leaderboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const PERPAGE = 25;

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const init = function() {
        const root = $('[data-region="nxd-leaderboard"]');
        if (!root.length) {
            return;
        }
        const element = root.get(0);
        const board = root.find('[data-region="board"]');
        const podium = root.find('[data-region="podium"]');
        const myRank = root.find('[data-region="my-rank"]');
        const pager = root.find('[data-region="pager"]');
        const institution = root.find('[data-filter="institution"]');
        let page = 1;
        let requestId = 0;

        const label = (name) => element.getAttribute('data-label-' + name) || '';

        const renderInstitutions = function(values, selected) {
            const options = ['<option value="">' + esc(institution.find('option:first').text()) + '</option>'];
            (values || []).forEach(function(value) {
                options.push('<option value="' + esc(value) + '"' +
                    (value === selected ? ' selected' : '') + '>' + esc(value) + '</option>');
            });
            institution.html(options.join(''));
        };

        const renderCurrent = function(current, filtered) {
            if (!current || !current.rank) {
                myRank.html('<span class="nxd-my-rank__empty">' +
                    esc(filtered ? label('not-ranked') : label('not-ranked-global')) + '</span>');
                return;
            }
            myRank.html(
                '<span class="nxd-my-rank__label">' + esc(label('your-rank')) + '</span>' +
                '<strong class="nxd-my-rank__value">#' + esc(current.rank) + '</strong>' +
                '<span class="nxd-my-rank__meta">' +
                    esc(current.coursegrade) + ' ' + esc(label('course')) +
                    ' · ' + esc(current.practicexp) + ' ' + esc(label('practice')) +
                    ' · ' + esc(current.codelabxp) + ' ' + esc(label('codelab')) +
                    ' · ' + esc(current.battlexp) + ' ' + esc(label('battle')) +
                    ' · ' + esc(current.total) + ' ' + esc(label('total')) +
                '</span>'
            );
        };

        const num = (value) => esc(value == null ? 0 : value);

        const initials = (name) => String(name || '')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0))
            .join('')
            .toUpperCase() || '?';

        const avatar = (entry, sizeClass) => {
            if (entry.picture) {
                return '<img class="nxd-avatar ' + sizeClass + '" src="' + esc(entry.picture) +
                    '" alt="" width="72" height="72">';
            }
            return '<span class="nxd-avatar nxd-avatar--fallback ' + sizeClass + '" aria-hidden="true">' +
                esc(initials(entry.fullname)) + '</span>';
        };

        const medalMeta = (rank) => {
            if (rank === 1) {
                return {cls: 'nxd-medal--gold', name: label('gold') || 'Gold'};
            }
            if (rank === 2) {
                return {cls: 'nxd-medal--silver', name: label('silver') || 'Silver'};
            }
            if (rank === 3) {
                return {cls: 'nxd-medal--bronze', name: label('bronze') || 'Bronze'};
            }
            return null;
        };

        const medalBadge = (rank) => {
            const meta = medalMeta(rank);
            if (!meta) {
                return '<span class="nxd-leaderboard-rank">#' + esc(rank) + '</span>';
            }
            return '<span class="nxd-medal ' + meta.cls + '" title="' + esc(meta.name) + '">' +
                esc(rank) + '</span>';
        };

        const renderPodium = function(entries) {
            const top = (entries || []).slice(0, 3);
            if (!top.length) {
                podium.attr('hidden', true).empty();
                return;
            }
            const order = top.length === 1 ? [top[0]]
                : top.length === 2 ? [top[1], top[0]]
                : [top[1], top[0], top[2]];
            const cards = order.map(function(entry) {
                const rank = entry.rank;
                const meta = medalMeta(rank) || {cls: '', name: ''};
                const you = entry.isme ?
                    '<span class="nxd-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                const college = entry.institution ? esc(entry.institution) : '—';
                return '<article class="nxd-podium__card nxd-podium__card--' + esc(rank) +
                    (entry.isme ? ' is-me' : '') + '">' +
                    '<span class="nxd-medal ' + meta.cls + ' nxd-medal--lg" title="' + esc(meta.name) + '">' +
                        esc(rank) + '</span>' +
                    avatar(entry, 'nxd-avatar--podium') +
                    '<h3 class="nxd-podium__name">' + esc(entry.fullname) + you + '</h3>' +
                    '<p class="nxd-podium__college">' + college + '</p>' +
                    '<p class="nxd-podium__total"><strong>' + num(entry.total) + '</strong> ' +
                        esc(label('total')) + '</p>' +
                    '<ul class="nxd-podium__split">' +
                        '<li><span>' + esc(label('course')) + '</span><strong>' + num(entry.coursegrade) + '</strong></li>' +
                        '<li><span>' + esc(label('practice')) + '</span><strong>' + num(entry.practicexp) + '</strong></li>' +
                        '<li><span>' + esc(label('codelab')) + '</span><strong>' + num(entry.codelabxp) + '</strong></li>' +
                        '<li><span>' + esc(label('battle')) + '</span><strong>' + num(entry.battlexp) + '</strong></li>' +
                    '</ul>' +
                    '<div class="nxd-podium__stand" aria-hidden="true"></div>' +
                    '</article>';
            }).join('');

            podium.removeAttr('hidden').html(
                '<div class="nxd-podium__head">' +
                    '<h2 class="nxd-podium__title">' + esc(label('podium') || 'Top 3 across all colleges') + '</h2>' +
                    '<p class="nxd-podium__hint">' + esc(label('podium-hint') || '') + '</p>' +
                '</div>' +
                '<div class="nxd-podium nxd-podium--' + top.length + '">' + cards + '</div>'
            );
        };

        const renderBoard = function(entries) {
            const rows = (entries || []).map(function(entry) {
                const medal = medalMeta(entry.rank);
                const current = entry.isme ? ' nxd-leaderboard-current' : '';
                const top = medal ? ' nxd-leaderboard-top nxd-leaderboard-top--' + entry.rank : '';
                const you = entry.isme ?
                    ' <span class="nxd-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                return '<tr class="' + (current + top).trim() + '"' +
                    (entry.isme ? ' aria-current="true"' : '') + '>' +
                    '<td>' + medalBadge(entry.rank) + '</td>' +
                    '<td><span class="nxd-leaderboard-who">' + avatar(entry, 'nxd-avatar--row') +
                    '<span class="nxd-leaderboard-name">' + esc(entry.fullname) + '</span>' + you +
                    '</span></td>' +
                    '<td>' + (entry.institution ? esc(entry.institution) : '<span aria-hidden="true">—</span>') + '</td>' +
                    '<td class="nxd-leaderboard-num">' + num(entry.coursegrade) + '</td>' +
                    '<td class="nxd-leaderboard-num">' + num(entry.practicexp) + '</td>' +
                    '<td class="nxd-leaderboard-num">' + num(entry.codelabxp) + '</td>' +
                    '<td class="nxd-leaderboard-num">' + num(entry.battlexp) + '</td>' +
                    '<td class="nxd-leaderboard-num nxd-leaderboard-total">' + num(entry.total) + '</td>' +
                    '</tr>';
            }).join('');

            board.html(rows
                ? '<div class="nxd-table-wrap"><table class="nxd-table nxd-leaderboard-table"><thead><tr>' +
                    '<th>' + esc(label('rank')) + '</th>' +
                    '<th>' + esc(label('user')) + '</th>' +
                    '<th>' + esc(label('institution')) + '</th>' +
                    '<th class="nxd-leaderboard-num">' + esc(label('course')) + '</th>' +
                    '<th class="nxd-leaderboard-num">' + esc(label('practice')) + '</th>' +
                    '<th class="nxd-leaderboard-num">' + esc(label('codelab')) + '</th>' +
                    '<th class="nxd-leaderboard-num">' + esc(label('battle')) + '</th>' +
                    '<th class="nxd-leaderboard-num">' + esc(label('total')) + '</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div>'
                : '<p class="nxd-empty">' + esc(label('empty')) + '</p>');
        };

        const pageWindow = function(current, pages) {
            if (pages <= 7) {
                return Array.from({length: pages}, (_, i) => i + 1);
            }
            const set = new Set([1, pages, current]);
            for (let i = current - 1; i <= current + 1; i++) {
                if (i >= 1 && i <= pages) {
                    set.add(i);
                }
            }
            return Array.from(set).sort((a, b) => a - b);
        };

        const renderPager = function(totalCount, currentPage, perpage) {
            const pages = Math.max(1, Math.ceil((totalCount || 0) / perpage));
            if (!totalCount || pages <= 1) {
                pager.attr('hidden', true).empty();
                return;
            }
            const from = (currentPage - 1) * perpage + 1;
            const to = Math.min(totalCount, currentPage * perpage);
            const showing = (label('showing') || 'Showing {from}–{to} of {total}')
                .replace('{from}', from)
                .replace('{to}', to)
                .replace('{total}', totalCount);
            const nums = pageWindow(currentPage, pages);
            let controls = '<button type="button" class="nxd-pager__btn" data-page="' +
                (currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>' +
                esc(label('prev') || 'Prev') + '</button>';
            let prev = null;
            nums.forEach(function(n) {
                if (prev !== null && n > prev + 1) {
                    controls += '<span class="nxd-pager__ellipsis" aria-hidden="true">…</span>';
                }
                controls += '<button type="button" class="nxd-pager__btn' +
                    (n === currentPage ? ' is-active' : '') + '" data-page="' + n + '"' +
                    (n === currentPage ? ' aria-current="page"' : '') + '>' + n + '</button>';
                prev = n;
            });
            controls += '<button type="button" class="nxd-pager__btn" data-page="' +
                (currentPage + 1) + '" ' + (currentPage >= pages ? 'disabled' : '') + '>' +
                esc(label('next') || 'Next') + '</button>';

            pager.removeAttr('hidden').html(
                '<div class="nxd-pager__meta">' + esc(showing) + '</div>' +
                '<div class="nxd-pager__controls">' + controls + '</div>'
            );
        };

        const load = function() {
            const id = ++requestId;
            const selected = String(institution.val() || '');
            institution.prop('disabled', true);
            board.attr('aria-busy', 'true');
            pager.find('button').prop('disabled', true);

            Ajax.call([{
                methodname: 'local_nexdashboard_get_overall_leaderboard',
                args: {page: page, perpage: PERPAGE, institution: selected}
            }])[0].then(function(data) {
                if (id !== requestId) {
                    return null;
                }
                const totalCount = data.total || 0;
                const pages = Math.max(1, Math.ceil(totalCount / PERPAGE));
                const nextPage = Math.max(1, Math.min(data.page || page, pages));
                if (totalCount > 0 && nextPage !== page) {
                    page = nextPage;
                    load();
                    return null;
                }
                page = nextPage;
                renderInstitutions(data.institutions, selected);
                renderCurrent(data.current, selected !== '');
                renderPodium(data.top3);
                renderBoard(data.entries);
                renderPager(totalCount, page, data.perpage || PERPAGE);
                institution.prop('disabled', false);
                board.removeAttr('aria-busy');
                return null;
            }).catch(function(error) {
                if (id === requestId) {
                    institution.prop('disabled', false);
                    board.removeAttr('aria-busy');
                    pager.find('button').prop('disabled', false);
                }
                Notification.exception(error);
            });
        };

        institution.on('change', function() {
            page = 1;
            load();
        });
        pager.on('click', '[data-page]', function(event) {
            const next = parseInt(event.currentTarget.getAttribute('data-page'), 10);
            if (!next || next === page || event.currentTarget.disabled) {
                return;
            }
            page = next;
            load();
        });
        load();
    };

    return {init};
});
