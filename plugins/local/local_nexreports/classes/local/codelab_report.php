<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCodeLab reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * CodeLab XP leaderboard and site-wide CodeLab KPIs.
 */
class codelab_report {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexcodelab', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_nexcodelab_userxp')
            || $dbman->table_exists('local_nexcodelab_mission_progress');
    }

    /**
     * CodeLab leaderboard with college / year / department / cohort filters.
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

        $userids = self::codelab_userids();
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
            'total' => $summary['activelearners'],
        ] + $meta;
    }

    /**
     * Users with CodeLab XP, submissions, or mission progress.
     *
     * @return int[]
     */
    private static function codelab_userids(): array {
        global $DB;
        $ids = [];
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_nexcodelab_userxp')) {
            $ids = array_merge($ids, $DB->get_fieldset_sql("SELECT userid FROM {local_nexcodelab_userxp}") ?: []);
        }
        if ($dbman->table_exists('local_nexcodelab_submission')) {
            $ids = array_merge($ids, $DB->get_fieldset_sql(
                "SELECT DISTINCT userid FROM {local_nexcodelab_submission}"
            ) ?: []);
        }
        if ($dbman->table_exists('local_nexcodelab_mission_progress')) {
            $ids = array_merge($ids, $DB->get_fieldset_sql(
                "SELECT DISTINCT userid FROM {local_nexcodelab_mission_progress}"
            ) ?: []);
        }
        return array_values(array_unique(array_map('intval', $ids)));
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
        $dbman = $DB->get_manager();

        $missionjoin = $dbman->table_exists('local_nexcodelab_mission_progress')
            ? "LEFT JOIN (
                    SELECT userid, SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed,
                           COUNT(1) AS started, MAX(timemodified) AS lastmission
                      FROM {local_nexcodelab_mission_progress}
                  GROUP BY userid
               ) mp ON mp.userid = u.id"
            : "LEFT JOIN (
                    SELECT 0 AS userid, 0 AS completed, 0 AS started, 0 AS lastmission
               ) mp ON mp.userid = u.id";

        $subjoin = $dbman->table_exists('local_nexcodelab_submission')
            ? "LEFT JOIN (
                    SELECT userid, COUNT(DISTINCT problemid) AS cnt
                      FROM {local_nexcodelab_submission}
                     WHERE status = :accepted
                  GROUP BY userid
               ) sol ON sol.userid = u.id
               LEFT JOIN (
                    SELECT userid, COUNT(1) AS cnt, MAX(timecreated) AS lastat
                      FROM {local_nexcodelab_submission}
                  GROUP BY userid
               ) att ON att.userid = u.id"
            : "LEFT JOIN (
                    SELECT 0 AS userid, 0 AS cnt
               ) sol ON sol.userid = u.id
               LEFT JOIN (
                    SELECT 0 AS userid, 0 AS cnt, 0 AS lastat
               ) att ON att.userid = u.id";

        $xpjoin = $dbman->table_exists('local_nexcodelab_userxp')
            ? "LEFT JOIN {local_nexcodelab_userxp} x ON x.userid = u.id"
            : "LEFT JOIN (SELECT 0 AS userid, 0 AS xp, 0 AS timemodified) x ON x.userid = u.id";

        $streakjoin = $dbman->table_exists('local_nexcodelab_streak')
            ? "LEFT JOIN {local_nexcodelab_streak} st ON st.userid = u.id"
            : "LEFT JOIN (SELECT 0 AS userid, 0 AS currentstreak, 0 AS longest) st ON st.userid = u.id";

        $records = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.institution, u.department,
                    u.idnumber, u.lastaccess,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                    COALESCE(x.xp, 0) AS xp,
                    COALESCE(x.timemodified, 0) AS xpmodified,
                    COALESCE(st.currentstreak, 0) AS streak,
                    COALESCE(st.longest, 0) AS longest,
                    COALESCE(mp.completed, 0) AS missionscompleted,
                    COALESCE(mp.started, 0) AS missionsstarted,
                    COALESCE(mp.lastmission, 0) AS lastmission,
                    COALESCE(sol.cnt, 0) AS solved,
                    COALESCE(att.cnt, 0) AS attempts,
                    COALESCE(att.lastat, 0) AS lastsubmission
               FROM {user} u
                    {$xpjoin}
                    {$streakjoin}
                    {$missionjoin}
                    {$subjoin}
              WHERE u.id {$insql}
                AND u.deleted = 0
           ORDER BY xp DESC, xpmodified ASC, u.id ASC",
            $inparams,
            0,
            $limit
        );

        $unspecified = get_string('notset', 'local_nexreports');
        $never = get_string('never');
        $rows = [];
        $rank = 1;

        foreach ($records as $user) {
            $uid = (int) $user->id;
            $lastts = max((int) $user->lastsubmission, (int) $user->lastmission, (int) $user->xpmodified);
            $college = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));

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
                'codelabUrl' => (new \moodle_url('/local/nexcodelab/leaderboard.php'))->out(false),
                'lastaccess' => $user->lastaccess
                    ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                    : $never,
                'xp' => (int) $user->xp,
                'missionscompleted' => (int) $user->missionscompleted,
                'missionsstarted' => (int) $user->missionsstarted,
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
        $dbman = $DB->get_manager();

        $totalxp = 0;
        if ($dbman->table_exists('local_nexcodelab_userxp')) {
            $totalxp = (int) $DB->get_field_sql(
                "SELECT COALESCE(SUM(xp), 0) FROM {local_nexcodelab_userxp} WHERE userid {$insql}",
                $inparams
            );
        }

        $missionscompleted = 0;
        if ($dbman->table_exists('local_nexcodelab_mission_progress')) {
            $missionscompleted = (int) $DB->count_records_select(
                'local_nexcodelab_mission_progress',
                "userid {$insql} AND completed = 1",
                $inparams
            );
        }

        $submissions = 0;
        if ($dbman->table_exists('local_nexcodelab_submission')) {
            $submissions = (int) $DB->count_records_select(
                'local_nexcodelab_submission',
                "userid {$insql}",
                $inparams
            );
        }

        $published = 0;
        if ($dbman->table_exists('local_nexcodelab_mission')) {
            [$readyin, $readyparams] = $DB->get_in_or_equal(['ready', 'published'], SQL_PARAMS_NAMED, 'st');
            $published = (int) $DB->count_records_select(
                'local_nexcodelab_mission',
                "status {$readyin}",
                $readyparams
            );
        }

        return [
            'activelearners' => count($userids),
            'totalxp' => $totalxp,
            'missionscompleted' => $missionscompleted,
            'totalsubmissions' => $submissions,
            'publishedmissions' => $published,
        ];
    }

    /**
     * @return array
     */
    private static function empty_summary(): array {
        return [
            'activelearners' => 0,
            'totalxp' => 0,
            'missionscompleted' => 0,
            'totalsubmissions' => 0,
            'publishedmissions' => 0,
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
