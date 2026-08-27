<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Student feedback report for one attempt.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);
$sessionid = optional_param('sessionid', '', PARAM_ALPHANUMEXT);

$cm = get_coursemodule_from_id('nexinterview', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/nexinterview:view', $context);

if ($attemptid > 0) {
    $attempt = $DB->get_record('nexinterview_attempts', [
        'id' => $attemptid,
        'activityid' => $instance->id,
    ], '*', MUST_EXIST);
} else if ($sessionid !== '') {
    $attempt = \mod_nexinterview\local\attempts::by_session($sessionid);
    if (!$attempt || (int) $attempt->activityid !== (int) $instance->id) {
        throw new moodle_exception('nopermissions', 'error', '', 'attempt');
    }
} else {
    $attempt = \mod_nexinterview\local\attempts::latest((int) $instance->id, (int) $USER->id);
    if (!$attempt || $attempt->status !== 'completed') {
        redirect(new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]));
    }
}

$canviewall = has_capability('mod/nexinterview:viewreports', $context);
if ((int) $attempt->userid !== (int) $USER->id && !$canviewall) {
    throw new moodle_exception('nopermissions', 'error', '', 'attempt');
}

$PAGE->set_url('/mod/nexinterview/report.php', ['id' => $cm->id, 'attemptid' => $attempt->id]);
$PAGE->set_title(get_string('feedbacktitle', 'nexinterview'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$PAGE->requires->css('/mod/nexinterview/styles.css');

$report = json_decode((string) $attempt->reportjson, true) ?: [];
$strengths = [];
foreach (($report['strengths'] ?? []) as $s) {
    $strengths[] = ['text' => is_string($s) ? $s : (string) ($s['text'] ?? '')];
}
$weaknesses = [];
foreach (($report['areas_to_improve'] ?? $report['weaknesses'] ?? []) as $w) {
    $weaknesses[] = ['text' => is_string($w) ? $w : (string) ($w['text'] ?? '')];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_nexinterview/report', [
    'name' => format_string($instance->name),
    'overall' => format_float((float) $attempt->overallscore, 1),
    'recommendation' => s((string) ($attempt->recommendation ?: ($report['recommendation'] ?? ''))),
    'summary' => s((string) ($report['summary'] ?? $report['spoken_summary'] ?? '')),
    'hasstrengths' => !empty($strengths),
    'strengths' => $strengths,
    'hasweaknesses' => !empty($weaknesses),
    'weaknesses' => $weaknesses,
    'backurl' => (new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
