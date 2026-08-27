<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Pick NexPractice problems for battles.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Problem helpers (depends on local_learnlogic).
 */
class problems {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $CFG, $DB;
        if (!is_readable($CFG->dirroot . '/local/learnlogic/version.php')) {
            return false;
        }
        try {
            return $DB->get_manager()->table_exists('local_learnlogic_problem');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pick a random ready problem neither player has already solved in NexPractice.
     *
     * @param string $difficulty empty = any
     * @param int[] $userids battle participants
     * @return int problem id
     */
    public static function pick_random(string $difficulty = '', array $userids = []): int {
        global $DB;

        if (!self::available()) {
            throw new \moodle_exception('nolearnlogic', 'local_nexbattleground');
        }

        $difficulty = strtolower(trim($difficulty));
        if (!in_array($difficulty, ['easy', 'medium', 'hard', 'veryhard'], true)) {
            $difficulty = '';
        }

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function (int $id): bool {
            return $id > 0;
        })));

        $solved = self::solved_problem_ids($userids);

        // 1) Preferred: matching difficulty, unsolved by both.
        $ids = self::ready_ids($difficulty, $solved);
        // 2) Any difficulty, still unsolved by both.
        if (!$ids && $difficulty !== '') {
            $ids = self::ready_ids('', $solved);
        }
        // 3) Last resort so matchmaking never hard-fails: ignore solve history.
        if (!$ids) {
            $ids = self::ready_ids($difficulty, []);
        }
        if (!$ids) {
            $ids = self::ready_ids('', []);
        }
        if (!$ids) {
            throw new \moodle_exception('noproblems', 'local_nexbattleground');
        }

        return (int) $ids[array_rand($ids)];
    }

    /**
     * Problem IDs either user has ACCEPTED in NexPractice.
     *
     * @param int[] $userids
     * @return int[]
     */
    public static function solved_problem_ids(array $userids): array {
        global $DB;

        if (!$userids || !$DB->get_manager()->table_exists('local_learnlogic_submission')) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['st'] = 'ACCEPTED';
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT problemid
               FROM {local_learnlogic_submission}
              WHERE userid {$insql} AND status = :st",
            $params
        );
        $frompractice = array_values(array_unique(array_map('intval', $ids ?: [])));

        $frombattle = [];
        if ($DB->get_manager()->table_exists('local_nexbattleground_battle')) {
            $frombattle = array_map('intval', $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_nexbattleground_battle}
                  WHERE winnerid {$insql}
                    AND problemid > 0
                    AND status = 'finished'",
                $params
            ) ?: []);
        }

        return array_values(array_unique(array_merge($frompractice, $frombattle)));
    }

    /**
     * Ready problem IDs, optionally by difficulty, excluding a set.
     *
     * @param string $difficulty
     * @param int[] $exclude
     * @return int[]
     */
    private static function ready_ids(string $difficulty, array $exclude): array {
        global $DB;

        $params = [];
        $where = "status = 'ready'";
        if ($difficulty !== '') {
            $where .= ' AND difficulty = :diff';
            $params['diff'] = $difficulty;
        }
        if ($exclude) {
            list($insql, $inparams) = $DB->get_in_or_equal($exclude, SQL_PARAMS_NAMED, 'ex', false);
            $where .= " AND id {$insql}";
            $params = array_merge($params, $inparams);
        }

        $ids = $DB->get_fieldset_select('local_learnlogic_problem', 'id', $where, $params);
        return array_values(array_map('intval', $ids ?: []));
    }

    /**
     * Export problem payload for the battle IDE (samples only for run).
     *
     * @param int $problemid
     * @param int $userid
     * @return array|null
     */
    public static function export(int $problemid, int $userid = 0): ?array {
        if (!self::available()) {
            return null;
        }
        return \local_learnlogic\local\catalog::get_problem($problemid, $userid, true);
    }
}
