<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: time spent on course block.
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
 * Top courses by engaged time, optionally filtered by course and user.
 */
class get_timespent_course extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days' => new external_value(PARAM_INT, 'Period length in days (7 or 30)', VALUE_DEFAULT, 7),
            'courseid' => new external_value(PARAM_INT, 'Course filter (0 = all)', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group filter (0 = all)', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'User filter (0 = all)', VALUE_DEFAULT, 0),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $days = 7,
        int $courseid = 0,
        int $groupid = 0,
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
            'courseid' => $courseid,
            'groupid' => $groupid,
            'userid' => $userid,
            'year' => $year,
            'department' => $department,
        ]);
        return overview::timespent_course(
            (int) $params['days'],
            (int) $params['courseid'],
            (int) $params['groupid'],
            (int) $params['userid'],
            (string) $params['year'],
            (string) $params['department']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'period' => new external_value(PARAM_INT, 'Period days'),
            'generated' => new external_value(PARAM_INT, 'Unix timestamp generated'),
            'available' => new external_value(PARAM_BOOL, 'Whether logstore data was available'),
            'courselabels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Course name')),
            'coursevalues' => new external_multiple_structure(new external_value(PARAM_INT, 'Course minutes')),
            'courseaverage' => new external_value(PARAM_INT, 'Average minutes per displayed course'),
            'coursetotal' => new external_value(PARAM_INT, 'Total minutes on displayed courses'),
            'coursechange' => new external_value(PARAM_FLOAT, 'Course time change'),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course id'),
            'selectedgroupid' => new external_value(PARAM_INT, 'Selected group id'),
            'selecteduserid' => new external_value(PARAM_INT, 'Selected user id'),
            'selectedcoursename' => new external_value(PARAM_TEXT, 'Selected course display name'),
            'selectedgroupname' => new external_value(PARAM_TEXT, 'Selected group display name'),
            'selectedusername' => new external_value(PARAM_TEXT, 'Selected user display name'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
        ]);
    }
}
