<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library helpers for local_nexproctor.
 * @package local_nexproctor
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Default trust penalties by event type.
 * @return array
 */
function local_nexproctor_penalties(): array {
    return [
        'tab_hidden' => 8,
        'blur' => 3,
        'fullscreen_exit' => 3,
        'no_face' => 10,
        'multi_face' => 15,
        'looking_away' => 5,
        'noise_detected' => 4,
        'camera_lost' => 12,
        'mic_lost' => 8,
        'screenshare_lost' => 15,
        'multi_monitor' => 25,
    ];
}

/**
 * Recalculate trust score for a session.
 * @param int $sessionid
 * @return int
 */
function local_nexproctor_recalc_trust(int $sessionid): int {
    global $DB;
    $penalties = local_nexproctor_penalties();
    $events = $DB->get_records('local_nexproctor_events', ['sessionid' => $sessionid], 'id ASC');
    $score = 100;
    foreach ($events as $ev) {
        $p = (int) $ev->penalty;
        if ($p <= 0 && isset($penalties[$ev->eventtype])) {
            $p = (int) $penalties[$ev->eventtype];
        }
        $score -= $p;
    }
    $score = max(0, min(100, $score));
    $DB->set_field('local_nexproctor_sessions', 'trustscore', $score, ['id' => $sessionid]);
    $DB->set_field('local_nexproctor_sessions', 'timemodified', time(), ['id' => $sessionid]);
    return $score;
}

/**
 * Session key for preflight completion.
 * @param int $quizid
 * @param int $userid
 * @return string
 */
function local_nexproctor_preflight_key(int $quizid, int $userid): string {
    return 'nexproctor_preflight_' . $quizid . '_' . $userid;
}

/**
 * @param int $quizid
 * @param int $userid
 * @return bool
 */
function local_nexproctor_preflight_done(int $quizid, int $userid): bool {
    global $SESSION;
    $key = local_nexproctor_preflight_key($quizid, $userid);
    return !empty($SESSION->$key);
}

/**
 * @param int $quizid
 * @param int $userid
 */
function local_nexproctor_mark_preflight_done(int $quizid, int $userid): void {
    global $SESSION;
    $key = local_nexproctor_preflight_key($quizid, $userid);
    $SESSION->$key = time();
}

/**
 * Load quizaccess settings row or defaults.
 * @param int $quizid
 * @return stdClass
 */
function local_nexproctor_get_quiz_settings(int $quizid): stdClass {
    global $DB;
    $row = $DB->get_record('quizaccess_nexproctor', ['quizid' => $quizid]);
    if ($row) {
        return $row;
    }
    $defaults = (object) [
        'quizid' => $quizid,
        'nexproctorenabled' => 0,
        'requirecamera' => 1,
        'requiremic' => 1,
        'requirescreenshare' => 1,
        'requirefullscreen' => 1,
        'blockmultimonitor' => 1,
        'detectfaces' => 1,
        'detectnoise' => 1,
        'detecttabswitch' => 1,
        'detectattention' => 1,
        'heartbeatsecs' => 45,
        'photoonviolation' => 1,
    ];
    return $defaults;
}

/**
 * Store base64 evidence into Moodle files.
 *
 * @param int $sessionid
 * @param int $eventid
 * @param string $filearea snapshot|screengrab|audioclip|prestart
 * @param string $mimetype
 * @param string $base64
 * @param string $filename
 * @return int evidence id
 */
