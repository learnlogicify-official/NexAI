<?php
// This file is part of Moodle - http://moodle.org/
/**
 * CLI: check whether NexReports dwell-time tracking is collecting.
 *
 * Usage (from Moodle root):
 *   php local/nexreports/cli/check_tracking.php
 *   php local/nexreports/cli/check_tracking.php --userid=123
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'userid' => 0,
], [
    'h' => 'help',
    'u' => 'userid',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    echo "Check NexReports time-tracking collection.

Options:
  -h, --help         Show this help
  -u, --userid=ID    Focus on one user (optional)
";
    exit(0);
}

$enabled = (string) get_config('local_nexreports', 'enabletracking') !== '0';
$freq = (int) (get_config('local_nexreports', 'trackfrequency') ?: 60);
$first = \local_nexreports\local\tracking::first_tracked();

cli_writeln('=== NexReports tracking check ===');
cli_writeln('enabletracking: ' . ($enabled ? 'ON' : 'OFF'));
cli_writeln('trackfrequency: ' . $freq . 's');
cli_writeln('first real tracked timestart: ' . ($first ? userdate($first) . " ($first)" : 'none yet'));

if (!$DB->get_manager()->table_exists('nexreports_tracking')) {
    cli_error('Table nexreports_tracking is missing — run plugin upgrade.');
}

$totals = $DB->get_record_sql(
    "SELECT COUNT(*) AS rows,
            COALESCE(SUM(CASE WHEN timespent > 0 THEN 1 ELSE 0 END), 0) AS withsecs,
            COALESCE(SUM(timespent), 0) AS secs
       FROM {nexreports_tracking}"
);
cli_writeln('rows total: ' . (int) $totals->rows);
cli_writeln('rows with timespent>0: ' . (int) $totals->withsecs);
cli_writeln('sum timespent (all users): ' . (int) $totals->secs . 's (' .
    round(((int) $totals->secs) / 60) . ' min)');

$userid = (int) $options['userid'];
if ($userid > 0) {
    $u = $DB->get_record_sql(
        "SELECT COUNT(*) AS rows,
                COALESCE(SUM(CASE WHEN timespent > 0 THEN 1 ELSE 0 END), 0) AS withsecs,
                COALESCE(SUM(timespent), 0) AS secs,
                MAX(lastping) AS lastping
           FROM {nexreports_tracking}
          WHERE userid = ?",
        [$userid]
    );
    cli_writeln("--- user {$userid} ---");
    cli_writeln('rows: ' . (int) $u->rows);
    cli_writeln('rows with seconds: ' . (int) $u->withsecs);
    cli_writeln('sum timespent: ' . (int) $u->secs . 's (' . round(((int) $u->secs) / 60) . ' min)');
    cli_writeln('lastping: ' . (!empty($u->lastping) ? userdate((int) $u->lastping) : 'n/a'));
} else {
    $top = $DB->get_records_sql(
        "SELECT userid, SUM(timespent) AS secs, MAX(lastping) AS lastping
           FROM {nexreports_tracking}
          WHERE timespent > 0
       GROUP BY userid
       ORDER BY secs DESC",
        [],
        0,
        10
    );
    if ($top) {
        cli_writeln('--- top 10 users by tracked seconds ---');
        foreach ($top as $row) {
            cli_writeln(sprintf(
                '  userid=%d  %ds (%d min)  lastping=%s',
                (int) $row->userid,
                (int) $row->secs,
                (int) round(((int) $row->secs) / 60),
                !empty($row->lastping) ? userdate((int) $row->lastping) : 'n/a'
            ));
        }
    } else {
        cli_writeln('No rows with timespent>0 yet.');
        cli_writeln('Browse as a real logged-in user (not loginas) with the tab visible for >60s, then re-run.');
    }
}

if (!$enabled) {
    cli_writeln('WARNING: tracking is disabled in settings.');
}
if ((int) $totals->rows > 0 && (int) $totals->withsecs === 0) {
    cli_writeln('WARNING: start() rows exist but pings never added seconds — install nexreports 0.4.3+ tracker fix.');
}

cli_writeln('Done.');
exit(0);
