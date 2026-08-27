<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * Log proctoring event.
 */
class log_event extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Session id'),
            'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Event type'),
            'severity' => new external_value(PARAM_ALPHA, 'Severity', VALUE_DEFAULT, 'info'),
            'payload' => new external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, ''),
            'penalty' => new external_value(PARAM_INT, 'Penalty override', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $sessionid,
        string $eventtype,
        string $severity = 'info',
        string $payload = '',
        int $penalty = 0
    ): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), compact(
            'sessionid', 'eventtype', 'severity', 'payload', 'penalty'
        ));

        $session = $DB->get_record('local_nexproctor_sessions', ['id' => $params['sessionid']], '*', MUST_EXIST);
        if ((int) $session->userid !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $cm = get_coursemodule_from_id('quiz', $session->cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $penalties = local_nexproctor_penalties();
        $pen = (int) $params['penalty'];
        if ($pen <= 0 && isset($penalties[$params['eventtype']])) {
            $pen = (int) $penalties[$params['eventtype']];
        }

        $eventid = (int) $DB->insert_record('local_nexproctor_events', (object) [
            'sessionid' => $params['sessionid'],
            'eventtype' => $params['eventtype'],
            'severity' => $params['severity'],
            'payload' => $params['payload'],
            'penalty' => $pen,
            'timecreated' => time(),
        ]);
        $score = local_nexproctor_recalc_trust($params['sessionid']);

        return ['eventid' => $eventid, 'trustscore' => $score];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'eventid' => new external_value(PARAM_INT, 'Event id'),
            'trustscore' => new external_value(PARAM_INT, 'Updated trust'),
        ]);
    }
}
