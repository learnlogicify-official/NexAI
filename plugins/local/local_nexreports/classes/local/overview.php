<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Site overview aggregates for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Query helpers for the super-admin overview dashboard.
 */
class overview {

    /** Active if lastaccess within this many seconds. */
    public const ACTIVE_THRESHOLD = 300;

    /** Show users with lastaccess within this many seconds in the realtime table. */
    public const RECENT_WINDOW = DAYSECS * 14;

    /**
     * Default gap between consecutive log events for the same user that still counts as
     * continuous time spent. Larger gaps start a new session (idle / left site).
     * Overridden by the local_nexreports/sessiongap setting.
     */
    public const SESSION_GAP = 20 * MINSECS;

    /**
     * Accounts excluded from activity metrics: the guest account and site administrators.
     *
     * @return int[]
     */
    public static function excluded_user_ids(): array {
        global $CFG;
        $ids = [(int) ($CFG->siteguest ?? 1)];
        foreach (explode(',', (string) ($CFG->siteadmins ?? '')) as $adminid) {
            $adminid = (int) trim($adminid);
            if ($adminid > 0) {
                $ids[] = $adminid;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * SQL fragment excluding guest and admin accounts from a user id column.
     *
     * @param string $column
     * @param string $prefix Unique named-parameter prefix
     * @return array{0:string,1:array} [sql, params]
     */
    public static function user_exclusion(string $column, string $prefix): array {
        global $DB;
        $ids = self::excluded_user_ids();
        $sql = '';
        $params = [];
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $prefix, false);
            $sql = " AND $column $insql";
        }
        [$instsql, $instparams] = access::institution_sql($column, $prefix . 'i');
        return [$sql . $instsql, array_merge($params, $instparams)];
    }

    /**
     * SQL fragment restricting a user id column to learners.
     *
     * A learner is any user holding a student-archetype role in a course context. Engagement
     * metrics count learners only, so teacher and manager browsing never inflates them.
     *
     * @param string $usercolumn Qualified user id column to correlate against
     * @param string $prefix Unique named-parameter prefix
     * @return array{0:string,1:array} [sql, params]
     */
    public static function learner_scope(string $usercolumn, string $prefix): array {
        global $DB;
        $archetype = $DB->sql_compare_text('r.archetype');
        $archevalue = $DB->sql_compare_text(':' . $prefix . 'archetype');
        $sql = " AND EXISTS (SELECT 1
                               FROM {role_assignments} ra
                               JOIN {role} r ON r.id = ra.roleid AND $archetype = $archevalue
                               JOIN {context} ctx ON ctx.id = ra.contextid
                                    AND ctx.contextlevel = :" . $prefix . "ctxlevel
                               JOIN {course} c ON c.id = ctx.instanceid
                              WHERE ra.userid = $usercolumn)";
        $params = [
            $prefix . 'archetype' => 'student',
            $prefix . 'ctxlevel' => CONTEXT_COURSE,
        ];
        [$instsql, $instparams] = access::institution_sql($usercolumn, $prefix . 'i');
        return [$sql . $instsql, array_merge($params, $instparams)];
    }

    /**
     * Configured session gap in seconds (gaps longer than this start a new session).
     *
     * @return int
     */
    public static function session_gap(): int {
        $minutes = (int) (get_config('local_nexreports', 'sessiongap') ?: 20);
        $minutes = max(1, min(120, $minutes));
        return $minutes * MINSECS;
    }

    /**
     * Day-bucket window for a period.
     *
     * Buckets are whole epoch days (floor(timestamp / 86400)) and the window ends with
     * yesterday, so the partial current day never distorts a period-over-period comparison.
     * Edwiser Reports anchors every block and insight the same way; keeping the same
     * anchoring is what makes the two dashboards report identical totals.
     *
     * @param int $days
     * @return array{0:int,1:int} [from, to) timestamps
     */
    private static function period_bounds(int $days): array {
        $to = (intdiv((int) strtotime('yesterday'), DAYSECS) + 2) * DAYSECS;
        return [$to - ($days * DAYSECS), $to];
    }

    /**
     * Cache lifetime in seconds.
     *
     * @return int
     */
    private static function ttl(): int {
        return max(60, (int) (get_config('local_nexreports', 'cachettl') ?: 600));
    }

    /**
     * Return a fresh cached block or null when missing/stale.
     *
     * @param string $key
     * @return array|null
     */
    private static function cache_hit(string $key): ?array {
        $key .= access::scope_cache_suffix();
        $cached = \cache::make('local_nexreports', 'overview')->get($key);
        if (is_array($cached) && !empty($cached['generated'])
                && ((time() - (int) $cached['generated']) < self::ttl())) {
            return $cached;
        }
        return null;
    }

    /**
     * Store a block payload in the cache.
     *
     * @param string $key
     * @param array $payload
     * @return array
     */
    private static function cache_store(string $key, array $payload): array {
        $key .= access::scope_cache_suffix();
        \cache::make('local_nexreports', 'overview')->set($key, $payload);
        return $payload;
    }

    /**
     * Cache key for a summary block.
     *
     * @param int $days
     * @return string
     */
    private static function summary_cache_key(int $days): string {
        return 'summary_d' . $days . '_v12';
    }

    /**
     * Cache key for a site time-spent block.
     *
     * @param int $days
     * @param int $userid
     * @return string
     */
    private static function timespent_site_cache_key(
        int $days,
        int $userid,
        bool $applyexclusion = true,
        string $year = '',
        string $department = ''
    ): string {
        return 'tssite_d' . $days . '_u' . $userid . '_g' . self::session_gap()
            . '_x' . ($applyexclusion ? '1' : '0')
            . '_y' . substr(sha1($year), 0, 8) . '_d' . substr(sha1($department), 0, 8) . '_v11';
    }

    /**
     * Cache key for visits-on-site block.
     *
     * @param int $days
     * @param int $userid
     * @return string
     */
    private static function visits_site_cache_key(int $days, int $userid): string {
        return 'visits_d' . $days . '_u' . $userid . '_v1';
    }

    /**
     * Cache key for a course time-spent block.
     *
     * @param int $days
     * @param int $courseid
     * @param int $userid
     * @return string
     */
    private static function timespent_course_cache_key(
        int $days,
        int $courseid,
        int $groupid,
        int $userid,
        string $year = '',
        string $department = ''
    ): string {
        return 'tscourse_d' . $days . '_c' . $courseid . '_gr' . $groupid . '_u' . $userid
            . '_y' . substr(sha1($year), 0, 8) . '_d' . substr(sha1($department), 0, 8)
            . '_g' . self::session_gap() . '_v11';
    }

    /**
     * KPI cards, activity/visits charts, and tables.
     *
     * Default (unfiltered) views prefer the cron snapshot table, then application cache,
     * then a live compute. Real-time users are always refreshed on read.
     *
     * @param int $days
     * @return array
     */
    public static function summary(int $days = 7): array {
        $days = $days === 30 ? 30 : 7;
        $key = self::summary_cache_key($days);

        if ($hit = self::cache_hit($key)) {
            $hit['realtimeusers'] = self::realtime_users(25);
            return $hit;
        }

        if (!access::is_scoped()) {
            $snap = snapshot::get(snapshot::BLOCK_SUMMARY, $days);
            if ($snap) {
                $snap['realtimeusers'] = self::realtime_users(25);
                return self::cache_store($key, $snap);
            }
        }

        $payload = self::compute_summary($days);
        return self::cache_store($key, $payload);
    }

