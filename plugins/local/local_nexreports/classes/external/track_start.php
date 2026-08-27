<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: begin a dwell-time tracking window for the current page.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexreports\local\tracking;

/**
 * Returns the tracking row id and flush frequency for the heartbeat client.
 */
class track_start extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Page context id'),
        ]);
    }

    public static function execute(int $contextid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid]);

        // Validate against the page's own context; any logged-in real user may track their time.
        $context = \core\context::instance_by_id($params['contextid'], IGNORE_MISSING)
            ?: \core\context\system::instance();
        self::validate_context($context);

        if (!tracking::enabled()) {
            return ['status' => false, 'id' => 0, 'frequency' => 0];
        }

        return [
            'status' => true,
            'id' => tracking::start((int) $params['contextid']),
            'frequency' => tracking::frequency(),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Whether tracking is active'),
            'id' => new external_value(PARAM_INT, 'Tracking row id'),
            'frequency' => new external_value(PARAM_INT, 'Flush frequency in seconds'),
        ]);
    }
}
