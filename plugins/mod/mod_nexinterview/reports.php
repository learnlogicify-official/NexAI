<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Teacher reports for a NexInterview activity.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('nexinterview', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/nexinterview:viewreports', $context);

$PAGE->set_url('/mod/nexinterview/reports.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('viewreports', 'nexinterview'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$PAGE->requires->css('/mod/nexinterview/styles.css');

$rows = $DB->get_records_sql(
    "SELECT a.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
       FROM {nexinterview_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.activityid = :aid AND a.status = 'completed'
   ORDER BY a.timecompleted DESC",
    ['aid' => $instance->id]
);

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'student' => fullname($r),
        'score' => format_float((float) $r->overallscore, 1),
        'completed' => userdate((int) $r->timecompleted),
        'url' => (new moodle_url('/mod/nexinterview/report.php', [
            'id' => $cm->id,
            'attemptid' => $r->id,
        ]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_nexinterview/reports', [
    'name' => format_string($instance->name),
    'hasrows' => !empty($items),
    'rows' => $items,
    'backurl' => (new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
