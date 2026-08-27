<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCodeLab reports tab — XP, missions, and challenge solves.
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
    throw new moodle_exception('nopermissions', 'error', '', get_string('nexcodelab', 'local_nexreports'));
}
if (get_config('local_nexcodelab', 'version') === false) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('nexcodelab', 'local_nexreports'));
}

$PAGE->set_url(new moodle_url('/local/nexreports/nexcodelab.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('nexcodelab', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexreports/styles.css', ['v' => '2026081042']));
$PAGE->requires->js_call_amd('local_nexreports/nexcodelab', 'init', []);

$shell = local_nexreports_shell_context('nexcodelab');
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'codelab_leaderboard',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/nexcodelab', array_merge($shell, [
    'exporturl' => $exporturl,
    'showperiod' => false,
    'showcollege' => true,
    'showdepartment' => true,
]));
echo $OUTPUT->footer();
