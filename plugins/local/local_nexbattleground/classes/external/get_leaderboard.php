<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\leaderboard;

class get_leaderboard extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit' => new external_value(PARAM_INT, 'Limit', VALUE_DEFAULT, 50),
            'institution' => new external_value(PARAM_TEXT, 'Institution filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $limit = 50, string $institution = ''): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'limit' => $limit,
            'institution' => $institution,
        ]);
        $institution = trim((string) $params['institution']);
        $entries = leaderboard::entries((int) $params['limit'], $institution);
        foreach ($entries as &$entry) {
            $entry['isme'] = (int) $entry['userid'] === (int) $USER->id;
        }
        unset($entry);

        $stats = leaderboard::user_stats((int) $USER->id, $institution);
        return [
            'entries' => $entries,
            'institutions' => leaderboard::institutions(),
            'current' => [
                'rank' => leaderboard::rank_for((int) $USER->id, $institution),
                'wins' => (int) $stats['wins'],
                'easywins' => (int) $stats['easywins'],
                'mediumwins' => (int) $stats['mediumwins'],
                'hardwins' => (int) $stats['hardwins'],
                'losses' => (int) $stats['losses'],
                'battles' => (int) $stats['battles'],
                'battlexp' => (int) $stats['battlexp'],
                'winrate' => (int) $stats['winrate'],
                'institution' => trim((string) ($USER->institution ?? '')),
            ],
        ];
    }

    public static function execute_returns(): external_single_structure {
        $entry = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'rank'),
            'userid' => new external_value(PARAM_INT, 'userid'),
            'fullname' => new external_value(PARAM_TEXT, 'fullname'),
            'institution' => new external_value(PARAM_TEXT, 'institution'),
            'wins' => new external_value(PARAM_INT, 'wins'),
            'easywins' => new external_value(PARAM_INT, 'easy wins'),
            'mediumwins' => new external_value(PARAM_INT, 'medium wins'),
            'hardwins' => new external_value(PARAM_INT, 'hard wins'),
            'veryhardwins' => new external_value(PARAM_INT, 'very hard wins'),
            'losses' => new external_value(PARAM_INT, 'losses'),
            'ties' => new external_value(PARAM_INT, 'ties'),
            'battles' => new external_value(PARAM_INT, 'battles'),
            'battlexp' => new external_value(PARAM_INT, 'battle xp'),
            'winrate' => new external_value(PARAM_INT, 'win rate percent'),
            'isme' => new external_value(PARAM_BOOL, 'current user'),
        ]);
        return new external_single_structure([
            'entries' => new external_multiple_structure($entry),
            'institutions' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Institution')),
            'current' => new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'rank or 0'),
                'wins' => new external_value(PARAM_INT, 'wins'),
                'easywins' => new external_value(PARAM_INT, 'easy wins'),
                'mediumwins' => new external_value(PARAM_INT, 'medium wins'),
                'hardwins' => new external_value(PARAM_INT, 'hard wins'),
                'losses' => new external_value(PARAM_INT, 'losses'),
                'battles' => new external_value(PARAM_INT, 'battles'),
                'battlexp' => new external_value(PARAM_INT, 'battle xp'),
                'winrate' => new external_value(PARAM_INT, 'win rate'),
                'institution' => new external_value(PARAM_TEXT, 'institution'),
            ]),
        ]);
    }
}