    /**
     * Build the summary payload without reading cache/snapshot.
     *
     * @param int $days
     * @return array
     */
    public static function compute_summary(int $days): array {
        $days = $days === 30 ? 30 : 7;
        $now = time();
        [$currentstart, $currentend] = self::period_bounds($days);
        $previousstart = $currentstart - ($days * DAYSECS);

        $regs = self::count_registrations($currentstart, $currentend);
        $regsprev = self::count_registrations($previousstart, $currentstart);
        $enrols = self::count_enrolments($currentstart, $currentend);
        $enrolsprev = self::count_enrolments($previousstart, $currentstart);
        $comps = self::count_completions($currentstart, $currentend);
        $compsprev = self::count_completions($previousstart, $currentstart);
        $active = self::count_active_users($currentstart, $currentend);
        $activeprev = self::count_active_users($previousstart, $currentstart);

        $series = self::daily_series($currentstart, $currentend, $days);
        $prevseries = self::daily_series($previousstart, $currentstart, $days);
        $visitseries = $series['visits'];
        $totalvisits = array_sum($visitseries);
        $avgvisits = $days > 0 ? (int) round($totalvisits / $days) : 0;
        $avgactive = $days > 0 ? (int) round(array_sum($series['active']) / $days) : 0;
        $prevavgactive = $days > 0 ? (int) round(array_sum($prevseries['active']) / $days) : 0;
        $prevavgvisits = $days > 0 ? (int) round(array_sum($prevseries['visits']) / $days) : 0;

        if (access::is_scoped()) {
            $kpis = array_merge(self::institution_headcount_kpis(), [
                self::kpi('activeusers', $active, $activeprev),
            ]);
        } else {
            $kpis = [
                self::kpi('registrations', $regs, $regsprev),
                self::kpi('enrolments', $enrols, $enrolsprev),
                self::kpi('completions', $comps, $compsprev),
                self::kpi('activeusers', $active, $activeprev),
            ];
        }

        return [
            'period' => $days,
            'generated' => $now,
            'kpis' => $kpis,
            'overview' => [
                'labels' => $series['labels'],
                'active' => $series['active'],
                'enrolments' => $series['enrolments'],
                'completions' => $series['completions'],
                'averageactive' => $avgactive,
                'totalactive' => $active,
                'totalenrolments' => $enrols,
                'totalcompletions' => $comps,
                'activechange' => self::pct_change($avgactive, $prevavgactive),
            ],
            'visits' => [
                'labels' => $series['labels'],
                'values' => $visitseries,
                'average' => $avgvisits,
                'total' => $totalvisits,
                'change' => self::pct_change($avgvisits, $prevavgvisits),
            ],
            'popularcourses' => self::popular_courses(10),
            'realtimeusers' => self::realtime_users(25),
        ];
    }

    /**
     * Visits on site block (daily trend + optional user filter).
     *
     * Unfiltered requests reuse the summary snapshot's visits series when present.
     *
     * @param int $days
     * @param int $userid
     * @return array
     */
    public static function visits_site(int $days = 7, int $userid = 0): array {
        $days = $days === 30 ? 30 : 7;
        $userid = max(0, $userid);
        access::require_user_in_scope($userid);
        $key = self::visits_site_cache_key($days, $userid);

        if ($hit = self::cache_hit($key)) {
            return $hit;
        }

        if ($userid === 0 && !access::is_scoped()) {
            $snap = snapshot::get(snapshot::BLOCK_SUMMARY, $days);
            if ($snap && !empty($snap['visits']) && is_array($snap['visits'])) {
                $payload = $snap['visits'];
                $payload['period'] = $days;
                $payload['generated'] = time();
                $payload['selecteduserid'] = 0;
                $payload['selectedusername'] = '';
                return self::cache_store($key, $payload);
            }
        }

        $payload = self::compute_visits_site($days, $userid);
        return self::cache_store($key, $payload);
    }

    /**
     * Build the visits-on-site payload without reading cache/snapshot.
     *
     * @param int $days
     * @param int $userid
     * @return array
     */
    public static function compute_visits_site(int $days, int $userid = 0): array {
        $days = $days === 30 ? 30 : 7;
        $userid = max(0, $userid);
        $now = time();
        [$currentstart, $currentend] = self::period_bounds($days);
        $previousstart = $currentstart - ($days * DAYSECS);

        $series = self::daily_visits_series($currentstart, $currentend, $days, $userid);
        $prevseries = self::daily_visits_series($previousstart, $currentstart, $days, $userid);
        $total = (int) array_sum($series['values']);
        $prevtotal = (int) array_sum($prevseries['values']);
        $avg = $days > 0 ? (int) round($total / $days) : 0;
        $prevavg = $days > 0 ? (int) round($prevtotal / $days) : 0;

        return [
            'period' => $days,
            'generated' => $now,
            'labels' => $series['labels'],
            'values' => $series['values'],
            'average' => $avg,
            'total' => $total,
            'change' => self::pct_change($avg, $prevavg),
            'selecteduserid' => $userid,
            'selectedusername' => self::user_display_name($userid),
        ];
    }

    /**
     * Cache key for course activity status block.
     *
     * @param int $days
     * @param int $courseid
     * @param int $groupid
     * @param int $userid
     * @return string
     */
    private static function activity_status_cache_key(
        int $days,
        int $courseid,
        int $groupid,
        int $userid,
        string $year = '',
        string $department = ''
    ): string {
        return 'actstatus_d' . $days . '_c' . $courseid . '_g' . $groupid . '_u' . $userid
            . '_y' . substr(sha1($year), 0, 8) . '_d' . substr(sha1($department), 0, 8) . '_v2';
    }

    /**
     * Course activity status: assignment submissions + activity completions by day.
     *
     * Matches Edwiser Course Activity Status (live path): assign_submission status=submitted,
     * course_modules_completion with completionstate <> 0. Average = mean daily completions.
     *
     * @param int $days
     * @param int $courseid 0 = all courses
     * @param int $groupid 0 = all groups (requires course)
     * @param int $userid 0 = all users
     * @return array
     */
    public static function activity_status(
        int $days = 7,
        int $courseid = 0,
        int $groupid = 0,
        int $userid = 0,
        string $year = '',
        string $department = ''
    ): array {
        $days = $days === 30 ? 30 : 7;
        $courseid = max(0, $courseid);
        $groupid = max(0, $groupid);
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        if (access::is_scoped()) {
            $groupid = 0;
        }
        access::require_user_in_scope($userid);
        $key = self::activity_status_cache_key($days, $courseid, $groupid, $userid, $year, $department);

        if ($hit = self::cache_hit($key)) {
            return $hit;
        }

        $payload = self::compute_activity_status($days, $courseid, $groupid, $userid, $year, $department);
        return self::cache_store($key, $payload);
    }

    /**
     * Build course activity status payload.
     *
     * @param int $days
     * @param int $courseid
     * @param int $groupid
     * @param int $userid
     * @return array
     */
    public static function compute_activity_status(
        int $days,
        int $courseid = 0,
        int $groupid = 0,
        int $userid = 0,
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        $days = $days === 30 ? 30 : 7;
        $courseid = max(0, $courseid);
        $groupid = max(0, $groupid);
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        $now = time();
        if (access::is_scoped()) {
            $groupid = 0;
        }

        if ($groupid > 0) {
            $groupcourse = (int) $DB->get_field('groups', 'courseid', ['id' => $groupid]);
            if ($courseid <= 0 || $groupcourse !== $courseid) {
                $groupid = 0;
            }
        }

        [$currentstart, $currentend] = self::period_bounds($days);
        $previousstart = $currentstart - ($days * DAYSECS);

        $current = self::activity_status_series(
            $currentstart, $currentend, $days, $courseid, $groupid, $userid, $year, $department
        );
        $previous = self::activity_status_series(
            $previousstart, $currentstart, $days, $courseid, $groupid, $userid, $year, $department
        );

        $totalcompletions = (int) array_sum($current['completions']);
        $totalsubmissions = (int) array_sum($current['submissions']);
        $prevcompletions = (int) array_sum($previous['completions']);
        $average = $days > 0 ? (int) round($totalcompletions / $days) : 0;
        $prevaverage = $days > 0 ? (int) round($prevcompletions / $days) : 0;

        $coursename = '';
        if ($courseid > 1) {
            $fullname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
            $coursename = $fullname ? format_string((string) $fullname) : '';
        }
        $groupname = '';
        if ($groupid > 0) {
            $gname = $DB->get_field('groups', 'name', ['id' => $groupid]);
            $groupname = $gname ? format_string((string) $gname) : '';
        }

        return [
            'period' => $days,
            'generated' => $now,
            'labels' => $current['labels'],
            'submissions' => $current['submissions'],
            'completions' => $current['completions'],
            'average' => $average,
            'totalsubmissions' => $totalsubmissions,
            'totalcompletions' => $totalcompletions,
            'change' => self::pct_change($average, $prevaverage),
            'selectedcourseid' => $courseid,
            'selectedcoursename' => $coursename,
            'selectedgroupid' => $groupid,
            'selectedgroupname' => $groupname,
            'selecteduserid' => $userid,
            'selectedusername' => self::user_display_name($userid),
            'selectedyear' => $year,
            'selecteddepartment' => $department,
        ];
    }

