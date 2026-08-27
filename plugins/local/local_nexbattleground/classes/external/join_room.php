<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class join_room extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_ALPHANUM, 'Six-digit room code'),
        ]);
    }

    public static function execute(string $code): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['code' => $code]);
        return matchmaker::join_room((int) $USER->id, (string) $params['code']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'roomcode' => new external_value(PARAM_ALPHANUM, 'Code'),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty'),
        ]);
    }
}
