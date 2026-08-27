<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: Course Completion ( Without Pass Grade Condition ).
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
 * Course Completion without pass-grade condition (inclusive quiz progress).
 */
class get_course_quiz_cumulative extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'cohortid' => new external_value(PARAM_INT, 'Cohort id (unused)', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group id (unused)', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Learner search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max learner rows', VALUE_DEFAULT, 500),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $cohortid = 0,
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
            'cohortid' => $cohortid,
            'groupid' => $groupid,
            'search' => $search,
            'limit' => $limit,
            'year' => $year,
            'department' => $department,
            'institution' => $institution,
        ]);
        return courses_report::quiz_cumulative(
            (int) $params['courseid'],
            0,
            0,
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['institution']
        );
    }

    public static function execute_returns(): external_single_structure {
        $learner = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'fullname' => new external_value(PARAM_TEXT, 'Learner name'),
            'username' => new external_value(PARAM_TEXT, 'Username'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'institution' => new external_value(PARAM_TEXT, 'Institution'),
            'department' => new external_value(PARAM_TEXT, 'Department'),
            'yearofpassing' => new external_value(PARAM_TEXT, 'Year of passing'),
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'enrolledon' => new external_value(PARAM_TEXT, 'Enrolled on'),
            'enrolledontime' => new external_value(PARAM_INT, 'Enrolled on timestamp'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'progress' => new external_value(PARAM_FLOAT, 'Progress %'),
            'completed' => new external_value(PARAM_INT, 'Completed flag'),
            'completedlabel' => new external_value(PARAM_TEXT, 'Status label'),
            'completedon' => new external_value(PARAM_TEXT, 'Completed on'),
            'completedontime' => new external_value(PARAM_INT, 'Completed on timestamp'),
            'completedactivities' => new external_value(PARAM_INT, 'Quizzes touched (pass+fail+in progress)'),
            'totalactivities' => new external_value(PARAM_INT, 'Total quizzes'),
            'passed' => new external_value(PARAM_INT, 'Quizzes passed', VALUE_OPTIONAL),
            'failed' => new external_value(PARAM_INT, 'Quizzes failed', VALUE_OPTIONAL),
            'inprogress' => new external_value(PARAM_INT, 'Quizzes in progress', VALUE_OPTIONAL),
            'notstarted' => new external_value(PARAM_INT, 'Quizzes not started', VALUE_OPTIONAL),
            'codingsolved' => new external_value(PARAM_INT, 'CodeRunner solved', VALUE_OPTIONAL),
            'codingattempted' => new external_value(PARAM_INT, 'CodeRunner attempted', VALUE_OPTIONAL),
            'codingtotal' => new external_value(PARAM_INT, 'CodeRunner total', VALUE_OPTIONAL),
            'avggrade' => new external_value(PARAM_TEXT, 'Average quiz grade %', VALUE_OPTIONAL),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'timespent' => new external_value(PARAM_INT, 'Time spent seconds'),
            'timespentminutes' => new external_value(PARAM_INT, 'Time spent minutes'),
        ]);
        $quiz = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'name' => new external_value(PARAM_TEXT, 'Quiz name'),
            'url' => new external_value(PARAM_URL, 'Drill-down URL'),
            'passed' => new external_value(PARAM_INT, 'Learners passed'),
            'failed' => new external_value(PARAM_INT, 'Learners failed'),
            'inprogress' => new external_value(PARAM_INT, 'Learners in progress'),
            'notstarted' => new external_value(PARAM_INT, 'Learners not started'),
            'learners' => new external_value(PARAM_INT, 'Learner count in filter'),
            'touched' => new external_value(PARAM_INT, 'Learners with pass/fail/in progress', VALUE_OPTIONAL),
            'progress' => new external_value(PARAM_FLOAT, 'Touched % of learners', VALUE_OPTIONAL),
            'avggrade' => new external_value(PARAM_TEXT, 'Average grade %'),
            'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
            'gradepass' => new external_value(PARAM_TEXT, 'Pass mark'),
        ]);
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated timestamp'),
            'rows' => new external_multiple_structure($learner),
            'quizrows' => new external_multiple_structure($quiz),
            'courses' => new external_multiple_structure($option),
            'cohorts' => new external_multiple_structure($option),
            'groups' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selectedcohortid' => new external_value(PARAM_INT, 'Selected cohort'),
            'selectedgroupid' => new external_value(PARAM_INT, 'Selected group'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'search' => new external_value(PARAM_TEXT, 'Search text'),
            'quizcount' => new external_value(PARAM_INT, 'Quiz count in course'),
            'codingtotal' => new external_value(PARAM_INT, 'CodeRunner total', VALUE_OPTIONAL),
        ]);
    }
}
