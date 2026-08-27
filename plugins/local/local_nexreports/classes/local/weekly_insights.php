<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Weekly learner improvement scorecards for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Build / read per-learner weekly metrics and improvement status.
 */
class weekly_insights {

    /** Default weeks retained / rebuilt. */
    public const DEFAULT_WEEKS = 8;

    /** Metric keys stored per week. */
    public const METRICS = [
        'timespent',
        'visits',
        'activedays',
        'activitiescompleted',
        'codingsolved',
        'quizattempts',
    ];

    /**
     * Absolute delta thresholds before treating a change as improving/declining.
     *
     * @return array<string,int>
     */
    public static function thresholds(): array {
        return [
            'timespent' => 15 * MINSECS, // 15 minutes.
            'visits' => 2,
            'activedays' => 0,
            'activitiescompleted' => 0,
            'codingsolved' => 0,
            'quizattempts' => 0,
        ];
    }

    /**
     * Monday 00:00 (site timezone) for the ISO week containing $ts.
     *
     * @param int $ts
     * @return int
     */
    public static function week_start(int $ts = 0): int {
        if ($ts <= 0) {
            $ts = time();
        }
        $tz = \core_date::get_server_timezone_object();
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $dow = (int) $dt->format('N'); // 1=Mon … 7=Sun.
        $monday = $dt->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        return $monday->getTimestamp();
    }

    /**
     * Exclusive end of the week that starts at $weekstart.
     *
     * @param int $weekstart
     * @return int
     */
    public static function week_end(int $weekstart): int {
        return $weekstart + (7 * DAYSECS);
    }

