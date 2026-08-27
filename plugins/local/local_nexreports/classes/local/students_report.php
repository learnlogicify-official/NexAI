<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Student engagement / all-learner summary report.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-learner rollup used by the Students tab (Edwiser All Learner Summary parity).
 */
class students_report {


    /**
     * College (site admin only) + year + department cascade options.
     *
     * @param int $courseid
     * @param string $institution
     * @param string $year
     * @param string $department
     * @return array{showcollege:int,showdepartment:int,institution:string,year:string,department:string,colleges:array,years:array,departments:array}
     */
    private static function college_year_department_options(
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
        $colleges = $showcollege
            ? profile_filters::search_institutions('', 100, $courseid > 1 ? $courseid : 0)
            : [];
        // Years for this college (and locked department when department-scoped).
        $years = profile_filters::search_years(
            '',
            100,
            $courseid > 1 ? $courseid : 0,
            $institution,
            $showdepartment ? '' : $department
        );
        $departments = [];
        if (!$showdepartment && $department !== '') {
            $departments = [['id' => $department, 'name' => $department]];
        } else if ($year !== '') {
            $departments = profile_filters::search_departments(
                '',
                100,
                $courseid > 1 ? $courseid : 0,
                $year,
                $institution
            );
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
     * Build learner summary rows + aggregate KPIs.
     *
     * @param int $courseid 0 = all visible courses
     * @param int $cohortid Unused; kept for call-site compatibility
     * @param string $search
     * @param int $limit
     * @param string $year Year of passing
     * @param string $department Department
     * @param string $inactive all|never|1|3|6|suspended
     * @return array
     */
    public static function engagement(
        int $courseid = 0,
        int $cohortid = 0,
        string $search = '',
        int $limit = 2000,
        string $year = '',
        string $department = '',
        string $inactive = 'all',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(5000, $limit));
        $search = trim($search);
        $inactive = trim($inactive);
        if ($inactive === '') {
            $inactive = 'all';
        }
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
            $userids = filters::learner_ids($courseid, 0, 0);
        } else {
            $userids = self::all_learner_ids(0);
        }
        $profileids = profile_filters::userids($courseid > 1 ? $courseid : 0, $year, $department, $institution);
        if ($profileids !== null) {
            $userids = array_values(array_intersect($userids, $profileids));
        }

        $empty = [
            'generated' => time(),
            'rows' => [],
            'summary' => self::empty_summary(),
            'courses' => filters::courses(500),
            'cohorts' => [],
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'selectedcourseid' => $courseid,
            'selectedcohortid' => 0,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedinactive' => $inactive,
            'search' => $search,
        ];

        if (!$userids) {
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber, lastaccess, suspended'
        );

        $now = time();
        $unspecified = get_string('notset', 'local_nexreports');
        $searchl = \core_text::strtolower($search);
        $filtered = [];
        foreach ($users as $user) {
            if (!self::matches_inactive($user, $inactive, $now)) {
                continue;
            }
            $fullname = fullname($user);
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );
            if ($searchl !== '' && !self::matches_search($user, $fullname, $yearofpassing, $searchl)) {
                continue;
            }
            $filtered[(int) $user->id] = $user;
        }
        if (!$filtered) {
            return $empty;
        }

        $ids = array_keys($filtered);
        $progressmap = self::progress_maps($ids, $courseid);
        $timesitemap = self::timespent_seconds_map($ids, 0, true); // Entire LMS.
        $timecoursemap = self::timespent_seconds_map($ids, $courseid, false);
        $visitsmap = self::visits_map($ids, $courseid);
        $activitiesmap = self::activities_completed_map($ids, $courseid);
        $assignmap = self::completed_mod_map($ids, $courseid, 'assign');
        $quizmap = self::completed_quiz_map($ids, $courseid);
        $scormmap = self::completed_mod_map($ids, $courseid, 'scorm');
        $grademap = self::total_grade_sum_map($ids, $courseid);
        $codingmap = self::coding_totals_map($ids, $courseid);

        $rows = [];
        $rank = 1;
        $sumvisits = 0;
        $sumcoursetime = 0;
        foreach ($ids as $userid) {
            $user = $filtered[$userid];
            $prog = $progressmap[$userid] ?? ['enrolled' => 0, 'inprogress' => 0, 'completed' => 0, 'avgprogress' => 0.0];
            // Prefer live enrolment count when progress cache is incomplete.
            $enrolled = max((int) $prog['enrolled'], self::enrolled_course_count($userid, $courseid));
            $sitesecs = (int) ($timesitemap[$userid] ?? 0);
            $coursesecs = (int) ($timecoursemap[$userid] ?? 0);
            $visits = (int) ($visitsmap[$userid] ?? 0);
            $sumvisits += $visits;
            $sumcoursetime += $coursesecs;
            $institution = trim((string) ($user->institution ?? ''));
            $userdepartment = trim((string) ($user->department ?? ''));
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );
            $lastaccesstime = (int) ($user->lastaccess ?? 0);
            $active = empty($user->suspended);
            $coding = $codingmap[$userid] ?? ['solved' => 0, 'total' => 0];

