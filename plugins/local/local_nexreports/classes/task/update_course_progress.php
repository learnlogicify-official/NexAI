<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Scheduled refresh of cached course progress rows.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\task;

defined('MOODLE_INTERNAL') || die();

use local_nexreports\local\progress;

/**
 * Rebuilds nexreports_course_progress for courses with completion enabled.
 */
class update_course_progress extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('taskupdatecourseprogress', 'local_nexreports');
    }

    public function execute(): void {
        if (!progress::global_completion_enabled()) {
            mtrace('NexReports: site-wide completion tracking is off '
                . '(Advanced features > Enable completion tracking). No progress can be cached.');
            return;
        }

        $result = progress::refresh_all();
        mtrace('NexReports: refreshed progress for ' . $result['courses']
            . ' courses (' . $result['rows'] . ' learner rows) in ' . $result['elapsed'] . 's');
        if (!empty($result['skipped'])) {
            mtrace('NexReports: ' . $result['skipped']
                . ' course(s) wrote no rows (completion disabled in the course, or no enrolled learners)');
        }
        if (!empty($result['errors'])) {
            mtrace('NexReports: ' . $result['errors']
                . ' course(s) failed (see debugging log)');
        }
        if (!empty($result['remaining'])) {
            mtrace('NexReports: paused at the run budget, ' . $result['remaining']
                . ' course(s) still queued. Run this task again to continue.');
        }

        $diag = progress::diagnostics();
        mtrace('NexReports: cache now holds ' . $diag['rows'] . ' rows, '
            . $diag['fullprogress'] . ' at 100% progress, '
            . $diag['withcompletiontime'] . ' with a completion time'
            . ' (' . $diag['completioncourses'] . ' courses have completion enabled)');
    }
}
