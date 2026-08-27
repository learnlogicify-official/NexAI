<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexReports courses tab — all courses summary.
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

$PAGE->set_url(new moodle_url('/local/nexreports/courses.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('courses', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$PAGE->requires->js_call_amd('local_nexreports/courses', 'init', []);

$shell = local_nexreports_shell_context('courses', false);
$exporturl = (new moodle_url('/local/nexreports/download.php', [
    'report' => 'courses_summary',
    'format' => 'csv',
]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/courses', array_merge($shell, [
    'exporturl' => $exporturl,
]));
echo $OUTPUT->footer();
