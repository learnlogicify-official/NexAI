<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class challenge_user extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_RAW_TRIMMED, 'Username / email / idnumber'),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'Difficulty', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $username, string $difficulty = ''): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('username', 'difficulty'));
        $out = matchmaker::challenge_user((int) $USER->id, (string) $params['username'], (string) $params['difficulty']);
        return [
            'battleid' => (int) ($out['battleid'] ?? 0),
            'status' => (string) ($out['status'] ?? ''),
            'difficulty' => (string) ($out['difficulty'] ?? $params['difficulty']),
            'message' => (string) ($out['message'] ?? ''),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
