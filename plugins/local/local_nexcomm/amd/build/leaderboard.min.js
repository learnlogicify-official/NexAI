/**
 * NexComm leaderboard.
 *
 * @module local_nexcomm/leaderboard
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
        const root = $('[data-region="nc-leaderboard"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const board = root.find('[data-region="board"]');
        const myRank = root.find('[data-region="my-rank"]');
        const institution = root.find('[data-filter="institution"]');
        let requestId = 0;

        const label = function(name) {
            return el.getAttribute('data-label-' + name) || '';
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
                myRank.html('<span class="nc-my-rank__empty">' +
                    esc(filtered ? label('not-ranked') : label('not-ranked-global')) + '</span>');
                return;
            }
            myRank.html(
                '<span class="nc-my-rank__label">' + esc(label('your-rank')) + '</span>' +
                '<strong class="nc-my-rank__value">#' + esc(current.rank) + '</strong>' +
                '<span class="nc-my-rank__meta">' + esc(current.xp) + ' ' + esc(label('xp')) + '</span>'
            );
        };

        const renderBoard = function(entries) {
            const rows = (entries || []).map(function(entry) {
                const current = entry.isme ? ' class="nc-leaderboard-current" aria-current="true"' : '';
                const you = entry.isme ?
                    ' <span class="nc-leaderboard-you">' + esc(label('you')) + '</span>' : '';
                return '<tr' + current + '>' +
                    '<td class="nc-leaderboard-rank">#' + esc(entry.rank) + '</td>' +
                    '<td><span class="nc-leaderboard-name">' + esc(entry.fullname) + '</span>' + you + '</td>' +
                    '<td>' + (entry.institution ? esc(entry.institution) : '—') + '</td>' +
                    '<td>' + esc(entry.xp) + '</td>' +
                    '<td>' + esc(entry.speaking) + '</td>' +
                    '<td>' + esc(entry.writing) + '</td>' +
                    '<td>' + esc(entry.listening) + '</td>' +
                    '<td>' + esc(entry.reading) + '</td>' +
                    '</tr>';
            }).join('');

            board.html(rows
                ? '<div class="nc-table-wrap"><table class="nc-table"><thead><tr>' +
                    '<th>' + esc(label('rank')) + '</th>' +
                    '<th>' + esc(label('user')) + '</th>' +
                    '<th>' + esc(label('institution')) + '</th>' +
                    '<th>' + esc(label('xp')) + '</th>' +
                    '<th>' + esc(label('speaking')) + '</th>' +
                    '<th>' + esc(label('writing')) + '</th>' +
                    '<th>' + esc(label('listening')) + '</th>' +
                    '<th>' + esc(label('reading')) + '</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div>'
                : '<p class="nc-empty">' + esc(label('empty')) + '</p>');
        };

        const load = function() {
            const id = ++requestId;
            const selected = String(institution.val() || '');
            Ajax.call([{
                methodname: 'local_nexcomm_get_leaderboard',
                args: {limit: 50, institution: selected}
            }])[0].then(function(data) {
                if (id !== requestId) {
                    return null;
                }
                renderInstitutions(data.institutions, selected);
                renderCurrent(data.current, selected !== '');
                renderBoard(data.entries);
                return null;
            }).catch(Notification.exception);
        };

        institution.on('change', load);
        load();
    };

    return {init: init};
});
