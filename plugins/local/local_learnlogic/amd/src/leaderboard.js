/**
 * NexPractice leaderboard.
 *
 * @module     local_learnlogic/leaderboard
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

    const n = (v) => Number(v) || 0;

    const diffCells = (entry) =>
        '<td class="ll-leaderboard-diff ll-leaderboard-diff--easy">' + esc(n(entry.solvedeasy)) + '</td>' +
        '<td class="ll-leaderboard-diff ll-leaderboard-diff--medium">' + esc(n(entry.solvedmedium)) + '</td>' +
        '<td class="ll-leaderboard-diff ll-leaderboard-diff--hard">' + esc(n(entry.solvedhard)) + '</td>' +
        '<td class="ll-leaderboard-diff ll-leaderboard-diff--veryhard">' + esc(n(entry.solvedveryhard)) + '</td>';

    const diffMeta = (current, labels) =>
        '<span class="ll-my-rank__diffs">' +
        '<span class="ll-my-rank__diff ll-my-rank__diff--easy">' + esc(labels.easy) + ' ' +
            esc(n(current.solvedeasy)) + '</span>' +
        '<span class="ll-my-rank__diff ll-my-rank__diff--medium">' + esc(labels.medium) + ' ' +
            esc(n(current.solvedmedium)) + '</span>' +
        '<span class="ll-my-rank__diff ll-my-rank__diff--hard">' + esc(labels.hard) + ' ' +
            esc(n(current.solvedhard)) + '</span>' +
        '<span class="ll-my-rank__diff ll-my-rank__diff--veryhard">' + esc(labels.veryhard) + ' ' +
            esc(n(current.solvedveryhard)) + '</span>' +
        '</span>';

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
        nums.forEach((pn) => {
            if (prev !== null && pn > prev + 1) {
                controls += '<span class="ll-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="ll-pager__btn' +
                (pn === page ? ' is-active' : '') + '" data-page="' + pn + '"' +
                (pn === page ? ' aria-current="page"' : '') + '>' + (pn + 1) + '</button>';
            prev = pn;
        });
        controls += '<button type="button" class="ll-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';

        pager.removeAttr('hidden').html(
            '<div class="ll-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="ll-pager__controls">' + controls + '</div>'
        );
    };

    const init = function() {
        const root = $('[data-region="ll-leaderboard"]');
        if (!root.length) {
            return;
        }
        const element = root.get(0);
        const board = root.find('[data-region="board"]');
        const myRank = root.find('[data-region="my-rank"]');
        const institution = root.find('[data-filter="institution"]');
        let requestId = 0;
        let state = {page: 0};

        const label = (name) => element.getAttribute('data-label-' + name) || '';
        const diffLabels = {
            easy: label('easy'),
            medium: label('medium'),
            hard: label('hard'),
            veryhard: label('veryhard'),
        };

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
                myRank.html('<span class="ll-my-rank__empty">' +
                    esc(filtered ? label('not-ranked') : label('not-ranked-global')) + '</span>');
                return;
            }
            myRank.html(
                '<span class="ll-my-rank__label">' + esc(label('your-rank')) + '</span>' +
                '<strong class="ll-my-rank__value">#' + esc(current.rank) + '</strong>' +
                '<span class="ll-my-rank__meta">' + esc(current.xp) + ' ' + esc(label('xp')) +
                ' · ' + esc(current.solved) + ' ' + esc(label('solved')) + '</span>' +
                diffMeta(current, diffLabels)
            );
        };

        const renderBoard = function(entries) {
            const rows = (entries || []).map(function(entry) {
                const current = entry.isme ? ' class="ll-leaderboard-current" aria-current="true"' : '';
                const you = entry.isme ?
                    ' <span class="ll-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                return '<tr' + current + '><td class="ll-leaderboard-rank">#' + esc(entry.rank) +
                    '</td><td><span class="ll-leaderboard-name">' + esc(entry.fullname) + '</span>' + you +
                    '</td><td>' + (entry.institution ? esc(entry.institution) : '<span aria-hidden="true">—</span>') +
                    '</td><td>' + esc(entry.xp) + '</td><td>' + esc(entry.solved) + '</td>' +
                    diffCells(entry) + '</tr>';
            }).join('');

            board.html(rows
                ? '<div class="ll-table-wrap"><table class="ll-table ll-leaderboard-table"><thead><tr>' +
                    '<th>' + esc(label('rank')) + '</th><th>' + esc(label('user')) + '</th>' +
                    '<th>' + esc(label('institution')) + '</th><th>' + esc(label('xp')) + '</th>' +
                    '<th>' + esc(label('solved')) + '</th>' +
                    '<th class="ll-leaderboard-diffhead ll-leaderboard-diffhead--easy">' +
                        esc(diffLabels.easy) + '</th>' +
                    '<th class="ll-leaderboard-diffhead ll-leaderboard-diffhead--medium">' +
                        esc(diffLabels.medium) + '</th>' +
                    '<th class="ll-leaderboard-diffhead ll-leaderboard-diffhead--hard">' +
                        esc(diffLabels.hard) + '</th>' +
                    '<th class="ll-leaderboard-diffhead ll-leaderboard-diffhead--veryhard">' +
                        esc(diffLabels.veryhard) + '</th>' +
                    '</tr></thead><tbody>' + rows +
                    '</tbody></table></div>'
                : '<p class="ll-empty">' + esc(label('empty')) + '</p>');
        };

        const load = function() {
            const id = ++requestId;
            const selected = String(institution.val() || '');
            institution.prop('disabled', true);
            board.attr('aria-busy', 'true');

            Ajax.call([{
                methodname: 'local_learnlogic_get_leaderboard',
                args: {
                    limit: PERPAGE,
                    institution: selected,
                    page: state.page,
                    perpage: PERPAGE
                }
            }])[0].then(function(data) {
                if (id !== requestId) {
                    return null;
                }
                const total = data.total || 0;
                const pages = Math.max(1, Math.ceil(total / PERPAGE));
                if (state.page > pages - 1) {
                    state.page = Math.max(0, pages - 1);
                    if (total > 0) {
                        load();
                        return null;
                    }
                }
                state.page = data.page != null ? data.page : state.page;
                renderInstitutions(data.institutions, selected);
                renderCurrent(data.current, selected !== '');
                renderBoard(data.entries);
                renderPager(root, total, state.page, PERPAGE);
                institution.prop('disabled', false);
                board.removeAttr('aria-busy');
                return null;
            }).catch(function(error) {
                if (id === requestId) {
                    institution.prop('disabled', false);
                    board.removeAttr('aria-busy');
                }
                Notification.exception(error);
            });
        };

        institution.on('change', function() {
            state.page = 0;
            load();
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
            load();
            const anchor = board[0];
            if (anchor && anchor.scrollIntoView) {
                anchor.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });

        load();
    };

    return {init};
});
