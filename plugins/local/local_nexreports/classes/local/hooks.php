<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Output hook callbacks for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Loads the dwell-time tracker on every page for logged-in users.
 */
class hooks {

    /**
     * Inject the tracker AMD module before the footer renders.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE;

        if (!tracking::enabled()) {
            return;
        }
        if (during_initial_install() || !get_config('local_nexreports', 'version')) {
            return;
        }
        if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
            return;
        }

        $PAGE->requires->js_call_amd('local_nexreports/tracker', 'init');
    }
}
