<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: signed-in user's own time spent on site.
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
use local_nexreports\local\learner;

/**
 * My Time Spent On Site — always the current user.
 */
class get_my_timespent extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days' => new external_value(PARAM_INT, 'Period length in days (7 or 30)', VALUE_DEFAULT, 7),
        ]);
    }

    public static function execute(int $days = 7): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewlearner', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'days' => $days,
        ]);
        return learner::my_timespent((int) $params['days']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'period' => new external_value(PARAM_INT, 'Period days'),
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'available' => new external_value(PARAM_BOOL, 'Whether data was available'),
            'labels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Label')),
            'values' => new external_multiple_structure(new external_value(PARAM_INT, 'Minutes')),
            'average' => new external_value(PARAM_INT, 'Average minutes per day'),
            'total' => new external_value(PARAM_INT, 'Total minutes'),
            'change' => new external_value(PARAM_FLOAT, 'Average change'),
            'selecteduserid' => new external_value(PARAM_INT, 'User id'),
            'selectedusername' => new external_value(PARAM_TEXT, 'User name'),
        ]);
    }
}
