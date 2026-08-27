<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Aggregate coding-profile stats from Nex plugins.
 *
 * @package   local_nexprofile
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexprofile\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only profile snapshot.
 */
class profile {

    /**
     * Full mustache context for a user.
     *
     * @param \stdClass $user
     * @param \moodle_page $page
     * @return array
     */
    public static function context(\stdClass $user, \moodle_page $page): array {
        global $USER, $CFG;

        $userid = (int) $user->id;
        $isown = ((int) $USER->id === $userid);

        $pic = new \user_picture($user);
        $pic->size = 256;
        $avatar = $pic->get_url($page)->out(false);

        $practice = self::practice_stats($userid);
        $course = self::course_stats($userid);
        $codelab = self::codelab_stats($userid);
        $interview = self::interview_stats($userid);
        $activity = self::aggregate_activity($userid);
        $heatmap = self::heatmap_from_days($activity['byday'], $activity['summary']);
        $languages = self::languages($userid);
        $skills = self::skills($userid);
        $recent = self::recent_submissions($userid);
        $platforms = self::portfolio_platforms($userid);
        $battle = self::battle_stats($userid);

        $bio = trim(strip_tags((string) ($user->description ?? '')));
        if (\core_text::strlen($bio) > 280) {
            $bio = \core_text::substr($bio, 0, 277) . '…';
        }

        $department = trim((string) ($user->department ?? ''));
        $institution = trim((string) ($user->institution ?? ''));
        $title = self::title_for_xp($practice['xp']);
        $streaklabel = get_string('streakchip', 'local_nexprofile', $practice['streak']);
        $xplabel = get_string('xpchip', 'local_nexprofile', $practice['xp']);
        $ranklabel = '';
        $hasrank = false;
        if ((int) $practice['rank'] > 0 && (int) $practice['xp'] > 0) {
            $hasrank = true;
            $ranklabel = get_string('rankchip', 'local_nexprofile', $practice['rank']);
        }
        $longestlabel = '';
        $haslongest = (int) $practice['longest'] > 0;
        if ($haslongest) {
            $longestlabel = get_string('longeststreakchip', 'local_nexprofile', $practice['longest']);
        }
        $acceptancelabel = '';
        $hasacceptance = (int) $practice['submissions'] > 0;
        if ($hasacceptance) {
            $acceptancelabel = get_string('acceptancechip', 'local_nexprofile', $practice['acceptance']);
        }

        $portfoliourl = '';
        if ($isown && file_exists($CFG->dirroot . '/local/nexportfolio/index.php')) {
            $portfoliourl = (new \moodle_url('/local/nexportfolio/index.php'))->out(false);
        }
        $courseurl = '';
        if (file_exists($CFG->dirroot . '/local/nexcourse/index.php')) {
            $courseurl = (new \moodle_url('/local/nexcourse/index.php'))->out(false);
        } else if (file_exists($CFG->dirroot . '/my/courses.php')) {
            $courseurl = (new \moodle_url('/my/courses.php'))->out(false);
        }
        $practiceurl = '';
        if (file_exists($CFG->dirroot . '/local/learnlogic/index.php')) {
            $practiceurl = (new \moodle_url('/local/learnlogic/index.php'))->out(false);
        }
        $codelaburl = '';
        if (file_exists($CFG->dirroot . '/local/nexcodelab/index.php')) {
            $codelaburl = (new \moodle_url('/local/nexcodelab/index.php'))->out(false);
        }
        $interviewurl = '';
        if (file_exists($CFG->dirroot . '/local/nexinterview/index.php')) {
            $interviewurl = (new \moodle_url('/local/nexinterview/index.php'))->out(false);
        }
        $battleurl = '';
        if (file_exists($CFG->dirroot . '/local/nexbattleground/index.php')) {
            $battleurl = (new \moodle_url('/local/nexbattleground/index.php'))->out(false);
        }

        $practicesolved = (int) ($practice['solved'] ?? 0);
        $remaining = max(0, $practice['totalready'] - $practicesolved);
        $solvedpct = $practice['totalready'] > 0
            ? (int) round(($practicesolved / $practice['totalready']) * 100)
            : 0;

        $classicurl = (new \moodle_url('/user/profile.php', [
            'id' => $userid,
            'nxp' => 'classic',
        ]))->out(false);

        $editurl = '';
        $settingsurl = '';
        if ($isown) {
            $editurl = (new \moodle_url('/user/edit.php', ['id' => $userid]))->out(false);
            $settingsurl = (new \moodle_url('/user/preferences.php'))->out(false);
        }

        $email = '';
        $showemail = false;
        if ($isown || !empty($user->maildisplay)) {
            $email = (string) ($user->email ?? '');
            $showemail = $email !== '';
        }

        $passtext = '';
        if (!empty($user->profile_field_passtext)) {
            $passtext = trim((string) $user->profile_field_passtext);
        }
        // Soft fallback label from institution year-ish custom fields is skipped; keep empty.

        return [
            'userid' => $userid,
            'isown' => $isown,
            'fullname' => fullname($user),
            'username' => (string) $user->username,
            'avatar' => $avatar,
            'bio' => $bio,
            'hasbio' => $bio !== '',
            'department' => $department,
            'hasdepartment' => $department !== '',
            'institution' => $institution,
            'hasinstitution' => $institution !== '',
            'passtext' => $passtext,
            'haspasstext' => $passtext !== '',
            'title' => $title,
            'streaklabel' => $streaklabel,
            'xplabel' => $xplabel,
            'hasrank' => $hasrank,
            'ranklabel' => $ranklabel,
            'haslongest' => $haslongest,
            'longestlabel' => $longestlabel,
            'hasacceptance' => $hasacceptance,
            'acceptancelabel' => $acceptancelabel,
            'followers' => 0,
            'following' => 0,
            'editurl' => $editurl,
            'settingsurl' => $settingsurl,
            'classicurl' => $classicurl,
            'showemail' => $showemail,
            'email' => $email,

            'course' => $course,
            'hascourseurl' => $courseurl !== '',
            'courseurl' => $courseurl,

            'practice' => [
                'solved' => $practicesolved,
                'totalready' => (int) $practice['totalready'],
                'remaining' => $remaining,
                'solvedpct' => $solvedpct,
                'difficulties' => $practice['difficulties'],
                'donutstyle' => $practice['donutstyle'],
                'acceptance' => (int) $practice['acceptance'],
                'submissions' => (int) $practice['submissions'],
            ],
            'haspracticeurl' => $practiceurl !== '',
            'practiceurl' => $practiceurl,

            'codelab' => $codelab,
            'hascodelaburl' => $codelaburl !== '',
            'codelaburl' => $codelaburl,

            'interview' => $interview,
            'hasinterview' => !empty($interview['available']),
            'hasinterviewurl' => $interviewurl !== '',
            'interviewurl' => $interviewurl,

            'activitytotal' => $activity['total'],
            'activitysummary' => $heatmap['summary'],
            'hasactivity' => $activity['total'] > 0,
            'activedays' => $heatmap['activedays'],

            'heatmapweeks' => $heatmap['weeks'],
            'heatmapmonths' => $heatmap['months'],
            'heatmapweekdays' => $heatmap['weekdays'],

            'languages' => $languages,
            'haslanguages' => !empty($languages),
            'skills' => $skills,
            'hasskills' => !empty($skills),

            'recent' => $recent,
            'hasrecent' => !empty($recent),

            'platforms' => $platforms,
            'hasplatforms' => !empty($platforms),
            'portfoliourl' => $portfoliourl,
            'hasportfoliourl' => $portfoliourl !== '',

            'battle' => $battle,
            'hasbattle' => !empty($battle['available']),
            'battleurl' => $battleurl,
            'hasbattleurl' => $battleurl !== '',
        ];
    }