function local_nexproctor_store_evidence(
    int $sessionid,
    int $eventid,
    string $filearea,
    string $mimetype,
    string $base64,
    string $filename = ''
): int {
    global $DB, $USER;

    $allowed = ['snapshot', 'screengrab', 'audioclip', 'prestart'];
    if (!in_array($filearea, $allowed, true)) {
        throw new invalid_parameter_exception('Invalid filearea');
    }

    if (strpos($base64, ',') !== false) {
        $base64 = substr($base64, strpos($base64, ',') + 1);
    }
    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) < 32) {
        throw new invalid_parameter_exception('Invalid evidence payload');
    }
    $max = (int) get_config('local_nexproctor', 'maxevidencesize');
    if ($max <= 0) {
        $max = 2 * 1024 * 1024;
    }
    if (strlen($binary) > $max) {
        throw new invalid_parameter_exception('Evidence too large');
    }

    $session = $DB->get_record('local_nexproctor_sessions', ['id' => $sessionid], '*', MUST_EXIST);
    if ((int) $session->userid !== (int) $USER->id) {
        throw new required_capability_exception(
            context_system::instance(),
            'local/nexproctor:takesession',
            'nopermissions',
            ''
        );
    }

    // Unique itemid per file so each event maps to the correct evidence
    // (reusing sessionid made every row show the latest photo).
    $itemid = (int) (microtime(true) * 1000) % 2000000000;
    if ($itemid < 1) {
        $itemid = random_int(1, 1999999999);
    }

    $fs = get_file_storage();
    $context = context_module::instance($session->cmid, IGNORE_MISSING);
    if (!$context) {
        $context = context_user::instance($USER->id);
    }

    if ($filename === '') {
        $ext = ($filearea === 'audioclip') ? 'webm' : 'jpg';
        $filename = $filearea . '_' . $sessionid . '_' . $eventid . '_' . time() . '_' . random_string(4) . '.' . $ext;
    }

    $record = [
        'contextid' => $context->id,
        'component' => 'local_nexproctor',
        'filearea' => $filearea,
        'itemid' => $itemid,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $USER->id,
    ];
    $fs->create_file_from_string($record, $binary);

    $ev = (object) [
        'sessionid' => $sessionid,
        'eventid' => $eventid,
        'filearea' => $filearea,
        'itemid' => $itemid,
        'mimetype' => $mimetype,
        'timecreated' => time(),
    ];
    return (int) $DB->insert_record('local_nexproctor_evidence', $ev);
}

/**
 * True on live quiz attempt pages only (not review/summary/view).
 */
function local_nexproctor_is_attempt_page(): bool {
    global $PAGE, $SCRIPT;

    if (!empty($PAGE->pagetype) && $PAGE->pagetype === 'mod-quiz-attempt') {
        return true;
    }
    $script = (string) ($SCRIPT ?? '');
    if (preg_match('~(^|/)mod/quiz/attempt\.php$~i', str_replace('\\', '/', $script))) {
        return true;
    }
    $path = '';
    if (!empty($PAGE->url)) {
        $path = $PAGE->url->get_path();
    }
    if ($path && preg_match('~(^|/)mod/quiz/attempt\.php$~i', $path)) {
        return true;
    }
    return false;
}

/**
 * True on quiz review pages.
 */
function local_nexproctor_is_review_page(): bool {
    global $PAGE, $SCRIPT;

    if (!empty($PAGE->pagetype) && $PAGE->pagetype === 'mod-quiz-review') {
        return true;
    }
    $script = (string) ($SCRIPT ?? '');
    if (preg_match('~(^|/)mod/quiz/review\.php$~i', str_replace('\\', '/', $script))) {
        return true;
    }
    return optional_param('attempt', 0, PARAM_INT) > 0
        && !empty($_SERVER['SCRIPT_NAME'])
        && stripos($_SERVER['SCRIPT_NAME'], 'review.php') !== false;
}

/**
 * Resolve quiz cmid from current page / params.
 */
function local_nexproctor_resolve_cmid(): int {
    global $PAGE, $DB;

    if (!empty($PAGE->cm) && $PAGE->cm->modname === 'quiz') {
        return (int) $PAGE->cm->id;
    }
    $cmid = optional_param('cmid', 0, PARAM_INT);
    if ($cmid) {
        return $cmid;
    }
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    if ($attemptid) {
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz', IGNORE_MISSING);
        if ($attempt) {
            $cm = get_coursemodule_from_instance('quiz', $attempt->quiz);
            if ($cm) {
                return (int) $cm->id;
            }
        }
    }
    return 0;
}

/**
 * End any active proctoring session for an attempt (server-side safety net).
 *
 * @param int $attemptid
 * @param int $userid
 */
