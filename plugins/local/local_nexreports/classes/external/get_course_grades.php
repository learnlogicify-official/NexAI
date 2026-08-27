<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: full course grades matrix.
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
use local_nexreports\local\course_grades_report;

/**
 * Courses → Full course grades.
 */
class get_course_grades extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Learner search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
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
            'search' => $search,
            'limit' => $limit,
            'year' => $year,
            'department' => $department,
            'institution' => $institution,
        ]);
        return course_grades_report::report(
            (int) $params['courseid'],
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['institution']
        );
    }

    public static function execute_returns(): external_single_structure {
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        $activity = new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'itemid' => new external_value(PARAM_INT, 'Grade item id'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module name'),
            'modlabel' => new external_value(PARAM_TEXT, 'Module label'),
            'maxgrade' => new external_value(PARAM_TEXT, 'Max grade display'),
            'maxgradevalue' => new external_value(PARAM_FLOAT, 'Max grade numeric'),
        ]);
        $section = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section id'),
            'section' => new external_value(PARAM_INT, 'Section number'),
            'name' => new external_value(PARAM_TEXT, 'Section name'),
            'activities' => new external_multiple_structure($activity),
        ]);
        $cell = new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'display' => new external_value(PARAM_TEXT, 'Grade display'),
            'value' => new external_value(PARAM_FLOAT, 'Grade numeric (-1 empty)'),
        ]);
        $row = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'username' => new external_value(PARAM_TEXT, 'Username'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'institution' => new external_value(PARAM_TEXT, 'Institution'),
            'department' => new external_value(PARAM_TEXT, 'Department'),
            'yearofpassing' => new external_value(PARAM_TEXT, 'Year of passing'),
            'url' => new external_value(PARAM_RAW, 'Profile URL'),
            'gradecells' => new external_multiple_structure($cell),
            'total' => new external_value(PARAM_TEXT, 'Course total display'),
            'totalvalue' => new external_value(PARAM_FLOAT, 'Course total numeric (-1 empty)'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated timestamp'),
            'rows' => new external_multiple_structure($row),
            'sections' => new external_multiple_structure($section),
            'courses' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available'),
            'search' => new external_value(PARAM_TEXT, 'Search text'),
            'coursetotalmax' => new external_value(PARAM_TEXT, 'Course total max display'),
            'coursetotalmaxvalue' => new external_value(PARAM_FLOAT, 'Course total max numeric'),
            'activitycount' => new external_value(PARAM_INT, 'Graded activity count'),
        ]);
    }
}
