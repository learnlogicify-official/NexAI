<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Daily refresh of the current weekly learner scorecard.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\task;

defined('MOODLE_INTERNAL') || die();

use local_nexreports\local\weekly_insights;

/**
 * Keeps the in-progress ISO week up to date.
 */
class refresh_weekly_insights extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('taskrefreshweekly', 'local_nexreports');
    }

    public function execute(): void {
        // On Mondays also ensure the previous closed week is finalized.
        $tz = \core_date::get_server_timezone_object();
        $dow = (int) (new \DateTimeImmutable('now', $tz))->format('N');
        if ($dow === 1) {
            $result = weekly_insights::rebuild(2);
            mtrace('NexReports weekly: rebuilt last 2 weeks — '
                . $result['rows'] . ' rows / ' . $result['learners']
                . ' learners in ' . $result['seconds'] . 's');
            return;
        }
        $result = weekly_insights::refresh_current_week();
        mtrace('NexReports weekly: refreshed current week — '
            . $result['rows'] . ' rows / ' . $result['learners']
            . ' learners in ' . $result['seconds'] . 's');
    }
}
