/**
 * NexPractice submissions list.
 *
 * @module     local_learnlogic/submissions
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
        const root = $('[data-region="ll-submissions"]');
        if (!root.length) {
            return;
        }
        Ajax.call([{
            methodname: 'local_learnlogic_get_submissions',
            args: {problemid: 0, page: 0, perpage: 50}
        }])[0].then((data) => {
            const rows = (data.submissions || []).map((s) =>
                '<tr><td>' + esc(s.timestr) + '</td>' +
                '<td><a href="/local/learnlogic/problem.php?id=' + esc(s.problemid) + '">' +
                esc(s.problemname) + '</a></td>' +
                '<td>' + esc(s.status) + '</td><td>' + esc(s.passed) + '/' + esc(s.total) +
                '</td><td>' + esc(s.language) + '</td></tr>'
            ).join('');
            root.find('[data-region="subs"]').html(
                rows
                    ? '<table class="ll-table"><thead><tr><th>When</th><th>Problem</th><th>Status</th><th>Tests</th><th>Lang</th></tr></thead><tbody>' +
                        rows + '</tbody></table>'
                    : '<p class="ll-empty">No submissions yet.</p>'
            );
            return null;
        }).catch(Notification.exception);
    };

    return {init};
});
