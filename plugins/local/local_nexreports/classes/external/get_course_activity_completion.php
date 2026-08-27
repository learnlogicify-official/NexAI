<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: course activity completion table.
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
 * Course Activity Completion data.
 */
class get_course_activity_completion extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group id (unused)', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Learner search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $cmid = 0,
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
            'cmid' => $cmid,
            'groupid' => $groupid,
            'search' => $search,
            'limit' => $limit,
            'year' => $year,
            'department' => $department,
            'institution' => $institution,
        ]);
        return courses_report::activity_completion(
            (int) $params['courseid'],
            (int) $params['cmid'],
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
            'completed' => new external_value(PARAM_INT, 'Completed / passed flag'),
            'completedlabel' => new external_value(PARAM_TEXT, 'Status label'),
            'completedon' => new external_value(PARAM_TEXT, 'Completed on'),
            'completedontime' => new external_value(PARAM_INT, 'Completed on timestamp'),
            'grade' => new external_value(PARAM_TEXT, 'Grade'),
            'gradevalue' => new external_value(PARAM_FLOAT, 'Grade numeric (-1 empty)'),
            'totalmark' => new external_value(PARAM_TEXT, 'Total mark'),
            'totalmarkvalue' => new external_value(PARAM_FLOAT, 'Total mark numeric (-1 empty)'),
            'gradepercent' => new external_value(PARAM_TEXT, 'Grade percentage'),
            'gradepercentvalue' => new external_value(PARAM_FLOAT, 'Grade percentage numeric (-1 empty)'),
            'passgrade' => new external_value(PARAM_TEXT, 'Pass grade', VALUE_OPTIONAL),
            'passgradevalue' => new external_value(PARAM_FLOAT, 'Pass grade numeric (-1 empty)'),
            'gradedon' => new external_value(PARAM_TEXT, 'Graded on'),
            'gradedontime' => new external_value(PARAM_INT, 'Graded on timestamp'),
            'firstaccess' => new external_value(PARAM_TEXT, 'First access'),
            'firstaccesstime' => new external_value(PARAM_INT, 'First access timestamp'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'timespent' => new external_value(PARAM_INT, 'Time spent seconds'),
            'timespentminutes' => new external_value(PARAM_INT, 'Time spent minutes'),
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
            'activities' => new external_multiple_structure($option),
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selectedgroupid' => new external_value(PARAM_INT, 'Selected group'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'selectedcmid' => new external_value(PARAM_INT, 'Selected activity'),
            'search' => new external_value(PARAM_TEXT, 'Search text'),
        ]);
    }
}
