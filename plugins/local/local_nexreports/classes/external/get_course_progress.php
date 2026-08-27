<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: course progress distribution block.
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
use local_nexreports\local\overview_extra;

/**
 * Average progress and bucket counts for one course.
 */
class get_course_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id (0 = default)', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group id (0 = all)', VALUE_DEFAULT, 0),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        int $groupid = 0,
        string $year = '',
        string $department = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'groupid' => $groupid,
            'year' => $year,
            'department' => $department,
        ]);
        return overview_extra::course_progress(
            (int) $params['courseid'],
            (int) $params['groupid'],
            (string) $params['year'],
            (string) $params['department']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'available' => new external_value(PARAM_BOOL, 'Whether learners exist'),
            'selectedcourseid' => new external_value(PARAM_INT, 'Course id'),
            'selectedcoursename' => new external_value(PARAM_TEXT, 'Course name'),
            'selectedgroupid' => new external_value(PARAM_INT, 'Group id'),
            'selectedgroupname' => new external_value(PARAM_TEXT, 'Group name'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'average' => new external_value(PARAM_FLOAT, 'Average progress percent'),
            'learners' => new external_value(PARAM_INT, 'Learner count'),
            'buckets' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Bucket key'),
                'label' => new external_value(PARAM_TEXT, 'Bucket label'),
                'count' => new external_value(PARAM_INT, 'Learners in bucket'),
            ])),
        ]);
    }
}
