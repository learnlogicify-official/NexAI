<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: course activities summary table.
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
use local_nexreports\local\courses_report;

/**
 * Course Activities Summary data.
 */
class get_course_activities_summary extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group id (unused)', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Activity search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $groupid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        if (!access::has_capability('local/nexreports:viewsite', $context)
                && !access::has_capability('local/nexreports:viewcourse', $context)) {
            throw new \required_capability_exception($context, 'local/nexreports:viewcourse', 'nopermissions', '');
        }
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'groupid' => $groupid,
            'search' => $search,
            'limit' => $limit,
            'year' => $year,
            'department' => $department,
            'institution' => $institution,
        ]);
        return courses_report::activities_summary(
            (int) $params['courseid'],
            0,
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['institution']
        );
    }

    public static function execute_returns(): external_single_structure {
        $row = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'type' => new external_value(PARAM_TEXT, 'Activity type'),
            'status' => new external_value(PARAM_TEXT, 'Status label'),
            'learnerscompleted' => new external_value(PARAM_INT, 'Learners completed'),
            'completionrate' => new external_value(PARAM_FLOAT, 'Completion rate %'),
            'totalgrade' => new external_value(PARAM_FLOAT, 'Total / max grade'),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Max grade'),
            'passgrade' => new external_value(PARAM_FLOAT, 'Pass grade'),
            'averagegrade' => new external_value(PARAM_FLOAT, 'Average grade'),
            'highestgrade' => new external_value(PARAM_FLOAT, 'Highest grade'),
            'lowestgrade' => new external_value(PARAM_FLOAT, 'Lowest grade'),
            'totaltimespent' => new external_value(PARAM_INT, 'Total time spent seconds'),
            'averagetimespent' => new external_value(PARAM_INT, 'Average time spent seconds'),
            'totaltimespentminutes' => new external_value(PARAM_INT, 'Total time spent minutes'),
            'averagetimespentminutes' => new external_value(PARAM_INT, 'Average time spent minutes'),
            'totalvisits' => new external_value(PARAM_INT, 'Total visits'),
            'averagevisits' => new external_value(PARAM_INT, 'Average visits'),
            'url' => new external_value(PARAM_URL, 'Activity completion drill URL'),
        ]);
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated timestamp'),
            'rows' => new external_multiple_structure($row),
            'courses' => new external_multiple_structure($option),
            'groups' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selectedgroupid' => new external_value(PARAM_INT, 'Selected group'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'search' => new external_value(PARAM_TEXT, 'Search text'),
        ]);
    }
}
