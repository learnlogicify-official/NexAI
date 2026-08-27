/**
 * NexComm home — refresh target bars.
 *
 * @module local_nexcomm/home
 */
define(['jquery', 'core/ajax'], function($, Ajax) {
    const init = function() {
        const root = $('[data-region="nc-home"]');
        if (!root.length) {
            return;
        }
        Ajax.call([{
            methodname: 'local_nexcomm_get_targets',
            args: {}
        }])[0].then(function(t) {
            if (!t) {
                return null;
            }
            root.find('[data-region="daily-meta"]').text(t.dailyDone + ' / ' + t.dailyGoal);
            root.find('[data-region="weekly-meta"]').text(t.weeklyDone + ' / ' + t.weeklyGoal);
            root.find('[data-region="daily-fill"]').css('width', (t.dailyPct || 0) + '%');
            root.find('[data-region="weekly-fill"]').css('width', (t.weeklyPct || 0) + '%');
            root.find('.nc-targetcard').eq(0).toggleClass('is-complete', !!t.dailyComplete);
            root.find('.nc-targetcard').eq(1).toggleClass('is-complete', !!t.weeklyComplete);
            return null;
        }).catch(function() {
            // Keep server-rendered values.
        });
    };
    return {init: init};
});