    /**
     * Soft rank title from XP (Codolio-style).
     *
     * @param int $xp
     * @return string
     */
    public static function title_for_xp(int $xp): string {
        if ($xp >= 5000) {
            return get_string('title_legend', 'local_nexprofile');
        }
        if ($xp >= 2000) {
            return get_string('title_expert', 'local_nexprofile');
        }
        if ($xp >= 800) {
            return get_string('title_specialist', 'local_nexprofile');
        }
        if ($xp >= 300) {
            return get_string('title_coder', 'local_nexprofile');
        }
        return get_string('title_rookie', 'local_nexprofile');
    }

    /**
     * LearnLogic practice stats + difficulty bars.
     *
     * @param int $userid
     * @return array
     */
    public static function practice_stats(int $userid): array {
        global $DB;

        $emptybars = self::difficulty_bars([], []);
        $out = [
            'xp' => 0,
            'streak' => 0,
            'longest' => 0,
            'rank' => 0,
            'solved' => 0,
            'totalready' => 0,
            'submissions' => 0,
            'acceptance' => 0,
            'difficulties' => $emptybars,
            'donutstyle' => self::donut_style($emptybars),
        ];

        if (!$DB->get_manager()->table_exists('local_learnlogic_problem')) {
            return $out;
        }

        if (class_exists('\\local_learnlogic\\local\\gamification')) {
            $stats = \local_learnlogic\local\gamification::user_stats($userid);
            $out['xp'] = (int) ($stats['xp'] ?? 0);
            $out['streak'] = (int) ($stats['streak'] ?? 0);
            $out['longest'] = (int) ($stats['longest'] ?? 0);
            $out['rank'] = (int) ($stats['rank'] ?? 0);
            $out['solved'] = (int) ($stats['solved'] ?? 0);
        }

        $totals = $DB->get_records_sql(
            "SELECT LOWER(difficulty) AS difficulty, COUNT(1) AS n
               FROM {local_learnlogic_problem}
              WHERE status = 'ready'
           GROUP BY LOWER(difficulty)"
        );
        $totalmap = [];
        $totalready = 0;
        foreach ($totals as $row) {
            $key = self::norm_diff((string) $row->difficulty);
            $totalmap[$key] = (int) $row->n;
            $totalready += (int) $row->n;
        }
        $out['totalready'] = $totalready;

        $solvedmap = [];
        if ($DB->get_manager()->table_exists('local_learnlogic_submission')) {
            $solvedrows = $DB->get_records_sql(
                "SELECT LOWER(p.difficulty) AS difficulty, COUNT(DISTINCT s.problemid) AS n
                   FROM {local_learnlogic_submission} s
                   JOIN {local_learnlogic_problem} p ON p.id = s.problemid
                  WHERE s.userid = ? AND s.status = 'ACCEPTED' AND p.status = 'ready'
               GROUP BY LOWER(p.difficulty)",
                [$userid]
            );
            foreach ($solvedrows as $row) {
                $solvedmap[self::norm_diff((string) $row->difficulty)] = (int) $row->n;
            }
            $out['submissions'] = (int) $DB->count_records('local_learnlogic_submission', ['userid' => $userid]);
            $accepted = (int) $DB->count_records('local_learnlogic_submission', [
                'userid' => $userid,
                'status' => 'ACCEPTED',
            ]);
            $out['acceptance'] = $out['submissions'] > 0
                ? (int) round(($accepted / $out['submissions']) * 100)
                : 0;
            if ($out['solved'] < 1) {
                $out['solved'] = array_sum($solvedmap);
            }
        }

        $out['difficulties'] = self::difficulty_bars($solvedmap, $totalmap);
        $out['donutstyle'] = self::donut_style($out['difficulties']);
        return $out;
    }

