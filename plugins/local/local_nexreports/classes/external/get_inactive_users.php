<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: inactive users list.
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
 * Inactive learners panel.
 */
class get_inactive_users extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'months' => new external_value(PARAM_INT, 'Inactive for N months (0 = never)', VALUE_DEFAULT, 1),
            'search' => new external_value(PARAM_TEXT, 'Name/email search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 100),
        ]);
    }

    public static function execute(int $months = 1, string $search = '', int $limit = 100): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'months' => $months,
            'search' => $search,
            'limit' => $limit,
        ]);
        return overview_extra::inactive_users(
            (int) $params['months'],
            (string) $params['search'],
            (int) $params['limit']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'months' => new external_value(PARAM_INT, 'Months filter'),
            'search' => new external_value(PARAM_TEXT, 'Search'),
            'rows' => new external_multiple_structure(new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'Rank'),
                'userid' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
                'url' => new external_value(PARAM_URL, 'Profile'),
                'lastaccess' => new external_value(PARAM_TEXT, 'Last access label'),
                'lastaccessuts' => new external_value(PARAM_INT, 'Last access unix'),
            ])),
        ]);
    }
}
