<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\battle_service;

class forfeit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
        ]);
    }

    public static function execute(int $battleid): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['battleid' => $battleid]);
        $data = battle_service::forfeit((int) $params['battleid'], (int) $USER->id);
        return get_battle::flatten($data);
    }

    public static function execute_returns(): external_single_structure {
        return get_battle::battle_structure();
    }
}
