<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexReports — Course Completion ( Without Pass Grade Condition ).
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
if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
        && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
    \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$year = optional_param('year', '', PARAM_TEXT);
$department = optional_param('department', '', PARAM_TEXT);
$institution = optional_param('institution', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/nexreports/course_quiz_cumulative.php', [
    'courseid' => $courseid ?: null,
    'year' => $year !== '' ? $year : null,
    'department' => $department !== '' ? $department : null,
    'institution' => $institution !== '' ? $institution : null,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursequizcumulative', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$PAGE->requires->js_call_amd('local_nexreports/course_quiz_cumulative', 'init', [
    ['courseid' => $courseid, 'year' => $year, 'department' => $department, 'institution' => $institution],
]);

$shell = local_nexreports_shell_context('coursequizcumulative', false);
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'course_quiz_cumulative',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/course_quiz_cumulative', array_merge($shell, [
    'exporturl' => $exporturl,
    'showcollege' => !\local_nexreports\local\access::is_scoped(),
    'showdepartment' => \local_nexreports\local\access::scoped_department() === null,
]));
echo $OUTPUT->footer();
