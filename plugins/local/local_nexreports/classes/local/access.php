<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Access gate for NexReports UI and report AJAX.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Report access for site admins, college ADMIN, and department *ADMIN users.
 *
 * Tracking heartbeats stay open to every logged-in user; only report pages and
 * their AJAX endpoints call through here.
 *
 * - department "ADMIN" + institution → college-wide reports for that institution
 * - department ending with ADMIN (e.g. CSBS-ADMIN) + institution → department
 *   reports for that college's matching learner department (CSBS), all years
 */
class access {

    /** Profile department value that grants institution-scoped reports. */
    public const INSTITUTION_ADMIN_DEPARTMENT = 'ADMIN';

    /**
     * Report capabilities granted to college / department ADMIN users without a role.
     */
    private const INSTITUTION_ADMIN_CAPS = [
        'local/nexreports:viewsite',
        'local/nexreports:viewcourse',
        'local/nexreports:viewstudents',
        'local/nexreports:export',
    ];

    /**
     * @param int|null $userid Null checks the current user
     * @return bool
     */
    public static function can_view_reports(?int $userid = null): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        return self::is_institution_admin($userid) || self::is_department_admin($userid);
    }

    /**
     * Whether the user is an institution-scoped college admin (department ADMIN).
     *
     * Site administrators are not treated as institution admins here even if their
     * profile department is ADMIN — they keep full site-wide access.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function is_institution_admin(?int $userid = null): bool {
        if (is_siteadmin($userid)) {
            return false;
        }
        $user = self::resolve_user($userid);
        if (!$user) {
            return false;
        }
        $department = trim((string) ($user->department ?? ''));
        $institution = trim((string) ($user->institution ?? ''));
        return $department !== ''
            && strcasecmp($department, self::INSTITUTION_ADMIN_DEPARTMENT) === 0
            && $institution !== '';
    }

    /**
     * Department-scoped admin: profile department ends with ADMIN (e.g. CSBS-ADMIN).
     *
     * Exact "ADMIN" is college-wide ({@see is_institution_admin}), not department.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function is_department_admin(?int $userid = null): bool {
        return self::scoped_department($userid) !== null;
    }

    /**
     * Learner department name this viewer is limited to, or null if unrestricted /
     * college-wide ADMIN.
     *
     * CSBS-ADMIN → CSBS
     *
     * @param int|null $userid
     * @return string|null
     */
    public static function scoped_department(?int $userid = null): ?string {
        if (is_siteadmin($userid)) {
            return null;
        }
        if (self::is_institution_admin($userid)) {
            return null;
        }
        $user = self::resolve_user($userid);
        if (!$user) {
            return null;
        }
        $department = trim((string) ($user->department ?? ''));
        $institution = trim((string) ($user->institution ?? ''));
        if ($department === '' || $institution === '') {
            return null;
        }
        if (strcasecmp($department, self::INSTITUTION_ADMIN_DEPARTMENT) === 0) {
            return null;
        }
        if (!preg_match('/^(.+?)[\s\-_]*ADMIN$/i', $department, $matches)) {
            return null;
        }
        $target = trim((string) $matches[1], " \t\n\r\0\x0B\-_");
        return $target !== '' ? $target : null;
    }

    /**
     * Institution name to scope report data to, or null for unrestricted (site admin).
     *
     * Applies to both college ADMIN and department *ADMIN viewers.
     *
     * @param int|null $userid
     * @return string|null
     */
    public static function scoped_institution(?int $userid = null): ?string {
        if (is_siteadmin($userid)) {
            return null;
        }
        if (!self::is_institution_admin($userid) && !self::is_department_admin($userid)) {
            return null;
        }
        $user = self::resolve_user($userid);
        if (!$user) {
            return null;
        }
        $institution = trim((string) ($user->institution ?? ''));
        return $institution !== '' ? $institution : null;
    }

    /**
     * @param int|null $userid
     * @return bool
     */
    public static function is_scoped(?int $userid = null): bool {
        return self::scoped_institution($userid) !== null;
    }

    /**
     * Stable cache-key fragment for the current viewer scope.
     *
     * @return string Empty for site-wide viewers
     */
    public static function scope_cache_suffix(): string {
        $institution = self::scoped_institution();
        if ($institution === null) {
            return '';
        }
        $suffix = '_i' . substr(sha1(\core_text::strtolower($institution)), 0, 12);
        $department = self::scoped_department();
        if ($department !== null) {
            $suffix .= '_d' . substr(sha1(\core_text::strtolower($department)), 0, 12);
        }
        return $suffix;
    }

    /**
     * SQL fragment limiting a user-id column to the viewer's institution
     * (and department when department-scoped).
     *
     * @param string $useridcolumn Qualified userid column (e.g. u.id, l.userid)
     * @param string $prefix Unique named-parameter prefix
     * @return array{0:string,1:array}
     */
    public static function institution_sql(string $useridcolumn, string $prefix = 'inst'): array {
        global $DB;

        $institution = self::scoped_institution();
        if ($institution === null) {
            return ['', []];
        }

        $alias = $prefix . 'u';
        $param = $prefix . 'name';
        $params = [$param => $institution];
        $deptsql = '';
        $department = self::scoped_department();
        if ($department !== null) {
            $dparam = $prefix . 'dept';
            $deptsql = ' AND ' . $DB->sql_equal("$alias.department", ':' . $dparam, false);
            $params[$dparam] = $department;
        }

        $sql = " AND EXISTS (
                    SELECT 1
                      FROM {user} $alias
                     WHERE $alias.id = $useridcolumn
                       AND " . $DB->sql_equal("$alias.institution", ':' . $param, false) . "
                       $deptsql
                )";
        return [$sql, $params];
    }

    /**
     * Whether a target user belongs to the viewer's institution / department scope.
     *
     * @param int $userid
     * @return bool
     */
    public static function user_in_scope(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return true;
        }
        $institution = self::scoped_institution();
        if ($institution === null) {
            return true;
        }
        $record = $DB->get_record('user', ['id' => $userid], 'institution, department');
        if (!$record) {
            return false;
        }
        if (strcasecmp(trim((string) ($record->institution ?? '')), $institution) !== 0) {
            return false;
        }
        $department = self::scoped_department();
        if ($department === null) {
            return true;
        }
        return strcasecmp(trim((string) ($record->department ?? '')), $department) === 0;
    }

    /**
     * Drop user ids that are outside the viewer's institution / department.
     *
     * @param int[] $userids
     * @return int[]
     */
    public static function filter_userids(array $userids): array {
        global $DB;

        $institution = self::scoped_institution();
        if ($institution === null || !$userids) {
            return array_values(array_unique(array_map('intval', $userids)));
        }

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'fu');
        $params['inst'] = $institution;
        $sql = "SELECT id
                  FROM {user}
                 WHERE id $insql
                   AND " . $DB->sql_equal('institution', ':inst', false);
        $department = self::scoped_department();
        if ($department !== null) {
            $params['dept'] = $department;
            $sql .= ' AND ' . $DB->sql_equal('department', ':dept', false);
        }
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * @param int $userid
     * @throws \required_capability_exception
     */
    public static function require_user_in_scope(int $userid): void {
        if (self::user_in_scope($userid)) {
            return;
        }
        throw new \required_capability_exception(
            \context_system::instance(),
            'local/nexreports:viewsite',
            'nopermissions',
            ''
        );
    }

    /**
     * Capability check that also grants college / department ADMIN the core report caps.
     *
     * @param string $capability
     * @param \context|null $context
     * @return bool
     */
    public static function has_capability(string $capability, $context = null): bool {
        $context = $context ?? \context_system::instance();
        if (has_capability($capability, $context)) {
            return true;
        }
        if (!self::is_institution_admin() && !self::is_department_admin()) {
            return false;
        }
        return in_array($capability, self::INSTITUTION_ADMIN_CAPS, true);
    }

    /**
     * @param string $capability
     * @param \context|null $context
     * @throws \required_capability_exception
     */
    public static function require_capability(string $capability, $context = null): void {
        $context = $context ?? \context_system::instance();
        if (self::has_capability($capability, $context)) {
            return;
        }
        require_capability($capability, $context);
    }

    /**
     * @throws \required_capability_exception
     */
    public static function require_reports(): void {
        if (self::can_view_reports()) {
            return;
        }
        throw new \required_capability_exception(
            \context_system::instance(),
            'local/nexreports:viewsite',
            'nopermissions',
            ''
        );
    }

    /**
     * Apply college / department scope to cascade filter values.
     *
     * @param string $institution
     * @param string $department
     * @return array{showcollege:bool,showdepartment:bool,institution:string,department:string}
     */
    public static function apply_scope_filters(string $institution = '', string $department = ''): array {
        $scopedinst = self::scoped_institution();
        $scopeddept = self::scoped_department();
        $showcollege = ($scopedinst === null);
        $showdepartment = ($scopeddept === null);
        if ($scopedinst !== null) {
            $institution = $scopedinst;
        } else {
            $institution = trim($institution);
        }
        if ($scopeddept !== null) {
            $department = $scopeddept;
        } else {
            $department = trim($department);
        }
        return [
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'institution' => $institution,
            'department' => $department,
        ];
    }

    /**
     * @param int|null $userid
     * @return \stdClass|null
     */
    private static function resolve_user(?int $userid): ?\stdClass {
        global $DB, $USER;

        if ($userid === null || $userid === 0 || (isloggedin() && (int) $USER->id === $userid)) {
            if (!isloggedin() || isguestuser()) {
                return null;
            }
            // Prefer live profile fields from $USER when present.
            if (isset($USER->department) && isset($USER->institution)) {
                return $USER;
            }
            $userid = (int) $USER->id;
        }

        $record = $DB->get_record('user', ['id' => $userid], 'id, department, institution, deleted');
        if (!$record || !empty($record->deleted)) {
            return null;
        }
        return $record;
    }
}
