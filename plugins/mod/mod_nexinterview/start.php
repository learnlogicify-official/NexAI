<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Resume + device gate before live interview (all profiles).
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->dirroot . '/local/nexinterview/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('nexinterview', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/nexinterview:attempt', $context);
nexinterview_require_open($instance);

$profile = nexinterview_resolve_profile($instance);
if (empty($profile['ok'])) {
    throw new moodle_exception('noprofilebound', 'nexinterview');
}

$track = (string) $profile['roletrack'];
$interviewerid = (int) $profile['interviewerid'];
$isresume = local_nexinterview_is_resume_track($track);

$PAGE->set_url('/mod/nexinterview/start.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');
$PAGE->requires->css('/mod/nexinterview/styles.css');

$roomurl = (new moodle_url('/mod/nexinterview/room.php', ['id' => $cm->id]))->out(false);
$viewurl = (new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]))->out(false);
$problemid = $isresume ? 0 : \local_nexinterview\local\problems::pick_for_track($track);

$PAGE->requires->js_call_amd('local_nexinterview/start', 'init', [[
    'track' => $track,
    'topics' => (string) $profile['topics'],
    'problemid' => $problemid,
    'interviewerid' => $interviewerid,
    'roomurl' => $roomurl,
    'huburl' => $viewurl,
    'minResumeChars' => $isresume ? 120 : 40,
    'strings' => [
        'needresume' => $isresume
            ? get_string('needresume_deep', 'local_nexinterview')
            : get_string('needresume', 'local_nexinterview'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/start', [
    'tracktitle' => format_string($profile['title']),
    'huburl' => $viewurl,
    'resumesub' => $isresume
        ? get_string('resumesub_deep', 'local_nexinterview')
        : get_string('resumesub', 'local_nexinterview'),
    'hasongoing' => false,
]);
echo $OUTPUT->footer();
