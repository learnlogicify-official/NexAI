<?php
// This file is part of Moodle - http://moodle.org/
/**
 * CLI: rebuild weekly learner improvement scorecards.
 *
 * Usage:
 *   php local/nexreports/cli/rebuild_weekly.php
 *   php local/nexreports/cli/rebuild_weekly.php --weeks=8
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
        'weeks' => 8,
        'help' => false,
    ],
    ['h' => 'help', 'w' => 'weeks']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if (!empty($options['help'])) {
    cli_writeln("Rebuild NexReports weekly learner scorecards (backfill).\n");
    cli_writeln("Options:");
    cli_writeln("  --weeks=N   Number of ISO weeks including current (default 8, max 26)");
    cli_writeln("  -h, --help  Show this help");
    exit(0);
}

$weeks = max(1, min(26, (int) $options['weeks']));
cli_writeln("Rebuilding last {$weeks} week(s)…");

$result = \local_nexreports\local\weekly_insights::rebuild($weeks, static function(string $msg): void {
    cli_writeln($msg);
});

cli_writeln(sprintf(
    'Done: %d weeks, %d learners, %d rows written in %.2fs',
    $result['weeks'],
    $result['learners'],
    $result['rows'],
    $result['seconds']
));
