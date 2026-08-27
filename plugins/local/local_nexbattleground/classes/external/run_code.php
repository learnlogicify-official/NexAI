<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\battle_service;

class run_code extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
            'language' => new external_value(PARAM_TEXT, 'Language'),
            'code' => new external_value(PARAM_RAW, 'Source'),
        ]);
    }

    public static function execute(int $battleid, string $language, string $code): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:battle', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('battleid', 'language', 'code'));
        $result = battle_service::run(
            (int) $params['battleid'],
            (int) $USER->id,
            (string) $params['language'],
            (string) $params['code']
        );
        return [
            'ok' => true,
            'allPassed' => !empty($result['allPassed']),
            'passed' => (int) ($result['passed'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
            'message' => (string) ($result['message'] ?? ''),
            'resultJson' => json_encode($result),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'ok'),
            'allPassed' => new external_value(PARAM_BOOL, 'passed all samples'),
            'passed' => new external_value(PARAM_INT, 'passed'),
            'total' => new external_value(PARAM_INT, 'total'),
            'message' => new external_value(PARAM_TEXT, 'error'),
            'resultJson' => new external_value(PARAM_RAW, 'full result'),
        ]);
    }
}
