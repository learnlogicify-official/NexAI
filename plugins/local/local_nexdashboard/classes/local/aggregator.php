<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Aggregate student dashboard data from Moodle + practice plugins.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');
require_once($GLOBALS['CFG']->libdir . '/enrollib.php');

/**
 * Dashboard data builder (MVP + Phase 2 insights).
 */
class aggregator {

    /** @var array<string,mixed> Per-request memo (cleared implicitly per PHP request). */
    private static array $memo = [];

    /**
     * Full dashboard payload for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function build(int $userid): array {
        self::$memo = [];

        // Short application cache — dashboard aggregates are expensive; 90s is fine.
        try {
            $cache = \cache::make_from_params(
                \cache_store::MODE_APPLICATION,
                'local_nexdashboard',
                'payload'
            );
            $key = 'u' . $userid . '_v6';
            $hit = $cache->get($key);
            if (is_array($hit) && isset($hit['data'], $hit['t']) && (time() - (int) $hit['t']) < 180) {
                return $hit['data'];
            }
        } catch (\Throwable $e) {
            $cache = null;
            $key = null;
        }

        $data = self::build_uncached($userid);

        if (!empty($cache) && !empty($key)) {
            try {
                $cache->set($key, ['t' => time(), 'data' => $data]);
            } catch (\Throwable $e) {
                // Ignore cache write failures.
            }
        }

        return $data;
    }

    /**
     * Learning time only (hero + analytics Time Spent) — slow path, loaded async.
     *
     * @param int $userid
     * @return array{learningTime:string,totalTimeMinutes:int,charts:array}
     */
    public static function build_learning_time(int $userid): array {
        self::$memo = [];

        try {
            $cache = \cache::make_from_params(
                \cache_store::MODE_APPLICATION,
                'local_nexdashboard',
                'payload'
            );
            $key = 'u' . $userid . '_time_v1';
            $hit = $cache->get($key);
            if (is_array($hit) && isset($hit['data'], $hit['t']) && (time() - (int) $hit['t']) < 180) {
                return $hit['data'];
            }
        } catch (\Throwable $e) {
            $cache = null;
            $key = null;
        }

        $minutes = self::learning_minutes_total($userid);
        $timecharts = [];
        foreach (['daily', 'weekly', 'monthly'] as $period) {
            $timecharts[$period] = self::metric_series($userid, $period, 'time');
        }

        $data = [
            'learningTime' => self::format_duration($minutes),
            'totalTimeMinutes' => $minutes,
            'charts' => $timecharts,
        ];

        if (!empty($cache) && !empty($key)) {
            try {
                $cache->set($key, ['t' => time(), 'data' => $data]);
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        return $data;
    }

    /**
     * Build payload without reading the application cache.
     *
     * @param int $userid
     * @return array
     */
    private static function build_uncached(int $userid): array {
        global $USER, $CFG;

        $practice = self::practice_stats($userid);
        $codelab = self::codelab_stats($userid);
        $courses = self::course_cards($userid);
        $skills = self::skill_heatmap($userid);
        $tracks = self::track_progress($userid);
        $stuck = self::stuck_items($userid);
        $peers = self::peer_context($userid);
        $online = self::online_users($userid);
        $goal = self::weekly_goal($userid);
        $deadlines = self::upcoming_deadlines($userid);
        $activity = self::recent_activity($userid);

        // Warm expensive first-solve maps once — charts + month + player reuse them.
        self::practice_first_solve_map($userid);
        self::course_coding_first_solve_map($userid);
        self::course_mcq_first_correct_map($userid);

        $month = self::month_summary($userid);
        $continue = self::continue_cards($userid, $courses, $practice, $codelab);
        $next = self::next_action($continue, $practice, $codelab, $skills, $stuck);
        $charts = self::analytics_charts($userid, false);
        $weekly = $charts['weekly']['xp'];
        $learningstreak = self::learning_streak($userid);
        $streakdays = self::streak_week_days($userid);

        $coursegrades = self::course_grades_sum($userid);
        $practicexp = (int) ($practice['xp'] ?? 0);
        $codelabxp = (int) ($codelab['xp'] ?? 0);
        // Total XP = sum of course final grades + CodeLab XP + NexPractice XP.
        $xp = (int) round($coursegrades + $practicexp + $codelabxp);
        $coursesolved = self::course_coding_solved_total($userid);
        $solved = (int) ($practice['solved'] + $codelab['solved'] + $coursesolved);
        $streak = (int) $learningstreak['current'];
        $longest = (int) $learningstreak['longest'];
        // Rank still compares Practice+CodeLab XP (site-wide grade sum is not comparable the same way).
        $rank = self::combined_rank($userid, $practicexp + $codelabxp);
        $accuracy = self::combined_accuracy($userid);
        $portfoliostats = self::portfolio_connection_stats($userid);
        $interviewstats = self::interview_stats($userid);

        $displayname = fullname($USER);
        $firstname = !empty($USER->firstname) ? $USER->firstname : $displayname;
        $greeting = local_nexdashboard_greeting();

        // Learning time is expensive (logstore / tracking scan) — deferred to
        // get_learning_time so the rest of the dashboard can paint first.
        $analyticsolved = self::analytics_solved_total($userid, $coursesolved);

        return [
            'greeting' => $greeting,
            'welcomeback' => get_string('welcomeback', 'local_nexdashboard', $firstname),
            'tagline' => get_string('unlockachievements', 'local_nexdashboard'),
            'displayname' => $displayname,
            'learningTime' => '',
            'learningTimePending' => true,
            'courseCount' => count($courses),
            'nextAction' => $next,
            'continueCards' => $continue,
            'courses' => $courses,
            'skills' => $skills,
            'tracks' => $tracks,
            'stuck' => $stuck,
            'peers' => $peers,
            'onlineUsers' => $online,
            'goal' => $goal,
            'deadlines' => $deadlines,
            'recentActivity' => $activity,
            'monthSummary' => $month,
            'analytics' => [
                'totalXp' => $xp,
                'totalSolved' => $analyticsolved,
                'totalTimeMinutes' => 0,
                'courseGrades' => (int) round($coursegrades),
                'practiceXp' => $practicexp,
                'codelabXp' => $codelabxp,
                'avgPerWeek' => (int) round($weekly['avg']),
                'trendPct' => (int) $weekly['trend'],
                'series' => array_map(static function(array $p): array {
                    return ['label' => $p['label'], 'xp' => (int) $p['value']];
                }, $weekly['series']),
                'charts' => $charts,
            ],
            'streak' => [
                'current' => $streak,
                'longest' => $longest,
                'days' => $streakdays,
                'hint' => get_string('streakhint', 'local_nexdashboard'),
            ],
            'player' => [
                'solved' => $solved,
                'rank' => $rank,
                'accuracy' => $accuracy,
                'streak' => $streak,
                'courseCodingSolved' => $coursesolved,
                'courseMcqCorrect' => self::course_mcq_correct_total($userid),
                'practiceSolved' => (int) ($practice['solved'] ?? 0),
                'battlesWon' => self::battles_won_total($userid),
                'platformsConnected' => $portfoliostats['connected'],
                'platformsTotal' => $portfoliostats['total'],
                'githubConnected' => $portfoliostats['github'],
                'interviewsTaken' => $interviewstats['taken'],
                'interviewsCompleted' => $interviewstats['completed'],
            ],
            'links' => [
                'practice' => (new \moodle_url('/local/learnlogic/index.php'))->out(false),
                'codelab' => (new \moodle_url('/local/nexcodelab/index.php'))->out(false),
                'mycourses' => file_exists($CFG->dirroot . '/local/nexcourse/version.php')
                    ? (new \moodle_url('/local/nexcourse/index.php'))->out(false)
                    : (new \moodle_url('/my/courses.php'))->out(false),
                'practiceLeaderboard' => (new \moodle_url('/local/learnlogic/leaderboard.php'))->out(false),
                'codelabLeaderboard' => (new \moodle_url('/local/nexcodelab/leaderboard.php'))->out(false),
                'overallLeaderboard' => (new \moodle_url('/local/nexdashboard/leaderboard.php'))->out(false),
                'battleground' => (new \moodle_url('/local/nexbattleground/index.php'))->out(false),
                'portfolio' => (new \moodle_url('/local/nexportfolio/index.php'))->out(false),
                'interview' => (new \moodle_url('/local/nexinterview/index.php'))->out(false),
                'messages' => (new \moodle_url('/message/index.php'))->out(false),
                'calendar' => (new \moodle_url('/calendar/view.php', ['view' => 'upcoming']))->out(false),
            ],
            'hasPractice' => self::table_exists('local_learnlogic_problem'),
            'hasCodeLab' => self::table_exists('local_nexcodelab_mission'),
            'hasBattleGround' => self::table_exists('local_nexbattleground_battle'),
            'hasPortfolio' => self::table_exists('local_nexportfolio_handles'),
            'hasInterview' => self::table_exists('local_nexinterview_attempt'),
        ];
    }

    /**
     * Sum of course final grades for the user (all enrolled courses).
     *
     * @param int $userid
     * @return float
     */
    private static function course_grades_sum(int $userid): float {
        global $DB, $CFG;

        if (!self::table_exists('grade_items') || !self::table_exists('grade_grades')) {
            return 0.0;
        }

        $courses = enrol_get_users_courses($userid, true, 'id');
        if (!$courses) {
            return 0.0;
        }
        $ids = array_map('intval', array_keys($courses));
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
        $params = array_merge(['userid' => $userid], $inparams);

        $sql = "SELECT COALESCE(SUM(gg.finalgrade), 0)
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gg.userid = :userid
                   AND gi.itemtype = 'course'
                   AND gi.courseid {$insql}
                   AND gg.finalgrade IS NOT NULL";
        try {
            return (float) $DB->get_field_sql($sql, $params);
        } catch (\Throwable $e) {
            // Fallback via grade API if SQL shape differs.
            if (!empty($CFG->libdir) && file_exists($CFG->libdir . '/gradelib.php')) {
                require_once($CFG->libdir . '/gradelib.php');
            }
            $sum = 0.0;
            foreach ($ids as $courseid) {
                if (!function_exists('grade_get_course_grade')) {
                    break;
                }
                $g = grade_get_course_grade($userid, $courseid);
                if ($g && isset($g->grade) && $g->grade !== null && is_numeric($g->grade)) {
                    $sum += (float) $g->grade;
                }
            }
            return $sum;
        }
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function table_exists(string $table): bool {
        if (array_key_exists('t:' . $table, self::$memo)) {
            return (bool) self::$memo['t:' . $table];
        }
        global $DB;
        try {
            self::$memo['t:' . $table] = $DB->get_manager()->table_exists($table);
        } catch (\Throwable $e) {
            self::$memo['t:' . $table] = false;
        }
        return (bool) self::$memo['t:' . $table];
    }

    /**
     * @param int $userid
     * @return array
     */
    private static function practice_stats(int $userid): array {
        global $DB;
        $empty = ['xp' => 0, 'streak' => 0, 'longest' => 0, 'solved' => 0, 'inprogress' => null];
        if (!self::table_exists('local_learnlogic_userxp')) {
            return $empty;
        }
        $xp = (int) ($DB->get_field('local_learnlogic_userxp', 'xp', ['userid' => $userid]) ?: 0);
        $streak = $DB->get_record('local_learnlogic_streak', ['userid' => $userid]);
        // Distinct Practice activity: ACCEPTED + BattleGround wins + completed interviews.
        $solved = self::practice_solved_total($userid);

        $inprogress = null;
        // Prefer a problem with drafts or failed attempts but not accepted.
        $sql = "SELECT p.id, p.name
                  FROM {local_learnlogic_problem} p
                 WHERE p.status = 'ready'
                   AND EXISTS (
                        SELECT 1 FROM {local_learnlogic_submission} s
                         WHERE s.problemid = p.id AND s.userid = :u1
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM {local_learnlogic_submission} s2
                         WHERE s2.problemid = p.id AND s2.userid = :u2 AND s2.status = 'ACCEPTED'
                   )
              ORDER BY (
                  SELECT MAX(s3.timecreated) FROM {local_learnlogic_submission} s3
                   WHERE s3.problemid = p.id AND s3.userid = :u3
              ) DESC";
        $row = $DB->get_record_sql($sql, ['u1' => $userid, 'u2' => $userid, 'u3' => $userid]);
        if ($row) {
            $inprogress = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'url' => (new \moodle_url('/local/learnlogic/problem.php', ['id' => $row->id]))->out(false),
            ];
        }

        return [
            'xp' => $xp,
            'streak' => $streak ? (int) $streak->currentstreak : 0,
            'longest' => $streak ? (int) $streak->longest : 0,
            'solved' => $solved,
            'inprogress' => $inprogress,
        ];
    }

    /**
     * @param int $userid
     * @return array
     */
    private static function codelab_stats(int $userid): array {
        global $DB;
        $empty = ['xp' => 0, 'streak' => 0, 'longest' => 0, 'solved' => 0, 'inprogress' => null];
        if (!self::table_exists('local_nexcodelab_userxp')) {
            return $empty;
        }
        $xp = (int) ($DB->get_field('local_nexcodelab_userxp', 'xp', ['userid' => $userid]) ?: 0);
        $streak = $DB->get_record('local_nexcodelab_streak', ['userid' => $userid]);
        $solved = 0;
        if (self::table_exists('local_nexcodelab_mission_progress')) {
            $solved = (int) $DB->count_records('local_nexcodelab_mission_progress', [
                'userid' => $userid,
                'completed' => 1,
            ]);
        }

        $inprogress = null;
        if (self::table_exists('local_nexcodelab_mission')) {
            $sql = "SELECT m.id, m.name, p.currentstep
                      FROM {local_nexcodelab_mission_progress} p
                      JOIN {local_nexcodelab_mission} m ON m.id = p.missionid
                     WHERE p.userid = ? AND p.completed = 0 AND m.status = 'ready'
                       AND p.currentstep > 0
                  ORDER BY p.timemodified DESC";
            $row = $DB->get_record_sql($sql, [$userid]);
            if (!$row) {
                // Any mission with a passed step attempt but not complete.
                $sql2 = "SELECT m.id, m.name
                           FROM {local_nexcodelab_mission} m
                          WHERE m.status = 'ready'
                            AND EXISTS (
                                SELECT 1 FROM {local_nexcodelab_mission_step} s
                                  JOIN {local_nexcodelab_step_attempt} a ON a.stepid = s.id
                                 WHERE s.missionid = m.id AND a.userid = ? AND a.status = 'pass'
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM {local_nexcodelab_mission_progress} p2
                                 WHERE p2.missionid = m.id AND p2.userid = ? AND p2.completed = 1
                            )
                       ORDER BY m.timemodified DESC";
                $row = $DB->get_record_sql($sql2, [$userid, $userid]);
            }
            if ($row) {
                $currentstep = (int) ($row->currentstep ?? 0);
                $stepcount = 0;
                $steptitle = '';
                $stepxp = 0;
                if (self::table_exists('local_nexcodelab_mission_step')) {
                    $stepcount = (int) $DB->count_records('local_nexcodelab_mission_step', [
                        'missionid' => (int) $row->id,
                    ]);
                    $nextord = max(1, $currentstep > 0 ? $currentstep : 1);
                    $step = $DB->get_record('local_nexcodelab_mission_step', [
                        'missionid' => (int) $row->id,
                        'sortorder' => $nextord,
                    ]);
                    if (!$step && $currentstep <= 0) {
                        $step = $DB->get_record_sql(
                            "SELECT * FROM {local_nexcodelab_mission_step}
                              WHERE missionid = ? ORDER BY sortorder ASC",
                            [(int) $row->id]
                        );
                    }
                    if ($step) {
                        $steptitle = (string) $step->title;
                        $stepxp = (int) ($step->xp ?? 0);
                        if ($currentstep <= 0) {
                            $currentstep = (int) $step->sortorder;
                        }
                    }
                }
                $inprogress = [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'url' => (new \moodle_url('/local/nexcodelab/mission.php', ['id' => $row->id]))->out(false),
                    'currentstep' => $currentstep,
                    'stepcount' => $stepcount,
                    'steptitle' => $steptitle,
                    'stepxp' => $stepxp,
                ];
            }
        }

        return [
            'xp' => $xp,
            'streak' => $streak ? (int) $streak->currentstreak : 0,
            'longest' => $streak ? (int) $streak->longest : 0,
            'solved' => $solved,
            'inprogress' => $inprogress,
        ];
    }

    /**
     * @param int $userid
     * @return array[]
     */
    private static function course_cards(int $userid): array {
        $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, visible');
        $out = [];
        foreach ($courses as $c) {
            if (empty($c->visible) && !has_capability('moodle/course:viewhiddencourses', \context_course::instance($c->id))) {
                continue;
            }
            $progress = 0;
            if (class_exists('\core_completion\progress')) {
                try {
                    $progress = (int) round(\core_completion\progress::get_course_progress_percentage($c, $userid) ?? 0);
                } catch (\Throwable $e) {
                    $progress = 0;
                }
            }
            $out[] = [
                'id' => (int) $c->id,
                'name' => format_string($c->fullname),
                'progress' => $progress,
                'url' => (new \moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
                'source' => 'course',
                'cta' => $progress > 0 ? get_string('continue', 'local_nexdashboard') : get_string('start', 'local_nexdashboard'),
            ];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param array $courses
     * @param array $practice
     * @param array $codelab
     * @return array[]
     */
    private static function continue_cards(int $userid, array $courses, array $practice, array $codelab): array {
        $cards = [];
        if (!empty($codelab['inprogress'])) {
            $ip = $codelab['inprogress'];
            $sub = get_string('codelab', 'local_nexdashboard');
            if (!empty($ip['currentstep']) && !empty($ip['stepcount'])) {
                $sub = get_string('stepof', 'local_nexdashboard', (object) [
                    'step' => $ip['currentstep'],
                    'total' => $ip['stepcount'],
                ]);
                if (!empty($ip['steptitle'])) {
                    $sub .= ' · ' . $ip['steptitle'];
                }
            }
            $cards[] = [
                'title' => $ip['name'],
                'subtitle' => $sub,
                'url' => $ip['url'],
                'source' => 'codelab',
                'progress' => (!empty($ip['stepcount']) && $ip['stepcount'] > 0)
                    ? (int) round((max(0, $ip['currentstep'] - 1) / $ip['stepcount']) * 100)
                    : 0,
                'cta' => get_string('continue', 'local_nexdashboard'),
                'badge' => 'CodeLab',
            ];
        }
        if (!empty($practice['inprogress'])) {
            $cards[] = [
                'title' => $practice['inprogress']['name'],
                'subtitle' => get_string('practice', 'local_nexdashboard'),
                'url' => $practice['inprogress']['url'],
                'source' => 'practice',
                'progress' => 0,
                'cta' => get_string('continue', 'local_nexdashboard'),
                'badge' => 'Practice',
            ];
        }

        // Prefer courses already in progress; avoid a wall of 0% "Start" cards.
        $activecourses = array_values(array_filter($courses, static function ($c) {
            return (int) ($c['progress'] ?? 0) > 0 && (int) ($c['progress'] ?? 0) < 100;
        }));
        usort($activecourses, static function ($a, $b) {
            return ((int) $b['progress']) <=> ((int) $a['progress']);
        });
        $freshcourses = array_values(array_filter($courses, static function ($c) {
            return (int) ($c['progress'] ?? 0) === 0;
        }));

        foreach ($activecourses as $c) {
            if (count($cards) >= 5) {
                break;
            }
            $cards[] = [
                'title' => $c['name'],
                'subtitle' => get_string('courses', 'local_nexdashboard'),
                'url' => $c['url'],
                'source' => 'course',
                'progress' => $c['progress'],
                'cta' => $c['cta'],
                'badge' => 'Course',
            ];
        }
        foreach ($freshcourses as $c) {
            if (count($cards) >= 5) {
                break;
            }
            $cards[] = [
                'title' => $c['name'],
                'subtitle' => get_string('courses', 'local_nexdashboard'),
                'url' => $c['url'],
                'source' => 'course',
                'progress' => 0,
                'cta' => $c['cta'],
                'badge' => 'Course',
            ];
        }
        return array_slice($cards, 0, 5);
    }

    /**
     * @param array $continue
     * @param array $practice
     * @param array $codelab
     * @param array $skills
     * @param array $stuck
     * @return array
     */
    private static function next_action(
        array $continue,
        array $practice,
        array $codelab,
        array $skills = [],
        array $stuck = []
    ): array {
        if (!empty($codelab['inprogress'])) {
            $ip = $codelab['inprogress'];
            $detail = get_string('resumemission', 'local_nexdashboard');
            if (!empty($ip['currentstep']) && !empty($ip['stepcount'])) {
                $detail = get_string('resumestepdetail', 'local_nexdashboard', (object) [
                    'step' => $ip['currentstep'],
                    'total' => $ip['stepcount'],
                    'xp' => (int) ($ip['stepxp'] ?? 0),
                ]);
            }
            return [
                'title' => $ip['name'],
                'detail' => $detail,
                'url' => $ip['url'],
                'cta' => get_string('continue', 'local_nexdashboard'),
            ];
        }
        if (!empty($practice['inprogress'])) {
            return [
                'title' => $practice['inprogress']['name'],
                'detail' => get_string('finishpractice', 'local_nexdashboard'),
                'url' => $practice['inprogress']['url'],
                'cta' => get_string('continue', 'local_nexdashboard'),
            ];
        }
        if ($stuck) {
            $s = $stuck[0];
            return [
                'title' => $s['title'],
                'detail' => $s['detail'],
                'url' => $s['url'],
                'cta' => get_string('retry', 'local_nexdashboard'),
            ];
        }
        if ($skills) {
            $weak = $skills[0];
            return [
                'title' => get_string('strengthen', 'local_nexdashboard', $weak['name']),
                'detail' => get_string('strengthen_detail', 'local_nexdashboard', $weak['accuracy']),
                'url' => $weak['url'],
                'cta' => get_string('practicebtn', 'local_nexdashboard'),
            ];
        }
        if ($continue) {
            $c = $continue[0];
            return [
                'title' => $c['title'],
                'detail' => $c['subtitle'],
                'url' => $c['url'],
                'cta' => $c['cta'],
            ];
        }
        // Fallback: open catalogs.
        if (self::table_exists('local_nexcodelab_mission')) {
            return [
                'title' => get_string('codelab', 'local_nexdashboard'),
                'detail' => get_string('nothingtodo', 'local_nexdashboard'),
                'url' => (new \moodle_url('/local/nexcodelab/index.php'))->out(false),
                'cta' => get_string('start', 'local_nexdashboard'),
            ];
        }
        if (self::table_exists('local_learnlogic_problem')) {
            return [
                'title' => get_string('practice', 'local_nexdashboard'),
                'detail' => get_string('nothingtodo', 'local_nexdashboard'),
                'url' => (new \moodle_url('/local/learnlogic/index.php'))->out(false),
                'cta' => get_string('start', 'local_nexdashboard'),
            ];
        }
        return [
            'title' => get_string('courses', 'local_nexdashboard'),
            'detail' => get_string('nocourses', 'local_nexdashboard'),
            'url' => (new \moodle_url('/my/courses.php'))->out(false),
            'cta' => get_string('viewall', 'local_nexdashboard'),
        ];
    }

    /**
     * Analytics charts for daily / weekly / monthly × xp / solved [/ time].
     *
     * @param int $userid
     * @param bool $includetime Time series is expensive — omit on first paint.
     * @return array
     */
    private static function analytics_charts(int $userid, bool $includetime = true): array {
        $out = [];
        $metrics = $includetime ? ['xp', 'solved', 'time'] : ['xp', 'solved'];
        foreach (['daily', 'weekly', 'monthly'] as $period) {
            $out[$period] = [];
            foreach ($metrics as $metric) {
                $out[$period][$metric] = self::metric_series($userid, $period, $metric);
            }
            if (!$includetime) {
                // Placeholder so the metric selector still works before async fill.
                $out[$period]['time'] = [
                    'series' => [],
                    'avg' => 0.0,
                    'trend' => 0,
                    'avgLabel' => get_string('perweek', 'local_nexdashboard'),
                ];
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param string $period daily|weekly|monthly
     * @param string $metric xp|solved|time
     * @return array{series:array,avg:float,trend:int,avgLabel:string}
     */
    private static function metric_series(int $userid, string $period, string $metric): array {
        global $DB;

        $buckets = self::period_buckets($period);
        if (!$buckets) {
            return ['series' => [], 'avg' => 0, 'trend' => 0, 'avgLabel' => ''];
        }
        $earliest = $buckets[0]['start'];

        if ($metric === 'xp') {
            foreach (self::xp_events_since($userid, $earliest) as $ev) {
                self::add_to_bucket($buckets, (int) $ev['ts'], (int) $ev['amount']);
            }
        } else if ($metric === 'solved') {
            // NexPractice (+ BattleGround wins + completed interviews).
            foreach (self::practice_first_solve_times($userid, $earliest) as $ts) {
                self::add_to_bucket($buckets, $ts, 1);
            }
            // CodeLab: passed step attempts.
            if (self::table_exists('local_nexcodelab_step_attempt')) {
                $rows = $DB->get_records_sql(
                    "SELECT id, timecreated FROM {local_nexcodelab_step_attempt}
                      WHERE userid = ? AND status = 'pass' AND timecreated >= ?",
                    [$userid, $earliest]
                );
                foreach ($rows as $r) {
                    self::add_to_bucket($buckets, (int) $r->timecreated, 1);
                }
            }
            // Course coding (CodeRunner) problems first solved in the window.
            foreach (self::course_coding_first_solve_times($userid, $earliest) as $ts) {
                self::add_to_bucket($buckets, $ts, 1);
            }
        } else { // Time Spent — tracked + pre-tracking log-gap.
            self::add_site_timespent_to_buckets($buckets, $userid, $earliest);
        }

        $series = [];
        $vals = [];
        foreach ($buckets as $b) {
            $v = (int) $b['value'];
            $series[] = ['label' => $b['label'], 'value' => $v];
            $vals[] = $v;
        }
        // Mean across every bucket in the chart (zeros included), same idea as
        // NexReports average = period total / number of days.
        $avg = count($vals) ? array_sum($vals) / count($vals) : 0;
        $curr = $vals[count($vals) - 1] ?? 0;
        $prevvals = array_slice($vals, 0, -1);
        $prevavg = count($prevvals) ? array_sum($prevvals) / count($prevvals) : 0;
        $trend = 0;
        if ($prevavg > 0) {
            $trend = (int) round((($curr - $prevavg) / $prevavg) * 100);
        } else if ($curr > 0) {
            $trend = 100;
        }

        $avglabels = [
            'daily' => get_string('perday', 'local_nexdashboard'),
            'weekly' => get_string('perweek', 'local_nexdashboard'),
            'monthly' => get_string('permonth', 'local_nexdashboard'),
        ];

        return [
            'series' => $series,
            'avg' => (float) $avg,
            'trend' => $trend,
            'avgLabel' => $avglabels[$period] ?? get_string('perweek', 'local_nexdashboard'),
        ];
    }

    /**
     * XP events since a timestamp (memoized for the broadest window).
     *
     * @param int $userid
     * @param int $since
     * @return array<int,array{ts:int,amount:int}>
     */
    private static function xp_events_since(int $userid, int $since): array {
        $cachekey = 'xpevents:' . $userid;
        if (!isset(self::$memo[$cachekey])) {
            global $DB;
            $all = [];
            // Cover monthly chart (~6 months) once.
            $floor = time() - (200 * DAYSECS);
            foreach (['local_learnlogic_xpevent', 'local_nexcodelab_xpevent'] as $table) {
                if (!self::table_exists($table)) {
                    continue;
                }
                $rows = $DB->get_records_sql(
                    "SELECT id, amount, timecreated FROM {" . $table . "}
                      WHERE userid = ? AND timecreated >= ?",
                    [$userid, $floor]
                );
                foreach ($rows as $r) {
                    $all[] = [
                        'ts' => (int) $r->timecreated,
                        'amount' => (int) $r->amount,
                    ];
                }
            }
            self::$memo[$cachekey] = $all;
        }

        if ($since <= 0) {
            return self::$memo[$cachekey];
        }
        $out = [];
        foreach (self::$memo[$cachekey] as $ev) {
            if ($ev['ts'] >= $since) {
                $out[] = $ev;
            }
        }
        return $out;
    }

    /**
     * @param string $period
     * @return array[]
     */
    private static function period_buckets(string $period): array {
        $now = time();
        $buckets = [];
        if ($period === 'daily') {
            for ($i = 13; $i >= 0; $i--) {
                $start = usergetmidnight(strtotime('-' . $i . ' days', $now));
                $buckets[] = [
                    'start' => $start,
                    'end' => $start + DAYSECS - 1,
                    'label' => userdate($start, '%b %e'),
                    'value' => 0,
                ];
            }
        } else if ($period === 'monthly') {
            $basemonth = strtotime('first day of this month 00:00:00', $now);
            for ($i = 5; $i >= 0; $i--) {
                $start = strtotime('-' . $i . ' months', $basemonth);
                $end = strtotime('+1 month', $start) - 1;
                $buckets[] = [
                    'start' => $start,
                    'end' => $end,
                    'label' => userdate($start, '%b %Y'),
                    'value' => 0,
                ];
            }
        } else { // weekly
            for ($i = 7; $i >= 0; $i--) {
                $start = strtotime('-' . $i . ' weeks', strtotime('monday this week', $now));
                $buckets[] = [
                    'start' => $start,
                    'end' => $start + (7 * DAYSECS) - 1,
                    'label' => get_string('weekof', 'local_nexdashboard', userdate($start, '%b %e')),
                    'value' => 0,
                ];
            }
        }
        return $buckets;
    }

    /**
     * @param array $buckets
     * @param int $ts
     * @param int $amount
     */
    private static function add_to_bucket(array &$buckets, int $ts, int $amount): void {
        foreach ($buckets as &$b) {
            if ($ts >= $b['start'] && $ts <= $b['end']) {
                $b['value'] += $amount;
                return;
            }
        }
        unset($b);
    }

    /**
     * @deprecated kept for callers — use analytics_charts
     * @param int $userid
     * @return array{series:array,avg:float,trend:int}
     */
    private static function weekly_xp(int $userid): array {
        $bundle = self::metric_series($userid, 'weekly', 'xp');
        $series = [];
        foreach ($bundle['series'] as $p) {
            $series[] = ['label' => $p['label'], 'xp' => (int) $p['value']];
        }
        return ['series' => $series, 'avg' => $bundle['avg'], 'trend' => $bundle['trend']];
    }

    /**
     * Collect midnight timestamps with meaningful learning activity.
     * Counts Practice, CodeLab, course completions, submitted course quizzes/tests,
     * finished battles played, and NexInterview sessions taken.
     * Does not count page visits or NexReports dwell time.
     *
     * @param int $userid
     * @param int $since Unix time lower bound
     * @return array<int,bool> Map of user-midnight => true
     */
    private static function activity_day_map(int $userid, int $since): array {
        $key = 'actdays:' . $userid . ':' . $since;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $active = [];
        $mark = static function(int $ts) use (&$active): void {
            if ($ts > 0) {
                $active[usergetmidnight($ts)] = true;
            }
        };

        foreach (['local_learnlogic_xpevent', 'local_nexcodelab_xpevent',
                  'local_learnlogic_submission', 'local_nexcodelab_step_attempt'] as $table) {
            if (!self::table_exists($table)) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT id, timecreated FROM {" . $table . "}
                  WHERE userid = ? AND timecreated >= ?",
                [$userid, $since],
                0,
                4000
            );
            foreach ($rows as $r) {
                $mark((int) $r->timecreated);
            }
        }

        // Course module completions (finished activities — not mere views).
        if (self::table_exists('course_modules_completion')) {
            $rows = $DB->get_records_sql(
                "SELECT id, timemodified
                   FROM {course_modules_completion}
                  WHERE userid = ? AND completionstate > 0 AND timemodified >= ?",
                [$userid, $since],
                0,
                2000
            );
            foreach ($rows as $r) {
                $mark((int) $r->timemodified);
            }
        }

        // Course quiz / test submissions (finished attempts only).
        if (self::table_exists('quiz_attempts')) {
            try {
                $rows = $DB->get_records_sql(
                    "SELECT id, timefinish
                       FROM {quiz_attempts}
                      WHERE userid = ?
                        AND preview = 0
                        AND timefinish > 0
                        AND timefinish >= ?",
                    [$userid, $since],
                    0,
                    2000
                );
                foreach ($rows as $r) {
                    $mark((int) $r->timefinish);
                }
            } catch (\Throwable $e) {
                debugging('nexdashboard streak quiz attempts failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Finished BattleGround battles the learner played (win or lose).
        if (self::table_exists('local_nexbattleground_player')
                && self::table_exists('local_nexbattleground_battle')) {
            try {
                $rows = $DB->get_records_sql(
                    "SELECT b.id, b.timefinish
                       FROM {local_nexbattleground_player} p
                       JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
                      WHERE p.userid = ?
                        AND b.status = 'finished'
                        AND b.outcome NOT IN ('declined', 'cancelled')
                        AND b.timefinish > 0
                        AND b.timefinish >= ?",
                    [$userid, $since],
                    0,
                    1000
                );
                foreach ($rows as $r) {
                    $mark((int) $r->timefinish);
                }
            } catch (\Throwable $e) {
                debugging('nexdashboard streak battles failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // NexInterview sessions taken (not abandoned).
        if (self::table_exists('local_nexinterview_attempt')) {
            try {
                $rows = $DB->get_records_sql(
                    "SELECT id, timecompleted, timecreated
                       FROM {local_nexinterview_attempt}
                      WHERE userid = ?
                        AND status <> 'abandoned'
                        AND (
                            (timecompleted > 0 AND timecompleted >= ?)
                            OR (timecreated >= ?)
                        )",
                    [$userid, $since, $since],
                    0,
                    1000
                );
                foreach ($rows as $r) {
                    $ts = (int) ($r->timecompleted ?? 0);
                    if ($ts <= 0) {
                        $ts = (int) ($r->timecreated ?? 0);
                    }
                    $mark($ts);
                }
            } catch (\Throwable $e) {
                debugging('nexdashboard streak interviews failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        self::$memo[$key] = $active;
        return $active;
    }

    /**
     * Combined learning streak from meaningful activity days.
     *
     * @param int $userid
     * @return array{current:int,longest:int}
     */
    private static function learning_streak(int $userid): array {
        $today = usergetmidnight(time());
        $since = $today - (120 * DAYSECS);
        $active = self::activity_day_map($userid, $since);
        if (!$active) {
            return ['current' => 0, 'longest' => 0];
        }

        $days = array_keys($active);
        rsort($days, SORT_NUMERIC);

        // Current streak: must include today or yesterday.
        $current = 0;
        $cursor = $today;
        if (empty($active[$today])) {
            $cursor = $today - DAYSECS;
            if (empty($active[$cursor])) {
                $current = 0;
            }
        }
        if (!empty($active[$cursor])) {
            while (!empty($active[$cursor])) {
                $current++;
                $cursor -= DAYSECS;
            }
        }

        // Longest consecutive run in the window.
        sort($days, SORT_NUMERIC);
        $longest = 1;
        $run = 1;
        for ($i = 1; $i < count($days); $i++) {
            if ($days[$i] === $days[$i - 1] + DAYSECS) {
                $run++;
                $longest = max($longest, $run);
            } else if ($days[$i] !== $days[$i - 1]) {
                $run = 1;
            }
        }
        $longest = max($longest, $current);

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * Current week Su–Sa activity flags (Practice, CodeLab, courses).
     *
     * @param int $userid
     * @return array[]
     */
    private static function streak_week_days(int $userid): array {
        $labels = ['Su', 'M', 'Tu', 'W', 'Th', 'F', 'Sa'];
        $today = usergetmidnight(time());
        $dow = (int) userdate($today, '%w'); // 0=Sun
        $weekstart = $today - ($dow * DAYSECS);
        $active = self::activity_day_map($userid, $weekstart);

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekstart + ($i * DAYSECS);
            $out[] = [
                'label' => $labels[$i],
                'active' => !empty($active[$day]),
                'isToday' => $day === $today,
            ];
        }
        return $out;
    }

    /**
     * Approximate global rank using summed Practice + CodeLab XP.
     *
     * @param int $userid
     * @param int $xp
     * @return int
     */
    private static function combined_rank(int $userid, int $xp): int {
        global $DB;
        if ($xp <= 0) {
            return 0;
        }
        $parts = [];
        if (self::table_exists('local_learnlogic_userxp')) {
            $parts[] = "SELECT userid, xp FROM {local_learnlogic_userxp}";
        }
        if (self::table_exists('local_nexcodelab_userxp')) {
            $parts[] = "SELECT userid, xp FROM {local_nexcodelab_userxp}";
        }
        if (!$parts) {
            return 0;
        }
        $union = implode(' UNION ALL ', $parts);
        $sql = "SELECT COUNT(*) FROM (
                    SELECT userid, SUM(xp) AS total
                      FROM ($union) u
                  GROUP BY userid
                    HAVING SUM(xp) > ?
                ) better";
        try {
            return 1 + (int) $DB->count_records_sql($sql, [$xp]);
        } catch (\Throwable $e) {
            // Fallback if nested UNION is unsupported on this DB.
            $better = 0;
            if (self::table_exists('local_learnlogic_userxp')) {
                $better = max($better, (int) $DB->count_records_select('local_learnlogic_userxp', 'xp > ?', [$xp]));
            }
            if (self::table_exists('local_nexcodelab_userxp')) {
                $better = max($better, (int) $DB->count_records_select('local_nexcodelab_userxp', 'xp > ?', [$xp]));
            }
            return 1 + $better;
        }
    }

    /**
     * Weak Practice tags by acceptance rate (min attempts).
     *
     * @param int $userid
     * @return array[]
     */
    private static function skill_heatmap(int $userid): array {
        global $DB;
        if (!self::table_exists('local_learnlogic_tag')
            || !self::table_exists('local_learnlogic_problem_tag')
            || !self::table_exists('local_learnlogic_submission')) {
            return [];
        }

        $sql = "SELECT t.id, t.name,
                       COUNT(s.id) AS attempts,
                       SUM(CASE WHEN s.status = 'ACCEPTED' THEN 1 ELSE 0 END) AS accepted
                  FROM {local_learnlogic_tag} t
                  JOIN {local_learnlogic_problem_tag} pt ON pt.tagid = t.id
                  JOIN {local_learnlogic_submission} s ON s.problemid = pt.problemid AND s.userid = ?
              GROUP BY t.id, t.name
                HAVING COUNT(s.id) >= 2
              ORDER BY (SUM(CASE WHEN s.status = 'ACCEPTED' THEN 1 ELSE 0 END) * 1.0 / COUNT(s.id)) ASC,
                       COUNT(s.id) DESC";
        try {
            $rows = $DB->get_records_sql($sql, [$userid], 0, 6);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $attempts = (int) $r->attempts;
            $accepted = (int) $r->accepted;
            $accuracy = $attempts > 0 ? (int) round(($accepted / $attempts) * 100) : 0;
            $url = (new \moodle_url('/local/learnlogic/index.php'))->out(false);
            // Prefer an unsolved problem that carries this tag.
            $suggest = $DB->get_record_sql(
                "SELECT p.id, p.name
                   FROM {local_learnlogic_problem} p
                   JOIN {local_learnlogic_problem_tag} pt ON pt.problemid = p.id
                  WHERE pt.tagid = ? AND p.status = 'ready'
                    AND NOT EXISTS (
                        SELECT 1 FROM {local_learnlogic_submission} s
                         WHERE s.problemid = p.id AND s.userid = ? AND s.status = 'ACCEPTED'
                    )
               ORDER BY p.timemodified DESC",
                [(int) $r->id, $userid]
            );
            if ($suggest) {
                $url = (new \moodle_url('/local/learnlogic/problem.php', ['id' => $suggest->id]))->out(false);
            }
            $out[] = [
                'id' => (int) $r->id,
                'name' => format_string($r->name),
                'attempts' => $attempts,
                'accepted' => $accepted,
                'accuracy' => $accuracy,
                'url' => $url,
            ];
        }
        return $out;
    }

    /**
     * CodeLab mission completion by track.
     *
     * @param int $userid
     * @return array[]
     */
    private static function track_progress(int $userid): array {
        global $DB;
        if (!self::table_exists('local_nexcodelab_mission')) {
            return [];
        }

        $labels = [
            'wrangling' => 'Wrangling',
            'eda' => 'EDA',
            'ml' => 'ML',
            'nlp' => 'NLP',
        ];
        if (function_exists('local_nexcodelab_tracks')) {
            $keys = local_nexcodelab_tracks();
        } else {
            $keys = array_keys($labels);
        }

        $out = [];
        foreach ($keys as $track) {
            $total = (int) $DB->count_records_select(
                'local_nexcodelab_mission',
                "track = ? AND status IN ('ready', 'published')",
                [$track]
            );
            if ($total <= 0) {
                continue;
            }
            $done = 0;
            if (self::table_exists('local_nexcodelab_mission_progress')) {
                $done = (int) $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {local_nexcodelab_mission_progress} p
                       JOIN {local_nexcodelab_mission} m ON m.id = p.missionid
                      WHERE p.userid = ?
                        AND p.completed = 1
                        AND m.track = ?
                        AND m.status IN ('ready', 'published')",
                    [$userid, $track]
                );
            }
            $label = $labels[$track] ?? ucfirst($track);
            if (get_string_manager()->string_exists('track_' . $track, 'local_nexcodelab')) {
                $label = get_string('track_' . $track, 'local_nexcodelab');
            }
            $out[] = [
                'key' => $track,
                'label' => $label,
                'done' => $done,
                'total' => $total,
                'pct' => (int) round(($done / $total) * 100),
                'url' => (new \moodle_url('/local/nexcodelab/index.php', ['track' => $track]))->out(false),
            ];
        }
        return $out;
    }

    /**
     * Items that need attention (many fails, not yet solved).
     *
     * @param int $userid
     * @return array[]
     */
    private static function stuck_items(int $userid): array {
        global $DB;
        $out = [];

        if (self::table_exists('local_learnlogic_submission') && self::table_exists('local_learnlogic_problem')) {
            $sql = "SELECT p.id, p.name, COUNT(s.id) AS fails
                      FROM {local_learnlogic_problem} p
                      JOIN {local_learnlogic_submission} s
                        ON s.problemid = p.id AND s.userid = ? AND s.status <> 'ACCEPTED'
                     WHERE p.status = 'ready'
                       AND NOT EXISTS (
                            SELECT 1 FROM {local_learnlogic_submission} s2
                             WHERE s2.problemid = p.id AND s2.userid = ? AND s2.status = 'ACCEPTED'
                       )
                  GROUP BY p.id, p.name
                    HAVING COUNT(s.id) >= 2
                  ORDER BY COUNT(s.id) DESC, MAX(s.timecreated) DESC";
            try {
                $rows = $DB->get_records_sql($sql, [$userid, $userid], 0, 2);
                foreach ($rows as $r) {
                    $url = (new \moodle_url('/local/learnlogic/problem.php', ['id' => $r->id]))->out(false);
                    $out[] = [
                        'title' => $r->name,
                        'detail' => get_string('stuckpractice', 'local_nexdashboard', (int) $r->fails),
                        'url' => $url,
                        'source' => 'practice',
                        'fails' => (int) $r->fails,
                        'cta' => get_string('retry', 'local_nexdashboard'),
                        'helpCta' => get_string('askforhelp', 'local_nexdashboard'),
                        'helpUrl' => (new \moodle_url('/message/index.php'))->out(false),
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        if (self::table_exists('local_nexcodelab_step_attempt')
            && self::table_exists('local_nexcodelab_mission_step')
            && self::table_exists('local_nexcodelab_mission')) {
            $sql = "SELECT m.id AS missionid, m.name AS missionname, s.id AS stepid, s.title AS steptitle,
                           s.sortorder, COUNT(a.id) AS fails
                      FROM {local_nexcodelab_step_attempt} a
                      JOIN {local_nexcodelab_mission_step} s ON s.id = a.stepid
                      JOIN {local_nexcodelab_mission} m ON m.id = s.missionid
                     WHERE a.userid = ? AND a.status = 'fail' AND m.status IN ('ready', 'published')
                       AND NOT EXISTS (
                            SELECT 1 FROM {local_nexcodelab_step_attempt} a2
                             WHERE a2.stepid = s.id AND a2.userid = ? AND a2.status = 'pass'
                       )
                  GROUP BY m.id, m.name, s.id, s.title, s.sortorder
                    HAVING COUNT(a.id) >= 2
                  ORDER BY COUNT(a.id) DESC, MAX(a.timecreated) DESC";
            try {
                $rows = $DB->get_records_sql($sql, [$userid, $userid], 0, 2);
                foreach ($rows as $r) {
                    $url = (new \moodle_url('/local/nexcodelab/mission.php', ['id' => $r->missionid]))->out(false);
                    $out[] = [
                        'title' => $r->missionname,
                        'detail' => get_string('stuckcodelab', 'local_nexdashboard', (object) [
                            'step' => $r->steptitle,
                            'fails' => (int) $r->fails,
                        ]),
                        'url' => $url,
                        'source' => 'codelab',
                        'fails' => (int) $r->fails,
                        'cta' => get_string('retry', 'local_nexdashboard'),
                        'helpCta' => get_string('askforhelp', 'local_nexdashboard'),
                        'helpUrl' => (new \moodle_url('/message/index.php'))->out(false),
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        usort($out, static function ($a, $b) {
            return ($b['fails'] ?? 0) <=> ($a['fails'] ?? 0);
        });
        return array_slice($out, 0, 3);
    }

    /**
     * Site-level online users (super admin only) — mirrors block_online_users.
     *
     * @param int $viewerid
     * @return array
     */
    private static function online_users(int $viewerid): array {
        global $DB, $CFG, $PAGE;

        $empty = [
            'enabled' => false,
            'count' => 0,
            'period' => '',
            'users' => [],
            'url' => '',
        ];
        if (!is_siteadmin($viewerid)) {
            return $empty;
        }

        $minutes = !empty($CFG->block_online_users_timetosee)
            ? (int) $CFG->block_online_users_timetosee
            : 5;
        if ($minutes < 1) {
            $minutes = 5;
        }
        $timetoshow = $minutes * MINSECS;
        $now = time();
        $timefrom = 100 * (int) floor(($now - $timetoshow) / 100);
        $guestid = (int) ($CFG->siteguest ?? 1);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.picture, u.imagealt, u.email, u.lastaccess
                  FROM {user} u
                 WHERE u.lastaccess > :timefrom
                   AND u.lastaccess <= :now
                   AND u.deleted = 0
                   AND u.confirmed = 1
                   AND u.id <> :guestid
              ORDER BY u.lastaccess DESC";
        $rows = $DB->get_records_sql($sql, [
            'timefrom' => $timefrom,
            'now' => $now,
            'guestid' => $guestid,
        ], 0, 50);

        $count = (int) $DB->count_records_sql(
            "SELECT COUNT(u.id)
               FROM {user} u
              WHERE u.lastaccess > :timefrom
                AND u.lastaccess <= :now
                AND u.deleted = 0
                AND u.confirmed = 1
                AND u.id <> :guestid",
            [
                'timefrom' => $timefrom,
                'now' => $now,
                'guestid' => $guestid,
            ]
        );

        $users = [];
        foreach ($rows as $u) {
            $picurl = '';
            try {
                $userpic = new \user_picture($u);
                $userpic->size = 64;
                $picurl = $userpic->get_url($PAGE)->out(false);
            } catch (\Throwable $e) {
                $picurl = '';
            }
            $ago = max(0, $now - (int) $u->lastaccess);
            $users[] = [
                'id' => (int) $u->id,
                'name' => fullname($u),
                'picture' => $picurl,
                'haspicture' => $picurl !== '',
                'timeago' => format_time($ago),
                'url' => (new \moodle_url('/user/profile.php', ['id' => $u->id]))->out(false),
                'isMe' => (int) $u->id === $viewerid,
            ];
        }

        return [
            'enabled' => true,
            'count' => $count,
            'period' => get_string('onlineperiod', 'local_nexdashboard', $minutes),
            'users' => $users,
            'url' => (new \moodle_url('/admin/user.php'))->out(false),
        ];
    }

    /**
     * Optional college peer rank + nearby peers (overall score).
     *
     * @param int $userid
     * @return array
     */
    private static function peer_context(int $userid): array {
        global $USER;

        $institution = trim((string) ($USER->institution ?? ''));
        $data = overall_leaderboard::page(1, 5, $institution, $userid);
        $peers = [];
        foreach ($data['entries'] as $row) {
            $peers[] = [
                'rank' => (int) ($row['rank'] ?? 0),
                'name' => (string) ($row['fullname'] ?? ''),
                'xp' => (int) ($row['total'] ?? 0),
                'isMe' => !empty($row['isme']),
            ];
        }
        $rank = (int) ($data['current']['rank'] ?? 0);

        return [
            'enabled' => $rank > 0 || !empty($peers),
            'institution' => $institution,
            'rank' => $rank,
            'total' => (int) ($data['total'] ?? 0),
            'peers' => $peers,
            'url' => (new \moodle_url('/local/nexdashboard/leaderboard.php'))->out(false),
        ];
    }

    /**
     * Weekly goal with user-chosen target (3 / 5 / 7).
     *
     * @param int $userid
     * @return array
     */
    private static function weekly_goal(int $userid): array {
        global $DB;

        $weekstart = strtotime('monday this week', usergetmidnight(time()));
        $target = self::goal_target_for($userid);
        $progress = 0;
        $label = get_string('goalpractice', 'local_nexdashboard', $target);

        if (self::table_exists('local_learnlogic_submission')) {
            $progress += (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT problemid)
                   FROM {local_learnlogic_submission}
                  WHERE userid = ? AND status = 'ACCEPTED' AND timecreated >= ?",
                [$userid, $weekstart]
            );
        }
        if (self::table_exists('local_nexcodelab_step_attempt')) {
            $steps = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT stepid)
                   FROM {local_nexcodelab_step_attempt}
                  WHERE userid = ? AND status = 'pass' AND timecreated >= ?",
                [$userid, $weekstart]
            );
            $progress += $steps;
            if ($steps > 0) {
                $label = get_string('goalmixed', 'local_nexdashboard', $target);
            }
        }

        $pct = (int) min(100, round(($progress / max(1, $target)) * 100));
        return [
            'label' => $label,
            'current' => min($progress, $target),
            'target' => $target,
            'pct' => $pct,
            'done' => $progress >= $target,
            'choices' => [3, 5, 7],
        ];
    }

    /**
     * @param int $userid
     * @return int
     */
    public static function goal_target_for(int $userid): int {
        $raw = (int) get_user_preferences('local_nexdashboard_weekly_goal', 5, $userid);
        return in_array($raw, [3, 5, 7], true) ? $raw : 5;
    }

    /**
     * Persist weekly goal target for the user.
     *
     * @param int $userid
     * @param int $target
     * @return array Updated goal payload.
     */
    public static function set_weekly_goal(int $userid, int $target): array {
        if (!in_array($target, [3, 5, 7], true)) {
            throw new \invalid_parameter_exception('target must be 3, 5, or 7');
        }
        set_user_preference('local_nexdashboard_weekly_goal', $target, $userid);
        return self::weekly_goal($userid);
    }

    /**
     * Upcoming course deadlines (next 14 days).
     *
     * @param int $userid
     * @return array[]
     */
    private static function upcoming_deadlines(int $userid): array {
        global $DB;

        if (!self::table_exists('event')) {
            return [];
        }

        $courses = enrol_get_users_courses($userid, true, 'id');
        if (!$courses) {
            return [];
        }
        $ids = array_map('intval', array_keys($courses));
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $until = $now + (14 * DAYSECS);
        $params = array_merge($inparams, [
            'now' => $now,
            'until' => $until,
            'uid' => $userid,
        ]);

        $sql = "SELECT e.id, e.name, e.timestart, e.courseid, e.eventtype, e.modulename, e.instance, e.uuid
                  FROM {event} e
                 WHERE e.courseid {$insql}
                   AND e.timestart >= :now AND e.timestart <= :until
                   AND (e.userid = 0 OR e.userid = :uid)
                   AND (
                        e.eventtype = 'due'
                        OR e.modulename IN ('assign', 'quiz', 'workshop')
                   )
              ORDER BY e.timestart ASC";
        try {
            $rows = $DB->get_records_sql($sql, $params, 0, 5);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $url = new \moodle_url('/calendar/view.php', ['view' => 'day', 'time' => (int) $r->timestart]);
            if (!empty($r->modulename) && !empty($r->instance) && !empty($r->courseid)) {
                try {
                    $cm = get_coursemodule_from_instance($r->modulename, $r->instance, $r->courseid, false, IGNORE_MISSING);
                    if ($cm) {
                        $url = new \moodle_url('/mod/' . $r->modulename . '/view.php', ['id' => $cm->id]);
                    }
                } catch (\Throwable $e) {
                    // Keep calendar fallback.
                }
            }
            $hours = max(0, (int) round(((int) $r->timestart - $now) / 3600));
            $when = $hours < 48
                ? get_string('inhours', 'local_nexdashboard', $hours)
                : userdate((int) $r->timestart, get_string('strftimedaydatetime', 'langconfig'));
            $out[] = [
                'title' => format_string($r->name),
                'when' => $when,
                'timestart' => (int) $r->timestart,
                'url' => $url->out(false),
                'type' => (string) ($r->modulename ?: $r->eventtype),
            ];
        }
        return $out;
    }

    /**
     * Last few Practice / CodeLab actions.
     *
     * @param int $userid
     * @return array[]
     */
    private static function recent_activity(int $userid): array {
        global $DB;
        $items = [];

        if (self::table_exists('local_learnlogic_submission') && self::table_exists('local_learnlogic_problem')) {
            $sql = "SELECT s.id, s.status, s.timecreated, p.id AS problemid, p.name
                      FROM {local_learnlogic_submission} s
                      JOIN {local_learnlogic_problem} p ON p.id = s.problemid
                     WHERE s.userid = ?
                  ORDER BY s.timecreated DESC";
            try {
                foreach ($DB->get_records_sql($sql, [$userid], 0, 6) as $r) {
                    $ok = $r->status === 'ACCEPTED';
                    $items[] = [
                        'title' => $r->name,
                        'detail' => $ok
                            ? get_string('activityaccepted', 'local_nexdashboard')
                            : get_string('activityfailed', 'local_nexdashboard', $r->status),
                        'url' => (new \moodle_url('/local/learnlogic/problem.php', ['id' => $r->problemid]))->out(false),
                        'source' => 'practice',
                        'time' => (int) $r->timecreated,
                        'when' => self::relative_time((int) $r->timecreated),
                        'ok' => $ok,
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        if (self::table_exists('local_nexcodelab_step_attempt')
            && self::table_exists('local_nexcodelab_mission_step')
            && self::table_exists('local_nexcodelab_mission')) {
            $sql = "SELECT a.id, a.status, a.timecreated, m.id AS missionid, m.name AS missionname, s.title AS steptitle
                      FROM {local_nexcodelab_step_attempt} a
                      JOIN {local_nexcodelab_mission_step} s ON s.id = a.stepid
                      JOIN {local_nexcodelab_mission} m ON m.id = s.missionid
                     WHERE a.userid = ?
                  ORDER BY a.timecreated DESC";
            try {
                foreach ($DB->get_records_sql($sql, [$userid], 0, 6) as $r) {
                    $ok = $r->status === 'pass';
                    $items[] = [
                        'title' => $r->missionname,
                        'detail' => ($ok
                            ? get_string('activitysteppass', 'local_nexdashboard', $r->steptitle)
                            : get_string('activitystepfail', 'local_nexdashboard', $r->steptitle)),
                        'url' => (new \moodle_url('/local/nexcodelab/mission.php', ['id' => $r->missionid]))->out(false),
                        'source' => 'codelab',
                        'time' => (int) $r->timecreated,
                        'when' => self::relative_time((int) $r->timecreated),
                        'ok' => $ok,
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        usort($items, static function ($a, $b) {
            return ($b['time'] ?? 0) <=> ($a['time'] ?? 0);
        });
        $items = array_slice($items, 0, 6);
        foreach ($items as &$it) {
            unset($it['time']);
        }
        unset($it);
        return $items;
    }

    /**
     * Lightweight “this month” portfolio strip.
     *
     * @param int $userid
     * @return array
     */
    private static function month_summary(int $userid): array {
        global $DB;

        $start = strtotime('first day of this month 00:00:00');
        $label = userdate($start, '%B %Y');
        $xp = 0;

        // NexPractice solved this month = first solve via Practice ACCEPTED or Battle win.
        $practice = count(self::practice_first_solve_times($userid, $start));

        foreach (['local_learnlogic_xpevent', 'local_nexcodelab_xpevent'] as $table) {
            if (!self::table_exists($table)) {
                continue;
            }
            $xp += (int) $DB->get_field_sql(
                "SELECT COALESCE(SUM(amount), 0) FROM {" . $table . "} WHERE userid = ? AND timecreated >= ?",
                [$userid, $start]
            );
        }

        $coursecoding = count(self::course_coding_first_solve_times($userid, $start));
        $coursemcq = count(self::course_mcq_first_correct_times($userid, $start));
        $battleswon = self::battles_won_since($userid, $start);
        $interviews = self::interviews_completed_since($userid, $start);

        $share = get_string('monthsharetext', 'local_nexdashboard', (object) [
            'month' => $label,
            'coursecoding' => $coursecoding,
            'coursemcq' => $coursemcq,
            'practice' => $practice,
            'battles' => $battleswon,
            'interviews' => $interviews,
            'xp' => $xp,
        ]);

        return [
            'label' => $label,
            'courseCodingSolved' => $coursecoding,
            'courseMcqCorrect' => $coursemcq,
            'practiceSolved' => $practice,
            'battlesWon' => $battleswon,
            'interviewsCompleted' => $interviews,
            'xp' => $xp,
            // Legacy keys kept so older cached AMD still renders something.
            'missionsDone' => 0,
            'stepsPassed' => 0,
            'shareText' => $share,
        ];
    }

    /**
     * @param int $ts
     * @return string
     */
    private static function relative_time(int $ts): string {
        $diff = max(0, time() - $ts);
        if ($diff < MINSECS) {
            return get_string('justnow', 'local_nexdashboard');
        }
        if ($diff < HOURSECS) {
            return get_string('minutesago', 'local_nexdashboard', (int) floor($diff / MINSECS));
        }
        if ($diff < DAYSECS) {
            return get_string('hoursago', 'local_nexdashboard', (int) floor($diff / HOURSECS));
        }
        return userdate($ts, get_string('strftimedate', 'langconfig'));
    }

    /**
     * @param int $userid
     * @return int Percent 0–100
     */
    private static function combined_accuracy(int $userid): int {
        global $DB;
        $passed = 0;
        $total = 0;
        if (self::table_exists('local_learnlogic_submission')) {
            $total += (int) $DB->count_records('local_learnlogic_submission', ['userid' => $userid]);
            $passed += (int) $DB->count_records('local_learnlogic_submission', [
                'userid' => $userid,
                'status' => 'ACCEPTED',
            ]);
        }
        if (self::table_exists('local_nexcodelab_step_attempt')) {
            $total += (int) $DB->count_records('local_nexcodelab_step_attempt', ['userid' => $userid]);
            $passed += (int) $DB->count_records('local_nexcodelab_step_attempt', [
                'userid' => $userid,
                'status' => 'pass',
            ]);
        }
        if ($total <= 0) {
            return 0;
        }
        return (int) round(($passed / $total) * 100);
    }

    /**
     * All-time count for Learning Analytics "Problems Solved".
     * NexPractice (distinct ACCEPTED) + CodeLab passes + course CodeRunner solves.
     *
     * @param int $userid
     * @param int|null $coursesolved Precomputed course coding total (avoids a second query)
     * @return int
     */
    private static function analytics_solved_total(int $userid, ?int $coursesolved = null): int {
        global $DB;
        $n = self::practice_solved_total($userid);
        if (self::table_exists('local_nexcodelab_step_attempt')) {
            $n += (int) $DB->count_records('local_nexcodelab_step_attempt', [
                'userid' => $userid,
                'status' => 'pass',
            ]);
        }
        $n += $coursesolved !== null ? $coursesolved : self::course_coding_solved_total($userid);
        return $n;
    }

    /**
     * Distinct NexPractice problems solved (ACCEPTED + BattleGround wins + completed interviews).
     *
     * @param int $userid
     * @return int
     */
    private static function practice_solved_total(int $userid): int {
        return count(self::practice_first_solve_times($userid, 0));
    }

    /**
     * First-solve timestamps for NexPractice-linked activity (for charts / month windows).
     * Includes Practice ACCEPTED, BattleGround wins, and completed NexInterview sessions.
     *
     * @param int $userid
     * @param int $since
     * @return int[]
     */
    private static function practice_first_solve_times(int $userid, int $since = 0): array {
        $times = [];
        foreach (self::practice_first_solve_map($userid) as $ts) {
            if ($since > 0 && $ts < $since) {
                continue;
            }
            $times[] = $ts;
        }
        // Completed interviews count toward NexPractice (no problemid stored on attempts).
        foreach (self::interview_completed_times($userid, $since) as $ts) {
            $times[] = $ts;
        }
        return $times;
    }

    /**
     * Map of practice problemid => earliest solve time.
     * Sources: NexPractice ACCEPTED submissions + finished BattleGround wins.
     *
     * @param int $userid
     * @return array<int,int>
     */
    private static function practice_first_solve_map(int $userid): array {
        $key = 'practmap:' . $userid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $map = [];

        if (self::table_exists('local_learnlogic_submission')) {
            $rows = $DB->get_records_sql(
                "SELECT problemid, MIN(timecreated) AS firstsolved
                   FROM {local_learnlogic_submission}
                  WHERE userid = ? AND status = 'ACCEPTED' AND problemid > 0
               GROUP BY problemid",
                [$userid]
            );
            foreach ($rows as $r) {
                $pid = (int) $r->problemid;
                $ts = (int) $r->firstsolved;
                if ($pid > 0 && $ts > 0) {
                    $map[$pid] = $ts;
                }
            }
        }

        // BattleGround uses the same NexPractice problem bank; a win counts as solving it.
        if (self::table_exists('local_nexbattleground_battle')) {
            try {
                $rows = $DB->get_records_sql(
                    "SELECT problemid, MIN(timefinish) AS firstsolved
                       FROM {local_nexbattleground_battle}
                      WHERE winnerid = ?
                        AND problemid > 0
                        AND status = 'finished'
                        AND outcome NOT IN ('declined', 'cancelled')
                        AND timefinish > 0
                   GROUP BY problemid",
                    [$userid]
                );
                foreach ($rows as $r) {
                    $pid = (int) $r->problemid;
                    $ts = (int) $r->firstsolved;
                    if ($pid <= 0 || $ts <= 0) {
                        continue;
                    }
                    if (!isset($map[$pid]) || $ts < $map[$pid]) {
                        $map[$pid] = $ts;
                    }
                }
            } catch (\Throwable $e) {
                debugging('nexdashboard battle practice solve query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        self::$memo[$key] = $map;
        return $map;
    }

    /**
     * Completion timestamps for finished NexInterview sessions.
     *
     * @param int $userid
     * @param int $since
     * @return int[]
     */
    private static function interview_completed_times(int $userid, int $since = 0): array {
        $allkey = 'intvdone:' . $userid;
        if (!isset(self::$memo[$allkey])) {
            $all = [];
            if (self::table_exists('local_nexinterview_attempt')) {
                global $DB;
                try {
                    $rows = $DB->get_records_sql(
                        "SELECT id, timecompleted
                           FROM {local_nexinterview_attempt}
                          WHERE userid = ?
                            AND status = 'completed'
                            AND timecompleted > 0
                       ORDER BY timecompleted ASC",
                        [$userid]
                    );
                    foreach ($rows as $r) {
                        $ts = (int) $r->timecompleted;
                        if ($ts > 0) {
                            $all[] = $ts;
                        }
                    }
                } catch (\Throwable $e) {
                    debugging('nexdashboard interview practice times query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            self::$memo[$allkey] = $all;
        }

        if ($since <= 0) {
            return self::$memo[$allkey];
        }
        $out = [];
        foreach (self::$memo[$allkey] as $ts) {
            if ($ts >= $since) {
                $out[] = $ts;
            }
        }
        return $out;
    }

    /**
     * Distinct course CodeRunner (coding) questions the learner has ever solved.
     *
     * @param int $userid
     * @return int
     */
    private static function course_coding_solved_total(int $userid): int {
        return count(self::course_coding_first_solve_times($userid, 0));
    }

    /**
     * Enrolled course ids for a user (excludes site course).
     *
     * @param int $userid
     * @return int[]
     */
    private static function enrolled_course_ids(int $userid): array {
        $key = 'enrolled:' . $userid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $courses = enrol_get_users_courses($userid, true, 'id');
        if (!$courses) {
            self::$memo[$key] = [];
            return [];
        }
        $ids = [];
        foreach ($courses as $course) {
            $cid = (int) $course->id;
            if ($cid > 1) {
                $ids[] = $cid;
            }
        }
        self::$memo[$key] = $ids;
        return $ids;
    }

    /**
     * First-solve timestamps for course coding questions (CodeRunner).
     *
     * @param int $userid
     * @param int $since Only include first solves at/after this unix time (0 = all-time)
     * @return int[]
     */
    private static function course_coding_first_solve_times(int $userid, int $since = 0): array {
        $times = [];
        foreach (self::course_coding_first_solve_map($userid) as $ts) {
            if ($since > 0 && $ts < $since) {
                continue;
            }
            $times[] = $ts;
        }
        return $times;
    }

    /**
     * All-time map of quiz-slot key => first solve timestamp (memoized per request).
     *
     * @param int $userid
     * @return array<string,int>
     */
    private static function course_coding_first_solve_map(int $userid): array {
        $key = 'codingmap:' . $userid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $times = [];

        if (!self::table_exists('quiz')
                || !self::table_exists('quiz_attempts')
                || !self::table_exists('question_attempts')
                || !self::table_exists('question_attempt_steps')) {
            self::$memo[$key] = [];
            return [];
        }

        $courseids = self::enrolled_course_ids($userid);
        if (!$courseids) {
            self::$memo[$key] = [];
            return [];
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cc');
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $baseparams = array_merge(['userid' => $userid], $cparams);

        // Prefer EXISTS (same shape as NexReports) so step rows do not inflate the join.
        $queries = [];
        $queries[] = [
            'sql' => "SELECT $slotkey AS slotkey,
                            (SELECT MIN(qas.timecreated)
                               FROM {question_attempt_steps} qas
                              WHERE qas.questionattemptid = qa.id
                                AND qas.fraction IS NOT NULL
                                AND qas.fraction > 0) AS firstsolved
                        FROM {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course $cinsql
                        JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                       WHERE quiza.preview = 0
                         AND quiza.userid = :userid
                         AND qa.behaviour = :behaviour
                         AND EXISTS (
                                SELECT 1 FROM {question_attempt_steps} qas
                                 WHERE qas.questionattemptid = qa.id
                                   AND qas.fraction IS NOT NULL
                                   AND qas.fraction > 0
                         )
                    GROUP BY quiza.quiz, qa.slot, qa.id",
            'params' => array_merge($baseparams, [
                'behaviour' => 'adaptive_adapted_for_coderunner',
            ]),
        ];

        if (self::table_exists('question')) {
            $queries[] = [
                'sql' => "SELECT $slotkey AS slotkey,
                                (SELECT MIN(qas.timecreated)
                                   FROM {question_attempt_steps} qas
                                  WHERE qas.questionattemptid = qa.id
                                    AND qas.fraction IS NOT NULL
                                    AND qas.fraction > 0) AS firstsolved
                            FROM {quiz_attempts} quiza
                            JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course $cinsql
                            JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                            JOIN {question} q ON q.id = qa.questionid AND q.qtype = :qtype
                           WHERE quiza.preview = 0
                             AND quiza.userid = :userid
                             AND EXISTS (
                                    SELECT 1 FROM {question_attempt_steps} qas
                                     WHERE qas.questionattemptid = qa.id
                                       AND qas.fraction IS NOT NULL
                                       AND qas.fraction > 0
                             )
                        GROUP BY quiza.quiz, qa.slot, qa.id",
                'params' => array_merge($baseparams, ['qtype' => 'coderunner']),
            ];
        }

        // CodeRunner often stores pre-penalty success in -_rawfraction when fraction is 0.
        if (self::table_exists('question_attempt_step_data')) {
            $queries[] = [
                'sql' => "SELECT $slotkey AS slotkey, MIN(qas.timecreated) AS firstsolved
                            FROM {quiz_attempts} quiza
                            JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course $cinsql
                            JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                            JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                           WHERE quiza.preview = 0
                             AND quiza.userid = :userid
                             AND qasd.name = :rawfrac
                             AND (qasd.value = :one
                                  OR qasd.value LIKE :onepoint
                                  OR qasd.value LIKE :nines)
                        GROUP BY quiza.quiz, qa.slot",
                'params' => array_merge($baseparams, [
                    'rawfrac' => '-_rawfraction',
                    'one' => '1',
                    'onepoint' => '1.%',
                    'nines' => '0.999%',
                ]),
            ];
        }

        foreach ($queries as $q) {
            try {
                $rs = $DB->get_recordset_sql($q['sql'], $q['params']);
                foreach ($rs as $row) {
                    $ts = (int) ($row->firstsolved ?? 0);
                    $skey = (string) ($row->slotkey ?? '');
                    if ($skey === '' || $ts <= 0) {
                        continue;
                    }
                    if (!isset($times[$skey]) || $ts < $times[$skey]) {
                        $times[$skey] = $ts;
                    }
                }
                $rs->close();
            } catch (\Throwable $e) {
                debugging('nexdashboard course coding solve query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        self::$memo[$key] = $times;
        return $times;
    }

    /**
     * Distinct correct MCQ / TrueFalse questions in enrolled courses.
     *
     * @param int $userid
     * @return int
     */
    private static function course_mcq_correct_total(int $userid): int {
        return count(self::course_mcq_first_correct_times($userid, 0));
    }

    /**
     * First-correct timestamps for course MCQ / TrueFalse questions.
     *
     * @param int $userid
     * @param int $since
     * @return int[]
     */
    private static function course_mcq_first_correct_times(int $userid, int $since = 0): array {
        $times = [];
        foreach (self::course_mcq_first_correct_map($userid) as $ts) {
            if ($since > 0 && $ts < $since) {
                continue;
            }
            $times[] = $ts;
        }
        return $times;
    }

    /**
     * All-time map of quiz-slot key => first correct timestamp (memoized).
     *
     * @param int $userid
     * @return array<string,int>
     */
    private static function course_mcq_first_correct_map(int $userid): array {
        $key = 'mcqmap:' . $userid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $times = [];

        if (!self::table_exists('quiz')
                || !self::table_exists('quiz_attempts')
                || !self::table_exists('question_attempts')
                || !self::table_exists('question_attempt_steps')
                || !self::table_exists('question')) {
            self::$memo[$key] = [];
            return [];
        }

        $courseids = self::enrolled_course_ids($userid);
        if (!$courseids) {
            self::$memo[$key] = [];
            return [];
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $params = array_merge(['userid' => $userid], $cparams);

        $sql = "SELECT $slotkey AS slotkey,
                       (SELECT MIN(qas.timecreated)
                          FROM {question_attempt_steps} qas
                         WHERE qas.questionattemptid = qa.id
                           AND qas.fraction IS NOT NULL
                           AND qas.fraction >= 1) AS firstsolved
                  FROM {quiz_attempts} quiza
                  JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course $cinsql
                  JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                  JOIN {question} q ON q.id = qa.questionid
                 WHERE quiza.preview = 0
                   AND quiza.userid = :userid
                   AND q.qtype IN ('multichoice', 'truefalse')
                   AND EXISTS (
                        SELECT 1 FROM {question_attempt_steps} qas
                         WHERE qas.questionattemptid = qa.id
                           AND qas.fraction IS NOT NULL
                           AND qas.fraction >= 1
                   )
              GROUP BY quiza.quiz, qa.slot, qa.id";

        try {
            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $row) {
                $ts = (int) ($row->firstsolved ?? 0);
                $skey = (string) ($row->slotkey ?? '');
                if ($skey === '' || $ts <= 0) {
                    continue;
                }
                if (!isset($times[$skey]) || $ts < $times[$skey]) {
                    $times[$skey] = $ts;
                }
            }
            $rs->close();
        } catch (\Throwable $e) {
            debugging('nexdashboard course MCQ query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        self::$memo[$key] = $times;
        return $times;
    }

    /**
     * All-time finished battle wins.
     *
     * @param int $userid
     * @return int
     */
    private static function battles_won_total(int $userid): int {
        return self::battles_won_since($userid, 0);
    }

    /**
     * Finished battle wins since a unix timestamp (0 = all-time).
     *
     * @param int $userid
     * @param int $since
     * @return int
     */
    private static function battles_won_since(int $userid, int $since = 0): int {
        global $DB;
        if (!self::table_exists('local_nexbattleground_player')
                || !self::table_exists('local_nexbattleground_battle')) {
            return 0;
        }
        $params = [$userid];
        $timesql = '';
        if ($since > 0) {
            $timesql = ' AND b.timefinish >= ?';
            $params[] = $since;
        }
        try {
            return (int) $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {local_nexbattleground_player} p
                   JOIN {local_nexbattleground_battle} b ON b.id = p.battleid
                  WHERE p.userid = ?
                    AND p.result = 'win'
                    AND b.status = 'finished'
                    AND b.outcome NOT IN ('declined', 'cancelled')
                    $timesql",
                $params
            );
        } catch (\Throwable $e) {
            debugging('nexdashboard battles won query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Portfolio platform + GitHub connection stats.
     *
     * @param int $userid
     * @return array{connected:int,total:int,github:bool}
     */
    private static function portfolio_connection_stats(int $userid): array {
        $out = ['connected' => 0, 'total' => 5, 'github' => false];

        if (function_exists('local_nexportfolio_user_summary')
                && function_exists('local_nexportfolio_platforms')) {
            $summary = local_nexportfolio_user_summary($userid);
            $out['connected'] = (int) ($summary['connected'] ?? 0);
            $out['total'] = (int) ($summary['totalplatforms'] ?? count(local_nexportfolio_platforms()));
        } else if (self::table_exists('local_nexportfolio_handles')) {
            global $DB;
            $platforms = ['leetcode', 'codechef', 'codeforces', 'geeksforgeeks', 'codingninjas'];
            $out['total'] = count($platforms);
            [$insql, $params] = $DB->get_in_or_equal($platforms, SQL_PARAMS_NAMED, 'pl');
            $params['userid'] = $userid;
            $out['connected'] = (int) $DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_nexportfolio_handles}
                  WHERE userid = :userid AND handle <> '' AND platform $insql",
                $params
            );
        }

        if (self::table_exists('local_nexportfolio_github')) {
            global $DB;
            $login = (string) ($DB->get_field('local_nexportfolio_github', 'github_login', ['userid' => $userid]) ?: '');
            $out['github'] = $login !== '';
        } else if (class_exists('\\local_nexportfolio\\local\\github')) {
            try {
                $profile = \local_nexportfolio\local\github::get_profile($userid);
                $out['github'] = $profile && trim((string) ($profile->github_login ?? '')) !== '';
            } catch (\Throwable $e) {
                $out['github'] = false;
            }
        }

        return $out;
    }

    /**
     * NexInterview attempt counts (all-time).
     *
     * @param int $userid
     * @return array{taken:int,completed:int}
     */
    private static function interview_stats(int $userid): array {
        if (class_exists('\\local_nexinterview\\local\\attempts')
                && method_exists('\\local_nexinterview\\local\\attempts', 'user_stats')) {
            $stats = \local_nexinterview\local\attempts::user_stats($userid);
            return [
                'taken' => (int) ($stats['attempts'] ?? 0),
                'completed' => (int) ($stats['completed'] ?? 0),
            ];
        }
        if (!self::table_exists('local_nexinterview_attempt')) {
            return ['taken' => 0, 'completed' => 0];
        }
        global $DB;
        $taken = (int) $DB->count_records_select(
            'local_nexinterview_attempt',
            "userid = ? AND status <> 'abandoned'",
            [$userid]
        );
        $completed = (int) $DB->count_records('local_nexinterview_attempt', [
            'userid' => $userid,
            'status' => 'completed',
        ]);
        return ['taken' => $taken, 'completed' => $completed];
    }

    /**
     * Interviews completed since a unix timestamp.
     *
     * @param int $userid
     * @param int $since
     * @return int
     */
    private static function interviews_completed_since(int $userid, int $since): int {
        if (!self::table_exists('local_nexinterview_attempt')) {
            return 0;
        }
        global $DB;
        return (int) $DB->count_records_select(
            'local_nexinterview_attempt',
            'userid = ? AND status = ? AND timecompleted >= ?',
            [$userid, 'completed', $since]
        );
    }

    /** Max days of logstore history to scan for pre-tracking estimates (keeps dashboard fast). */
    private const LOGGAP_LOOKBACK_DAYS = 180;

    /**
     * All-time learning minutes — shared by hero "Learning time" and Analytics "Time Spent".
     * Measured tracking + bounded log-gap only before first real tracked second.
     *
     * @param int $userid
     * @return int
     */
    private static function learning_minutes_total(int $userid): int {
        return (int) round(self::site_timespent_seconds($userid) / MINSECS);
    }

    /**
     * @deprecated Use learning_minutes_total()
     * @param int $userid
     * @return int
     */
    private static function analytics_time_minutes_total(int $userid): int {
        return self::learning_minutes_total($userid);
    }

    /**
     * Site time spent in seconds for one user.
     *
     * @param int $userid
     * @return int
     */
    private static function site_timespent_seconds(int $userid): int {
        $key = 'sitesecs:' . $userid;
        if (isset(self::$memo[$key])) {
            return (int) self::$memo[$key];
        }

        $tracked = self::tracked_timespent_seconds($userid, 0);
        $first = self::first_tracked_timestamp();
        $lookback = self::LOGGAP_LOOKBACK_DAYS * DAYSECS;

        if ($tracked > 0 && $first > 0) {
            // Pre-tracking estimate — never scan unbounded history.
            $from = max(0, $first - $lookback);
            $total = $tracked + self::loggap_timespent_seconds($userid, $from, $first);
        } else if ($tracked > 0) {
            $total = $tracked;
        } else {
            // No measured dwell yet — short recent estimate only (not all-time logscan).
            $now = time();
            $total = self::loggap_timespent_seconds($userid, $now - (30 * DAYSECS), $now);
        }

        self::$memo[$key] = $total;
        return $total;
    }

    /**
     * Earliest site-wide timestart with timespent > 0 (NexReports first_tracked).
     *
     * @return int
     */
    private static function first_tracked_timestamp(): int {
        if (isset(self::$memo['firsttracked'])) {
            return (int) self::$memo['firsttracked'];
        }
        $ts = 0;
        if (class_exists('\\local_nexreports\\local\\tracking')
                && method_exists('\\local_nexreports\\local\\tracking', 'first_tracked')) {
            $ts = (int) \local_nexreports\local\tracking::first_tracked();
        } else if (self::table_exists('nexreports_tracking')) {
            global $DB;
            try {
                $ts = (int) $DB->get_field_sql(
                    'SELECT MIN(timestart) FROM {nexreports_tracking} WHERE timespent > 0'
                );
            } catch (\Throwable $e) {
                $ts = 0;
            }
        }
        self::$memo['firsttracked'] = $ts;
        return $ts;
    }

    /**
     * Session-gap seconds (NexReports setting when available).
     *
     * @return int
     */
    private static function timespent_session_gap(): int {
        if (isset(self::$memo['sessiongap'])) {
            return (int) self::$memo['sessiongap'];
        }
        $gap = 20 * MINSECS;
        if (class_exists('\\local_nexreports\\local\\overview')
                && method_exists('\\local_nexreports\\local\\overview', 'session_gap')) {
            $gap = (int) \local_nexreports\local\overview::session_gap();
        }
        self::$memo['sessiongap'] = $gap;
        return $gap;
    }

    /**
     * One memoized logstore scan → list of [timestamp, deltaSeconds] pairs (fallback path).
     *
     * @param int $userid
     * @param int $fromts Inclusive
     * @param int $tots Exclusive
     * @return array<int,array{0:int,1:int}>
     */
    private static function loggap_deltas(int $userid, int $fromts, int $tots): array {
        if ($userid < 1 || $fromts <= 0 || $tots <= $fromts) {
            return [];
        }
        $maxspan = self::LOGGAP_LOOKBACK_DAYS * DAYSECS;
        if (($tots - $fromts) > $maxspan) {
            $fromts = $tots - $maxspan;
        }

        $key = 'logdeltas:' . $userid . ':' . $fromts . ':' . $tots;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $out = [];
        if (!self::table_exists('logstore_standard_log')) {
            self::$memo[$key] = [];
            return [];
        }

        $gap = self::timespent_session_gap();
        $prevts = 0;
        try {
            $rs = $DB->get_recordset_sql(
                "SELECT timecreated
                   FROM {logstore_standard_log}
                  WHERE userid = :userid
                    AND timecreated >= :fromts
                    AND timecreated < :tots
               ORDER BY timecreated ASC",
                [
                    'userid' => $userid,
                    'fromts' => $fromts,
                    'tots' => $tots,
                ]
            );
            foreach ($rs as $row) {
                $ts = (int) $row->timecreated;
                if ($prevts > 0) {
                    $delta = $ts - $prevts;
                    if ($delta > 0 && $delta <= $gap) {
                        $out[] = [$prevts, $delta];
                    }
                }
                $prevts = $ts;
            }
            $rs->close();
        } catch (\Throwable $e) {
            debugging('nexdashboard loggap deltas failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $out = [];
        }

        self::$memo[$key] = $out;
        return $out;
    }

    /**
     * Sum of log-gap seconds in a bounded window.
     *
     * @param int $userid
     * @param int $fromts
     * @param int $tots
     * @return int
     */
    private static function loggap_timespent_seconds(int $userid, int $fromts, int $tots): int {
        $sum = 0;
        foreach (self::loggap_deltas($userid, $fromts, $tots) as $pair) {
            $sum += (int) $pair[1];
        }
        return $sum;
    }

    /**
     * Measured site time spent in seconds from {nexreports_tracking} only.
     *
     * @param int $userid
     * @param int $since Inclusive lower bound on timestart (0 = all-time)
     * @return int
     */
    private static function tracked_timespent_seconds(int $userid, int $since = 0): int {
        $key = 'tracksum:' . $userid . ':' . $since;
        if (isset(self::$memo[$key])) {
            return (int) self::$memo[$key];
        }

        global $DB;
        $total = 0;
        if ($userid < 1 || !self::table_exists('nexreports_tracking')) {
            self::$memo[$key] = 0;
            return 0;
        }

        $params = ['userid' => $userid];
        $timesql = '';
        if ($since > 0) {
            $timesql = ' AND timestart >= :since';
            $params['since'] = $since;
        }

        try {
            $total = (int) $DB->get_field_sql(
                "SELECT COALESCE(SUM(timespent), 0)
                   FROM {nexreports_tracking}
                  WHERE userid = :userid
                    AND timespent > 0
                    $timesql",
                $params
            );
        } catch (\Throwable $e) {
            debugging('nexdashboard tracked timespent failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $total = 0;
        }

        self::$memo[$key] = $total;
        return $total;
    }

    /**
     * Fallback bucket fill when NexReports is unavailable.
     *
     * @param array $buckets
     * @param int $userid
     * @param int $earliest
     */
    private static function add_site_timespent_to_buckets(array &$buckets, int $userid, int $earliest): void {
        if ($userid < 1 || !$buckets) {
            return;
        }

        $seconds = array_fill(0, count($buckets), 0);
        $addsecs = static function(int $ts, int $amount) use (&$seconds, &$buckets): void {
            if ($amount <= 0 || $ts <= 0) {
                return;
            }
            foreach ($buckets as $i => $b) {
                if ($ts >= $b['start'] && $ts <= $b['end']) {
                    $seconds[$i] += $amount;
                    return;
                }
            }
        };

        if (self::table_exists('nexreports_tracking')) {
            foreach (self::tracked_timespent_rows($userid, $earliest) as $row) {
                $addsecs((int) $row['timestart'], (int) $row['timespent']);
            }
        }

        $first = self::first_tracked_timestamp();
        if ($first > 0 && ($earliest <= 0 || $earliest < $first)) {
            $gapfrom = max(0, $first - (self::LOGGAP_LOOKBACK_DAYS * DAYSECS));
            foreach (self::loggap_deltas($userid, $gapfrom, $first) as $pair) {
                $ts = (int) $pair[0];
                if ($earliest > 0 && $ts < $earliest) {
                    continue;
                }
                $addsecs($ts, (int) $pair[1]);
            }
        }

        foreach ($buckets as $i => &$b) {
            $secs = (int) ($seconds[$i] ?? 0);
            if ($secs <= 0) {
                continue;
            }
            $span = max(MINSECS, ((int) $b['end'] - (int) $b['start'] + 1));
            $secs = min($secs, $span);
            $b['value'] += (int) round($secs / MINSECS);
        }
        unset($b);
    }

    /**
     * Tracking rows for a user (memoized).
     *
     * @param int $userid
     * @param int $since
     * @return array<int,array{timestart:int,timespent:int}>
     */
    private static function tracked_timespent_rows(int $userid, int $since = 0): array {
        $cachekey = 'trackrows:' . $userid;
        if (!isset(self::$memo[$cachekey])) {
            global $DB;
            $out = [];
            if ($userid > 0 && self::table_exists('nexreports_tracking')) {
                try {
                    $floor = time() - (200 * DAYSECS);
                    $rs = $DB->get_recordset_sql(
                        "SELECT timestart, timespent
                           FROM {nexreports_tracking}
                          WHERE userid = ?
                            AND timespent > 0
                            AND timestart >= ?",
                        [$userid, $floor]
                    );
                    foreach ($rs as $row) {
                        $out[] = [
                            'timestart' => (int) $row->timestart,
                            'timespent' => (int) $row->timespent,
                        ];
                    }
                    $rs->close();
                } catch (\Throwable $e) {
                    debugging('nexdashboard tracking rows failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            self::$memo[$cachekey] = $out;
        }

        if ($since <= 0) {
            return self::$memo[$cachekey];
        }
        $filtered = [];
        foreach (self::$memo[$cachekey] as $row) {
            if ($row['timestart'] >= $since) {
                $filtered[] = $row;
            }
        }
        return $filtered;
    }

    /**
     * @param int $minutes
     * @return string
     */
    private static function format_duration(int $minutes): string {
        if ($minutes <= 0) {
            return '0h 0m';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . 'h ' . $m . 'm';
    }
}
