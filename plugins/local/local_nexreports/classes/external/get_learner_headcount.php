<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: learner headcount by institution, department, and year of passing.
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
 * Total students with institution / department / year-of-passing breakdown.
 */
class get_learner_headcount extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        self::validate_parameters(self::execute_parameters(), []);
        return overview::learner_headcount();
    }

    public static function execute_returns(): external_single_structure {
        $yearfields = [
            'name' => new external_value(PARAM_RAW, 'Year of passing'),
            'count' => new external_value(PARAM_INT, 'Learners in year'),
        ];

        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'totalstudents' => new external_value(PARAM_INT, 'Total learners'),
            'institutions' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Institution'),
                'count' => new external_value(PARAM_INT, 'Learners in institution'),
                'departments' => new external_multiple_structure(new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Department'),
                    'count' => new external_value(PARAM_INT, 'Learners in department'),
                    'years' => new external_multiple_structure(new external_single_structure($yearfields)),
                ])),
                'years' => new external_multiple_structure(new external_single_structure($yearfields)),
            ])),
        ]);
    }
}
