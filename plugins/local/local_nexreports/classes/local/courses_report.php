<?php
// This file is part of Moodle - http://moodle.org/
/**
 * All-courses summary report (Edwiser All Courses Summary equivalent).
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Course-level engagement table for the Courses tab.
 */
class courses_report {

    /**
     * Build the all-courses summary rows (Edwiser All Courses Summary columns).
     *
     * @param string $enrolment all|last7days|weekly|monthly|yearly|custom "Y-m-d to Y-m-d"
     * @param string $exclude Comma list: suspended,inactiveyear,inactivemonth
     * @param string $search
     * @param int $limit
     * @return array
     */
    public static function summary(
        string $enrolment = 'all',
        string $exclude = '',
        string $search = '',
        int $limit = 500
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $search = trim(\core_text::strtolower($search));
        $excludeflags = array_filter(array_map('trim', explode(',', $exclude)));
        $courses = filters::courses(2000);

        // Completables per course (completion tracking on).
        $activitycounts = $DB->get_records_sql(
            "SELECT course AS id, COUNT(id) AS total
               FROM {course_modules}
              WHERE completion > 0
           GROUP BY course"
        );

        $completionurl = (new \moodle_url('/local/nexreports/course_completion.php'))->out(false);
        $activitiesurl = (new \moodle_url('/local/nexreports/course_activities.php'))->out(false);

        $rows = [];
        foreach ($courses as $course) {
            if ($search !== ''
                    && \core_text::strpos(\core_text::strtolower($course['name']), $search) === false
                    && \core_text::strpos(\core_text::strtolower($course['category']), $search) === false) {
                continue;
            }

            $courseid = (int) $course['id'];
            $learnerids = self::summary_learner_ids($courseid, 0, $enrolment, $excludeflags);
            $enrolments = count($learnerids);
            // Institution reports omit courses with no matching college learners.
            if (access::is_scoped() && $enrolments === 0) {
                continue;
            }

            $completions = self::summary_completions($courseid, $learnerids);
            $grades = self::summary_grades($courseid, $learnerids);
            $totalseconds = self::summary_timespent($courseid, $learnerids);
            $avgseconds = $enrolments > 0 ? (int) ceil($totalseconds / $enrolments) : 0;
            $totalactivities = (int) ($activitycounts[$courseid]->total ?? 0);
            $avgprogress = $enrolments > 0
                ? round($completions['totalprogress'] / $enrolments, 2)
                : 0.0;

            $rows[] = [
                'courseid' => $courseid,
                'name' => $course['name'],
                'category' => $course['category'],
                'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'enrolments' => $enrolments,
                'completionurl' => $completionurl . '?courseid=' . $courseid,
                'completed' => $completions['completed'],
                'notstarted' => $completions['notstarted'],
                'inprogress' => $completions['inprogress'],
                'atleastoneactivitystarted' => $completions['atleastonestarted'],
                'totalactivities' => $totalactivities,
                'activitiesurl' => $activitiesurl . '?courseid=' . $courseid,
                'avgprogress' => $avgprogress,
                'avggrade' => round((float) $grades['avggrade'], 2),
                'highestgrade' => round((float) $grades['highestgrade'], 2),
                'lowestgrade' => round((float) $grades['lowestgrade'], 2),
                'totaltimespent' => $totalseconds,
                'avgtimespent' => $avgseconds,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'enrolment' => $enrolment,
            'exclude' => implode(',', $excludeflags),
            'search' => $search,
            'enrolmentlabel' => self::enrolment_label($enrolment),
        ];
    }

    /**
     * Enrolment period label for the UI pill.
     *
     * @param string $enrolment
     * @return string
     */
    private static function enrolment_label(string $enrolment): string {
        $map = [
            'all' => 'enrolmentalltime',
            'last7days' => 'enrolmentlast7days',
            'weekly' => 'enrolmentlastweek',
            'monthly' => 'enrolmentlastmonth',
            'yearly' => 'enrolmentlastyear',
        ];
        if (isset($map[$enrolment])) {
            return get_string($map[$enrolment], 'local_nexreports');
        }
        if (strpos($enrolment, ' to ') !== false) {
            return $enrolment;
        }
        return get_string('enrolmentalltime', 'local_nexreports');
    }

    /**
     * Enrolment date window, or null for all time.
     *
     * @param string $enrolment
     * @return array{0:int,1:int}|null
     */
    private static function enrolment_range(string $enrolment): ?array {
        if ($enrolment === '' || $enrolment === 'all') {
            return null;
        }

        $end = (int) (floor(strtotime('yesterday') / DAYSECS) + 1) * DAYSECS;
        $days = 6;

        switch ($enrolment) {
            case 'last7days':
                $days = 6;
                break;
            case 'weekly':
                $end = (int) (floor(strtotime('last saturday') / DAYSECS) + 1) * DAYSECS;
                $days = 6;
                break;
            case 'monthly':
                $end = (int) strtotime('last day of previous month 23:59:59') + 1;
                $start = (int) strtotime('first day of previous month');
                return [$start, $end];
            case 'yearly':
                $month = (int) date('n');
                $year = (int) date('Y');
                if ($month < 4) {
                    $year--;
                }
                $end = (int) strtotime("$year-03-31 23:59:59") + 1;
                $start = (int) strtotime(($year - 1) . '-04-01');
                return [$start, $end];
            default:
                $dates = explode(' to ', $enrolment);
                if (count($dates) === 2) {
                    $start = (int) strtotime(trim($dates[0]) . ' 00:00:00');
                    $end = (int) strtotime(trim($dates[1]) . ' 23:59:59') + 1;
                    if ($start > 0 && $end > $start) {
                        return [$start, $end];
                    }
                }
                return null;
        }

        return [$end - ($days * DAYSECS), $end];
    }

    /**
     * Learners for one course under ACS filters.
     *
     * @param int $courseid
     * @param int $groupid
     * @param string $enrolment
     * @param string[] $excludeflags
     * @return int[]
     */
    private static function summary_learner_ids(
        int $courseid,
        int $groupid,
        string $enrolment,
        array $excludeflags
    ): array {
        global $DB;

        $ids = filters::learner_ids($courseid, 0, $groupid, true);
        if (!$ids) {
            return [];
        }

        $excludesuspended = in_array('suspended', $excludeflags, true);
        $inactiveyear = in_array('inactiveyear', $excludeflags, true);
        $inactivemonth = in_array('inactivemonth', $excludeflags, true);
        $range = self::enrolment_range($enrolment);

        if (!$excludesuspended && !$inactiveyear && !$inactivemonth && $range === null) {
            return $ids;
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $joins = "FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid";
        $wheres = ["u.id $insql", 'u.deleted = 0'];

        if ($excludesuspended) {
            $wheres[] = 'u.suspended = 0';
            $wheres[] = 'ue.status = 0';
        }

        if ($range !== null) {
            $wheres[] = 'FLOOR(ue.timestart / 86400) BETWEEN :startday AND :endday';
            $params['startday'] = (int) floor($range[0] / DAYSECS);
            $params['endday'] = (int) floor(($range[1] - 1) / DAYSECS);
        }

        if ($inactiveyear || $inactivemonth) {
            $cutoff = $inactivemonth
                ? (time() - (DAYSECS * 30))
                : (time() - (DAYSECS * 365));
            $params['lastaccess'] = $cutoff;
            $params['action'] = 'viewed';
            $joins .= " LEFT JOIN (
                            SELECT userid, MAX(timecreated) AS lastaccess
                              FROM {logstore_standard_log}
                             WHERE action = :action AND courseid = :logcourse
                          GROUP BY userid
                        ) logs ON logs.userid = u.id";
            $params['logcourse'] = $courseid;
            $wheres[] = 'logs.lastaccess > :lastaccess';
        }

        $sql = "SELECT DISTINCT u.id $joins WHERE " . implode(' AND ', $wheres);
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Progress buckets matching Edwiser ACS.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @return array
     */
    private static function summary_completions(int $courseid, array $learnerids): array {
        global $DB;

        $empty = [
            'completed' => 0,
            'notstarted' => 0,
            'inprogress' => 0,
            'atleastonestarted' => 0,
            'totalprogress' => 0.0,
        ];
        if (!$learnerids) {
            return $empty;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $sql = "SELECT COALESCE(SUM(progress), 0) AS totalprogress,
                       SUM(CASE WHEN progress >= 100 THEN 1 ELSE 0 END) AS completed,
                       SUM(CASE WHEN totalmodules = 0 THEN 1 ELSE 0 END) AS notstarted,
                       SUM(CASE WHEN progress < 100 AND progress > 0 THEN 1 ELSE 0 END) AS inprogress,
                       SUM(CASE WHEN totalmodules > 0 THEN 1 ELSE 0 END) AS atleastonestarted
                  FROM {nexreports_course_progress}
                 WHERE courseid = :courseid AND userid $insql";
        $row = $DB->get_record_sql($sql, $params);
        if (!$row) {
            return $empty;
        }
        return [
            'completed' => (int) $row->completed,
            'notstarted' => (int) $row->notstarted,
            'inprogress' => (int) $row->inprogress,
            'atleastonestarted' => (int) $row->atleastonestarted,
            'totalprogress' => (float) $row->totalprogress,
        ];
    }

    /**
     * Course-total grade aggregates for enrolled learners.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @return array{avggrade:float,highestgrade:float,lowestgrade:float}
     */
    private static function summary_grades(int $courseid, array $learnerids): array {
        global $DB;

        $empty = ['avggrade' => 0.0, 'highestgrade' => 0.0, 'lowestgrade' => 0.0];
        if (!$learnerids) {
            return $empty;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $params['itemtype'] = 'course';
        $sql = "SELECT MAX(gg.finalgrade) AS highestgrade,
                       MIN(gg.finalgrade) AS lowestgrade,
                       AVG(gg.finalgrade) AS avggrade
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = :itemtype
                   AND gg.finalgrade IS NOT NULL
                   AND gg.userid $insql";
        $row = $DB->get_record_sql($sql, $params);
        if (!$row) {
            return $empty;
        }
        return [
            'avggrade' => (float) ($row->avggrade ?? 0),
            'highestgrade' => (float) ($row->highestgrade ?? 0),
            'lowestgrade' => (float) ($row->lowestgrade ?? 0),
        ];
    }

    /**
     * All-time time spent for learners on a course.
     *
     * Uses nexreports_tracking measured dwell time, plus a log-gap estimate for
     * history before tracking existed (same split as Overview time spent).
     * Never reads Edwiser tables.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @return int Seconds
     */
    private static function summary_timespent(int $courseid, array $learnerids): int {
        return (int) array_sum(self::learner_timespent_map($courseid, $learnerids));
    }

    /**
     * Per-learner all-time course time spent in seconds.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @return array<int,int> userid => seconds
     */
    private static function learner_timespent_map(int $courseid, array $learnerids): array {
        global $DB;

        $out = [];
        foreach ($learnerids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$learnerids || $courseid <= 1) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $tracked = $DB->get_records_sql(
            "SELECT userid, COALESCE(SUM(timespent), 0) AS timespent
               FROM {nexreports_tracking}
              WHERE courseid = :courseid
                AND userid $insql
           GROUP BY userid",
            $params
        );

        $firsttracked = tracking::first_tracked();
        $pregap = ($firsttracked > 0)
            ? self::timespent_loggap_by_user($courseid, $learnerids, 0, $firsttracked)
            : [];
        $needfull = false;
        foreach ($learnerids as $uid) {
            $uid = (int) $uid;
            $t = (int) ($tracked[$uid]->timespent ?? 0);
            if ($t <= 0) {
                $needfull = true;
                break;
            }
        }
        $fullgap = $needfull
            ? self::timespent_loggap_by_user($courseid, $learnerids, 0, 0)
            : [];

        foreach ($learnerids as $uid) {
            $uid = (int) $uid;
            $t = (int) ($tracked[$uid]->timespent ?? 0);
            if ($t > 0 && $firsttracked > 0) {
                $out[$uid] = $t + (int) ($pregap[$uid] ?? 0);
            } else if ($t > 0) {
                $out[$uid] = $t;
            } else {
                $out[$uid] = (int) ($fullgap[$uid] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Estimate course time spent from consecutive logstore events (session gap).
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @param int $fromts Inclusive lower bound (0 = no lower bound)
     * @param int $tots Exclusive upper bound (0 = no upper bound)
     * @return int Seconds
     */
    private static function summary_timespent_loggap(
        int $courseid,
        array $learnerids,
        int $fromts = 0,
        int $tots = 0
    ): int {
        return (int) array_sum(self::timespent_loggap_by_user($courseid, $learnerids, $fromts, $tots));
    }

    /**
     * Per-learner log-gap time estimate for a course.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @param int $fromts
     * @param int $tots
     * @return array<int,int> userid => seconds
     */
    private static function timespent_loggap_by_user(
        int $courseid,
        array $learnerids,
        int $fromts = 0,
        int $tots = 0
    ): array {
        global $DB;

        $out = [];
        foreach ($learnerids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$learnerids || !overview::logstore_usable()) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $timewhere = '';
        if ($fromts > 0) {
            $timewhere .= ' AND timecreated >= :fromts';
            $params['fromts'] = $fromts;
        }
        if ($tots > 0) {
            $timewhere .= ' AND timecreated < :tots';
            $params['tots'] = $tots;
        }
        $sql = "SELECT userid, timecreated
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid
                   AND userid $insql
                   $timewhere
              ORDER BY userid ASC, timecreated ASC";
        $rs = $DB->get_recordset_sql($sql, $params);
        $sessiongap = overview::session_gap();
        $prevuid = 0;
        $prevts = 0;
        foreach ($rs as $row) {
            $uid = (int) $row->userid;
            $ts = (int) $row->timecreated;
            if ($uid === $prevuid && $prevts > 0) {
                $gap = $ts - $prevts;
                if ($gap > 0 && $gap <= $sessiongap) {
                    $out[$uid] = ($out[$uid] ?? 0) + $gap;
                }
            }
            $prevuid = $uid;
            $prevts = $ts;
        }
        $rs->close();
        return $out;
    }

    /**
     * Per-activity time spent totals for a course (tracking + activity log-gap).
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @return array<int,int> cmid => seconds
     */
    private static function activity_timespent_by_cm(int $courseid, array $learnerids): array {
        global $DB;

        if (!$learnerids || $courseid <= 1) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $tracked = $DB->get_records_sql(
            "SELECT cmid AS id, COALESCE(SUM(timespent), 0) AS timespent
               FROM {nexreports_tracking}
              WHERE courseid = :courseid
                AND cmid > 0
                AND userid $insql
           GROUP BY cmid",
            $params
        );

        $firsttracked = tracking::first_tracked();
        $pregap = ($firsttracked > 0)
            ? self::activity_timespent_loggap_by_cm($courseid, $learnerids, 0, $firsttracked)
            : [];
        $fullgap = self::activity_timespent_loggap_by_cm($courseid, $learnerids, 0, 0);

        $cmids = array_unique(array_merge(
            array_map('intval', array_keys($tracked)),
            array_map('intval', array_keys($pregap)),
            array_map('intval', array_keys($fullgap))
        ));
        $out = [];
        foreach ($cmids as $cmid) {
            if ($cmid <= 0) {
                continue;
            }
            $t = (int) ($tracked[$cmid]->timespent ?? 0);
            if ($t > 0 && $firsttracked > 0) {
                $out[$cmid] = $t + (int) ($pregap[$cmid] ?? 0);
            } else if ($t > 0) {
                $out[$cmid] = $t;
            } else {
                $out[$cmid] = (int) ($fullgap[$cmid] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Per-learner time spent on one activity (tracking + activity log-gap).
     *
     * @param int $courseid
     * @param int $cmid
     * @param int[] $learnerids
     * @return array<int,int> userid => seconds
     */
    public static function activity_learner_timespent_map(int $courseid, int $cmid, array $learnerids): array {
        global $DB;

        $out = [];
        foreach ($learnerids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$learnerids || $courseid <= 1 || $cmid <= 0) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $params['cmid'] = $cmid;
        $tracked = $DB->get_records_sql(
            "SELECT userid, COALESCE(SUM(timespent), 0) AS timespent
               FROM {nexreports_tracking}
              WHERE courseid = :courseid
                AND cmid = :cmid
                AND userid $insql
           GROUP BY userid",
            $params
        );

        $firsttracked = tracking::first_tracked();
        $pregap = ($firsttracked > 0)
            ? self::activity_timespent_loggap_by_user($courseid, $cmid, $learnerids, 0, $firsttracked)
            : [];
        $needfull = false;
        foreach ($learnerids as $uid) {
            if ((int) ($tracked[(int) $uid]->timespent ?? 0) <= 0) {
                $needfull = true;
                break;
            }
        }
        $fullgap = $needfull
            ? self::activity_timespent_loggap_by_user($courseid, $cmid, $learnerids, 0, 0)
            : [];

        foreach ($learnerids as $uid) {
            $uid = (int) $uid;
            $t = (int) ($tracked[$uid]->timespent ?? 0);
            if ($t > 0 && $firsttracked > 0) {
                $out[$uid] = $t + (int) ($pregap[$uid] ?? 0);
            } else if ($t > 0) {
                $out[$uid] = $t;
            } else {
                $out[$uid] = (int) ($fullgap[$uid] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Log-gap seconds per course module for enrolled learners.
     *
     * @param int $courseid
     * @param int[] $learnerids
     * @param int $fromts
     * @param int $tots
     * @return array<int,int> cmid => seconds
     */
    private static function activity_timespent_loggap_by_cm(
        int $courseid,
        array $learnerids,
        int $fromts = 0,
        int $tots = 0
    ): array {
        global $DB;

        if (!$learnerids || !overview::logstore_usable()) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $params['target'] = 'course_module';
        $timewhere = '';
        if ($fromts > 0) {
            $timewhere .= ' AND timecreated >= :fromts';
            $params['fromts'] = $fromts;
        }
        if ($tots > 0) {
            $timewhere .= ' AND timecreated < :tots';
            $params['tots'] = $tots;
        }
        $sql = "SELECT userid, contextinstanceid AS cmid, timecreated
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid
                   AND target = :target
                   AND contextinstanceid > 0
                   AND userid $insql
                   $timewhere
              ORDER BY contextinstanceid ASC, userid ASC, timecreated ASC";
        $rs = $DB->get_recordset_sql($sql, $params);
        $sessiongap = overview::session_gap();
        $out = [];
        $prevcm = 0;
        $prevuid = 0;
        $prevts = 0;
        foreach ($rs as $row) {
            $cmid = (int) $row->cmid;
            $uid = (int) $row->userid;
            $ts = (int) $row->timecreated;
            if ($cmid === $prevcm && $uid === $prevuid && $prevts > 0) {
                $gap = $ts - $prevts;
                if ($gap > 0 && $gap <= $sessiongap) {
                    $out[$cmid] = ($out[$cmid] ?? 0) + $gap;
                }
            }
            $prevcm = $cmid;
            $prevuid = $uid;
            $prevts = $ts;
        }
        $rs->close();
        return $out;
    }

    /**
     * Log-gap seconds per learner for one activity.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int[] $learnerids
     * @param int $fromts
     * @param int $tots
     * @return array<int,int> userid => seconds
     */
    private static function activity_timespent_loggap_by_user(
        int $courseid,
        int $cmid,
        array $learnerids,
        int $fromts = 0,
        int $tots = 0
    ): array {
        global $DB;

        $out = [];
        foreach ($learnerids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$learnerids || !overview::logstore_usable() || $cmid <= 0) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $params['cmid'] = $cmid;
        $params['target'] = 'course_module';
        $timewhere = '';
        if ($fromts > 0) {
            $timewhere .= ' AND timecreated >= :fromts';
            $params['fromts'] = $fromts;
        }
        if ($tots > 0) {
            $timewhere .= ' AND timecreated < :tots';
            $params['tots'] = $tots;
        }
        $sql = "SELECT userid, timecreated
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid
                   AND target = :target
                   AND contextinstanceid = :cmid
                   AND userid $insql
                   $timewhere
              ORDER BY userid ASC, timecreated ASC";
        $rs = $DB->get_recordset_sql($sql, $params);
        $sessiongap = overview::session_gap();
        $prevuid = 0;
        $prevts = 0;
        foreach ($rs as $row) {
            $uid = (int) $row->userid;
            $ts = (int) $row->timecreated;
            if ($uid === $prevuid && $prevts > 0) {
                $gap = $ts - $prevts;
                if ($gap > 0 && $gap <= $sessiongap) {
                    $out[$uid] = ($out[$uid] ?? 0) + $gap;
                }
            }
            $prevuid = $uid;
            $prevts = $ts;
        }
        $rs->close();
        return $out;
    }

    /**
     * Resolve a usable course id (default: first visible course).
     *
     * @param int $courseid
     * @return int
     */
    public static function resolve_courseid(int $courseid): int {
        $courses = filters::courses(500);
        if (!$courses) {
            return 0;
        }
        if ($courseid > 1) {
            foreach ($courses as $course) {
                if ((int) $course['id'] === $courseid) {
                    return $courseid;
                }
            }
        }
        return (int) $courses[0]['id'];
    }

    /**
     * Course id/name options for selectors.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public static function course_options(): array {
        $out = [];
        foreach (filters::courses(500) as $course) {
            $out[] = ['id' => (int) $course['id'], 'name' => $course['name']];
        }
        return $out;
    }

    /**
     * Safe activity type label.
     *
     * @param string $modname
     * @return string
     */
    public static function activity_type_label(string $modname): string {
        $component = 'mod_' . $modname;
        if (get_string_manager()->string_exists('pluginname', $component)) {
            return get_string('pluginname', $component);
        }
        return $modname;
    }


    /**
     * College (site admin only) + year + department cascade options.
     *
     * @param int $courseid
     * @param string $institution
     * @param string $year
     * @param string $department
     * @return array{showcollege:int,showdepartment:int,institution:string,year:string,department:string,colleges:array,years:array,departments:array}
     */
    public static function college_year_department_options(
        int $courseid,
        string $institution,
        string $year,
        string $department
    ): array {
        $scope = access::apply_scope_filters($institution, $department);
        $showcollege = $scope['showcollege'];
        $showdepartment = $scope['showdepartment'];
        $institution = $scope['institution'];
        $department = $scope['department'];
        $year = trim($year);
        $colleges = ($showcollege && $courseid > 1)
            ? profile_filters::search_institutions('', 100, $courseid)
            : [];
        $years = $courseid > 1
            ? profile_filters::search_years(
                '',
                100,
                $courseid,
                $institution,
                $showdepartment ? '' : $department
            )
            : [];
        $departments = [];
        if (!$showdepartment && $department !== '') {
            $departments = [['id' => $department, 'name' => $department]];
        } else if ($courseid > 1 && $year !== '') {
            $departments = profile_filters::search_departments('', 100, $courseid, $year, $institution);
        }
        return [
            'showcollege' => $showcollege ? 1 : 0,
            'showdepartment' => $showdepartment ? 1 : 0,
            'institution' => $institution,
            'year' => $year,
            'department' => $department,
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
        ];
    }

    /**
     * Course Activities Summary — one row per course module.
     *
     * @param int $courseid
     * @param int $groupid Unused; kept for call-site compatibility (always ignored)
     * @param string $search
     * @param int $limit
     * @param string $year Year of passing filter
     * @param string $department Department filter
     * @return array
     */
    public static function activities_summary(
        int $courseid = 0,
        int $groupid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $courseid = self::resolve_courseid($courseid);
        $courses = self::course_options();
        $search = trim(\core_text::strtolower($search));
        $cascade = self::college_year_department_options($courseid, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];
        $years = $cascade['years'];
        $departments = $cascade['departments'];
        $colleges = $cascade['colleges'];
        $showcollege = $cascade['showcollege'];
        $showdepartment = $cascade['showdepartment'] ?? 1;

        if ($courseid <= 1) {
            return [
                'generated' => time(),
                'rows' => [],
                'courses' => $courses,
                'groups' => [],
                'colleges' => [],
                'years' => [],
                'departments' => [],
                'selectedcourseid' => 0,
                'selectedgroupid' => 0,
                'selectedinstitution' => '',
                'selectedyear' => '',
                'selecteddepartment' => '',
                'showcollege' => $showcollege,
                'showdepartment' => $showdepartment,
                'search' => $search,
            ];
        }

        $learnerids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, $department, $institution);
        if ($profileids !== null) {
            $learnerids = array_values(array_intersect($learnerids, $profileids));
        }
        $usercount = count($learnerids);
        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        $excludedmods = ['forum' => true, 'qbank' => true];

        // Completions per CM.
        $completions = [];
        if ($learnerids) {
            [$insql, $inparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
            $sql = "SELECT cmc.coursemoduleid AS id, COUNT(*) AS completion
                      FROM {course_modules_completion} cmc
                      JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                     WHERE cm.course = :courseid
                       AND cmc.completionstate <> 0
                       AND cmc.userid $insql
                  GROUP BY cmc.coursemoduleid";
            $completions = $DB->get_records_sql($sql, array_merge(['courseid' => $courseid], $inparams));
        }

        // Visits per CM (course_module context).
        $visits = [];
        if ($learnerids && overview::logstore_usable()) {
            [$vinsql, $vinparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'vu');
            [$excludesql, $excludeparams] = overview::user_exclusion('lsl.userid', 'exc');
            $vsql = "SELECT lsl.contextinstanceid AS id, COUNT(*) AS visits
                       FROM {logstore_standard_log} lsl
                      WHERE lsl.courseid = :courseid
                        AND lsl.action = :action
                        AND lsl.target = :target
                        AND lsl.contextinstanceid > 0
                        AND lsl.userid $vinsql
                        $excludesql
                   GROUP BY lsl.contextinstanceid";
            $visits = $DB->get_records_sql($vsql, array_merge([
                'courseid' => $courseid,
                'action' => 'viewed',
                'target' => 'course_module',
            ], $vinparams, $excludeparams));
        }

        // Time spent per CM (tracking + activity log-gap).
        $timespent = $learnerids ? self::activity_timespent_by_cm($courseid, $learnerids) : [];

        // Grades per CM (mod items) — learner aggregates.
        $grades = [];
        if ($learnerids) {
            [$ginsql, $ginparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'gu');
            $gsql = "SELECT cm.id,
                            gi.grademax,
                            gi.gradepass,
                            MAX(gg.finalgrade) AS highestgrade,
                            MIN(gg.finalgrade) AS lowestgrade,
                            AVG(gg.finalgrade) AS avggrades
                       FROM {grade_items} gi
                       JOIN {modules} m ON m.name = gi.itemmodule
                       JOIN {course_modules} cm ON cm.module = m.id
                                                AND cm.instance = gi.iteminstance
                                                AND cm.course = gi.courseid
                       JOIN {grade_grades} gg ON gg.itemid = gi.id
                      WHERE gi.courseid = :courseid
                        AND gi.itemtype = :itemtype
                        AND gg.finalgrade IS NOT NULL
                        AND gg.userid $ginsql
                   GROUP BY cm.id, gi.grademax, gi.gradepass";
            $grades = $DB->get_records_sql($gsql, array_merge([
                'courseid' => $courseid,
                'itemtype' => 'mod',
            ], $ginparams));
        }

        // Total marks from grade items (available even when no learner has a grade yet).
        $gradeitems = $DB->get_records_sql(
            "SELECT cm.id, gi.grademax, gi.gradepass
               FROM {grade_items} gi
               JOIN {modules} m ON m.name = gi.itemmodule
               JOIN {course_modules} cm ON cm.module = m.id
                                        AND cm.instance = gi.iteminstance
                                        AND cm.course = gi.courseid
              WHERE gi.courseid = :courseid
                AND gi.itemtype = :itemtype",
            ['courseid' => $courseid, 'itemtype' => 'mod']
        );

        // Quiz open/close windows — both set => Assessment, otherwise Practice Test.
        $quizwindows = $DB->get_records_sql(
            "SELECT cm.id, q.timeopen, q.timeclose, q.grade
               FROM {quiz} q
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid]
        );

        $completionurlbase = (new \moodle_url('/local/nexreports/course_activity_completion.php'))->out(false);
        $rows = [];
        $rank = 1;
        foreach ($cms as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if (!empty($excludedmods[$cm->modname])) {
                continue;
            }
            $name = format_string($cm->name);
            $cmid = (int) $cm->id;
            if ($cm->modname === 'quiz') {
                $qw = $quizwindows[$cmid] ?? null;
                if ($qw && (int) $qw->timeopen > 0 && (int) $qw->timeclose > 0) {
                    $typename = get_string('typeassessment', 'local_nexreports');
                } else {
                    $typename = get_string('typepracticetest', 'local_nexreports');
                }
            } else {
                $typename = self::activity_type_label($cm->modname);
            }
            if ($search !== ''
                    && \core_text::strpos(\core_text::strtolower($name), $search) === false
                    && \core_text::strpos(\core_text::strtolower($typename), $search) === false) {
                continue;
            }

            $completed = (int) ($completions[$cmid]->completion ?? 0);
            $visit = (int) ($visits[$cmid]->visits ?? 0);
            $totaltime = (int) ($timespent[$cmid] ?? 0);
            $grade = $grades[$cmid] ?? null;
            $gi = $gradeitems[$cmid] ?? null;
            $totalgrade = round((float) ($gi->grademax ?? $grade->grademax ?? 0), 2);
            if ($totalgrade <= 0 && $cm->modname === 'quiz' && !empty($quizwindows[$cmid]->grade)) {
                $totalgrade = round((float) $quizwindows[$cmid]->grade, 2);
            }
            $passgrade = round((float) ($gi->gradepass ?? $grade->gradepass ?? 0), 2);
            $completionrate = $usercount > 0 ? round(($completed / $usercount) * 100, 1) : 0.0;
            if ($completed <= 0) {
                $status = get_string('statusnotyetstarted', 'local_nexreports');
            } else if ($usercount > 0 && $completed >= $usercount) {
                $status = get_string('statuscompleted', 'local_nexreports');
            } else {
                $status = get_string('statusinprogress', 'local_nexreports');
            }

            $rows[] = [
                'rank' => $rank++,
                'cmid' => $cmid,
                'courseid' => $courseid,
                'name' => $name,
                'type' => $typename,
                'status' => $status,
                'learnerscompleted' => $completed,
                'completionrate' => $completionrate,
                'totalgrade' => $totalgrade,
                'maxgrade' => $totalgrade,
                'passgrade' => $passgrade,
                'averagegrade' => round((float) ($grade->avggrades ?? 0), 2),
                'highestgrade' => round((float) ($grade->highestgrade ?? 0), 2),
                'lowestgrade' => round((float) ($grade->lowestgrade ?? 0), 2),
                'totaltimespent' => $totaltime,
                'averagetimespent' => $usercount > 0 ? (int) ceil($totaltime / $usercount) : 0,
                'totaltimespentminutes' => (int) round($totaltime / MINSECS),
                'averagetimespentminutes' => $usercount > 0
                    ? (int) round(($totaltime / $usercount) / MINSECS)
                    : 0,
                'totalvisits' => $visit,
                'averagevisits' => $usercount > 0 ? (int) ceil($visit / $usercount) : 0,
                'url' => $completionurlbase . '?courseid=' . $courseid . '&cmid=' . $cmid,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'courses' => $courses,
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedcourseid' => $courseid,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
        ];
    }

    /**
     * Course Activity Completion — learners for one activity.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $groupid Unused; kept for call-site compatibility (always ignored)
     * @param string $search
     * @param int $limit
     * @param string $year Year of passing filter
     * @param string $department Department filter
     * @return array
     */
    public static function activity_completion(
        int $courseid = 0,
        int $cmid = 0,
        int $groupid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $courseid = self::resolve_courseid($courseid);
        $courses = self::course_options();
        $activities = [];
        $search = trim(\core_text::strtolower($search));
        $cascade = self::college_year_department_options($courseid, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];
        $years = $cascade['years'];
        $departments = $cascade['departments'];
        $colleges = $cascade['colleges'];
        $showcollege = $cascade['showcollege'];
        $showdepartment = $cascade['showdepartment'] ?? 1;

        if ($courseid > 1) {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->deletioninprogress) {
                    continue;
                }
                $activities[] = [
                    'id' => (int) $cm->id,
                    'name' => format_string($cm->name) . ' (' . self::activity_type_label($cm->modname) . ')',
                ];
            }
        }

        if ($cmid <= 0 && $activities) {
            $cmid = (int) $activities[0]['id'];
        }
        $validcm = false;
        foreach ($activities as $activity) {
            if ((int) $activity['id'] === $cmid) {
                $validcm = true;
                break;
            }
        }
        if (!$validcm) {
            $cmid = $activities ? (int) $activities[0]['id'] : 0;
        }

        $empty = [
            'generated' => time(),
            'rows' => [],
            'courses' => $courses,
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'activities' => $activities,
            'selectedcourseid' => $courseid,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedcmid' => $cmid,
            'search' => $search,
        ];

        if ($courseid <= 1 || $cmid <= 0) {
            return $empty;
        }

        $learnerids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, $department, $institution);
        if ($profileids !== null) {
            $learnerids = array_values(array_intersect($learnerids, $profileids));
        }
        if (!$learnerids) {
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');

        // Completion.
        $cmcsql = "SELECT userid, completionstate, timemodified
                     FROM {course_modules_completion}
                    WHERE coursemoduleid = :cmid AND userid $insql";
        $cmc = $DB->get_records_sql($cmcsql, array_merge(['cmid' => $cmid], $inparams));

        // Visits.
        $visits = [];
        if (overview::logstore_usable()) {
            [$vinsql, $vinparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'vu');
            $vsql = "SELECT userid, COUNT(*) AS visits,
                            MIN(timecreated) AS firstaccess,
                            MAX(timecreated) AS lastaccess
                       FROM {logstore_standard_log}
                      WHERE courseid = :courseid
                        AND action = :action
                        AND target = :target
                        AND contextinstanceid = :cmid
                        AND userid $vinsql
                   GROUP BY userid";
            $visits = $DB->get_records_sql($vsql, array_merge([
                'courseid' => $courseid,
                'action' => 'viewed',
                'target' => 'course_module',
                'cmid' => $cmid,
            ], $vinparams));
        }

        // Time spent (tracking + activity log-gap).
        $times = self::activity_learner_timespent_map($courseid, $cmid, $learnerids);

        // Grade for this module.
        $cm = get_coursemodule_from_id(null, $cmid, $courseid, false, IGNORE_MISSING);
        $grades = [];
        $gi = null;
        $quizattempts = [];
        $quizclosed = false;
        $quiztimeclose = 0;
        $quizsum = 0.0;
        $quizgrade = 0.0;
        $isquiz = $cm && $cm->modname === 'quiz';
        if ($cm) {
            $gi = $DB->get_record('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
                'courseid' => $courseid,
            ]);
            if ($gi) {
                [$ginsql, $ginparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'gu');
                $gsql = "SELECT userid, finalgrade, timemodified
                           FROM {grade_grades}
                          WHERE itemid = :itemid AND userid $ginsql";
                $grades = $DB->get_records_sql($gsql, array_merge(['itemid' => $gi->id], $ginparams));
            }

            // Quiz attempts: page views alone should not mean "In progress".
            // Closed/overdue attempts are treated as finished even if cron has not auto-submitted yet.
            if ($isquiz) {
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, timeclose, sumgrades, grade', IGNORE_MISSING);
                if ($quiz) {
                    $quizsum = (float) ($quiz->sumgrades ?? 0);
                    $quizgrade = (float) ($quiz->grade ?? 0);
                    $quiztimeclose = (int) ($quiz->timeclose ?? 0);
                    $quizclosed = $quiztimeclose > 0 && $quiztimeclose < time();
                    $asql = "SELECT quiza.userid,
                                    COUNT(*) AS attempts,
                                    MAX(quiza.sumgrades) AS bestsum,
                                    MAX(CASE WHEN quiza.state = :finished THEN 1 ELSE 0 END) AS hasfinished,
                                    MAX(CASE WHEN quiza.state = :abandoned THEN 1 ELSE 0 END) AS hasabandoned,
                                    MAX(CASE WHEN quiza.state = :overdue THEN 1 ELSE 0 END) AS hasoverdue,
                                    MAX(CASE WHEN quiza.state = :inprogress THEN 1 ELSE 0 END) AS hasinprogress,
                                    MAX(NULLIF(quiza.timefinish, 0)) AS lastfinish
                               FROM {quiz_attempts} quiza
                              WHERE quiza.quiz = :quizid
                                AND quiza.preview = 0
                                AND quiza.userid $insql
                           GROUP BY quiza.userid";
                    $quizattempts = $DB->get_records_sql($asql, array_merge([
                        'quizid' => (int) $quiz->id,
                        'finished' => 'finished',
                        'abandoned' => 'abandoned',
                        'overdue' => 'overdue',
                        'inprogress' => 'inprogress',
                    ], $inparams));
                }
            }
        }

        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber'
        );

        $grademax = $gi ? round((float) $gi->grademax, 2) : 0.0;
        if ($grademax <= 0 && $isquiz && $quizgrade > 0) {
            $grademax = round($quizgrade, 2);
        }
        $unspecified = get_string('notset', 'local_nexreports');

        $rows = [];
        $rank = 1;
        foreach ($users as $user) {
            $fullname = fullname($user);
            $firstname = (string) ($user->firstname ?? '');
            $lastname = (string) ($user->lastname ?? '');
            $username = (string) ($user->username ?? '');
            $institution = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );
            if ($search !== ''
                    && \core_text::strpos(\core_text::strtolower($fullname), $search) === false
                    && \core_text::strpos(\core_text::strtolower($firstname), $search) === false
                    && \core_text::strpos(\core_text::strtolower($lastname), $search) === false
                    && \core_text::strpos(\core_text::strtolower($username), $search) === false
                    && \core_text::strpos(\core_text::strtolower($user->email), $search) === false
                    && \core_text::strpos(\core_text::strtolower($institution), $search) === false
                    && \core_text::strpos(\core_text::strtolower($userdepartment), $search) === false
                    && \core_text::strpos(\core_text::strtolower($yearofpassing), $search) === false) {
                continue;
            }
            $uid = (int) $user->id;
            $comp = $cmc[$uid] ?? null;
            $state = $comp ? (int) $comp->completionstate : 0;
            $grade = $grades[$uid] ?? null;
            $hasgrade = $grade && $grade->finalgrade !== null && $grade->finalgrade !== '';
            $finalgrade = $hasgrade ? round((float) $grade->finalgrade, 2) : null;
            $gradepass = $gi ? round((float) $gi->gradepass, 2) : 0.0;
            $visit = $visits[$uid] ?? null;
            $time = (int) ($times[$uid] ?? 0);

            $attempt = $quizattempts[$uid] ?? null;
            $hasfinishedattempt = $attempt && (
                !empty($attempt->hasfinished)
                || !empty($attempt->hasabandoned)
                || !empty($attempt->hasoverdue)
                || (!empty($attempt->hasinprogress) && $quizclosed)
            );
            $hasopenattempt = $attempt && !empty($attempt->hasinprogress) && !$hasfinishedattempt;

            // Prefer live attempt score when gradebook is empty (common before/without auto-submit cron).
            $attemptgrade = null;
            if ($attempt && $attempt->bestsum !== null && $quizsum > 0 && $quizgrade > 0) {
                $attemptgrade = round(((float) $attempt->bestsum / $quizsum) * $quizgrade, 2);
            }
            $displaygrade = $finalgrade;
            if ($displaygrade === null && $hasfinishedattempt) {
                $displaygrade = $attemptgrade;
            }
            $hasdisplaygrade = $displaygrade !== null;
            $gradepercent = ($hasdisplaygrade && $grademax > 0)
                ? round(($displaygrade / $grademax) * 100, 2)
                : null;

            // Moodle completion: 0 incomplete, 1 complete, 2 pass, 3 fail.
            // Quizzes: use attempt state (not mere page views). Auto-closed attempts count as finished.
            if ($state === COMPLETION_COMPLETE_PASS) {
                $statuslabel = get_string('statuspassed', 'local_nexreports');
                $completed = 1;
            } else if ($state === COMPLETION_COMPLETE_FAIL) {
                $statuslabel = get_string('statusfailed', 'local_nexreports');
                $completed = 0;
            } else if ($state === COMPLETION_COMPLETE) {
                $statuslabel = get_string('statuscompleted', 'local_nexreports');
                $completed = 1;
            } else if ($hasdisplaygrade && $gradepass > 0) {
                if ($displaygrade >= $gradepass) {
                    $statuslabel = get_string('statuspassed', 'local_nexreports');
                    $completed = 1;
                } else {
                    $statuslabel = get_string('statusfailed', 'local_nexreports');
                    $completed = 0;
                }
            } else if ($hasfinishedattempt || ($hasdisplaygrade && $isquiz)) {
                $statuslabel = get_string('statusfinished', 'local_nexreports');
                $completed = 1;
            } else if ($hasdisplaygrade) {
                $statuslabel = get_string('statuscompleted', 'local_nexreports');
                $completed = 1;
            } else if ($hasopenattempt || (!$isquiz && !empty($visit->visits))) {
                $statuslabel = get_string('statusinprogress', 'local_nexreports');
                $completed = 0;
            } else {
                $statuslabel = get_string('statusnotyetstarted', 'local_nexreports');
                $completed = 0;
            }

            $completedontime = 0;
            if ($state !== 0 && !empty($comp->timemodified)) {
                $completedontime = (int) $comp->timemodified;
            } else if ($hasfinishedattempt && !empty($attempt->lastfinish)) {
                $completedontime = (int) $attempt->lastfinish;
            } else if ($hasfinishedattempt && $quiztimeclose > 0) {
                $completedontime = $quiztimeclose;
            }
            $completedon = $completedontime
                ? userdate($completedontime, get_string('strftimedate', 'langconfig'))
                : '—';

            $gradedontime = 0;
            if ($grade && !empty($grade->timemodified) && $hasgrade) {
                $gradedontime = (int) $grade->timemodified;
            } else if ($hasfinishedattempt && !empty($attempt->lastfinish) && $hasdisplaygrade) {
                $gradedontime = (int) $attempt->lastfinish;
            }
            $gradedon = $gradedontime
                ? userdate($gradedontime, get_string('strftimedate', 'langconfig'))
                : '—';

            $firstaccesstime = !empty($visit->firstaccess) ? (int) $visit->firstaccess : 0;
            $lastaccesstime = !empty($visit->lastaccess) ? (int) $visit->lastaccess : 0;

            $rows[] = [
                'rank' => $rank++,
                'userid' => $uid,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'fullname' => $fullname,
                'username' => $username,
                'email' => $user->email,
                'institution' => $institution !== '' ? $institution : '—',
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'yearofpassing' => $yearofpassing,
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'completed' => $completed,
                'completedlabel' => $statuslabel,
                'completedon' => $completedon,
                'completedontime' => $completedontime,
                'grade' => $hasdisplaygrade ? (string) $displaygrade : '—',
                'gradevalue' => $hasdisplaygrade ? (float) $displaygrade : -1.0,
                'totalmark' => $grademax > 0 ? (string) $grademax : '—',
                'totalmarkvalue' => $grademax > 0 ? (float) $grademax : -1.0,
                'gradepercent' => $gradepercent !== null ? (string) $gradepercent : '—',
                'gradepercentvalue' => $gradepercent !== null ? (float) $gradepercent : -1.0,
                'passgrade' => $gradepass > 0 ? (string) $gradepass : '—',
                'passgradevalue' => $gradepass > 0 ? (float) $gradepass : -1.0,
                'gradedon' => $gradedon,
                'gradedontime' => $gradedontime,
                'firstaccess' => $firstaccesstime
                    ? userdate($firstaccesstime, get_string('strftimedate', 'langconfig'))
                    : '—',
                'firstaccesstime' => $firstaccesstime,
                'lastaccess' => $lastaccesstime
                    ? userdate($lastaccesstime, get_string('strftimedate', 'langconfig'))
                    : '—',
                'lastaccesstime' => $lastaccesstime,
                'visits' => (int) ($visit->visits ?? 0),
                'timespent' => $time,
                'timespentminutes' => (int) round($time / MINSECS),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'courses' => $courses,
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'activities' => $activities,
            'selectedcourseid' => $courseid,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedcmid' => $cmid,
            'search' => $search,
        ];
    }

    /**
     * Course Completion — learners for one course.
     *
     * @param int $courseid
     * @param int $cohortid Unused; kept for call-site compatibility
     * @param int $groupid Unused; kept for call-site compatibility
     * @param string $search
     * @param int $limit
     * @param string $year Year of passing filter
     * @param string $department Department filter
     * @return array
     */
    public static function course_completion(
        int $courseid = 0,
        int $cohortid = 0,
        int $groupid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $courseid = self::resolve_courseid($courseid);
        $courses = self::course_options();
        $search = trim(\core_text::strtolower($search));
        $cascade = self::college_year_department_options($courseid, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];
        $years = $cascade['years'];
        $departments = $cascade['departments'];
        $colleges = $cascade['colleges'];
        $showcollege = $cascade['showcollege'];
        $showdepartment = $cascade['showdepartment'] ?? 1;

        $empty = [
            'generated' => time(),
            'rows' => [],
            'courses' => $courses,
            'cohorts' => [],
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedcourseid' => $courseid > 1 ? $courseid : 0,
            'selectedcohortid' => 0,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'codingtotal' => 0,
        ];

        if ($courseid <= 1) {
            return $empty;
        }

        $learnerids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, $department, $institution);
        if ($profileids !== null) {
            $learnerids = array_values(array_intersect($learnerids, $profileids));
        }
        if (!$learnerids) {
            $empty['selectedcourseid'] = $courseid;
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');

        // Progress cache.
        $psql = "SELECT userid, progress, completiontime, totalmodules, completablemods, completedmodules
                   FROM {nexreports_course_progress}
                  WHERE courseid = :courseid AND userid $insql";
        $progress = $DB->get_records_sql($psql, array_merge(['courseid' => $courseid], $inparams));

        // Enrolment time.
        $esql = "SELECT ue.userid, MIN(ue.timecreated) AS enrolledon
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND ue.userid $insql
               GROUP BY ue.userid";
        $enrols = $DB->get_records_sql($esql, array_merge(['courseid' => $courseid], $inparams));

        // Visits on course.
        $visits = [];
        if (overview::logstore_usable()) {
            [$vinsql, $vinparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'vu');
            $vsql = "SELECT userid, COUNT(*) AS visits, MAX(timecreated) AS lastaccess
                       FROM {logstore_standard_log}
                      WHERE courseid = :courseid
                        AND action = :action
                        AND userid $vinsql
                   GROUP BY userid";
            $visits = $DB->get_records_sql($vsql, array_merge([
                'courseid' => $courseid,
                'action' => 'viewed',
            ], $vinparams));
        }

        // Time spent on course (tracking + log-gap fallback).
        $times = self::learner_timespent_map($courseid, $learnerids);

        // CodeRunner solves (counts even while a quiz attempt is still in progress).
        $coding = self::learner_coding_solved_map($courseid, $learnerids);
        $codingtotal = (int) ($coding['total'] ?? 0);
        $codingbyuser = $coding['byuser'] ?? [];
        $codingattempted = $coding['attempted'] ?? [];

        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber, lastaccess'
        );

        $unspecified = get_string('notset', 'local_nexreports');
        $rows = [];
        $rank = 1;
        foreach ($users as $user) {
            $fullname = fullname($user);
            if (!self::quiz_cumulative_matches_search($user, $fullname, $search)) {
                continue;
            }
            $uid = (int) $user->id;
            $p = $progress[$uid] ?? null;
            $pct = round((float) ($p->progress ?? 0), 1);
            $completed = $p && ((float) $p->progress >= 100 || !empty($p->completiontime));
            $completedontime = !empty($p->completiontime) ? (int) $p->completiontime : 0;
            $completedon = $completedontime
                ? userdate($completedontime, get_string('strftimedate', 'langconfig'))
                : '—';
            $enrolledontime = !empty($enrols[$uid]->enrolledon) ? (int) $enrols[$uid]->enrolledon : 0;
            $enrolledon = $enrolledontime
                ? userdate($enrolledontime, get_string('strftimedate', 'langconfig'))
                : '—';
            $visit = $visits[$uid] ?? null;
            $lastaccesstime = !empty($visit->lastaccess)
                ? (int) $visit->lastaccess
                : (!empty($user->lastaccess) ? (int) $user->lastaccess : 0);
            $lastaccess = $lastaccesstime
                ? userdate($lastaccesstime, get_string('strftimedate', 'langconfig'))
                : '—';
            $timeseconds = (int) ($times[$uid] ?? 0);
            $codingsolved = (int) ($codingbyuser[$uid] ?? 0);
            $codingtried = (int) ($codingattempted[$uid] ?? 0);
            $institution = trim((string) ($user->institution ?? ''));
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
                'fullname' => $fullname,
                'username' => (string) ($user->username ?? ''),
                'email' => $user->email,
                'institution' => $institution !== '' ? $institution : '—',
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'yearofpassing' => $yearofpassing,
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'enrolledon' => $enrolledon,
                'enrolledontime' => $enrolledontime,
                'lastaccess' => $lastaccess,
                'lastaccesstime' => $lastaccesstime,
                'progress' => $pct,
                'completed' => $completed ? 1 : 0,
                'completedlabel' => $completed
                    ? get_string('statuscompleted', 'local_nexreports')
                    : ($pct > 0
                        ? get_string('statusinprogress', 'local_nexreports')
                        : get_string('statusnotyetstarted', 'local_nexreports')),
                'completedon' => $completedon,
                'completedontime' => $completedontime,
                'completedactivities' => (int) ($p->totalmodules ?? 0),
                'totalactivities' => (int) ($p->completablemods ?? 0),
                'codingsolved' => $codingsolved,
                'codingattempted' => $codingtried,
                'codingtotal' => $codingtotal,
                'visits' => (int) ($visit->visits ?? 0),
                'timespent' => $timeseconds,
                'timespentminutes' => (int) round($timeseconds / MINSECS),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'courses' => $courses,
            'cohorts' => [],
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedcourseid' => $courseid,
            'selectedcohortid' => 0,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'codingtotal' => $codingtotal,
        ];
    }

    /**
     * Count CodeRunner questions each learner has fully solved in this course.
     *
     * Practice Test quizzes only — Assessments (open+close both set) excluded.
     * Includes in-progress quiz attempts. Marks live on question_attempt_steps
     * (question_attempts has no fraction column). CodeRunner behaviour is
     * adaptive_adapted_for_coderunner; any step with fraction > 0 means the
     * learner passed the tests at least once (penalties keep the mark < 1).
     *
     * @param int $courseid
     * @param int[] $userids
     * @return array{total:int,byuser:array<int,int>,attempted:array<int,int>}
     */
    public static function coding_stats_for_learners(int $courseid, array $userids): array {
        return self::learner_coding_solved_map($courseid, $userids);
    }


    /**
     * SQL AND-clause: quiz is not an Assessment (open+close window both set).
     *
     * Matches activity type labels: both timeopen and timeclose > 0 => Assessment.
     *
     * @param string $quizalias
     * @return string
     */
    private static function quiz_exclude_assessment_sql(string $quizalias = 'quiz'): string {
        return " AND (COALESCE({$quizalias}.timeopen, 0) = 0 OR COALESCE({$quizalias}.timeclose, 0) = 0)";
    }

    /**
     * @param int $courseid
     * @param int[] $userids
     * @return array{total:int,byuser:array<int,int>,attempted:array<int,int>}
     */
    private static function learner_coding_solved_map(int $courseid, array $userids): array {
        global $DB;

        $empty = ['total' => 0, 'byuser' => [], 'attempted' => []];
        if ($courseid <= 1 || !$userids) {
            return $empty;
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('quiz')
                || !$dbman->table_exists('quiz_attempts')
                || !$dbman->table_exists('question_attempts')
                || !$dbman->table_exists('question_attempt_steps')) {
            return $empty;
        }

        $notassessment = self::quiz_exclude_assessment_sql('quiz');

        $total = 0;
        try {
            $total = self::course_coding_question_total($courseid);
        } catch (\Throwable $e) {
            debugging('local_nexreports coding total failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $total = 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cu');
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $baseparams = array_merge(['courseid' => $courseid], $inparams);

        $queries = [];

        // Primary: CodeRunner behaviour + any positive step mark (in-progress OK).
        $queries[] = [
            'sql' => "SELECT quiza.userid, COUNT(DISTINCT $slotkey) AS solved
                        FROM {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                        JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                       WHERE quiza.preview = 0
                         AND quiza.userid $insql
                         AND qa.behaviour = :behaviour
                         $notassessment
                         AND EXISTS (
                                SELECT 1
                                  FROM {question_attempt_steps} qas
                                 WHERE qas.questionattemptid = qa.id
                                   AND qas.fraction IS NOT NULL
                                   AND qas.fraction > 0
                         )
                    GROUP BY quiza.userid",
            'params' => array_merge($baseparams, [
                'behaviour' => 'adaptive_adapted_for_coderunner',
            ]),
        ];

        // Fallback: qtype coderunner + positive step mark.
        if ($dbman->table_exists('question')) {
            $queries[] = [
                'sql' => "SELECT quiza.userid, COUNT(DISTINCT $slotkey) AS solved
                            FROM {quiz_attempts} quiza
                            JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                            JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                            JOIN {question} q ON q.id = qa.questionid AND q.qtype = :qtype
                           WHERE quiza.preview = 0
                             AND quiza.userid $insql
                             $notassessment
                             AND EXISTS (
                                    SELECT 1
                                      FROM {question_attempt_steps} qas
                                     WHERE qas.questionattemptid = qa.id
                                       AND qas.fraction IS NOT NULL
                                       AND qas.fraction > 0
                             )
                        GROUP BY quiza.userid",
                'params' => array_merge($baseparams, ['qtype' => 'coderunner']),
            ];
        }

        // Fallback: -_rawfraction = 1 (pre-penalty full mark), no SQL cast.
        if ($dbman->table_exists('question_attempt_step_data')) {
            $queries[] = [
                'sql' => "SELECT quiza.userid, COUNT(DISTINCT $slotkey) AS solved
                            FROM {quiz_attempts} quiza
                            JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                            JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                            JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                           WHERE quiza.preview = 0
                             AND quiza.userid $insql
                             $notassessment
                             AND qasd.name = :rawfrac
                             AND (qasd.value = :one
                                  OR qasd.value LIKE :onepoint
                                  OR qasd.value LIKE :nines)
                        GROUP BY quiza.userid",
                'params' => array_merge($baseparams, [
                    'rawfrac' => '-_rawfraction',
                    'one' => '1',
                    'onepoint' => '1.%',
                    'nines' => '0.999%',
                ]),
            ];
        }

        $byuser = [];
        foreach ($queries as $query) {
            try {
                $rows = $DB->get_records_sql($query['sql'], $query['params']);
            } catch (\Throwable $e) {
                debugging('local_nexreports coding solved query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            if (!$rows) {
                continue;
            }
            foreach ($rows as $row) {
                $uid = (int) $row->userid;
                $solved = (int) $row->solved;
                if ($solved > ($byuser[$uid] ?? 0)) {
                    $byuser[$uid] = $solved;
                }
            }
            if ($byuser) {
                break;
            }
        }

        if ($total <= 0) {
            try {
                $total = self::course_coding_question_total_from_attempts($courseid);
            } catch (\Throwable $e) {
                $total = 0;
            }
        }

        // Attempted = any CodeRunner slot touched (even with zero / fail marks).
        $attempted = [];
        try {
            $asql = "SELECT quiza.userid, COUNT(DISTINCT $slotkey) AS attempted
                       FROM {quiz_attempts} quiza
                       JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                       JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                      WHERE quiza.preview = 0
                        AND quiza.userid $insql
                        AND qa.behaviour = :behaviour
                        $notassessment
                   GROUP BY quiza.userid";
            $arows = $DB->get_records_sql($asql, array_merge($baseparams, [
                'behaviour' => 'adaptive_adapted_for_coderunner',
            ]));
            foreach ($arows as $row) {
                $attempted[(int) $row->userid] = (int) $row->attempted;
            }
        } catch (\Throwable $e) {
            debugging('local_nexreports coding attempted query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return ['total' => $total, 'byuser' => $byuser, 'attempted' => $attempted];
    }

    /**
     * Distinct CodeRunner question slots in Practice Test quizzes of this course.
     *
     * @param int $courseid
     * @return int
     */
    private static function course_coding_question_total(int $courseid): int {
        global $DB;

        $dbman = $DB->get_manager();
        $notassessment = self::quiz_exclude_assessment_sql('quiz');

        if ($dbman->table_exists('question_references')
                && $dbman->table_exists('question_versions')
                && $dbman->table_exists('quiz_slots')
                && $dbman->table_exists('question')) {
            $sql = "SELECT COUNT(DISTINCT qs.id)
                      FROM {quiz} quiz
                      JOIN {quiz_slots} qs ON qs.quizid = quiz.id
                      JOIN {question_references} qr ON qr.itemid = qs.id
                           AND qr.component = :component
                           AND qr.questionarea = :qarea
                     WHERE quiz.course = :courseid
                       $notassessment
                       AND EXISTS (
                            SELECT 1
                              FROM {question_versions} qv
                              JOIN {question} q ON q.id = qv.questionid
                             WHERE qv.questionbankentryid = qr.questionbankentryid
                               AND q.qtype = :qtype
                       )";
            $count = (int) $DB->count_records_sql($sql, [
                'component' => 'mod_quiz',
                'qarea' => 'slot',
                'qtype' => 'coderunner',
                'courseid' => $courseid,
            ]);
            if ($count > 0) {
                return $count;
            }
        }

        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $sql = "SELECT COUNT(DISTINCT $slotkey)
                  FROM {quiz_attempts} quiza
                  JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                  JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                 WHERE quiza.preview = 0
                   AND qa.behaviour = :behaviour
                   $notassessment";
        $count = (int) $DB->count_records_sql($sql, [
            'courseid' => $courseid,
            'behaviour' => 'adaptive_adapted_for_coderunner',
        ]);
        if ($count > 0) {
            return $count;
        }

        return self::course_coding_question_total_from_attempts($courseid);
    }

    /**
     * Fallback total: distinct CodeRunner questions ever attempted in Practice Test quizzes.
     *
     * @param int $courseid
     * @return int
     */
    private static function course_coding_question_total_from_attempts(int $courseid): int {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('question')) {
            return 0;
        }

        $notassessment = self::quiz_exclude_assessment_sql('quiz');
        $sql = "SELECT COUNT(DISTINCT qa.questionid)
                  FROM {quiz_attempts} quiza
                  JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course = :courseid
                  JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                  JOIN {question} q ON q.id = qa.questionid AND q.qtype = :qtype
                 WHERE quiza.preview = 0
                   $notassessment";
        return (int) $DB->count_records_sql($sql, [
            'courseid' => $courseid,
            'qtype' => 'coderunner',
        ]);
    }


    /**
     * All Quizzes — Course Completion layout with inclusive quiz progress.
     *
     * Same columns as Course Completion, but a quiz counts toward activities /
     * progress when it is passed, failed, or still in progress (not only pass).
     *
     * @param int $courseid
     * @param int $cohortid Unused; kept for call-site compatibility
     * @param int $groupid Unused; kept for call-site compatibility
     * @param string $search
     * @param int $limit
     * @param string $year Year of passing filter
     * @param string $department Department filter
     * @return array
     */
    public static function quiz_cumulative(
        int $courseid = 0,
        int $cohortid = 0,
        int $groupid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $courseid = self::resolve_courseid($courseid);
        $courses = self::course_options();
        $search = trim(\core_text::strtolower($search));
        $cascade = self::college_year_department_options($courseid, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];
        $years = $cascade['years'];
        $departments = $cascade['departments'];
        $colleges = $cascade['colleges'];
        $showcollege = $cascade['showcollege'];
        $showdepartment = $cascade['showdepartment'] ?? 1;

        $empty = [
            'generated' => time(),
            'rows' => [],
            'quizrows' => [],
            'courses' => $courses,
            'cohorts' => [],
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedcourseid' => $courseid > 1 ? $courseid : 0,
            'selectedcohortid' => 0,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'quizcount' => 0,
            'codingtotal' => 0,
        ];

        if ($courseid <= 1) {
            return $empty;
        }

        $modinfo = get_fast_modinfo($courseid);
        $quizcms = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || $cm->modname !== 'quiz') {
                continue;
            }
            $quizcms[] = $cm;
        }
        $quizcount = count($quizcms);
        $empty['quizcount'] = $quizcount;
        $empty['selectedcourseid'] = $courseid;

        $learnerids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, $department, $institution);
        if ($profileids !== null) {
            $learnerids = array_values(array_intersect($learnerids, $profileids));
        }
        if (!$learnerids) {
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');

        // Enrolment + visits + time (Course Completion parity).
        $esql = "SELECT ue.userid, MIN(ue.timecreated) AS enrolledon
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND ue.userid $insql
               GROUP BY ue.userid";
        $enrols = $DB->get_records_sql($esql, array_merge(['courseid' => $courseid], $inparams));

        $visits = [];
        if (overview::logstore_usable()) {
            [$vinsql, $vinparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'vu');
            $vsql = "SELECT userid, COUNT(*) AS visits, MAX(timecreated) AS lastaccess
                       FROM {logstore_standard_log}
                      WHERE courseid = :courseid
                        AND action = :action
                        AND userid $vinsql
                   GROUP BY userid";
            $visits = $DB->get_records_sql($vsql, array_merge([
                'courseid' => $courseid,
                'action' => 'viewed',
            ], $vinparams));
        }
        $times = self::learner_timespent_map($courseid, $learnerids);
        $coding = self::learner_coding_solved_map($courseid, $learnerids);
        $codingtotal = (int) ($coding['total'] ?? 0);
        $codingbyuser = $coding['byuser'] ?? [];
        $codingattempted = $coding['attempted'] ?? [];

        if (!$quizcount) {
            // Still return learners with zero quiz progress (Course Completion shape).
            $users = $DB->get_records_select(
                'user',
                "id $insql AND deleted = 0",
                $inparams,
                'lastname ASC, firstname ASC',
                'id, firstname, lastname, email, username, institution, department, idnumber, lastaccess'
            );
            $rows = [];
            $rank = 1;
            foreach ($users as $user) {
                $fullname = fullname($user);
                if (!self::quiz_cumulative_matches_search($user, $fullname, $search)) {
                    continue;
                }
                $uid = (int) $user->id;
                $visit = $visits[$uid] ?? null;
                $timeseconds = (int) ($times[$uid] ?? 0);
                $rows[] = self::quiz_cumulative_learner_row(
                    $rank++,
                    $user,
                    $fullname,
                    $enrols[$uid] ?? null,
                    $visit,
                    $timeseconds,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    null,
                    (int) ($codingbyuser[$uid] ?? 0),
                    (int) ($codingattempted[$uid] ?? 0),
                    $codingtotal
                );
                if (count($rows) >= $limit) {
                    break;
                }
            }
            $empty['rows'] = $rows;
            $empty['codingtotal'] = $codingtotal;
            return $empty;
        }

        $quizids = [];
        $quizmeta = [];
        foreach ($quizcms as $cm) {
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, name, grade, sumgrades', IGNORE_MISSING);
            if (!$quiz) {
                continue;
            }
            $qid = (int) $quiz->id;
            $quizids[] = $qid;
            $gi = $DB->get_record('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $qid,
                'courseid' => $courseid,
            ]);
            $quizmeta[$qid] = (object) [
                'cmid' => (int) $cm->id,
                'name' => format_string($cm->name),
                'grade' => (float) $quiz->grade,
                'sumgrades' => (float) ($quiz->sumgrades ?? 0),
                'grademax' => $gi ? (float) $gi->grademax : (float) $quiz->grade,
                'gradepass' => $gi ? (float) $gi->gradepass : 0.0,
                'itemid' => $gi ? (int) $gi->id : 0,
            ];
        }
        $quizcount = count($quizmeta);
        if (!$quizids) {
            $empty['quizcount'] = 0;
            $empty['codingtotal'] = $codingtotal;
            return $empty;
        }

        $grades = [];
        $itemids = array_values(array_filter(array_map(static function ($m) {
            return (int) $m->itemid;
        }, $quizmeta)));
        if ($itemids) {
            [$iinsql, $iinparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'gi');
            [$ginsql, $ginparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'gu');
            $grows = $DB->get_records_sql(
                "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.timemodified
                   FROM {grade_grades} gg
                  WHERE gg.itemid $iinsql AND gg.userid $ginsql",
                array_merge($iinparams, $ginparams)
            );
            foreach ($grows as $grow) {
                $grades[(int) $grow->itemid . ':' . (int) $grow->userid] = $grow;
            }
        }

        [$qinsql, $qinparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qz');
        $asql = "SELECT " . $DB->sql_concat('quiza.quiz', "':'", 'quiza.userid') . " AS k,
                        quiza.quiz,
                        quiza.userid,
                        COUNT(*) AS attempts,
                        MAX(quiza.sumgrades) AS bestsum,
                        MAX(CASE WHEN quiza.state = :finished THEN 1 ELSE 0 END) AS hasfinished,
                        MAX(CASE WHEN quiza.state = :inprogress THEN 1 ELSE 0 END) AS hasinprogress,
                        MAX(quiza.timestart) AS laststart,
                        MAX(NULLIF(quiza.timefinish, 0)) AS lastfinish
                   FROM {quiz_attempts} quiza
                  WHERE quiza.quiz $qinsql
                    AND quiza.preview = 0
                    AND quiza.userid $insql
               GROUP BY quiza.quiz, quiza.userid";
        $attempts = $DB->get_records_sql($asql, array_merge([
            'finished' => 'finished',
            'inprogress' => 'inprogress',
        ], $qinparams, $inparams));

        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber, lastaccess'
        );

        $quizagg = [];
        foreach ($quizmeta as $qid => $meta) {
            $quizagg[$qid] = [
                'cmid' => $meta->cmid,
                'quizid' => $qid,
                'name' => $meta->name,
                'passed' => 0,
                'failed' => 0,
                'inprogress' => 0,
                'notstarted' => 0,
                'gradesum' => 0.0,
                'gradecount' => 0,
                'grademax' => round($meta->grademax, 2),
                'gradepass' => $meta->gradepass > 0 ? round($meta->gradepass, 2) : 0.0,
            ];
        }

        $rows = [];
        $rank = 1;
        foreach ($users as $user) {
            $fullname = fullname($user);
            if (!self::quiz_cumulative_matches_search($user, $fullname, $search)) {
                continue;
            }
            $uid = (int) $user->id;
            $passed = 0;
            $failed = 0;
            $inprogress = 0;
            $notstarted = 0;
            $gradesum = 0.0;
            $gradecount = 0;
            $lastts = 0;

            foreach ($quizmeta as $qid => $meta) {
                $gkey = $meta->itemid ? ($meta->itemid . ':' . $uid) : '';
                $g = $gkey !== '' ? ($grades[$gkey] ?? null) : null;
                $a = $attempts[$qid . ':' . $uid] ?? null;

                $hasgrade = $g && $g->finalgrade !== null && $g->finalgrade !== '';
                $finalgrade = $hasgrade ? (float) $g->finalgrade : null;
                $attemptgrade = null;
                if ($a && $a->bestsum !== null && $meta->sumgrades > 0 && $meta->grade > 0) {
                    $attemptgrade = ((float) $a->bestsum / $meta->sumgrades) * $meta->grade;
                }
                $display = $finalgrade !== null ? $finalgrade : $attemptgrade;

                $hasfinished = $a && !empty($a->hasfinished);
                $hasinprogress = $a && !empty($a->hasinprogress);
                $attemptcount = $a ? (int) $a->attempts : 0;

                if ($display !== null && $meta->gradepass > 0) {
                    $bucket = ($display >= $meta->gradepass) ? 'passed' : 'failed';
                } else if ($display !== null && $hasfinished) {
                    $bucket = ($display > 0) ? 'passed' : 'failed';
                } else if ($hasfinished) {
                    $bucket = 'failed';
                } else if ($hasinprogress || $attemptcount > 0) {
                    $bucket = 'inprogress';
                } else {
                    $bucket = 'notstarted';
                }

                if ($bucket === 'passed') {
                    $passed++;
                    $quizagg[$qid]['passed']++;
                } else if ($bucket === 'failed') {
                    $failed++;
                    $quizagg[$qid]['failed']++;
                } else if ($bucket === 'inprogress') {
                    $inprogress++;
                    $quizagg[$qid]['inprogress']++;
                } else {
                    $notstarted++;
                    $quizagg[$qid]['notstarted']++;
                }

                if ($display !== null && $meta->grademax > 0) {
                    $pct = max(0.0, min(100.0, ($display / $meta->grademax) * 100.0));
                    $gradesum += $pct;
                    $gradecount++;
                    $quizagg[$qid]['gradesum'] += $pct;
                    $quizagg[$qid]['gradecount']++;
                }

                foreach ([
                    $a ? (int) ($a->lastfinish ?? 0) : 0,
                    $a ? (int) ($a->laststart ?? 0) : 0,
                    $g ? (int) ($g->timemodified ?? 0) : 0,
                ] as $ts) {
                    if ($ts > $lastts) {
                        $lastts = $ts;
                    }
                }
            }

            $visit = $visits[$uid] ?? null;
            $timeseconds = (int) ($times[$uid] ?? 0);
            $avg = $gradecount ? round($gradesum / $gradecount, 1) : null;
            $rows[] = self::quiz_cumulative_learner_row(
                $rank++,
                $user,
                $fullname,
                $enrols[$uid] ?? null,
                $visit,
                $timeseconds,
                $passed,
                $failed,
                $inprogress,
                $notstarted,
                $quizcount,
                $lastts,
                $avg,
                (int) ($codingbyuser[$uid] ?? 0),
                (int) ($codingattempted[$uid] ?? 0),
                $codingtotal
            );

            if (count($rows) >= $limit) {
                break;
            }
        }

        $quizrows = [];
        $qrank = 1;
        foreach ($quizagg as $agg) {
            $avg = $agg['gradecount'] ? round($agg['gradesum'] / $agg['gradecount'], 1) : null;
            $touched = $agg['passed'] + $agg['failed'] + $agg['inprogress'];
            $learners = count($learnerids);
            $quizrows[] = [
                'rank' => $qrank++,
                'cmid' => $agg['cmid'],
                'quizid' => $agg['quizid'],
                'name' => $agg['name'],
                'url' => (new \moodle_url('/local/nexreports/course_activity_completion.php', [
                    'courseid' => $courseid,
                    'cmid' => $agg['cmid'],
                ]))->out(false),
                'passed' => $agg['passed'],
                'failed' => $agg['failed'],
                'inprogress' => $agg['inprogress'],
                'notstarted' => $agg['notstarted'],
                'learners' => $learners,
                'touched' => $touched,
                'progress' => $learners ? round(($touched / $learners) * 100, 1) : 0.0,
                'avggrade' => $avg !== null ? (string) $avg : '—',
                'grademax' => $agg['grademax'],
                'gradepass' => $agg['gradepass'] > 0 ? (string) $agg['gradepass'] : '—',
            ];
        }

        return [
            'generated' => time(),
            'rows' => $rows,
            'quizrows' => $quizrows,
            'courses' => $courses,
            'cohorts' => [],
            'groups' => [],
            'colleges' => $colleges,
            'years' => $years,
            'departments' => $departments,
            'selectedcourseid' => $courseid,
            'selectedcohortid' => 0,
            'selectedgroupid' => 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'quizcount' => $quizcount,
            'codingtotal' => $codingtotal,
        ];
    }

    /**
     * Whether a learner row matches the cumulative report search box.
     *
     * @param \stdClass $user
     * @param string $fullname
     * @param string $search Already lowercased
     * @return bool
     */
    private static function quiz_cumulative_matches_search($user, string $fullname, string $search): bool {
        if ($search === '') {
            return true;
        }
        $fields = [
            $fullname,
            (string) ($user->firstname ?? ''),
            (string) ($user->lastname ?? ''),
            (string) ($user->username ?? ''),
            (string) ($user->email ?? ''),
            (string) ($user->institution ?? ''),
            (string) ($user->department ?? ''),
            overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                get_string('notset', 'local_nexreports')
            ),
        ];
        foreach ($fields as $field) {
            if (\core_text::strpos(\core_text::strtolower($field), $search) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build one All Quizzes learner row in Course Completion shape.
     *
     * completedactivities = passed + failed + inprogress (inclusive engagement).
     * progress % = completedactivities / total quizzes.
     *
     * @param int $rank
     * @param \stdClass $user
     * @param string $fullname
     * @param \stdClass|null $enrol
     * @param \stdClass|null $visit
     * @param int $timeseconds
     * @param int $passed
     * @param int $failed
     * @param int $inprogress
     * @param int $notstarted
     * @param int $quizcount
     * @param int $lastts
     * @param float|null $avg
     * @param int $codingsolved
     * @param int $codingtried
     * @param int $codingtotal
     * @return array
     */
    private static function quiz_cumulative_learner_row(
        int $rank,
        $user,
        string $fullname,
        $enrol,
        $visit,
        int $timeseconds,
        int $passed,
        int $failed,
        int $inprogress,
        int $notstarted,
        int $quizcount,
        int $lastts,
        $avg,
        int $codingsolved,
        int $codingtried,
        int $codingtotal
    ): array {
        $uid = (int) $user->id;
        $done = $passed + $failed + $inprogress;
        $pct = $quizcount > 0 ? round(($done / $quizcount) * 100, 1) : 0.0;
        $completed = $quizcount > 0 && $done >= $quizcount;
        $enrolledontime = !empty($enrol->enrolledon) ? (int) $enrol->enrolledon : 0;
        $enrolledon = $enrolledontime
            ? userdate($enrolledontime, get_string('strftimedate', 'langconfig'))
            : '—';
        $lastaccesstime = !empty($visit->lastaccess)
            ? (int) $visit->lastaccess
            : (!empty($user->lastaccess) ? (int) $user->lastaccess : 0);
        $lastaccess = $lastaccesstime
            ? userdate($lastaccesstime, get_string('strftimedate', 'langconfig'))
            : '—';
        $completedontime = $lastts ?: 0;
        $completedon = $completedontime
            ? userdate($completedontime, get_string('strftimedate', 'langconfig'))
            : '—';
        $institution = trim((string) ($user->institution ?? ''));
        $department = trim((string) ($user->department ?? ''));
        $yearofpassing = overview::normalize_year_of_passing_public(
            (string) ($user->idnumber ?? ''),
            get_string('notset', 'local_nexreports')
        );

        return [
            'rank' => $rank,
            'userid' => $uid,
            'firstname' => (string) ($user->firstname ?? ''),
            'lastname' => (string) ($user->lastname ?? ''),
            'fullname' => $fullname,
            'username' => (string) ($user->username ?? ''),
            'email' => $user->email,
            'institution' => $institution !== '' ? $institution : '—',
            'department' => $department !== '' ? $department : '—',
            'yearofpassing' => $yearofpassing,
            'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
            'enrolledon' => $enrolledon,
            'enrolledontime' => $enrolledontime,
            'lastaccess' => $lastaccess,
            'lastaccesstime' => $lastaccesstime,
            'progress' => $pct,
            'completed' => $completed ? 1 : 0,
            'completedlabel' => $completed
                ? get_string('statuscompleted', 'local_nexreports')
                : ($done > 0
                    ? get_string('statusinprogress', 'local_nexreports')
                    : get_string('statusnotyetstarted', 'local_nexreports')),
            'completedon' => $completedon,
            'completedontime' => $completedontime,
            'completedactivities' => $done,
            'totalactivities' => $quizcount,
            'passed' => $passed,
            'failed' => $failed,
            'inprogress' => $inprogress,
            'notstarted' => $notstarted,
            'codingsolved' => $codingsolved,
            'codingattempted' => $codingtried,
            'codingtotal' => $codingtotal,
            'avggrade' => $avg !== null ? (string) $avg : '—',
            'visits' => (int) ($visit->visits ?? 0),
            'timespent' => $timeseconds,
            'timespentminutes' => (int) round($timeseconds / MINSECS),
        ];
    }

    /**
     * @param int $days
     * @return array{0:int,1:int}
     */
    private static function period_bounds(int $days): array {
        $to = usergetmidnight(time()) + DAYSECS;
        return [$to - ($days * DAYSECS), $to];
    }
}
