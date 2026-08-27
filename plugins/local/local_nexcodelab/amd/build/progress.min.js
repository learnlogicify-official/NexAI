/**
 * Mission progress page (NexPractice-style chrome).
 *
 * @module     local_nexcodelab/progress
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

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

    const trackLabel = (t) => {
        const map = {wrangling: 'Wrangling', eda: 'EDA', ml: 'ML', nlp: 'NLP'};
        return map[t] || t;
    };

    const init = function() {
        const root = $('[data-region="ncl-progress"]');
        if (!root.length) {
            return;
        }
        Ajax.call([{methodname: 'local_nexcodelab_get_progress', args: {}}])[0].then((data) => {
            root.find('[data-p="completed"]').text(String(data.completed || 0));
            root.find('[data-p="inprogress"]').text(String(data.inprogress || 0));
            root.find('[data-p="total"]').text(String(data.total || 0));
            root.find('[data-p="xp"]').text(String((data.stats && data.stats.xp) || 0));

            const html = (data.missions || []).map((m) => {
                const meta = statusMeta[m.userstatus] || statusMeta.notstarted;
                const steps = (m.passedsteps || 0) + ' / ' + (m.stepcount || 0) + ' steps';
                return '<article class="ncl-pcard ncl-pcard--' + esc(m.userstatus) + '">' +
                    '<div class="ncl-pcard__left">' +
                        '<div class="ncl-pcard__num">' + esc(m.number != null ? m.number : m.id) + '</div>' +
                        '<div class="ncl-trackbadge">' + esc(trackLabel(m.track)) + '</div>' +
                    '</div>' +
                    '<div class="ncl-pcard__body">' +
                        '<div class="ncl-pcard__topline">' +
                            '<h3 class="ncl-pcard__title"><a href="' + esc(m.url) + '">' + esc(m.name) + '</a></h3>' +
                            '<span class="ncl-statuspill ncl-statuspill--' + esc(m.userstatus) + '">' +
                                esc(meta.label) + '</span>' +
                        '</div>' +
                        '<div class="ncl-pcard__meta">' +
                            '<span>' + esc(steps) + '</span>' +
                            '<a class="ncl-action ' + meta.btn + '" href="' + esc(m.url) + '">' +
                                esc(meta.action) + '</a>' +
                        '</div>' +
                    '</div>' +
                    '</article>';
            }).join('');
            root.find('[data-region="progress-grid"]').html(
                html || '<p class="ncl-empty">No missions yet.</p>'
            );
            return null;
        }).catch(Notification.exception);
    };

    return {init};
});
