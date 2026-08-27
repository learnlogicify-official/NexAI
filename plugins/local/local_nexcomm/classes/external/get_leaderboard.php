<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\gamification;

class get_leaderboard extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit' => new external_value(PARAM_INT, 'Limit', VALUE_DEFAULT, 50),
            'institution' => new external_value(PARAM_TEXT, 'Institution', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $limit = 50, string $institution = ''): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('limit', 'institution'));
        $institution = trim((string) $params['institution']);
        $entries = gamification::leaderboard((int) $params['limit'], $institution);
        foreach ($entries as &$e) {
            $e['isme'] = (int) $e['userid'] === (int) $USER->id;
        }
        unset($e);
        $stats = gamification::user_stats((int) $USER->id);
        return [
            'entries' => $entries,
            'institutions' => gamification::institutions(),
            'current' => [
                'rank' => gamification::leaderboard_rank((int) $USER->id, $institution),
                'xp' => (int) $stats['xp'],
                'institution' => trim((string) ($USER->institution ?? '')),
            ],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'rank'),
                'userid' => new external_value(PARAM_INT, 'uid'),
                'fullname' => new external_value(PARAM_TEXT, 'name'),
                'institution' => new external_value(PARAM_TEXT, 'inst'),
                'xp' => new external_value(PARAM_INT, 'xp'),
                'reading' => new external_value(PARAM_INT, 'reading'),
                'listening' => new external_value(PARAM_INT, 'listening'),
                'speaking' => new external_value(PARAM_INT, 'speaking'),
                'writing' => new external_value(PARAM_INT, 'writing'),
                'isme' => new external_value(PARAM_BOOL, 'me'),
            ])),
            'institutions' => new external_multiple_structure(new external_value(PARAM_TEXT, 'inst')),
            'current' => new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'rank'),
                'xp' => new external_value(PARAM_INT, 'xp'),
                'institution' => new external_value(PARAM_TEXT, 'inst'),
            ]),
        ]);
    }
}
