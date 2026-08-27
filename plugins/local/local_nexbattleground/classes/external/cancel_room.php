<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class cancel_room extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'battleid' => new external_value(PARAM_INT, 'Battle id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $battleid = 0): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['battleid' => $battleid]);
        return matchmaker::cancel_room((int) $USER->id, (int) $params['battleid']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Done'),
        ]);
    }
}
