<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexPractice (local_learnlogic) reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Practice leaderboard and site-wide practice KPIs.
 */
class practice_report {

    /** XP event reasons awarded for solving practice problems. */
    private const PRACTICE_XP_REASONS = ['solve', 'firstbonus', 'streak'];

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_learnlogic', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_learnlogic_submission');
    }

    /**
     * Practice leaderboard with college / year / department / cohort filters.
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

        $userids = self::practice_userids();
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
            'total' => $summary['activepracticers'],
        ] + $meta;
    }

    /**
     * Users with at least one practice submission or XP row.
     *
     * @return int[]
     */
    private static function practice_userids(): array {
        global $DB;
        $fromxp = $DB->get_fieldset_sql("SELECT userid FROM {local_learnlogic_userxp}") ?: [];
        $fromsub = $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {local_learnlogic_submission}") ?: [];
        return array_values(array_unique(array_map('intval', array_merge($fromxp, $fromsub))));
    }

    /**
     * @param int[] $userids
     * @param int $limit
     * @return array
     */
    private static function rows_for_users(array $userids, int $limit): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $inparams['accepted'] = 'ACCEPTED';
        $inparams['rsolve'] = 'solve';
        $inparams['rfirst'] = 'firstbonus';
        $inparams['rstreak'] = 'streak';

        $records = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.institution, u.department,
                    u.idnumber, u.lastaccess,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                    COALESCE(x.xp, 0) AS xp,
                    COALESCE(x.timemodified, 0) AS xpmodified,
                    COALESCE(st.currentstreak, 0) AS streak,
                    COALESCE(st.longest, 0) AS longest,
                    COALESCE(sol.cnt, 0) AS solved,
                    COALESCE(att.cnt, 0) AS attempts,
                    COALESCE(att.lastat, 0) AS lastsubmission,
                    COALESCE(prxp.practicexp, 0) AS practicexp_sort
               FROM {user} u
          LEFT JOIN {local_learnlogic_userxp} x ON x.userid = u.id
          LEFT JOIN {local_learnlogic_streak} st ON st.userid = u.id
          LEFT JOIN (
                SELECT userid, COUNT(DISTINCT problemid) AS cnt
                  FROM {local_learnlogic_submission}
                 WHERE status = :accepted
              GROUP BY userid
               ) sol ON sol.userid = u.id
          LEFT JOIN (
                SELECT userid, COUNT(1) AS cnt, MAX(timecreated) AS lastat
                  FROM {local_learnlogic_submission}
              GROUP BY userid
               ) att ON att.userid = u.id
          LEFT JOIN (
                SELECT userid, SUM(amount) AS practicexp
                  FROM {local_learnlogic_xpevent}
                 WHERE reason IN (:rsolve, :rfirst, :rstreak)
              GROUP BY userid
               ) prxp ON prxp.userid = u.id
              WHERE u.id {$insql}
                AND u.deleted = 0
           ORDER BY practicexp_sort DESC, xpmodified ASC, u.id ASC",
            $inparams,
            0,
            $limit
        );

        $breakdown = self::xp_breakdown_for_users(array_map('intval', array_keys($records)));
        $unspecified = get_string('notset', 'local_nexreports');
        $never = get_string('never', 'local_nexreports');
        $rows = [];
        $rank = 1;

        foreach ($records as $user) {
            $uid = (int) $user->id;
            $lastts = max((int) $user->lastsubmission, (int) $user->xpmodified);
            $college = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));
            $totalxp = (int) $user->xp;
            $parts = $breakdown[$uid] ?? ['practicexp' => 0, 'bonusxp' => 0];
            $practicexp = (int) $parts['practicexp'];
            $bonusxp = (int) $parts['bonusxp'] + max(0, $totalxp - $practicexp - (int) $parts['bonusxp']);

            $rows[] = [
                'rank' => $rank++,
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
                'practiceUrl' => (new \moodle_url('/local/learnlogic/submissions.php'))->out(false),
                'lastaccess' => $user->lastaccess
                    ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                    : $never,
                'xp' => $totalxp,
                'practicexp' => $practicexp,
                'bonusxp' => $bonusxp,
                'solved' => (int) $user->solved,
                'streak' => (int) $user->streak,
                'longest' => (int) $user->longest,
                'attempts' => (int) $user->attempts,
                'lastactivity' => $lastts
                    ? userdate($lastts, get_string('strftimedatetimeshort', 'langconfig'))
                    : $never,
            ];
        }

        return $rows;
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
        $inparams['accepted'] = 'ACCEPTED';

        $stats = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT u.id) AS practicers,
                    COALESCE(SUM(x.xp), 0) AS totalxp,
                    COALESCE(SUM(prxp.practicexp), 0) AS practicexpsum,
                    COALESCE(SUM(sol.cnt), 0) AS solvedsum,
                    COALESCE(SUM(att.cnt), 0) AS submissions
               FROM {user} u
          LEFT JOIN {local_learnlogic_userxp} x ON x.userid = u.id
          LEFT JOIN (
                SELECT userid, SUM(amount) AS practicexp
                  FROM {local_learnlogic_xpevent}
                 WHERE reason IN (:rsolve, :rfirst, :rstreak)
              GROUP BY userid
               ) prxp ON prxp.userid = u.id
          LEFT JOIN (
                SELECT userid, COUNT(DISTINCT problemid) AS cnt
                  FROM {local_learnlogic_submission}
                 WHERE status = :accepted
              GROUP BY userid
               ) sol ON sol.userid = u.id
          LEFT JOIN (
                SELECT userid, COUNT(1) AS cnt
                  FROM {local_learnlogic_submission}
              GROUP BY userid
               ) att ON att.userid = u.id
              WHERE u.id {$insql}",
            $inparams + [
                'rsolve' => 'solve',
                'rfirst' => 'firstbonus',
                'rstreak' => 'streak',
            ]
        );

        $published = 0;
        if ($DB->get_manager()->table_exists('local_learnlogic_problem')) {
            $published = (int) $DB->count_records('local_learnlogic_problem', ['status' => 'ready']);
        }

        return [
            'activepracticers' => (int) ($stats->practicers ?? 0),
            'totalxp' => (int) ($stats->practicexpsum ?? 0),
            'problemssolved' => (int) ($stats->solvedsum ?? 0),
            'totalsubmissions' => (int) ($stats->submissions ?? 0),
            'publishedproblems' => $published,
        ];
    }

    /**
     * @return array
     */
    private static function empty_summary(): array {
        return [
            'activepracticers' => 0,
            'totalxp' => 0,
            'problemssolved' => 0,
            'totalsubmissions' => 0,
            'publishedproblems' => 0,
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
     * Split logged XP events into practice solves vs other sources (e.g. BattleGround).
     *
     * @param int[] $userids
     * @return array<int, array{practicexp:int,bonusxp:int}>
     */
    private static function xp_breakdown_for_users(array $userids): array {
        global $DB;

        $out = [];
        foreach ($userids as $uid) {
            $out[(int) $uid] = ['practicexp' => 0, 'bonusxp' => 0];
        }
        if (!$userids || !$DB->get_manager()->table_exists('local_learnlogic_xpevent')) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $records = $DB->get_records_sql(
            "SELECT userid, reason, SUM(amount) AS amt
               FROM {local_learnlogic_xpevent}
              WHERE userid {$insql}
           GROUP BY userid, reason",
            $params
        );

        foreach ($records as $rec) {
            $uid = (int) $rec->userid;
            $amt = (int) $rec->amt;
            if (!isset($out[$uid])) {
                $out[$uid] = ['practicexp' => 0, 'bonusxp' => 0];
            }
            if (in_array((string) $rec->reason, self::PRACTICE_XP_REASONS, true)) {
                $out[$uid]['practicexp'] += $amt;
            } else {
                $out[$uid]['bonusxp'] += $amt;
            }
        }

        return $out;
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
