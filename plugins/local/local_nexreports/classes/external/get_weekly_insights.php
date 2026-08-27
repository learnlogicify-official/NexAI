<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: weekly learner improvement insights.
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
use local_nexreports\local\weekly_insights;

/**
 * Students → Weekly improvement report.
 */
class get_weekly_insights extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'institution' => new external_value(PARAM_TEXT, 'College', VALUE_DEFAULT, ''),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
        ]);
    }

    public static function execute(
        string $institution = '',
        string $year = '',
        string $department = '',
        string $search = '',
        int $limit = 500
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        if (!access::has_capability('local/nexreports:viewstudents', $context)
                && !access::has_capability('local/nexreports:viewsite', $context)) {
            access::require_capability('local/nexreports:viewstudents', $context);
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'institution' => $institution,
            'year' => $year,
            'department' => $department,
            'search' => $search,
            'limit' => $limit,
        ]);

        return weekly_insights::report(
            (string) $params['institution'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['search'],
            (int) $params['limit']
        );
    }

    public static function execute_returns(): external_single_structure {
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        $weekpoint = new external_single_structure([
            'weekstart' => new external_value(PARAM_INT, 'Week start'),
            'timespent' => new external_value(PARAM_INT, 'Seconds'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'activedays' => new external_value(PARAM_INT, 'Active days'),
            'activitiescompleted' => new external_value(PARAM_INT, 'Activities'),
            'codingsolved' => new external_value(PARAM_INT, 'Coding'),
            'quizattempts' => new external_value(PARAM_INT, 'Quiz attempts'),
        ]);
        $row = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'username' => new external_value(PARAM_TEXT, 'Username'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'institution' => new external_value(PARAM_TEXT, 'College'),
            'yearofpassing' => new external_value(PARAM_TEXT, 'Year'),
            'department' => new external_value(PARAM_TEXT, 'Department'),
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Overall status'),
            'timespent' => new external_value(PARAM_INT, 'Time spent'),
            'visits' => new external_value(PARAM_INT, 'Visits'),
            'activedays' => new external_value(PARAM_INT, 'Active days'),
            'activitiescompleted' => new external_value(PARAM_INT, 'Activities'),
            'codingsolved' => new external_value(PARAM_INT, 'Coding'),
            'quizattempts' => new external_value(PARAM_INT, 'Quiz attempts'),
            'deltatimespent' => new external_value(PARAM_INT, 'Δ time'),
            'deltavisits' => new external_value(PARAM_INT, 'Δ visits'),
            'deltaactivedays' => new external_value(PARAM_INT, 'Δ active days'),
            'deltaactivities' => new external_value(PARAM_INT, 'Δ activities'),
            'deltacoding' => new external_value(PARAM_INT, 'Δ coding'),
            'deltaquiz' => new external_value(PARAM_INT, 'Δ quiz'),
            'statustimespent' => new external_value(PARAM_ALPHANUMEXT, 'Time status'),
            'statusvisits' => new external_value(PARAM_ALPHANUMEXT, 'Visits status'),
            'statusactivedays' => new external_value(PARAM_ALPHANUMEXT, 'Active days status'),
            'statusactivities' => new external_value(PARAM_ALPHANUMEXT, 'Activities status'),
            'statuscoding' => new external_value(PARAM_ALPHANUMEXT, 'Coding status'),
            'statusquiz' => new external_value(PARAM_ALPHANUMEXT, 'Quiz status'),
            'weekseries' => new external_multiple_structure($weekpoint),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'weeks' => new external_multiple_structure(new external_single_structure([
                'weekstart' => new external_value(PARAM_INT, 'Start'),
                'label' => new external_value(PARAM_TEXT, 'Label'),
                'current' => new external_value(PARAM_INT, 'Is current week'),
            ])),
            'aspects' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Key'),
                'label' => new external_value(PARAM_TEXT, 'Label'),
            ])),
            'summary' => new external_single_structure([
                'totallearners' => new external_value(PARAM_INT, 'Total'),
                'improving' => new external_value(PARAM_INT, 'Improving'),
                'declining' => new external_value(PARAM_INT, 'Declining'),
                'stable' => new external_value(PARAM_INT, 'Stable'),
                'neworidle' => new external_value(PARAM_INT, 'New or idle'),
            ]),
            'rows' => new external_multiple_structure($row),
            'colleges' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'showcollege' => new external_value(PARAM_INT, 'Show college filter'),
            'showdepartment' => new external_value(PARAM_INT, 'Show department filter'),
            'search' => new external_value(PARAM_TEXT, 'Search'),
            'historyready' => new external_value(PARAM_INT, '1 when weekly table has data'),
            'latestweek' => new external_value(PARAM_INT, 'Latest week start', VALUE_DEFAULT, 0),
            'latestweeklabel' => new external_value(PARAM_TEXT, 'Latest week label', VALUE_DEFAULT, ''),
        ]);
    }
}