function local_nexproctor_end_sessions_for_attempt(int $attemptid, int $userid = 0): void {
    global $DB;
    if ($attemptid <= 0) {
        return;
    }
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, userid', IGNORE_MISSING);
    $now = time();

    $sessions = $DB->get_records('local_nexproctor_sessions', [
        'attemptid' => $attemptid,
        'status' => 'active',
    ]);
    // Also end active sessions for same user+quiz with missing attemptid.
    if ($attempt) {
        $orphans = $DB->get_records_select(
            'local_nexproctor_sessions',
            'quizid = :qid AND userid = :uid AND status = :st AND (attemptid = 0 OR attemptid IS NULL)',
            [
                'qid' => $attempt->quiz,
                'uid' => $userid ?: $attempt->userid,
                'st' => 'active',
            ]
        );
        foreach ($orphans as $oid => $orow) {
            $sessions[$oid] = $orow;
        }
    }
    foreach ($sessions as $session) {
        $session->status = 'ended';
        $session->endedat = $now;
        $session->timemodified = $now;
        if (empty($session->attemptid)) {
            $session->attemptid = $attemptid;
        }
        $DB->update_record('local_nexproctor_sessions', $session);
        local_nexproctor_recalc_trust((int) $session->id);
    }
}

/**
 * Observer: attempt submitted → end proctoring sessions.
 *
 * @param \mod_quiz\event\attempt_submitted $event
 */
function local_nexproctor_observer_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
    $data = $event->get_data();
    $attemptid = (int) ($data['objectid'] ?? 0);
    $userid = (int) ($data['relateduserid'] ?? $data['userid'] ?? 0);
    local_nexproctor_end_sessions_for_attempt($attemptid, $userid);
}

/**
 * Bootstrap monitor AMD on attempt pages only (also called from LL Assessment arena).
 */