    /**
     * NexCourse / enrolled-course coding + MCQ + test snapshot.
     *
     * @param int $userid
     * @return array
     */
    public static function course_stats(int $userid): array {
        $courseids = self::enrolled_course_ids($userid);
        $catalog = self::course_question_catalog_totals($courseids);
        return [
            'solved' => self::course_coding_solved_total($userid, $courseids),
            'mcqsolved' => self::course_mcq_correct_total($userid, $courseids),
            'testssubmitted' => self::course_tests_submitted_total($userid, $courseids),
            'codingtotal' => (int) ($catalog['coding'] ?? 0),
            'mcqtotal' => (int) ($catalog['mcq'] ?? 0),
            'enrolled' => count($courseids),
        ];
    }

    /**
     * NexInterview attempt snapshot.
     *
     * @param int $userid
     * @return array
     */
    public static function interview_stats(int $userid): array {
        global $CFG, $DB;

        $available = file_exists($CFG->dirroot . '/local/nexinterview/version.php')
            || $DB->get_manager()->table_exists('local_nexinterview_attempt');
        $empty = [
            'available' => $available,
            'taken' => 0,
            'completed' => 0,
            'avg' => 0,
            'best' => 0,
            'hasavg' => false,
            'hasbest' => false,
        ];
        if (!$available) {
            return $empty;
        }

        if (class_exists('\\local_nexinterview\\local\\attempts')
                && method_exists('\\local_nexinterview\\local\\attempts', 'user_stats')) {
            $stats = \local_nexinterview\local\attempts::user_stats($userid);
            $avg = (float) ($stats['avg'] ?? 0);
            $best = (float) ($stats['best'] ?? 0);
            return [
                'available' => true,
                'taken' => (int) ($stats['attempts'] ?? 0),
                'completed' => (int) ($stats['completed'] ?? 0),
                'avg' => (int) round($avg),
                'best' => (int) round($best),
                'hasavg' => $avg > 0,
                'hasbest' => $best > 0,
            ];
        }

        if (!$DB->get_manager()->table_exists('local_nexinterview_attempt')) {
            return $empty;
        }
        $taken = (int) $DB->count_records_select(
            'local_nexinterview_attempt',
            "userid = ? AND status <> 'abandoned'",
            [$userid]
        );
        $completed = (int) $DB->count_records('local_nexinterview_attempt', [
            'userid' => $userid,
            'status' => 'completed',
        ]);
        return [
            'available' => true,
            'taken' => $taken,
            'completed' => $completed,
            'avg' => 0,
            'best' => 0,
            'hasavg' => false,
            'hasbest' => false,
        ];
    }

