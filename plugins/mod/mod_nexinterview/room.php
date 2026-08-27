<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Fullscreen interview room (reuses local_nexinterview UI).
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->dirroot . '/local/nexinterview/lib.php');

$id = required_param('id', PARAM_INT);
$start = optional_param('start', 0, PARAM_INT);
$sessionid = optional_param('sessionid', '', PARAM_ALPHANUMEXT);
$resume = optional_param('resume', '', PARAM_RAW);

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

$client = new \local_nexinterview\local\client();
if (!$client->configured()) {
    throw new moodle_exception('noservice', 'nexinterview', '', get_string('servicenotconfigured', 'nexinterview'));
}

$duration = nexinterview_effective_duration($instance, $profile);
$track = (string) $profile['roletrack'];
$topics = (string) $profile['topics'];
$interviewerid = (int) $profile['interviewerid'];

$resuming = false;
if ($sessionid !== '') {
    $attempt = \mod_nexinterview\local\attempts::by_session($sessionid);
    if (!$attempt || (int) $attempt->activityid !== (int) $instance->id
            || (int) $attempt->userid !== (int) $USER->id) {
        redirect(new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]));
    }
    if ((string) $attempt->status === 'completed') {
        redirect(new moodle_url('/mod/nexinterview/report.php', [
            'id' => $cm->id,
            'attemptid' => $attempt->id,
        ]));
    }
    $resuming = true;
} else if ($start && $resume === '') {
    // Fresh start without resume text — send through the gate.
    redirect(new moodle_url('/mod/nexinterview/start.php', ['id' => $cm->id]));
}

$PAGE->set_url('/mod/nexinterview/room.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('roomtitle', 'nexinterview'));
$PAGE->set_heading(format_string($instance->name));
$PAGE->set_pagelayout('embedded');
$PAGE->activityheader->disable();
$PAGE->add_body_class('path-local-nexinterview');
$PAGE->add_body_class('nxi-room-page');
$PAGE->add_body_class('path-mod-nexinterview');

$acebase = '';
$acedir = $CFG->dirroot . '/question/type/coderunner/ace';
if (is_readable($acedir . '/ace.js')) {
    $jsrev = empty($CFG->jsrev) ? -1 : $CFG->jsrev;
    $acebase = $CFG->wwwroot . '/lib/javascript.php/' . $jsrev . '/question/type/coderunner/ace';
    $PAGE->requires->js(new moodle_url('/question/type/coderunner/ace/ace.js'), true);
    if (is_readable($acedir . '/ext-language_tools.js')) {
        $PAGE->requires->js(new moodle_url('/question/type/coderunner/ace/ext-language_tools.js'), true);
    }
    if (is_readable($acedir . '/ext-modelist.js')) {
        $PAGE->requires->js(new moodle_url('/question/type/coderunner/ace/ext-modelist.js'), true);
    }
}

$llcss = $CFG->dirroot . '/local/learnlogic/styles.css';
if (is_readable($llcss)) {
    $PAGE->requires->css('/local/learnlogic/styles.css');
}
$PAGE->requires->css('/local/nexinterview/fonts.css');
$PAGE->requires->css('/local/nexinterview/styles.css');

$voicelang = (string) (get_config('local_nexinterview', 'voicelang') ?: 'en-IN');
$realtime = (int) (get_config('local_nexinterview', 'realtimevoice') ?? 1) === 1;
$problemid = local_nexinterview_is_resume_track($track)
    ? 0
    : \local_nexinterview\local\problems::pick_for_track($track);
$viewurl = (new moodle_url('/mod/nexinterview/view.php', ['id' => $cm->id]))->out(false);
$feedbackurl = (new moodle_url('/mod/nexinterview/report.php', ['id' => $cm->id]))->out(false);

$PAGE->requires->js_call_amd('local_nexinterview/room', 'init', [[
    'methodname' => 'mod_nexinterview_proxy',
    'cmid' => (int) $cm->id,
    'sessionid' => $sessionid,
    'start' => (bool) $start && !$resuming,
    'resuming' => $resuming,
    'roletrack' => $track,
    'topics' => $topics,
    'resume' => $resume,
    'problemid' => $problemid,
    'interviewerid' => $interviewerid,
    'durationminutes' => $duration,
    'voicelang' => $voicelang,
    'realtime' => $realtime,
    'realtimeSpeak' => $realtime,
    'feedbackurl' => $feedbackurl,
    'huburl' => $viewurl,
    'aceBaseUrl' => $acebase,
    'canAttempt' => true,
    'serviceConfigured' => true,
    'hasLearnlogic' => is_dir($CFG->dirroot . '/local/learnlogic'),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/room', [
    'name' => format_string($instance->name),
    'huburl' => $viewurl,
    'problemid' => $problemid,
    'listurl' => $viewurl,
    'resuming' => $resuming,
]);
echo $OUTPUT->footer();
