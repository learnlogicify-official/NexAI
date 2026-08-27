<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexReports — Learner Course Activities.
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

$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nexreports/learner_course_activities.php', [
    'courseid' => $courseid ?: null,
    'userid' => $userid ?: null,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('learnercourseactivities', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$PAGE->requires->js_call_amd('local_nexreports/learner_course_activities', 'init', [
    ['courseid' => $courseid, 'userid' => $userid],
]);

$shell = local_nexreports_shell_context('learnercourseactivities', false);
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'learner_course_activities',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/learner_course_activities', array_merge($shell, [
    'exporturl' => $exporturl,
    'showcollege' => !\local_nexreports\local\access::is_scoped(),
    'showdepartment' => \local_nexreports\local\access::scoped_department() === null,
]));
echo $OUTPUT->footer();