    /**
     * NexCodeLab solved / XP snapshot.
     *
     * @param int $userid
     * @return array
     */
    public static function codelab_stats(int $userid): array {
        global $DB;

        $out = [
            'solved' => 0,
            'missions' => 0,
            'xp' => 0,
            'streak' => 0,
            'submissions' => 0,
            'acceptance' => 0,
        ];

        $dm = $DB->get_manager();
        if ($dm->table_exists('local_nexcodelab_userxp')) {
            $out['xp'] = (int) ($DB->get_field('local_nexcodelab_userxp', 'xp', ['userid' => $userid]) ?: 0);
        }
        if ($dm->table_exists('local_nexcodelab_streak')) {
            $streak = $DB->get_record('local_nexcodelab_streak', ['userid' => $userid]);
            if ($streak) {
                $out['streak'] = (int) ($streak->currentstreak ?? 0);
            }
        }
        if ($dm->table_exists('local_nexcodelab_mission_progress')) {
            $out['missions'] = (int) $DB->count_records('local_nexcodelab_mission_progress', [
                'userid' => $userid,
                'completed' => 1,
            ]);
        }
        if ($dm->table_exists('local_nexcodelab_submission')) {
            $out['submissions'] = (int) $DB->count_records('local_nexcodelab_submission', ['userid' => $userid]);
            $accepted = (int) $DB->count_records('local_nexcodelab_submission', [
                'userid' => $userid,
                'status' => 'ACCEPTED',
            ]);
            $out['acceptance'] = $out['submissions'] > 0
                ? (int) round(($accepted / $out['submissions']) * 100)
                : 0;
            try {
                $out['solved'] = (int) $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT problemid)
                       FROM {local_nexcodelab_submission}
                      WHERE userid = ? AND status = 'ACCEPTED'",
                    [$userid]
                );
            } catch (\Throwable $e) {
                $out['solved'] = 0;
            }
        }
        if ($out['solved'] < 1 && $out['missions'] > 0) {
            $out['solved'] = $out['missions'];
        }
        return $out;
    }

    /**
     * Collect all learning activity and bucket by calendar day.
     *
     * @param int $userid
     * @return array{byday: array, summary: array, total: int}
     */
    public static function aggregate_activity(int $userid): array {
        global $DB, $CFG;

        $since = time() - (400 * 86400);
        $byday = [];
        $totals = [
            'quiz' => 0,
            'practice' => 0,
            'practice_ac' => 0,
            'practice_wa' => 0,
            'codelab' => 0,
            'codelab_check' => 0,
            'battle' => 0,
            'portfolio' => 0,
            'total' => 0,
        ];

        $addts = function (int $ts, string $source) use ($userid, $since, &$byday, &$totals): void {
            if ($ts < 1 || $ts < $since) {
                return;
            }
            self::add_activity($byday, $totals, self::day_key($userid, $ts), $source, 1);
        };

        $addday = function (string $day, string $source, int $weight = 1) use (&$byday, &$totals): void {
            if ($day === '' || $weight < 1) {
                return;
            }
            self::add_activity($byday, $totals, $day, $source, $weight);
        };

        $dm = $DB->get_manager();

        if ($dm->table_exists('quiz_attempts')) {
            $attempts = $DB->get_records_sql(
                "SELECT timefinish, timestart, state
                   FROM {quiz_attempts}
                  WHERE userid = ?
                    AND (timefinish >= ? OR timestart >= ?)",
                [$userid, $since, $since]
            );
            foreach ($attempts as $row) {
                $ts = (int) $row->timefinish;
                if ($ts < 1) {
                    $ts = (int) $row->timestart;
                }
                if ($ts > 0 && (string) $row->state === 'finished') {
                    $addts($ts, 'quiz');
                }
            }
        }

        if ($dm->table_exists('local_learnlogic_submission')) {
            $subs = $DB->get_records_sql(
                "SELECT timecreated, status
                   FROM {local_learnlogic_submission}
                  WHERE userid = ? AND timecreated >= ?",
                [$userid, $since]
            );
            foreach ($subs as $row) {
                $addts((int) $row->timecreated, 'practice');
                $v = self::norm_verdict((string) $row->status);
                if ($v === 'ac') {
                    $totals['practice_ac']++;
                } else if ($v === 'wa') {
                    $totals['practice_wa']++;
                }
            }
        }

        if ($dm->table_exists('local_nexcodelab_submission')) {
            $subs = $DB->get_records_sql(
                "SELECT timecreated
                   FROM {local_nexcodelab_submission}
                  WHERE userid = ? AND timecreated >= ?",
                [$userid, $since]
            );
            foreach ($subs as $row) {
                $addts((int) $row->timecreated, 'codelab');
            }
        }

        if ($dm->table_exists('local_nexcodelab_step_attempt')) {
            $checks = $DB->get_records_sql(
                "SELECT timecreated
                   FROM {local_nexcodelab_step_attempt}
                  WHERE userid = ? AND timecreated >= ?",
                [$userid, $since]
            );
            foreach ($checks as $row) {
                $addts((int) $row->timecreated, 'codelab_check');
            }
        }

        if ($dm->table_exists('local_nexbattleground_sub')) {
            $subs = $DB->get_records_sql(
                "SELECT timecreated
                   FROM {local_nexbattleground_sub}
                  WHERE userid = ? AND timecreated >= ?",
                [$userid, $since]
            );
            foreach ($subs as $row) {
                $addts((int) $row->timecreated, 'battle');
            }
        }

        if ($dm->table_exists('local_nexbattleground_player')) {
            $players = $DB->get_records_sql(
                "SELECT timemodified, acceptedat
                   FROM {local_nexbattleground_player}
                  WHERE userid = ?
                    AND (timemodified >= ? OR acceptedat >= ?)",
                [$userid, $since, $since]
            );
            foreach ($players as $row) {
                if ((int) $row->acceptedat >= $since) {
                    $addts((int) $row->acceptedat, 'battle');
                } else if ((int) $row->timemodified >= $since) {
                    $addts((int) $row->timemodified, 'battle');
                }
            }
        }

        if (file_exists($CFG->dirroot . '/local/nexportfolio/lib.php')) {
            require_once($CFG->dirroot . '/local/nexportfolio/lib.php');
            if (function_exists('local_nexportfolio_get_cached_data')) {
                foreach (local_nexportfolio_get_cached_data($userid) as $cached) {
                    if (empty($cached->datajson)) {
                        continue;
                    }
                    $profile = json_decode($cached->datajson, true);
                    if (!is_array($profile)) {
                        continue;
                    }
                    foreach (($profile['activityHeatmap'] ?? []) as $pt) {
                        $date = trim((string) ($pt['date'] ?? ''));
                        $count = (int) ($pt['count'] ?? 0);
                        if ($date === '' || $count < 1) {
                            continue;
                        }
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            continue;
                        }
                        $addday($date, 'portfolio', $count);
                    }
                }
            }
        }

        return [
            'byday' => $byday,
            'summary' => self::activity_summary_items($totals),
            'total' => (int) $totals['total'],
        ];
    }

    /**
     * @param array $byday
     * @param array $totals
     * @param string $day
     * @param string $source
     * @param int $weight
     */
    private static function add_activity(array &$byday, array &$totals, string $day, string $source, int $weight): void {
        if ($day === '' || $weight < 1) {
            return;
        }
        if (!isset($byday[$day])) {
            $byday[$day] = ['count' => 0, 'sources' => []];
        }
        $byday[$day]['count'] += $weight;
        $byday[$day]['sources'][$source] = ($byday[$day]['sources'][$source] ?? 0) + $weight;
        $totals[$source] = ($totals[$source] ?? 0) + $weight;
        $totals['total'] += $weight;
    }

    /**
     * @param array $totals
     * @return array
     */
    private static function activity_summary_items(array $totals): array {
        $items = [];
        $push = function (string $key, string $label, int $count, string $detail = '') use (&$items): void {
            if ($count < 1) {
                return;
            }
            $items[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'detail' => $detail,
                'hasdetail' => $detail !== '',
            ];
        };

        $push('quiz', get_string('src_quiz', 'local_nexprofile'), (int) $totals['quiz']);
        if ((int) $totals['practice'] > 0) {
            $detail = (int) $totals['practice_ac'] . ' ' . get_string('acceptedshort', 'local_nexprofile')
                . ' · ' . (int) $totals['practice_wa'] . ' ' . get_string('wrongshort', 'local_nexprofile');
            $push('practice', get_string('src_practice', 'local_nexprofile'), (int) $totals['practice'], $detail);
        }
        $push('codelab', get_string('src_codelab', 'local_nexprofile'), (int) $totals['codelab']);
        $push('codelab_check', get_string('src_codelab_check', 'local_nexprofile'), (int) $totals['codelab_check']);
        $push('battle', get_string('src_battle', 'local_nexprofile'), (int) $totals['battle']);
        $push('portfolio', get_string('src_portfolio', 'local_nexprofile'), (int) $totals['portfolio']);
        return $items;
    }

    /**
     * Last-12-months heatmap from aggregated activity.
     *
     * @param array $byday
     * @param array $summary
     * @return array
     */
    public static function heatmap_from_days(array $byday, array $summary): array {
        $today = new \DateTimeImmutable('today');
        $thismonth = $today->modify('first day of this month')->setTime(0, 0);
        $months = [];
        $flatweeks = [];
        $activedays = 0;
        $total = 0;

        for ($i = 11; $i >= 0; $i--) {
            $monthstart = $thismonth->modify('-' . $i . ' months');
            $year = (int) $monthstart->format('Y');
            $monthnum = (int) $monthstart->format('n');
            $daysinmonth = (int) $monthstart->format('t');
            $pad = (int) $monthstart->format('w');

            $cells = [];
            for ($p = 0; $p < $pad; $p++) {
                $cells[] = self::empty_heat_cell();
            }
            for ($d = 1; $d <= $daysinmonth; $d++) {
                $cursor = $monthstart->setDate($year, $monthnum, $d);
                $cell = self::heat_cell_for($cursor, $today, $byday);
                $cells[] = $cell;
                if ($cell['count'] > 0) {
                    $activedays++;
                    $total += (int) $cell['count'];
                }
            }
            while (count($cells) % 7 !== 0) {
                $cells[] = self::empty_heat_cell();
            }

            $weeks = [];
            foreach (array_chunk($cells, 7) as $weekdays) {
                $week = ['days' => $weekdays];
                $weeks[] = $week;
                $flatweeks[] = $week;
            }

            $months[] = [
                'label' => $monthstart->format('M'),
                'year' => (string) $year,
                'key' => $monthstart->format('Y-m'),
                'weeks' => $weeks,
            ];
        }

        $sumtotal = 0;
        foreach ($summary as $row) {
            $sumtotal += (int) ($row['count'] ?? 0);
        }

        return [
            'weeks' => $flatweeks,
            'months' => $months,
            'weekdays' => [
                ['label' => 'S'],
                ['label' => 'M'],
                ['label' => 'T'],
                ['label' => 'W'],
                ['label' => 'T'],
                ['label' => 'F'],
                ['label' => 'S'],
            ],
            'summary' => $summary,
            'total' => $sumtotal > 0 ? $sumtotal : $total,
            'activedays' => $activedays,
        ];
    }

    /**
     * @return array
     */
    private static function empty_heat_cell(): array {
        return [
            'date' => '',
            'count' => 0,
            'level' => 'empty',
            'title' => '',
            'hastooltip' => false,
        ];
    }

    /**
     * @param \DateTimeImmutable $cursor
     * @param \DateTimeImmutable $today
     * @param array $byday
     * @return array
     */
    private static function heat_cell_for(\DateTimeImmutable $cursor, \DateTimeImmutable $today, array $byday): array {
        $key = $cursor->format('Y-m-d');
        $future = $cursor > $today;
        $info = $byday[$key] ?? null;
        $count = $future || !$info ? 0 : (int) $info['count'];
        $label = self::format_day_label($key);
        if ($future) {
            return [
                'date' => $key,
                'count' => 0,
                'level' => 'empty',
                'title' => $label . ' — ' . get_string('dayupcoming', 'local_nexprofile'),
                'hastooltip' => true,
            ];
        }
        return [
            'date' => $key,
            'count' => $count,
            'level' => (string) self::heat_level($count),
            'title' => self::heat_title($key, $info),
            'hastooltip' => true,
        ];
    }

    /**
     * @param string $day Y-m-d
     * @return string
     */
    private static function format_day_label(string $day): string {
        try {
            return (new \DateTimeImmutable($day))->format('j M Y');
        } catch (\Throwable $e) {
            return $day;
        }
    }

    /**
     * @param string $day
     * @param array|null $info
     * @return string
     */
    private static function heat_title(string $day, ?array $info): string {
        $label = self::format_day_label($day);
        if (!$info || (int) ($info['count'] ?? 0) < 1) {
            return $label . ' — ' . get_string('noactivityday', 'local_nexprofile');
        }
        $labels = [
            'quiz' => get_string('src_quiz', 'local_nexprofile'),
            'practice' => get_string('src_practice', 'local_nexprofile'),
            'codelab' => get_string('src_codelab', 'local_nexprofile'),
            'codelab_check' => get_string('src_codelab_check', 'local_nexprofile'),
            'battle' => get_string('src_battle', 'local_nexprofile'),
            'portfolio' => get_string('src_portfolio', 'local_nexprofile'),
        ];
        $parts = [];
        foreach ($labels as $src => $srclabel) {
            $n = (int) ($info['sources'][$src] ?? 0);
            if ($n > 0) {
                $parts[] = $srclabel . ' ' . $n;
            }
        }
        return $label . ' — ' . (int) $info['count'] . ' ' . get_string('events', 'local_nexprofile')
            . ': ' . implode(', ', $parts);
    }

    /**
     * @param int $userid
     * @return array
     */
    public static function languages(int $userid): array {
        global $DB;
        $map = [];
        $total = 0;
        foreach (['local_learnlogic_submission', 'local_nexcodelab_submission'] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $recs = $DB->get_records_sql(
                "SELECT language, COUNT(1) AS n
                   FROM {{$table}}
                  WHERE userid = ?
               GROUP BY language",
                [$userid]
            );
            foreach ($recs as $rec) {
                $lang = self::pretty_language((string) $rec->language);
                if ($lang === '') {
                    continue;
                }
                $map[$lang] = ($map[$lang] ?? 0) + (int) $rec->n;
                $total += (int) $rec->n;
            }
        }
        if (!$map) {
            return [];
        }
        arsort($map);
        $out = [];
        $i = 0;
        foreach ($map as $lang => $n) {
            if ($i++ >= 8) {
                break;
            }
            $out[] = [
                'name' => $lang,
                'count' => $n,
                'pct' => $total > 0 ? (int) round(($n / $total) * 100) : 0,
            ];
        }
        return $out;
    }

    /**
     * @param int $userid
     * @return array
     */
    public static function skills(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_learnlogic_tag')
                || !$DB->get_manager()->table_exists('local_learnlogic_problem_tag')
                || !$DB->get_manager()->table_exists('local_learnlogic_submission')) {
            return [];
        }
        $recs = $DB->get_records_sql(
            "SELECT t.name, COUNT(DISTINCT s.problemid) AS n
               FROM {local_learnlogic_submission} s
               JOIN {local_learnlogic_problem_tag} pt ON pt.problemid = s.problemid
               JOIN {local_learnlogic_tag} t ON t.id = pt.tagid
              WHERE s.userid = ? AND s.status = 'ACCEPTED'
           GROUP BY t.id, t.name
           ORDER BY n DESC, t.name ASC",
            [$userid],
            0,
            12
        );
        $out = [];
        foreach ($recs as $rec) {
            $out[] = [
                'name' => (string) $rec->name,
                'count' => (int) $rec->n,
            ];
        }
        if ($out) {
            $max = max(array_column($out, 'count')) ?: 1;
            $tones = ['a', 'b', 'c', 'd', 'e'];
            foreach ($out as $i => $row) {
                $pct = (int) round(($row['count'] / $max) * 100);
                $size = 'md';
                if ($pct >= 75) {
                    $size = 'lg';
                } else if ($pct < 40) {
                    $size = 'sm';
                }
                $out[$i]['pct'] = $pct;
                $out[$i]['size'] = $size;
                $out[$i]['tone'] = $tones[$i % count($tones)];
            }
        }
        return $out;
    }

    /**
     * Latest NexPractice + CodeLab submissions.
     *
     * @param int $userid
     * @param int $limit
     * @return array
     */
    public static function recent_submissions(int $userid, int $limit = 10): array {
        global $DB;

        $rows = [];
        $dm = $DB->get_manager();
        $fetch = max($limit, 10);

        if ($dm->table_exists('local_learnlogic_submission') && $dm->table_exists('local_learnlogic_problem')) {
            $recs = $DB->get_records_sql(
                "SELECT s.timecreated, s.status, s.language, p.name, p.id AS problemid
                   FROM {local_learnlogic_submission} s
                   JOIN {local_learnlogic_problem} p ON p.id = s.problemid
                  WHERE s.userid = ?
               ORDER BY s.timecreated DESC",
                [$userid],
                0,
                $fetch
            );
            foreach ($recs as $rec) {
                $rows[] = self::format_submission_row($rec, 'practice', $userid);
            }
        }

        if ($dm->table_exists('local_nexcodelab_submission') && $dm->table_exists('local_nexcodelab_problem')) {
            $recs = $DB->get_records_sql(
                "SELECT s.timecreated, s.status, s.language, p.name, p.id AS problemid
                   FROM {local_nexcodelab_submission} s
                   JOIN {local_nexcodelab_problem} p ON p.id = s.problemid
                  WHERE s.userid = ?
               ORDER BY s.timecreated DESC",
                [$userid],
                0,
                $fetch
            );
            foreach ($recs as $rec) {
                $rows[] = self::format_submission_row($rec, 'codelab', $userid);
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['timecreated'] ?? 0) <=> ($a['timecreated'] ?? 0);
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * Connected external coding platforms (NexPortfolio cache).
     *
     * @param int $userid
     * @return array
     */
    public static function portfolio_platforms(int $userid): array {
        global $CFG;

        if (!file_exists($CFG->dirroot . '/local/nexportfolio/lib.php')) {
            return [];
        }
        require_once($CFG->dirroot . '/local/nexportfolio/lib.php');
        if (!function_exists('local_nexportfolio_platforms') || !function_exists('local_nexportfolio_get_handles')) {
            return [];
        }

        $handles = local_nexportfolio_get_handles($userid);
        $cached = function_exists('local_nexportfolio_get_cached_data')
            ? local_nexportfolio_get_cached_data($userid)
            : [];

        $out = [];
        foreach (local_nexportfolio_platforms() as $key => $strkey) {
            $h = $handles[$key] ?? null;
            if (!$h || trim((string) ($h->handle ?? '')) === '') {
                continue;
            }
            $d = $cached[$key] ?? null;
            $solved = $d ? (int) $d->totalsolved : 0;
            $rating = $d ? (float) $d->rating : 0.0;
            $ranktext = $d ? trim((string) ($d->ranktext ?? '')) : '';
            $contests = $d ? (int) $d->contests : 0;
            $out[] = [
                'key' => $key,
                'label' => get_string($strkey, 'local_nexportfolio'),
                'handle' => (string) $h->handle,
                'totalsolved' => $solved,
                'hassolved' => $solved > 0,
                'rating' => $rating,
                'hasrating' => $rating > 0,
                'ratingdisplay' => $rating > 0 ? rtrim(rtrim(number_format($rating, 0), '0'), '.') : '',
                'ranktext' => $ranktext,
                'hasrank' => $ranktext !== '',
                'contests' => $contests,
                'hascontests' => $contests > 0,
            ];
        }
        return $out;
    }

    /**
     * BattleGround win/loss snapshot.
     *
     * @param int $userid
     * @return array
     */
    public static function battle_stats(int $userid): array {
        global $CFG, $DB;

        $available = file_exists($CFG->dirroot . '/local/nexbattleground/version.php')
            || $DB->get_manager()->table_exists('local_nexbattleground_battle');
        $empty = [
            'available' => $available,
            'wins' => 0,
            'losses' => 0,
            'ties' => 0,
            'battles' => 0,
            'winrate' => 0,
            'hasrank' => false,
            'rank' => 0,
        ];
        if (!$available || !class_exists('\\local_nexbattleground\\local\\leaderboard')) {
            return $empty;
        }
        $stats = \local_nexbattleground\local\leaderboard::user_stats($userid);
        $rank = \local_nexbattleground\local\leaderboard::rank_for($userid);
        return [
            'available' => true,
            'wins' => (int) ($stats['wins'] ?? 0),
            'losses' => (int) ($stats['losses'] ?? 0),
            'ties' => (int) ($stats['ties'] ?? 0),
            'battles' => (int) ($stats['battles'] ?? 0),
            'winrate' => (int) ($stats['winrate'] ?? 0),
            'hasrank' => $rank > 0,
            'rank' => $rank,
        ];
    }

    /**
     * @param \stdClass $rec
     * @param string $source practice|codelab
     * @param int $userid
     * @return array
     */
    private static function format_submission_row(\stdClass $rec, string $source, int $userid): array {
        $verdict = self::norm_verdict((string) $rec->status);
        if ($source === 'codelab') {
            $url = (new \moodle_url('/local/nexcodelab/problem.php', ['id' => (int) $rec->problemid]))->out(false);
            $sourcelabel = get_string('src_codelab_short', 'local_nexprofile');
        } else {
            $url = (new \moodle_url('/local/learnlogic/problem.php', ['id' => (int) $rec->problemid]))->out(false);
            $sourcelabel = get_string('src_practice_short', 'local_nexprofile');
        }

        return [
            'timecreated' => (int) $rec->timecreated,
            'timestr' => userdate((int) $rec->timecreated, get_string('strftimedatemonthabbr', 'langconfig')),
            'name' => (string) $rec->name,
            'url' => $url,
            'language' => self::pretty_language((string) $rec->language),
            'haslanguage' => trim((string) $rec->language) !== '',
            'verdict' => $verdict,
            'status' => self::pretty_status((string) $rec->status),
            'isac' => $verdict === 'ac',
            'source' => $source,
            'sourcelabel' => $sourcelabel,
        ];
    }

    /**
     * @param string $status
     * @return string
     */
    private static function pretty_status(string $status): string {
        $key = self::norm_verdict($status);
        $map = [
            'ac' => get_string('verdict_ac', 'local_nexprofile'),
            'wa' => get_string('verdict_wa', 'local_nexprofile'),
            'tle' => get_string('verdict_tle', 'local_nexprofile'),
            'ce' => get_string('verdict_ce', 'local_nexprofile'),
            're' => get_string('verdict_re', 'local_nexprofile'),
            'mle' => get_string('verdict_mle', 'local_nexprofile'),
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        $raw = trim($status);
        return $raw !== '' ? $raw : get_string('verdict_other', 'local_nexprofile');
    }

    /**
     * @param int $count
     * @return int
     */
    private static function heat_level(int $count): int {
        if ($count <= 0) {
            return 0;
        }
        if ($count <= 2) {
            return 1;
        }
        if ($count <= 5) {
            return 2;
        }
        if ($count <= 10) {
            return 3;
        }
        return 4;
    }

    /**
     * @param array $solved
     * @param array $totals
     * @return array
     */
    private static function difficulty_bars(array $solved, array $totals): array {
        $keys = [
            'easy' => get_string('easy', 'local_nexprofile'),
            'medium' => get_string('medium', 'local_nexprofile'),
            'hard' => get_string('hard', 'local_nexprofile'),
        ];
        $bars = [];
        foreach ($keys as $key => $label) {
            $s = (int) ($solved[$key] ?? 0);
            // Fold veryhard into hard for the Codolio-style 3-bar chart.
            if ($key === 'hard') {
                $s += (int) ($solved['veryhard'] ?? 0);
            }
            $t = (int) ($totals[$key] ?? 0);
            if ($key === 'hard') {
                $t += (int) ($totals['veryhard'] ?? 0);
            }
            $pct = $t > 0 ? (int) min(100, round(($s / $t) * 100)) : ($s > 0 ? 100 : 0);
            $bars[] = [
                'key' => $key,
                'label' => $label,
                'solved' => $s,
                'total' => $t,
                'pct' => $pct,
                'show' => true,
            ];
        }
        return $bars;
    }

    /**
     * @param array $bars
     * @return string
     */
    private static function donut_style(array $bars): string {
        $map = [];
        foreach ($bars as $bar) {
            $map[$bar['key']] = (int) $bar['solved'];
        }
        $e = $map['easy'] ?? 0;
        $m = $map['medium'] ?? 0;
        $h = $map['hard'] ?? 0;
        $sum = $e + $m + $h;
        if ($sum < 1) {
            return '--e:0;--m:0;--h:0;';
        }
        $ep = (int) round(($e / $sum) * 100);
        $mp = (int) round(($m / $sum) * 100);
        $hp = max(0, 100 - $ep - $mp);
        return "--e:{$ep};--m:{$mp};--h:{$hp};";
    }

    /**
     * @param int $userid
     * @param int $ts
     * @return string
     */
    private static function day_key(int $userid, int $ts): string {
        try {
            $tz = \core_date::get_user_timezone_object($userid);
            return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
        } catch (\Throwable $e) {
            return userdate($ts, '%Y-%m-%d');
        }
    }

    /**
     * @param string $status
     * @return string
     */
    private static function norm_verdict(string $status): string {
        $s = strtoupper(trim($status));
        $s = str_replace([' ', '-'], '_', $s);
        if (in_array($s, ['ACCEPTED', 'AC', 'PASS', 'PASSED', 'OK'], true)) {
            return 'ac';
        }
        if (in_array($s, ['WRONG_ANSWER', 'WA', 'FAIL', 'FAILED'], true)) {
            return 'wa';
        }
        if (in_array($s, ['TIME_LIMIT', 'TIME_LIMIT_EXCEEDED', 'TLE'], true)) {
            return 'tle';
        }
        if (in_array($s, ['COMPILE_ERROR', 'COMPILATION_ERROR', 'CE'], true)) {
            return 'ce';
        }
        if (in_array($s, ['RUNTIME_ERROR', 'RE', 'ERROR'], true)) {
            return 're';
        }
        if (in_array($s, ['MEMORY_LIMIT', 'MEMORY_LIMIT_EXCEEDED', 'MLE'], true)) {
            return 'mle';
        }
        return 'other';
    }

    /**
     * @param string $diff
     * @return string
     */
    private static function norm_diff(string $diff): string {
        $d = strtolower(str_replace([' ', '_', '-'], '', $diff));
        if ($d === 'veryhard' || $d === 'expert') {
            return 'veryhard';
        }
        if (in_array($d, ['easy', 'medium', 'hard'], true)) {
            return $d;
        }
        return 'medium';
    }

    /**
     * Enrolled course ids for a user (excludes site course).
     *
     * @param int $userid
     * @return int[]
     */
    private static function enrolled_course_ids(int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $courses = enrol_get_users_courses($userid, true, 'id');
        if (!$courses) {
            return [];
        }
        $ids = [];
        foreach ($courses as $course) {
            $cid = (int) $course->id;
            if ($cid > 1) {
                $ids[] = $cid;
            }
        }
        return $ids;
    }

    /**
     * Catalog totals for coding + MCQ questions in enrolled-course quizzes.
     *
     * @param int[] $courseids
     * @return array{coding:int,mcq:int}
     */
    private static function course_question_catalog_totals(array $courseids): array {
        global $DB;
        $out = ['coding' => 0, 'mcq' => 0];
        if (!$courseids) {
            return $out;
        }
        $dm = $DB->get_manager();
        if (!$dm->table_exists('quiz')
                || !$dm->table_exists('quiz_slots')
                || !$dm->table_exists('question_references')
                || !$dm->table_exists('question_versions')
                || !$dm->table_exists('question')) {
            return $out;
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cat');
        $sql = "SELECT q.qtype, COUNT(DISTINCT qs.id) AS n
                  FROM {quiz_slots} qs
                  JOIN {quiz} quiz ON quiz.id = qs.quizid AND quiz.course $cinsql
                  JOIN {question_references} qr
                    ON qr.itemid = qs.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                  JOIN {question_versions} qv
                    ON qv.questionbankentryid = qr.questionbankentryid
                   AND qv.version = (
                        SELECT MAX(qv2.version)
                          FROM {question_versions} qv2
                         WHERE qv2.questionbankentryid = qr.questionbankentryid
                   )
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE q.qtype IN ('coderunner', 'multichoice', 'truefalse')
              GROUP BY q.qtype";
        try {
            $rows = $DB->get_records_sql($sql, $cparams);
            foreach ($rows as $row) {
                $n = (int) ($row->n ?? 0);
                $qtype = (string) ($row->qtype ?? '');
                if ($qtype === 'coderunner') {
                    $out['coding'] += $n;
                } else if ($qtype === 'multichoice' || $qtype === 'truefalse') {
                    $out['mcq'] += $n;
                }
            }
        } catch (\Throwable $e) {
            debugging('nexprofile course catalog totals failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $out;
    }

    /**
     * Finished quiz/test attempts in enrolled courses.
     *
     * @param int $userid
     * @param int[]|null $courseids
     * @return int
     */
    private static function course_tests_submitted_total(int $userid, ?array $courseids = null): int {
        global $DB;
        if ($courseids === null) {
            $courseids = self::enrolled_course_ids($userid);
        }
        if (!$courseids || !$DB->get_manager()->table_exists('quiz_attempts')) {
            return 0;
        }
        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ts');
        $params = array_merge(['userid' => $userid], $cparams);
        try {
            return (int) $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {quiz_attempts} quiza
                   JOIN {quiz} quiz ON quiz.id = quiza.quiz AND quiz.course $cinsql
                  WHERE quiza.userid = :userid
                    AND quiza.preview = 0
                    AND quiza.timefinish > 0",
                $params
            );
        } catch (\Throwable $e) {
            debugging('nexprofile course tests submitted failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Distinct correct MCQ / TrueFalse questions in enrolled courses.
     *
     * @param int $userid
     * @param int[]|null $courseids
     * @return int
     */
    private static function course_mcq_correct_total(int $userid, ?array $courseids = null): int {
        global $DB;

        $dm = $DB->get_manager();
        if (!$dm->table_exists('quiz')
                || !$dm->table_exists('quiz_attempts')
                || !$dm->table_exists('question_attempts')
                || !$dm->table_exists('question_attempt_steps')
                || !$dm->table_exists('question')) {
            return 0;
        }
        if ($courseids === null) {
            $courseids = self::enrolled_course_ids($userid);
        }
        if (!$courseids) {
            return 0;
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $params = array_merge(['userid' => $userid], $cparams);
        $times = [];

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
            debugging('nexprofile course MCQ query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return count($times);
    }

    /**
     * Distinct NexCourse / enrolled-course CodeRunner questions the learner has solved.
     *
     * @param int $userid
     * @param int[]|null $courseids
     * @return int
     */
    private static function course_coding_solved_total(int $userid, ?array $courseids = null): int {
        global $DB;

        $dm = $DB->get_manager();
        if (!$dm->table_exists('quiz')
                || !$dm->table_exists('quiz_attempts')
                || !$dm->table_exists('question_attempts')
                || !$dm->table_exists('question_attempt_steps')) {
            return 0;
        }

        if ($courseids === null) {
            $courseids = self::enrolled_course_ids($userid);
        }
        if (!$courseids) {
            return 0;
        }

        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cc');
        $slotkey = $DB->sql_concat('quiza.quiz', "'_'", 'qa.slot');
        $baseparams = array_merge(['userid' => $userid], $cparams);
        $times = [];

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

        if ($dm->table_exists('question')) {
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

        if ($dm->table_exists('question_attempt_step_data')) {
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
                debugging('nexprofile course coding solve query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return count($times);
    }

    /**
     * @param string $lang
     * @return string
     */
    private static function pretty_language(string $lang): string {
        $lang = trim($lang);
        if ($lang === '') {
            return '';
        }
        $map = [
            'python3' => 'Python (3)',
            'python' => 'Python',
            'py' => 'Python',
            'cpp' => 'C++',
            'c++' => 'C++',
            'java' => 'Java',
            'javascript' => 'JavaScript',
            'js' => 'JavaScript',
            'c' => 'C',
            'csharp' => 'C#',
            'go' => 'Go',
            'rust' => 'Rust',
        ];
        $key = strtolower($lang);
        return $map[$key] ?? $lang;
    }
}
