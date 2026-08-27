/**
 * NexReports dwell-time tracker.
 *
 * Counts seconds while the tab is visible and posts them to the server
 * periodically. Paused whenever the tab is hidden; pending seconds are flushed
 * when the tab is hidden or the page unloads.
 *
 * @module     local_nexreports/tracker
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/config'], function(Ajax, Config) {

    let trackId = null;
    let frequency = 60;
    let seconds = 0;
    let ticker = null;

    /**
     * Flush via fetch+keepalive / sendBeacon so unload and tab-hide still deliver.
     *
     * @param {Number} payload Seconds to add
     */
    const flushBeacon = function(payload) {
        if (!trackId || payload <= 0) {
            return;
        }
        const url = Config.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(Config.sesskey);
        const body = JSON.stringify([{
            index: 0,
            methodname: 'local_nexreports_track_ping',
            args: {id: trackId, time: payload},
        }]);
        try {
            if (navigator.sendBeacon) {
                const blob = new Blob([body], {type: 'application/json'});
                if (navigator.sendBeacon(url, blob)) {
                    return;
                }
            }
        } catch (e) {
            // Fall through to fetch.
        }
        try {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: body,
                keepalive: true,
            });
        } catch (e2) {
            // Best-effort.
        }
    };

    /**
     * Flush pending seconds. Use beacon on unload paths; Ajax otherwise.
     *
     * @param {Boolean} useBeacon
     */
    const flush = function(useBeacon) {
        if (trackId === null || seconds === 0) {
            return;
        }
        const payload = seconds;
        seconds = 0;
        if (useBeacon) {
            flushBeacon(payload);
            return;
        }
        // Must use loginrequired=true — the service requires a session.
        // nosessionupdate=true avoids bumping lastaccess on every heartbeat.
        Ajax.call([{
            methodname: 'local_nexreports_track_ping',
            args: {id: trackId, time: payload},
        }], true, true, true);
    };

    const startTicker = function() {
        if (trackId === null || ticker !== null) {
            return;
        }
        ticker = window.setInterval(function() {
            seconds++;
            if (seconds >= frequency) {
                flush(false);
            }
        }, 1000);
    };

    const stopTicker = function() {
        if (ticker !== null) {
            window.clearInterval(ticker);
            ticker = null;
        }
    };

    const init = function() {
        if (window.nxrTrackerInit) {
            return;
        }
        window.nxrTrackerInit = true;

        Ajax.call([{
            methodname: 'local_nexreports_track_start',
            args: {contextid: M.cfg.contextid},
        }])[0].then(function(response) {
            if (!response || !response.status) {
                return null;
            }
            trackId = response.id;
            frequency = Math.max(30, response.frequency || 60);

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    startTicker();
                } else {
                    stopTicker();
                    flush(true);
                }
            });

            window.addEventListener('pagehide', function() {
                stopTicker();
                flush(true);
            });

            if (document.visibilityState === 'visible') {
                startTicker();
            }
            return null;
        }).catch(function() {
            // Tracking is best-effort; never surface errors to the user.
        });
    };

    return {init: init};
});
