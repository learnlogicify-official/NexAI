<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: student engagement / all-learner summary.
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
use local_nexreports\local\students_report;

/**
 * Students tab engagement table.
 */
class get_students_engagement extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course filter', VALUE_DEFAULT, 0),
            'cohortid' => new external_value(PARAM_INT, 'Cohort filter (unused)', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Name/email search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 2000),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'inactive' => new external_value(PARAM_ALPHANUMEXT, 'Inactive filter', VALUE_DEFAULT, 'all'),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $cohortid = 0,
        string $search = '',
        int $limit = 2000,
        string $year = '',
        string $department = '',
        string $inactive = 'all',
        string $institution = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        if (!access::has_capability('local/nexreports:viewstudents', $context)
                && !access::has_capability('local/nexreports:viewsite', $context)) {
            access::require_capability('local/nexreports:viewstudents', $context);
        }
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cohortid' => $cohortid,
            'search' => $search,
            'limit' => $limit,
            'year' => $year,
            'department' => $department,
            'inactive' => $inactive,
            'institution' => $institution,
        ]);
        return students_report::engagement(
            (int) $params['courseid'],
            0,
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['inactive'],
            (string) $params['institution']
        );
    }

    public static function execute_returns(): external_single_structure {
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
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'status' => new external_value(PARAM_TEXT, 'Active/Inactive label'),
            'statusactive' => new external_value(PARAM_INT, '1 if active'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access label'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'enrolledcourses' => new external_value(PARAM_INT, 'Enrolled courses'),
            'inprogress' => new external_value(PARAM_INT, 'In-progress courses'),
            'completed' => new external_value(PARAM_INT, 'Completed courses'),
            'avgprogress' => new external_value(PARAM_FLOAT, 'Completion progress %'),
            'totalgrade' => new external_value(PARAM_FLOAT, 'Sum of course total grades'),
            'codingsolved' => new external_value(PARAM_INT, 'CodeRunner questions solved'),
            'codingtotal' => new external_value(PARAM_INT, 'CodeRunner questions available'),
            'timespentonsite' => new external_value(PARAM_INT, 'Time spent on site seconds'),
            'timespentoncourse' => new external_value(PARAM_INT, 'Time spent on course seconds'),
            'timespentminutes' => new external_value(PARAM_INT, 'Time spent minutes (course)'),
            'activitiescompleted' => new external_value(PARAM_INT, 'Activities completed'),
            'visits' => new external_value(PARAM_INT, 'Visits on course'),
            'completedassignments' => new external_value(PARAM_INT, 'Completed assignments'),
            'completedquizzes' => new external_value(PARAM_INT, 'Completed quizzes'),
            'completedscorms' => new external_value(PARAM_INT, 'Completed scorms'),
        ]);
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'category' => new external_value(PARAM_TEXT, 'Category', VALUE_OPTIONAL),
        ]);
        $summary = new external_single_structure([
            'totalvisits' => new external_value(PARAM_INT, 'Total visits'),
            'avgvisits' => new external_value(PARAM_INT, 'Average visits'),
            'totallearners' => new external_value(PARAM_INT, 'Total learners'),
            'totaltimespent' => new external_value(PARAM_INT, 'Total time spent seconds'),
            'avgtimespent' => new external_value(PARAM_INT, 'Average time spent seconds'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'rows' => new external_multiple_structure($row),
            'summary' => $summary,
            'courses' => new external_multiple_structure($option),
            'cohorts' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selectedcohortid' => new external_value(PARAM_INT, 'Selected cohort'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'selectedinactive' => new external_value(PARAM_ALPHANUMEXT, 'Selected inactive filter'),
            'search' => new external_value(PARAM_TEXT, 'Search'),
        ]);
    }
}
