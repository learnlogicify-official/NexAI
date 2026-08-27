<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Delete a NexPractice problem.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:manageproblems', $context);

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$problem = $DB->get_record('local_learnlogic_problem', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/learnlogic/manage/delete.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('delete', 'local_learnlogic'));
$PAGE->set_heading(get_string('delete', 'local_learnlogic'));
$PAGE->requires->css('/local/learnlogic/styles.css');

if ($confirm && confirm_sesskey()) {
    // Adjust user XP totals before removing events tied to this problem.
    $events = $DB->get_records('local_learnlogic_xpevent', ['problemid' => $id], '', 'id, userid, amount');
    $deduct = [];
    foreach ($events as $event) {
        $uid = (int) $event->userid;
        $deduct[$uid] = ($deduct[$uid] ?? 0) + (int) $event->amount;
    }

    $DB->delete_records('local_learnlogic_testcase', ['problemid' => $id]);
    $DB->delete_records('local_learnlogic_lang', ['problemid' => $id]);
    \local_learnlogic\local\solutions::delete_for_problem($id);
    $DB->delete_records('local_learnlogic_problem_tag', ['problemid' => $id]);
    $DB->delete_records('local_learnlogic_draft', ['problemid' => $id]);
    $DB->delete_records('local_learnlogic_submission', ['problemid' => $id]);
    $DB->delete_records('local_learnlogic_xpevent', ['problemid' => $id]);
    $DB->delete_records('local_learnlogic_problem', ['id' => $id]);

    foreach ($deduct as $userid => $amount) {
        $rec = $DB->get_record('local_learnlogic_userxp', ['userid' => $userid]);
        if (!$rec) {
            continue;
        }
        $rec->xp = max(0, (int) $rec->xp - (int) $amount);
        $rec->timemodified = time();
        if ($rec->xp <= 0) {
            $DB->delete_records('local_learnlogic_userxp', ['userid' => $userid]);
        } else {
            $DB->update_record('local_learnlogic_userxp', $rec);
        }
    }

    redirect(new moodle_url('/local/learnlogic/manage/index.php'));
}

echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('confirmdelete', 'local_learnlogic') . ' (' . format_string($problem->name) . ')',
    new moodle_url('/local/learnlogic/manage/delete.php', ['id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
    new moodle_url('/local/learnlogic/manage/index.php')
);
echo $OUTPUT->footer();
