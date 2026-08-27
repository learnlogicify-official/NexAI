<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: Learner Course Activities.
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
 * Per-learner activities within a course (Students tab).
 */
class get_learner_course_activities extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Learner id', VALUE_DEFAULT, 0),
            'section' => new external_value(PARAM_INT, 'Section number (-1 all)', VALUE_DEFAULT, -1),
            'search' => new external_value(PARAM_TEXT, 'Activity name search', VALUE_DEFAULT, ''),
            'activitytype' => new external_value(PARAM_ALPHANUMEXT, 'Activity mod filter', VALUE_DEFAULT, ''),
            'completionstatus' => new external_value(PARAM_ALPHANUMEXT, 'Completion status filter', VALUE_DEFAULT, 'all'),
            'learnersearch' => new external_value(PARAM_TEXT, 'Learner picker search', VALUE_DEFAULT, ''),
            'metaonly' => new external_value(PARAM_BOOL, 'Filter options only', VALUE_DEFAULT, false),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $userid = 0,
        int $section = -1,
        string $search = '',
        string $activitytype = '',
        string $completionstatus = 'all',
        string $learnersearch = '',
        bool $metaonly = false,
        string $year = '',
        string $department = '',
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
            'userid' => $userid,
            'section' => $section,
            'search' => $search,
            'activitytype' => $activitytype,
            'completionstatus' => $completionstatus,
            'learnersearch' => $learnersearch,
            'metaonly' => $metaonly,
            'year' => $year,
            'department' => $department,
            'institution' => $institution,
        ]);
        return students_report::course_activities(
            (int) $params['courseid'],
            (int) $params['userid'],
            (int) $params['section'],
            (string) $params['search'],
            (string) $params['activitytype'],
            (string) $params['completionstatus'],
            (string) $params['learnersearch'],
            !empty($params['metaonly']),
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
        $row = new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'activity' => new external_value(PARAM_TEXT, 'Activity name'),
            'type' => new external_value(PARAM_TEXT, 'Activity type'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Mod name'),
            'status' => new external_value(PARAM_TEXT, 'Status label'),
            'statuskey' => new external_value(PARAM_ALPHANUMEXT, 'Status key'),
            'completedon' => new external_value(PARAM_TEXT, 'Completed on'),
            'completedontime' => new external_value(PARAM_INT, 'Completed timestamp'),
            'grade' => new external_value(PARAM_TEXT, 'Grade'),
            'gradevalue' => new external_value(PARAM_FLOAT, 'Grade numeric'),
            'gradedon' => new external_value(PARAM_TEXT, 'Graded on'),
            'gradedontime' => new external_value(PARAM_INT, 'Graded timestamp'),
            'attempts' => new external_value(PARAM_INT, 'Attempts'),
            'highestgrade' => new external_value(PARAM_TEXT, 'Highest grade'),
            'highestgradevalue' => new external_value(PARAM_FLOAT, 'Highest grade numeric'),
            'lowestgrade' => new external_value(PARAM_TEXT, 'Lowest grade'),
            'lowestgradevalue' => new external_value(PARAM_FLOAT, 'Lowest grade numeric'),
            'firstaccess' => new external_value(PARAM_TEXT, 'First access'),
            'firstaccesstime' => new external_value(PARAM_INT, 'First access timestamp'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'timespent' => new external_value(PARAM_INT, 'Time spent seconds'),
        ]);
        $summary = new external_single_structure([
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'fullname' => new external_value(PARAM_TEXT, 'Learner name'),
            'url' => new external_value(PARAM_RAW, 'Profile URL'),
            'status' => new external_value(PARAM_TEXT, 'Active/Inactive'),
            'statusactive' => new external_value(PARAM_INT, '1 if active'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'visitsoncourse' => new external_value(PARAM_INT, 'Visits on course'),
            'enrolledon' => new external_value(PARAM_TEXT, 'Enrollment date'),
            'enrolledontime' => new external_value(PARAM_INT, 'Enrollment timestamp'),
            'timespent' => new external_value(PARAM_INT, 'Course time seconds'),
            'marks' => new external_value(PARAM_FLOAT, 'Course marks'),
            'gradepercent' => new external_value(PARAM_FLOAT, 'Course grade %'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'rows' => new external_multiple_structure($row),
            'courses' => new external_multiple_structure($option),
            'learners' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'sections' => new external_multiple_structure($option),
            'activitytypes' => new external_multiple_structure($option),
            'summary' => $summary,
            'selectedcourseid' => new external_value(PARAM_INT, 'Selected course'),
            'selecteduserid' => new external_value(PARAM_INT, 'Selected learner'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'selectedsection' => new external_value(PARAM_INT, 'Selected section'),
            'selectedactivitytype' => new external_value(PARAM_ALPHANUMEXT, 'Selected activity type'),
            'selectedcompletionstatus' => new external_value(PARAM_ALPHANUMEXT, 'Selected completion status'),
            'search' => new external_value(PARAM_TEXT, 'Activity search'),
            'learnersearch' => new external_value(PARAM_TEXT, 'Learner search'),
        ]);
    }
}
