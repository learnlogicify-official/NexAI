<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Battle win leaderboard helpers.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Rank players by finished-battle wins.
 */
class leaderboard {

    /**
     * Aggregated battle stats for users (optionally filtered by institution).
     *
     * @param string $institution
     * @return array<int, array>
     */
    public static function stats_rows(string $institution = ''): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_nexbattleground_player')) {
            return [];
        }

        $institution = trim($institution);
        $params = [];
        $instsql = '';
        if ($institution !== '') {
            $instsql = ' AND u.institution = :institution';
            $params['institution'] = $institution;
        }

        $sql = "SELECT p.userid,
                       SUM(CASE WHEN p.result = 'win' THEN 1 ELSE 0 END) AS wins,
                       SUM(CASE WHEN p.result = 'loss' THEN 1 ELSE 0 END) AS losses,
                       SUM(CASE WHEN p.result = 'tie' THEN 1 ELSE 0 END) AS ties,
                       COUNT(1) AS battles,
                       SUM(CASE WHEN p.result = 'win' AND b.difficulty = 'easy' THEN 1 ELSE 0 END) AS easywins,
                       SUM(CASE WHEN p.result = 'win' AND b.difficulty = 'medium' THEN 1 ELSE 0 END) AS mediumwins,
                       SUM(CASE WHEN p.result = 'win' AND b.difficulty = 'hard' THEN 1 ELSE 0 END) AS hardwins,
                       SUM(CASE WHEN p.result = 'win' AND b.difficulty = 'veryhard' THEN 1 ELSE 0 END) AS veryhardwins
                  FROM {local_nexbattleground_player} p
                  JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
                  JOIN {user} u ON u.id = p.userid
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND b.status = 'finished'
                   AND b.outcome NOT IN ('declined', 'cancelled')
                   {$instsql}
              GROUP BY p.userid
                HAVING COUNT(1) > 0";

        $rows = $DB->get_records_sql($sql, $params);
        if (!$rows) {
            return [];
        }

        $xpmap = self::battle_xp_map(array_keys($rows));
        $out = [];
        foreach ($rows as $row) {
            $userid = (int) $row->userid;
            $u = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, institution');
            $wins = (int) $row->wins;
            $losses = (int) $row->losses;
            $out[$userid] = [
                'userid' => $userid,
                'wins' => $wins,
                'losses' => $losses,
                'ties' => (int) $row->ties,
                'battles' => (int) $row->battles,
                'easywins' => (int) $row->easywins,
                'mediumwins' => (int) $row->mediumwins,
                'hardwins' => (int) $row->hardwins,
                'veryhardwins' => (int) $row->veryhardwins,
                'battlexp' => (int) ($xpmap[$userid] ?? 0),
                'fullname' => $u ? fullname($u) : get_string('opponent', 'local_nexbattleground'),
                'institution' => $u ? trim((string) $u->institution) : '',
            ];
        }

        uasort($out, static function (array $a, array $b): int {
            if ($a['wins'] !== $b['wins']) {
                return $b['wins'] <=> $a['wins'];
            }
            $arate = self::win_rate($a['wins'], $a['losses']);
            $brate = self::win_rate($b['wins'], $b['losses']);
            if ($arate !== $brate) {
                return $brate <=> $arate;
            }
            if ($a['battlexp'] !== $b['battlexp']) {
                return $b['battlexp'] <=> $a['battlexp'];
            }
            if ($a['battles'] !== $b['battles']) {
                return $b['battles'] <=> $a['battles'];
            }
            return $a['userid'] <=> $b['userid'];
        });

        return $out;
    }

    /**
     * Top N leaderboard entries.
     *
     * @param int $limit
     * @param string $institution
     * @return array
     */
    public static function entries(int $limit = 50, string $institution = ''): array {
        $limit = max(1, min(200, $limit));
        $rows = self::stats_rows($institution);
        $entries = [];
        $rank = 1;
        foreach ($rows as $row) {
            if ($rank > $limit) {
                break;
            }
            $entries[] = self::format_entry($row, $rank);
            $rank++;
        }
        return $entries;
    }

    /**
     * Positional rank for a user (0 if unranked).
     *
     * @param int $userid
     * @param string $institution
     * @return int
     */
    public static function rank_for(int $userid, string $institution = ''): int {
        if ($userid < 1) {
            return 0;
        }
        $rank = 1;
        foreach (self::stats_rows($institution) as $row) {
            if ((int) $row['userid'] === $userid) {
                return $rank;
            }
            $rank++;
        }
        return 0;
    }

    /**
     * Stats for one user.
     *
     * @param int $userid
     * @param string $institution
     * @return array
     */
    public static function user_stats(int $userid, string $institution = ''): array {
        $empty = [
            'wins' => 0,
            'losses' => 0,
            'ties' => 0,
            'battles' => 0,
            'easywins' => 0,
            'mediumwins' => 0,
            'hardwins' => 0,
            'veryhardwins' => 0,
            'battlexp' => 0,
            'winrate' => 0,
        ];
        if ($userid < 1) {
            return $empty;
        }
        $rows = self::stats_rows($institution);
        if (empty($rows[$userid])) {
            return $empty;
        }
        $row = $rows[$userid];
        return [
            'wins' => (int) $row['wins'],
            'losses' => (int) $row['losses'],
            'ties' => (int) $row['ties'],
            'battles' => (int) $row['battles'],
            'easywins' => (int) $row['easywins'],
            'mediumwins' => (int) $row['mediumwins'],
            'hardwins' => (int) $row['hardwins'],
            'veryhardwins' => (int) $row['veryhardwins'],
            'battlexp' => (int) $row['battlexp'],
            'winrate' => self::win_rate((int) $row['wins'], (int) $row['losses']),
        ];
    }

    /**
     * Distinct institutions among battlers.
     *
     * @return string[]
     */
    public static function institutions(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexbattleground_player')) {
            return [];
        }
        $sql = "SELECT DISTINCT u.institution
                  FROM {local_nexbattleground_player} p
                  JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
                  JOIN {user} u ON u.id = p.userid
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND u.institution <> ''
                   AND b.status = 'finished'
                   AND b.outcome NOT IN ('declined', 'cancelled')
              ORDER BY u.institution ASC";
        $raw = $DB->get_fieldset_sql($sql);
        $out = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array $row
     * @param int $rank
     * @return array
     */
    private static function format_entry(array $row, int $rank): array {
        $wins = (int) $row['wins'];
        $losses = (int) $row['losses'];
        return [
            'rank' => $rank,
            'userid' => (int) $row['userid'],
            'fullname' => (string) $row['fullname'],
            'institution' => (string) $row['institution'],
            'wins' => $wins,
            'easywins' => (int) $row['easywins'],
            'mediumwins' => (int) $row['mediumwins'],
            'hardwins' => (int) $row['hardwins'],
            'veryhardwins' => (int) $row['veryhardwins'],
            'losses' => $losses,
            'ties' => (int) $row['ties'],
            'battles' => (int) $row['battles'],
            'battlexp' => (int) $row['battlexp'],
            'winrate' => self::win_rate($wins, $losses),
        ];
    }

    /**
     * @param int $wins
     * @param int $losses
     * @return int
     */
    private static function win_rate(int $wins, int $losses): int {
        $decided = $wins + $losses;
        if ($decided < 1) {
            return 0;
        }
        return (int) round(($wins / $decided) * 100);
    }

    /**
     * @param int[] $userids
     * @return array<int,int>
     */
    private static function battle_xp_map(array $userids): array {
        global $DB;
        $map = [];
        if (!$userids || !$DB->get_manager()->table_exists('local_learnlogic_xpevent')) {
            return $map;
        }
        list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $sql = "SELECT userid, SUM(amount) AS xp
                  FROM {local_learnlogic_xpevent}
                 WHERE userid {$insql}
                   AND " . $DB->sql_like('reason', ':pat') . "
              GROUP BY userid";
        $params['pat'] = 'battle_win_%';
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $map[(int) $row->userid] = (int) $row->xp;
        }
        return $map;
    }
}
