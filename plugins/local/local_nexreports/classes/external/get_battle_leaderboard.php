<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: NexBattleGround leaderboard report.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexreports\local\access;
use local_nexreports\local\battle_report;

/**
 * NexBattleGround tab — wins, win rate, and battle XP.
 */
class get_battle_leaderboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cohortid' => new external_value(PARAM_INT, 'Cohort filter', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Name/email search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
            'institution' => new external_value(PARAM_TEXT, 'College filter', VALUE_DEFAULT, ''),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $cohortid = 0,
        string $search = '',
        int $limit = 500,
        string $institution = '',
        string $year = '',
        string $department = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        if (access::is_scoped()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('nexbattleground', 'local_nexreports'));
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'cohortid' => $cohortid,
            'search' => $search,
            'limit' => $limit,
            'institution' => $institution,
            'year' => $year,
            'department' => $department,
        ]);

        return battle_report::leaderboard(
            (int) $params['cohortid'],
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['institution'],
            (string) $params['year'],
            (string) $params['department']
        );
    }

    public static function execute_returns(): external_single_structure {
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        $row = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'username' => new external_value(PARAM_TEXT, 'Username'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'institution' => new external_value(PARAM_TEXT, 'College'),
            'yearofpassing' => new external_value(PARAM_TEXT, 'Year of passing'),
            'department' => new external_value(PARAM_TEXT, 'Department'),
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'battleUrl' => new external_value(PARAM_URL, 'Battle leaderboard URL'),
            'lastaccess' => new external_value(PARAM_TEXT, 'Last access'),
            'battlexp' => new external_value(PARAM_INT, 'Battle XP'),
            'wins' => new external_value(PARAM_INT, 'Wins'),
            'losses' => new external_value(PARAM_INT, 'Losses'),
            'ties' => new external_value(PARAM_INT, 'Ties'),
            'battles' => new external_value(PARAM_INT, 'Battles'),
            'winrate' => new external_value(PARAM_INT, 'Win rate percent'),
            'attempts' => new external_value(PARAM_INT, 'Battle submissions'),
            'lastactivity' => new external_value(PARAM_TEXT, 'Last battle activity'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'rows' => new external_multiple_structure($row),
            'summary' => new external_single_structure([
                'activebattlers' => new external_value(PARAM_INT, 'Active battlers'),
                'finishedbattles' => new external_value(PARAM_INT, 'Finished battles'),
                'totalwins' => new external_value(PARAM_INT, 'Total wins'),
                'totalxp' => new external_value(PARAM_INT, 'Battle XP'),
                'totalsubmissions' => new external_value(PARAM_INT, 'Battle submissions'),
            ]),
            'cohorts' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
            ])),
            'colleges' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'selectedcohortid' => new external_value(PARAM_INT, 'Selected cohort'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available'),
            'search' => new external_value(PARAM_TEXT, 'Search'),
            'total' => new external_value(PARAM_INT, 'Total matched'),
        ]);
    }
}
