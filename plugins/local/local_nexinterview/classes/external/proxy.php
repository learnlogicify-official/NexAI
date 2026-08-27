<?php
namespace local_nexinterview\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');
require_once(__DIR__ . '/../../lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_nexinterview\local\attempts;
use local_nexinterview\local\client;
use local_nexinterview\local\problems;

/**
 * AJAX proxy to interview-service.
 */
class proxy extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action'),
            'sessionid' => new external_value(PARAM_TEXT, 'Session id', VALUE_DEFAULT, ''),
            'message' => new external_value(PARAM_RAW, 'Message / TTS text', VALUE_DEFAULT, ''),
            'code' => new external_value(PARAM_RAW, 'Source code', VALUE_DEFAULT, ''),
            'mode' => new external_value(PARAM_ALPHANUMEXT, 'Run mode', VALUE_DEFAULT, 'sample'),
            'roletrack' => new external_value(PARAM_ALPHANUMEXT, 'Role track', VALUE_DEFAULT, 'sde_intern'),
            'topics' => new external_value(PARAM_RAW, 'Topics CSV', VALUE_DEFAULT, ''),
            'resume' => new external_value(PARAM_RAW, 'Resume text (plain or base64: prefix)', VALUE_DEFAULT, ''),
            'problemid' => new external_value(PARAM_INT, 'NexPractice problem id', VALUE_DEFAULT, 0),
            'audio' => new external_value(PARAM_RAW, 'Base64 audio for STT', VALUE_DEFAULT, ''),
            'durationsec' => new external_value(PARAM_FLOAT, 'Utterance duration seconds', VALUE_DEFAULT, 0),
            'interviewerid' => new external_value(PARAM_INT, 'Custom interviewer id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
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
        $interviewerid = 0
    ) {
        global $USER;

        // Decode base64 resume early (ASCII-safe over the wire).
        $resume = (string) $resume;
        if (str_starts_with($resume, 'b64:')) {
            $decoded = base64_decode(substr($resume, 4), true);
            $resume = is_string($decoded) ? $decoded : '';
        }

        // Sanitize UTF-8 before Moodle PARAM validation (binary resume scrapes often break PARAM_RAW).
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
        $durationsec = max(0.0, (float) $durationsec);
        $interviewerid = max(0, (int) $interviewerid);

        $params = self::validate_parameters(self::execute_parameters(), [
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
            'durationsec' => $durationsec,
            'interviewerid' => $interviewerid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexinterview:attempt', $context);

        $client = new client();
        if (!$client->configured()) {
            throw new \moodle_exception('noservice', 'local_nexinterview', '',
                get_string('servicenotconfigured', 'local_nexinterview'));
        }

        $action = $params['action'];
        $view = null;
        $extra = [];

        if ($action === 'start') {
            $role = $params['roletrack'] !== '' ? $params['roletrack'] : 'sde_intern';
            $defaults = \local_nexinterview\local\interviewers::default_topics_for_track($role);
            $topiccsv = $params['topics'] !== '' ? $params['topics'] : implode(',', $defaults);
            if (local_nexinterview_is_resume_track($role) && $params['topics'] === '') {
                $topiccsv = implode(',', $defaults);
            }
            $topicslist = array_values(array_filter(array_map('trim', explode(',', $topiccsv))));
            $duration = (int) (get_config('local_nexinterview', 'durationminutes') ?: 17);
            if (local_nexinterview_is_resume_track($role)) {
                $duration = max($duration, 20);
            }
            if ($duration < 10 || $duration > 45) {
                $duration = local_nexinterview_is_resume_track($role) ? 20 : 17;
            }

            $includecoding = !local_nexinterview_is_resume_track($role);
            $startpayload = [
                'moodle_user_id' => (int) $USER->id,
                'moodle_cm_id' => 0,
                'moodle_instance_id' => 0,
                'student_name' => fullname($USER),
                'role_track' => $role,
                'duration_minutes' => $duration,
                'topics' => $topicslist ?: $defaults,
                'resume_text' => (string) $params['resume'],
                'interviewer_name' => 'NexAI',
                'interviewer_style' => local_nexinterview_is_resume_track($role) ? 'strict' : 'friendly',
                'interviewer_briefing' => '',
                'include_coding' => $includecoding,
                'moodle_interviewer_id' => 0,
            ];

            $custom = null;
            if ((int) $params['interviewerid'] > 0) {
                $custom = \local_nexinterview\local\interviewers::get((int) $params['interviewerid']);
            }
            if ($custom && (int) $custom->enabled) {
                $startpayload = array_merge($startpayload, \local_nexinterview\local\interviewers::service_payload($custom));
                $role = (string) $startpayload['role_track'];
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
                $picked = problems::pick_unsolved_for_user((int) $USER->id, $role);
                $pid = (int) ($picked['id'] ?? 0);
                if ($pid <= 0) {
                    $pid = (int) $params['problemid'];
                }
                problems::remember_assigned((int) $USER->id, $pid);
            }
            $startpayload['moodle_problem_id'] = $pid;
            $startpayload['moodle_problem_title'] = (string) ($picked['name'] ?? '');

            // Starting a new interview ends any previous in-progress session for this user.
            attempts::abandon_all_inprogress((int) $USER->id, $client);

            $view = $client->start($startpayload);
            attempts::create((int) $USER->id, (string) $view['session_id'], $role);
            $extra['moodle_problem_id'] = $pid;
            $extra['moodle_problem_title'] = (string) ($picked['name'] ?? '');
            $extra['interviewerid'] = (int) ($startpayload['moodle_interviewer_id'] ?? 0);
        } else if ($action === 'message' || $action === 'snapshot' || $action === 'run'
                || $action === 'end' || $action === 'get' || $action === 'tts'
                || $action === 'stt' || $action === 'realtime_token' || $action === 'gladia_live'
                || $action === 'coding_result') {
            self::require_session_owner((string) $params['sessionid'], (int) $USER->id);
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
                // message = "passed/total" (e.g. 3/3), mode = allpassed|failed
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
                    $picked = problems::pick_unsolved_for_user((int) $USER->id, $params['roletrack'], $exclude);
                    $npid = (int) ($picked['id'] ?? 0);
                    if ($npid > 0) {
                        problems::remember_assigned((int) $USER->id, $npid);
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
            attempts::sync_completed($view);
        }

        return [
            'ok' => true,
            'payload' => json_encode(['session' => $view, 'extra' => $extra]),
        ];
    }

    /**
     * Ensure the Moodle attempt for this session belongs to the current user.
     *
     * @param string $sessionid
     * @param int $userid
     */
    private static function require_session_owner(string $sessionid, int $userid): void {
        if ($sessionid === '') {
            throw new \moodle_exception('nopermissions', 'error', '', 'session');
        }
        $attempt = attempts::get_by_session($sessionid);
        if (!$attempt) {
            // Brand-new remote session may not be mirrored yet; only allow after create.
            throw new \moodle_exception('nopermissions', 'error', '', 'session');
        }
        if ((int) $attempt->userid !== $userid) {
            $sysctx = \context_system::instance();
            if (!has_capability('local/nexinterview:viewallreports', $sysctx) && !is_siteadmin()) {
                throw new \moodle_exception('nopermissions', 'error', '', 'session');
            }
        }
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'payload' => new external_value(PARAM_RAW, 'JSON payload'),
        ]);
    }
}
