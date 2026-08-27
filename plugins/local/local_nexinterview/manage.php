<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Manage custom interviewers.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:manage', $context);

$PAGE->set_url(new moodle_url('/local/nexinterview/manage.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('manageinterviewers', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$deleteid = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
if ($deleteid && confirm_sesskey()) {
    if ($confirm) {
        \local_nexinterview\local\interviewers::delete($deleteid);
        redirect(new moodle_url('/local/nexinterview/manage.php'),
            get_string('interviewer_deleted', 'local_nexinterview'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('interviewer_deleteconfirm', 'local_nexinterview'),
        new moodle_url('/local/nexinterview/manage.php', ['delete' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
        new moodle_url('/local/nexinterview/manage.php')
    );
    echo $OUTPUT->footer();
    exit;
}

$rows = [];
foreach (\local_nexinterview\local\interviewers::list_all() as $r) {
    $rows[] = [
        'id' => (int) $r->id,
        'name' => (string) $r->name,
        'description' => (string) ($r->description ?? ''),
        'duration' => (int) $r->durationminutes,
        'style' => get_string('style_' . $r->style, 'local_nexinterview'),
        'coding' => (int) $r->includecoding
            ? get_string('yes')
            : get_string('no'),
        'enabledlabel' => (int) $r->enabled
            ? get_string('interviewer_live', 'local_nexinterview')
            : get_string('interviewer_hidden', 'local_nexinterview'),
        'enabled' => (bool) ((int) $r->enabled),
        'editurl' => (new moodle_url('/local/nexinterview/edit_interviewer.php', ['id' => $r->id]))->out(false),
        'deleteurl' => (new moodle_url('/local/nexinterview/manage.php', [
            'delete' => $r->id,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/manage', [
    'title' => get_string('manageinterviewers', 'local_nexinterview'),
    'subtitle' => get_string('manageinterviewers_sub', 'local_nexinterview'),
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'createurl' => (new moodle_url('/local/nexinterview/edit_interviewer.php'))->out(false),
    'hasrows' => !empty($rows),
    'rows' => $rows,
]);
echo $OUTPUT->footer();
