<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: all-courses summary table.
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
 * Courses tab summary data (Edwiser All Courses Summary parity).
 */
class get_courses_summary extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enrolment' => new external_value(PARAM_TEXT, 'Enrolment period filter', VALUE_DEFAULT, 'all'),
            'exclude' => new external_value(PARAM_TEXT, 'Exclude flags CSV', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Course name search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
        ]);
    }

    public static function execute(
        string $enrolment = 'all',
        string $exclude = '',
        string $search = '',
        int $limit = 500
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        if (!access::has_capability('local/nexreports:viewsite', $context)
                && !access::has_capability('local/nexreports:viewcourse', $context)) {
            throw new \required_capability_exception($context, 'local/nexreports:viewcourse', 'nopermissions', '');
        }
        $params = self::validate_parameters(self::execute_parameters(), [
            'enrolment' => $enrolment,
            'exclude' => $exclude,
            'search' => $search,
            'limit' => $limit,
        ]);
        return courses_report::summary(
            (string) $params['enrolment'],
            (string) $params['exclude'],
            (string) $params['search'],
            (int) $params['limit']
        );
    }

    public static function execute_returns(): external_single_structure {
        $row = new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Course name'),
            'category' => new external_value(PARAM_TEXT, 'Category'),
            'url' => new external_value(PARAM_URL, 'Course URL'),
            'enrolments' => new external_value(PARAM_INT, 'Enrolled learners'),
            'completionurl' => new external_value(PARAM_URL, 'Course completion drill URL'),
            'completed' => new external_value(PARAM_INT, 'Completed learners'),
            'notstarted' => new external_value(PARAM_INT, 'Not started'),
            'inprogress' => new external_value(PARAM_INT, 'In progress'),
            'atleastoneactivitystarted' => new external_value(PARAM_INT, 'At least one activity started'),
            'totalactivities' => new external_value(PARAM_INT, 'Total activities'),
            'activitiesurl' => new external_value(PARAM_URL, 'Activities summary drill URL'),
            'avgprogress' => new external_value(PARAM_FLOAT, 'Average progress %'),
            'avggrade' => new external_value(PARAM_FLOAT, 'Average grade'),
            'highestgrade' => new external_value(PARAM_FLOAT, 'Highest grade'),
            'lowestgrade' => new external_value(PARAM_FLOAT, 'Lowest grade'),
            'totaltimespent' => new external_value(PARAM_INT, 'Total time spent seconds'),
            'avgtimespent' => new external_value(PARAM_INT, 'Average time spent seconds'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated timestamp'),
            'rows' => new external_multiple_structure($row),
            'enrolment' => new external_value(PARAM_TEXT, 'Enrolment filter'),
            'exclude' => new external_value(PARAM_TEXT, 'Exclude flags'),
            'search' => new external_value(PARAM_TEXT, 'Search text'),
            'enrolmentlabel' => new external_value(PARAM_TEXT, 'Enrolment period label'),
        ]);
    }
}
