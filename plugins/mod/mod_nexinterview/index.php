<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course module index — list all NexInterview activities in the course.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$PAGE->set_url('/mod/nexinterview/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'nexinterview'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'nexinterview'));

if (!$cms = get_all_instances_in_course('nexinterview', $course)) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'nexinterview')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('interviewerlabel', 'nexinterview'), get_string('durationlabel', 'nexinterview')];
foreach ($cms as $cm) {
    $interviewer = null;
    if (!empty($cm->interviewerid) && class_exists('\\local_nexinterview\\local\\interviewers')) {
        $interviewer = \local_nexinterview\local\interviewers::get((int) $cm->interviewerid);
    }
    $table->data[] = [
        html_writer::link(
            new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->coursemodule]),
            format_string($cm->name)
        ),
        $interviewer ? format_string($interviewer->name) : '—',
        get_string('minutes', 'nexinterview', (int) $cm->durationminutes),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
