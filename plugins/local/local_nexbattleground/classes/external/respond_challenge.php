<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class respond_challenge extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
            'accept' => new external_value(PARAM_BOOL, 'Accept?'),
        ]);
    }

    public static function execute(int $battleid, bool $accept): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('battleid', 'accept'));
        return matchmaker::respond_challenge((int) $USER->id, (int) $params['battleid'], (bool) $params['accept']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'id'),
            'status' => new external_value(PARAM_TEXT, 'status'),
            'accepted' => new external_value(PARAM_BOOL, 'accepted'),
        ]);
    }
}
