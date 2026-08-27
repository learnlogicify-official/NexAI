<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexBattleGround progress visible inside NexPractice (soft dependency).
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Problems the learner has won in NexBattleGround (not practice submissions).
 */
class battle_progress {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexbattleground', 'version') === false) {
            return false;
        }
        return $DB->get_manager()->table_exists('local_nexbattleground_battle');
    }

    /**
     * Distinct problem ids the user has won in a finished battle.
     *
     * @param int $userid
     * @param int[] $problemids Optional scope; empty = all wins for the user
     * @return int[]
     */
    public static function won_problem_ids(int $userid, array $problemids = []): array {
        global $DB;

        if ($userid < 1 || !self::available()) {
            return [];
        }

        $params = ['userid' => $userid];
        $scopesql = '';
        if ($problemids) {
            [$insql, $inparams] = $DB->get_in_or_equal($problemids, SQL_PARAMS_NAMED, 'pid');
            $scopesql = " AND problemid {$insql}";
            $params = array_merge($params, $inparams);
        }

        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT problemid
               FROM {local_nexbattleground_battle}
              WHERE winnerid = :userid
                AND problemid > 0
                AND status = 'finished'
                    {$scopesql}",
            $params
        ) ?: []);
    }

    /**
     * @param int $userid
     * @param int[] $problemids
     * @return array<int, true>
     */
    public static function won_map(int $userid, array $problemids = []): array {
        $map = [];
        foreach (self::won_problem_ids($userid, $problemids) as $pid) {
            $map[(int) $pid] = true;
        }
        return $map;
    }
}
