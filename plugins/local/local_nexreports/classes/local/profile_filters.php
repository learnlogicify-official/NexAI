<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Institution year / department profile filters for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * College / year-of-passing / department filters for course reports.
 */
class profile_filters {

    /**
     * SQL fragment restricting a userid column to a concrete id list.
     *
     * @param string $column
     * @param int[]|null $userids Null = no extra constraint
     * @param string $prefix
     * @return array{0:string,1:array}
     */
    public static function userid_in_sql(string $column, ?array $userids, string $prefix): array {
        global $DB;

        if ($userids === null) {
            return ['', []];
        }
        if (!$userids) {
            return [' AND 1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, $prefix);
        return [" AND $column $insql", $params];
    }

    /**
     * Learner user ids matching optional course + college + year + department.
     *
     * Returns null when all profile filters are empty (no extra IN-list needed).
     *
     * @param int $courseid
     * @param string $year Exact year label from normalize_year_of_passing / Not set
     * @param string $department Exact department profile value
     * @param string $institution Exact college / institution (user.institution)
     * @return int[]|null
     */
    public static function userids(
        int $courseid = 0,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): ?array {
        global $DB;

        $year = trim($year);
        $department = trim($department);
        $institution = trim($institution);
        $scope = access::apply_scope_filters($institution, $department);
        $institution = $scope['institution'];
        $department = $scope['department'];
        if ($year === '' && $department === '' && $institution === '') {
            return null;
        }

        [$excludesql, $params] = overview::user_exclusion('u.id', 'pfu');
        $where = "u.deleted = 0 AND u.suspended = 0 $excludesql";
        if ($courseid > 1) {
            $where .= ' AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = u.id AND e.courseid = :courseid
            )';
            $params['courseid'] = $courseid;
        }
        if ($department !== '') {
            $where .= ' AND ' . $DB->sql_equal('u.department', ':pfdept', false);
            $params['pfdept'] = $department;
        }

        $sql = "SELECT u.id, u.idnumber, u.institution
                  FROM {user} u
                 WHERE $where";
        $records = $DB->get_recordset_sql($sql, $params);
        $unspecified = get_string('notset', 'local_nexreports');
        $ids = [];
        foreach ($records as $record) {
            if ($institution !== '') {
                $iname = trim((string) ($record->institution ?? ''));
                if ($iname === '') {
                    $iname = $unspecified;
                }
                if ($iname !== $institution) {
                    continue;
                }
            }
            if ($year !== '') {
                $normalized = overview::normalize_year_of_passing_public(
                    (string) ($record->idnumber ?? ''),
                    $unspecified
                );
                if ($normalized !== $year) {
                    continue;
                }
            }
            $ids[] = (int) $record->id;
        }
        $records->close();
        return array_values(array_unique($ids));
    }

    /**
     * Colleges (user.institution) available for site-admin filters.
     *
     * @param string $query
     * @param int $limit
     * @param int $courseid
     * @return array<int, array{id:string,name:string}>
     */
    public static function search_institutions(string $query, int $limit = 20, int $courseid = 0): array {
        $limit = max(1, min(100, $limit));
        $counts = self::collect_profile_values(max(0, $courseid), 'institution');
        return self::filter_named_options(array_keys($counts), $query, $limit, false);
    }

    /**
     * Years of passing available (optionally within a course / college / department).
     *
     * @param string $query
     * @param int $limit
     * @param int $courseid
     * @param string $institution
     * @param string $department Empty = all departments
     * @return array<int, array{id:string,name:string}>
     */
    public static function search_years(
        string $query,
        int $limit = 20,
        int $courseid = 0,
        string $institution = '',
        string $department = ''
    ): array {
        $limit = max(1, min(100, $limit));
        $counts = self::collect_profile_values(
            max(0, $courseid),
            'year',
            trim($institution),
            '',
            trim($department)
        );
        return self::filter_named_options(array_keys($counts), $query, $limit, true);
    }

    /**
     * Departments available after a year is chosen (optionally within a course / college).
     *
     * @param string $query
     * @param int $limit
     * @param int $courseid
     * @param string $year
     * @param string $institution
     * @return array<int, array{id:string,name:string}>
     */
    public static function search_departments(
        string $query,
        int $limit = 20,
        int $courseid = 0,
        string $year = '',
        string $institution = ''
    ): array {
        $limit = max(1, min(100, $limit));
        $year = trim($year);
        if ($year === '') {
            return [];
        }
        $counts = self::collect_profile_values(max(0, $courseid), 'department', trim($institution), $year);
        return self::filter_named_options(array_keys($counts), $query, $limit, false);
    }

    /**
     * @param int $courseid
     * @param string $mode institution|year|department
     * @param string $institution Empty = all colleges
     * @param string $year Empty = all years (for department mode)
     * @param string $department Empty = all departments (for year mode)
     * @return array<string,int>
     */
    private static function collect_profile_values(
        int $courseid,
        string $mode,
        string $institution = '',
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        [$excludesql, $params] = overview::user_exclusion('u.id', 'cpv');
        $where = "u.deleted = 0 AND u.suspended = 0 $excludesql";
        if ($courseid > 1) {
            $where .= ' AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = u.id AND e.courseid = :courseid
            )';
            $params['courseid'] = $courseid;
        }
        if ($department !== '') {
            $where .= ' AND ' . $DB->sql_equal('u.department', ':cpvdept', false);
            $params['cpvdept'] = $department;
        }

        $sql = "SELECT u.id, u.idnumber, u.department, u.institution
                  FROM {user} u
                 WHERE $where";
        $records = $DB->get_recordset_sql($sql, $params);
        $unspecified = get_string('notset', 'local_nexreports');
        $counts = [];
        foreach ($records as $record) {
            $iname = trim((string) ($record->institution ?? ''));
            if ($iname === '') {
                $iname = $unspecified;
            }
            if ($institution !== '' && $iname !== $institution) {
                continue;
            }
            $normalizedyear = overview::normalize_year_of_passing_public(
                (string) ($record->idnumber ?? ''),
                $unspecified
            );
            if ($year !== '' && $normalizedyear !== $year) {
                continue;
            }
            if ($mode === 'institution') {
                $key = $iname;
            } else if ($mode === 'year') {
                $key = $normalizedyear;
            } else {
                $key = trim((string) ($record->department ?? ''));
                if ($key === '') {
                    $key = $unspecified;
                }
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $records->close();
        return $counts;
    }

    /**
     * @param string[] $names
     * @param string $query
     * @param int $limit
     * @param bool $yearsfirst Prefer numeric years descending
     * @return array<int, array{id:string,name:string}>
     */
    private static function filter_named_options(
        array $names,
        string $query,
        int $limit,
        bool $yearsfirst
    ): array {
        $query = trim($query);
        $filtered = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($query !== '' && stripos($name, $query) === false) {
                continue;
            }
            $filtered[] = $name;
        }
        usort($filtered, static function (string $a, string $b) use ($yearsfirst): int {
            if ($yearsfirst) {
                $an = ctype_digit($a) ? (int) $a : null;
                $bn = ctype_digit($b) ? (int) $b : null;
                if ($an !== null && $bn !== null && $an !== $bn) {
                    return $bn <=> $an;
                }
            }
            return strcasecmp($a, $b);
        });
        $out = [];
        foreach (array_slice($filtered, 0, $limit) as $name) {
            $out[] = ['id' => $name, 'name' => $name];
        }
        return $out;
    }
}
