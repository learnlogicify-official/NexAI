<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course progress cache for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Maintains nexreports_course_progress and exposes progress queries.
 */
class progress {

    /**
     * Ensure completionlib is loaded (needs global $CFG inside a namespaced file).
     */
    private static function require_completionlib(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
    }

    /**
     * Whether completion tracking is enabled site wide.
     *
     * @return bool
     */
    public static function global_completion_enabled(): bool {
        global $CFG;
        return !empty($CFG->enablecompletion);
    }

    /**
     * The activities of a course that can count towards a learner's progress.
     *
     * Core counts an activity only when a learner could see it on the course page and is not
     * excluded from it by an access restriction. Visibility is read from the stored flags
     * rather than cm_info::is_visible_on_course_page(), because that resolves against whoever
     * is running the code: under cron or CLI there is no enrolled viewer, so every activity
     * would look hidden and no learner would be cached. The stored flag is also what core's
     * own count_modules_completed() filters on.
     *
     * @param \completion_info $info
     * @return array{0: array<int, string>, 1: array<int, \cm_info>} Module names by id, and the
     *     subset carrying access restrictions that may exclude individual learners
     */
    private static function module_set(\completion_info $info): array {
        $shared = [];
        $restricted = [];

        foreach ($info->get_activities() as $cm) {
            if (empty($cm->visible) || empty($cm->visibleoncoursepage) || !empty($cm->deletioninprogress)) {
                continue;
            }
            $shared[(int) $cm->id] = $cm->modname;
            if (!empty($cm->availability)) {
                // Every restriction is offered to core, which applies only the conditions that
                // can exclude named users (group, grouping, profile field and similar) and
                // ignores the rest. Matching specific condition types here would silently miss
                // the others.
                $restricted[(int) $cm->id] = $cm;
            }
        }

        return [$shared, $restricted];
    }

    /**
     * Learners of a page who are excluded from restricted activities.
     *
     * Restrictions are resolved a module at a time for the whole page, which is far cheaper
     * than asking core for one learner's reachable activities at a time.
     *
     * @param array<int, \cm_info> $restrictedcms
     * @param int[] $userids
     * @return array<int, array<int, bool>> Excluded module ids by user id
     */
    private static function excluded_modules(array $restrictedcms, array $userids): array {
        if (!$restrictedcms || !$userids) {
            return [];
        }

        $candidates = [];
        foreach ($userids as $id) {
            $candidates[$id] = (object) ['id' => $id];
        }

        $excluded = [];
        foreach ($restrictedcms as $cmid => $cm) {
            $allowed = (new \core_availability\info_module($cm))->filter_user_list($candidates);
            foreach ($userids as $id) {
                if (!isset($allowed[$id])) {
                    $excluded[$id][$cmid] = true;
                }
            }
        }

        return $excluded;
    }

