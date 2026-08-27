<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: search users or courses for report filter dropdowns.
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
use local_nexreports\local\profile_filters;

/**
 * Type-ahead search backing the filter dropdowns.
 */
class search_options extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'type' => new external_value(PARAM_ALPHA, 'user|course|group|year|department'),
            'query' => new external_value(PARAM_TEXT, 'Search text', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max results', VALUE_DEFAULT, 20),
            'courseid' => new external_value(PARAM_INT, 'Limit users/groups to a course', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Limit users to a group', VALUE_DEFAULT, 0),
            'year' => new external_value(PARAM_TEXT, 'Year of passing filter', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        string $type,
        string $query = '',
        int $limit = 20,
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
            'type' => $type,
            'query' => $query,
            'limit' => $limit,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'year' => $year,
            'department' => $department,
        ]);

        if ($params['type'] === 'course') {
            $options = overview::search_courses($params['query'], (int) $params['limit']);
        } else if ($params['type'] === 'group') {
            $options = overview::search_groups(
                $params['query'],
                (int) $params['limit'],
                (int) $params['courseid']
            );
        } else if ($params['type'] === 'year') {
            $options = profile_filters::search_years(
                $params['query'],
                (int) $params['limit'],
                (int) $params['courseid']
            );
        } else if ($params['type'] === 'department') {
            $options = profile_filters::search_departments(
                $params['query'],
                (int) $params['limit'],
                (int) $params['courseid'],
                (string) $params['year']
            );
        } else {
            $options = overview::search_users(
                $params['query'],
                (int) $params['limit'],
                (int) $params['courseid'],
                (int) $params['groupid'],
                (string) $params['year'],
                (string) $params['department']
            );
        }

        return ['options' => $options];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'options' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_RAW, 'Record id or profile value'),
                'name' => new external_value(PARAM_TEXT, 'Display name'),
            ])),
        ]);
    }
}
