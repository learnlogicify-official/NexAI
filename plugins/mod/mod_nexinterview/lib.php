<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for mod_nexinterview.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param string $feature
 * @return bool|string|null
 */
function nexinterview_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Normalize profile fields from form / DB row.
 */
function nexinterview_normalize_profile_fields(stdClass $data): void {
    $data->interviewerid = (int) ($data->interviewerid ?? 0);
    $track = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data->roletrack ?? '')) ?? '';
    if ($data->interviewerid > 0) {
        // Custom interviewer wins; roletrack is resolved from the profile at runtime.
        $data->roletrack = '';
    } else {
        $allowed = [];
        if (class_exists('\\local_nexinterview\\local\\interviewers')) {
            $allowed = \local_nexinterview\local\interviewers::ROLE_TRACKS;
        }
        if ($track === '' || ($allowed && !in_array($track, $allowed, true))) {
            $track = 'sde_intern';
        }
        $data->roletrack = $track;
    }
    $data->durationminutes = max(10, min(45, (int) ($data->durationminutes ?? 17)));
    $data->maxattempts = max(1, min(20, (int) ($data->maxattempts ?? 3)));
    $data->timeopen = max(0, (int) ($data->timeopen ?? 0));
    $data->timeclose = max(0, (int) ($data->timeclose ?? 0));
}

/**
 * @param stdClass $data
 * @param mod_nexinterview_mod_form|null $mform
 * @return int
 */
function nexinterview_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    nexinterview_normalize_profile_fields($data);
    $data->id = $DB->insert_record('nexinterview', $data);
    nexinterview_grade_item_update($data);
    return (int) $data->id;
}

/**
 * @param stdClass $data
 * @param mod_nexinterview_mod_form|null $mform
 * @return bool
 */
function nexinterview_update_instance(stdClass $data, $mform = null): bool {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    nexinterview_normalize_profile_fields($data);
    $DB->update_record('nexinterview', $data);
    nexinterview_grade_item_update($data);
    return true;
}

/**
 * @param int $id
 * @return bool
 */
function nexinterview_delete_instance(int $id): bool {
    global $DB;
    if (!$instance = $DB->get_record('nexinterview', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('nexinterview_attempts', ['activityid' => $id]);
    $DB->delete_records('nexinterview', ['id' => $id]);
    nexinterview_grade_item_delete($instance);
    return true;
}

/**
 * @param stdClass $instance
 * @param mixed $grades
 * @return int
 */
function nexinterview_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $instance->name,
        'idnumber' => $instance->cmidnumber ?? '',
    ];

    if (!empty($instance->grade) && (int) $instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = (float) $instance->grade;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/nexinterview',
        $instance->course,
        'mod',
        'nexinterview',
        $instance->id,
        0,
        $grades,
        $params
    );
}

/**
 * @param stdClass $instance
 * @return int
 */
function nexinterview_grade_item_delete(stdClass $instance): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/nexinterview',
        $instance->course,
        'mod',
        'nexinterview',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Push overall score into gradebook (0–grademax).
 *
 * @param stdClass $instance
 * @param int $userid
 * @param float $percent 0–100
 */
function nexinterview_update_grades(stdClass $instance, int $userid, float $percent): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $max = !empty($instance->grade) ? (float) $instance->grade : 100.0;
    $grade = (object) [
        'userid' => $userid,
        'rawgrade' => max(0, min($max, ($percent / 100.0) * $max)),
    ];
    nexinterview_grade_item_update($instance, $grade);
}

/**
 * Resolve the custom interviewer bound to this activity (null for default tracks).
 *
 * @return stdClass|null
 */
function nexinterview_get_interviewer(stdClass $instance): ?stdClass {
    $id = (int) ($instance->interviewerid ?? 0);
    if ($id <= 0 || !class_exists('\\local_nexinterview\\local\\interviewers')) {
        return null;
    }
    $row = \local_nexinterview\local\interviewers::get($id);
    if (!$row || !(int) $row->enabled) {
        return null;
    }
    return $row;
}

/**
 * Built-in track meta for the activity (when no custom interviewer).
 *
 * @return array|null
 */
function nexinterview_get_track_meta(string $roletrack): ?array {
    global $CFG;
    if (!function_exists('local_nexinterview_tracks')) {
        require_once($CFG->dirroot . '/local/nexinterview/lib.php');
    }
    foreach (local_nexinterview_tracks() as $t) {
        if (($t['id'] ?? '') === $roletrack) {
            return $t;
        }
    }
    return null;
}

