<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: add measured seconds to a dwell-time tracking window.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_nexreports\local\tracking;

/**
 * Heartbeat endpoint; only updates rows owned by the calling user.
 */
class track_ping extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Tracking row id'),
            'time' => new external_value(PARAM_INT, 'Seconds to add'),
        ]);
    }

    public static function execute(int $id, int $time): bool {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id, 'time' => $time]);
        self::validate_context(\core\context\system::instance());

        if (!tracking::enabled()) {
            return false;
        }

        return tracking::ping((int) $params['id'], (int) $params['time']);
    }

    public static function execute_returns(): external_value {
        return new external_value(PARAM_BOOL, 'Status');
    }
}
