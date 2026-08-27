<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Filtered bulk enrol roster for NexCoursePro Participants.
 *
 * College = user.institution, department = user.department,
 * year of passing = normalized from user.idnumber (same convention as NexReports).
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\local;

defined('MOODLE_INTERNAL') || die();

use context_course;
use moodle_url;

/**
 * Search / enrol helpers for the Pro enrol roster modal.
 */
class enrol_roster {

    /** Max candidates returned per search. */
    public const LIMIT = 80;

    /**
     * @param string $idnumber
     * @param string $unspecified
     * @return string
     */
    public static function year_of_passing(string $idnumber, string $unspecified = 'Not set'): string {
        $raw = trim($idnumber);
        if ($raw === '') {
            return $unspecified;
        }
        if (preg_match('/(19|20)\d{2}/', $raw, $m)) {
            return $m[0];
        }
        return $raw;
    }

    /**
     * Case-insensitive match for filter labels.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function same_label(string $a, string $b): bool {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * @param string[] $list
     * @param string $value
     * @return bool
     */
    private static function list_has(array $list, string $value): bool {
        foreach ($list as $item) {
            if (self::same_label((string) $item, $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require capability to enrol via manual plugin.
     *
     * @param \stdClass $course
     * @return \context_course
     */
    public static function require_enrol_capability(\stdClass $course): context_course {
        $context = context_course::instance((int) $course->id);
        require_capability('enrol/manual:enrol', $context);
        return $context;
    }

    /**
     * Active manual enrol instance for the course.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function manual_instance(int $courseid): ?\stdClass {
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual' && (int) $instance->status === ENROL_INSTANCE_ENABLED) {
                return $instance;
            }
        }
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Roles the current user can assign when manually enrolling.
     *
     * @param int $courseid
     * @return array{0:array<int,array{id:int,name:string,selected:bool}>,1:int} [roles, defaultroleid]
     */
    public static function assignable_roles(int $courseid): array {
        global $DB;

        $context = context_course::instance($courseid);
        $raw = get_assignable_roles($context, ROLENAME_BOTH);
        $instance = self::manual_instance($courseid);
        $default = (int) ($instance->roleid ?? 0);
        if ($default <= 0 || !isset($raw[$default])) {
            $student = $DB->get_record('role', ['shortname' => 'student'], 'id', IGNORE_MISSING);
            $default = $student ? (int) $student->id : 0;
            if ($default <= 0 || !isset($raw[$default])) {
                $default = $raw ? (int) array_key_first($raw) : 0;
            }
        }

        $roles = [];
        foreach ($raw as $id => $name) {
            $id = (int) $id;
            $roles[] = [
                'id' => $id,
                'name' => (string) $name,
                'selected' => $id === $default,
            ];
        }
        // Prefer default role at the top of the list.
        usort($roles, static function (array $a, array $b) use ($default): int {
            if ($a['id'] === $default) {
                return -1;
            }
            if ($b['id'] === $default) {
                return 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [$roles, $default];
    }

    /**
     * @param int $courseid
     * @param int $roleid
     * @return int Valid role id (0 if none)
     */
    public static function resolve_roleid(int $courseid, int $roleid): int {
        [$roles, $default] = self::assignable_roles($courseid);
        $allowed = [];
        foreach ($roles as $role) {
            $allowed[(int) $role['id']] = true;
        }
        if ($roleid > 0 && isset($allowed[$roleid])) {
            return $roleid;
        }
        return $default;
    }

    /**
     * Cascading filter options among users not yet enrolled.
     *
     * - colleges: always all colleges
     * - years: only when $college is set (years in that college)
     * - departments: only when $college and $year are set
     *
     * @param int $courseid
     * @param string $college
     * @param string $year
     * @return array{colleges:string[],years:string[],departments:string[]}
     */
    public static function filter_options(int $courseid, string $college = '', string $year = ''): array {
        global $DB;

        $college = trim($college);
        $year = trim($year);
        $unspecified = 'Not set';

        $sql = "SELECT u.id, u.institution, u.department, u.idnumber
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   AND NOT EXISTS (
                        SELECT 1
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE ue.userid = u.id AND e.courseid = :courseid
                   )";
        $rs = $DB->get_recordset_sql($sql, ['courseid' => $courseid]);
        $colleges = [];
        $years = [];
        $departments = [];
        foreach ($rs as $u) {
            $iname = trim((string) ($u->institution ?? ''));
            if ($iname === '') {
                $iname = $unspecified;
            }
            $colleges[$iname] = true;

            $ypass = self::year_of_passing((string) ($u->idnumber ?? ''), $unspecified);
            $dept = trim((string) ($u->department ?? ''));
            if ($dept === '') {
                $dept = $unspecified;
            }

            // Years only for the selected college.
            if ($college !== '' && self::same_label($iname, $college)) {
                $years[$ypass] = true;
                // Departments only for selected college + year.
                if ($year !== '' && self::same_label($ypass, $year)) {
                    $departments[$dept] = true;
                }
            }
        }
        $rs->close();

        $sort = static function (array $keys, bool $yearsfirst): array {
            usort($keys, static function (string $a, string $b) use ($yearsfirst): int {
                if ($yearsfirst) {
                    $an = ctype_digit($a) ? (int) $a : null;
                    $bn = ctype_digit($b) ? (int) $b : null;
                    if ($an !== null && $bn !== null && $an !== $bn) {
                        return $bn <=> $an;
                    }
                }
                return strcasecmp($a, $b);
            });
            return $keys;
        };

        return [
            'colleges' => $sort(array_keys($colleges), false),
            'years' => $college !== '' ? $sort(array_keys($years), true) : [],
            'departments' => ($college !== '' && $year !== '') ? $sort(array_keys($departments), false) : [],
        ];
    }

    /**
     * Search users not enrolled. Cascading filters: college → year → department.
     * Users are listed only after a college is selected.
     *
     * @param int $courseid
     * @param string $college
     * @param string $year
     * @param string $department
     * @param string $query
     * @param int $page 0-based
     * @param int $perpage
     * @return array
     */
    public static function search(
        int $courseid,
        string $college = '',
        string $year = '',
        string $department = '',
        string $query = '',
        int $page = 0,
        int $perpage = self::LIMIT
    ): array {
        global $DB, $OUTPUT;

        $college = trim($college);
        $year = trim($year);
        $department = trim($department);
        $query = trim($query);
        $page = max(0, $page);
        $perpage = max(1, min(200, $perpage));
        $unspecified = 'Not set';

        $filters = self::filter_options($courseid, $college, $year);
        [$roles, $defaultroleid] = self::assignable_roles($courseid);

        // Cascade integrity: drop year/dept if they no longer exist for the parent.
        if ($college === '') {
            $year = '';
            $department = '';
        } else {
            $yearok = $year !== '' && self::list_has($filters['years'], $year);
            if (!$yearok) {
                $year = '';
                $department = '';
                $filters = self::filter_options($courseid, $college, '');
            } else if ($department !== '' && !self::list_has($filters['departments'], $department)) {
                $department = '';
            }
        }

        // No college yet — return filter options only (no user list).
        if ($college === '') {
            return [
                'total' => 0,
                'page' => 0,
                'perpage' => $perpage,
                'users' => [],
                'colleges' => $filters['colleges'],
                'years' => [],
                'departments' => [],
                'college' => '',
                'year' => '',
                'department' => '',
                'query' => $query,
                'needcollege' => true,
                'roles' => $roles,
                'roleid' => $defaultroleid,
            ];
        }

        // College chosen but not year — return years only; wait for year before listing users.
        if ($year === '') {
            return [
                'total' => 0,
                'page' => 0,
                'perpage' => $perpage,
                'users' => [],
                'colleges' => $filters['colleges'],
                'years' => $filters['years'],
                'departments' => [],
                'college' => $college,
                'year' => '',
                'department' => '',
                'query' => $query,
                'needcollege' => false,
                'roles' => $roles,
                'roleid' => $defaultroleid,
            ];
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email,
                       u.institution, u.department, u.idnumber, u.picture,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename,
                       u.alternatename, u.imagealt
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   AND NOT EXISTS (
                        SELECT 1
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE ue.userid = u.id AND e.courseid = :courseid
                   )";
        $params = ['courseid' => $courseid];
        if ($department !== '') {
            if ($department === $unspecified) {
                $sql .= " AND (u.department IS NULL OR " . $DB->sql_equal('u.department', ':emptyd', false) . ")";
                $params['emptyd'] = '';
            } else {
                $sql .= ' AND ' . $DB->sql_equal('u.department', ':dept', false);
                $params['dept'] = $department;
            }
        }
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $sql .= " AND (" .
                $DB->sql_like('u.firstname', ':q1', false) . ' OR ' .
                $DB->sql_like('u.lastname', ':q2', false) . ' OR ' .
                $DB->sql_like('u.email', ':q3', false) . ' OR ' .
                $DB->sql_like('u.username', ':q4', false) .
                ")";
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }
        $sql .= ' ORDER BY u.lastname ASC, u.firstname ASC';

        $rs = $DB->get_recordset_sql($sql, $params);
        $matched = [];
        foreach ($rs as $u) {
            $iname = trim((string) ($u->institution ?? ''));
            if ($iname === '') {
                $iname = $unspecified;
            }
            if (!self::same_label($iname, $college)) {
                continue;
            }
            $ypass = self::year_of_passing((string) ($u->idnumber ?? ''), $unspecified);
            if ($year !== '' && !self::same_label($ypass, $year)) {
                continue;
            }
            $matched[] = $u;
        }
        $rs->close();

        $total = count($matched);
        $slice = array_slice($matched, $page * $perpage, $perpage);
        $rows = [];
        foreach ($slice as $u) {
            $fullname = fullname($u);
            $collegelabel = trim((string) ($u->institution ?? ''));
            $deptlabel = trim((string) ($u->department ?? ''));
            $rows[] = [
                'id' => (int) $u->id,
                'fullname' => $fullname,
                'username' => (string) $u->username,
                'email' => (string) $u->email,
                'college' => $collegelabel !== '' ? $collegelabel : $unspecified,
                'department' => $deptlabel !== '' ? $deptlabel : $unspecified,
                'year' => self::year_of_passing((string) ($u->idnumber ?? ''), $unspecified),
                'avatar' => $OUTPUT->user_picture($u, [
                    'size' => 35,
                    'link' => false,
                    'courseid' => $courseid,
                    'class' => 'nxpro-enrol__avatar',
                ]),
                'profileurl' => (new moodle_url('/user/view.php', [
                    'id' => $u->id,
                    'course' => $courseid,
                ]))->out(false),
            ];
        }

        return [
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
            'users' => $rows,
            'colleges' => $filters['colleges'],
            'years' => $filters['years'],
            'departments' => $filters['departments'],
            'college' => $college,
            'year' => $year,
            'department' => $department,
            'query' => $query,
            'needcollege' => false,
            'roles' => $roles,
            'roleid' => $defaultroleid,
        ];
    }

    /**
     * Enrol selected users via manual enrol.
     *
     * @param int $courseid
     * @param int[] $userids
     * @param int $roleid Assignable course role (0 = instance default / student)
     * @return array{enrolled:int,skipped:int,errors:string[]}
     */
    public static function enrol_users(int $courseid, array $userids, int $roleid = 0): array {
        $course = get_course($courseid);
        $context = self::require_enrol_capability($course);
        $instance = self::manual_instance($courseid);
        if (!$instance) {
            throw new \moodle_exception('enrolnotavailable', 'enrol');
        }
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('enrolnotavailable', 'enrol');
        }

        $roleid = self::resolve_roleid($courseid, $roleid);

        $enrolled = 0;
        $skipped = 0;
        $errors = [];
        $userids = array_values(array_unique(array_map('intval', $userids)));
        foreach ($userids as $userid) {
            if ($userid <= 1) {
                $skipped++;
                continue;
            }
            if (is_enrolled($context, $userid, '', true)) {
                $skipped++;
                continue;
            }
            try {
                $plugin->enrol_user($instance, $userid, $roleid ?: null);
                $enrolled++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'enrolled' => $enrolled,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