/**
 * Resolved interview profile for start / room / view.
 *
 * @return array{
 *   ok:bool,
 *   interviewer:?stdClass,
 *   interviewerid:int,
 *   roletrack:string,
 *   topics:string,
 *   title:string,
 *   includecoding:bool,
 *   durationdefault:int
 * }
 */
function nexinterview_resolve_profile(stdClass $instance): array {
    global $CFG;
    require_once($CFG->dirroot . '/local/nexinterview/lib.php');

    $interviewer = nexinterview_get_interviewer($instance);
    if ($interviewer) {
        $mins = max(10, min(45, (int) $interviewer->durationminutes));
        return [
            'ok' => true,
            'interviewer' => $interviewer,
            'interviewerid' => (int) $interviewer->id,
            'roletrack' => (string) $interviewer->roletrack,
            'topics' => (string) $interviewer->topics,
            'title' => (string) $interviewer->name,
            'includecoding' => (bool) ((int) $interviewer->includecoding),
            'durationdefault' => $mins,
        ];
    }

    $track = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($instance->roletrack ?? '')) ?? '';
    $meta = $track !== '' ? nexinterview_get_track_meta($track) : null;
    if (!$meta) {
        $track = 'sde_intern';
        $meta = nexinterview_get_track_meta($track);
    }
    if (!$meta) {
        return [
            'ok' => false,
            'interviewer' => null,
            'interviewerid' => 0,
            'roletrack' => '',
            'topics' => '',
            'title' => '',
            'includecoding' => false,
            'durationdefault' => 17,
        ];
    }

    $isresume = local_nexinterview_is_resume_track($track);
    return [
        'ok' => true,
        'interviewer' => null,
        'interviewerid' => 0,
        'roletrack' => $track,
        'topics' => (string) ($meta['topics'] ?? ''),
        'title' => (string) ($meta['title'] ?? $track),
        'includecoding' => !$isresume,
        'durationdefault' => $isresume ? 20 : 17,
    ];
}

/**
 * Whether the activity has a usable profile (custom interviewer or default track).
 */
function nexinterview_has_profile(stdClass $instance): bool {
    $profile = nexinterview_resolve_profile($instance);
    return !empty($profile['ok']);
}

/**
 * Effective duration for this activity (activity override wins).
 */
function nexinterview_effective_duration(stdClass $instance, ?array $profile = null): int {
    $mins = (int) ($instance->durationminutes ?? 0);
    if ($mins <= 0) {
        if ($profile === null) {
            $profile = nexinterview_resolve_profile($instance);
        }
        $mins = (int) ($profile['durationdefault'] ?? 17);
    }
    return max(10, min(45, $mins ?: 17));
}

/**
 * Availability window for taking the interview.
 *
 * @return array{open:bool, reason:string, timeopen:int, timeclose:int}
 */
function nexinterview_availability(stdClass $instance, ?int $now = null): array {
    $now = $now ?? time();
    $open = max(0, (int) ($instance->timeopen ?? 0));
    $close = max(0, (int) ($instance->timeclose ?? 0));

    if ($open > 0 && $now < $open) {
        return [
            'open' => false,
            'reason' => 'notopenyet',
            'timeopen' => $open,
            'timeclose' => $close,
        ];
    }
    if ($close > 0 && $now > $close) {
        return [
            'open' => false,
            'reason' => 'closed',
            'timeopen' => $open,
            'timeclose' => $close,
        ];
    }
    return [
        'open' => true,
        'reason' => '',
        'timeopen' => $open,
        'timeclose' => $close,
    ];
}

/**
 * Throw if the interview window is closed (teachers with reports cap can still open view).
 */
function nexinterview_require_open(stdClass $instance): void {
    $avail = nexinterview_availability($instance);
    if (!empty($avail['open'])) {
        return;
    }
    if ($avail['reason'] === 'notopenyet') {
        throw new moodle_exception(
            'notopenyet',
            'nexinterview',
            '',
            userdate($avail['timeopen'])
        );
    }
    throw new moodle_exception(
        'interviewclosed',
        'nexinterview',
        '',
        userdate($avail['timeclose'])
    );
}

/**
 * Mustache context for the activity landing (standalone view + NexCoursePro pane).
 *
 * @param stdClass $cm Course module (id, course, instance at minimum)
 * @param stdClass|null $instance Activity record; loaded when null
 * @param array $options inpane=true hides the activity hero chrome for Pro shell
 * @return array
 */
