<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Shared cohort / course / group / learner filter helpers.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Filter option lists and learner scoping used across reports.
 */
class filters {

    /**
     * Visible cohorts for the current user.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public static function cohorts(): array {
        if (!class_exists('\core_cohort\api') && !function_exists('cohort_get_all_cohorts')) {
            return [];
        }
        require_once($GLOBALS['CFG']->dirroot . '/cohort/lib.php');
        $out = [];
        $all = cohort_get_all_cohorts(0, 500);
        foreach ($all['cohorts'] as $cohort) {
            $out[] = [
                'id' => (int) $cohort->id,
                'name' => format_string($cohort->name),
            ];
        }
        return $out;
    }

    /**
     * Courses the viewer may report on.
     *
     * Managers with viewsite see all non-site courses. Teachers see courses they can view.
     *
     * @param int $limit
     * @return array<int, array{id:int,name:string,category:string}>
     */
    public static function courses(int $limit = 500): array {
        global $DB, $USER;

        $limit = max(1, min(2000, $limit));
        $context = \context_system::instance();
        $seeall = access::has_capability('local/nexreports:viewsite', $context);

        if ($seeall && access::is_scoped()) {
            // Institution ADMIN: only courses with at least one learner from that college.
            [$instsql, $instparams] = access::institution_sql('u.id', 'fc');
            $sql = "SELECT DISTINCT c.id, c.fullname, c.category
                      FROM {course} c
                      JOIN {enrol} e ON e.courseid = c.id
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                     WHERE c.id > 1
                           $instsql
                  ORDER BY c.fullname ASC";
            $records = $DB->get_records_sql($sql, $instparams, 0, $limit);
        } else if ($seeall) {
            $records = $DB->get_records_select(
                'course',
                'id > 1',
                null,
                'fullname ASC',
                'id, fullname, category',
                0,
                $limit
            );
        } else {
            $courses = enrol_get_users_courses($USER->id, true, 'id, fullname, category');
            $records = [];
            foreach ($courses as $course) {
                $ctx = \context_course::instance($course->id);
                if (has_capability('moodle/course:viewparticipants', $ctx)
                        || access::has_capability('local/nexreports:viewcourse', $context)) {
                    $records[$course->id] = $course;
                }
                if (count($records) >= $limit) {
                    break;
                }
            }
        }

        $catnames = [];
        $out = [];
        foreach ($records as $course) {
            $catid = (int) $course->category;
            if ($catid && !isset($catnames[$catid])) {
                $catnames[$catid] = $DB->get_field('course_categories', 'name', ['id' => $catid]) ?: '';
            }
            $out[] = [
                'id' => (int) $course->id,
                'name' => format_string($course->fullname),
                'category' => $catnames[$catid] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Groups in a course.
     *
     * @param int $courseid
     * @return array<int, array{id:int,name:string}>
     */
    public static function groups(int $courseid): array {
        if ($courseid <= 1) {
            return [];
        }
        $groups = groups_get_all_groups($courseid);
        $out = [];
        foreach ($groups as $group) {
            $out[] = ['id' => (int) $group->id, 'name' => format_string($group->name)];
        }
        return $out;
    }

    /**
     * Learner user ids for a course, optionally narrowed by cohort and group.
     *
     * @param int $courseid
     * @param int $cohortid
     * @param int $groupid
     * @param bool $excludesuspended
     * @return int[]
     */
    public static function learner_ids(
        int $courseid,
        int $cohortid = 0,
        int $groupid = 0,
        bool $excludesuspended = true
    ): array {
        global $DB;

        if ($courseid <= 1) {
            return [];
        }

        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, 'moodle/course:isincompletionreports', $groupid, 'u.*', null, 0, 0, $excludesuspended);
        $ids = [];
        foreach ($users as $user) {
            $ids[] = (int) $user->id;
        }
        $ids = array_values(array_diff($ids, overview::excluded_user_ids()));
        $ids = access::filter_userids($ids);

        if ($cohortid > 0 && $ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'u');
            $params['cohortid'] = $cohortid;
            $sql = "SELECT userid FROM {cohort_members}
                     WHERE cohortid = :cohortid AND userid $insql";
            $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        }

        return array_values(array_unique($ids));
    }
}
