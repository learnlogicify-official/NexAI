<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * Session summary for AutoProctor-style review UI.
 */
class get_session_summary extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
        ]);
    }

    /**
     * Build pluginfile URL for evidence row.
     */
    protected static function evidence_url(int $contextid, string $filearea, int $itemid, string $filename): string {
        $url = \moodle_url::make_pluginfile_url(
            $contextid,
            'local_nexproctor',
            $filearea,
            $itemid,
            '/',
            $filename
        );
        return $url->out(false);
    }

    public static function execute(int $cmid, int $attemptid): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'attemptid' => $attemptid,
        ]);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $canview = has_capability('local/nexproctor:viewreport', $context);
        if (!$canview && (int) $attempt->userid !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $empty = [
            'found' => false,
            'sessionid' => 0,
            'trustscore' => 0,
            'status' => '',
            'startedat' => 0,
            'endedat' => 0,
            'startedstr' => '',
            'endedstr' => '',
            'eventcount' => 0,
            'violationcount' => 0,
            'fullname' => '',
            'email' => '',
            'reporturl' => '',
            'device' => 'Desktop',
            'browser' => '',
            'tracking' => [
                'camera' => false,
                'mic' => false,
                'screen' => false,
                'tab' => false,
                'fullscreen' => false,
                'attention' => false,
            ],
            'counts' => [
                'noise' => 0,
                'tab' => 0,
                'fullscreen' => 0,
                'noface' => 0,
                'multiface' => 0,
                'multimonitor' => 0,
            ],
            'events' => [],
        ];

        // Strict: only the session for THIS attempt (never fall back to another attempt).
        $rows = $DB->get_records(
            'local_nexproctor_sessions',
            ['attemptid' => $params['attemptid'], 'userid' => $attempt->userid],
            'id DESC',
            '*',
            0,
            1
        );
        $session = $rows ? reset($rows) : false;
        if (!$session) {
            return $empty;
        }

        $user = $DB->get_record('user', ['id' => $session->userid], '*', MUST_EXIST);
        $settings = local_nexproctor_get_quiz_settings((int) $session->quizid);

        $evidence = $DB->get_records('local_nexproctor_evidence', ['sessionid' => $session->id], 'timecreated ASC');
        $evbyevent = [];
        $orphan = [];
        foreach ($evidence as $e) {
            if (!empty($e->eventid)) {
                $evbyevent[(int) $e->eventid][] = $e;
            } else {
                $orphan[] = $e;
            }
        }

        $fs = get_file_storage();
        $events = $DB->get_records('local_nexproctor_events', ['sessionid' => $session->id], 'timecreated DESC');

        $counts = [
            'noise' => 0,
            'tab' => 0,
            'fullscreen' => 0,
            'noface' => 0,
            'multiface' => 0,
            'multimonitor' => 0,
        ];
        $out = [];
        $violations = 0;
        $skiplist = ['tab_visible', 'blur', 'heartbeat'];

        foreach ($events as $ev) {
            $type = (string) $ev->eventtype;
            if (in_array($type, ['noise_detected'], true)) {
                $counts['noise']++;
            } else if (in_array($type, ['tab_hidden'], true)) {
                $counts['tab']++;
            } else if (in_array($type, ['fullscreen_exit'], true)) {
                $counts['fullscreen']++;
            } else if ($type === 'no_face') {
                $counts['noface']++;
            } else if ($type === 'multi_face') {
                $counts['multiface']++;
            } else if ($type === 'multi_monitor') {
                $counts['multimonitor']++;
            }

            if (in_array($ev->severity, ['warning', 'danger'], true) && !in_array($type, $skiplist, true)) {
                $violations++;
            }
            if (in_array($type, $skiplist, true)) {
                continue;
            }

            $evidencetype = '';
            $evidenceurl = '';
            $evidencemime = '';
            $filesmeta = $evbyevent[(int) $ev->id] ?? [];
            // Match orphan evidence by close timestamp + filearea heuristic.
            if (!$filesmeta && $orphan) {
                foreach ($orphan as $idx => $oe) {
                    if (abs((int) $oe->timecreated - (int) $ev->timecreated) <= 8) {
                        $filesmeta[] = $oe;
                        unset($orphan[$idx]);
                        break;
                    }
                }
            }

            // Prefer the right media type per event.
            $prefer = ['snapshot', 'screengrab', 'audioclip'];
            if ($type === 'noise_detected') {
                $prefer = ['audioclip', 'snapshot', 'screengrab'];
            } else if ($type === 'tab_hidden') {
                $prefer = ['screengrab', 'snapshot', 'audioclip'];
            }

            usort($filesmeta, static function ($a, $b) use ($prefer) {
                $ia = array_search($a->filearea, $prefer, true);
                $ib = array_search($b->filearea, $prefer, true);
                $ia = ($ia === false) ? 99 : $ia;
                $ib = ($ib === false) ? 99 : $ib;
                return $ia <=> $ib;
            });

            foreach ($filesmeta as $filemeta) {
                $files = $fs->get_area_files(
                    $context->id,
                    'local_nexproctor',
                    $filemeta->filearea,
                    $filemeta->itemid,
                    'timecreated DESC',
                    false
                );
                // Prefer the file closest in time to this event (legacy shared itemids).
                $best = null;
                $bestdiff = PHP_INT_MAX;
                foreach ($files as $f) {
                    $diff = abs((int) $f->get_timecreated() - (int) $ev->timecreated);
                    if ($diff < $bestdiff) {
                        $bestdiff = $diff;
                        $best = $f;
                    }
                }
                if ($best) {
                    $evidencetype = $filemeta->filearea;
                    $evidencemime = (string) $filemeta->mimetype;
                    $evidenceurl = self::evidence_url(
                        $context->id,
                        $filemeta->filearea,
                        (int) $filemeta->itemid,
                        $best->get_filename()
                    );
                    break;
                }
            }

            $label = self::event_label($type, $ev->payload);
            $out[] = [
                'eventtype' => $type,
                'label' => $label,
                'severity' => (string) $ev->severity,
                'penalty' => (int) $ev->penalty,
                'timecreated' => (int) $ev->timecreated,
                'timestr' => userdate($ev->timecreated, get_string('strftimetime', 'langconfig')),
                'evidencetype' => $evidencetype,
                'evidenceurl' => $evidenceurl,
                'evidencemime' => $evidencemime,
            ];
        }

        $reporturl = (new \moodle_url('/local/nexproctor/report.php', [
            'cmid' => $cmid,
            'sessionid' => $session->id,
        ]))->out(false);

        return [
            'found' => true,
            'sessionid' => (int) $session->id,
            'trustscore' => (int) $session->trustscore,
            'status' => (string) $session->status,
            'startedat' => (int) $session->startedat,
            'endedat' => (int) ($session->endedat ?: $attempt->timefinish),
            'startedstr' => $session->startedat ? userdate($session->startedat, '%d-%b %I:%M %p') : '',
            'endedstr' => ($session->endedat || $attempt->timefinish)
                ? userdate($session->endedat ?: $attempt->timefinish, '%d-%b %I:%M %p') : '',
            'eventcount' => count($events),
            'violationcount' => $violations,
            'fullname' => fullname($user),
            'email' => (string) $user->email,
            'reporturl' => $reporturl,
            'device' => 'Desktop',
            'browser' => '',
            'tracking' => [
                'camera' => !empty($settings->requirecamera),
                'mic' => !empty($settings->requiremic),
                'screen' => !empty($settings->requirescreenshare),
                'tab' => !empty($settings->detecttabswitch),
                'fullscreen' => !empty($settings->requirefullscreen),
                'attention' => !empty($settings->detectattention),
            ],
            'counts' => $counts,
            'events' => $out,
        ];
    }

    /**
     * Human label for event type.
     */
    protected static function event_label(string $type, $payload = ''): string {
        $map = [
            'noise_detected' => 'Noise Detected',
            'tab_hidden' => 'Switched to different application / tab',
            'fullscreen_exit' => 'Exited Full Screen',
            'no_face' => 'No Face Detected',
            'multi_face' => 'Multiple Faces Detected',
            'multi_monitor' => 'Multiple Monitors Detected',
            'looking_away' => 'Looking Away',
            'camera_lost' => 'Camera Disconnected',
            'mic_lost' => 'Microphone Disconnected',
            'screenshare_lost' => 'Screen Share Stopped',
            'random_snapshot' => 'Random Photo',
            'heartbeat' => 'Heartbeat Snapshot',
        ];
        return $map[$type] ?? $type;
    }

    public static function execute_returns(): external_single_structure {
        $bool = PARAM_BOOL;
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Session found'),
            'sessionid' => new external_value(PARAM_INT, 'Session id'),
            'trustscore' => new external_value(PARAM_INT, 'Trust score'),
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'startedat' => new external_value(PARAM_INT, 'Started'),
            'endedat' => new external_value(PARAM_INT, 'Ended'),
            'startedstr' => new external_value(PARAM_TEXT, 'Started label'),
            'endedstr' => new external_value(PARAM_TEXT, 'Ended label'),
            'eventcount' => new external_value(PARAM_INT, 'Event count'),
            'violationcount' => new external_value(PARAM_INT, 'Violation count'),
            'fullname' => new external_value(PARAM_TEXT, 'Student name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'reporturl' => new external_value(PARAM_URL, 'Full report URL'),
            'device' => new external_value(PARAM_TEXT, 'Device'),
            'browser' => new external_value(PARAM_TEXT, 'Browser'),
            'tracking' => new external_single_structure([
                'camera' => new external_value($bool, 'Camera'),
                'mic' => new external_value($bool, 'Mic'),
                'screen' => new external_value($bool, 'Screen'),
                'tab' => new external_value($bool, 'Tab'),
                'fullscreen' => new external_value($bool, 'Fullscreen'),
                'attention' => new external_value($bool, 'Attention'),
            ]),
            'counts' => new external_single_structure([
                'noise' => new external_value(PARAM_INT, 'Noise'),
                'tab' => new external_value(PARAM_INT, 'Tab'),
                'fullscreen' => new external_value(PARAM_INT, 'Fullscreen'),
                'noface' => new external_value(PARAM_INT, 'No face'),
                'multiface' => new external_value(PARAM_INT, 'Multi face'),
                'multimonitor' => new external_value(PARAM_INT, 'Multi monitor'),
            ]),
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'eventtype' => new external_value(PARAM_TEXT, 'Type'),
                    'label' => new external_value(PARAM_TEXT, 'Label'),
                    'severity' => new external_value(PARAM_TEXT, 'Severity'),
                    'penalty' => new external_value(PARAM_INT, 'Penalty'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix time'),
                    'timestr' => new external_value(PARAM_TEXT, 'Time label'),
                    'evidencetype' => new external_value(PARAM_TEXT, 'Evidence area'),
                    'evidenceurl' => new external_value(PARAM_RAW, 'Evidence URL'),
                    'evidencemime' => new external_value(PARAM_TEXT, 'MIME'),
                ]),
                'Events',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
