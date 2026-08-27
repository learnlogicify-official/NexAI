<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: overview summary block (KPIs, charts, tables).
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
use local_nexreports\local\overview;

/**
 * Fast summary block for the dashboard.
 */
class get_summary extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days' => new external_value(PARAM_INT, 'Period length in days (7 or 30)', VALUE_DEFAULT, 7),
        ]);
    }

    public static function execute(int $days = 7): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['days' => $days]);
        return overview::summary((int) $params['days']);
    }

    public static function execute_returns(): external_single_structure {
        $kpi = new external_single_structure([
            'key' => new external_value(PARAM_ALPHANUMEXT, 'KPI key'),
            'value' => new external_value(PARAM_INT, 'Current value'),
            'previous' => new external_value(PARAM_INT, 'Previous period value'),
            'change' => new external_value(PARAM_FLOAT, 'Percent change'),
        ]);
        $course = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Course name'),
            'url' => new external_value(PARAM_URL, 'Course URL'),
            'enrolments' => new external_value(PARAM_INT, 'Enrolment count'),
        ]);
        $user = new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'onlinesince' => new external_value(PARAM_TEXT, 'Online since label'),
            'active' => new external_value(PARAM_BOOL, 'Active now'),
        ]);

        return new external_single_structure([
            'period' => new external_value(PARAM_INT, 'Period days'),
            'generated' => new external_value(PARAM_INT, 'Unix timestamp generated'),
            'kpis' => new external_multiple_structure($kpi),
            'overview' => new external_single_structure([
                'labels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Label')),
                'active' => new external_multiple_structure(new external_value(PARAM_INT, 'Active')),
                'enrolments' => new external_multiple_structure(new external_value(PARAM_INT, 'Enrolments')),
                'completions' => new external_multiple_structure(new external_value(PARAM_INT, 'Completions')),
                'averageactive' => new external_value(PARAM_INT, 'Average active'),
                'totalactive' => new external_value(PARAM_INT, 'Total active'),
                'totalenrolments' => new external_value(PARAM_INT, 'Total enrolments'),
                'totalcompletions' => new external_value(PARAM_INT, 'Total completions'),
                'activechange' => new external_value(PARAM_FLOAT, 'Average active change'),
            ]),
            'visits' => new external_single_structure([
                'labels' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Label')),
                'values' => new external_multiple_structure(new external_value(PARAM_INT, 'Visits')),
                'average' => new external_value(PARAM_INT, 'Average visits'),
                'total' => new external_value(PARAM_INT, 'Total visits'),
                'change' => new external_value(PARAM_FLOAT, 'Average visits change'),
            ]),
            'popularcourses' => new external_multiple_structure($course),
            'realtimeusers' => new external_multiple_structure($user),
        ]);
    }
}
