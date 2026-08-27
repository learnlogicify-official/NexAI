<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Rebuild the NexReports course progress cache without the scheduled-task lock.
 *
 * Use this when Site administration → Server → Tasks shows
 * "Cannot obtain task lock" for update_course_progress (a previous run is still
 * holding the lock, or cron is already running the same task).
 *
 * Usage:
 *   php local/nexreports/cli/refresh_progress.php
 *   php local/nexreports/cli/refresh_progress.php --unlock
 *   php local/nexreports/cli/refresh_progress.php --status
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'unlock' => false,
        'status' => false,
        'explain' => '',
        'help' => false,
    ],
    ['h' => 'help', 'u' => 'unlock', 's' => 'status', 'e' => 'explain']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Rebuild NexReports course progress (bypasses the scheduled-task lock).\n");
    cli_writeln("Options:");
    cli_writeln("  --status, -s              Print cache diagnostics only");
    cli_writeln("  --unlock, -u              Drop a stuck task lock, then rebuild");
    cli_writeln("  --explain=COURSEID:USERID Show how one learner's percentage was reached");
    cli_writeln("  -h, --help                Print this help");
    exit(0);
}

raise_memory_limit(MEMORY_HUGE);
\core_php_time_limit::raise(0);

// Match the cron environment, where completion and availability code runs as an admin.
\core\session\manager::set_user(get_admin());

$taskclass = 'local_nexreports\\task\\update_course_progress';

/**
 * Remove a stuck scheduled-task lock for the progress refresh.
 *
 * @param string $taskclass
 * @return int Rows removed
 */
function local_nexreports_cli_unlock_progress_task(string $taskclass): int {
    global $DB;

    if (!$DB->get_manager()->table_exists('lock_db')) {
        return 0;
    }

    $columns = $DB->get_columns('lock_db');
    $keyfield = isset($columns['resourcekey']) ? 'resourcekey'
        : (isset($columns['resource']) ? 'resource' : null);
    if ($keyfield === null) {
        return 0;
    }

    $removed = 0;
    $patterns = [
        '%' . str_replace('\\', '%', $taskclass) . '%',
        '%update_course_progress%',
        '%local_nexreports%task%update_course_progress%',
    ];
    foreach ($patterns as $like) {
        $select = $DB->sql_like($keyfield, ':k');
        $count = (int) $DB->count_records_select('lock_db', $select, ['k' => $like]);
        if ($count > 0) {
            $DB->delete_records_select('lock_db', $select, ['k' => $like]);
            $removed += $count;
        }
    }

    return $removed;
}

/**
 * Print progress-cache diagnostics.
 */
function local_nexreports_cli_print_progress_status(): void {
    global $DB;

    $diag = \local_nexreports\local\progress::diagnostics();

    cli_heading('Completion tracking');
    cli_writeln('  Site-wide completion enabled: ' . ($diag['globalcompletion'] ? 'yes' : 'NO'));
    cli_writeln('  Courses with completion enabled: ' . $diag['completioncourses']);

    cli_heading('NexReports progress cache');
    cli_writeln('  Rows: ' . $diag['rows']);
    cli_writeln('  Rows at 100% progress: ' . $diag['fullprogress']);
    cli_writeln('  Rows with a completion time: ' . $diag['withcompletiontime']);

    cli_heading('Completions inside each KPI window');
    foreach ([7, 30] as $days) {
        $to = (intdiv((int) strtotime('yesterday'), DAYSECS) + 2) * DAYSECS;
        $from = $to - ($days * DAYSECS);
        $count = $DB->count_records_select(
            'nexreports_course_progress',
            'completiontime IS NOT NULL AND completiontime >= :fromts AND completiontime < :tots',
            ['fromts' => $from, 'tots' => $to]
        );
        cli_writeln('  Last ' . $days . ' days (' . userdate($from) . ' → ' . userdate($to) . '): ' . $count);
    }
}

if ($options['explain'] !== '') {
    if (!preg_match('/^(\d+):(\d+)$/', trim((string) $options['explain']), $matches)) {
        cli_error('Use --explain=COURSEID:USERID, for example --explain=82:3112');
    }

    $detail = \local_nexreports\local\progress::explain_learner((int) $matches[1], (int) $matches[2]);
    if (isset($detail['error'])) {
        cli_error($detail['error']);
    }

    cli_heading('Course ' . $matches[1] . ' (' . $detail['course'] . '), user ' . $matches[2]);
    cli_writeln('  Counted by completion reports: ' . ($detail['tracked'] ? 'yes' : 'NO'));

    cli_heading('Activities');
    foreach ($detail['activities'] as $activity) {
        cli_writeln(sprintf(
            '  [%s] %-10s %-45s state %d%s',
            $activity->counted ? ($activity->done ? 'done' : '    ') : 'skip',
            $activity->modname,
            \core_text::substr($activity->name, 0, 45),
            $activity->state,
            $activity->counted ? '' : '  (' . $activity->reason . ')'
        ));
    }

    cli_heading('Result');
    cli_writeln('  Activities counted: ' . $detail['counted']);
    cli_writeln('  Activities complete: ' . $detail['done']);
    cli_writeln('  Progress: ' . $detail['progress'] . '%');
    cli_writeln('  Course marked complete by criteria: '
        . ($detail['coursecompletion'] ? userdate($detail['coursecompletion']) : 'no'));
    if ($detail['cached']) {
        cli_writeln('  Cached NexReports row: ' . $detail['cached']->progress . '%, completion time '
            . ($detail['cached']->completiontime ? userdate($detail['cached']->completiontime) : 'none'));
    }
    exit(0);
}

if ($options['status']) {
    local_nexreports_cli_print_progress_status();
    exit(0);
}

if (!\local_nexreports\local\progress::global_completion_enabled()) {
    cli_error('Site-wide completion tracking is off '
        . '(Site administration → Advanced features → Enable completion tracking).');
}

if ($options['unlock']) {
    cli_heading('Clearing stuck task lock');
    $removed = local_nexreports_cli_unlock_progress_task($taskclass);
    cli_writeln('  Removed ' . $removed . ' lock row(s) matching ' . $taskclass);
}

cli_heading('Rebuilding progress cache (no scheduled-task lock)');
set_config('progresscursor', 0, 'local_nexreports');

$pass = 0;
do {
    $pass++;
    $run = \local_nexreports\local\progress::refresh_all();
    cli_writeln(sprintf(
        '  Pass %d: %d courses, %d learner rows, %.1fs, %d remaining',
        $pass,
        $run['courses'],
        $run['rows'],
        $run['elapsed'],
        $run['remaining']
    ));
    if (!empty($run['skipped'])) {
        cli_writeln('  ' . $run['skipped'] . ' course(s) wrote no rows '
            . '(no tracked learners, or no visible activity with completion enabled)');
    }
    if (!empty($run['errors'])) {
        cli_writeln('  Warnings: ' . $run['errors'] . ' course(s) failed (enable debugging for detail)');
    }
} while ($run['remaining'] > 0 && $run['courses'] > 0 && $pass < 200);

if ($run['remaining'] > 0) {
    cli_writeln('Stopped with ' . $run['remaining'] . ' course(s) still queued. Re-run this script to continue.');
}

local_nexreports_cli_print_progress_status();
cli_writeln('');
cli_writeln('Next: run the overview snapshot task (or wait for cron), then reload NexReports.');
exit(0);