function nexinterview_export_view_context(stdClass $cm, ?stdClass $instance = null, array $options = []): array {
    global $DB, $USER;

    if ($instance === null) {
        $instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    $context = context_module::instance($cm->id);
    $client = new \local_nexinterview\local\client();
    $configured = $client->configured();
    $probe = $configured ? $client->probe() : [
        'ok' => false,
        'message' => get_string('servicenotconfigured', 'nexinterview'),
    ];
    $serviceok = !empty($probe['ok']);

    $profile = nexinterview_resolve_profile($instance);
    $duration = nexinterview_effective_duration($instance, $profile);
    $used = \mod_nexinterview\local\attempts::count_for_user((int) $instance->id, (int) $USER->id);
    $avail = nexinterview_availability($instance);
    $windowopen = !empty($avail['open']);
    $canattempt = has_capability('mod/nexinterview:attempt', $context)
        && !empty($profile['ok'])
        && $windowopen
        && $used < (int) $instance->maxattempts;
    $canreports = has_capability('mod/nexinterview:viewreports', $context);

    $starturl = (new moodle_url('/mod/nexinterview/start.php', ['id' => $cm->id]))->out(false);

    $attempts = $DB->get_records('nexinterview_attempts', [
        'activityid' => $instance->id,
        'userid' => $USER->id,
    ], 'id DESC');

    $attemptrows = [];
    foreach ($attempts as $a) {
        if ((string) $a->status === 'abandoned') {
            continue;
        }
        $continueurl = '';
        if ($a->status === 'inprogress' && $windowopen) {
            $continueurl = (new moodle_url('/mod/nexinterview/room.php', [
                'id' => $cm->id,
                'sessionid' => $a->sessionid,
            ]))->out(false);
        }
        $attemptrows[] = [
            'attemptno' => $a->attemptno,
            'status' => get_string('status_' . $a->status, 'nexinterview'),
            'score' => $a->status === 'completed' ? format_float((float) $a->overallscore, 1) : '—',
            'feedbackurl' => ($a->status === 'completed')
                ? (new moodle_url('/mod/nexinterview/report.php', ['id' => $cm->id, 'attemptid' => $a->id]))->out(false)
                : '',
            'continueurl' => $continueurl,
        ];
    }

    $windowlabel = '';
    if (!empty($avail['timeopen']) || !empty($avail['timeclose'])) {
        $from = !empty($avail['timeopen']) ? userdate($avail['timeopen']) : get_string('now', 'nexinterview');
        $until = !empty($avail['timeclose']) ? userdate($avail['timeclose']) : get_string('nolimit', 'nexinterview');
        $windowlabel = get_string('availabilitywindow', 'nexinterview', (object) [
            'from' => $from,
            'until' => $until,
        ]);
    }

    $closedmessage = '';
    if (!$windowopen) {
        if ($avail['reason'] === 'notopenyet') {
            $closedmessage = get_string('notopenyet', 'nexinterview', userdate($avail['timeopen']));
        } else {
            $closedmessage = get_string('interviewclosed', 'nexinterview', userdate($avail['timeclose']));
        }
    }

    $inpane = !empty($options['inpane']);

    return [
        'name' => format_string($instance->name),
        'intro' => format_module_intro('nexinterview', $instance, $cm->id),
        'readyblurb' => get_string('readyblurb', 'nexinterview'),
        'duration' => $duration,
        'profilename' => !empty($profile['ok']) ? format_string($profile['title']) : '',
        'topics' => !empty($profile['ok']) ? s($profile['topics']) : '',
        'hasprofile' => !empty($profile['ok']),
        'serviceok' => $serviceok,
        'servicemessage' => $serviceok
            ? ''
            : get_string('noservice', 'nexinterview', $probe['message'] ?? ''),
        'canattempt' => $canattempt && $serviceok,
        'starturl' => $starturl,
        'hasattempts' => !empty($attemptrows),
        'attempts' => $attemptrows,
        'canreports' => $canreports,
        'reportsurl' => (new moodle_url('/mod/nexinterview/reports.php', ['id' => $cm->id]))->out(false),
        'attemptslimit' => !$canattempt && $serviceok && !empty($profile['ok']) && $windowopen
            && $used >= (int) $instance->maxattempts,
        'noprofile' => empty($profile['ok']),
        'haswindow' => $windowlabel !== '',
        'windowlabel' => $windowlabel,
        'windowclosed' => !$windowopen,
        'closedmessage' => $closedmessage,
        'inpane' => $inpane,
        // Hide inner Start when Pro shell owns the CTA.
        'showstartbutton' => !$inpane && $canattempt && $serviceok,
    ];
}
