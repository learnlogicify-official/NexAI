<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Scheduled refresh of default NexReports overview snapshots.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\task;

defined('MOODLE_INTERNAL') || die();

use local_nexreports\local\overview;

/**
 * Precompute unfiltered overview blocks so the dashboard can read from the table.
 */
class refresh_overview extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrefreshoverview', 'local_nexreports');
    }

    /**
     * Recompute default 7- and 30-day blocks and store them.
     */
    public function execute(): void {
        $result = overview::refresh_default_snapshots();
        mtrace('NexReports: refreshed ' . $result['blocks'] . ' snapshot blocks in '
            . $result['seconds'] . 's');
    }
}
