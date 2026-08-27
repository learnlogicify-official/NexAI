<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Fullscreen AI interview room.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:attempt', $context);

$sessionid = optional_param('sessionid', '', PARAM_ALPHANUMEXT);
$track = optional_param('track', 'sde_intern', PARAM_ALPHANUMEXT);
$resume = optional_param('resume', '', PARAM_RAW);
$autostart = optional_param('start', 0, PARAM_BOOL);
$interviewerid = optional_param('interviewerid', 0, PARAM_INT);

$resuming = false;
if ($sessionid !== '') {
    $attempt = \local_nexinterview\local\attempts::get_by_session($sessionid);
    $canviewall = has_capability('local/nexinterview:viewallreports', $context) || is_siteadmin();
    if (!$attempt || ((int) $attempt->userid !== (int) $USER->id && !$canviewall)) {
        redirect(new moodle_url('/local/nexinterview/index.php'));
    }
    if ((string) $attempt->status === 'completed') {
        redirect(new moodle_url('/local/nexinterview/feedback.php', ['sessionid' => $sessionid]));
    }
    if ((string) $attempt->status === 'abandoned') {
        redirect(new moodle_url('/local/nexinterview/index.php'));
    }
    $resuming = true;
    $track = (string) ($attempt->roletrack ?: $track);
}

$PAGE->set_url(new moodle_url('/local/nexinterview/room.php', [
    'sessionid' => $sessionid,
    'track' => $track,
    'interviewerid' => $interviewerid,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexinterview'));
$PAGE->set_pagelayout('embedded');
$PAGE->add_body_class('path-local-nexinterview');
$PAGE->add_body_class('nxi-room-page');
$PAGE->set_heading('');

// Load Ace for later coding stage — do NOT call local_learnlogic_setup_ide_page()
// here (it adds ll-ide-attempt and blanks the talk UI).
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

$feedbackurl = (new moodle_url('/local/nexinterview/feedback.php'))->out(false);
$voicelang = (string) (get_config('local_nexinterview', 'voicelang') ?: 'en-IN');
$problemid = \local_nexinterview\local\problems::pick_for_track($track);
$serviceok = (new \local_nexinterview\local\client())->configured();
$realtime = (int) (get_config('local_nexinterview', 'realtimevoice') ?? 1) === 1;

$custom = $interviewerid > 0 ? \local_nexinterview\local\interviewers::get($interviewerid) : null;
$topics = '';
if ($custom && (int) $custom->enabled) {
    $track = (string) $custom->roletrack;
    $topics = (string) $custom->topics;
}

$PAGE->requires->js_call_amd('local_nexinterview/room', 'init', [[
    'sessionid' => $sessionid,
    'start' => (bool) $autostart,
    'resuming' => $resuming,
    'roletrack' => $track,
    'topics' => $topics,
    'resume' => $resume,
    'problemid' => $problemid,
    'interviewerid' => $interviewerid,
    'voicelang' => $voicelang,
    'realtime' => $realtime,
    'realtimeSpeak' => $realtime,
    'feedbackurl' => $feedbackurl,
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'aceBaseUrl' => $acebase,
    'canAttempt' => true,
    'serviceConfigured' => $serviceok,
    'hasLearnlogic' => is_dir($CFG->dirroot . '/local/learnlogic'),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/room', [
    'name' => get_string('pluginname', 'local_nexinterview'),
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'problemid' => $problemid,
    'listurl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'resuming' => $resuming,
]);
echo $OUTPUT->footer();
