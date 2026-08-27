<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexProctor preflight — consent + media permissions.
 * @package local_nexproctor
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

global $DB, $PAGE, $OUTPUT;

$cmid = required_param('cmid', PARAM_INT);
$quizid = required_param('quizid', PARAM_INT);

$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:attempt', $context);

$settings = local_nexproctor_get_quiz_settings($quizid);
if (empty($settings->nexproctorenabled)) {
    redirect(new moodle_url('/mod/quiz/view.php', ['id' => $cmid]));
}

$PAGE->set_url('/local/nexproctor/preflight.php', ['cmid' => $cmid, 'quizid' => $quizid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('preflight_title', 'local_nexproctor'));
$PAGE->set_heading(format_string($quiz->name));
$PAGE->set_pagelayout('embedded');
$PAGE->requires->css(new moodle_url('/local/nexproctor/styles/monitor.css'));

// After checks, send student back to quiz view so they can start (attempt gate also runs).
$returnurl = (new moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false);

$PAGE->requires->js_call_amd('local_nexproctor/preflight', 'init', [[
    'cmid' => $cmid,
    'quizid' => $quizid,
    'returnUrl' => $returnurl,
    'autoConsent' => false,
    'settings' => [
        'requirecamera' => (int) $settings->requirecamera,
        'requiremic' => (int) $settings->requiremic,
        'requirescreenshare' => (int) $settings->requirescreenshare,
        'requirefullscreen' => (int) $settings->requirefullscreen,
        'blockmultimonitor' => (int) $settings->blockmultimonitor,
        'detectfaces' => (int) $settings->detectfaces,
    ],
    'strings' => [
        'consentLabel' => get_string('consent_label', 'local_nexproctor'),
        'startBtn' => get_string('start_after_preflight', 'local_nexproctor'),
        'needCamera' => get_string('need_camera', 'local_nexproctor'),
        'needMic' => get_string('need_mic', 'local_nexproctor'),
        'needScreen' => get_string('need_screen', 'local_nexproctor'),
        'needFullscreen' => get_string('need_fullscreen', 'local_nexproctor'),
        'multiMonitor' => get_string('multi_monitor_blocked', 'local_nexproctor'),
        'needFace' => get_string('need_one_face', 'local_nexproctor'),
        'ready' => get_string('preflight_ready', 'local_nexproctor'),
        'runningChecks' => get_string('running_checks', 'local_nexproctor'),
        'needConsent' => get_string('need_consent', 'local_nexproctor'),
        'notReady' => get_string('not_ready', 'local_nexproctor'),
        'checkCamera' => get_string('check_camera', 'local_nexproctor'),
        'checkMic' => get_string('check_mic', 'local_nexproctor'),
        'checkScreen' => get_string('check_screen', 'local_nexproctor'),
        'checkFullscreen' => get_string('check_fullscreen', 'local_nexproctor'),
        'checkMonitor' => get_string('check_monitor', 'local_nexproctor'),
        'checkFace' => get_string('check_face', 'local_nexproctor'),
        'checkConsent' => get_string('check_consent', 'local_nexproctor'),
        'retry' => get_string('fix_retry', 'local_nexproctor'),
        'startChecks' => get_string('start_checks', 'local_nexproctor'),
    ],
]]);

echo $OUTPUT->header();
echo html_writer::start_div('np-preflight', ['id' => 'np-preflight']);
echo html_writer::tag('h2', get_string('preflight_title', 'local_nexproctor'));
echo html_writer::tag('p', get_string('preflight_intro', 'local_nexproctor'));
echo html_writer::div('', 'np-preflight__progress', ['id' => 'np-preflight-progress']);
echo html_writer::div('', 'np-preflight__preview-wrap', [
    'id' => 'np-preflight-preview-wrap',
]);
echo html_writer::div('', 'np-preflight__status', ['id' => 'np-preflight-status']);
echo html_writer::div(
    html_writer::checkbox('np-consent', 1, false, get_string('consent_label', 'local_nexproctor'), [
        'id' => 'np-consent',
    ]),
    'np-preflight__consent'
);
echo html_writer::tag('button', get_string('enable_devices', 'local_nexproctor'), [
    'type' => 'button',
    'class' => 'btn btn-secondary',
    'id' => 'np-preflight-enable',
]);
echo ' ';
echo html_writer::tag('button', get_string('start_after_preflight', 'local_nexproctor'), [
    'type' => 'button',
    'class' => 'btn btn-primary',
    'id' => 'np-preflight-start',
    'disabled' => 'disabled',
]);
echo html_writer::end_div();
echo $OUTPUT->footer();
