<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Weekly learner improvement insights.
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
if (!\local_nexreports\local\access::has_capability('local/nexreports:viewstudents', $context)
        && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
    \local_nexreports\local\access::require_capability('local/nexreports:viewstudents', $context);
}

$PAGE->set_url(new moodle_url('/local/nexreports/weekly_insights.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('weeklyinsights', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$PAGE->requires->js_call_amd('local_nexreports/weekly_insights', 'init', []);

$shell = local_nexreports_shell_context('weeklyinsights');
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'weekly_insights',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/weekly_insights', array_merge($shell, [
    'exporturl' => $exporturl,
    'showcollege' => !\local_nexreports\local\access::is_scoped(),
    'showdepartment' => \local_nexreports\local\access::scoped_department() === null,
]));
echo $OUTPUT->footer();