    /**
     * Recalculate progress for every enrolled learner in a course.
     *
     * @param int $courseid
     * @return int Rows written
     */
    public static function refresh_course(int $courseid): int {
        global $DB;

        self::require_completionlib();

        if ($courseid <= 1) {
            return 0;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*');
        if (!$course) {
            return 0;
        }

        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return 0;
        }

        [$sharedmods, $restrictedcms] = self::module_set($info);
        if (!$sharedmods) {
            return 0;
        }

        // get_progress_all() loads users and their course_modules_completion rows in bulk.
        // The previous implementation called get_course_progress_percentage() and then
        // get_data() once per module for every learner, effectively traversing all completion
        // data twice and issuing thousands of queries on large courses.
        $pagesize = 1000;
        $start = 0;
        $written = 0;
        $now = time();
        $transaction = $DB->start_delegated_transaction();

        // Replacing all rows for the course is considerably faster than an existence query
        // plus an update/insert for every learner. The transaction keeps the old cache visible
        // until the complete replacement is ready.
        $DB->delete_records('nexreports_course_progress', ['courseid' => $courseid]);

        do {
            $users = $info->get_progress_all('', [], 0, 'u.id ASC', $pagesize, $start);
            if (!$users) {
                break;
            }

            $userids = array_map('intval', array_keys($users));
            $coursecompletions = [];
            if ($userids) {
                [$insql, $inparams] = $DB->get_in_or_equal(
                    $userids,
                    SQL_PARAMS_NAMED,
                    'ccu'
                );
                $completionrows = $DB->get_records_select(
                    'course_completions',
                    "course = :courseid AND userid $insql AND timecompleted IS NOT NULL",
                    array_merge(['courseid' => $courseid], $inparams),
                    '',
                    'userid, timecompleted'
                );
                foreach ($completionrows as $completionrow) {
                    $coursecompletions[(int) $completionrow->userid] =
                        (int) $completionrow->timecompleted;
                }
            }

            $excluded = self::excluded_modules($restrictedcms, $userids);

            $records = [];
            foreach ($users as $user) {
                $userid = (int) $user->id;

                $usermods = isset($excluded[$userid])
                    ? array_diff_key($sharedmods, $excluded[$userid])
                    : $sharedmods;
                $usercompletable = count($usermods);

                $completed = [];
                $progressdone = 0;
                $lastcompleted = 0;

                foreach ($user->progress as $cmid => $cdata) {
                    $cmid = (int) $cmid;
                    if (!isset($usermods[$cmid])) {
                        continue;
                    }

                    $state = (int) ($cdata->completionstate ?? COMPLETION_INCOMPLETE);
                    if ($state !== COMPLETION_COMPLETE && $state !== COMPLETION_COMPLETE_PASS) {
                        // Core ignores failed and incomplete states when measuring progress.
                        continue;
                    }

                    $progressdone++;
                    if ($usermods[$cmid] === 'label') {
                        continue;
                    }
                    $completed[] = $cmid;
                    $lastcompleted = max($lastcompleted, (int) ($cdata->timemodified ?? 0));
                }

                $pct = $usercompletable > 0
                    ? round(($progressdone / $usercompletable) * 100, 3)
                    : 0.0;
                if (isset($coursecompletions[$userid])) {
                    // This mirrors core progress: completed course criteria override the
                    // activity ratio to 100%.
                    $pct = 100.0;
                }

                $completiontime = null;
                if ($pct >= 100 && $lastcompleted > 0) {
                    // Edwiser stamps completion with the latest passing/plain-complete
                    // activity, not the course_completions timestamp.
                    $completiontime = $lastcompleted;
                }

                $record = (object) [
                    'courseid' => $courseid,
                    'userid' => $userid,
                    'completedmodules' => $completed ? implode(',', $completed) : null,
                    'totalmodules' => count($completed),
                    'completablemods' => $usercompletable,
                    'progress' => $pct,
                    'completiontime' => $completiontime,
                    'timemodified' => $now,
                ];
                $records[] = $record;
            }

            if ($records) {
                $DB->insert_records('nexreports_course_progress', $records);
                $written += count($records);
            }

            $returned = count($users);
            $start += $returned;
        } while ($returned === $pagesize);

        $transaction->allow_commit();
        return $written;
    }

    /**
     * Seconds a single refresh run may spend before pausing until the next run.
     */
    public const RUN_BUDGET = 240;

