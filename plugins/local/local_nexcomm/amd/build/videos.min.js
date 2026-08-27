/**
 * NexComm Video Lab catalog (English Central–style).
 *
 * @module local_nexcomm/videos
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
        const root = $('[data-region="nc-videos"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const label = function(n) {
            return el.getAttribute('data-label-' + n) || n;
        };

        const renderGoals = function(g) {
            if (!g) {
                return;
            }
            root.find('[data-region="watch-meta"]').text(g.watchDone + ' / ' + g.watchGoal);
            root.find('[data-region="learn-meta"]').text(g.learnDone + ' / ' + g.learnGoal);
            root.find('[data-region="speak-meta"]').text(g.speakDone + ' / ' + g.speakGoal);
            root.find('[data-region="watch-fill"]').css('width', (g.watchPct || 0) + '%');
            root.find('[data-region="learn-fill"]').css('width', (g.learnPct || 0) + '%');
            root.find('[data-region="speak-fill"]').css('width', (g.speakPct || 0) + '%');
        };

        const render = function(items) {
            const grid = root.find('[data-region="grid"]');
            const empty = root.find('[data-region="empty"]');
            if (!items || !items.length) {
                grid.empty();
                empty.removeAttr('hidden');
                return;
            }
            empty.attr('hidden', true);
            grid.html(items.map(function(item) {
                const action = item.complete ? label('complete')
                    : (item.watched || item.wordsLearned || item.linesSpoken ? label('continue') : label('start'));
                return '<article class="nc-card nc-vcard' + (item.complete ? ' is-complete' : '') + '">' +
                    '<div class="nc-card__meta">' +
                    '<span class="nc-badge nc-badge--' + esc(item.difficulty) + '">' + esc(item.difficulty) + '</span>' +
                    '<span class="nc-badge">' + esc(item.topic || 'lesson') + '</span>' +
                    '</div>' +
                    '<h3 class="nc-card__title"><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h3>' +
                    '<p class="nc-muted">' + esc(item.summary || '') + '</p>' +
                    '<div class="nc-vcard__goals">' +
                    '<span>' + esc(label('watch')) + ': ' + (item.watched ? '✓' : '○') + '</span>' +
                    '<span>' + esc(label('learn')) + ': ' + esc(item.wordsLearned) + '/' + esc(item.wordCount) + '</span>' +
                    '<span>' + esc(label('speak')) + ': ' + esc(item.linesSpoken) + '/' + esc(item.lineCount) + '</span>' +
                    '</div>' +
                    '<div class="nc-card__foot"><a class="nc-btn nc-btn--primary" href="' + esc(item.url) + '">' +
                    esc(action) + '</a></div></article>';
            }).join(''));
        };

        Ajax.call([{methodname: 'local_nexcomm_get_lessons', args: {}}])[0]
            .then(function(data) {
                renderGoals(data.goals);
                render(data.items || []);
                return null;
            }).catch(Notification.exception);
    };

    return {init: init};
});