    /**
     * List of weekstart timestamps, oldest first (includes current week).
     *
     * @param int $weeks
     * @param int $now
     * @return int[]
     */
    public static function week_starts(int $weeks = self::DEFAULT_WEEKS, int $now = 0): array {
        $weeks = max(1, min(26, $weeks));
        $current = self::week_start($now > 0 ? $now : time());
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $out[] = $current - ($i * 7 * DAYSECS);
        }
        return $out;
    }

    /**
     * Rebuild weekly scorecards for the last N weeks (including current).
     *
     * @param int $weeks
     * @param callable|null $progress fn(string $message): void
     * @return array{weeks:int,learners:int,rows:int,seconds:float}
     */
    public static function rebuild(int $weeks = self::DEFAULT_WEEKS, ?callable $progress = null): array {
        global $DB;

        $t0 = microtime(true);
        if (!$DB->get_manager()->table_exists('nexreports_weekly_learner')) {
            return ['weeks' => 0, 'learners' => 0, 'rows' => 0, 'seconds' => 0.0];
        }

        $userids = self::enrolled_learner_ids();
        $starts = self::week_starts($weeks);
        $rows = 0;
        foreach ($starts as $weekstart) {
            if ($progress) {
                $progress('Computing week ' . userdate($weekstart, '%Y-%m-%d') . '…');
            }
            $rows += self::compute_week($weekstart, $userids);
        }

        return [
            'weeks' => count($starts),
            'learners' => count($userids),
            'rows' => $rows,
            'seconds' => round(microtime(true) - $t0, 2),
        ];
    }

    /**
     * Refresh only the current ISO week (scheduled daily).
     *
     * @return array{weeks:int,learners:int,rows:int,seconds:float}
     */
    public static function refresh_current_week(): array {
        global $DB;
        $t0 = microtime(true);
        if (!$DB->get_manager()->table_exists('nexreports_weekly_learner')) {
            return ['weeks' => 0, 'learners' => 0, 'rows' => 0, 'seconds' => 0.0];
        }
        $userids = self::enrolled_learner_ids();
        $weekstart = self::week_start();
        $rows = self::compute_week($weekstart, $userids);
        return [
            'weeks' => 1,
            'learners' => count($userids),
            'rows' => $rows,
            'seconds' => round(microtime(true) - $t0, 2),
        ];
    }

    /**
     * Cohort pulse + per-learner latest-week improvement table.
     *
     * @param string $institution
     * @param string $year
     * @param string $department
     * @param string $search
     * @param int $limit
     * @return array
     */
    public static function report(
        string $institution = '',
        string $year = '',
        string $department = '',
        string $search = '',
        int $limit = 500
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $search = trim($search);
        $cascade = self::filter_options($institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];

        $empty = [
            'generated' => time(),
            'weeks' => [],
            'aspects' => self::aspect_meta(),
            'summary' => self::empty_summary(),
            'rows' => [],
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $cascade['showcollege'],
            'showdepartment' => $cascade['showdepartment'],
            'colleges' => $cascade['colleges'],
            'years' => $cascade['years'],
            'departments' => $cascade['departments'],
            'search' => $search,
            'historyready' => 0,
        ];

        if (!$DB->get_manager()->table_exists('nexreports_weekly_learner')) {
            return $empty;
        }

        $weekstarts = self::week_starts(self::DEFAULT_WEEKS);
        $weeksmeta = [];
        foreach ($weekstarts as $ws) {
            $weeksmeta[] = [
                'weekstart' => $ws,
                'label' => userdate($ws, get_string('strftimedate', 'langconfig')),
                'current' => $ws === self::week_start() ? 1 : 0,
            ];
        }

        $userids = self::enrolled_learner_ids();
        $profileids = profile_filters::userids(0, $year, $department, $institution);
        if ($profileids !== null) {
            $userids = array_values(array_intersect($userids, $profileids));
        }
        $userids = access::filter_userids($userids);

        if ($search !== '' && $userids) {
            $userids = self::filter_by_search($userids, $search);
        }

        if (!$userids) {
            return $empty + [
                'weeks' => $weeksmeta,
                'historyready' => self::history_ready($weekstarts) ? 1 : 0,
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'wu');
        [$winsql, $wparams] = $DB->get_in_or_equal($weekstarts, SQL_PARAMS_NAMED, 'ww');
        $records = $DB->get_records_select(
            'nexreports_weekly_learner',
            "userid {$insql} AND weekstart {$winsql}",
            $inparams + $wparams,
            'userid ASC, weekstart ASC'
        );

        $byuser = [];
        foreach ($records as $rec) {
            $uid = (int) $rec->userid;
            $byuser[$uid][(int) $rec->weekstart] = $rec;
        }

        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, username, email, institution, department, idnumber, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );

        $latest = end($weekstarts);
        $prev = count($weekstarts) > 1 ? $weekstarts[count($weekstarts) - 2] : 0;
        $unspecified = get_string('notset', 'local_nexreports');
        $rows = [];
        $summary = self::empty_summary();
        $summary['totallearners'] = count($userids);

        $rank = 1;
        foreach ($users as $user) {
            $uid = (int) $user->id;
            $series = $byuser[$uid] ?? [];
            $cur = $series[$latest] ?? null;
            $prv = $prev ? ($series[$prev] ?? null) : null;
            $aspects = self::aspect_statuses($cur, $prv);
            $overall = self::overall_status($aspects);

            $weekseries = [];
            foreach ($weekstarts as $ws) {
                $point = $series[$ws] ?? null;
                $weekseries[] = [
                    'weekstart' => $ws,
                    'timespent' => $point ? (int) $point->timespent : 0,
                    'visits' => $point ? (int) $point->visits : 0,
                    'activedays' => $point ? (int) $point->activedays : 0,
                    'activitiescompleted' => $point ? (int) $point->activitiescompleted : 0,
                    'codingsolved' => $point ? (int) $point->codingsolved : 0,
                    'quizattempts' => $point ? (int) $point->quizattempts : 0,
                ];
            }

            $college = trim((string) ($user->institution ?? ''));
            $dept = trim((string) ($user->department ?? ''));
            $yop = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );

            $row = [
                'rank' => $rank++,
                'userid' => $uid,
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'username' => (string) ($user->username ?? ''),
                'fullname' => fullname($user),
                'email' => (string) ($user->email ?? ''),
                'institution' => $college !== '' ? $college : '—',
                'yearofpassing' => $yop,
                'department' => $dept !== '' ? $dept : '—',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'status' => $overall,
                'timespent' => $cur ? (int) $cur->timespent : 0,
                'visits' => $cur ? (int) $cur->visits : 0,
                'activedays' => $cur ? (int) $cur->activedays : 0,
                'activitiescompleted' => $cur ? (int) $cur->activitiescompleted : 0,
                'codingsolved' => $cur ? (int) $cur->codingsolved : 0,
                'quizattempts' => $cur ? (int) $cur->quizattempts : 0,
                'deltatimespent' => (int) ($aspects['timespent']['delta'] ?? 0),
                'deltavisits' => (int) ($aspects['visits']['delta'] ?? 0),
                'deltaactivedays' => (int) ($aspects['activedays']['delta'] ?? 0),
                'deltaactivities' => (int) ($aspects['activitiescompleted']['delta'] ?? 0),
                'deltacoding' => (int) ($aspects['codingsolved']['delta'] ?? 0),
                'deltaquiz' => (int) ($aspects['quizattempts']['delta'] ?? 0),
                'statustimespent' => $aspects['timespent']['status'],
                'statusvisits' => $aspects['visits']['status'],
                'statusactivedays' => $aspects['activedays']['status'],
                'statusactivities' => $aspects['activitiescompleted']['status'],
                'statuscoding' => $aspects['codingsolved']['status'],
                'statusquiz' => $aspects['quizattempts']['status'],
                'weekseries' => $weekseries,
            ];
            $rows[] = $row;

            if ($overall === 'improving') {
                $summary['improving']++;
            } else if ($overall === 'declining') {
                $summary['declining']++;
            } else if ($overall === 'stable') {
                $summary['stable']++;
            } else {
                $summary['neworidle']++;
            }

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'generated' => time(),
            'weeks' => $weeksmeta,
            'aspects' => self::aspect_meta(),
            'summary' => $summary,
            'rows' => $rows,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $cascade['showcollege'],
            'showdepartment' => $cascade['showdepartment'],
            'colleges' => $cascade['colleges'],
            'years' => $cascade['years'],
            'departments' => $cascade['departments'],
            'search' => $search,
            'historyready' => self::history_ready($weekstarts) ? 1 : 0,
            'latestweek' => $latest,
            'latestweeklabel' => userdate($latest, get_string('strftimedate', 'langconfig')),
        ];
    }

    /**
     * @param int $weekstart
     * @param int[] $userids
     * @return int Rows written
     */
    public static function compute_week(int $weekstart, array $userids): int {
        global $DB;

        if (!$userids) {
            return 0;
        }
        $weekend = self::week_end($weekstart);
        $now = time();
        $written = 0;

        // Process in chunks to keep memory bounded.
        foreach (array_chunk($userids, 400) as $chunk) {
            $metrics = [];
            foreach ($chunk as $uid) {
                $metrics[(int) $uid] = [
                    'timespent' => 0,
                    'visits' => 0,
                    'activedays' => 0,
                    'activitiescompleted' => 0,
                    'codingsolved' => 0,
                    'quizattempts' => 0,
                ];
            }

            self::merge_map($metrics, self::timespent_map($chunk, $weekstart, $weekend), 'timespent');
            self::merge_map($metrics, self::visits_map($chunk, $weekstart, $weekend), 'visits');
            self::merge_map($metrics, self::activedays_map($chunk, $weekstart, $weekend), 'activedays');
            self::merge_map($metrics, self::activities_map($chunk, $weekstart, $weekend), 'activitiescompleted');
            self::merge_map($metrics, self::coding_map($chunk, $weekstart, $weekend), 'codingsolved');
            self::merge_map($metrics, self::quiz_map($chunk, $weekstart, $weekend), 'quizattempts');

            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'eu');
            $params['weekstart'] = $weekstart;
            $existing = $DB->get_records_select(
                'nexreports_weekly_learner',
                "userid {$insql} AND weekstart = :weekstart",
                $params,
                '',
                'id, userid'
            );
            $byuser = [];
            foreach ($existing as $row) {
                $byuser[(int) $row->userid] = (int) $row->id;
            }

            foreach ($metrics as $uid => $m) {
                $record = (object) [
                    'userid' => $uid,
                    'weekstart' => $weekstart,
                    'timespent' => (int) $m['timespent'],
                    'visits' => (int) $m['visits'],
                    'activedays' => (int) $m['activedays'],
                    'activitiescompleted' => (int) $m['activitiescompleted'],
                    'codingsolved' => (int) $m['codingsolved'],
                    'quizattempts' => (int) $m['quizattempts'],
                    'timemodified' => $now,
                ];
                if (isset($byuser[$uid])) {
                    $record->id = $byuser[$uid];
                    $DB->update_record('nexreports_weekly_learner', $record);
                } else {
                    $DB->insert_record('nexreports_weekly_learner', $record);
                }
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return int[]
     */
    public static function enrolled_learner_ids(): array {
        global $DB;

        [$excludesql, $params] = overview::user_exclusion('u.id', 'wil');
        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0 AND e.courseid > 1
                 WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
                       $excludesql";
        $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params) ?: []);
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,array<string,int>> $metrics
     * @param array<int,int> $map
     * @param string $key
     */
    private static function merge_map(array &$metrics, array $map, string $key): void {
        foreach ($map as $uid => $value) {
            if (isset($metrics[$uid])) {
                $metrics[$uid][$key] = (int) $value;
            }
        }
    }

    /**
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function timespent_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids) {
            return $out;
        }

        if ($DB->get_manager()->table_exists('nexreports_tracking')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tu');
            $params['ws'] = $weekstart;
            $params['we'] = $weekend;
            $sql = "SELECT userid, COALESCE(SUM(timespent), 0) AS secs
                      FROM {nexreports_tracking}
                     WHERE userid $insql
                       AND timestart >= :ws AND timestart < :we
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $out[(int) $row->userid] = (int) $row->secs;
            }
        }

        // Fill gaps / add logstore estimate for users with little/no tracking.
        $need = [];
        foreach ($out as $uid => $secs) {
            if ($secs <= 0) {
                $need[] = $uid;
            }
        }
        if ($need) {
            foreach (self::loggap_map($need, $weekstart, $weekend) as $uid => $secs) {
                if ((int) ($out[$uid] ?? 0) <= 0) {
                    $out[$uid] = (int) $secs;
                }
            }
        }
        return $out;
    }

    /**
     * Session-gap estimate from logstore for a closed week window.
     *
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function loggap_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids || !overview::logstore_usable()) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $sql = "SELECT userid, timecreated
                  FROM {logstore_standard_log}
                 WHERE userid $insql
                   AND timecreated >= :ws AND timecreated < :we
                   AND courseid > 1
              ORDER BY userid ASC, timecreated ASC";
        $rs = $DB->get_recordset_sql($sql, $params);
        $gap = overview::session_gap();
        $prevuser = 0;
        $prevts = 0;
        foreach ($rs as $row) {
            $uid = (int) $row->userid;
            $ts = (int) $row->timecreated;
            if ($uid === $prevuser && $prevts > 0) {
                $delta = $ts - $prevts;
                if ($delta > 0 && $delta <= $gap) {
                    $out[$uid] += $delta;
                }
            }
            $prevuser = $uid;
            $prevts = $ts;
        }
        $rs->close();
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function visits_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids || !overview::logstore_usable()) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'vu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $sql = "SELECT userid, COUNT(1) AS visits
                  FROM {logstore_standard_log}
                 WHERE userid $insql
                   AND timecreated >= :ws AND timecreated < :we
                   AND courseid > 1
              GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->visits;
        }
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function activedays_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids || !overview::logstore_usable()) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'adu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $sql = "SELECT userid, timecreated
                  FROM {logstore_standard_log}
                 WHERE userid $insql
                   AND timecreated >= :ws AND timecreated < :we
                   AND courseid > 1";
        $tz = \core_date::get_server_timezone_object();
        $days = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $uid = (int) $row->userid;
            $day = (new \DateTimeImmutable('@' . (int) $row->timecreated))
                ->setTimezone($tz)
                ->format('Y-m-d');
            $days[$uid][$day] = true;
        }
        $rs->close();
        foreach ($days as $uid => $set) {
            $out[$uid] = count($set);
        }
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function activities_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'acu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $sql = "SELECT cmc.userid, COUNT(1) AS completed
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                 WHERE cmc.userid $insql
                   AND cmc.completionstate <> 0
                   AND cmc.timemodified >= :ws AND cmc.timemodified < :we
                   AND cm.course > 1
              GROUP BY cmc.userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->completed;
        }
        return $out;
    }

    /**
     * Coding questions first solved (positive fraction) during the week.
     *
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function coding_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids) {
            return $out;
        }
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('quiz')
                || !$dbman->table_exists('quiz_attempts')
                || !$dbman->table_exists('question_attempts')
                || !$dbman->table_exists('question_attempt_steps')) {
            return $out;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $params['behaviour'] = 'adaptive_adapted_for_coderunner';
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $notassessment = " AND (COALESCE(quiz.timeopen, 0) = 0 OR COALESCE(quiz.timeclose, 0) = 0)";

        $sql = "SELECT quiza.userid, COUNT(DISTINCT $slotkey) AS solved
                  FROM {quiz_attempts} quiza
                  JOIN {quiz} quiz ON quiz.id = quiza.quiz
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
                           AND qas.timecreated = (
                                SELECT MIN(qas2.timecreated)
                                  FROM {question_attempt_steps} qas2
                                 WHERE qas2.questionattemptid = qa.id
                                   AND qas2.fraction IS NOT NULL
                                   AND qas2.fraction > 0
                           )
                           AND qas.timecreated >= :ws AND qas.timecreated < :we
                   )
              GROUP BY quiza.userid";
        try {
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $out[(int) $row->userid] = (int) $row->solved;
            }
        } catch (\Throwable $e) {
            debugging('weekly coding_map failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $out;
    }

    /**
     * @param int[] $userids
     * @param int $weekstart
     * @param int $weekend
     * @return array<int,int>
     */
    private static function quiz_map(array $userids, int $weekstart, int $weekend): array {
        global $DB;
        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids || !$DB->get_manager()->table_exists('quiz_attempts')) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'qu');
        $params['ws'] = $weekstart;
        $params['we'] = $weekend;
        $sql = "SELECT userid, COUNT(1) AS attempts
                  FROM {quiz_attempts}
                 WHERE userid $insql
                   AND preview = 0
                   AND state = :state
                   AND timefinish >= :ws AND timefinish < :we
              GROUP BY userid";
        $params['state'] = 'finished';
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->attempts;
        }
        return $out;
    }

    /**
     * @param \stdClass|null $cur
     * @param \stdClass|null $prev
     * @return array<string,array{status:string,delta:int,value:int,previous:int}>
     */
    private static function aspect_statuses(?\stdClass $cur, ?\stdClass $prev): array {
        $thresholds = self::thresholds();
        $out = [];
        foreach (self::METRICS as $key) {
            $value = $cur ? (int) $cur->{$key} : 0;
            $previous = $prev ? (int) $prev->{$key} : 0;
            $delta = $value - $previous;
            $threshold = (int) ($thresholds[$key] ?? 0);
            if ($prev === null && $cur === null) {
                $status = 'idle';
            } else if ($prev === null) {
                $status = ($value > 0) ? 'new' : 'idle';
            } else if ($delta > $threshold) {
                $status = 'improving';
            } else if ($delta < -$threshold) {
                $status = 'declining';
            } else {
                $status = 'stable';
            }
            $out[$key] = [
                'status' => $status,
                'delta' => $delta,
                'value' => $value,
                'previous' => $previous,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array{status:string}> $aspects
     * @return string improving|declining|stable|new|idle
     */
    private static function overall_status(array $aspects): string {
        $up = 0;
        $down = 0;
        $stable = 0;
        $new = 0;
        $idle = 0;
        foreach ($aspects as $a) {
            switch ($a['status']) {
                case 'improving':
                    $up++;
                    break;
                case 'declining':
                    $down++;
                    break;
                case 'stable':
                    $stable++;
                    break;
                case 'new':
                    $new++;
                    break;
                default:
                    $idle++;
            }
        }
        if ($up > $down && $up > 0) {
            return 'improving';
        }
        if ($down > $up && $down > 0) {
            return 'declining';
        }
        if ($stable > 0 || ($up > 0 && $down > 0 && $up === $down)) {
            return 'stable';
        }
        if ($new > 0) {
            return 'new';
        }
        return 'idle';
    }

    /**
     * @return array<int,array{key:string,label:string}>
     */
    private static function aspect_meta(): array {
        return [
            ['key' => 'timespent', 'label' => get_string('insighttimespent', 'local_nexreports')],
            ['key' => 'visits', 'label' => get_string('insightvisits', 'local_nexreports')],
            ['key' => 'activedays', 'label' => get_string('insightactivedays', 'local_nexreports')],
            ['key' => 'activitiescompleted', 'label' => get_string('insightactivities', 'local_nexreports')],
            ['key' => 'codingsolved', 'label' => get_string('insightcoding', 'local_nexreports')],
            ['key' => 'quizattempts', 'label' => get_string('insightquiz', 'local_nexreports')],
        ];
    }

    /**
     * @return array{totallearners:int,improving:int,declining:int,stable:int,neworidle:int}
     */
    private static function empty_summary(): array {
        return [
            'totallearners' => 0,
            'improving' => 0,
            'declining' => 0,
            'stable' => 0,
            'neworidle' => 0,
        ];
    }

    /**
     * @param string $institution
     * @param string $year
     * @param string $department
     * @return array
     */
    private static function filter_options(
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
        $colleges = $showcollege ? profile_filters::search_institutions('', 100, 0) : [];
        $years = profile_filters::search_years('', 100, 0, $institution, $showdepartment ? '' : $department);
        $departments = [];
        if (!$showdepartment && $department !== '') {
            $departments = [['id' => $department, 'name' => $department]];
        } else if ($year !== '') {
            $departments = profile_filters::search_departments('', 100, 0, $year, $institution);
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
     * @param int[] $weekstarts
     * @return bool
     */
    private static function history_ready(array $weekstarts): bool {
        global $DB;
        if (!$weekstarts) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($weekstarts, SQL_PARAMS_NAMED, 'hw');
        $count = (int) $DB->count_records_select(
            'nexreports_weekly_learner',
            "weekstart {$insql}",
            $params
        );
        return $count > 0;
    }

    /**
     * @param int[] $userids
     * @param string $search
     * @return int[]
     */
    private static function filter_by_search(array $userids, string $search): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'su');
        $fullnamefields = $DB->sql_fullname();
        $like = $DB->sql_like($fullnamefields, ':q', false)
            . ' OR ' . $DB->sql_like('u.email', ':q2', false)
            . ' OR ' . $DB->sql_like('u.username', ':q3', false);
        $params['q'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['q2'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['q3'] = '%' . $DB->sql_like_escape($search) . '%';
        $sql = "SELECT u.id FROM {user} u WHERE u.id {$insql} AND ({$like})";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params) ?: []);
    }
}
