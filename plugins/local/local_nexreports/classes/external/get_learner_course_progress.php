<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: Learner Course Progress.
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
 * Per-learner course progress under Students.
 */
class get_learner_course_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Learner user id', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Course name search', VALUE_DEFAULT, ''),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'learnersearch' => new external_value(PARAM_TEXT, 'Learner picker search', VALUE_DEFAULT, ''),
            'metaonly' => new external_value(PARAM_BOOL, 'Return filter options only', VALUE_DEFAULT, false),
            'institution' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid = 0,
        string $search = '',
        string $year = '',
        string $department = '',
        string $learnersearch = '',
        bool $metaonly = false,
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
            'userid' => $userid,
            'search' => $search,
            'year' => $year,
            'department' => $department,
            'learnersearch' => $learnersearch,
            'metaonly' => $metaonly,
            'institution' => $institution,
        ]);
        return students_report::course_progress(
            (int) $params['userid'],
            (string) $params['search'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['learnersearch'],
            !empty($params['metaonly']),
            (string) $params['institution']
        );
    }

    public static function execute_returns(): external_single_structure {
        $row = new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL'),
            'status' => new external_value(PARAM_TEXT, 'Status label'),
            'statuskey' => new external_value(PARAM_ALPHANUMEXT, 'Status key'),
            'enrolledon' => new external_value(PARAM_TEXT, 'Enrolled on'),
            'enrolledontime' => new external_value(PARAM_INT, 'Enrolled timestamp'),
            'completedon' => new external_value(PARAM_TEXT, 'Completed on'),
            'completedontime' => new external_value(PARAM_INT, 'Completed timestamp'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'progress' => new external_value(PARAM_FLOAT, 'Progress %'),
            'grade' => new external_value(PARAM_FLOAT, 'Course grade'),
            'totalactivities' => new external_value(PARAM_INT, 'Total activities'),
            'completedactivities' => new external_value(PARAM_INT, 'Completed activities'),
            'attemptedactivities' => new external_value(PARAM_INT, 'Attempted activities'),
            'codingsolved' => new external_value(PARAM_INT, 'Coding solved'),
            'codingtotal' => new external_value(PARAM_INT, 'Coding total'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'timespent' => new external_value(PARAM_INT, 'Time spent seconds'),
        ]);
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        $summary = new external_single_structure([
            'fullname' => new external_value(PARAM_TEXT, 'Learner display name'),
            'url' => new external_value(PARAM_RAW, 'Profile URL'),
            'status' => new external_value(PARAM_TEXT, 'Active/Inactive'),
            'statusactive' => new external_value(PARAM_INT, '1 if active'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'lastaccesstime' => new external_value(PARAM_INT, 'Last access timestamp'),
            'visitsoncourse' => new external_value(PARAM_INT, 'Visits on course'),
            'timespentoncourse' => new external_value(PARAM_INT, 'Time on course seconds'),
            'timespentonsite' => new external_value(PARAM_INT, 'Time on site seconds'),
            'enrolledcourses' => new external_value(PARAM_INT, 'Enrolled courses'),
            'completionprogress' => new external_value(PARAM_FLOAT, 'Avg completion %'),
            'totalmarks' => new external_value(PARAM_FLOAT, 'Total marks'),
            'totalgrade' => new external_value(PARAM_FLOAT, 'Total grade %'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'rows' => new external_multiple_structure($row),
            'learners' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'colleges' => new external_multiple_structure($option),
            'summary' => $summary,
            'selecteduserid' => new external_value(PARAM_INT, 'Selected learner'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available', VALUE_DEFAULT, 1),
            'search' => new external_value(PARAM_TEXT, 'Course search'),
            'learnersearch' => new external_value(PARAM_TEXT, 'Learner search'),
        ]);
    }
}
