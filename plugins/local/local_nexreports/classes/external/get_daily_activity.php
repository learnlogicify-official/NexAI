<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: daily activities panel.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexreports\local\access;
use local_nexreports\local\overview_extra;

/**
 * Daily activity totals for one calendar day.
 */
class get_daily_activity extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'daystart' => new external_value(PARAM_INT, 'Day midnight unix (0 = today)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $daystart = 0): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['daystart' => $daystart]);
        return overview_extra::daily_activity((int) $params['daystart']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'daystart' => new external_value(PARAM_INT, 'Day start'),
            'daylabel' => new external_value(PARAM_TEXT, 'Day label'),
            'registrations' => new external_value(PARAM_INT, 'Registrations'),
            'enrolments' => new external_value(PARAM_INT, 'Enrolments'),
            'coursecompletions' => new external_value(PARAM_INT, 'Course completions'),
            'activitycompletions' => new external_value(PARAM_INT, 'Activity completions'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'onlinelearners' => new external_value(PARAM_INT, 'Online learners'),
            'onlineteachers' => new external_value(PARAM_INT, 'Online teachers'),
            'labels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Hour label')),
            'visitsbyhour' => new external_multiple_structure(new external_value(PARAM_INT, 'Visits')),
        ]);
    }
}
