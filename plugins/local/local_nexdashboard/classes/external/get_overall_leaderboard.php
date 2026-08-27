<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: overall student leaderboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexdashboard\local\overall_leaderboard;

/**
 * Paginated overall leaderboard.
 */
class get_overall_leaderboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, '1-based page', VALUE_DEFAULT, 1),
            'perpage' => new external_value(PARAM_INT, 'rows per page', VALUE_DEFAULT, overall_leaderboard::PERPAGE),
            'institution' => new external_value(PARAM_TEXT, 'college filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $page = 1, int $perpage = overall_leaderboard::PERPAGE, string $institution = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'page' => $page,
            'perpage' => $perpage,
            'institution' => $institution,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexdashboard:view', $context);

        return overall_leaderboard::page(
            (int) $params['page'],
            (int) $params['perpage'],
            (string) $params['institution'],
            (int) $USER->id
        );
    }

    public static function execute_returns(): external_single_structure {
        $entry = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'rank'),
            'userid' => new external_value(PARAM_INT, 'userid'),
            'fullname' => new external_value(PARAM_TEXT, 'name'),
            'institution' => new external_value(PARAM_TEXT, 'institution'),
            'picture' => new external_value(PARAM_RAW, 'avatar url', VALUE_OPTIONAL),
            'coursegrade' => new external_value(PARAM_INT, 'course grades'),
            'practicexp' => new external_value(PARAM_INT, 'NexPractice XP'),
            'codelabxp' => new external_value(PARAM_INT, 'CodeLab XP'),
            'battlexp' => new external_value(PARAM_INT, 'BattleGround XP'),
            'total' => new external_value(PARAM_INT, 'total'),
            'isme' => new external_value(PARAM_BOOL, 'current user'),
        ]);
        $current = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'rank, 0 if unranked'),
            'coursegrade' => new external_value(PARAM_INT, 'course grades'),
            'practicexp' => new external_value(PARAM_INT, 'NexPractice XP'),
            'codelabxp' => new external_value(PARAM_INT, 'CodeLab XP'),
            'battlexp' => new external_value(PARAM_INT, 'BattleGround XP'),
            'total' => new external_value(PARAM_INT, 'total'),
        ]);

        return new external_single_structure([
            'entries' => new external_multiple_structure($entry),
            'top3' => new external_multiple_structure($entry, 'site-wide top 3', VALUE_OPTIONAL),
            'institutions' => new external_multiple_structure(new external_value(PARAM_TEXT, 'institution')),
            'current' => $current,
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'per page'),
            'total' => new external_value(PARAM_INT, 'total rows'),
        ]);
    }
}
