<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * Mark preflight complete in session.
 */
class complete_preflight extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
        ]);
    }

    public static function execute(int $cmid, int $quizid): array {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'quizid' => $quizid,
        ]);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:attempt', $context);

        local_nexproctor_mark_preflight_done($params['quizid'], (int) $USER->id);
        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'OK'),
        ]);
    }
}
