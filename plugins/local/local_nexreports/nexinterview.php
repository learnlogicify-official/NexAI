<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexInterview reports tab — attempt ledger and interview KPIs.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
local_nexreports_require_access();
\local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
if (\local_nexreports\local\access::is_scoped()) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('nexinterview', 'local_nexreports'));
}
if (get_config('local_nexinterview', 'version') === false) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('nexinterview', 'local_nexreports'));
}

$PAGE->set_url(new moodle_url('/local/nexreports/nexinterview.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('nexinterview', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexreports/styles.css', ['v' => '2026081045']));
$PAGE->requires->js_call_amd('local_nexreports/nexinterview', 'init', []);

$shell = local_nexreports_shell_context('nexinterview');
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'interview_attempts',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/nexinterview', array_merge($shell, [
    'exporturl' => $exporturl,
    'showperiod' => false,
    'showcollege' => true,
    'showdepartment' => true,
]));
echo $OUTPUT->footer();
