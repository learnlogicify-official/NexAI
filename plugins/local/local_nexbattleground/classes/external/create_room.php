<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class create_room extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'Difficulty filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $difficulty = ''): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['difficulty' => $difficulty]);
        return matchmaker::create_room((int) $USER->id, (string) $params['difficulty']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
            'roomcode' => new external_value(PARAM_ALPHANUM, 'Six-digit code'),
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
