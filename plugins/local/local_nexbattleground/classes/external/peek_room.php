<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class peek_room extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_ALPHANUM, 'Six-digit room code'),
        ]);
    }

    public static function execute(string $code): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['code' => $code]);
        return matchmaker::peek_room((string) $params['code']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Room exists'),
            'roomcode' => new external_value(PARAM_ALPHANUM, 'Code'),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty'),
            'host' => new external_value(PARAM_TEXT, 'Host display name'),
        ]);
    }
}
