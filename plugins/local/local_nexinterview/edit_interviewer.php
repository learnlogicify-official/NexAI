<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Create / edit a custom interviewer.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/interviewer_form.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:manage', $context);

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_url(new moodle_url('/local/nexinterview/edit_interviewer.php', $id ? ['id' => $id] : []));
$PAGE->set_context($context);
$PAGE->set_title($id
    ? get_string('editinterviewer', 'local_nexinterview')
    : get_string('createinterviewer', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$existing = $id ? \local_nexinterview\local\interviewers::get($id) : null;
if ($id && !$existing) {
    throw new moodle_exception('invalidrecord', 'error');
}

$form = new local_nexinterview_interviewer_form(null, ['id' => $id]);
if ($existing) {
    $form->set_data($existing);
} else {
    $form->set_data((object) ['id' => 0, 'enabled' => 1, 'includecoding' => 1, 'durationminutes' => 17, 'qaminutes' => 0]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/nexinterview/manage.php'));
}

if ($data = $form->get_data()) {
    $saved = \local_nexinterview\local\interviewers::save((array) $data);
    redirect(
        new moodle_url('/local/nexinterview/manage.php'),
        get_string('interviewersaved', 'local_nexinterview'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::start_div('nxi-manage');
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/local/nexinterview/manage.php'),
    '← ' . get_string('manageinterviewers', 'local_nexinterview')
));
echo $OUTPUT->heading($id
    ? get_string('editinterviewer', 'local_nexinterview')
    : get_string('createinterviewer', 'local_nexinterview'));
$form->display();
echo html_writer::end_div();
echo $OUTPUT->footer();
