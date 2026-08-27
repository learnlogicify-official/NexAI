<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: time spent on site block.
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
use local_nexreports\local\overview;

/**
 * Daily time spent on site, optionally filtered by user.
 */
class get_timespent_site extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days' => new external_value(PARAM_INT, 'Period length in days (7 or 30)', VALUE_DEFAULT, 7),
            'userid' => new external_value(PARAM_INT, 'User filter (0 = all)', VALUE_DEFAULT, 0),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $days = 7,
        int $userid = 0,
        string $year = '',
        string $department = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'days' => $days,
            'userid' => $userid,
            'year' => $year,
            'department' => $department,
        ]);
        return overview::timespent_site(
            (int) $params['days'],
            (int) $params['userid'],
            true,
            (string) $params['year'],
            (string) $params['department']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'period' => new external_value(PARAM_INT, 'Period days'),
            'generated' => new external_value(PARAM_INT, 'Unix timestamp generated'),
            'available' => new external_value(PARAM_BOOL, 'Whether logstore data was available'),
            'labels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Label')),
            'values' => new external_multiple_structure(new external_value(PARAM_INT, 'Minutes')),
            'average' => new external_value(PARAM_INT, 'Average minutes per day'),
            'total' => new external_value(PARAM_INT, 'Total minutes'),
            'change' => new external_value(PARAM_FLOAT, 'Average minutes change'),
            'selecteduserid' => new external_value(PARAM_INT, 'Selected user id'),
            'selectedusername' => new external_value(PARAM_TEXT, 'Selected user display name'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
        ]);
    }
}
