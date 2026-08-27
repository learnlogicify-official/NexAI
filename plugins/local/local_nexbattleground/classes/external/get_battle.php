<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\battle_service;

class get_battle extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'battleid' => new external_value(PARAM_INT, 'Battle id'),
        ]);
    }

    public static function execute(int $battleid): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['battleid' => $battleid]);
        $data = battle_service::export((int) $params['battleid'], (int) $USER->id);
        return self::flatten($data);
    }

    public static function flatten(array $data): array {
        return [
            'battleid' => (int) ($data['battleid'] ?? 0),
            'status' => (string) ($data['status'] ?? ''),
            'outcome' => (string) ($data['outcome'] ?? ''),
            'winnerid' => (int) ($data['winnerid'] ?? 0),
            'problemid' => (int) ($data['problemid'] ?? 0),
            'difficulty' => (string) ($data['difficulty'] ?? ''),
            'duration' => (int) ($data['duration'] ?? 0),
            'timestart' => (int) ($data['timestart'] ?? 0),
            'timefinish' => (int) ($data['timefinish'] ?? 0),
            'deadline' => (int) ($data['deadline'] ?? 0),
            'timeleft' => (int) ($data['timeleft'] ?? 0),
            'servertime' => (int) ($data['servertime'] ?? time()),
            'language' => (string) ($data['language'] ?? 'python3'),
            'code' => (string) ($data['code'] ?? ''),
            'canact' => !empty($data['canact']),
            'youJson' => json_encode($data['you'] ?? new \stdClass()),
            'opponentJson' => json_encode($data['opponent'] ?? new \stdClass()),
            'problemJson' => json_encode($data['problem'] ?? new \stdClass()),
            'summaryJson' => json_encode($data['summary'] ?? new \stdClass()),
            'result' => (string) (($data['summary']['result'] ?? '')),
            'roomcode' => (string) ($data['roomcode'] ?? ''),
            'xpAwarded' => (int) ($data['xpAwarded'] ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return self::battle_structure();
    }

    public static function battle_structure(): external_single_structure {
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
        ]);
    }
}
