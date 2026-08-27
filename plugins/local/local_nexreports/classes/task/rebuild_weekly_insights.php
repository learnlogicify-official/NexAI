<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Adhoc / one-shot rebuild of weekly learner scorecards (default last 8 weeks).
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\task;

defined('MOODLE_INTERNAL') || die();

use local_nexreports\local\weekly_insights;

/**
 * Queued on upgrade to backfill history without blocking the upgrade request.
 */
class rebuild_weekly_insights extends \core\task\adhoc_task {

    public function execute(): void {
        $data = $this->get_custom_data();
        $weeks = is_object($data) && isset($data->weeks)
            ? (int) $data->weeks
            : weekly_insights::DEFAULT_WEEKS;
        $weeks = max(1, min(26, $weeks));
        $result = weekly_insights::rebuild($weeks, static function(string $msg): void {
            mtrace('NexReports weekly: ' . $msg);
        });
        mtrace('NexReports weekly rebuild done: ' . $result['weeks'] . ' weeks, '
            . $result['rows'] . ' rows, ' . $result['learners'] . ' learners, '
            . $result['seconds'] . 's');
    }
}
