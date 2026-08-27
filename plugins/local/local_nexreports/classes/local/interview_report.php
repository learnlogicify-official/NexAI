<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexInterview (local_nexinterview) reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Interview attempt ledger with site-wide KPIs.
 */
class interview_report {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexinterview', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_nexinterview_attempt');
    }

    /**
     * Interview attempts with college / year / department / cohort / track / status filters.
     *
     * @param int $cohortid
     * @param string $search
     * @param int $limit
     * @param string $institution
     * @param string $year
     * @param string $department
     * @param string $status completed|inprogress|all
     * @param string $track roletrack id or empty
     * @return array
     */
    public static function attempts(
        int $cohortid = 0,
        string $search = '',
        int $limit = 500,
        string $institution = '',
        string $year = '',
        string $department = '',
        string $status = 'all',
        string $track = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $search = trim($search);
        $institution = trim($institution);
        $year = trim($year);
        $department = trim($department);
        $status = self::normalize_status($status);
        $track = trim($track);

        $colleges = profile_filters::search_institutions('', 100, 0);
        $years = profile_filters::search_years('', 100, 0, $institution);
        $departments = ($year !== '')
            ? profile_filters::search_departments('', 100, 0, $year, $institution)
            : [];
        $tracks = self::track_options();

        $meta = [
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'tracks' => $tracks,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedstatus' => $status,
            'selectedtrack' => $track,
            'showcollege' => 1,
            'showdepartment' => 1,
        ];

        if (!self::available()) {
            return self::empty_payload($cohortid, $search) + $meta;
        }

        $userids = self::interview_userids();
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

        $summary = self::summary_for_users($userids, $status, $track);
        $rows = self::rows_for_users($userids, $limit, $status, $track);

        return [
            'generated' => time(),
            'rows' => $rows,
            'summary' => $summary,
            'cohorts' => filters::cohorts(),
            'selectedcohortid' => $cohortid,
            'search' => $search,
            'total' => count($rows),
        ] + $meta;
    }

    /**
     * @return int[]
     */
    private static function interview_userids(): array {
        global $DB;
        return array_values(array_unique(array_map(
            'intval',
            $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {local_nexinterview_attempt}") ?: []
        )));
    }

    /**
     * @param int[] $userids
     * @param int $limit
     * @param string $status
     * @param string $track
     * @return array
     */
    private static function rows_for_users(
        array $userids,
        int $limit,
        string $status,
        string $track
    ): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $where = "a.userid {$insql} AND u.deleted = 0";
        if ($status !== 'all') {
            $where .= ' AND a.status = :status';
            $inparams['status'] = $status;
        }
        if ($track !== '') {
            $where .= ' AND a.roletrack = :track';
            $inparams['track'] = $track;
        }

        $records = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.sessionid, a.roletrack, a.status, a.overallscore,
                    a.timecreated, a.timecompleted,
                    u.firstname, u.lastname, u.email, u.username, u.institution, u.department,
                    u.idnumber, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
               FROM {local_nexinterview_attempt} a
               JOIN {user} u ON u.id = a.userid
              WHERE {$where}
           ORDER BY COALESCE(NULLIF(a.timecompleted, 0), a.timecreated) DESC, a.id DESC",
            $inparams,
            0,
            $limit
        );

        $trackmap = self::track_map();
        $unspecified = get_string('notset', 'local_nexreports');
        $never = get_string('never', 'local_nexreports');
        $completedlabel = get_string('interviewstatuscompleted', 'local_nexreports');
        $inprogresslabel = get_string('interviewstatusinprogress', 'local_nexreports');
        $feedbacklabel = get_string('interviewfeedback', 'local_nexreports');
        $rows = [];
        $rank = 1;
        $dimkeys = [
            'conceptual',
            'problem_solving',
            'coding',
            'explanation',
            'communication',
            'independence',
        ];

        // Prefetch dimension scores for completed attempts that only have overall locally.
        $dimcache = self::hydrate_missing_dimensions($records);

        foreach ($records as $rec) {
            $uid = (int) $rec->userid;
            $college = trim((string) ($rec->institution ?? ''));
            $userdepartment = trim((string) ($rec->department ?? ''));
            $trackid = (string) ($rec->roletrack ?? '');
            $attemptstatus = (string) ($rec->status ?? '');
            $score = $attemptstatus === 'completed'
                ? (int) round((float) ($rec->overallscore ?? 0))
                : 0;
            $started = (int) ($rec->timecreated ?? 0);
            $completed = (int) ($rec->timecompleted ?? 0);
            $sessionid = (string) ($rec->sessionid ?? '');
            $feedbackurl = $sessionid !== ''
                ? (new \moodle_url('/local/nexinterview/feedback.php', ['sessionid' => $sessionid]))->out(false)
                : '';

            $attemptid = (int) $rec->id;
            $dims = $dimcache[$attemptid] ?? self::dims_from_local_record($rec);
            $row = [
                'rank' => $rank++,
                'attemptid' => $attemptid,
                'userid' => $uid,
                'firstname' => (string) ($rec->firstname ?? ''),
                'lastname' => (string) ($rec->lastname ?? ''),
                'username' => (string) ($rec->username ?? ''),
                'fullname' => fullname($rec),
                'email' => (string) ($rec->email ?? ''),
                'institution' => $college !== '' ? $college : '—',
                'yearofpassing' => overview::normalize_year_of_passing_public(
                    (string) ($rec->idnumber ?? ''),
                    $unspecified
                ),
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'track' => $trackmap[$trackid] ?? ($trackid !== '' ? $trackid : '—'),
                'trackid' => $trackid,
                'status' => $attemptstatus === 'completed' ? $completedlabel : $inprogresslabel,
                'statusid' => $attemptstatus,
                'score' => $score,
                'scoredisplay' => $attemptstatus === 'completed' ? (string) $score : '—',
                'started' => $started
                    ? userdate($started, get_string('strftimedatetimeshort', 'langconfig'))
                    : $never,
                'completed' => $completed
                    ? userdate($completed, get_string('strftimedatetimeshort', 'langconfig'))
                    : '—',
                'sessionid' => $sessionid,
                'feedbackurl' => $feedbackurl,
                'feedbacklabel' => $feedbacklabel,
            ];
            foreach ($dimkeys as $key) {
                $field = str_replace('_', '', $key);
                $val = (int) round((float) ($dims[$key] ?? 0));
                $row[$field] = $attemptstatus === 'completed' ? $val : 0;
                $row[$field . 'display'] = ($attemptstatus === 'completed' && $dims !== null)
                    ? (string) $val
                    : '—';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Local scoresjson only (no remote call).
     *
     * @param \stdClass $rec
     * @return array<string, float>|null
     */
    private static function dims_from_local_record(\stdClass $rec): ?array {
        if (!class_exists('\\local_nexinterview\\local\\attempts')) {
            return null;
        }
        $dims = \local_nexinterview\local\attempts::dimension_scores_from_record($rec);
        if (\local_nexinterview\local\attempts::dimensions_are_empty($dims)) {
            return null;
        }
        return $dims;
    }

    /**
     * Fetch and persist dimension scores for completed attempts that lack them.
     *
     * @param array<int,\stdClass> $records
     * @return array<int, array<string, float>>
     */
    private static function hydrate_missing_dimensions(array $records): array {
        $out = [];
        if (!class_exists('\\local_nexinterview\\local\\attempts')
                || !class_exists('\\local_nexinterview\\local\\client')) {
            return $out;
        }

        $need = [];
        foreach ($records as $rec) {
            $attemptid = (int) ($rec->id ?? 0);
            if ($attemptid <= 0 || (string) ($rec->status ?? '') !== 'completed') {
                continue;
            }
            $local = self::dims_from_local_record($rec);
            if ($local !== null) {
                $out[$attemptid] = $local;
                continue;
            }
            $sessionid = trim((string) ($rec->sessionid ?? ''));
            if ($sessionid === '') {
                continue;
            }
            $need[$attemptid] = $sessionid;
        }

        if (!$need) {
            return $out;
        }

        try {
            $client = new \local_nexinterview\local\client();
            if (!$client->configured()) {
                return $out;
            }
        } catch (\Throwable $e) {
            return $out;
        }

        @set_time_limit(max(120, 15 + (count($need) * 3)));

        foreach ($need as $attemptid => $sessionid) {
            try {
                $view = $client->get($sessionid);
                $dims = \local_nexinterview\local\attempts::dimension_scores_from_view($view);
                if (\local_nexinterview\local\attempts::dimensions_are_empty($dims)) {
                    continue;
                }
                \local_nexinterview\local\attempts::save_dimension_scores($attemptid, $dims);
                if (!empty($view['status']) && (string) $view['status'] === 'completed') {
                    \local_nexinterview\local\attempts::sync_completed($view);
                }
                $out[$attemptid] = $dims;
            } catch (\Throwable $e) {
                // Keep overall from Moodle; dimensions stay unavailable for this row.
                continue;
            }
        }

        return $out;
    }

    /**
     * @param int[] $userids
     * @param string $status
     * @param string $track
     * @return array
     */
    private static function summary_for_users(array $userids, string $status, string $track): array {
        global $DB;

        if (!$userids) {
            return self::empty_summary();
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $where = "userid {$insql}";
        if ($status !== 'all') {
            $where .= ' AND status = :status';
            $inparams['status'] = $status;
        }
        if ($track !== '') {
            $where .= ' AND roletrack = :track';
            $inparams['track'] = $track;
        }

        $stats = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT userid) AS learners,
                    COUNT(1) AS totalattempts,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'inprogress' THEN 1 ELSE 0 END) AS inprogress,
                    AVG(CASE WHEN status = 'completed' THEN overallscore ELSE NULL END) AS avgscore
               FROM {local_nexinterview_attempt}
              WHERE {$where}",
            $inparams
        );

        return [
            'learners' => (int) ($stats->learners ?? 0),
            'totalattempts' => (int) ($stats->totalattempts ?? 0),
            'completed' => (int) ($stats->completed ?? 0),
            'inprogress' => (int) ($stats->inprogress ?? 0),
            'avgscore' => (int) round((float) ($stats->avgscore ?? 0)),
        ];
    }

    /**
     * @return array
     */
    private static function empty_summary(): array {
        return [
            'learners' => 0,
            'totalattempts' => 0,
            'completed' => 0,
            'inprogress' => 0,
            'avgscore' => 0,
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
            'tracks' => self::track_options(),
            'selectedinstitution' => '',
            'selectedyear' => '',
            'selecteddepartment' => '',
            'selectedstatus' => 'all',
            'selectedtrack' => '',
            'showcollege' => 1,
            'showdepartment' => 1,
        ];
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private static function track_options(): array {
        $out = [];
        foreach (self::track_map() as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name];
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function track_map(): array {
        global $CFG;
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        if (!function_exists('local_nexinterview_tracks')) {
            $lib = $CFG->dirroot . '/local/nexinterview/lib.php';
            if (is_readable($lib)) {
                require_once($lib);
            }
        }
        if (function_exists('local_nexinterview_tracks')) {
            foreach (local_nexinterview_tracks() as $t) {
                $id = (string) ($t['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $map[$id] = (string) ($t['title'] ?? $id);
            }
        }
        // Include any custom interviewer tracks already used on attempts.
        if (self::available()) {
            global $DB;
            $used = $DB->get_fieldset_sql(
                "SELECT DISTINCT roletrack FROM {local_nexinterview_attempt} WHERE roletrack <> ''"
            ) ?: [];
            foreach ($used as $id) {
                $id = (string) $id;
                if ($id !== '' && !isset($map[$id])) {
                    $map[$id] = $id;
                }
            }
        }
        return $map;
    }

    /**
     * @param string $status
     * @return string
     */
    private static function normalize_status(string $status): string {
        $status = strtolower(trim($status));
        if ($status === 'completed' || $status === 'inprogress') {
            return $status;
        }
        return 'all';
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