    /**
     * Daily assignment submissions and activity completions for a window.
     *
     * @param int $from
     * @param int $to
     * @param int $days
     * @param int $courseid
     * @param int $groupid
     * @param int $userid
     * @return array{labels:string[],submissions:int[],completions:int[]}
     */
    private static function activity_status_series(
        int $from,
        int $to,
        int $days,
        int $courseid,
        int $groupid,
        int $userid,
        string $year = '',
        string $department = ''
    ): array {
        $labels = [];
        $submissions = [];
        $completions = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = userdate($from + ($i * DAYSECS), '%d %b');
            $submissions[] = 0;
            $completions[] = 0;
        }

        self::fill_daily_submissions($from, $to, $submissions, $courseid, $groupid, $userid, $year, $department);
        self::fill_daily_module_completions($from, $to, $completions, $courseid, $groupid, $userid, $year, $department);

        return [
            'labels' => $labels,
            'submissions' => $submissions,
            'completions' => $completions,
        ];
    }

    /**
     * Fill daily assign_submission counts (status = submitted).
     *
     * @param int $from
     * @param int $to
     * @param array $submissions
     * @param int $courseid
     * @param int $groupid
     * @param int $userid
     */
    private static function fill_daily_submissions(
        int $from,
        int $to,
        array &$submissions,
        int $courseid,
        int $groupid,
        int $userid,
        string $year = '',
        string $department = ''
    ): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('assign_submission')
                || !$DB->get_manager()->table_exists('assign')) {
            return;
        }

        $days = count($submissions);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;
        $status = $DB->sql_compare_text('asub.status');
        $params = [
            'fromts' => $from,
            'tots' => $to,
            'status' => 'submitted',
        ];
        $joins = "JOIN {assign} a ON a.id = asub.assignment
                  JOIN {user} u ON u.id = asub.userid AND u.deleted = 0";
        $where = "$status = :status
                    AND asub.timecreated >= :fromts
                    AND asub.timecreated < :tots
                    AND asub.userid > 1";

        if ($courseid > 1) {
            $where .= ' AND a.course = :courseid';
            $params['courseid'] = $courseid;
        } else {
            $where .= ' AND a.course > 1';
        }
        if ($userid > 0) {
            $where .= ' AND asub.userid = :userid';
            $params['userid'] = $userid;
        }
        if ($groupid > 0) {
            $joins .= ' JOIN {groups_members} gm ON gm.userid = asub.userid AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }

        [$instsql, $instparams] = access::institution_sql('asub.userid', 'asb');
        $where .= $instsql;
        $params = array_merge($params, $instparams);
        $profileids = profile_filters::userids($courseid, $year, $department);
        [$profilesql, $profileparams] = profile_filters::userid_in_sql('asub.userid', $profileids, 'asbp');
        $where .= $profilesql;
        $params = array_merge($params, $profileparams);

        $sql = "SELECT FLOOR((asub.timecreated - $base) / $daysecs) AS daybucket,
                       COUNT(asub.id) AS submissions
                  FROM {assign_submission} asub
                  $joins
                 WHERE $where
              GROUP BY FLOOR((asub.timecreated - $base) / $daysecs)";
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $i = (int) $row->daybucket;
            if ($i >= 0 && $i < $days) {
                $submissions[$i] = (int) $row->submissions;
            }
        }
    }

    /**
     * Fill daily course_modules_completion counts (completionstate <> 0).
     *
     * @param int $from
     * @param int $to
     * @param array $completions
     * @param int $courseid
     * @param int $groupid
     * @param int $userid
     */
    private static function fill_daily_module_completions(
        int $from,
        int $to,
        array &$completions,
        int $courseid,
        int $groupid,
        int $userid,
        string $year = '',
        string $department = ''
    ): void {
        global $DB;

        $days = count($completions);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;
        $params = [
            'fromts' => $from,
            'tots' => $to,
        ];
        $joins = "JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                  JOIN {user} u ON u.id = cmc.userid AND u.deleted = 0";
        $where = "cmc.completionstate <> 0
                    AND cmc.timemodified >= :fromts
                    AND cmc.timemodified < :tots
                    AND cmc.userid > 1";

        if ($courseid > 1) {
            $where .= ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        } else {
            $where .= ' AND cm.course > 1';
        }
        if ($userid > 0) {
            $where .= ' AND cmc.userid = :userid';
            $params['userid'] = $userid;
        }
        if ($groupid > 0) {
            $joins .= ' JOIN {groups_members} gm ON gm.userid = cmc.userid AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }

        [$instsql, $instparams] = access::institution_sql('cmc.userid', 'cmc');
        $where .= $instsql;
        $params = array_merge($params, $instparams);
        $profileids = profile_filters::userids($courseid, $year, $department);
        [$profilesql, $profileparams] = profile_filters::userid_in_sql('cmc.userid', $profileids, 'cmcp');
        $where .= $profilesql;
        $params = array_merge($params, $profileparams);

        $sql = "SELECT FLOOR((cmc.timemodified - $base) / $daysecs) AS daybucket,
                       COUNT(cmc.id) AS completed
                  FROM {course_modules_completion} cmc
                  $joins
                 WHERE $where
              GROUP BY FLOOR((cmc.timemodified - $base) / $daysecs)";
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $i = (int) $row->daybucket;
            if ($i >= 0 && $i < $days) {
                $completions[$i] = (int) $row->completed;
            }
        }
    }

    /**
     * Time spent on site block (daily trend + user filter).
     *
     * Unfiltered requests use the cron snapshot; filtered user views stay lazy-cached.
     *
     * @param int $days
     * @param int $userid
     * @param bool $applyexclusion When false, include guest/admin ids (for "my time" views)
     * @return array
     */
    public static function timespent_site(
        int $days = 7,
        int $userid = 0,
        bool $applyexclusion = true,
        string $year = '',
        string $department = ''
    ): array {
        $days = $days === 30 ? 30 : 7;
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        access::require_user_in_scope($userid);
        $key = self::timespent_site_cache_key($days, $userid, $applyexclusion, $year, $department);

        if ($hit = self::cache_hit($key)) {
            return $hit;
        }

        if ($userid === 0 && $year === '' && $department === '' && $applyexclusion && !access::is_scoped()) {
            $snap = snapshot::get(snapshot::BLOCK_TIMESPENT_SITE, $days);
            if ($snap) {
                $snap['selecteduserid'] = 0;
                $snap['selectedusername'] = '';
                $snap['selectedyear'] = '';
                $snap['selecteddepartment'] = '';
                return self::cache_store($key, $snap);
            }
        }

        $payload = self::compute_timespent_site($days, $userid, $applyexclusion, $year, $department);
        return self::cache_store($key, $payload);
    }

    /**
     * Build the site time-spent payload without reading cache/snapshot.
     *
     * @param int $days
     * @param int $userid
     * @param bool $applyexclusion
     * @return array
     */
    public static function compute_timespent_site(
        int $days,
        int $userid = 0,
        bool $applyexclusion = true,
        string $year = '',
        string $department = ''
    ): array {
        $days = $days === 30 ? 30 : 7;
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        $now = time();
        [$currentstart, $currentend] = self::period_bounds($days);
        $previousstart = $currentstart - ($days * DAYSECS);
        $report = self::timespent_report(
            $currentstart, $currentend, $days, $userid, 0, false, 0, $applyexclusion, $year, $department
        );
        $prev = self::timespent_report(
            $previousstart, $currentstart, $days, $userid, 0, false, 0, $applyexclusion, $year, $department
        );
        $total = (int) array_sum($report['minutes']);
        $prevtotal = (int) array_sum($prev['minutes']);
        $avg = $days > 0 ? (int) round($total / $days) : 0;
        $prevavg = $days > 0 ? (int) round($prevtotal / $days) : 0;

        return [
            'period' => $days,
            'generated' => $now,
            'available' => $report['available'],
            'labels' => $report['labels'],
            'values' => $report['minutes'],
            'average' => $avg,
            'total' => $total,
            'change' => self::pct_change($avg, $prevavg),
            'selecteduserid' => $userid,
            'selectedusername' => self::user_display_name($userid),
            'selectedyear' => $year,
            'selecteddepartment' => $department,
        ];
    }

    /**
     * Time spent on course block (top courses + course/user filters).
     *
     * Unfiltered requests use the cron snapshot; filtered views stay lazy-cached.
     *
     * @param int $days
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function timespent_course(
        int $days = 7,
        int $courseid = 0,
        int $groupid = 0,
        int $userid = 0,
        string $year = '',
        string $department = ''
    ): array {
        $days = $days === 30 ? 30 : 7;
        $courseid = max(0, $courseid);
        $groupid = max(0, $groupid);
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        if (access::is_scoped()) {
            $groupid = 0;
        }
        access::require_user_in_scope($userid);
        $key = self::timespent_course_cache_key($days, $courseid, $groupid, $userid, $year, $department);

        if ($hit = self::cache_hit($key)) {
            return $hit;
        }

        if ($courseid === 0 && $groupid === 0 && $userid === 0 && $year === '' && $department === ''
                && !access::is_scoped()) {
            $snap = snapshot::get(snapshot::BLOCK_TIMESPENT_COURSE, $days);
            if ($snap) {
                $snap['selectedcourseid'] = 0;
                $snap['selectedgroupid'] = 0;
                $snap['selecteduserid'] = 0;
                $snap['selectedcoursename'] = '';
                $snap['selectedgroupname'] = '';
                $snap['selectedusername'] = '';
                $snap['selectedyear'] = '';
                $snap['selecteddepartment'] = '';
                return self::cache_store($key, $snap);
            }
        }

        $payload = self::compute_timespent_course($days, $courseid, $groupid, $userid, $year, $department);
        return self::cache_store($key, $payload);
    }

    /**
     * Build the course time-spent payload without reading cache/snapshot.
     *
     * @param int $days
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function compute_timespent_course(
        int $days,
        int $courseid = 0,
        int $groupid = 0,
        int $userid = 0,
        string $year = '',
        string $department = ''
    ): array {
        $days = $days === 30 ? 30 : 7;
        $courseid = max(0, $courseid);
        $groupid = max(0, $groupid);
        $userid = max(0, $userid);
        $year = trim($year);
        $department = trim($department);
        if (access::is_scoped()) {
            $groupid = 0;
        }
        $now = time();
        [$currentstart, $currentend] = self::period_bounds($days);
        $previousstart = $currentstart - ($days * DAYSECS);
        $report = self::timespent_report(
            $currentstart, $currentend, $days, $userid, $courseid, true, $groupid, true, $year, $department
        );
        $prev = self::timespent_report(
            $previousstart, $currentstart, $days, $userid, $courseid, true, $groupid, true, $year, $department
        );

        return [
            'period' => $days,
            'generated' => $now,
            'available' => $report['available'],
            'courselabels' => $report['courselabels'],
            'coursevalues' => $report['courseminutes'],
            'courseaverage' => $report['courseaverage'],
            'coursetotal' => $report['coursetotal'],
            'coursechange' => self::pct_change($report['coursetotal'], $prev['coursetotal']),
            'selectedcourseid' => $courseid,
            'selectedgroupid' => $groupid,
            'selecteduserid' => $userid,
            'selectedcoursename' => self::course_display_name($courseid),
            'selectedgroupname' => self::group_display_name($groupid),
            'selectedusername' => self::user_display_name($userid),
            'selectedyear' => $year,
            'selecteddepartment' => $department,
        ];
    }

    /**
     * Recompute default (unfiltered) blocks for 7- and 30-day periods and persist them.
     *
     * Called by the scheduled task. Warms both the snapshot table and application cache.
     *
     * @return array{blocks:int,seconds:float}
     */
    public static function refresh_default_snapshots(): array {
        $started = microtime(true);
        $blocks = 0;
        $gap = self::session_gap();

        foreach ([7, 30] as $days) {
            $summary = self::compute_summary($days);
            // Keep personal realtime rows out of the durable snapshot; they are refreshed on read.
            $storedsummary = $summary;
            unset($storedsummary['realtimeusers']);
            snapshot::put(snapshot::BLOCK_SUMMARY, $days, 0, 0, $storedsummary);
            self::cache_store(self::summary_cache_key($days), $summary);
            $blocks++;

            $site = self::compute_timespent_site($days, 0);
            snapshot::put(snapshot::BLOCK_TIMESPENT_SITE, $days, 0, 0, $site, $gap);
            self::cache_store(self::timespent_site_cache_key($days, 0), $site);
            $blocks++;

            $course = self::compute_timespent_course($days, 0, 0, 0);
            snapshot::put(snapshot::BLOCK_TIMESPENT_COURSE, $days, 0, 0, $course, $gap);
            self::cache_store(self::timespent_course_cache_key($days, 0, 0, 0), $course);
            $blocks++;
        }

        return [
            'blocks' => $blocks,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * @param string $key
     * @param int $current
     * @param int $previous
     * @return array
     */
    private static function kpi(string $key, int $current, int $previous): array {
        return [
            'key' => $key,
            'value' => $current,
            'previous' => $previous,
            'change' => self::pct_change($current, $previous),
        ];
    }

    /**
     * Absolute (non period-over-period) KPI card.
     *
     * @param string $key
     * @param int $value
     * @return array
     */
    private static function static_kpi(string $key, int $value): array {
        return [
            'key' => $key,
            'value' => $value,
            'previous' => $value,
            'change' => 0.0,
        ];
    }

    /**
     * Institution ADMIN KPI set: years of passing, departments, and student headcount.
     *
     * @return array
     */
    private static function institution_headcount_kpis(): array {
        $headcount = self::learner_headcount();
        $departments = [];
        $years = [];
        foreach ($headcount['institutions'] as $institution) {
            foreach ($institution['departments'] as $department) {
                $departments[(string) $department['name']] = true;
                foreach ($department['years'] as $year) {
                    $years[(string) $year['name']] = true;
                }
            }
            foreach ($institution['years'] as $year) {
                $years[(string) $year['name']] = true;
            }
        }
        return [
            self::static_kpi('totalyears', count($years)),
            self::static_kpi('totaldepartments', count($departments)),
            self::static_kpi('totalstudents', (int) ($headcount['totalstudents'] ?? 0)),
        ];
    }

    /**
     * @param int $current
     * @param int $previous
     * @return float
     */
    public static function pct_change(int $current, int $previous): float {
        return round((($current - $previous) / max($previous, 1)) * 100, 1);
    }

    /**
     * @param int $from
     * @param int $to
     * @return int
     */
    public static function count_registrations(int $from, int $to): int {
        global $DB;
        $params = ['fromts' => $from, 'tots' => $to];
        $where = 'timecreated >= :fromts AND timecreated < :tots';
        [$instsql, $instparams] = access::institution_sql('id', 'reg');
        // Every account created in the window counts, including admins and since-deleted users
        // (site-wide). Institution admins only see registrations for their college.
        return (int) $DB->count_records_select(
            'user',
            $where . $instsql,
            array_merge($params, $instparams)
        );
    }

    /**
     * Learner enrolments created in range, counted once per course/user pair.
     *
     * @param int $from
     * @param int $to
     * @return int
     */
    public static function count_enrolments(int $from, int $to): int {
        global $DB;

        if (self::logstore_usable()) {
            [$joinsql, $wheresql, $params] = self::enrolment_log_clauses();
            $pair = $DB->sql_concat('l.courseid', "'-'", 'l.relateduserid');
            $sql = "SELECT COUNT(DISTINCT $pair)
                      FROM {logstore_standard_log} l
                      $joinsql
                     WHERE $wheresql
                       AND l.timecreated >= :fromts
                       AND l.timecreated < :tots";
            return (int) $DB->count_records_sql($sql, array_merge($params, [
                'fromts' => $from,
                'tots' => $to,
            ]));
        }

        $sql = "SELECT COUNT(1)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE u.deleted = 0
                   AND ue.timecreated >= :fromts
                   AND ue.timecreated < :tots
                   AND e.courseid > 1";
        $params = ['fromts' => $from, 'tots' => $to];
        [$instsql, $instparams] = access::institution_sql('u.id', 'enr');
        return (int) $DB->count_records_sql($sql . $instsql, array_merge($params, $instparams));
    }

    /**
     * Join and where fragments selecting learner enrolment-created log rows.
     *
     * @return array{0:string,1:string,2:array}
     */
    private static function enrolment_log_clauses(): array {
        global $DB;
        $archetype = $DB->sql_compare_text('r.archetype');
        $archevalue = $DB->sql_compare_text(':archetype');
        $join = "JOIN {role_assignments} ra ON ra.contextid = l.contextid AND ra.userid = l.relateduserid
                 JOIN {role} r ON r.id = ra.roleid AND $archetype = $archevalue";
        $where = "l.eventname = :eventname AND l.action = :actionname";
        $params = [
            'archetype' => 'student',
            'eventname' => '\\core\\event\\user_enrolment_created',
            'actionname' => 'created',
        ];
        [$instsql, $instparams] = access::institution_sql('l.relateduserid', 'enli');
        return [$join, $where . $instsql, array_merge($params, $instparams)];
    }

    /**
     * Course completions recorded in range.
     *
     * Reads the plugin progress cache rather than {course_completions}: a learner counts
     * as complete once every completion-tracked activity is done, which does not require
     * the course to define Moodle completion criteria.
     *
     * @param int $from
     * @param int $to
     * @return int
     */
    public static function count_completions(int $from, int $to): int {
        global $DB;
        $params = ['fromts' => $from, 'tots' => $to];
        [$instsql, $instparams] = access::institution_sql('userid', 'cmp');
        return (int) $DB->count_records_select(
            'nexreports_course_progress',
            'completiontime IS NOT NULL AND completiontime >= :fromts AND completiontime < :tots'
                . $instsql,
            array_merge($params, $instparams)
        );
    }

    /**
     * Distinct learners who viewed something in range (logstore preferred, lastaccess fallback).
     *
     * @param int $from
     * @param int $to
     * @return int
     */
    public static function count_active_users(int $from, int $to): int {
        global $DB;

        [$learnersql, $learnerparams] = self::learner_scope('l.userid', 'lau');

        if (self::logstore_usable()) {
            $sql = "SELECT COUNT(DISTINCT l.userid)
                      FROM {logstore_standard_log} l
                     WHERE l.userid > 1
                       AND l.action = :action
                       $learnersql
                       AND l.timecreated >= :fromts
                       AND l.timecreated < :tots";
            $count = (int) $DB->count_records_sql($sql, array_merge($learnerparams, [
                'action' => 'viewed',
                'fromts' => $from,
                'tots' => $to,
            ]));
            if ($count > 0) {
                return $count;
            }
        }

        [$fallbacksql, $fallbackparams] = self::learner_scope('l.id', 'lau2');
        return (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {user} l
              WHERE l.deleted = 0
                AND l.id > 1
                $fallbacksql
                AND l.lastaccess >= :fromts
                AND l.lastaccess < :tots",
            array_merge($fallbackparams, ['fromts' => $from, 'tots' => $to])
        );
    }

    /**
     * @return bool
     */
    public static function logstore_usable(): bool {
        global $DB;
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }
        try {
            $usable = $DB->get_manager()->table_exists('logstore_standard_log');
        } catch (\Throwable $e) {
            $usable = false;
        }
        return $usable;
    }

    /**
     * Build daily buckets for charts.
     *
     * @param int $from
     * @param int $to
     * @param int $days
     * @return array{labels:string[],active:int[],enrolments:int[],completions:int[],visits:int[]}
     */
    public static function daily_series(int $from, int $to, int $days): array {
        $labels = [];
        $active = [];
        $enrolments = [];
        $completions = [];
        $visits = [];

        for ($i = 0; $i < $days; $i++) {
            $labels[] = userdate($from + ($i * DAYSECS), '%d %b');
            $active[] = 0;
            $enrolments[] = 0;
            $completions[] = 0;
            $visits[] = 0;
        }

        self::fill_daily_enrolments($from, $to, $enrolments);
        self::fill_daily_completions($from, $to, $completions);
        self::fill_daily_visits($from, $to, $visits, 0);
        self::fill_daily_active($from, $to, $active);

        return [
            'labels' => $labels,
            'active' => $active,
            'enrolments' => $enrolments,
            'completions' => $completions,
            'visits' => $visits,
        ];
    }

    /**
     * Daily visit counts for the visits-on-site chart.
     *
     * @param int $from
     * @param int $to
     * @param int $days
     * @param int $userid 0 = all users
     * @return array{labels:string[],values:int[]}
     */
    public static function daily_visits_series(int $from, int $to, int $days, int $userid = 0): array {
        $labels = [];
        $values = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = userdate($from + ($i * DAYSECS), '%d %b');
            $values[] = 0;
        }
        self::fill_daily_visits($from, $to, $values, $userid);
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param int $from
     * @param int $to
     * @param array $enrolments
     */
    private static function fill_daily_enrolments(int $from, int $to, array &$enrolments): void {
        global $DB;
        $days = count($enrolments);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;

        if (self::logstore_usable()) {
            [$joinsql, $wheresql, $params] = self::enrolment_log_clauses();
            $pair = $DB->sql_concat('l.courseid', "'-'", 'l.relateduserid');
            $sql = "SELECT FLOOR((l.timecreated - $base) / $daysecs) AS daybucket,
                           COUNT(DISTINCT $pair) AS enrolments
                      FROM {logstore_standard_log} l
                      $joinsql
                     WHERE $wheresql
                       AND l.timecreated >= :fromts
                       AND l.timecreated < :tots
                  GROUP BY FLOOR((l.timecreated - $base) / $daysecs)";
            $rows = $DB->get_records_sql($sql, array_merge($params, [
                'fromts' => $from,
                'tots' => $to,
            ]));
            foreach ($rows as $row) {
                $i = (int) $row->daybucket;
                if ($i >= 0 && $i < $days) {
                    $enrolments[$i] = (int) $row->enrolments;
                }
            }
            return;
        }

        $sql = "SELECT ue.timecreated
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE u.deleted = 0
                   AND e.courseid > 1
                   AND ue.timecreated >= :fromts
                   AND ue.timecreated < :tots";
        $params = ['fromts' => $from, 'tots' => $to];
        [$instsql, $instparams] = access::institution_sql('u.id', 'fen');
        $rows = $DB->get_recordset_sql($sql . $instsql, array_merge($params, $instparams));
        foreach ($rows as $row) {
            $i = intdiv(((int) $row->timecreated) - $base, $daysecs);
            if ($i >= 0 && $i < $days) {
                $enrolments[$i]++;
            }
        }
        $rows->close();
    }

    /**
     * @param int $from
     * @param int $to
     * @param array $completions
     */
    private static function fill_daily_completions(int $from, int $to, array &$completions): void {
        global $DB;
        $days = count($completions);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;

        $sql = "SELECT completiontime
                  FROM {nexreports_course_progress}
                 WHERE completiontime IS NOT NULL
                   AND completiontime >= :fromts
                   AND completiontime < :tots";
        $params = ['fromts' => $from, 'tots' => $to];
        [$instsql, $instparams] = access::institution_sql('userid', 'fcp');
        $rows = $DB->get_recordset_sql($sql . $instsql, array_merge($params, $instparams));
        foreach ($rows as $row) {
            $i = intdiv(((int) $row->completiontime) - $base, $daysecs);
            if ($i >= 0 && $i < $days) {
                $completions[$i]++;
            }
        }
        $rows->close();
    }

    /**
     * Fill daily course/activity view counts (optionally for one user).
     *
     * @param int $from
     * @param int $to
     * @param array $visits
     * @param int $userid 0 = all users (excludes guest + primary admin)
     */
    private static function fill_daily_visits(int $from, int $to, array &$visits, int $userid = 0): void {
        global $DB;
        $days = count($visits);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;
        $userid = max(0, $userid);

        if (!self::logstore_usable()) {
            if ($userid > 0) {
                $lastaccess = (int) $DB->get_field('user', 'lastaccess', ['id' => $userid, 'deleted' => 0]);
                if ($lastaccess >= $from && $lastaccess < $to) {
                    $i = intdiv($lastaccess - $base, $daysecs);
                    if ($i >= 0 && $i < $days) {
                        $visits[$i]++;
                    }
                }
                return;
            }

            [$fallbacksql, $fallbackparams] = self::learner_scope('l.id', 'ldv');
            $sql = "SELECT l.id, l.lastaccess
                      FROM {user} l
                     WHERE l.deleted = 0
                       AND l.id > 1
                       $fallbacksql
                       AND l.lastaccess >= :fromts
                       AND l.lastaccess < :tots";
            $rows = $DB->get_recordset_sql($sql, array_merge($fallbackparams, [
                'fromts' => $from,
                'tots' => $to,
            ]));
            foreach ($rows as $row) {
                $i = intdiv(((int) $row->lastaccess) - $base, $daysecs);
                if ($i >= 0 && $i < $days) {
                    $visits[$i]++;
                }
            }
            $rows->close();
            return;
        }

        $target = $DB->sql_compare_text('l.target');
        $params = [
            'action' => 'viewed',
            'coursetarget' => 'course',
            'moduletarget' => 'course_module',
            'fromts' => $from,
            'tots' => $to,
        ];
        $userclause = 'l.userid > 2 AND u.deleted = 0';
        if ($userid > 0) {
            $userclause = 'l.userid = :filteruserid AND u.deleted = 0';
            $params['filteruserid'] = $userid;
        }

        [$instsql, $instparams] = access::institution_sql('l.userid', 'vst');
        $params = array_merge($params, $instparams);

        $visitsql = "SELECT FLOOR((l.timecreated - $base) / $daysecs) AS daybucket,
                            COUNT(*) AS visits
                       FROM {logstore_standard_log} l
                       JOIN {user} u ON u.id = l.userid
                      WHERE $userclause
                        AND l.action = :action
                        AND (($target = :coursetarget)
                             OR ($target = :moduletarget AND l.objecttable IS NOT NULL))
                        AND l.timecreated >= :fromts
                        AND l.timecreated < :tots
                        $instsql
                   GROUP BY FLOOR((l.timecreated - $base) / $daysecs)";
        $rows = $DB->get_records_sql($visitsql, $params);
        foreach ($rows as $row) {
            $i = (int) $row->daybucket;
            if ($i >= 0 && $i < $days) {
                $visits[$i] = (int) $row->visits;
            }
        }
    }

    /**
     * Fill daily distinct active learner counts.
     *
     * @param int $from
     * @param int $to
     * @param array $active
     */
    private static function fill_daily_active(int $from, int $to, array &$active): void {
        global $DB;
        $days = count($active);
        $base = (int) $from;
        $daysecs = (int) DAYSECS;

        if (self::logstore_usable()) {
            [$learnersql, $learnerparams] = self::learner_scope('l.userid', 'lda');
            $activesql = "SELECT FLOOR((l.timecreated - $base) / $daysecs) AS daybucket,
                                 COUNT(DISTINCT l.userid) AS activeusers
                            FROM {logstore_standard_log} l
                           WHERE l.userid > 1
                             AND l.action = :action
                             $learnersql
                             AND l.timecreated >= :fromts
                             AND l.timecreated < :tots
                        GROUP BY FLOOR((l.timecreated - $base) / $daysecs)";
            $rows = $DB->get_records_sql($activesql, array_merge($learnerparams, [
                'action' => 'viewed',
                'fromts' => $from,
                'tots' => $to,
            ]));
            $has = false;
            foreach ($rows as $row) {
                $i = (int) $row->daybucket;
                if ($i >= 0 && $i < $days) {
                    $active[$i] = (int) $row->activeusers;
                    $has = true;
                }
            }
            if ($has) {
                return;
            }
        }

        [$fallbacksql, $fallbackparams] = self::learner_scope('l.id', 'ldb');
        $sql = "SELECT l.id, l.lastaccess
                  FROM {user} l
                 WHERE l.deleted = 0
                   AND l.id > 1
                   $fallbacksql
                   AND l.lastaccess >= :fromts
                   AND l.lastaccess < :tots";
        $rows = $DB->get_recordset_sql($sql, array_merge($fallbackparams, [
            'fromts' => $from,
            'tots' => $to,
        ]));
        foreach ($rows as $row) {
            $i = intdiv(((int) $row->lastaccess) - $base, $daysecs);
            if ($i < 0 || $i >= $days) {
                continue;
            }
            $active[$i]++;
        }
        $rows->close();
    }

    /**
     * Time spent per day and per course for a window.
     *
     * Prefers measured dwell time from the nexreports_tracking heartbeat table.
     * Days before the first tracked record fall back to the log-gap estimate
     * (gaps between consecutive logstore events up to the configured session gap).
     * Each day is served by exactly one source, so nothing is double counted.
     *
     * @param int $from
     * @param int $to
     * @param int $days
     * @return array{labels:string[],minutes:int[],available:bool}
     */
    public static function timespent_report(
        int $from,
        int $to,
        int $days,
        int $userid = 0,
        int $courseid = 0,
        bool $wantcourses = true,
        int $groupid = 0,
        bool $applyexclusion = true,
        string $year = '',
        string $department = ''
    ): array {
        $labels = [];
        $minutes = [];
        $base = (int) $from;
        $daysecs = (int) DAYSECS;
        for ($i = 0; $i < $days; $i++) {
            $labels[] = userdate($from + ($i * DAYSECS), '%d %b');
            $minutes[] = 0;
        }

        // First day index covered by measured tracking data; $days when none.
        $firsttracked = tracking::first_tracked();
        $trackedindex = $days;
        if ($firsttracked > 0 && $firsttracked < $to) {
            $trackedindex = max(0, intdiv(max($firsttracked, $from) - $base, $daysecs));
        }

        if (!self::logstore_usable() && $trackedindex >= $days) {
            return [
                'labels' => $labels,
                'minutes' => $minutes,
                'courselabels' => [],
                'courseminutes' => [],
                'courseaverage' => 0,
                'coursetotal' => 0,
                'available' => false,
            ];
        }

        global $DB;
        $seconds = array_fill(0, $days, 0);
        $courseconds = [];
        $trackedfrom = $base + ($trackedindex * $daysecs);
        if (access::is_scoped()) {
            $groupid = 0;
        }
        $profileids = profile_filters::userids($courseid, $year, $department);

        // Measured segment: aggregate the heartbeat table.
        if ($trackedindex < $days) {
            $excludesql = '';
            $excludeparams = [];
            if ($applyexclusion) {
                [$excludesql, $excludeparams] = self::user_exclusion('userid', 'extr');
            }
            $params = array_merge($excludeparams, [
                'fromts' => max($from, $trackedfrom),
                'tots' => $to,
            ]);
            $userwhere = '';
            if ($userid > 0) {
                $userwhere = ' AND userid = :userid';
                $params['userid'] = $userid;
            }
            if ($groupid > 0) {
                $userwhere .= ' AND EXISTS (
                    SELECT 1 FROM {groups_members} gm
                     WHERE gm.userid = {nexreports_tracking}.userid AND gm.groupid = :groupid
                )';
                $params['groupid'] = $groupid;
            }
            [$profilesql, $profileparams] = profile_filters::userid_in_sql('userid', $profileids, 'ptr');
            $userwhere .= $profilesql;
            $params = array_merge($params, $profileparams);
            $sql = "SELECT FLOOR((timestart - $base) / $daysecs) AS daybucket,
                           SUM(timespent) AS secs
                      FROM {nexreports_tracking}
                     WHERE timestart >= :fromts
                       AND timestart < :tots
                       $userwhere
                       $excludesql
                  GROUP BY FLOOR((timestart - $base) / $daysecs)";
            $rows = $DB->get_records_sql($sql, $params);
            foreach ($rows as $row) {
                $i = (int) $row->daybucket;
                if ($i >= 0 && $i < $days) {
                    $seconds[$i] += (int) $row->secs;
                }
            }

            if ($wantcourses) {
                $courseparams = $params;
                $coursewhere = '';
                if ($courseid > 0) {
                    $coursewhere = ' AND courseid = :courseid';
                    $courseparams['courseid'] = $courseid;
                }
                $sql = "SELECT courseid, SUM(timespent) AS secs
                          FROM {nexreports_tracking}
                         WHERE timestart >= :fromts
                           AND timestart < :tots
                           AND courseid > 1
                           $userwhere
                           $coursewhere
                           $excludesql
                      GROUP BY courseid";
                $rows = $DB->get_records_sql($sql, $courseparams);
                foreach ($rows as $row) {
                    $courseconds[(int) $row->courseid] =
                        ($courseconds[(int) $row->courseid] ?? 0) + (int) $row->secs;
                }
            }
        }

        // Estimated segment: log-gap scan for days before tracking existed.
        if ($trackedindex > 0 && self::logstore_usable()) {
            $estimateto = min($to, $trackedfrom);
            $sessiongap = self::session_gap();
            $excludesql = '';
            $excludeparams = [];
            if ($applyexclusion) {
                [$excludesql, $excludeparams] = self::user_exclusion('userid', 'exts');
            }

            $params = array_merge($excludeparams, [
                'fromts' => $from,
                'tots' => $estimateto,
            ]);
            $userwhere = '';
            if ($userid > 0) {
                $userwhere = ' AND userid = :userid';
                $params['userid'] = $userid;
            }
            if ($groupid > 0) {
                $userwhere .= ' AND EXISTS (
                    SELECT 1 FROM {groups_members} gm
                     WHERE gm.userid = {logstore_standard_log}.userid AND gm.groupid = :groupid
                )';
                $params['groupid'] = $groupid;
            }
            [$profilesql, $profileparams] = profile_filters::userid_in_sql('userid', $profileids, 'pts');
            $userwhere .= $profilesql;
            $params = array_merge($params, $profileparams);
            $coursecol = $wantcourses ? ', courseid' : '';
            $sql = "SELECT userid, timecreated $coursecol
                      FROM {logstore_standard_log}
                     WHERE userid > 0
                       $excludesql
                       AND timecreated >= :fromts
                       AND timecreated < :tots
                       $userwhere
                  ORDER BY userid ASC, timecreated ASC";
            $rows = $DB->get_recordset_sql($sql, $params);

            $prevuid = 0;
            $prevts = 0;
            $prevcourseid = 0;
            foreach ($rows as $row) {
                $uid = (int) $row->userid;
                $ts = (int) $row->timecreated;
                $eventcourseid = $wantcourses ? (int) $row->courseid : 0;
                if ($uid === $prevuid && $prevts > 0) {
                    $gap = $ts - $prevts;
                    if ($gap > 0 && $gap <= $sessiongap) {
                        // Numeric day bucket avoids a per-row userdate() timezone conversion.
                        $i = intdiv($ts - $base, $daysecs);
                        if ($i >= 0 && $i < $days && $i < $trackedindex) {
                            $seconds[$i] += $gap;
                        }
                        if ($wantcourses && $eventcourseid > 1 && $eventcourseid === $prevcourseid
                                && ($courseid === 0 || $eventcourseid === $courseid)) {
                            $courseconds[$eventcourseid] = ($courseconds[$eventcourseid] ?? 0) + $gap;
                        }
                    }
                }
                $prevuid = $uid;
                $prevts = $ts;
                $prevcourseid = $eventcourseid;
            }
            $rows->close();
        }

        foreach ($seconds as $i => $sec) {
            $minutes[$i] = (int) round($sec / MINSECS);
        }

        $coursetotal = (int) round(array_sum($courseconds) / MINSECS);
        $coursecount = count($courseconds);
        arsort($courseconds, SORT_NUMERIC);
        $courseconds = array_slice($courseconds, 0, 12, true);
        $courselabels = [];
        $courseminutes = [];
        if ($courseconds) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($courseconds), SQL_PARAMS_NAMED, 'tc');
            $names = $DB->get_records_select('course', "id $insql", $inparams, '', 'id, fullname');
            foreach ($courseconds as $id => $secondsvalue) {
                if (!isset($names[$id])) {
                    continue;
                }
                $courselabels[] = format_string($names[$id]->fullname);
                $courseminutes[] = (int) round($secondsvalue / MINSECS);
            }
        }
        return [
            'labels' => $labels,
            'minutes' => $minutes,
            'courselabels' => $courselabels,
            'courseminutes' => $courseminutes,
            'courseaverage' => $coursecount > 0
                ? (int) round($coursetotal / $coursecount)
                : 0,
            'coursetotal' => $coursetotal,
            'available' => true,
        ];
    }

    /**
     * Search users for a filter dropdown.
     *
     * Searches the whole user table rather than only users with recent log activity,
     * so any account can be found. An empty query returns the first alphabetical page.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array{id:int,name:string}>
     */
    public static function search_users(
        string $query,
        int $limit = 20,
        int $courseid = 0,
        int $groupid = 0,
        string $year = '',
        string $department = ''
    ): array {
        global $DB;

        $limit = max(1, min(50, $limit));
        $year = trim($year);
        $department = trim($department);
        [$excludesql, $params] = self::user_exclusion('u.id', 'exsu');
        $where = "u.deleted = 0 $excludesql";
        if ($groupid > 0 && !access::is_scoped()) {
            $where .= ' AND EXISTS (
                SELECT 1 FROM {groups_members} gmu
                 WHERE gmu.userid = u.id AND gmu.groupid = :groupid
            )';
            $params['groupid'] = $groupid;
        } else if ($courseid > 0) {
            $where .= ' AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ueu
                  JOIN {enrol} eu ON eu.id = ueu.enrolid
                 WHERE ueu.userid = u.id AND eu.courseid = :courseid
            )';
            $params['courseid'] = $courseid;
        }
        if ($department !== '') {
            $where .= ' AND ' . $DB->sql_equal('u.department', ':sudept', false);
            $params['sudept'] = $department;
        }

        $query = trim($query);
        if ($query !== '') {
            $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
            $namelike = $DB->sql_like($fullname, ':q1', false, false);
            $emaillike = $DB->sql_like('u.email', ':q2', false, false);
            $where .= " AND ($namelike OR $emaillike)";
            $escaped = '%' . $DB->sql_like_escape($query) . '%';
            $params['q1'] = $escaped;
            $params['q2'] = $escaped;
        }

        $sql = "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                       u.lastnamephonetic, u.middlename, u.alternatename, u.idnumber
                  FROM {user} u
                 WHERE $where
              ORDER BY u.lastname, u.firstname";
        // Over-fetch when year filtering is applied in PHP.
        $fetch = $year !== '' ? max($limit * 20, 200) : $limit;
        $records = $DB->get_records_sql($sql, $params, 0, $fetch);

        $unspecified = get_string('notset', 'local_nexreports');
        $out = [];
        foreach ($records as $record) {
            if ($year !== '') {
                $normalized = self::normalize_year_of_passing((string) ($record->idnumber ?? ''), $unspecified);
                if ($normalized !== $year) {
                    continue;
                }
            }
            $out[] = ['id' => (string) ((int) $record->id), 'name' => fullname($record)];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Search course groups for the time-spent filter.
     *
     * Groups only make sense within a course, so an unset course returns nothing rather than
     * every group on the site.
     *
     * @param string $query
     * @param int $limit
     * @param int $courseid
     * @return array<int, array{id:int,name:string}>
     */
    public static function search_groups(string $query, int $limit = 20, int $courseid = 0): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $params = ['courseid' => $courseid];
        $where = 'g.courseid = :courseid';

        $query = trim($query);
        if ($query !== '') {
            $where .= ' AND ' . $DB->sql_like('g.name', ':groupquery', false, false);
            $params['groupquery'] = '%' . $DB->sql_like_escape($query) . '%';
        }

        $sql = "SELECT g.id, g.name
                  FROM {groups} g
                 WHERE $where
              ORDER BY g.name";
        $records = $DB->get_records_sql($sql, $params, 0, $limit);

        $out = [];
        foreach ($records as $record) {
            $out[] = ['id' => (string) ((int) $record->id), 'name' => format_string($record->name)];
        }
        return $out;
    }

    /**
     * Search courses for a filter dropdown.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array{id:int,name:string}>
     */
    public static function search_courses(string $query, int $limit = 20): array {
        global $DB;

        $limit = max(1, min(50, $limit));
        $params = [];
        $where = 'c.id > 1';

        $query = trim($query);
        if ($query !== '') {
            $fulllike = $DB->sql_like('c.fullname', ':q1', false, false);
            $shortlike = $DB->sql_like('c.shortname', ':q2', false, false);
            $where .= " AND ($fulllike OR $shortlike)";
            $escaped = '%' . $DB->sql_like_escape($query) . '%';
            $params['q1'] = $escaped;
            $params['q2'] = $escaped;
        }

        // Institution admins only see courses that have learners from their college.
        if (access::is_scoped()) {
            [$instsql, $instparams] = access::institution_sql('u.id', 'scu');
            $where .= " AND EXISTS (
                SELECT 1
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE e.courseid = c.id
                       $instsql
            )";
            $params = array_merge($params, $instparams);
        }

        $sql = "SELECT c.id, c.fullname
                  FROM {course} c
                 WHERE $where
              ORDER BY c.fullname";
        $records = $DB->get_records_sql($sql, $params, 0, $limit);

        $out = [];
        foreach ($records as $record) {
            $out[] = ['id' => (string) ((int) $record->id), 'name' => format_string($record->fullname)];
        }
        return $out;
    }

    /**
     * Display name for a selected user filter, or empty when unset/missing.
     *
     * @param int $userid
     * @return string
     */
    private static function user_display_name(int $userid): string {
        global $DB;
        if ($userid <= 0) {
            return '';
        }
        $record = $DB->get_record('user', ['id' => $userid],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
        return $record ? fullname($record) : '';
    }

    /**
     * Display name for a selected course filter, or empty when unset/missing.
     *
     * @param int $courseid
     * @return string
     */
    private static function course_display_name(int $courseid): string {
        global $DB;
        if ($courseid <= 0) {
            return '';
        }
        $fullname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        return $fullname ? format_string($fullname) : '';
    }

    /**
     * Display name for a selected group filter, or empty when unset/missing.
     *
     * @param int $groupid
     * @return string
     */
    private static function group_display_name(int $groupid): string {
        global $DB;
        if ($groupid <= 0) {
            return '';
        }
        $name = $DB->get_field('groups', 'name', ['id' => $groupid]);
        return $name ? format_string($name) : '';
    }

    /**
     * Lifetime popular courses by enrolment count.
     *
     * @param int $limit
     * @return array
     */
    public static function popular_courses(int $limit = 10): array {
        global $DB;
        $limit = max(1, min(50, $limit));
        [$instsql, $instparams] = access::institution_sql('u.id', 'pop');
        $sql = "SELECT c.id, c.fullname, c.shortname, COUNT(ue.id) AS enrolments
                  FROM {course} c
                  JOIN {enrol} e ON e.courseid = c.id
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                 WHERE c.id > 1
                       $instsql
              GROUP BY c.id, c.fullname, c.shortname
              ORDER BY enrolments DESC, c.fullname ASC";
        $rows = $DB->get_records_sql($sql, $instparams, 0, $limit);
        $out = [];
        $rank = 1;
        foreach ($rows as $row) {
            $out[] = [
                'rank' => $rank++,
                'courseid' => (int) $row->id,
                'name' => format_string($row->fullname),
                'url' => (new \moodle_url('/course/view.php', ['id' => (int) $row->id]))->out(false),
                'enrolments' => (int) $row->enrolments,
            ];
        }
        return $out;
    }

    /**
     * Recent users by lastaccess.
     *
     * @param int $limit
     * @return array
     */
    public static function realtime_users(int $limit = 25): array {
        global $DB;
        $limit = max(1, min(100, $limit));
        $since = time() - self::RECENT_WINDOW;
        [$excludesql, $excludeparams] = self::user_exclusion('u.id', 'exrt');
        $sql = "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.lastaccess
                  FROM {user} u
                 WHERE u.deleted = 0
                   $excludesql
                   AND u.lastaccess >= :since
              ORDER BY u.lastaccess DESC";
        $rows = $DB->get_records_sql($sql, array_merge($excludeparams, ['since' => $since]), 0, $limit);
        $now = time();
        $out = [];
        foreach ($rows as $row) {
            $last = (int) $row->lastaccess;
            $active = ($now - $last) <= self::ACTIVE_THRESHOLD;
            $out[] = [
                'userid' => (int) $row->id,
                'fullname' => fullname($row),
                'url' => (new \moodle_url('/user/profile.php', ['id' => (int) $row->id]))->out(false),
                'onlinesince' => self::format_ago($now - $last),
                'active' => $active,
            ];
        }
        return $out;
    }

    /**
     * Human-readable duration.
     *
     * @param int $seconds
     * @return string
     */
    public static function format_ago(int $seconds): string {
        $seconds = max(0, $seconds);
        if ($seconds < MINSECS) {
            return '< 1 ' . get_string('min');
        }
        return format_time($seconds);
    }

    /**
     * Site-wide learner headcount with institution, department, and year-of-passing.
     *
     * Counts distinct users with a student-archetype role in any course context.
     * Uses core {user}.institution / {user}.department / {user}.idnumber
     * (idnumber treated as Year of Passing; empty → "Not set").
     *
     * @return array{generated:int,totalstudents:int,institutions:array}
     */
    public static function learner_headcount(): array {
        global $DB;

        $cachekey = 'learner_headcount_v3';
        if ($hit = self::cache_hit($cachekey)) {
            return $hit;
        }

        [$scopesql, $scopeparams] = self::learner_scope('u.id', 'hc');
        $unspecified = get_string('notset', 'local_nexreports');

        $wheresql = "u.deleted = 0
                        AND u.suspended = 0
                        AND u.id > 1
                        $scopesql";

        $totalsql = "SELECT COUNT(DISTINCT u.id)
                       FROM {user} u
                      WHERE $wheresql";
        $total = (int) $DB->count_records_sql($totalsql, $scopeparams);

        $sql = "SELECT u.id, u.institution, u.department, u.idnumber
                  FROM {user} u
                 WHERE $wheresql";
        $users = $DB->get_recordset_sql($sql, $scopeparams);

        $byinst = [];
        foreach ($users as $user) {
            $iname = trim((string) ($user->institution ?? ''));
            $dname = trim((string) ($user->department ?? ''));
            $yname = self::normalize_year_of_passing((string) ($user->idnumber ?? ''), $unspecified);
            if ($iname === '') {
                $iname = $unspecified;
            }
            if ($dname === '') {
                $dname = $unspecified;
            }
            if (!isset($byinst[$iname])) {
                $byinst[$iname] = [
                    'name' => $iname,
                    'count' => 0,
                    'departments' => [],
                    'years' => [],
                ];
            }
            $byinst[$iname]['count']++;
            if (!isset($byinst[$iname]['departments'][$dname])) {
                $byinst[$iname]['departments'][$dname] = [
                    'count' => 0,
                    'years' => [],
                ];
            }
            $byinst[$iname]['departments'][$dname]['count']++;
            if (!isset($byinst[$iname]['departments'][$dname]['years'][$yname])) {
                $byinst[$iname]['departments'][$dname]['years'][$yname] = 0;
            }
            $byinst[$iname]['departments'][$dname]['years'][$yname]++;

            if (!isset($byinst[$iname]['years'][$yname])) {
                $byinst[$iname]['years'][$yname] = 0;
            }
            $byinst[$iname]['years'][$yname]++;
        }
        $users->close();

        $institutions = [];
        foreach ($byinst as $inst) {
            $departments = [];
            foreach ($inst['departments'] as $dname => $dept) {
                $departments[] = [
                    'name' => (string) $dname,
                    'count' => (int) $dept['count'],
                    'years' => self::sort_year_rows($dept['years']),
                ];
            }
            usort($departments, static function (array $a, array $b): int {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }
                return strcasecmp($a['name'], $b['name']);
            });
            $institutions[] = [
                'name' => $inst['name'],
                'count' => (int) $inst['count'],
                'departments' => $departments,
                'years' => self::sort_year_rows($inst['years']),
            ];
        }
        usort($institutions, static function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return self::cache_store($cachekey, [
            'generated' => time(),
            'totalstudents' => $total,
            'institutions' => $institutions,
        ]);
    }

    /**
     * Normalize idnumber into a Year of Passing label.
     *
     * @param string $idnumber
     * @param string $unspecified
     * @return string
     */
    public static function normalize_year_of_passing_public(string $idnumber, string $unspecified): string {
        return self::normalize_year_of_passing($idnumber, $unspecified);
    }

    /**
     * Normalize idnumber into a Year of Passing label.
     *
     * @param string $idnumber
     * @param string $unspecified
     * @return string
     */
    private static function normalize_year_of_passing(string $idnumber, string $unspecified): string {
        $raw = trim($idnumber);
        if ($raw === '') {
            return $unspecified;
        }
        // Prefer a 4-digit year if present (e.g. 2024, YOP-2025, 2024-25 → 2024).
        if (preg_match('/(19|20)\d{2}/', $raw, $m)) {
            return $m[0];
        }
        return $raw;
    }

    /**
     * @param array<string,int> $years
     * @return array<int,array{name:string,count:int}>
     */
    private static function sort_year_rows(array $years): array {
        $rows = [];
        foreach ($years as $name => $count) {
            $rows[] = [
                'name' => (string) $name,
                'count' => (int) $count,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $an = ctype_digit($a['name']) ? (int) $a['name'] : null;
            $bn = ctype_digit($b['name']) ? (int) $b['name'] : null;
            if ($an !== null && $bn !== null && $an !== $bn) {
                return $bn <=> $an; // Newer years first.
            }
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    /**
     * Mustache-friendly headcount payload for Overview SSR.
     *
     * @return array
     */
    public static function learner_headcount_template(): array {
        $data = self::learner_headcount();
        $institutions = [];
        $first = true;
        foreach ($data['institutions'] as $inst) {
            $departments = [];
            foreach ($inst['departments'] as $dept) {
                $departments[] = [
                    'name' => $dept['name'],
                    'count' => $dept['count'],
                    'years' => $dept['years'],
                ];
            }
            $institutions[] = [
                'name' => $inst['name'],
                'count' => $inst['count'],
                'departments' => $departments,
                'years' => $inst['years'],
                'hasdepartments' => !empty($departments),
                'hasyears' => !empty($inst['years']),
                'selected' => $first,
            ];
            $first = false;
        }
        return [
            'totalstudents' => (int) $data['totalstudents'],
            'hasinstitutions' => !empty($institutions),
            'institutions' => $institutions,
            // HTML-attribute-safe JSON for client bootstrap.
            'json' => htmlspecialchars(json_encode([
                'totalstudents' => (int) $data['totalstudents'],
                'institutions' => $data['institutions'],
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'),
        ];
    }
}
