<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: deferred learning-time payload (hero + analytics Time Spent).
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
 * Learning time only — loaded after the main dashboard paints.
 */
class get_learning_time extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexdashboard:view', $context);
        \core\session\manager::write_close();
        return aggregator::build_learning_time((int) $USER->id);
    }

    public static function execute_returns(): external_single_structure {
        $chartpoint = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'value' => new external_value(PARAM_INT, 'value'),
        ]);
        $chartbundle = new external_single_structure([
            'series' => new external_multiple_structure($chartpoint),
            'avg' => new external_value(PARAM_FLOAT, 'avg'),
            'trend' => new external_value(PARAM_INT, 'trend'),
            'avgLabel' => new external_value(PARAM_TEXT, 'avg label'),
        ]);
        return new external_single_structure([
            'learningTime' => new external_value(PARAM_TEXT, 'formatted learning time'),
            'totalTimeMinutes' => new external_value(PARAM_INT, 'total minutes'),
            'charts' => new external_single_structure([
                'daily' => $chartbundle,
                'weekly' => $chartbundle,
                'monthly' => $chartbundle,
            ], 'time metric chart bundles by period'),
        ]);
    }
}
