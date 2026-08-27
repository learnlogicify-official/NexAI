<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: set weekly goal target.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexdashboard\local\aggregator;

/**
 * Persist student weekly goal (3 / 5 / 7).
 */
class set_goal extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'target' => new external_value(PARAM_INT, 'Weekly goal target: 3, 5, or 7'),
        ]);
    }

    public static function execute(int $target): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexdashboard:view', $context);
        return aggregator::set_weekly_goal((int) $USER->id, $target);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'current' => new external_value(PARAM_INT, 'current'),
            'target' => new external_value(PARAM_INT, 'target'),
            'pct' => new external_value(PARAM_INT, 'pct'),
            'done' => new external_value(PARAM_BOOL, 'done'),
            'choices' => new external_multiple_structure(new external_value(PARAM_INT, 'choice')),
        ]);
    }
}