            $rows[] = [
                'rank' => $rank++,
                'userid' => $userid,
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'fullname' => fullname($user),
                'username' => (string) ($user->username ?? ''),
                'email' => $user->email,
                'institution' => $institution !== '' ? $institution : '—',
                'department' => $userdepartment !== '' ? $userdepartment : '—',
                'yearofpassing' => $yearofpassing,
                'url' => (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false),
                'status' => $active ? get_string('active', 'local_nexreports') : get_string('inactive', 'local_nexreports'),
                'statusactive' => $active ? 1 : 0,
                'lastaccess' => $lastaccesstime
                    ? userdate($lastaccesstime, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'),
                'lastaccesstime' => $lastaccesstime,
                'enrolledcourses' => $enrolled,
                'inprogress' => (int) $prog['inprogress'],
                'completed' => (int) $prog['completed'],
                'avgprogress' => (float) $prog['avgprogress'],
                'totalgrade' => (float) ($grademap[$userid] ?? 0),
                'codingsolved' => (int) $coding['solved'],
                'codingtotal' => (int) $coding['total'],
                'timespentonsite' => $sitesecs,
                'timespentoncourse' => $coursesecs,
                'timespentminutes' => (int) round($coursesecs / MINSECS),
                'activitiescompleted' => (int) ($activitiesmap[$userid] ?? 0),
                'visits' => $visits,
                'completedassignments' => (int) ($assignmap[$userid] ?? 0),
                'completedquizzes' => (int) ($quizmap[$userid] ?? 0),
                'completedscorms' => (int) ($scormmap[$userid] ?? 0),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        $learnercount = count($rows);
        $summary = [
            'totalvisits' => $sumvisits,
            'avgvisits' => $learnercount ? (int) round($sumvisits / $learnercount) : 0,
            'totallearners' => $learnercount,
            'totaltimespent' => $sumcoursetime,
            'avgtimespent' => $learnercount ? (int) round($sumcoursetime / $learnercount) : 0,
        ];

        return [
            'generated' => time(),
            'rows' => $rows,
            'summary' => $summary,
            'courses' => filters::courses(500),
            'cohorts' => [],
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'selectedcourseid' => $courseid,
            'selectedcohortid' => 0,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedinactive' => $inactive,
            'search' => $search,
        ];
    }

    /**
     * @return array{totalvisits:int,avgvisits:int,totallearners:int,totaltimespent:int,avgtimespent:int}
     */
    private static function empty_summary(): array {
        return [
            'totalvisits' => 0,
            'avgvisits' => 0,
            'totallearners' => 0,
            'totaltimespent' => 0,
            'avgtimespent' => 0,
        ];
    }

    /**
     * @param \stdClass $user
     * @param string $inactive
     * @param int $now
     * @return bool
     */
    private static function matches_inactive($user, string $inactive, int $now): bool {
        if ($inactive === 'all') {
            return true;
        }
        if ($inactive === 'suspended') {
            return !empty($user->suspended);
        }
        $last = (int) ($user->lastaccess ?? 0);
        if ($inactive === 'never') {
            return $last <= 0;
        }
        $months = (int) $inactive;
        if ($months > 0) {
            if ($last <= 0) {
                return true;
            }
            return $last < ($now - ($months * 30 * DAYSECS));
        }
        return true;
    }

    /**
     * @param \stdClass $user
     * @param string $fullname
     * @param string $yearofpassing
     * @param string $search Lowercased
     * @return bool
     */
    private static function matches_search($user, string $fullname, string $yearofpassing, string $search): bool {
        $fields = [
            $fullname,
            (string) ($user->firstname ?? ''),
            (string) ($user->lastname ?? ''),
            (string) ($user->username ?? ''),
            (string) ($user->email ?? ''),
            (string) ($user->institution ?? ''),
            (string) ($user->department ?? ''),
            $yearofpassing,
        ];
        foreach ($fields as $field) {
            if (\core_text::strpos(\core_text::strtolower($field), $search) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $cohortid
     * @return int[]
     */
    private static function all_learner_ids(int $cohortid): array {
        $courses = filters::courses(500);
        $ids = [];
        foreach ($courses as $course) {
            foreach (filters::learner_ids((int) $course['id'], $cohortid) as $uid) {
                $ids[$uid] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private static function enrolled_course_count(int $userid, int $courseid): int {
        global $DB;
        $sql = "SELECT COUNT(DISTINCT e.courseid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND e.courseid > 1
                   AND ue.status = 0";
        $params = ['userid' => $userid];
        if ($courseid > 1) {
            $sql .= ' AND e.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * @param int[] $userids
     * @param int $courseid
     * @return array<int, array{enrolled:int,inprogress:int,completed:int,avgprogress:float}>
     */
    private static function progress_maps(array $userids, int $courseid): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'pu');
        $where = "userid $insql";
        if ($courseid > 1) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $records = $DB->get_recordset_select('nexreports_course_progress', $where, $params);
        $out = [];
        foreach ($records as $row) {
            $uid = (int) $row->userid;
            if (!isset($out[$uid])) {
                $out[$uid] = ['enrolled' => 0, 'inprogress' => 0, 'completed' => 0, 'avgprogress' => 0.0, '_sum' => 0.0];
            }
            $out[$uid]['enrolled']++;
            $pct = (float) $row->progress;
            $out[$uid]['_sum'] += $pct;
            if ($pct >= 100 || !empty($row->completiontime)) {
                $out[$uid]['completed']++;
            } else if ($pct > 0) {
                $out[$uid]['inprogress']++;
            }
        }
        $records->close();
        foreach ($out as $uid => $data) {
            $n = max(1, (int) $data['enrolled']);
            $out[$uid]['avgprogress'] = round($data['_sum'] / $n, 1);
            unset($out[$uid]['_sum']);
        }
        return $out;
    }

    /**
     * Per-learner time spent in seconds (heartbeat tracking + log-gap fallback).
     *
     * Same split as Course Completion / Overview: measured dwell from
     * nexreports_tracking, plus logstore session-gap estimate when tracking is
     * missing or only covers recent history.
     *
     * @param int[] $userids
     * @param int $courseid
     * @param bool $sitewide If true, entire LMS (all courseids). If false and
     *        courseid=0, courses only (courseid > 1). If false and courseid > 1,
     *        that course only.
     * @return array<int,int> seconds
     */
    private static function timespent_seconds_map(array $userids, int $courseid, bool $sitewide): array {
        global $DB;

        $out = [];
        foreach ($userids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$userids) {
            return $out;
        }

        $tracked = [];
        if ($DB->get_manager()->table_exists('nexreports_tracking')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tu');
            $sql = "SELECT userid, COALESCE(SUM(timespent), 0) AS secs
                      FROM {nexreports_tracking}
                     WHERE userid $insql";
            if ($sitewide) {
                // Entire LMS — Edwiser "Time spent on site" / LMS.
            } else if ($courseid > 1) {
                $sql .= ' AND courseid = :courseid';
                $params['courseid'] = $courseid;
            } else {
                $sql .= ' AND courseid > 1';
            }
            $sql .= ' GROUP BY userid';
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $tracked[(int) $row->userid] = (int) $row->secs;
            }
        }

        $firsttracked = tracking::first_tracked();
        $pregap = ($firsttracked > 0)
            ? self::timespent_loggap_map($userids, $courseid, $sitewide, 0, $firsttracked)
            : [];
        $needfull = false;
        foreach ($userids as $uid) {
            if ((int) ($tracked[(int) $uid] ?? 0) <= 0) {
                $needfull = true;
                break;
            }
        }
        $fullgap = $needfull
            ? self::timespent_loggap_map($userids, $courseid, $sitewide, 0, 0)
            : [];

        foreach ($userids as $uid) {
            $uid = (int) $uid;
            $t = (int) ($tracked[$uid] ?? 0);
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
     * Log-gap time estimate (consecutive logstore events within session gap).
     *
     * @param int[] $userids
     * @param int $courseid
     * @param bool $sitewide Entire LMS when true
     * @param int $fromts Inclusive lower bound (0 = none)
     * @param int $tots Exclusive upper bound (0 = none)
     * @return array<int,int> userid => seconds
     */
    private static function timespent_loggap_map(
        array $userids,
        int $courseid,
        bool $sitewide,
        int $fromts = 0,
        int $tots = 0
    ): array {
        global $DB;

        $out = [];
        foreach ($userids as $uid) {
            $out[(int) $uid] = 0;
        }
        if (!$userids || !overview::logstore_usable()) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $timewhere = '';
        if ($fromts > 0) {
            $timewhere .= ' AND timecreated >= :fromts';
            $params['fromts'] = $fromts;
        }
        if ($tots > 0) {
            $timewhere .= ' AND timecreated < :tots';
            $params['tots'] = $tots;
        }

        // Entire LMS vs one course vs all courses (excluding site home).
        if ($sitewide) {
            $coursewhere = '';
            $order = 'userid ASC, timecreated ASC';
            $samecourse = false;
        } else if ($courseid > 1) {
            $coursewhere = ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
            $order = 'userid ASC, timecreated ASC';
            $samecourse = false;
        } else {
            $coursewhere = ' AND courseid > 1';
            $order = 'userid ASC, courseid ASC, timecreated ASC';
            $samecourse = true;
        }

        $sql = "SELECT userid, courseid, timecreated
                  FROM {logstore_standard_log}
                 WHERE userid $insql
                   $coursewhere
                   $timewhere
              ORDER BY $order";
        $rs = $DB->get_recordset_sql($sql, $params);
        $sessiongap = overview::session_gap();
        $prevuid = 0;
        $prevts = 0;
        $prevcourse = 0;
        foreach ($rs as $row) {
            $uid = (int) $row->userid;
            $ts = (int) $row->timecreated;
            $cid = (int) $row->courseid;
            if ($uid === $prevuid && $prevts > 0) {
                $ok = !$samecourse || ($cid === $prevcourse && $cid > 1);
                if ($ok) {
                    $gap = $ts - $prevts;
                    if ($gap > 0 && $gap <= $sessiongap) {
                        $out[$uid] = ($out[$uid] ?? 0) + $gap;
                    }
                }
            }
            $prevuid = $uid;
            $prevts = $ts;
            $prevcourse = $cid;
        }
        $rs->close();
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $courseid
     * @return array<int,int>
     */
    private static function visits_map(array $userids, int $courseid): array {
        global $DB;
        if (!$userids || !overview::logstore_usable()) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'vu');
        $params['action'] = 'viewed';
        $sql = "SELECT userid, COUNT(*) AS visits
                  FROM {logstore_standard_log}
                 WHERE userid $insql
                   AND action = :action";
        if ($courseid > 1) {
            $sql .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        } else {
            $sql .= ' AND courseid > 1';
        }
        $sql .= ' GROUP BY userid';
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->visits;
        }
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $courseid
     * @return array<int,int>
     */
    private static function activities_completed_map(array $userids, int $courseid): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'au');
        $sql = "SELECT cmc.userid, COUNT(*) AS cnt
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid";
        if ($courseid > 1) {
            $sql .= ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        } else {
            $sql .= ' AND cm.course > 1';
        }
        $sql .= " WHERE cmc.userid $insql AND cmc.completionstate <> 0
               GROUP BY cmc.userid";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * Completed assignments (submitted) or SCORM attempts with a finish/status.
     *
     * @param int[] $userids
     * @param int $courseid
     * @param string $modname assign|scorm
     * @return array<int,int>
     */
    private static function completed_mod_map(array $userids, int $courseid, string $modname): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        $dbman = $DB->get_manager();
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mu');

        if ($modname === 'assign' && $dbman->table_exists('assign_submission')) {
            $sql = "SELECT asub.userid, COUNT(DISTINCT asub.assignment) AS cnt
                      FROM {assign_submission} asub
                      JOIN {assign} a ON a.id = asub.assignment
                     WHERE asub.userid $uinsql
                       AND asub.status = :status
                       AND asub.latest = 1";
            $params = array_merge($uparams, ['status' => 'submitted']);
            if ($courseid > 1) {
                $sql .= ' AND a.course = :courseid';
                $params['courseid'] = $courseid;
            } else {
                $sql .= ' AND a.course > 1';
            }
            $sql .= ' GROUP BY asub.userid';
            $out = [];
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $out[(int) $row->userid] = (int) $row->cnt;
            }
            return $out;
        }

        if ($modname === 'scorm' && $dbman->table_exists('scorm_scoes_track')) {
            $sql = "SELECT t.userid, COUNT(DISTINCT s.id) AS cnt
                      FROM {scorm_scoes_track} t
                      JOIN {scorm} s ON s.id = t.scormid
                     WHERE t.userid $uinsql
                       AND t.element = :element
                       AND (" . $DB->sql_compare_text('t.value') . " = :v1
                            OR " . $DB->sql_compare_text('t.value') . " = :v2
                            OR " . $DB->sql_compare_text('t.value') . " = :v3)";
            $params = array_merge($uparams, [
                'element' => 'cmi.core.lesson_status',
                'v1' => 'completed',
                'v2' => 'passed',
                'v3' => 'failed',
            ]);
            if ($courseid > 1) {
                $sql .= ' AND s.course = :courseid';
                $params['courseid'] = $courseid;
            } else {
                $sql .= ' AND s.course > 1';
            }
            $sql .= ' GROUP BY t.userid';
            $out = [];
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $out[(int) $row->userid] = (int) $row->cnt;
            }
            return $out;
        }

        return [];
    }

    /**
     * Distinct quizzes with a finished attempt.
     *
     * @param int[] $userids
     * @param int $courseid
     * @return array<int,int>
     */
    private static function completed_quiz_map(array $userids, int $courseid): array {
        global $DB;
        if (!$userids || !$DB->get_manager()->table_exists('quiz_attempts')) {
            return [];
        }
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'qu');
        $sql = "SELECT quiza.userid, COUNT(DISTINCT quiza.quiz) AS cnt
                  FROM {quiz_attempts} quiza
                  JOIN {quiz} q ON q.id = quiza.quiz
                 WHERE quiza.userid $uinsql
                   AND quiza.preview = 0
                   AND quiza.state = :finished";
        $params = array_merge($uparams, ['finished' => 'finished']);
        if ($courseid > 1) {
            $sql .= ' AND q.course = :courseid';
            $params['courseid'] = $courseid;
        } else {
            $sql .= ' AND q.course > 1';
        }
        $sql .= ' GROUP BY quiza.userid';
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * Sum of course total grades (raw marks) across enrolled courses.
     *
     * @param int[] $userids
     * @param int $courseid
     * @return array<int,float>
     */
    private static function total_grade_sum_map(array $userids, int $courseid): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $sql = "SELECT gg.userid,
                       COALESCE(SUM(gg.finalgrade), 0) AS totalgrade
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {user_enrolments} ue ON ue.userid = gg.userid AND ue.status = 0
                  JOIN {enrol} e ON e.id = ue.enrolid
                                 AND e.courseid = gi.courseid
                                 AND e.status = 0
                 WHERE gg.userid $uinsql
                   AND gi.itemtype = :itemtype
                   AND gg.finalgrade IS NOT NULL
                   AND gg.hidden = 0
                   AND gi.courseid > 1";
        $params = array_merge($uparams, ['itemtype' => 'course']);
        if ($courseid > 1) {
            $sql .= ' AND gi.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $sql .= ' GROUP BY gg.userid';
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = round((float) $row->totalgrade, 2);
        }
        return $out;
    }

    /**
     * CodeRunner solved + available question totals per learner.
     *
     * Uses the same enrolment source as Learner Course Progress / enrolled-course
     * count (active user_enrolments on visible courses) — not learner_ids, which
     * also requires moodle/course:isincompletionreports and can differ between
     * students with the same enrolments.
     *
     * When $courseid > 1, totals are for that course (enrolled users only).
     * Otherwise solved/total are summed across each learner's enrolled visible courses.
     *
     * @param int[] $userids
     * @param int $courseid
     * @return array<int, array{solved:int,total:int}>
     */
    private static function coding_totals_map(array $userids, int $courseid): array {
        global $DB;

        $out = [];
        foreach ($userids as $uid) {
            $out[(int) $uid] = ['solved' => 0, 'total' => 0];
        }
        if (!$userids) {
            return $out;
        }

        $visible = [];
        if ($courseid > 1) {
            $visible[$courseid] = true;
        } else {
            foreach (filters::courses(500) as $course) {
                $visible[(int) $course['id']] = true;
            }
        }
        if (!$visible) {
            return $out;
        }

        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cu');
        [$cinsql, $cparams] = $DB->get_in_or_equal(array_keys($visible), SQL_PARAMS_NAMED, 'cc');
        $sql = "SELECT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid $uinsql
                   AND ue.status = 0
                   AND e.courseid $cinsql
                   AND e.courseid > 1";
        $bycourse = [];
        foreach ($DB->get_recordset_sql($sql, array_merge($uparams, $cparams)) as $row) {
            $cid = (int) $row->courseid;
            $uid = (int) $row->userid;
            $bycourse[$cid][$uid] = true;
        }

        foreach ($bycourse as $cid => $uidset) {
            $enrolled = array_map('intval', array_keys($uidset));
            if (!$enrolled) {
                continue;
            }
            $stats = courses_report::coding_stats_for_learners($cid, $enrolled);
            $ctotal = (int) ($stats['total'] ?? 0);
            $byuser = $stats['byuser'] ?? [];
            if ($ctotal <= 0 && !$byuser) {
                continue;
            }
            foreach ($enrolled as $uid) {
                $out[$uid]['solved'] += (int) ($byuser[$uid] ?? 0);
                $out[$uid]['total'] += $ctotal;
            }
        }
        return $out;
    }

    /**
     * Learner Course Progress: one learner's enrolled courses + summary KPIs.
     *
     * @param int $userid 0 = first available learner in the current year/department filter
     * @param string $search Course name search
     * @param string $year Year of passing
     * @param string $department Department
     * @param string $learnersearch Learner picker search (firstname/lastname)
     * @param bool $metaonly When true, only return filter options (no course rows)
     * @return array
     */
    public static function course_progress(
        int $userid = 0,
        string $search = '',
        string $year = '',
        string $department = '',
        string $learnersearch = '',
        bool $metaonly = false,
        string $institution = ''
    ): array {
        global $DB;

        $search = trim($search);
        $learnersearch = trim($learnersearch);
        $cascade = self::college_year_department_options(0, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];
        $years = $cascade['years'];
        $departments = $cascade['departments'];
        $colleges = $cascade['colleges'];
        $showcollege = $cascade['showcollege'];
        $showdepartment = $cascade['showdepartment'] ?? 1;
        // Few names in the picker; search widens the match set slightly.
        $learnerlimit = $learnersearch !== '' ? 30 : 15;
        $learners = ($year !== '')
            ? self::learner_options($year, $department, $learnersearch, $learnerlimit, $institution)
            : [];

        if ($userid <= 0 && $learners && !$metaonly) {
            $userid = (int) $learners[0]['id'];
        }

        $empty = [
            'generated' => time(),
            'rows' => [],
            'learners' => $learners,
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'summary' => self::empty_course_progress_summary(),
            'selecteduserid' => 0,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'learnersearch' => $learnersearch,
        ];

        if ($metaonly || $userid <= 0) {
            if ($metaonly) {
                $empty['selecteduserid'] = $userid;
            }
            return $empty;
        }

        access::require_user_in_scope($userid);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            return $empty;
        }

        // Keep selected learner in the picker even when outside the current filter page.
        $displayname = self::learner_display_name($user);
        $found = false;
        foreach ($learners as $opt) {
            if ((int) $opt['id'] === $userid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            array_unshift($learners, ['id' => (string) $userid, 'name' => $displayname]);
        }

        $courseids = self::enrolled_course_ids($userid);
        if (!$courseids) {
            $summary = self::empty_course_progress_summary();
            $summary['fullname'] = $displayname;
            $summary['url'] = (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
            $summary['status'] = empty($user->suspended)
                ? get_string('active', 'local_nexreports')
                : get_string('inactive', 'local_nexreports');
            $summary['statusactive'] = empty($user->suspended) ? 1 : 0;
            $last = (int) ($user->lastaccess ?? 0);
            $summary['lastaccess'] = $last
                ? userdate($last, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('never');
            $summary['lastaccesstime'] = $last;
            $sitesecs = (int) (self::timespent_seconds_map([$userid], 0, true)[$userid] ?? 0);
            $summary['timespentonsite'] = $sitesecs;
            return [
                'generated' => time(),
                'rows' => [],
                'learners' => $learners,
                'years' => $years,
                'departments' => $departments,
                'summary' => $summary,
                'selecteduserid' => $userid,
                'selectedyear' => $year,
                'selecteddepartment' => $department,
                'search' => $search,
                'learnersearch' => $learnersearch,
            ];
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $courses = $DB->get_records_select('course', "id $cinsql", $cparams, 'fullname ASC', 'id, fullname, shortname');

        $progress = $DB->get_records_sql(
            "SELECT courseid, progress, completiontime, totalmodules, completablemods, completedmodules
               FROM {nexreports_course_progress}
              WHERE userid = :userid AND courseid $cinsql",
            array_merge(['userid' => $userid], $cparams)
        );

        $enrols = $DB->get_records_sql(
            "SELECT e.courseid, MIN(ue.timecreated) AS enrolledon
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid $cinsql
           GROUP BY e.courseid",
            array_merge(['userid' => $userid], $cparams)
        );

        $visits = [];
        $lastaccessbycourse = [];
        if (overview::logstore_usable()) {
            $vrows = $DB->get_records_sql(
                "SELECT courseid, COUNT(*) AS visits, MAX(timecreated) AS lastaccess
                   FROM {logstore_standard_log}
                  WHERE userid = :userid
                    AND action = :action
                    AND courseid $cinsql
               GROUP BY courseid",
                array_merge(['userid' => $userid, 'action' => 'viewed'], $cparams)
            );
            foreach ($vrows as $row) {
                $cid = (int) $row->courseid;
                $visits[$cid] = (int) $row->visits;
                $lastaccessbycourse[$cid] = (int) $row->lastaccess;
            }
        }

        $attempted = self::attempted_activities_by_course($userid, $courseids);
        // Include COMPLETE_FAIL — Edwiser-style "completed" counts pass and fail finishes.
        $completedmap = self::completed_activities_by_course($userid, $courseids, true);
        $grades = self::course_grades_for_user($userid, $courseids);
        $codingbycourse = [];
        foreach ($courseids as $cid) {
            $coding = courses_report::coding_stats_for_learners($cid, [$userid]);
            $codingbycourse[$cid] = [
                'solved' => (int) ($coding['byuser'][$userid] ?? 0),
                'total' => (int) ($coding['total'] ?? 0),
            ];
        }
        $timemap = [];
        foreach ($courseids as $cid) {
            $timemap[$cid] = (int) (self::timespent_seconds_map([$userid], $cid, false)[$userid] ?? 0);
        }

        $sitesecs = (int) (self::timespent_seconds_map([$userid], 0, true)[$userid] ?? 0);
        $sumcoursetime = (int) array_sum($timemap);
        $sumvisits = (int) array_sum($visits);
        $sumprogress = 0.0;
        $summarks = 0.0;
        $sumgradepct = 0.0;
        $gradepctcount = 0;
        $coursecount = count($courseids);
        $searchl = \core_text::strtolower($search);

        $rows = [];
        foreach ($courseids as $cid) {
            $course = $courses[$cid] ?? null;
            if (!$course) {
                continue;
            }
            $coursename = format_string($course->fullname);
            if ($searchl !== '' && \core_text::strpos(\core_text::strtolower($coursename), $searchl) === false) {
                continue;
            }

            $p = $progress[$cid] ?? null;
            $totalmods = (int) ($p->completablemods ?? 0);
            if ($totalmods <= 0) {
                $totalmods = self::completable_module_count($cid);
            }
            $completedmods = (int) ($completedmap[$cid] ?? 0);
            if ($completedmods <= 0) {
                // Fallback to cache (pass/complete only) when CMC has no rows yet.
                $completedmods = (int) ($p->totalmodules ?? 0);
            }
            if ($completedmods > $totalmods && $totalmods > 0) {
                $completedmods = $totalmods;
            }
            $pct = $totalmods > 0
                ? round(($completedmods / $totalmods) * 100, 2)
                : round((float) ($p->progress ?? 0), 2);
            $attemptedmods = (int) ($attempted[$cid] ?? 0);
            if ($attemptedmods < $completedmods) {
                $attemptedmods = $completedmods;
            }
            if ($totalmods > 0 && $attemptedmods > $totalmods) {
                $attemptedmods = $totalmods;
            }

            $completedontime = !empty($p->completiontime) ? (int) $p->completiontime : 0;
            $completed = $completedontime > 0 || $pct >= 100;
            if ($completed) {
                $status = get_string('statuscompleted', 'local_nexreports');
                $statuskey = 'completed';
            } else if ($pct <= 0 && $completedmods <= 0) {
                $status = get_string('statusnotyetstarted', 'local_nexreports');
                $statuskey = 'notyetstarted';
            } else {
                $status = get_string('statusinprogress', 'local_nexreports');
                $statuskey = 'inprogress';
            }

            $enrolledontime = !empty($enrols[$cid]->enrolledon) ? (int) $enrols[$cid]->enrolledon : 0;
            $lastaccesstime = (int) ($lastaccessbycourse[$cid] ?? 0);
            $coursevisits = (int) ($visits[$cid] ?? 0);
            $timesecs = (int) ($timemap[$cid] ?? 0);
            $g = $grades[$cid] ?? ['grade' => 0.0, 'grademax' => 0.0, 'gradepercent' => 0.0];
            $coding = $codingbycourse[$cid] ?? ['solved' => 0, 'total' => 0];

            $sumprogress += $pct;
            $summarks += (float) $g['grade'];
            if ((float) $g['grademax'] > 0) {
                $sumgradepct += (float) $g['gradepercent'];
                $gradepctcount++;
            }

            $rows[] = [
                'courseid' => $cid,
                'coursename' => $coursename,
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $cid]))->out(false),
                'status' => $status,
                'statuskey' => $statuskey,
                'enrolledon' => $enrolledontime
                    ? userdate($enrolledontime, get_string('strftimedate', 'langconfig'))
                    : '—',
                'enrolledontime' => $enrolledontime,
                'completedon' => $completedontime
                    ? userdate($completedontime, get_string('strftimedate', 'langconfig'))
                    : '—',
                'completedontime' => $completedontime,
                'lastaccess' => $lastaccesstime
                    ? userdate($lastaccesstime, get_string('strftimedatetimeshort', 'langconfig'))
                    : '—',
                'lastaccesstime' => $lastaccesstime,
                'progress' => $pct,
                'grade' => round((float) $g['grade'], 2),
                'totalactivities' => $totalmods,
                'completedactivities' => $completedmods,
                'attemptedactivities' => $attemptedmods,
                'codingsolved' => (int) $coding['solved'],
                'codingtotal' => (int) $coding['total'],
                'visits' => $coursevisits,
                'timespent' => $timesecs,
            ];
        }

        $userlast = (int) ($user->lastaccess ?? 0);
        $maxcourselast = 0;
        foreach ($lastaccessbycourse as $ts) {
            $maxcourselast = max($maxcourselast, (int) $ts);
        }
        $summarylast = max($userlast, $maxcourselast);

        $summary = [
            'fullname' => $displayname,
            'url' => (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false),
            'status' => empty($user->suspended)
                ? get_string('active', 'local_nexreports')
                : get_string('inactive', 'local_nexreports'),
            'statusactive' => empty($user->suspended) ? 1 : 0,
            'lastaccess' => $summarylast
                ? userdate($summarylast, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('never'),
            'lastaccesstime' => $summarylast,
            'visitsoncourse' => $sumvisits,
            'timespentoncourse' => $sumcoursetime,
            'timespentonsite' => $sitesecs,
            'enrolledcourses' => $coursecount,
            'completionprogress' => $coursecount ? round($sumprogress / $coursecount, 2) : 0.0,
            'totalmarks' => round($summarks, 2),
            'totalgrade' => $gradepctcount ? round($sumgradepct / $gradepctcount, 2) : 0.0,
        ];

        return [
            'generated' => time(),
            'rows' => $rows,
            'learners' => $learners,
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'summary' => $summary,
            'selecteduserid' => $userid,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'search' => $search,
            'learnersearch' => $learnersearch,
        ];
    }

    /**
     * @return array
     */
    private static function empty_course_progress_summary(): array {
        return [
            'fullname' => '',
            'url' => '',
            'status' => '',
            'statusactive' => 0,
            'lastaccess' => '',
            'lastaccesstime' => 0,
            'visitsoncourse' => 0,
            'timespentoncourse' => 0,
            'timespentonsite' => 0,
            'enrolledcourses' => 0,
            'completionprogress' => 0.0,
            'totalmarks' => 0.0,
            'totalgrade' => 0.0,
        ];
    }

    /**
     * @param \stdClass $user
     * @return string First name + last name only
     */
    private static function learner_display_name($user): string {
        $first = trim((string) ($user->firstname ?? ''));
        $last = trim((string) ($user->lastname ?? ''));
        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : fullname($user);
    }

    /**
     * @param string $year
     * @param string $department
     * @param string $query Name search within the year/department set
     * @param int $limit
     * @return array<int, array{id:string,name:string}>
     */
    private static function learner_options(
        string $year,
        string $department,
        string $query = '',
        int $limit = 15,
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(50, $limit));
        $year = trim($year);
        $query = trim($query);
        if ($year === '') {
            return [];
        }
        $ids = self::all_learner_ids(0);
        $profileids = profile_filters::userids(0, $year, trim($department), trim($institution));
        if ($profileids !== null) {
            $ids = array_values(array_intersect($ids, $profileids));
        }
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'lu');
        $where = "id $insql AND deleted = 0";
        if ($query !== '') {
            $namelike = $DB->sql_like($DB->sql_concat('firstname', "' '", 'lastname'), ':qname', false, false);
            $firstlike = $DB->sql_like('firstname', ':qfirst', false, false);
            $lastlike = $DB->sql_like('lastname', ':qlast', false, false);
            $where .= " AND ($namelike OR $firstlike OR $lastlike)";
            $escaped = '%' . $DB->sql_like_escape($query) . '%';
            $params['qname'] = $escaped;
            $params['qfirst'] = $escaped;
            $params['qlast'] = $escaped;
        }
        $users = $DB->get_records_select(
            'user',
            $where,
            $params,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname',
            0,
            $limit
        );
        $out = [];
        foreach ($users as $user) {
            $out[] = [
                'id' => (string) ((int) $user->id),
                'name' => self::learner_display_name($user),
            ];
        }
        return $out;
    }

    /**
     * @param int $userid
     * @return int[]
     */
    private static function enrolled_course_ids(int $userid): array {
        global $DB;
        $visible = [];
        foreach (filters::courses(500) as $course) {
            $visible[(int) $course['id']] = true;
        }
        if (!$visible) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($visible), SQL_PARAMS_NAMED, 'ec');
        $params['userid'] = $userid;
        $sql = "SELECT DISTINCT e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND ue.status = 0
                   AND e.courseid $insql
                   AND e.courseid > 1";
        return array_map('intval', array_keys($DB->get_records_sql($sql, $params)));
    }

    /**
     * @param int $courseid
     * @return int
     */
    private static function completable_module_count(int $courseid): int {
        global $DB;
        return (int) $DB->count_records_select(
            'course_modules',
            'course = :courseid AND deletioninprogress = 0 AND completion > 0',
            ['courseid' => $courseid]
        );
    }

    /**
     * Completed activities per course (optionally including COMPLETE_FAIL).
     *
     * @param int $userid
     * @param int[] $courseids
     * @param bool $includefail Count COMPLETION_COMPLETE_FAIL as completed
     * @return array<int,int> courseid => count
     */
    private static function completed_activities_by_course(
        int $userid,
        array $courseids,
        bool $includefail = true
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $out = [];
        foreach ($courseids as $cid) {
            $out[(int) $cid] = 0;
        }
        if (!$courseids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cc');
        $params['userid'] = $userid;
        $params['st_complete'] = COMPLETION_COMPLETE;
        $params['st_pass'] = COMPLETION_COMPLETE_PASS;
        $statesql = 'cmc.completionstate IN (:st_complete, :st_pass)';
        if ($includefail) {
            $params['st_fail'] = COMPLETION_COMPLETE_FAIL;
            $statesql = 'cmc.completionstate IN (:st_complete, :st_pass, :st_fail)';
        }
        $rows = $DB->get_records_sql(
            "SELECT cm.course AS courseid, COUNT(DISTINCT cmc.coursemoduleid) AS cnt
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid = :userid
                AND cm.course $insql
                AND cm.deletioninprogress = 0
                AND cm.completion > 0
                AND $statesql
           GROUP BY cm.course",
            $params
        );
        foreach ($rows as $row) {
            $out[(int) $row->courseid] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * Activities the learner has a completion record for (any state) or has viewed.
     *
     * @param int $userid
     * @param int[] $courseids
     * @return array<int,int> courseid => count
     */
    private static function attempted_activities_by_course(int $userid, array $courseids): array {
        global $DB;
        $out = [];
        foreach ($courseids as $cid) {
            $out[(int) $cid] = 0;
        }
        if (!$courseids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ac');
        $params['userid'] = $userid;
        $rows = $DB->get_records_sql(
            "SELECT cm.course AS courseid, COUNT(DISTINCT cmc.coursemoduleid) AS cnt
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid = :userid
                AND cm.course $insql
                AND cm.deletioninprogress = 0
                AND cm.completion > 0
           GROUP BY cm.course",
            $params
        );
        foreach ($rows as $row) {
            $out[(int) $row->courseid] = (int) $row->cnt;
        }

        // Supplement with views of completion-tracked modules when CMC rows are sparse.
        if (overview::logstore_usable()) {
            $vrows = $DB->get_records_sql(
                "SELECT cm.course AS courseid, COUNT(DISTINCT cm.id) AS cnt
                   FROM {logstore_standard_log} l
                   JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                  WHERE l.userid = :userid
                    AND l.action = :action
                    AND l.contextlevel = :ctx
                    AND cm.course $insql
                    AND cm.deletioninprogress = 0
                    AND cm.completion > 0
               GROUP BY cm.course",
                array_merge($params, [
                    'action' => 'viewed',
                    'ctx' => CONTEXT_MODULE,
                ])
            );
            foreach ($vrows as $row) {
                $cid = (int) $row->courseid;
                $out[$cid] = max($out[$cid] ?? 0, (int) $row->cnt);
            }
        }
        return $out;
    }

    /**
     * Course-total gradebook values for one user.
     *
     * @param int $userid
     * @param int[] $courseids
     * @return array<int, array{grade:float,grademax:float,gradepercent:float}>
     */
    private static function course_grades_for_user(int $userid, array $courseids): array {
        global $DB;
        $out = [];
        foreach ($courseids as $cid) {
            $out[(int) $cid] = ['grade' => 0.0, 'grademax' => 0.0, 'gradepercent' => 0.0];
        }
        if (!$courseids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gc');
        $params['userid'] = $userid;
        $params['itemtype'] = 'course';
        $rows = $DB->get_records_sql(
            "SELECT gi.courseid, gg.finalgrade, gi.grademax
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
              WHERE gg.userid = :userid
                AND gi.itemtype = :itemtype
                AND gi.courseid $insql
                AND gg.finalgrade IS NOT NULL",
            $params
        );
        foreach ($rows as $row) {
            $cid = (int) $row->courseid;
            $grade = (float) $row->finalgrade;
            $max = (float) $row->grademax;
            $out[$cid] = [
                'grade' => $grade,
                'grademax' => $max,
                'gradepercent' => $max > 0 ? round(($grade / $max) * 100, 2) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Learner Course Activities: one learner's activities in one course.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $sectionnum -1 = all sections
     * @param string $search Activity name search
     * @param string $activitytype Mod name or '' / 'all'
     * @param string $completionstatus all|completed|inprogress|notyetstarted|passed|failed
     * @param string $learnersearch
     * @param bool $metaonly
     * @param string $year Year of passing
     * @param string $department Department
     * @return array
     */
    public static function course_activities(
        int $courseid = 0,
        int $userid = 0,
        int $sectionnum = -1,
        string $search = '',
        string $activitytype = '',
        string $completionstatus = 'all',
        string $learnersearch = '',
        bool $metaonly = false,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $courseid = courses_report::resolve_courseid($courseid);
        $courses = courses_report::course_options();
        $search = trim($search);
        $activitytype = trim(\core_text::strtolower($activitytype));
        if ($activitytype === 'all') {
            $activitytype = '';
        }
        $completionstatus = trim(\core_text::strtolower($completionstatus));
        if ($completionstatus === '') {
            $completionstatus = 'all';
        }
        $learnersearch = trim($learnersearch);
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
            $years = [];
            $departments = [];
            $colleges = [];
        }
        $learners = ($courseid > 1 && $year !== '')
            ? self::course_learner_options(
                $courseid,
                $year,
                $department,
                $learnersearch,
                $learnersearch !== '' ? 30 : 15,
                $institution
            )
            : [];
        $sections = ($courseid > 1) ? self::course_section_options($courseid) : [];

        if ($userid <= 0 && $learners && !$metaonly) {
            $userid = (int) $learners[0]['id'];
        }

        $empty = [
            'generated' => time(),
            'rows' => [],
            'courses' => $courses,
            'learners' => $learners,
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'sections' => $sections,
            'activitytypes' => self::activity_type_filter_options($courseid),
            'summary' => self::empty_course_activities_summary(),
            'selectedcourseid' => $courseid > 1 ? $courseid : 0,
            'selecteduserid' => 0,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedsection' => $sectionnum,
            'selectedactivitytype' => $activitytype,
            'selectedcompletionstatus' => $completionstatus,
            'search' => $search,
            'learnersearch' => $learnersearch,
        ];

        if ($metaonly || $courseid <= 1 || $userid <= 0 || $year === '') {
            if ($metaonly) {
                $empty['selecteduserid'] = $userid;
            }
            return $empty;
        }

        access::require_user_in_scope($userid);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            return $empty;
        }

        $displayname = self::learner_display_name($user);
        $found = false;
        foreach ($learners as $opt) {
            if ((int) $opt['id'] === $userid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            array_unshift($learners, ['id' => (string) $userid, 'name' => $displayname]);
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', MUST_EXIST);
        $coursename = format_string($course->fullname);
        $modinfo = get_fast_modinfo($courseid);
        $cms = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || empty($cm->uservisible)) {
                // Still include hidden-from-students activities for reports if teacher-visible.
            }
            if ($cm->deletioninprogress) {
                continue;
            }
            if ($sectionnum >= 0 && (int) $cm->sectionnum !== $sectionnum) {
                continue;
            }
            if ($activitytype !== '' && $cm->modname !== $activitytype) {
                continue;
            }
            $cms[] = $cm;
        }

        $cmids = array_map(static fn($cm) => (int) $cm->id, $cms);
        $cmc = [];
        $visits = [];
        $grades = [];
        $gradeitems = [];
        $quizmeta = [];
        $quizattempts = [];

        if ($cmids) {
            [$cinsql, $cparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
            $cparams['userid'] = $userid;
            foreach ($DB->get_records_sql(
                "SELECT coursemoduleid, completionstate, timemodified
                   FROM {course_modules_completion}
                  WHERE userid = :userid AND coursemoduleid $cinsql",
                $cparams
            ) as $row) {
                $cmc[(int) $row->coursemoduleid] = $row;
            }

            if (overview::logstore_usable()) {
                foreach ($DB->get_records_sql(
                    "SELECT contextinstanceid AS cmid, COUNT(*) AS visits,
                            MIN(timecreated) AS firstaccess, MAX(timecreated) AS lastaccess
                       FROM {logstore_standard_log}
                      WHERE userid = :userid
                        AND courseid = :courseid
                        AND action = :action
                        AND target = :target
                        AND contextinstanceid $cinsql
                   GROUP BY contextinstanceid",
                    array_merge($cparams, [
                        'courseid' => $courseid,
                        'action' => 'viewed',
                        'target' => 'course_module',
                    ])
                ) as $row) {
                    $visits[(int) $row->cmid] = $row;
                }
            }

            foreach ($DB->get_records_sql(
                "SELECT gi.id, gi.itemmodule, gi.iteminstance, gi.grademax, gi.gradepass,
                        gg.finalgrade, gg.timemodified
                   FROM {grade_items} gi
              LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                  WHERE gi.courseid = :courseid AND gi.itemtype = :itemtype",
                ['userid' => $userid, 'courseid' => $courseid, 'itemtype' => 'mod']
            ) as $gi) {
                $key = $gi->itemmodule . ':' . $gi->iteminstance;
                $gradeitems[$key] = $gi;
            }

            $quizcms = array_filter($cms, static fn($cm) => $cm->modname === 'quiz');
            if ($quizcms) {
                $quizids = array_map(static fn($cm) => (int) $cm->instance, $quizcms);
                [$qinsql, $qparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qz');
                foreach ($DB->get_records_select('quiz', "id $qinsql", $qparams, '', 'id, timeclose, sumgrades, grade') as $quiz) {
                    $quizmeta[(int) $quiz->id] = $quiz;
                }
                $qparams['userid'] = $userid;
                foreach ($DB->get_records_sql(
                    "SELECT quiz AS quizid,
                            COUNT(*) AS attempts,
                            MAX(sumgrades) AS bestsum,
                            MIN(NULLIF(sumgrades, 0)) AS worstsum,
                            MAX(CASE WHEN state = 'finished' THEN 1 ELSE 0 END) AS hasfinished,
                            MAX(CASE WHEN state = 'abandoned' THEN 1 ELSE 0 END) AS hasabandoned,
                            MAX(CASE WHEN state = 'overdue' THEN 1 ELSE 0 END) AS hasoverdue,
                            MAX(CASE WHEN state = 'inprogress' THEN 1 ELSE 0 END) AS hasinprogress,
                            MIN(NULLIF(timestart, 0)) AS firstattempt,
                            MAX(NULLIF(timefinish, 0)) AS lastfinish
                       FROM {quiz_attempts}
                      WHERE preview = 0 AND userid = :userid AND quiz $qinsql
                   GROUP BY quiz",
                    $qparams
                ) as $row) {
                    $quizattempts[(int) $row->quizid] = $row;
                }
            }
        }

        $enrolledontime = (int) $DB->get_field_sql(
            "SELECT MIN(ue.timecreated)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );
        $coursevisits = 0;
        $courselast = 0;
        if (overview::logstore_usable()) {
            $cv = $DB->get_record_sql(
                "SELECT COUNT(*) AS visits, MAX(timecreated) AS lastaccess
                   FROM {logstore_standard_log}
                  WHERE userid = :userid AND courseid = :courseid AND action = :action",
                ['userid' => $userid, 'courseid' => $courseid, 'action' => 'viewed']
            );
            $coursevisits = (int) ($cv->visits ?? 0);
            $courselast = (int) ($cv->lastaccess ?? 0);
        }
        $coursetime = (int) (self::timespent_seconds_map([$userid], $courseid, false)[$userid] ?? 0);
        $coursegrade = self::course_grades_for_user($userid, [$courseid])[$courseid]
            ?? ['grade' => 0.0, 'grademax' => 0.0, 'gradepercent' => 0.0];

        $searchl = \core_text::strtolower($search);
        $rows = [];
        foreach ($cms as $cm) {
            $cmid = (int) $cm->id;
            $activityname = format_string($cm->name);
            if ($searchl !== '' && \core_text::strpos(\core_text::strtolower($activityname), $searchl) === false) {
                continue;
            }
            $type = courses_report::activity_type_label($cm->modname);
            $comp = $cmc[$cmid] ?? null;
            $state = $comp ? (int) $comp->completionstate : COMPLETION_INCOMPLETE;
            $completedontime = $comp && (int) $comp->timemodified > 0 ? (int) $comp->timemodified : 0;

            $visit = $visits[$cmid] ?? null;
            $firstaccesstime = $visit ? (int) $visit->firstaccess : 0;
            $lastaccesstime = $visit ? (int) $visit->lastaccess : 0;
            $activityvisits = $visit ? (int) $visit->visits : 0;
            $timesecs = (int) (courses_report::activity_learner_timespent_map(
                $courseid,
                $cmid,
                [$userid]
            )[$userid] ?? 0);

            $gikey = $cm->modname . ':' . $cm->instance;
            $gi = $gradeitems[$gikey] ?? null;
            $grademax = $gi ? round((float) $gi->grademax, 2) : 0.0;
            $gradepass = $gi ? round((float) $gi->gradepass, 2) : 0.0;
            $finalgrade = ($gi && $gi->finalgrade !== null && $gi->finalgrade !== '')
                ? round((float) $gi->finalgrade, 2) : null;
            $gradedontime = ($gi && $finalgrade !== null && !empty($gi->timemodified))
                ? (int) $gi->timemodified : 0;

            $attempts = 0;
            $highest = null;
            $lowest = null;
            $firstattempttime = 0;
            $hasfinished = false;
            $hasinprogress = false;
            $isquiz = $cm->modname === 'quiz';
            if ($isquiz) {
                $qmeta = $quizmeta[(int) $cm->instance] ?? null;
                $qatt = $quizattempts[(int) $cm->instance] ?? null;
                if ($qmeta && $grademax <= 0 && (float) $qmeta->grade > 0) {
                    $grademax = round((float) $qmeta->grade, 2);
                }
                if ($qatt) {
                    $attempts = (int) $qatt->attempts;
                    $quizsum = (float) ($qmeta->sumgrades ?? 0);
                    $quizgrade = (float) ($qmeta->grade ?? 0);
                    if ($qatt->bestsum !== null && $quizsum > 0 && $quizgrade > 0) {
                        $highest = round(((float) $qatt->bestsum / $quizsum) * $quizgrade, 2);
                    }
                    if ($qatt->worstsum !== null && $quizsum > 0 && $quizgrade > 0) {
                        $lowest = round(((float) $qatt->worstsum / $quizsum) * $quizgrade, 2);
                    }
                    $firstattempttime = (int) ($qatt->firstattempt ?? 0);
                    $quizclosed = !empty($qmeta->timeclose) && (int) $qmeta->timeclose < time();
                    $hasfinished = !empty($qatt->hasfinished) || !empty($qatt->hasabandoned)
                        || !empty($qatt->hasoverdue) || $quizclosed;
                    $hasinprogress = !empty($qatt->hasinprogress) && !$hasfinished;
                    if ($finalgrade === null && $hasfinished && $highest !== null) {
                        $finalgrade = $highest;
                    }
                    if ($gradedontime <= 0 && !empty($qatt->lastfinish) && $finalgrade !== null) {
                        $gradedontime = (int) $qatt->lastfinish;
                    }
                }
            }

            // Status (include fail as completed/failed).
            $statuskey = 'notyetstarted';
            $status = get_string('statusnotyetstarted', 'local_nexreports');
            if ($state === COMPLETION_COMPLETE_PASS) {
                $statuskey = 'passed';
                $status = get_string('statuscompleted', 'local_nexreports');
            } else if ($state === COMPLETION_COMPLETE_FAIL) {
                $statuskey = 'failed';
                $status = get_string('statusfailed', 'local_nexreports');
            } else if ($state === COMPLETION_COMPLETE) {
                $statuskey = 'completed';
                $status = get_string('statuscompleted', 'local_nexreports');
            } else if ($finalgrade !== null && $gradepass > 0) {
                if ($finalgrade >= $gradepass) {
                    $statuskey = 'passed';
                    $status = get_string('statuscompleted', 'local_nexreports');
                } else {
                    $statuskey = 'failed';
                    $status = get_string('statusfailed', 'local_nexreports');
                }
            } else if ($hasfinished || ($finalgrade !== null && $isquiz)) {
                $statuskey = 'completed';
                $status = get_string('statuscompleted', 'local_nexreports');
            } else if ($finalgrade !== null) {
                $statuskey = 'completed';
                $status = get_string('statuscompleted', 'local_nexreports');
            } else if ($hasinprogress || (!$isquiz && $activityvisits > 0)) {
                $statuskey = 'inprogress';
                $status = get_string('statusinprogress', 'local_nexreports');
            }

            if ($completionstatus !== 'all') {
                $ok = match ($completionstatus) {
                    'completed' => in_array($statuskey, ['completed', 'passed', 'failed'], true),
                    'inprogress' => $statuskey === 'inprogress',
                    'notyetstarted' => $statuskey === 'notyetstarted',
                    'passed' => $statuskey === 'passed',
                    'failed' => $statuskey === 'failed',
                    default => true,
                };
                if (!$ok) {
                    continue;
                }
            }

            $rows[] = [
                'cmid' => $cmid,
                'activity' => $activityname,
                'type' => $type,
                'modname' => $cm->modname,
                'status' => $status,
                'statuskey' => $statuskey,
                'completedon' => $completedontime
                    ? userdate($completedontime, get_string('strftimedate', 'langconfig')) : '—',
                'completedontime' => $completedontime,
                'grade' => $finalgrade !== null ? (string) $finalgrade : '—',
                'gradevalue' => $finalgrade !== null ? (float) $finalgrade : -1.0,
                'gradedon' => $gradedontime
                    ? userdate($gradedontime, get_string('strftimedate', 'langconfig')) : '—',
                'gradedontime' => $gradedontime,
                'attempts' => $attempts,
                'highestgrade' => $highest !== null ? (string) $highest : '—',
                'highestgradevalue' => $highest !== null ? (float) $highest : -1.0,
                'lowestgrade' => $lowest !== null ? (string) $lowest : '—',
                'lowestgradevalue' => $lowest !== null ? (float) $lowest : -1.0,
                'firstaccess' => $firstaccesstime
                    ? userdate($firstaccesstime, get_string('strftimedatetimeshort', 'langconfig')) : '—',
                'firstaccesstime' => $firstaccesstime,
                'lastaccess' => $lastaccesstime
                    ? userdate($lastaccesstime, get_string('strftimedatetimeshort', 'langconfig')) : '—',
                'lastaccesstime' => $lastaccesstime,
                'visits' => $activityvisits,
                'timespent' => $timesecs,
            ];
        }

        $summarylast = max($courselast, (int) ($user->lastaccess ?? 0));
        $summary = [
            'coursename' => $coursename,
            'fullname' => $displayname,
            'url' => (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false),
            'status' => empty($user->suspended)
                ? get_string('active', 'local_nexreports')
                : get_string('inactive', 'local_nexreports'),
            'statusactive' => empty($user->suspended) ? 1 : 0,
            'lastaccess' => $summarylast
                ? userdate($summarylast, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('never'),
            'lastaccesstime' => $summarylast,
            'visitsoncourse' => $coursevisits,
            'enrolledon' => $enrolledontime
                ? userdate($enrolledontime, get_string('strftimedate', 'langconfig')) : '—',
            'enrolledontime' => $enrolledontime,
            'timespent' => $coursetime,
            'marks' => round((float) $coursegrade['grade'], 2),
            'gradepercent' => round((float) $coursegrade['gradepercent'], 2),
        ];

        return [
            'generated' => time(),
            'rows' => $rows,
            'courses' => $courses,
            'learners' => $learners,
            'years' => $years,
            'departments' => $departments,
            'colleges' => $colleges,
            'sections' => $sections,
            'activitytypes' => self::activity_type_filter_options($courseid),
            'summary' => $summary,
            'selectedcourseid' => $courseid,
            'selecteduserid' => $userid,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'selectedinstitution' => $institution,
            'showcollege' => $showcollege,
            'showdepartment' => $showdepartment,
            'selectedsection' => $sectionnum,
            'selectedactivitytype' => $activitytype,
            'selectedcompletionstatus' => $completionstatus,
            'search' => $search,
            'learnersearch' => $learnersearch,
        ];
    }

    /**
     * @return array
     */
    private static function empty_course_activities_summary(): array {
        return [
            'coursename' => '',
            'fullname' => '',
            'url' => '',
            'status' => '',
            'statusactive' => 0,
            'lastaccess' => '',
            'lastaccesstime' => 0,
            'visitsoncourse' => 0,
            'enrolledon' => '',
            'enrolledontime' => 0,
            'timespent' => 0,
            'marks' => 0.0,
            'gradepercent' => 0.0,
        ];
    }

    /**
     * @param int $courseid
     * @param string $year
     * @param string $department
     * @param string $query
     * @param int $limit
     * @return array<int, array{id:string,name:string}>
     */
    private static function course_learner_options(
        int $courseid,
        string $year,
        string $department,
        string $query,
        int $limit = 15,
        string $institution = ''
    ): array {
        global $DB;
        $limit = max(1, min(50, $limit));
        $year = trim($year);
        if ($courseid <= 1 || $year === '') {
            return [];
        }
        $ids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, trim($department), trim($institution));
        if ($profileids !== null) {
            $ids = array_values(array_intersect($ids, $profileids));
        }
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'clu');
        $where = "id $insql AND deleted = 0";
        $query = trim($query);
        if ($query !== '') {
            $namelike = $DB->sql_like($DB->sql_concat('firstname', "' '", 'lastname'), ':qname', false, false);
            $firstlike = $DB->sql_like('firstname', ':qfirst', false, false);
            $lastlike = $DB->sql_like('lastname', ':qlast', false, false);
            $where .= " AND ($namelike OR $firstlike OR $lastlike)";
            $escaped = '%' . $DB->sql_like_escape($query) . '%';
            $params['qname'] = $escaped;
            $params['qfirst'] = $escaped;
            $params['qlast'] = $escaped;
        }
        $users = $DB->get_records_select(
            'user',
            $where,
            $params,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname',
            0,
            $limit
        );
        $out = [];
        foreach ($users as $user) {
            $out[] = [
                'id' => (string) ((int) $user->id),
                'name' => self::learner_display_name($user),
            ];
        }
        return $out;
    }

    /**
     * @param int $courseid
     * @return array<int, array{id:string,name:string}>
     */
    private static function course_section_options(int $courseid): array {
        if ($courseid <= 1) {
            return [];
        }
        $modinfo = get_fast_modinfo($courseid);
        $out = [['id' => '-1', 'name' => get_string('allsections', 'local_nexreports')]];
        foreach ($modinfo->get_section_info_all() as $section) {
            $num = (int) $section->section;
            if ($num < 0) {
                continue;
            }
            $name = get_section_name($courseid, $section);
            $out[] = [
                'id' => (string) $num,
                'name' => $name !== '' ? $name : get_string('section') . ' ' . $num,
            ];
        }
        return $out;
    }

    /**
     * @param int $courseid
     * @return array<int, array{id:string,name:string}>
     */
    private static function activity_type_filter_options(int $courseid): array {
        $out = [['id' => '', 'name' => get_string('allmodules', 'local_nexreports')]];
        if ($courseid <= 1) {
            return $out;
        }
        $seen = [];
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || isset($seen[$cm->modname])) {
                continue;
            }
            $seen[$cm->modname] = true;
            $out[] = [
                'id' => $cm->modname,
                'name' => courses_report::activity_type_label($cm->modname),
            ];
        }
        return $out;
    }
}
