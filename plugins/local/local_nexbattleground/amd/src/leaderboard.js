/**
 * NexBattleGround leaderboard.
 *
 * @module local_nexbattleground/leaderboard
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const init = function() {
        const root = $('[data-region="nbg-leaderboard"]');
        if (!root.length) {
            return;
        }
        const element = root.get(0);
        const board = root.find('[data-region="board"]');
        const myRank = root.find('[data-region="my-rank"]');
        const institution = root.find('[data-filter="institution"]');
        let requestId = 0;

        const label = function(name) {
            return element.getAttribute('data-label-' + name) || '';
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
                myRank.html('<span class="nbg-my-rank__empty">' +
                    esc(filtered ? label('not-ranked') : label('not-ranked-global')) + '</span>');
                return;
            }
            myRank.html(
                '<span class="nbg-my-rank__label">' + esc(label('your-rank')) + '</span>' +
                '<strong class="nbg-my-rank__value">#' + esc(current.rank) + '</strong>' +
                '<span class="nbg-my-rank__meta">' +
                esc(current.wins) + ' ' + esc(label('wins')) +
                ' · E ' + esc(current.easywins || 0) +
                ' · M ' + esc(current.mediumwins || 0) +
                ' · H ' + esc(current.hardwins || 0) +
                ' · ' + esc(current.winrate) + '% ' + esc(label('winrate')) +
                '</span>'
            );
        };

        const renderBoard = function(entries) {
            const rows = (entries || []).map(function(entry) {
                const current = entry.isme ? ' class="nbg-leaderboard-current" aria-current="true"' : '';
                const you = entry.isme ?
                    ' <span class="nbg-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                return '<tr' + current + '>' +
                    '<td class="nbg-leaderboard-rank">#' + esc(entry.rank) + '</td>' +
                    '<td><span class="nbg-leaderboard-name">' + esc(entry.fullname) + '</span>' + you + '</td>' +
                    '<td>' + (entry.institution ? esc(entry.institution) : '<span aria-hidden="true">—</span>') + '</td>' +
                    '<td>' + esc(entry.wins) + '</td>' +
                    '<td class="nbg-leaderboard-diff nbg-leaderboard-diff--easy">' + esc(entry.easywins || 0) + '</td>' +
                    '<td class="nbg-leaderboard-diff nbg-leaderboard-diff--medium">' + esc(entry.mediumwins || 0) + '</td>' +
                    '<td class="nbg-leaderboard-diff nbg-leaderboard-diff--hard">' + esc(entry.hardwins || 0) + '</td>' +
                    '<td>' + esc(entry.losses) + '</td>' +
                    '<td>' + esc(entry.winrate) + '%</td>' +
                    '<td>' + esc(entry.battles) + '</td>' +
                    '<td>' + esc(entry.battlexp) + '</td>' +
                    '</tr>';
            }).join('');

            board.html(rows
                ? '<div class="nbg-table-wrap"><table class="nbg-table nbg-leaderboard-table"><thead><tr>' +
                    '<th>' + esc(label('rank')) + '</th>' +
                    '<th>' + esc(label('user')) + '</th>' +
                    '<th>' + esc(label('institution')) + '</th>' +
                    '<th>' + esc(label('wins')) + '</th>' +
                    '<th class="nbg-leaderboard-diff nbg-leaderboard-diff--easy">' + esc(label('easy')) + '</th>' +
                    '<th class="nbg-leaderboard-diff nbg-leaderboard-diff--medium">' + esc(label('medium')) + '</th>' +
                    '<th class="nbg-leaderboard-diff nbg-leaderboard-diff--hard">' + esc(label('hard')) + '</th>' +
                    '<th>' + esc(label('losses')) + '</th>' +
                    '<th>' + esc(label('winrate')) + '</th>' +
                    '<th>' + esc(label('battles')) + '</th>' +
                    '<th>' + esc(label('battlexp')) + '</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div>'
                : '<p class="nbg-empty">' + esc(label('empty')) + '</p>');
        };

        const load = function() {
            const id = ++requestId;
            const selected = String(institution.val() || '');
            institution.prop('disabled', true);
            board.attr('aria-busy', 'true');

            Ajax.call([{
                methodname: 'local_nexbattleground_get_leaderboard',
                args: {limit: 50, institution: selected}
            }])[0].then(function(data) {
                if (id !== requestId) {
                    return null;
                }
                renderInstitutions(data.institutions, selected);
                renderCurrent(data.current, selected !== '');
                renderBoard(data.entries);
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

        institution.on('change', load);
        load();
    };

    return {init: init};
});