function local_nexproctor_bootstrap_on_attempt(): void {
    global $PAGE, $USER;

    if (empty($PAGE) || during_initial_install()) {
        return;
    }
    // Never run monitor on review / summary / view.
    if (!local_nexproctor_is_attempt_page()) {
        return;
    }

    static $done = false;
    if ($done) {
        return;
    }

    $cmid = local_nexproctor_resolve_cmid();
    if (!$cmid) {
        return;
    }
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }
    // Proctor everyone (including admins/teachers) the same as students.
    $settings = local_nexproctor_get_quiz_settings((int) $cm->instance);
    if (empty($settings->nexproctorenabled)) {
        return;
    }

    $done = true;
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    // Hide quiz chrome immediately (before AMD loads) so sensors gate first.
    $PAGE->requires->js_init_code("document.documentElement.classList.add('np-attempt-gated');document.body.classList.add('np-attempt-gated');");
    $PAGE->requires->css(new moodle_url('/local/nexproctor/styles/monitor.css'));
    $PAGE->requires->js_call_amd('local_nexproctor/monitor', 'init', [[
        'cmid' => $cmid,
        'quizid' => (int) $cm->instance,
        'attemptid' => $attemptid,
        'userid' => (int) $USER->id,
        'settings' => [
            'requirecamera' => (int) $settings->requirecamera,
            'requiremic' => (int) $settings->requiremic,
            'requirescreenshare' => (int) $settings->requirescreenshare,
            'requirefullscreen' => (int) $settings->requirefullscreen,
            'blockmultimonitor' => (int) $settings->blockmultimonitor,
            'detectfaces' => (int) $settings->detectfaces,
            'detectnoise' => (int) $settings->detectnoise,
            'detecttabswitch' => (int) $settings->detecttabswitch,
            'detectattention' => (int) $settings->detectattention,
            'heartbeatsecs' => (int) $settings->heartbeatsecs,
            'photoonviolation' => (int) $settings->photoonviolation,
        ],
        'strings' => [
            'cameraLost' => get_string('camera_lost', 'local_nexproctor'),
            'micLost' => get_string('mic_lost', 'local_nexproctor'),
            'screenLost' => get_string('screenshare_lost', 'local_nexproctor'),
            'deviceRequiredTitle' => get_string('device_required_title', 'local_nexproctor'),
            'deviceRequiredBody' => get_string('device_required_body', 'local_nexproctor'),
            'deviceRequiredHint' => get_string('device_required_hint', 'local_nexproctor'),
            'deviceRestoreAv' => get_string('device_restore_av', 'local_nexproctor'),
            'deviceRestoreScreen' => get_string('device_restore_screen', 'local_nexproctor'),
            'deviceRestoring' => get_string('device_restoring', 'local_nexproctor'),
            'deviceRestoringScreen' => get_string('device_restoring_screen', 'local_nexproctor'),
            'deviceRestoreFailed' => get_string('device_restore_failed', 'local_nexproctor'),
            'fullscreenHint' => get_string('fullscreen_hint', 'local_nexproctor'),
            'fullscreenRequired' => get_string('fullscreen_required_title', 'local_nexproctor'),
            'fullscreenRequiredBody' => get_string('fullscreen_required_body', 'local_nexproctor'),
            'fullscreenReturn' => get_string('fullscreen_return_btn', 'local_nexproctor'),
            'gateTitle' => get_string('gate_title', 'local_nexproctor'),
            'gateSub' => get_string('gate_sub', 'local_nexproctor'),
            'runningChecks' => get_string('running_checks', 'local_nexproctor'),
            'ready' => get_string('preflight_ready', 'local_nexproctor'),
            'retry' => get_string('fix_retry', 'local_nexproctor'),
            'needMic' => get_string('need_mic', 'local_nexproctor'),
            'needFace' => get_string('need_one_face', 'local_nexproctor'),
            'needFullscreen' => get_string('need_fullscreen', 'local_nexproctor'),
            'needScreen' => get_string('need_screen', 'local_nexproctor'),
            'multiMonitor' => get_string('multi_monitor_blocked', 'local_nexproctor'),
            'checkCamera' => get_string('check_camera', 'local_nexproctor'),
            'checkMic' => get_string('check_mic', 'local_nexproctor'),
            'checkScreen' => get_string('check_screen', 'local_nexproctor'),
            'checkFullscreen' => get_string('check_fullscreen', 'local_nexproctor'),
            'checkMonitor' => get_string('check_monitor', 'local_nexproctor'),
            'checkFace' => get_string('check_face', 'local_nexproctor'),
            'startAttempt' => get_string('start_attempt', 'local_nexproctor'),
            'permIntro' => get_string('perm_intro', 'local_nexproctor'),
            'permScreen' => get_string('perm_screen', 'local_nexproctor'),
            'permMic' => get_string('perm_mic', 'local_nexproctor'),
            'permCamera' => get_string('perm_camera', 'local_nexproctor'),
            'consentPermissions' => get_string('consent_permissions', 'local_nexproctor'),
            'consentValidation' => get_string('consent_validation', 'local_nexproctor'),
            'continueBtn' => get_string('continue_btn', 'local_nexproctor'),
            'webcamTitle' => get_string('webcam_title', 'local_nexproctor'),
            'webcamLead' => get_string('webcam_lead', 'local_nexproctor'),
            'webcamSub' => get_string('webcam_sub', 'local_nexproctor'),
            'checkingFace' => get_string('checking_face', 'local_nexproctor'),
            'faceOk' => get_string('face_ok', 'local_nexproctor'),
            'multiFace' => get_string('multi_face', 'local_nexproctor'),
            'setupTitle' => get_string('setup_title', 'local_nexproctor'),
            'setupWait' => get_string('setup_wait', 'local_nexproctor'),
            'shareScreenTitle' => get_string('share_screen_title', 'local_nexproctor'),
            'shareScreenIntro' => get_string('share_screen_intro', 'local_nexproctor'),
            'shareScreenHint' => get_string('share_screen_hint', 'local_nexproctor'),
            'fsModalTitle' => get_string('fs_modal_title', 'local_nexproctor'),
            'fsModalBody' => get_string('fs_modal_body', 'local_nexproctor'),
            'fsModalBtn' => get_string('fs_modal_btn', 'local_nexproctor'),
            'requestingAv' => get_string('requesting_av', 'local_nexproctor'),
            'needCamera' => get_string('need_camera', 'local_nexproctor'),
            'cancelled' => get_string('start_cancelled', 'local_nexproctor'),
            'back' => get_string('back', 'local_nexproctor'),
            'resuming' => get_string('resuming_proctoring', 'local_nexproctor'),
        ],
    ]]);
}

