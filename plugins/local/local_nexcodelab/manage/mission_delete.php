<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Delete a NexCodeLab mission.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_nexcodelab\local\mission_admin;

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$mission = $DB->get_record('local_nexcodelab_mission', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/mission_delete.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('deletemission', 'local_nexcodelab'));
$PAGE->set_heading(get_string('deletemission', 'local_nexcodelab'));
$PAGE->requires->css('/local/nexcodelab/styles.css');

if ($confirm && confirm_sesskey()) {
    mission_admin::delete($id);
    redirect(
        new moodle_url('/local/nexcodelab/manage/index.php'),
        get_string('missiondeleted', 'local_nexcodelab'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('confirmdeletemission', 'local_nexcodelab') . ' (' . format_string($mission->name) . ')',
    new moodle_url('/local/nexcodelab/manage/mission_delete.php', [
        'id' => $id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]),
    new moodle_url('/local/nexcodelab/manage/index.php')
);
echo $OUTPUT->footer();
