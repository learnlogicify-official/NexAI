<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexPortfolio GitHub reporting for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Learners with connected GitHub profiles and cached contribution stats.
 */
class portfolio_github_report {

    /**
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (get_config('local_nexportfolio', 'version') === false) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_nexportfolio_github');
    }

    /**
     * GitHub leaderboard with college / year / department / cohort filters.
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

        $params = ['emptyhandle' => ''];
        $sql = "SELECT g.userid
                  FROM {local_nexportfolio_github} g
                  JOIN {user} u ON u.id = g.userid AND u.deleted = 0 AND u.confirmed = 1
                 WHERE g.github_login <> :emptyhandle";
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
            return self::empty_payload($cohortid, $search) + $meta + [
                'summary' => self::empty_summary(),
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $dbman = $DB->get_manager();
        $hasstats = $dbman->field_exists('local_nexportfolio_github', 'stats_json');
        $fields = 'id, userid, github_login, avatar_url, heatmap_fetch, timemodified';
        if ($hasstats) {
            $fields .= ', stats_json, stats_fetch';
        }
        $ghrows = $DB->get_records_select(
            'local_nexportfolio_github',
            "userid {$insql}",
            $inparams,
            '',
            $fields
        );

        $projectcounts = [];
        if ($dbman->table_exists('local_nexportfolio_projects')) {
            $prows = $DB->get_records_sql(
                "SELECT userid, COUNT(1) AS projectcount
                   FROM {local_nexportfolio_projects}
                  WHERE userid {$insql} AND source = :source
               GROUP BY userid",
                $inparams + ['source' => 'github']
            );
            foreach ($prows as $prow) {
                $projectcounts[(int) $prow->userid] = (int) $prow->projectcount;
            }
        }

        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            '',
            'id, firstname, lastname, email, username, institution, department, idnumber, firstnamephonetic, lastnamephonetic, middlename, alternatename, lastaccess'
        );

        $unspecified = get_string('notset', 'local_nexreports');
        $scored = [];
        foreach ($ghrows as $gh) {
            $uid = (int) $gh->userid;
            if (!isset($users[$uid])) {
                continue;
            }
            $stats = self::decode_stats($hasstats ? ($gh->stats_json ?? null) : null);
            $login = trim((string) ($gh->github_login ?? ''));
            if ($login === '') {
                continue;
            }
            $profileurl = (string) ($stats['profileurl'] ?? '');
            if ($profileurl === '') {
                $profileurl = 'https://github.com/' . $login;
            }
            $lastfetch = 0;
            if ($hasstats) {
                $lastfetch = max((int) ($gh->stats_fetch ?? 0), (int) ($gh->heatmap_fetch ?? 0));
            } else {
                $lastfetch = (int) ($gh->heatmap_fetch ?? 0);
            }
            if ($lastfetch <= 0) {
                $lastfetch = (int) ($gh->timemodified ?? 0);
            }

            $scored[] = [
                'userid' => $uid,
                'login' => $login,
                'avatarurl' => (string) ($gh->avatar_url ?? ''),
                'profileurl' => $profileurl,
                'contributionsyear' => (int) $stats['contributionsyear'],
                'commitsyear' => (int) $stats['commitsyear'],
                'prsyear' => (int) $stats['prsyear'],
                'issuesyear' => (int) $stats['issuesyear'],
                'reviewsyear' => (int) $stats['reviewsyear'],
                'publicrepos' => (int) $stats['publicrepos'],
                'contributedto' => (int) $stats['contributedto'],
                'followers' => (int) $stats['followers'],
                'following' => (int) $stats['following'],
                'gists' => (int) $stats['gists'],
                'starsreceived' => (int) $stats['starsreceived'],
                'forksreceived' => (int) $stats['forksreceived'],
                'projectcount' => (int) ($projectcounts[$uid] ?? 0),
                'lastfetch' => $lastfetch,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return [$b['contributionsyear'], $b['publicrepos'], $b['starsreceived'], $b['projectcount'], $a['login']]
                <=> [$a['contributionsyear'], $a['publicrepos'], $a['starsreceived'], $a['projectcount'], $b['login']];
        });

        $summary = [
            'connectedlearners' => count($scored),
            'totalcontributions' => 0,
            'totalrepos' => 0,
            'totalstars' => 0,
            'totalprojects' => 0,
        ];
        foreach ($scored as $row) {
            $summary['totalcontributions'] += (int) $row['contributionsyear'];
            $summary['totalrepos'] += (int) $row['publicrepos'];
            $summary['totalstars'] += (int) $row['starsreceived'];
            $summary['totalprojects'] += (int) $row['projectcount'];
        }

        $rows = [];
        $rank = 1;
        foreach ($scored as $row) {
            if (count($rows) >= $limit) {
                break;
            }
            $user = $users[$row['userid']];
            $college = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );
            $rows[] = [
                'rank' => $rank++,
                'userid' => $row['userid'],
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'username' => (string) ($user->username ?? ''),
                'fullname' => fullname($user),
                'email' => $user->email,
                'institution' => $college !== '' ? $college : '—',
                'yearofpassing' => $yearofpassing,
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $row['userid']]))->out(false),
                'portfolioUrl' => (new \moodle_url('/local/nexportfolio/index.php'))->out(false),
                'lastaccess' => $user->lastaccess
                    ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'),
                'login' => $row['login'],
                'avatarurl' => $row['avatarurl'],
                'profileurl' => $row['profileurl'],
                'contributionsyear' => $row['contributionsyear'],
                'commitsyear' => $row['commitsyear'],
                'prsyear' => $row['prsyear'],
                'issuesyear' => $row['issuesyear'],
                'reviewsyear' => $row['reviewsyear'],
                'publicrepos' => $row['publicrepos'],
                'contributedto' => $row['contributedto'],
                'followers' => $row['followers'],
                'following' => $row['following'],
                'gists' => $row['gists'],
                'starsreceived' => $row['starsreceived'],
                'forksreceived' => $row['forksreceived'],
                'projectcount' => $row['projectcount'],
                'lastfetch' => $row['lastfetch']
                    ? userdate($row['lastfetch'], get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'),
            ];
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'summary' => $summary,
            'cohorts' => filters::cohorts(),
            'selectedcohortid' => $cohortid,
            'search' => $search,
            'total' => $summary['connectedlearners'],
        ] + $meta;
    }

    /**
     * @param string|null $json
     * @return array
     */
    private static function decode_stats($json): array {
        $defaults = [
            'name' => '',
            'bio' => '',
            'company' => '',
            'location' => '',
            'profileurl' => '',
            'createdat' => 0,
            'contributionsyear' => 0,
            'commitsyear' => 0,
            'issuesyear' => 0,
            'prsyear' => 0,
            'reviewsyear' => 0,
            'publicrepos' => 0,
            'contributedto' => 0,
            'followers' => 0,
            'following' => 0,
            'gists' => 0,
            'starsreceived' => 0,
            'forksreceived' => 0,
        ];
        if ($json === null || $json === '') {
            return $defaults;
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            if (is_int($default)) {
                $defaults[$key] = (int) $decoded[$key];
            } else {
                $defaults[$key] = (string) $decoded[$key];
            }
        }
        return $defaults;
    }

    /**
     * @return array
     */
    private static function empty_summary(): array {
        return [
            'connectedlearners' => 0,
            'totalcontributions' => 0,
            'totalrepos' => 0,
            'totalstars' => 0,
            'totalprojects' => 0,
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
        $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params) ?: []);

        // Also match GitHub login.
        [$insql2, $params2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'g');
        $params2['q4'] = '%' . $DB->sql_like_escape($search) . '%';
        $ghids = array_map('intval', $DB->get_fieldset_sql(
            "SELECT userid FROM {local_nexportfolio_github}
              WHERE userid {$insql2} AND " . $DB->sql_like('github_login', ':q4', false),
            $params2
        ) ?: []);

        return array_values(array_unique(array_merge($ids, $ghids)));
    }
}
