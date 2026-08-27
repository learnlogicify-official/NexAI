<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Reports the state of the NexReports progress cache and the completion KPI window.
 *
 * Usage: php local/nexreports/cli/progress_status.php [--rebuild]
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['rebuild' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Show why the NexReports course completions KPI reads the value it does.\n");
    cli_writeln("Options:");
    cli_writeln("  --rebuild   Rebuild the progress cache before reporting");
    cli_writeln("  -h, --help  Print this help");
    exit(0);
}

if ($options['rebuild']) {
    cli_heading('Rebuilding progress cache');
    do {
        $run = \local_nexreports\local\progress::refresh_all();
        cli_writeln('  ' . $run['courses'] . ' courses, ' . $run['rows'] . ' rows, '
            . $run['elapsed'] . 's, ' . $run['remaining'] . ' remaining');
    } while ($run['remaining'] > 0 && $run['courses'] > 0);
}

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
    cli_writeln('  Last ' . $days . ' days (' . userdate($from) . ' to ' . userdate($to) . '): ' . $count);
}

exit(0);
