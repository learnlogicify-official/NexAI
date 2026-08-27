<?php
namespace mod_nexinterview\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/nexinterview/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_nexinterview\local\attempts;

/**
 * AJAX proxy to interview-service for a course NexInterview activity.
 */
class proxy extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action'),
            'sessionid' => new external_value(PARAM_TEXT, 'Session id', VALUE_DEFAULT, ''),
            'message' => new external_value(PARAM_RAW, 'Message / TTS text', VALUE_DEFAULT, ''),
            'code' => new external_value(PARAM_RAW, 'Source code', VALUE_DEFAULT, ''),
            'mode' => new external_value(PARAM_ALPHANUMEXT, 'Run mode', VALUE_DEFAULT, 'sample'),
            'roletrack' => new external_value(PARAM_ALPHANUMEXT, 'Role track', VALUE_DEFAULT, 'sde_intern'),
            'topics' => new external_value(PARAM_RAW, 'Topics CSV', VALUE_DEFAULT, ''),
            'resume' => new external_value(PARAM_RAW, 'Resume text', VALUE_DEFAULT, ''),
            'problemid' => new external_value(PARAM_INT, 'NexPractice problem id', VALUE_DEFAULT, 0),
            'audio' => new external_value(PARAM_RAW, 'Base64 audio for STT', VALUE_DEFAULT, ''),
            'durationsec' => new external_value(PARAM_FLOAT, 'Utterance duration seconds', VALUE_DEFAULT, 0),
            'interviewerid' => new external_value(PARAM_INT, 'Custom interviewer id', VALUE_DEFAULT, 0),
            'durationminutes' => new external_value(PARAM_INT, 'Activity duration override', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        $cmid,
        $action,
        $sessionid = '',
        $message = '',
        $code = '',
        $mode = 'sample',
        $roletrack = 'sde_intern',
        $topics = '',
        $resume = '',
        $problemid = 0,
        $audio = '',
        $durationsec = 0,
        $interviewerid = 0,
        $durationminutes = 0
    ) {
        global $USER, $DB, $CFG;

        require_once($CFG->dirroot . '/local/nexinterview/lib.php');

        $resume = (string) $resume;
        if (str_starts_with($resume, 'b64:')) {
            $decoded = base64_decode(substr($resume, 4), true);
            $resume = is_string($decoded) ? $decoded : '';
        }

        $message = \local_nexinterview\local\resume::normalize((string) $message);
        $code = \local_nexinterview\local\resume::fix_utf8((string) $code);
        $resume = \local_nexinterview\local\resume::normalize($resume);
        $topics = \local_nexinterview\local\resume::fix_utf8((string) $topics);
        $audio = preg_replace('/\s+/', '', (string) $audio) ?? '';
        $audio = preg_replace('/[^A-Za-z0-9+\/=]/', '', $audio) ?? '';
        $sessionid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $sessionid) ?? '';
        $action = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $action) ?? '');
        $mode = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $mode) ?? '') ?: 'sample';
        $roletrack = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $roletrack) ?? '';
        if ($roletrack === '') {
            $roletrack = 'sde_intern';
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => (int) $cmid,
            'action' => $action,
            'sessionid' => $sessionid,
            'message' => $message,
            'code' => $code,
            'mode' => $mode,
            'roletrack' => $roletrack,
            'topics' => $topics,
            'resume' => $resume,
            'problemid' => (int) $problemid,
            'audio' => $audio,
            'durationsec' => max(0.0, (float) $durationsec),
            'interviewerid' => max(0, (int) $interviewerid),
            'durationminutes' => max(0, (int) $durationminutes),
        ]);

        $cm = get_coursemodule_from_id('nexinterview', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/nexinterview:attempt', $context);

        $client = new \local_nexinterview\local\client();
        if (!$client->configured()) {
            throw new \moodle_exception(
                'noservice',
                'nexinterview',
                '',
                get_string('servicenotconfigured', 'nexinterview')
            );
        }

        $action = $params['action'];
        $view = null;
        $extra = [];
        $profile = nexinterview_resolve_profile($instance);
        if (empty($profile['ok'])) {
            throw new \moodle_exception('noprofilebound', 'nexinterview');
        }

        if ($action === 'start') {
            nexinterview_require_open($instance);
            $used = attempts::count_for_user((int) $instance->id, (int) $USER->id);
            if ($used >= (int) $instance->maxattempts) {
                throw new \moodle_exception('attemptslimit', 'nexinterview');
            }
            $latest = attempts::latest((int) $instance->id, (int) $USER->id);
            if ($latest && $latest->status === 'inprogress' && $latest->sessionid !== '') {
                $view = $client->get($latest->sessionid);
            } else {
                $duration = nexinterview_effective_duration($instance, $profile);
                if ((int) $params['durationminutes'] > 0) {
                    $duration = max(10, min(45, (int) $params['durationminutes']));
                }

                $role = (string) $profile['roletrack'];
                $interviewer = $profile['interviewer'];
                if ($interviewer) {
                    $payload = \local_nexinterview\local\interviewers::service_payload($interviewer);
                    $payload['duration_minutes'] = $duration;
                    if (!empty($payload['qa_minutes'])) {
                        $payload['qa_minutes'] = min((int) $payload['qa_minutes'], $duration - 2);
                    }
                    $role = (string) ($payload['role_track'] ?? $role);
                    $startpayload = array_merge([
                        'moodle_user_id' => (int) $USER->id,
                        'moodle_cm_id' => (int) $cm->id,
                        'moodle_instance_id' => (int) $instance->id,
                        'student_name' => fullname($USER),
                        'resume_text' => (string) $params['resume'],
                    ], $payload);
                } else {
                    $defaults = \local_nexinterview\local\interviewers::default_topics_for_track($role);
                    $topiccsv = (string) $profile['topics'];
                    $topicslist = array_values(array_filter(array_map('trim', explode(',', $topiccsv))));
                    if (!$topicslist) {
                        $topicslist = $defaults;
                    }
                    $includecoding = !local_nexinterview_is_resume_track($role);
                    $startpayload = [
                        'moodle_user_id' => (int) $USER->id,
                        'moodle_cm_id' => (int) $cm->id,
                        'moodle_instance_id' => (int) $instance->id,
                        'student_name' => fullname($USER),
                        'role_track' => $role,
                        'duration_minutes' => $duration,
                        'topics' => $topicslist,
                        'resume_text' => (string) $params['resume'],
                        'interviewer_name' => 'NexAI',
                        'interviewer_style' => local_nexinterview_is_resume_track($role) ? 'strict' : 'friendly',
                        'interviewer_briefing' => '',
                        'include_coding' => $includecoding,
                        'moodle_interviewer_id' => 0,
                    ];
                }

                if (local_nexinterview_is_resume_track($role)) {
                    $startpayload['include_coding'] = false;
                    $startpayload['interviewer_style'] = 'strict';
                    if (strlen((string) $startpayload['resume_text']) < 80) {
                        throw new \moodle_exception('needresume_deep', 'local_nexinterview');
                    }
                }

                $picked = ['id' => 0, 'name' => ''];
                $pid = 0;
                if (!empty($startpayload['include_coding'])) {
                    $picked = \local_nexinterview\local\problems::pick_unsolved_for_user((int) $USER->id, $role);
                    $pid = (int) ($picked['id'] ?? 0);
                    if ($pid <= 0) {
                        $pid = (int) $params['problemid'];
                    }
                    \local_nexinterview\local\problems::remember_assigned((int) $USER->id, $pid);
                }
                $startpayload['moodle_problem_id'] = $pid;
                $startpayload['moodle_problem_title'] = (string) ($picked['name'] ?? '');

                // End prior hub/activity in-progress sessions for this user.
                \local_nexinterview\local\attempts::abandon_all_inprogress((int) $USER->id, $client);
                $prev = attempts::latest((int) $instance->id, (int) $USER->id);
                if ($prev && $prev->status === 'inprogress') {
                    attempts::mark_abandoned((int) $prev->id);
                }

                $view = $client->start($startpayload);
                attempts::create((int) $instance->id, (int) $USER->id, (string) $view['session_id']);
                \local_nexinterview\local\attempts::create((int) $USER->id, (string) $view['session_id'], $role);
                $extra['moodle_problem_id'] = $pid;
                $extra['moodle_problem_title'] = (string) ($picked['name'] ?? '');
                $extra['interviewerid'] = (int) ($profile['interviewerid'] ?? 0);
            }
        } else if (in_array($action, [
            'message', 'snapshot', 'run', 'end', 'get', 'tts', 'stt',
            'realtime_token', 'gladia_live', 'coding_result',
        ], true)) {
            self::require_session_owner((string) $params['sessionid'], (int) $USER->id, (int) $instance->id);
            if ($action === 'message') {
                $view = $client->message($params['sessionid'], $params['message'], (float) $params['durationsec']);
            } else if ($action === 'snapshot') {
                $view = $client->snapshot($params['sessionid'], $params['code'], 'autosave');
            } else if ($action === 'run') {
                $payload = $client->run($params['sessionid'], $params['code'], $params['mode'] ?: 'sample');
                $view = $payload['session'] ?? $payload;
                $extra['result'] = $payload['result'] ?? null;
            } else if ($action === 'end') {
                $view = $client->end($params['sessionid']);
            } else if ($action === 'get') {
                $view = $client->get($params['sessionid']);
            } else if ($action === 'tts') {
                $tts = $client->tts($params['sessionid'], $params['message']);
                return [
                    'ok' => true,
                    'payload' => json_encode(['tts' => $tts]),
                ];
            } else if ($action === 'stt') {
                $lang = (string) (get_config('local_nexinterview', 'voicelang') ?: 'en');
                $stt = $client->stt(
                    $params['sessionid'],
                    (string) $params['audio'],
                    $params['message'] !== '' ? $params['message'] : 'audio.webm',
                    $lang
                );
                return [
                    'ok' => true,
                    'payload' => json_encode(['stt' => $stt]),
                ];
            } else if ($action === 'realtime_token') {
                $token = $client->realtime_token($params['sessionid']);
                return [
                    'ok' => true,
                    'payload' => json_encode(['realtime' => $token]),
                ];
            } else if ($action === 'gladia_live') {
                $lang = (string) (get_config('local_nexinterview', 'voicelang') ?: 'en');
                $live = $client->gladia_live($params['sessionid'], $lang, 16000);
                return [
                    'ok' => true,
                    'payload' => json_encode(['gladia' => $live]),
                ];
            } else if ($action === 'coding_result') {
                $parts = explode('/', (string) $params['message']);
                $passed = isset($parts[0]) ? (int) $parts[0] : 0;
                $total = isset($parts[1]) ? (int) $parts[1] : 0;
                $allpassed = ($params['mode'] === 'allpassed') || ($total > 0 && $passed >= $total);
                $view = $client->coding_result(
                    $params['sessionid'],
                    $passed,
                    $total,
                    $allpassed,
                    (int) $params['problemid']
                );
                $ui = is_array($view['ui'] ?? null) ? $view['ui'] : [];
                if (!empty($ui['need_next_problem'])) {
                    $exclude = [];
                    if (!empty($ui['used_moodle_problems']) && is_array($ui['used_moodle_problems'])) {
                        foreach ($ui['used_moodle_problems'] as $xid) {
                            $exclude[] = (int) $xid;
                        }
                    }
                    $role = (string) ($profile['roletrack'] ?? $params['roletrack']);
                    $picked = \local_nexinterview\local\problems::pick_unsolved_for_user(
                        (int) $USER->id,
                        $role,
                        $exclude
                    );
                    $npid = (int) ($picked['id'] ?? 0);
                    if ($npid > 0) {
                        \local_nexinterview\local\problems::remember_assigned((int) $USER->id, $npid);
                        $view = $client->assign_problem(
                            $params['sessionid'],
                            $npid,
                            (string) ($picked['name'] ?? '')
                        );
                        $extra['moodle_problem_id'] = $npid;
                        $extra['moodle_problem_title'] = (string) ($picked['name'] ?? '');
                    } else {
                        $view = $client->end($params['sessionid']);
                    }
                }
            }
        } else {
            throw new \invalid_parameter_exception('Unknown action');
        }

        if (is_array($view)) {
            attempts::sync_completed($instance, $view);
            \local_nexinterview\local\attempts::sync_completed($view);
        }

        return [
            'ok' => true,
            'payload' => json_encode(['session' => $view, 'extra' => $extra]),
        ];
    }

    private static function require_session_owner(string $sessionid, int $userid, int $activityid): void {
        if ($sessionid === '') {
            throw new \moodle_exception('nopermissions', 'error', '', 'session');
        }
        $attempt = attempts::by_session($sessionid);
        if (!$attempt || (int) $attempt->activityid !== $activityid) {
            throw new \moodle_exception('nopermissions', 'error', '', 'session');
        }
        if ((int) $attempt->userid !== $userid) {
            throw new \moodle_exception('nopermissions', 'error', '', 'session');
        }
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'payload' => new external_value(PARAM_RAW, 'JSON payload'),
        ]);
    }
}
