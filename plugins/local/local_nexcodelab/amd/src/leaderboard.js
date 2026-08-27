/**
 * NexCodeLab leaderboard.
 *
 * @module     local_nexcodelab/leaderboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const init = function() {
        const root = $('[data-region="ncl-leaderboard"]');
        if (!root.length) {
            return;
        }
        const element = root.get(0);
        const board = root.find('[data-region="board"]');
        const myRank = root.find('[data-region="my-rank"]');
        const institution = root.find('[data-filter="institution"]');
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
                myRank.html('<span class="ncl-my-rank__empty">' +
                    esc(filtered ? label('not-ranked') : label('not-ranked-global')) + '</span>');
                return;
            }
            myRank.html(
                '<span class="ncl-my-rank__label">' + esc(label('your-rank')) + '</span>' +
                '<strong class="ncl-my-rank__value">#' + esc(current.rank) + '</strong>' +
                '<span class="ncl-my-rank__meta">' + esc(current.xp) + ' ' + esc(label('xp')) +
                ' · ' + esc(current.solved) + ' ' + esc(label('solved')) + '</span>'
            );
        };

        const renderBoard = function(entries) {
            const rows = (entries || []).map(function(entry) {
                const current = entry.isme ? ' class="ncl-leaderboard-current" aria-current="true"' : '';
                const you = entry.isme ?
                    ' <span class="ncl-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                return '<tr' + current + '><td class="ncl-leaderboard-rank">#' + esc(entry.rank) +
                    '</td><td><span class="ncl-leaderboard-name">' + esc(entry.fullname) + '</span>' + you +
                    '</td><td>' + (entry.institution ? esc(entry.institution) : '<span aria-hidden="true">—</span>') +
                    '</td><td>' + esc(entry.xp) + '</td><td>' + esc(entry.solved) + '</td></tr>';
            }).join('');

            board.html(rows
                ? '<div class="ncl-table-wrap"><table class="ncl-table ncl-leaderboard-table"><thead><tr>' +
                    '<th>' + esc(label('rank')) + '</th><th>' + esc(label('user')) + '</th>' +
                    '<th>' + esc(label('institution')) + '</th><th>' + esc(label('xp')) + '</th>' +
                    '<th>' + esc(label('solved')) + '</th></tr></thead><tbody>' + rows +
                    '</tbody></table></div>'
                : '<p class="ncl-empty">' + esc(label('empty')) + '</p>');
        };

        const load = function() {
            const id = ++requestId;
            const selected = String(institution.val() || '');
            institution.prop('disabled', true);
            board.attr('aria-busy', 'true');

            Ajax.call([{
                methodname: 'local_nexcodelab_get_leaderboard',
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

    return {init};
});
