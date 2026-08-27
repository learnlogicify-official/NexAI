<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexPortfolio reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Learners who have connected at least one coding platform.
 */
class portfolio_report {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexportfolio', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_nexportfolio_handles');
    }

    /**
     * Platform label map.
     *
     * @return string[] key => label
     */
    public static function platform_labels(): array {
        if (!function_exists('local_nexportfolio_platforms')) {
            $cfg = $GLOBALS['CFG'] ?? null;
            if ($cfg && is_readable($cfg->dirroot . '/local/nexportfolio/lib.php')) {
                require_once($cfg->dirroot . '/local/nexportfolio/lib.php');
            }
        }
        $out = [];
        if (function_exists('local_nexportfolio_platforms')) {
            foreach (local_nexportfolio_platforms() as $key => $str) {
                $out[$key] = get_string($str, 'local_nexportfolio');
            }
        } else {
            $out = [
                'leetcode' => 'LeetCode',
                'codechef' => 'CodeChef',
                'codeforces' => 'Codeforces',
                'geeksforgeeks' => 'GeeksforGeeks',
                'codingninjas' => 'Coding Ninjas',
            ];
        }
        return $out;
    }

    /**
     * Short header labels for nested platform groups.
     *
     * @return string[]
     */
    public static function platform_short_labels(): array {
        return [
            'leetcode' => 'LeetCode',
            'codechef' => 'CodeChef',
            'codeforces' => 'Codeforces',
            'geeksforgeeks' => 'GFG',
            'codingninjas' => 'Code360',
        ];
    }

    /**
     * Metrics for one platform cell group.
     *
     * @param \stdClass|null $handle
     * @param \stdClass|null $data
     * @return array
     */
    public static function platform_metrics($handle, $data): array {
        $connected = $handle && trim((string) ($handle->handle ?? '')) !== '';
        $out = [
            'connected' => $connected,
            'handle' => $connected ? (string) $handle->handle : '',
            'solved' => 0,
            'rating' => 0,
            'bestrating' => 0,
            'contests' => 0,
        ];
        if (!$connected || !$data) {
            return $out;
        }
        $out['solved'] = (int) $data->totalsolved;
        $out['rating'] = (int) round((float) $data->rating);
        $out['contests'] = (int) $data->contests;
        $best = (float) $data->rating;
        if (!empty($data->datajson)) {
            $profile = json_decode($data->datajson, true) ?: [];
            $stats = is_array($profile['stats'] ?? null) ? $profile['stats'] : [];
            if (!empty($stats['maxRating'])) {
                $best = max($best, (float) $stats['maxRating']);
            }
            $history = is_array($profile['ratingHistory'] ?? null) ? $profile['ratingHistory'] : [];
            foreach ($history as $pt) {
                if (isset($pt['rating'])) {
                    $best = max($best, (float) $pt['rating']);
                }
            }
            $ch = is_array($profile['contestHistory'] ?? null) ? $profile['contestHistory'] : [];
            foreach ($ch as $pt) {
                if (isset($pt['rating'])) {
                    $best = max($best, (float) $pt['rating']);
                }
            }
        }
        $out['bestrating'] = (int) round($best);
        return $out;
    }

    /**
     * Columns shown for the current filter.
     *
     * @param string $platform
     * @param string[] $labels
     * @return array
     */
    private static function visible_platform_columns(string $platform, array $labels): array {
        $shorts = self::platform_short_labels();
        $keys = $platform !== '' ? [$platform] : array_keys($labels);
        $cols = [];
        foreach ($keys as $key) {
            if (!isset($labels[$key])) {
                continue;
            }
            $cols[] = [
                'id' => $key,
                'name' => $labels[$key],
                'short' => $shorts[$key] ?? $labels[$key],
            ];
        }
        return $cols;
    }

    /**
     * Connected learners report.
     *
     * @param int $cohortid
     * @param string $platform empty = all
     * @param string $search
     * @param int $limit
     * @param string $institution College filter (site admin)
     * @param string $year Year of passing
     * @param string $department Department
     * @return array
     */
    public static function connected_learners(
        int $cohortid = 0,
        string $platform = '',
        string $search = '',
        int $limit = 500,
        string $institution = '',
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $search = trim($search);
        $platform = strtolower(trim($platform));
        $institution = trim($institution);
        $year = trim($year);
        $department = trim($department);
        $labels = self::platform_labels();
        if ($platform !== '' && !isset($labels[$platform])) {
            $platform = '';
        }
        $columns = self::visible_platform_columns($platform, $labels);

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
            return self::empty_payload($cohortid, $platform, $search, $labels, $columns) + $meta;
        }

        $params = [];
        $platformsql = '';
        if ($platform !== '') {
            $platformsql = ' AND h.platform = :platform';
            $params['platform'] = $platform;
        }

        // Users with at least one non-empty handle.
        $sql = "SELECT DISTINCT h.userid
                  FROM {local_nexportfolio_handles} h
                  JOIN {user} u ON u.id = h.userid AND u.deleted = 0 AND u.confirmed = 1
                 WHERE h.handle <> :emptyhandle" . $platformsql;
        $params['emptyhandle'] = '';
        $userids = $DB->get_fieldset_sql($sql, $params);
        $userids = array_map('intval', $userids ?: []);
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
            return self::empty_payload($cohortid, $platform, $search, $labels, $columns) + $meta + [
                'summary' => [
                    'connectedlearners' => 0,
                    'platformlinks' => 0,
                    'totalsolved' => 0,
                    'totalcontests' => 0,
                ],
            ];
        }

        // Load all handles + cached stats for these users.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $handles = $DB->get_records_select(
            'local_nexportfolio_handles',
            "userid {$insql} AND handle <> :emptyhandle",
            $inparams + ['emptyhandle' => ''],
            'userid ASC, platform ASC'
        );

        $datarows = [];
        if ($DB->get_manager()->table_exists('local_nexportfolio_data')) {
            $datarows = $DB->get_records_select(
                'local_nexportfolio_data',
                "userid {$insql}",
                $inparams,
                '',
                'id, userid, platform, totalsolved, rating, contests, streak, activedays, datajson, lastfetch'
            );
        }
        $dataByUser = [];
        foreach ($datarows as $d) {
            $dataByUser[(int) $d->userid][$d->platform] = $d;
        }

        $byuser = [];
        foreach ($handles as $h) {
            $uid = (int) $h->userid;
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = [];
            }
            $byuser[$uid][$h->platform] = $h;
        }

        // Prefer name sort.
        $users = $DB->get_records_list(
            'user',
            'id',
            array_keys($byuser),
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber, firstnamephonetic, lastnamephonetic, middlename, alternatename, lastaccess'
        );

        $unspecified = get_string('notset', 'local_nexreports');
        $rows = [];
        $rank = 1;

        foreach ($users as $user) {
            $uid = (int) $user->id;
            $userhandles = $byuser[$uid] ?? [];
            if (!$userhandles) {
                continue;
            }

            $platformstats = [];
            $platformcount = 0;
            $totalsolved = 0;
            $totalcontests = 0;
            foreach ($columns as $col) {
                $key = $col['id'];
                $m = self::platform_metrics(
                    $userhandles[$key] ?? null,
                    $dataByUser[$uid][$key] ?? null
                );
                $m['platform'] = $key;
                $platformstats[] = $m;
                if ($m['connected']) {
                    $platformcount++;
                    $totalsolved += $m['solved'];
                    $totalcontests += $m['contests'];
                }
            }

            $college = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );

            $rows[] = [
                'rank' => $rank++,
                'userid' => $uid,
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'username' => (string) ($user->username ?? ''),
                'fullname' => fullname($user),
                'email' => $user->email,
                'institution' => $college !== '' ? $college : '—',
                'yearofpassing' => $yearofpassing,
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'portfolioUrl' => (new \moodle_url('/local/nexportfolio/index.php'))->out(false),
                'lastaccess' => $user->lastaccess
                    ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'),
                'platformcount' => $platformcount,
                'totalsolved' => $totalsolved,
                'totalcontests' => $totalcontests,
                'platformstats' => $platformstats,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        // Summary over all matched users (not only the truncated page).
        $summary = self::summary_for_users(array_keys($byuser), $byuser, $dataByUser, $platform);

        $platformoptions = [];
        foreach ($labels as $key => $label) {
            $platformoptions[] = ['id' => $key, 'name' => $label];
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'summary' => $summary,
            'cohorts' => filters::cohorts(),
            'platforms' => $platformoptions,
            'platformcolumns' => $columns,
            'selectedcohortid' => $cohortid,
            'selectedplatform' => $platform,
            'search' => $search,
            'total' => $summary['connectedlearners'],
        ] + $meta;
    }

    /**
     * @param int[] $userids
     * @param array $byuser userid => [platform => handle row]
     * @param array $dataByUser
     * @param string $platform
     * @return array
     */
    private static function summary_for_users(
        array $userids,
        array $byuser,
        array $dataByUser,
        string $platform
    ): array {
        $connected = 0;
        $links = 0;
        $solved = 0;
        $contests = 0;
        foreach ($userids as $uid) {
            $uid = (int) $uid;
            $plats = $byuser[$uid] ?? [];
            if ($platform !== '') {
                if (empty($plats[$platform])) {
                    continue;
                }
                $plats = [$platform => $plats[$platform]];
            }
            if (!$plats) {
                continue;
            }
            $connected++;
            $links += count($plats);
            foreach ($plats as $key => $h) {
                $d = $dataByUser[$uid][$key] ?? null;
                if ($d) {
                    $solved += (int) $d->totalsolved;
                    $contests += (int) $d->contests;
                }
            }
        }
        return [
            'connectedlearners' => $connected,
            'platformlinks' => $links,
            'totalsolved' => $solved,
            'totalcontests' => $contests,
        ];
    }

    /**
     * @param int $cohortid
     * @param string $platform
     * @param string $search
     * @param array $labels
     * @param array $columns
     * @return array
     */
    private static function empty_payload(
        int $cohortid,
        string $platform,
        string $search,
        array $labels,
        array $columns = []
    ): array {
        if (!$columns) {
            $columns = self::visible_platform_columns($platform, $labels);
        }
        $platformoptions = [];
        foreach ($labels as $key => $label) {
            $platformoptions[] = ['id' => $key, 'name' => $label];
        }
        return [
            'generated' => time(),
            'rows' => [],
            'summary' => [
                'connectedlearners' => 0,
                'platformlinks' => 0,
                'totalsolved' => 0,
                'totalcontests' => 0,
            ],
            'cohorts' => filters::cohorts(),
            'platforms' => $platformoptions,
            'platformcolumns' => $columns,
            'selectedcohortid' => $cohortid,
            'selectedplatform' => $platform,
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
