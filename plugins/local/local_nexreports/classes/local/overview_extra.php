<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Inactive users and daily-activity overview blocks.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Extra overview panels matching Edwiser Inactive Users + Daily Activities.
 */
class overview_extra {

    /**
     * Learners inactive for at least $months months (0 = never logged in).
     *
     * @param int $months 0|1|3|6
     * @param string $search
     * @param int $limit
     * @return array
     */
    public static function inactive_users(int $months = 1, string $search = '', int $limit = 100): array {
        global $DB;

        $months = in_array($months, [0, 1, 3, 6], true) ? $months : 1;
        $limit = max(1, min(500, $limit));
        $search = trim($search);
        [$excludesql, $excludeparams] = overview::user_exclusion('u.id', 'exiu');

        $params = $excludeparams;
        $where = "u.deleted = 0 AND u.confirmed = 1 $excludesql";

        if ($months === 0) {
            $where .= ' AND u.lastaccess = 0';
        } else {
            $cutoff = time() - ($months * 30 * DAYSECS);
            $where .= ' AND u.lastaccess > 0 AND u.lastaccess < :cutoff';
            $params['cutoff'] = $cutoff;
        }

        if ($search !== '') {
            $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
            $namelike = $DB->sql_like($fullname, ':q1', false, false);
            $emaillike = $DB->sql_like('u.email', ':q2', false, false);
            $where .= " AND ($namelike OR $emaillike)";
            $escaped = '%' . $DB->sql_like_escape($search) . '%';
            $params['q1'] = $escaped;
            $params['q2'] = $escaped;
        }

        // Prefer student-archetype users (matches Edwiser inactive list).
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       u.lastaccess
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :archetype
                 WHERE $where
              ORDER BY u.lastaccess ASC, u.lastname ASC";
        $params['archetype'] = 'student';
        $records = $DB->get_records_sql($sql, $params, 0, $limit);

        $rows = [];
        $rank = 1;
        foreach ($records as $user) {
            $rows[] = [
                'rank' => $rank++,
                'userid' => (int) $user->id,
                'fullname' => fullname($user),
                'email' => $user->email,
                'url' => (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false),
                'lastaccess' => $user->lastaccess
                    ? userdate((int) $user->lastaccess, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'),
                'lastaccessuts' => (int) $user->lastaccess,
            ];
        }

        return [
            'generated' => time(),
            'months' => $months,
            'search' => $search,
            'rows' => $rows,
        ];
    }

    /**
     * Activity totals for a single calendar day (defaults to today).
     *
     * Definitions follow Edwiser Reports Pro's Daily Activities block: registrations and
     * enrolments by creation time, activity completions with any non-zero state, course
     * completions from the progress cache at 100%, visits and the hourly chart as distinct
     * users from the log store, and learners/teachers as users who generated a log that day
     * and hold the matching course role. Edwiser's own events "Visits" query uses
     * `userid < 1` and under-counts; we deliberately keep the distinct-user reading.
     *
     * @param int $daystart Midnight timestamp; 0 = today
     * @return array
     */
    public static function daily_activity(int $daystart = 0): array {
        global $DB;

        if ($daystart <= 0) {
            $daystart = usergetmidnight(time());
        } else {
            $daystart = usergetmidnight($daystart);
        }
        $dayend = $daystart + DAYSECS;
        $params = ['fromts' => $daystart, 'tots' => $dayend];

        // Edwiser counts every {user} row created that day, including deleted accounts.
        [$regsql, $regparams] = access::institution_sql('id', 'dreg');
        $registrations = (int) $DB->count_records_select(
            'user',
            'timecreated >= :fromts AND timecreated < :tots' . $regsql,
            array_merge($params, $regparams)
        );

        [$enrsql, $enrparams] = access::institution_sql('ue.userid', 'denr');
        $enrolments = (int) $DB->count_records_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
              WHERE ue.timecreated >= :fromts
                AND ue.timecreated < :tots
                $enrsql
                AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype = :archetype
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                     WHERE ra.userid = ue.userid
                )",
            array_merge($params, $enrparams, [
                'archetype' => 'student',
                'ctxlevel' => CONTEXT_COURSE,
            ])
        );

