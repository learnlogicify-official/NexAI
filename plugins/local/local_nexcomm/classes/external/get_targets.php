<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\targets;

class get_targets extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        return targets::summary((int) $USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'dailyDone' => new external_value(PARAM_INT, 'done'),
            'dailyGoal' => new external_value(PARAM_INT, 'goal'),
            'dailyPct' => new external_value(PARAM_INT, 'pct'),
            'dailyComplete' => new external_value(PARAM_BOOL, 'complete'),
            'weeklyDone' => new external_value(PARAM_INT, 'done'),
            'weeklyGoal' => new external_value(PARAM_INT, 'goal'),
            'weeklyPct' => new external_value(PARAM_INT, 'pct'),
            'weeklyComplete' => new external_value(PARAM_BOOL, 'complete'),
        ]);
    }
}