/**
 * Bootstrap Overview / Proctoring tabs on quiz review.
 */
function local_nexproctor_bootstrap_on_review(): void {
    global $PAGE;

    if (empty($PAGE) || during_initial_install()) {
        return;
    }
    if (!local_nexproctor_is_review_page()) {
        return;
    }

    static $done = false;
    if ($done) {
        return;
    }

    $cmid = local_nexproctor_resolve_cmid();
    if (!$cmid) {
        return;
    }
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }
    $settings = local_nexproctor_get_quiz_settings((int) $cm->instance);
    if (empty($settings->nexproctorenabled)) {
        return;
    }

    $done = true;
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    $PAGE->requires->css(new moodle_url('/local/nexproctor/styles/monitor.css'));
    $PAGE->requires->js_call_amd('local_nexproctor/review_tabs', 'init', [[
        'cmid' => $cmid,
        'quizid' => (int) $cm->instance,
        'attemptid' => $attemptid,
        'strings' => [
            'overview' => get_string('tab_overview', 'local_nexproctor'),
            'proctoring' => get_string('tab_proctoring', 'local_nexproctor'),
            'trustscore' => get_string('trustscore_label', 'local_nexproctor'),
            'openReport' => get_string('open_report', 'local_nexproctor'),
            'status' => get_string('status', 'local_nexproctor'),
            'started' => get_string('started', 'local_nexproctor'),
            'ended' => get_string('ended', 'local_nexproctor'),
            'violations' => get_string('violations', 'local_nexproctor'),
            'noSession' => get_string('nosession_for_attempt', 'local_nexproctor'),
            'event' => get_string('event', 'local_nexproctor'),
            'severity' => get_string('severity', 'local_nexproctor'),
            'penalty' => get_string('penalty', 'local_nexproctor'),
            'time' => get_string('time', 'local_nexproctor'),
        ],
    ]]);
}

/**
 * Pluginfile callback.
 */
function local_nexproctor_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    global $DB;

    $allowed = ['snapshot', 'screengrab', 'audioclip', 'prestart'];
    if (!in_array($filearea, $allowed, true)) {
        return false;
    }
    require_login();
    global $USER, $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    // Teachers with report cap, or the student who owns the evidence session.
    $canview = has_capability('local/nexproctor:viewreport', $context);
    if (!$canview) {
        $evrow = $DB->get_record('local_nexproctor_evidence', [
            'itemid' => $itemid,
            'filearea' => $filearea,
        ], 'id, sessionid', IGNORE_MISSING);
        // Legacy files used sessionid as itemid.
        $sessionid = $evrow ? (int) $evrow->sessionid : $itemid;
        $session = $DB->get_record('local_nexproctor_sessions', ['id' => $sessionid], 'id, userid', IGNORE_MISSING);
        if (!$session || (int) $session->userid !== (int) $USER->id) {
            return false;
        }
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_nexproctor', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Early page hooks — review tabs without relying on LL Assessment.
 */
function local_nexproctor_before_http_headers(): void {
    local_nexproctor_bootstrap_on_review();
}

/**
 * Add report link on quiz module navigation for teachers.
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_nexproctor_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;
    if ($context->contextlevel !== CONTEXT_MODULE || empty($PAGE->cm) || $PAGE->cm->modname !== 'quiz') {
        return;
    }
    if (!has_capability('local/nexproctor:viewreport', $context)) {
        return;
    }
    $settings = local_nexproctor_get_quiz_settings((int) $PAGE->cm->instance);
    if (empty($settings->nexproctorenabled)) {
        return;
    }
    if ($quiznode = $nav->find('modulesettings', navigation_node::TYPE_SETTING)) {
        $quiznode->add(
            get_string('report_title', 'local_nexproctor'),
            new moodle_url('/local/nexproctor/report.php', ['cmid' => $PAGE->cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_nexproctor_report'
        );
    }
}
