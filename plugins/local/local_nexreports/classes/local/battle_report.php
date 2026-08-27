<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexBattleGround reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Battle win leaderboard and site-wide battle KPIs.
 *
 * Battle XP lives in learnlogic xpevents (`battle_win_%`) and is not counted
 * as NexPractice solves.
 */
class battle_report {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexbattleground', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_nexbattleground_player')
            && $dbman->table_exists('local_nexbattleground_battle');
    }

    /**
     * Battle leaderboard with college / year / department / cohort filters.
     *
     * @param int $cohortid
     * @param string $search
     * @param int $limit
     * @param string $institution
     * @param string $year
     * @param string $department
     * @return array
     */
    public static function leaderboard(
        int $cohortid = 0,
        string $search = '',
        int $limit = 500,
        string $institution = '',
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $search = trim($search);
        $institution = trim($institution);
        $year = trim($year);
        $department = trim($department);

        $colleges = profile_filters::search_institutions('', 100, 0);
        $years = profile_filters::search_years('', 100, 0, $institution);
        $departments = ($year !== '')
            ? profile_filters::search_departments('', 100, 0, $year, $institution)
            : [];

        $meta = [
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => 1,
            'showdepartment' => 1,
        ];

        if (!self::available()) {
            return self::empty_payload($cohortid, $search) + $meta;
        }

        $userids = self::battle_userids();
        $userids = access::filter_userids($userids);

        $profileids = profile_filters::userids(0, $year, $department, $institution);
        if ($profileids !== null) {
            $userids = array_values(array_intersect($userids, $profileids));
        }

        if ($cohortid > 0 && $userids) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $inparams['cohortid'] = $cohortid;
            $userids = array_map('intval', $DB->get_fieldset_sql(
                "SELECT userid FROM {cohort_members} WHERE cohortid = :cohortid AND userid {$insql}",
                $inparams
            ) ?: []);
        }

        if ($search !== '' && $userids) {
            $userids = self::filter_by_name($userids, $search);
        }

        if (!$userids) {
            return self::empty_payload($cohortid, $search) + $meta + [
                'summary' => self::empty_summary(),
            ];
        }

        $summary = self::summary_for_users($userids);
        $rows = self::rows_for_users($userids, $limit);

        return [
            'generated' => time(),
            'rows' => $rows,
            'summary' => $summary,
            'cohorts' => filters::cohorts(),
            'selectedcohortid' => $cohortid,
            'search' => $search,
            'total' => $summary['activebattlers'],
        ] + $meta;
    }

    /**
     * Users who played a finished battle.
     *
     * @return int[]
     */
    private static function battle_userids(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT p.userid
               FROM {local_nexbattleground_player} p
               JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
              WHERE b.status = 'finished'
                AND b.outcome NOT IN ('declined', 'cancelled')"
        ) ?: []);
    }

    /**
     * @param int[] $userids
     * @param int $limit
     * @return array
     */
    private static function rows_for_users(array $userids, int $limit): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $hassubs = $DB->get_manager()->table_exists('local_nexbattleground_sub');

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.institution, u.department,
                       u.idnumber, u.lastaccess,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       COALESCE(st.wins, 0) AS wins,
                       COALESCE(st.losses, 0) AS losses,
                       COALESCE(st.ties, 0) AS ties,
                       COALESCE(st.battles, 0) AS battles,
                       COALESCE(st.lastplayer, 0) AS lastplayer,
                       COALESCE(st.lastbattle, 0) AS lastbattle,
                       COALESCE(att.cnt, 0) AS attempts,
                       COALESCE(att.lastat, 0) AS lastsubmission
                  FROM {user} u
             LEFT JOIN (
                    SELECT p.userid,
                           SUM(CASE WHEN p.result = 'win' THEN 1 ELSE 0 END) AS wins,
                           SUM(CASE WHEN p.result = 'loss' THEN 1 ELSE 0 END) AS losses,
                           SUM(CASE WHEN p.result = 'tie' THEN 1 ELSE 0 END) AS ties,
                           COUNT(1) AS battles,
                           MAX(p.timemodified) AS lastplayer,
                           MAX(b.timefinish) AS lastbattle
                      FROM {local_nexbattleground_player} p
                      JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
                     WHERE b.status = 'finished'
                       AND b.outcome NOT IN ('declined', 'cancelled')
                  GROUP BY p.userid
                   ) st ON st.userid = u.id";
        if ($hassubs) {
            $sql .= "
             LEFT JOIN (
                    SELECT userid, COUNT(1) AS cnt, MAX(timecreated) AS lastat
                      FROM {local_nexbattleground_sub}
                  GROUP BY userid
                   ) att ON att.userid = u.id";
        } else {
            $sql .= "
             LEFT JOIN (
                    SELECT 0 AS userid, 0 AS cnt, 0 AS lastat
                   ) att ON att.userid = u.id";
        }
        $sql .= "
                 WHERE u.id {$insql}
                   AND u.deleted = 0";

        $records = $DB->get_records_sql($sql, $inparams);
        $xpmap = self::battle_xp_map(array_map('intval', array_keys($records)));

        $rows = [];
        foreach ($records as $user) {
            $uid = (int) $user->id;
            $wins = (int) $user->wins;
            $losses = (int) $user->losses;
            $rows[] = self::format_row($user, $wins, $losses, (int) ($xpmap[$uid] ?? 0));
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['wins'] !== $b['wins']) {
                return $b['wins'] <=> $a['wins'];
            }
            if ($a['winrate'] !== $b['winrate']) {
                return $b['winrate'] <=> $a['winrate'];
            }
            if ($a['battlexp'] !== $b['battlexp']) {
                return $b['battlexp'] <=> $a['battlexp'];
            }
            if ($a['battles'] !== $b['battles']) {
                return $b['battles'] <=> $a['battles'];
            }
            return $a['userid'] <=> $b['userid'];
        });

        $rank = 1;
        $out = [];
        foreach ($rows as $row) {
            $row['rank'] = $rank++;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param \stdClass $user
     * @param int $wins
     * @param int $losses
     * @param int $battlexp
     * @return array
     */
    private static function format_row($user, int $wins, int $losses, int $battlexp): array {
        $uid = (int) $user->id;
        $unspecified = get_string('notset', 'local_nexreports');
        $never = get_string('never');
        $college = trim((string) ($user->institution ?? ''));
        $userdepartment = trim((string) ($user->department ?? ''));
        $lastts = max((int) $user->lastplayer, (int) $user->lastbattle, (int) $user->lastsubmission);

        return [
            'rank' => 0,
            'userid' => $uid,
            'firstname' => (string) ($user->firstname ?? ''),
            'lastname' => (string) ($user->lastname ?? ''),
            'username' => (string) ($user->username ?? ''),
            'fullname' => fullname($user),
            'email' => (string) ($user->email ?? ''),
            'institution' => $college !== '' ? $college : '—',
            'yearofpassing' => overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            ),
            'department' => $userdepartment !== '' ? $userdepartment : '—',
            'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
            'battleUrl' => (new \moodle_url('/local/nexbattleground/leaderboard.php'))->out(false),
            'lastaccess' => $user->lastaccess
                ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                : $never,
            'battlexp' => $battlexp,
            'wins' => $wins,
            'losses' => $losses,
            'ties' => (int) $user->ties,
            'battles' => (int) $user->battles,
            'winrate' => self::win_rate($wins, $losses),
            'attempts' => (int) $user->attempts,
            'lastactivity' => $lastts
                ? userdate($lastts, get_string('strftimedatetimeshort', 'langconfig'))
                : $never,
        ];
    }

    /**
     * @param int[] $userids
     * @return array
     */
    private static function summary_for_users(array $userids): array {
        global $DB;

        if (!$userids) {
            return self::empty_summary();
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $stats = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT p.userid) AS battlers,
                    COUNT(DISTINCT p.battleid) AS finishedbattles,
                    SUM(CASE WHEN p.result = 'win' THEN 1 ELSE 0 END) AS wins
               FROM {local_nexbattleground_player} p
               JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
              WHERE p.userid {$insql}
                AND b.status = 'finished'
                AND b.outcome NOT IN ('declined', 'cancelled')",
            $inparams
        );

        $submissions = 0;
        if ($DB->get_manager()->table_exists('local_nexbattleground_sub')) {
            $submissions = (int) $DB->count_records_select(
                'local_nexbattleground_sub',
                "userid {$insql}",
                $inparams
            );
        }

        $xpmap = self::battle_xp_map($userids);
        $totalxp = 0;
        foreach ($xpmap as $amount) {
            $totalxp += (int) $amount;
        }

        return [
            'activebattlers' => (int) ($stats->battlers ?? 0),
            'finishedbattles' => (int) ($stats->finishedbattles ?? 0),
            'totalwins' => (int) ($stats->wins ?? 0),
            'totalxp' => $totalxp,
            'totalsubmissions' => $submissions,
        ];
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
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'uid');
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
     * @return array
     */
    private static function empty_summary(): array {
        return [
            'activebattlers' => 0,
            'finishedbattles' => 0,
            'totalwins' => 0,
            'totalxp' => 0,
            'totalsubmissions' => 0,
        ];
    }

    /**
     * @param int $cohortid
     * @param string $search
     * @return array
     */
    private static function empty_payload(int $cohortid, string $search): array {
        return [
            'generated' => time(),
            'rows' => [],
            'summary' => self::empty_summary(),
            'cohorts' => filters::cohorts(),
            'selectedcohortid' => $cohortid,
            'search' => $search,
            'total' => 0,
            'colleges' => [],
            'years' => [],
            'departments' => [],
            'selectedinstitution' => '',
            'selectedyear' => '',
            'selecteddepartment' => '',
            'showcollege' => 1,
            'showdepartment' => 1,
        ];
    }

    /**
     * @param int[] $userids
     * @param string $search
     * @return int[]
     */
    private static function filter_by_name(array $userids, string $search): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $fullnamefields = $DB->sql_fullname();
        $like = $DB->sql_like($fullnamefields, ':q', false)
            . ' OR ' . $DB->sql_like('u.email', ':q2', false)
            . ' OR ' . $DB->sql_like('u.username', ':q3', false);
        $params['q'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['q2'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['q3'] = '%' . $DB->sql_like_escape($search) . '%';
        $sql = "SELECT u.id FROM {user} u WHERE u.id {$insql} AND ({$like}) ORDER BY u.lastname, u.firstname";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params) ?: []);
    }
}
