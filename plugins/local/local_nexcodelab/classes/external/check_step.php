<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: check one mission step.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\mission_runner;

/**
 * Check step.
 */
class check_step extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
            'stepid' => new external_value(PARAM_INT, 'Step id'),
            'code' => new external_value(PARAM_RAW, 'main.py source'),
        ]);
    }

    public static function execute(int $missionid, int $stepid, string $code): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:attempt', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('missionid', 'stepid', 'code'));
        if ($params['code'] === '') {
            throw new \invalid_parameter_exception('Empty code');
        }
        return mission_runner::check_step(
            (int) $USER->id,
            (int) $params['missionid'],
            (int) $params['stepid'],
            (string) $params['code']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'ok'),
            'passed' => new external_value(PARAM_BOOL, 'passed'),
            'message' => new external_value(PARAM_TEXT, 'message'),
            'output' => new external_value(PARAM_RAW, 'output'),
            'xpAwarded' => new external_value(PARAM_INT, 'xp'),
            'missionCompleted' => new external_value(PARAM_BOOL, 'done'),
            'expected' => new external_value(PARAM_RAW, 'expected', VALUE_DEFAULT, ''),
            'actual' => new external_value(PARAM_RAW, 'actual', VALUE_DEFAULT, ''),
        ]);
    }
}
