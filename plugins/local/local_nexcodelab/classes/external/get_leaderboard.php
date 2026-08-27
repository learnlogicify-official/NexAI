<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: leaderboard.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\gamification;

/**
 * XP leaderboard.
 */
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
        require_capability('local/nexcodelab:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'limit' => $limit,
            'institution' => $institution,
        ]);
        $institution = trim((string) $params['institution']);
        $entries = gamification::leaderboard((int) $params['limit'], $institution);
        foreach ($entries as &$entry) {
            $entry['isme'] = (int) $entry['userid'] === (int) $USER->id;
        }
        unset($entry);

        $stats = gamification::user_stats((int) $USER->id);
        return [
            'entries' => $entries,
            'institutions' => gamification::leaderboard_institutions(),
            'current' => [
                'rank' => gamification::leaderboard_rank((int) $USER->id, $institution),
                'xp' => (int) $stats['xp'],
                'solved' => (int) $stats['solved'],
                'institution' => trim((string) ($USER->institution ?? '')),
            ],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'rank'),
                'userid' => new external_value(PARAM_INT, 'userid'),
                'fullname' => new external_value(PARAM_TEXT, 'fullname'),
                'institution' => new external_value(PARAM_TEXT, 'institution'),
                'xp' => new external_value(PARAM_INT, 'xp'),
                'solved' => new external_value(PARAM_INT, 'solved'),
                'isme' => new external_value(PARAM_BOOL, 'Whether this is the current user'),
            ])),
            'institutions' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Institution')
            ),
            'current' => new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'Current user rank, or zero'),
                'xp' => new external_value(PARAM_INT, 'Current user XP'),
                'solved' => new external_value(PARAM_INT, 'Current user solved count'),
                'institution' => new external_value(PARAM_TEXT, 'Current user institution'),
            ]),
        ]);
    }
}
