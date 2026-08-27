<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Delete a NexCodeLab problem.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$problem = $DB->get_record('local_nexcodelab_problem', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/delete.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('delete', 'local_nexcodelab'));
$PAGE->set_heading(get_string('delete', 'local_nexcodelab'));
$PAGE->requires->css('/local/nexcodelab/styles.css');

if ($confirm && confirm_sesskey()) {
    $DB->delete_records('local_nexcodelab_testcase', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_lang', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_problem_tag', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_draft', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_submission', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_xpevent', ['problemid' => $id]);
    $DB->delete_records('local_nexcodelab_problem', ['id' => $id]);
    redirect(new moodle_url('/local/nexcodelab/manage/index.php'));
}

echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('confirmdelete', 'local_nexcodelab') . ' (' . format_string($problem->name) . ')',
    new moodle_url('/local/nexcodelab/manage/delete.php', ['id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
    new moodle_url('/local/nexcodelab/manage/index.php')
);
echo $OUTPUT->footer();
