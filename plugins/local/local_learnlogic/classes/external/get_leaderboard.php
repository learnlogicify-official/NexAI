<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: leaderboard.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_learnlogic\local\gamification;

/**
 * XP leaderboard.
 */
class get_leaderboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit' => new external_value(PARAM_INT, 'Legacy page size', VALUE_DEFAULT, 25),
            'institution' => new external_value(PARAM_TEXT, 'Institution filter', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Zero-based page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Rows per page', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $limit = 25,
        string $institution = '',
        int $page = 0,
        int $perpage = 0
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/learnlogic:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'limit' => $limit,
            'institution' => $institution,
            'page' => $page,
            'perpage' => $perpage,
        ]);
        $institution = trim((string) $params['institution']);
        $pagesize = (int) $params['perpage'] > 0 ? (int) $params['perpage'] : (int) $params['limit'];
        $board = gamification::leaderboard($pagesize, $institution, (int) $params['page'], $pagesize);
        $entries = $board['entries'];
        foreach ($entries as &$entry) {
            $entry['isme'] = (int) $entry['userid'] === (int) $USER->id;
        }
        unset($entry);

        $stats = gamification::user_stats((int) $USER->id);
        return [
            'entries' => $entries,
            'total' => (int) $board['total'],
            'page' => (int) $board['page'],
            'perpage' => (int) $board['perpage'],
            'institutions' => gamification::leaderboard_institutions(),
            'current' => [
                'rank' => gamification::leaderboard_rank((int) $USER->id, $institution),
                'xp' => (int) $stats['xp'],
                'solved' => (int) $stats['solved'],
                'solvedeasy' => (int) ($stats['solvedeasy'] ?? 0),
                'solvedmedium' => (int) ($stats['solvedmedium'] ?? 0),
                'solvedhard' => (int) ($stats['solvedhard'] ?? 0),
                'solvedveryhard' => (int) ($stats['solvedveryhard'] ?? 0),
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
            'xp' => new external_value(PARAM_INT, 'xp'),
            'solved' => new external_value(PARAM_INT, 'solved'),
            'solvedeasy' => new external_value(PARAM_INT, 'Easy solved', VALUE_DEFAULT, 0),
            'solvedmedium' => new external_value(PARAM_INT, 'Medium solved', VALUE_DEFAULT, 0),
            'solvedhard' => new external_value(PARAM_INT, 'Hard solved', VALUE_DEFAULT, 0),
            'solvedveryhard' => new external_value(PARAM_INT, 'Very hard solved', VALUE_DEFAULT, 0),
            'isme' => new external_value(PARAM_BOOL, 'Whether this is the current user'),
        ]);
        return new external_single_structure([
            'entries' => new external_multiple_structure($entry),
            'total' => new external_value(PARAM_INT, 'Total ranked users'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Page size'),
            'institutions' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Institution')
            ),
            'current' => new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'Current user rank, or zero'),
                'xp' => new external_value(PARAM_INT, 'Current user XP'),
                'solved' => new external_value(PARAM_INT, 'Current user solved count'),
                'solvedeasy' => new external_value(PARAM_INT, 'Easy solved', VALUE_DEFAULT, 0),
                'solvedmedium' => new external_value(PARAM_INT, 'Medium solved', VALUE_DEFAULT, 0),
                'solvedhard' => new external_value(PARAM_INT, 'Hard solved', VALUE_DEFAULT, 0),
                'solvedveryhard' => new external_value(PARAM_INT, 'Very hard solved', VALUE_DEFAULT, 0),
                'institution' => new external_value(PARAM_TEXT, 'Current user institution'),
            ]),
        ]);
    }
}