        [$cmpsql, $cmpparams] = access::institution_sql('p.userid', 'dcmp');
        $coursecompletions = (int) $DB->count_records_sql(
            "SELECT COUNT(p.id)
               FROM {nexreports_course_progress} p
              WHERE p.completiontime IS NOT NULL
                AND p.completiontime >= :fromts
                AND p.completiontime < :tots
                AND p.progress >= 100
                $cmpsql
                AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype = :archetype
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                     WHERE ra.userid = p.userid
                )",
            array_merge($params, $cmpparams, [
                'archetype' => 'student',
                'ctxlevel' => CONTEXT_COURSE,
            ])
        );

        // Student-archetype users only, matching Edwiser's temporary user table for admins.
        [$actsql, $actparams] = access::institution_sql('cmc.userid', 'dact');
        $activitycompletions = (int) $DB->count_records_sql(
            "SELECT COUNT(cmc.id)
               FROM {course_modules_completion} cmc
               JOIN {user} u ON u.id = cmc.userid AND u.confirmed = 1
              WHERE cmc.timemodified >= :fromts
                AND cmc.timemodified < :tots
                AND cmc.completionstate <> 0
                $actsql
                AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype = :archetype
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                     WHERE ra.userid = cmc.userid
                )",
            array_merge($params, $actparams, [
                'archetype' => 'student',
                'ctxlevel' => CONTEXT_COURSE,
            ])
        );

        $visits = 0;
        $visitsbyhour = array_fill(0, 24, 0);
        $onlinelearners = 0;
        $onlineteachers = 0;

        if (overview::logstore_usable()) {
            // Distinct users who generated any log that day — Edwiser's hourly chart uses the
            // same idea. Their events-table Visits query is broken (`userid < 1`).
            [$vissql, $visparams] = access::institution_sql('userid', 'dvis');
            $visits = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid)
                   FROM {logstore_standard_log}
                  WHERE userid > 0
                    AND timecreated >= :fromts
                    AND timecreated < :tots
                    $vissql",
                array_merge($params, $visparams)
            );

            $base = (int) $daystart;
            $hoursql = "SELECT FLOOR((timecreated - $base) / 3600) AS hourbucket,
                               COUNT(DISTINCT userid) AS visits
                          FROM {logstore_standard_log}
                         WHERE userid > 0
                           AND timecreated >= :fromts
                           AND timecreated < :tots
                           $vissql
                      GROUP BY FLOOR((timecreated - $base) / 3600)";
            $hourrows = $DB->get_records_sql($hoursql, array_merge($params, $visparams));
            foreach ($hourrows as $row) {
                $h = (int) $row->hourbucket;
                if ($h >= 0 && $h < 24) {
                    $visitsbyhour[$h] = (int) $row->visits;
                }
            }

            // Learners / teachers who appeared in the logs that day (Edwiser "Login" panel).
            [$olsql, $olparams] = access::institution_sql('l.userid', 'dol');
            $onlinelearners = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT l.userid)
                   FROM {logstore_standard_log} l
                  WHERE l.userid > 0
                    AND l.timecreated >= :fromts
                    AND l.timecreated < :tots
                    $olsql
                    AND EXISTS (
                        SELECT 1
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid AND r.archetype = :archetype
                          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                         WHERE ra.userid = l.userid
                    )",
                array_merge($params, $olparams, [
                    'archetype' => 'student',
                    'ctxlevel' => CONTEXT_COURSE,
                ])
            );
            [$otsql, $otparams] = access::institution_sql('l.userid', 'dot');
            $onlineteachers = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT l.userid)
                   FROM {logstore_standard_log} l
                  WHERE l.userid > 0
                    AND l.timecreated >= :fromts
                    AND l.timecreated < :tots
                    $otsql
                    AND EXISTS (
                        SELECT 1
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid
                               AND r.archetype IN ('editingteacher', 'teacher')
                          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                         WHERE ra.userid = l.userid
                    )",
                array_merge($params, $otparams, ['ctxlevel' => CONTEXT_COURSE])
            );
        }

        $labels = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = sprintf('%02d:00', $h);
        }

        return [
            'generated' => time(),
            'daystart' => $daystart,
            'daylabel' => userdate($daystart, get_string('strftimedatefullshort', 'langconfig')),
            'registrations' => $registrations,
            'enrolments' => $enrolments,
            'coursecompletions' => $coursecompletions,
            'activitycompletions' => $activitycompletions,
            'visits' => $visits,
            'onlinelearners' => $onlinelearners,
            'onlineteachers' => $onlineteachers,
            'labels' => $labels,
            'visitsbyhour' => $visitsbyhour,
        ];
    }

    /**
     * Course progress distribution for enrolled learners (Edwiser Course Progress block).
     *
     * Matches Edwiser's get_enrolled_students + get_completion_with_percentage:
     * - learners = enrolled with moodle/course:isincompletionreports (including inactive /
     *   suspended enrolments; onlyactive = false)
     * - missing progress-cache rows count in 0–20% and contribute 0 to the average sum
     * - average = sum(progress) / enrolled count (0 when the sum is 0)
     * - buckets: ≤20, ≤40, ≤60, ≤80, else
     *
     * @param int $courseid 0 picks the first completion-enabled course
     * @param int $groupid 0 = all groups in the course
     * @return array
     */
    public static function course_progress(
        int $courseid = 0,
        int $groupid = 0,
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        $courseid = max(0, $courseid);
        $groupid = max(0, $groupid);
        $year = trim($year);
        $department = trim($department);
        if (access::is_scoped()) {
            $groupid = 0;
        }

        if ($courseid <= 1) {
            $courseid = self::default_progress_course();
        }
        if ($courseid <= 1) {
            return self::empty_course_progress();
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
        if (!$course) {
            return self::empty_course_progress();
        }

        // If a group from another course is passed, ignore it.
        if ($groupid > 0) {
            $groupcourse = (int) $DB->get_field('groups', 'courseid', ['id' => $groupid]);
            if ($groupcourse !== $courseid) {
                $groupid = 0;
            }
        }

        $context = \context_course::instance($courseid);
        // Same enrolment set as Edwiser utility::get_enrolled_students (onlyactive = false).
        [$esql, $eparams] = get_enrolled_sql(
            $context,
            'moodle/course:isincompletionreports',
            $groupid,
            false
        );
        [$instsql, $instparams] = access::institution_sql('u.id', 'cpi');
        $profileids = profile_filters::userids($courseid, $year, $department);
        [$profilesql, $profileparams] = profile_filters::userid_in_sql('u.id', $profileids, 'cpp');
        $learners = $DB->get_records_sql(
            "SELECT DISTINCT u.id
               FROM {user} u
               JOIN ($esql) je ON je.id = u.id
              WHERE u.deleted = 0
                    $instsql
                    $profilesql",
            array_merge($eparams, $instparams, $profileparams)
        );

        $buckets = [
            '81-100' => 0,
            '61-80' => 0,
            '41-60' => 0,
            '21-40' => 0,
            '0-20' => 0,
        ];
        $labels = [
            '81-100' => '81% - 100%',
            '61-80' => '61% - 80%',
            '41-60' => '41% - 60%',
            '21-40' => '21% - 40%',
            '0-20' => '0% - 20%',
        ];

        $total = 0.0;
        $count = count($learners);
        $progressbyuser = [];

        if ($learners) {
            $userids = array_map('intval', array_keys($learners));
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cp');
            $rows = $DB->get_records_select(
                'nexreports_course_progress',
                "courseid = :courseid AND userid $insql",
                array_merge(['courseid' => $courseid], $inparams),
                '',
                'userid, progress'
            );
            foreach ($rows as $row) {
                $progressbyuser[(int) $row->userid] = (float) $row->progress;
            }
        }

        foreach ($learners as $learner) {
            $userid = (int) $learner->id;
            // Edwiser: missing cache row → 0–20% bucket, and do not add to $total.
            if (!array_key_exists($userid, $progressbyuser)) {
                $buckets['0-20']++;
                continue;
            }
            $pct = $progressbyuser[$userid];
            $total += $pct;
            switch (true) {
                case $pct <= 20:
                    $buckets['0-20']++;
                    break;
                case $pct <= 40:
                    $buckets['21-40']++;
                    break;
                case $pct <= 60:
                    $buckets['41-60']++;
                    break;
                case $pct <= 80:
                    $buckets['61-80']++;
                    break;
                default:
                    $buckets['81-100']++;
                    break;
            }
        }

        $series = [];
        foreach ($buckets as $key => $value) {
            $series[] = [
                'key' => $key,
                'label' => $labels[$key],
                'count' => $value,
            ];
        }

        // Edwiser returns raw mean; UI formats with toPrecision(2) (significant figures).
        $average = ($count > 0 && $total != 0.0) ? ($total / $count) : 0.0;

        return [
            'generated' => time(),
            'available' => $count > 0,
            'selectedcourseid' => $courseid,
            'selectedcoursename' => format_string($course->fullname),
            'selectedgroupid' => $groupid,
            'selectedgroupname' => $groupid
                ? (string) format_string((string) $DB->get_field('groups', 'name', ['id' => $groupid]))
                : '',
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'average' => $average,
            'learners' => $count,
            'buckets' => $series,
        ];
    }

    /**
     * First completion-enabled course with cached progress, else the first such course.
     *
     * @return int
     */
    private static function default_progress_course(): int {
        global $DB;

        [$instsql, $instparams] = access::institution_sql('p.userid', 'dpc');
        $sql = "SELECT p.courseid
                  FROM {nexreports_course_progress} p
                  JOIN {course} c ON c.id = p.courseid AND c.id > 1
                 WHERE 1 = 1
                       $instsql
              GROUP BY p.courseid
              ORDER BY COUNT(*) DESC, p.courseid ASC";
        $rows = $DB->get_records_sql($sql, $instparams, 0, 1);
        if ($rows) {
            return (int) reset($rows)->courseid;
        }

        if (access::is_scoped()) {
            $courses = overview::search_courses('', 1);
            return $courses ? (int) $courses[0]['id'] : 0;
        }

        $course = $DB->get_records_select(
            'course',
            'id > 1 AND enablecompletion = 1',
            null,
            'fullname ASC',
            'id',
            0,
            1
        );
        return $course ? (int) reset($course)->id : 0;
    }

    /**
     * @return array
     */
    private static function empty_course_progress(): array {
        $labels = [
            '81-100' => '81% - 100%',
            '61-80' => '61% - 80%',
            '41-60' => '41% - 60%',
            '21-40' => '21% - 40%',
            '0-20' => '0% - 20%',
        ];
        $buckets = [];
        foreach ($labels as $key => $label) {
            $buckets[] = ['key' => $key, 'label' => $label, 'count' => 0];
        }
        return [
            'generated' => time(),
            'available' => false,
            'selectedcourseid' => 0,
            'selectedcoursename' => '',
            'selectedgroupid' => 0,
            'selectedgroupname' => '',
            'selectedyear' => '',
            'selecteddepartment' => '',
            'average' => 0.0,
            'learners' => 0,
            'buckets' => $buckets,
        ];
    }
}
