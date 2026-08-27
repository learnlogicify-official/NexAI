<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * End session.
 */
class end_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Session id'),
        ]);
    }

    public static function execute(int $sessionid): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['sessionid' => $sessionid]);
        $session = $DB->get_record('local_nexproctor_sessions', ['id' => $params['sessionid']], '*', MUST_EXIST);
        if ((int) $session->userid !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $cm = get_coursemodule_from_id('quiz', $session->cmid, 0, false, MUST_EXIST);
        self::validate_context(context_module::instance($cm->id));

        $session->status = 'ended';
        $session->endedat = time();
        $session->timemodified = time();
        $DB->update_record('local_nexproctor_sessions', $session);
        $score = local_nexproctor_recalc_trust($session->id);

        return ['ok' => true, 'trustscore' => $score];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'OK'),
            'trustscore' => new external_value(PARAM_INT, 'Final trust'),
        ]);
    }
}
