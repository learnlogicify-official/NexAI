<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: search users to enrol (college / year / department filters).
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_nexcoursepro\local\enrol_roster;

/**
 * External API: search enrol candidates.
 */
class search_enrol_users extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'college' => new external_value(PARAM_TEXT, 'College / institution', VALUE_DEFAULT, ''),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'query' => new external_value(PARAM_TEXT, 'Name / email search', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, '0-based page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Rows per page', VALUE_DEFAULT, enrol_roster::LIMIT),
        ]);
    }

    public static function execute(
        int $courseid,
        string $college = '',
        string $year = '',
        string $department = '',
        string $query = '',
        int $page = 0,
        int $perpage = enrol_roster::LIMIT
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'college' => $college,
            'year' => $year,
            'department' => $department,
            'query' => $query,
            'page' => $page,
            'perpage' => $perpage,
        ]);

        $course = get_course((int) $params['courseid']);
        if (($course->format ?? '') !== 'nexcoursepro') {
            throw new \invalid_parameter_exception('Course is not using NexCoursePro format.');
        }
        $context = enrol_roster::require_enrol_capability($course);
        self::validate_context($context);
        require_login($course);

        \core\session\manager::write_close();

        return enrol_roster::search(
            (int) $course->id,
            (string) $params['college'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['query'],
            (int) $params['page'],
            (int) $params['perpage']
        );
    }

    public static function execute_returns(): external_single_structure {
        $user = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'user id'),
            'fullname' => new external_value(PARAM_TEXT, 'full name'),
            'username' => new external_value(PARAM_TEXT, 'username'),
            'email' => new external_value(PARAM_RAW, 'email'),
            'college' => new external_value(PARAM_TEXT, 'college'),
            'department' => new external_value(PARAM_TEXT, 'department'),
            'year' => new external_value(PARAM_TEXT, 'year of passing'),
            'avatar' => new external_value(PARAM_RAW, 'avatar html'),
            'profileurl' => new external_value(PARAM_URL, 'profile url'),
        ]);
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'total matches'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'per page'),
            'users' => new external_multiple_structure($user),
            'colleges' => new external_multiple_structure(new external_value(PARAM_TEXT, 'college')),
            'years' => new external_multiple_structure(new external_value(PARAM_TEXT, 'year')),
            'departments' => new external_multiple_structure(new external_value(PARAM_TEXT, 'department')),
            'college' => new external_value(PARAM_TEXT, 'selected college'),
            'year' => new external_value(PARAM_TEXT, 'selected year'),
            'department' => new external_value(PARAM_TEXT, 'selected department'),
            'query' => new external_value(PARAM_TEXT, 'search query'),
            'needcollege' => new external_value(PARAM_BOOL, 'true when college not selected yet', VALUE_DEFAULT, false),
            'roles' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'role id'),
                'name' => new external_value(PARAM_TEXT, 'role name'),
                'selected' => new external_value(PARAM_BOOL, 'default selected'),
            ])),
            'roleid' => new external_value(PARAM_INT, 'default role id'),
        ]);
    }
}