    /**
     * Refresh courses with completion enabled, resuming where the last run stopped.
     *
     * Recalculating every learner in every course can exceed the PHP time limit on a large
     * site, which would leave the cache permanently half built. Runs therefore stop at a time
     * budget and store a cursor, so consecutive runs eventually cover the whole site.
     *
     * Individual course failures are collected rather than thrown, so one broken course
     * cannot fail the task for everyone else.
     *
     * @param int $limit Max courses per run (0 = until the time budget is reached)
     * @return array
     */
    public static function refresh_all(int $limit = 0): array {
        global $CFG, $DB;

        $started = microtime(true);
        $result = [
            'courses' => 0,
            'skipped' => 0,
            'rows' => 0,
            'errors' => 0,
            'elapsed' => 0.0,
            'remaining' => 0,
            'wrapped' => false,
            'globalcompletion' => !empty($CFG->enablecompletion),
        ];

        if (empty($CFG->enablecompletion)) {
            // completion_info::is_enabled() is false for every course, so nothing can be cached.
            return $result;
        }

        $cursor = (int) (get_config('local_nexreports', 'progresscursor') ?: 0);
        $courses = $DB->get_records_select(
            'course',
            'id > :cursor AND id > 1 AND enablecompletion = 1',
            ['cursor' => $cursor],
            'id ASC',
            'id'
        );

        // Reaching the end of the list restarts from the beginning on this same run.
        if (!$courses && $cursor > 0) {
            $cursor = 0;
            $result['wrapped'] = true;
            $courses = $DB->get_records_select('course', 'id > 1 AND enablecompletion = 1', null, 'id ASC', 'id');
        }

        $lastid = $cursor;
        foreach ($courses as $course) {
            try {
                $written = self::refresh_course((int) $course->id);
                $result['rows'] += $written;
                if ($written === 0) {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                debugging('local_nexreports progress refresh failed for course '
                    . $course->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
            }
            $result['courses']++;
            $lastid = (int) $course->id;

            if ($limit > 0 && $result['courses'] >= $limit) {
                break;
            }
            if ((microtime(true) - $started) >= self::RUN_BUDGET) {
                break;
            }
        }

        $result['remaining'] = (int) $DB->count_records_select(
            'course',
            'id > :cursor AND id > 1 AND enablecompletion = 1',
            ['cursor' => $lastid]
        );
        set_config('progresscursor', $result['remaining'] > 0 ? $lastid : 0, 'local_nexreports');

        $result['elapsed'] = round(microtime(true) - $started, 1);

        return $result;
    }

    /**
     * Counts describing the state of the progress cache, for task output and support.
     *
     * @return array
     */
    public static function diagnostics(): array {
        global $CFG, $DB;

        $rows = (int) $DB->count_records('nexreports_course_progress');
        $completed = (int) $DB->count_records_select(
            'nexreports_course_progress',
            'completiontime IS NOT NULL'
        );
        $full = (int) $DB->count_records_select('nexreports_course_progress', 'progress >= 100');

        $data = [
            'globalcompletion' => !empty($CFG->enablecompletion),
            'completioncourses' => (int) $DB->count_records_select(
                'course',
                'id > 1 AND enablecompletion = 1'
            ),
            'rows' => $rows,
            'fullprogress' => $full,
            'withcompletiontime' => $completed,
        ];

        return $data;
    }

    /**
     * Activity by activity account of how one learner's progress was reached.
     *
     * Intended for support: when a percentage disagrees with another report, this shows which
     * activities entered the denominator, which counted as done, and why the rest did not.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function explain_learner(int $courseid, int $userid): array {
        global $DB;

        self::require_completionlib();

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return ['error' => 'Completion tracking is off for this course.'];
        }

        [$sharedmods, $restrictedcms] = self::module_set($info);
        $excluded = self::excluded_modules($restrictedcms, [$userid])[$userid] ?? [];

        $states = $DB->get_records_sql(
            "SELECT cmc.coursemoduleid, cmc.completionstate, cmc.timemodified
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );

        $activities = [];
        $counted = 0;
        $done = 0;
        foreach ($info->get_activities() as $cm) {
            $cmid = (int) $cm->id;
            $state = isset($states[$cmid]) ? (int) $states[$cmid]->completionstate : COMPLETION_INCOMPLETE;
            $iscounted = isset($sharedmods[$cmid]) && !isset($excluded[$cmid]);
            $isdone = $state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS;

            if ($iscounted) {
                $counted++;
                if ($isdone) {
                    $done++;
                }
            }

            $reason = 'counted';
            if (empty($cm->visible)) {
                $reason = 'hidden from learners';
            } else if (empty($cm->visibleoncoursepage)) {
                $reason = 'not shown on the course page';
            } else if (!empty($cm->deletioninprogress)) {
                $reason = 'being deleted';
            } else if (isset($excluded[$cmid])) {
                $reason = 'access restriction excludes this learner';
            }

            $activities[] = (object) [
                'cmid' => $cmid,
                'modname' => $cm->modname,
                'name' => $cm->name,
                'counted' => $iscounted,
                'reason' => $reason,
                'state' => $state,
                'done' => $isdone,
                'timemodified' => isset($states[$cmid]) ? (int) $states[$cmid]->timemodified : 0,
            ];
        }

        $coursecompletion = $DB->get_field(
            'course_completions',
            'timecompleted',
            ['course' => $courseid, 'userid' => $userid]
        );

        return [
            'course' => $course->shortname,
            'tracked' => $info->is_tracked_user($userid),
            'activities' => $activities,
            'counted' => $counted,
            'done' => $done,
            'progress' => $counted > 0 ? round(($done / $counted) * 100, 3) : 0.0,
            'coursecompletion' => $coursecompletion ? (int) $coursecompletion : null,
            'cached' => $DB->get_record('nexreports_course_progress', ['courseid' => $courseid, 'userid' => $userid]),
        ];
    }

    /**
     * Average progress and completion counts for a course.
     *
     * @param int $courseid
     * @param int[]|null $userids
     * @return array{learners:int,avgprogress:float,completed:int}
     */
    public static function course_summary(int $courseid, ?array $userids = null): array {
        global $DB;

        $params = ['courseid' => $courseid];
        $where = 'courseid = :courseid';
        if ($userids !== null) {
            if (!$userids) {
                return ['learners' => 0, 'avgprogress' => 0.0, 'completed' => 0];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $where .= " AND userid $insql";
            $params = array_merge($params, $inparams);
        }

        $sql = "SELECT COUNT(*) AS learners,
                       COALESCE(AVG(progress), 0) AS avgprogress,
                       SUM(CASE WHEN progress >= 100 OR completiontime IS NOT NULL THEN 1 ELSE 0 END) AS completed
                  FROM {nexreports_course_progress}
                 WHERE $where";
        $row = $DB->get_record_sql($sql, $params);
        return [
            'learners' => (int) ($row->learners ?? 0),
            'avgprogress' => round((float) ($row->avgprogress ?? 0), 1),
            'completed' => (int) ($row->completed ?? 0),
        ];
    }
}
