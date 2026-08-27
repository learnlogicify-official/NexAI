<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\battle_service;

class submit_code extends external_api {
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
        $payload = battle_service::submit(
            (int) $params['battleid'],
            (int) $USER->id,
            (string) $params['language'],
            (string) $params['code']
        );
        $flat = get_battle::flatten($payload);
        $judge = $payload['judge'] ?? null;
        $flat['won'] = !empty($payload['won']);
        $flat['statusLabel'] = (string) ($judge['statusLabel'] ?? '');
        $flat['judgeJson'] = json_encode($judge ?? new \stdClass());
        $flat['allPassed'] = !empty($judge['allPassed']);
        $flat['passed'] = (int) ($judge['passed'] ?? 0);
        $flat['total'] = (int) ($judge['total'] ?? 0);
        $flat['message'] = (string) ($judge['message'] ?? '');
        return $flat;
    }

    public static function execute_returns(): external_single_structure {
        $base = get_battle::battle_structure();
        // Merge judge fields — rebuild structure.
        return new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'id'),
            'status' => new external_value(PARAM_TEXT, 'status'),
            'outcome' => new external_value(PARAM_TEXT, 'outcome'),
            'winnerid' => new external_value(PARAM_INT, 'winner'),
            'problemid' => new external_value(PARAM_INT, 'problem'),
            'difficulty' => new external_value(PARAM_TEXT, 'diff'),
            'duration' => new external_value(PARAM_INT, 'secs'),
            'timestart' => new external_value(PARAM_INT, 'start'),
            'timefinish' => new external_value(PARAM_INT, 'end'),
            'deadline' => new external_value(PARAM_INT, 'deadline'),
            'timeleft' => new external_value(PARAM_INT, 'left'),
            'servertime' => new external_value(PARAM_INT, 'now'),
            'language' => new external_value(PARAM_TEXT, 'lang'),
            'code' => new external_value(PARAM_RAW, 'code'),
            'canact' => new external_value(PARAM_BOOL, 'can act'),
            'youJson' => new external_value(PARAM_RAW, 'you'),
            'opponentJson' => new external_value(PARAM_RAW, 'opponent'),
            'problemJson' => new external_value(PARAM_RAW, 'problem'),
            'summaryJson' => new external_value(PARAM_RAW, 'summary'),
            'result' => new external_value(PARAM_TEXT, 'your result'),
            'roomcode' => new external_value(PARAM_ALPHANUM, 'room code'),
            'xpAwarded' => new external_value(PARAM_INT, 'XP for viewer if win'),
            'won' => new external_value(PARAM_BOOL, 'just won'),
            'statusLabel' => new external_value(PARAM_TEXT, 'judge label'),
            'judgeJson' => new external_value(PARAM_RAW, 'judge'),
            'allPassed' => new external_value(PARAM_BOOL, 'all passed'),
            'passed' => new external_value(PARAM_INT, 'passed'),
            'total' => new external_value(PARAM_INT, 'total'),
            'message' => new external_value(PARAM_TEXT, 'message'),
        ]);
    }
}
