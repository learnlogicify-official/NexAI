<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Outline + learn-shell data for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');

use completion_info;
use context_course;
use context_module;
use core_courseformat\base as format_base;
use moodle_page;
use moodle_url;

/**
 * Build Pro learn shell data (sidebar outline + main pane + prev/next).
 */
class catalog {

    /** @var array<int, bool> Per-request cache for quiz fail lookups (cmid => failed). */
    private static array $activityfailedcache = [];

    /**
     * Outline-only payload for refreshing the sidebar after edits.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function export_outline(int $courseid, int $cmid = 0): array {
        global $PAGE, $USER;

        $format = course_get_format($courseid);
        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $userid = (int) $USER->id;
        $completion = new completion_info($course);
        $flat = self::flat_activities($format, $modinfo, $completion, $userid, $PAGE);
        $sections = self::outline_sections($format, $modinfo, $completion, $userid, $PAGE, $flat);

        if ($cmid > 0) {
            foreach ($sections as &$sec) {
                $sec['expanded'] = false;
                foreach ($sec['activities'] as &$act) {
                    $act['active'] = ((int) $act['id'] === $cmid);
                    if ($act['active']) {
                        $sec['expanded'] = true;
                    }
                }
                unset($act);
                if (!empty($sec['subsections'])) {
                    foreach ($sec['subsections'] as &$sub) {
                        $sub['expanded'] = false;
                        foreach ($sub['activities'] as &$act) {
                            $act['active'] = ((int) $act['id'] === $cmid);
                            if ($act['active']) {
                                $sub['expanded'] = true;
                                $sec['expanded'] = true;
                            }
                        }
                        unset($act);
                    }
                    unset($sub);
                }
            }
            unset($sec);
        }

        $context = context_course::instance($courseid);
        $canupdate = has_capability('moodle/course:update', $context);
        $canmanage = has_capability('moodle/course:manageactivities', $context);

        return [
            'courseid' => $courseid,
            'sections' => $sections,
            'hassections' => !empty($sections),
            'canedit' => $canupdate || $canmanage,
            'canmanageactivities' => $canmanage,
            'canupdatesection' => $canupdate,
            'addmodules' => $canmanage ? self::export_add_modules($courseid) : [],
            'hasaddmodules' => $canmanage,
            'hassubsection' => $canmanage && self::module_available('subsection'),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Lightweight H5P/live progress payload (no player HTML).
     *
     * Forces Moodle to re-evaluate completion from the gradebook so
     * "Receive a grade" flips without a full page reload.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function export_cm_progress(int $courseid, int $cmid): array {
        global $USER, $PAGE;

        $course = get_course($courseid);
        $userid = (int) $USER->id;
        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cmid);
        $completion = new completion_info($course);

        // Re-evaluate automatic conditions (grade / view) from current data.
        self::refresh_h5p_completion_state($course, $cmid, $userid);

        try {
            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cmid);
            $completion = new completion_info($course);
        } catch (\Throwable $e) {
            // Keep prior cminfo.
        }

        $completed = self::activity_rail_complete($cminfo, $completion, $userid, $course);
        $failed = !$completed && self::activity_is_failed($cminfo, $completion, $userid);
        $gradeinfo = self::activity_grade_display($courseid, $cminfo, $userid);
        // Score badge only when the activity is complete (sidebar tick rules).
        if (!$completed) {
            $gradeinfo = [
                'hasactivitygrade' => false,
                'gradedisplay' => '',
            ];
        }

        $completionhtml = self::render_completion_html($cmid, $PAGE, $course);
        $hascompletion = trim(strip_tags($completionhtml)) !== '';

        // Keep this endpoint light — callers only need this CM's completion/score.
        // Full course stats strip is updated elsewhere (course load / idle refresh).
        $stats = [
            'progresspct' => 0,
            'activitydisplay' => '',
            'items' => [],
        ];
        $hasstats = false;

        return [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'completed' => $completed,
            'failed' => $failed,
            'hasgrade' => !empty($gradeinfo['hasactivitygrade']),
            'hasactivitygrade' => !empty($gradeinfo['hasactivitygrade']),
            'gradedisplay' => (string) ($gradeinfo['gradedisplay'] ?? ''),
            'completionhtml' => $completionhtml,
            'hascompletion' => $hascompletion,
            'hasstats' => $hasstats,
            'stats' => $stats,
        ];
    }

    /**
     * Activity grade as "obtained / max" for the hero header (when attempted).
     *
     * @param int $courseid
     * @param \cm_info|\stdClass $cminfo
     * @param int $userid
     * @return array{hasactivitygrade:bool,gradedisplay:string}
     */
    private static function activity_grade_display(int $courseid, $cminfo, int $userid): array {
        $empty = [
            'hasactivitygrade' => false,
            'gradedisplay' => '',
        ];
        if ($userid < 1 || $courseid < 1 || empty($cminfo)) {
            return $empty;
        }
        try {
            $grades = grade_get_grades(
                $courseid,
                'mod',
                (string) $cminfo->modname,
                (int) $cminfo->instance,
                $userid
            );
            $item = reset($grades->items);
            if (!$item) {
                return $empty;
            }
            $max = isset($item->grademax) ? (float) $item->grademax : 0.0;
            if ($max <= 0) {
                return $empty;
            }
            $obtained = null;
            if (!empty($item->grades[$userid])) {
                $g = $item->grades[$userid];
                if (isset($g->grade) && $g->grade !== null && $g->grade !== '') {
                    $obtained = (float) $g->grade;
                }
            }
            if ($obtained === null) {
                return $empty;
            }
            $decimals = isset($item->decimals) ? (int) $item->decimals : 2;
            return [
                'hasactivitygrade' => true,
                'gradedisplay' => format_float($obtained, $decimals, true) . ' / ' . format_float($max, $decimals, true),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Full learn shell for course view (browsing mode).
     *
     * @param format_base $format
     * @param moodle_page $page
     * @param int $sectionnum Active section (0 = auto)
     * @param int $cmid Active cm (0 = auto)
     * @return array
     */
    public static function export_learn(format_base $format, moodle_page $page, int $sectionnum = 0, int $cmid = 0): array {
        global $USER;

        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $userid = (int) $USER->id;
        $completion = new completion_info($course);
        $context = context_course::instance($course->id);

        $flat = self::flat_activities($format, $modinfo, $completion, $userid, $page);
        $sections = self::outline_sections($format, $modinfo, $completion, $userid, $page, $flat);

        if ($cmid < 1) {
            $cmid = self::pick_default_cmid($flat);
        }
        $current = null;
        foreach ($flat as $item) {
            if ((int) $item['id'] === $cmid) {
                $current = $item;
                break;
            }
        }
        if (!$current && !empty($flat)) {
            $current = $flat[0];
            $cmid = (int) $current['id'];
        }

        // Mark active in outline (including nested subsections).
        foreach ($sections as &$sec) {
            $sec['expanded'] = false;
            foreach ($sec['activities'] as &$act) {
                $act['active'] = ((int) $act['id'] === $cmid);
                if ($act['active']) {
                    $sec['expanded'] = true;
                    $sectionnum = (int) $sec['sectionnum'];
                }
            }
            unset($act);
            if (!empty($sec['subsections'])) {
                foreach ($sec['subsections'] as &$sub) {
                    $sub['expanded'] = false;
                    foreach ($sub['activities'] as &$act) {
                        $act['active'] = ((int) $act['id'] === $cmid);
                        if ($act['active']) {
                            $sub['expanded'] = true;
                            $sec['expanded'] = true;
                            $sectionnum = (int) $sec['sectionnum'];
                        }
                    }
                    unset($act);
                }
                unset($sub);
            }
            if ($sectionnum > 0 && (int) $sec['sectionnum'] === $sectionnum) {
                $sec['expanded'] = true;
            }
        }
        unset($sec);

        $nav = self::prev_next($flat, $cmid);
        $main = self::export_main_pane($course, $current, $page);
        $stats = self::export_stats_strip($flat, $sections, $context);
        $leaderboard = leaderboard::export((int) $course->id, (int) $USER->id, $flat);

        $canupdate = has_capability('moodle/course:update', $context);
        $canmanage = has_capability('moodle/course:manageactivities', $context);
        $canedit = $canupdate || $canmanage;

        return [
            'courseid' => (int) $course->id,
            'coursename' => format_string($course->fullname, true, ['context' => $context]),
            'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'dashboardurl' => (new moodle_url('/my/courses.php'))->out(false),
            'sections' => $sections,
            'hassections' => !empty($sections),
            'main' => $main,
            'prev' => $nav['prev'],
            'next' => $nav['next'],
            'hasprev' => !empty($nav['prev']),
            'hasnext' => !empty($nav['next']),
            'stats' => $stats,
            'hasstats' => !empty($stats['items']),
            'leaderboard' => $leaderboard,
            'hasleaderboard' => !empty($leaderboard['available']),
            'tabcontentlabel' => get_string('tabcontent', 'format_nexcoursepro'),
            'tableaderboardlabel' => get_string('tableaderboard', 'format_nexcoursepro'),
            'canedit' => $canedit,
            'canmanageactivities' => $canmanage,
            'canupdatesection' => $canupdate,
            'editing' => $page->user_is_editing(),
            'sesskey' => sesskey(),
            'addmodules' => $canmanage ? self::export_add_modules((int) $course->id) : [],
            'hasaddmodules' => $canmanage,
            'addmodulesjson' => $canmanage ? json_encode(self::export_add_modules((int) $course->id), JSON_UNESCAPED_SLASHES) : '[]',
            'hassubsection' => $canmanage && self::module_available('subsection'),
        ];
    }

    /**
     * Leaderboard payload for AJAX tab refresh.
     *
     * Uses the same flat activity list + completion rules as the course header stats.
     *
     * @param int $courseid
     * @return array
     */
    public static function export_leaderboard(
        int $courseid,
        string $institution = '',
        int $page = 0,
        int $perpage = 25
    ): array {
        global $USER, $PAGE;

        $course = get_course($courseid);
        $format = course_get_format($course);
        $modinfo = $format->get_modinfo();
        $completion = new completion_info($course);
        $flat = self::flat_activities(
            $format,
            $modinfo,
            $completion,
            (int) $USER->id,
            $PAGE,
            true
        );

        return leaderboard::export(
            $courseid,
            (int) $USER->id,
            $flat,
            $institution,
            $page,
            $perpage
        );
    }

    /**
     * Activity progress counts matching the course header strip.
     *
     * @param array $flat
     * @return array{pct:int,completed:int,total:int,display:string}
     */
    public static function progress_from_flat(array $flat): array {
        $total = count($flat);
        $completed = 0;
        foreach ($flat as $item) {
            if (!empty($item['completed'])) {
                $completed++;
            }
        }
        $pct = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        return [
            'pct' => $pct,
            'completed' => $completed,
            'total' => $total,
            'display' => get_string('activitiesprogress', 'format_nexcoursepro', (object) [
                'completed' => $completed,
                'total' => $total,
            ]),
        ];
    }

    /**
     * Common modules for the in-rail Add activity menu.
     *
     * @param int $courseid
     * @return array
     */
    private static function export_add_modules(int $courseid): array {
        $preferred = ['quiz', 'page', 'resource', 'url', 'forum', 'assign', 'label', 'book', 'subsection'];
        $out = [];
        foreach ($preferred as $modname) {
            if (!self::module_available($modname)) {
                continue;
            }
            try {
                $out[] = [
                    'modname' => $modname,
                    'name' => get_string('modulename', $modname),
                    'isnested' => $modname === 'subsection',
                ];
            } catch (\Throwable $e) {
                // Skip unknown lang.
            }
        }
        return $out;
    }

    /**
     * @param string $modname
     * @return bool
     */
    private static function module_available(string $modname): bool {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/mod/' . $modname . '/lib.php')) {
            return false;
        }
        if (function_exists('get_module_types_names')) {
            $types = get_module_types_names(false);
            return isset($types[$modname]);
        }
        return true;
    }

    /**
     * Compact progress strip (NexCourse-style stats row).
     *
     * @param array $flat
     * @param array $sections
     * @param \context_course $context
     * @return array
     */
    private static function export_stats_strip(array $flat, array $sections, $context): array {
        global $USER;

        $progress = self::progress_from_flat($flat);
        $pct = $progress['pct'];

        $sectotal = count($sections);
        $secdone = 0;
        foreach ($sections as $sec) {
            if (!empty($sec['sectioncomplete'])) {
                $secdone++;
            }
        }

        $items = [
            [
                'key' => 'progress',
                'value' => $pct . '%',
                'label' => get_string('statprogress', 'format_nexcoursepro'),
            ],
            [
                'key' => 'sections',
                'value' => $secdone . '/' . $sectotal,
                'label' => get_string('statsections', 'format_nexcoursepro'),
            ],
        ];

        $grades = self::course_grade_stat_parts((int) $context->instanceid, (int) $USER->id);
        if ($grades !== null) {
            $items[] = [
                'key' => 'gradeobtained',
                'value' => $grades['obtained'],
                'label' => get_string('statgradeobtained', 'format_nexcoursepro'),
            ];
            $items[] = [
                'key' => 'gradetotal',
                'value' => $grades['total'],
                'label' => get_string('statgradetotal', 'format_nexcoursepro'),
            ];
            $items[] = [
                'key' => 'gradepct',
                'value' => $grades['percent'],
                'label' => get_string('statgradepct', 'format_nexcoursepro'),
            ];
        }

        return [
            'progresspct' => $pct,
            'activitydisplay' => $progress['display'],
            'items' => $items,
        ];
    }

    /**
     * Current user's course total as obtained + max for the stats strip.
     *
     * @param int $courseid
     * @param int $userid
     * @return array{obtained:string,total:string,percent:string}|null
     */
    private static function course_grade_stat_parts(int $courseid, int $userid): ?array {
        global $CFG;

        if ($courseid < 1 || $userid < 1) {
            return null;
        }

        try {
            require_once($CFG->libdir . '/gradelib.php');
            $courseitem = \grade_item::fetch_course_item($courseid);
            if (!$courseitem || !(float) $courseitem->grademax) {
                return null;
            }

            // Hidden course totals should not leak into the learner chrome.
            if ($courseitem->is_hidden()) {
                return null;
            }

            $grade = \grade_grade::fetch([
                'itemid' => (int) $courseitem->id,
                'userid' => $userid,
            ]);
            if ($grade) {
                $grade->grade_item = $courseitem;
                if (method_exists($grade, 'is_hidden') && $grade->is_hidden()) {
                    return null;
                }
            }

            $decimals = method_exists($courseitem, 'get_decimals')
                ? (int) $courseitem->get_decimals()
                : (int) ($courseitem->decimals ?? 2);

            $grademax = (float) $courseitem->grademax;
            $total = format_float($grademax, $decimals, true);
            if (!$grade || $grade->finalgrade === null || $grade->finalgrade === '') {
                $obtained = '—';
                $percent = '—';
            } else {
                $final = (float) $grade->finalgrade;
                $obtained = format_float($final, $decimals, true);
                $percent = (int) round(($final / $grademax) * 100) . '%';
            }

            return [
                'obtained' => $obtained,
                'total' => $total,
                'percent' => $percent,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Current user's course total as "obtained / max" for the stats strip.
     *
     * @param int $courseid
     * @param int $userid
     * @return string|null
     * @deprecated since 0.1.95 — use course_grade_stat_parts().
     */
    private static function course_grade_stat_value(int $courseid, int $userid): ?string {
        $parts = self::course_grade_stat_parts($courseid, $userid);
        if ($parts === null) {
            return null;
        }
        return $parts['obtained'] . ' / ' . $parts['total'];
    }

    /**
     * Flat ordered list of playable activities across the course.
     *
     * @param format_base $format
     * @param \course_modinfo $modinfo
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @param bool $lightweight Skip expensive per-quiz fail checks (SPA pane nav).
     * @return array<int, array>
     */
    public static function flat_activities(
        format_base $format,
        $modinfo,
        completion_info $completion,
        int $userid,
        moodle_page $page,
        bool $lightweight = false
    ): array {
        $out = [];
        $courseid = (int) $format->get_course()->id;
        foreach (self::listed_sections($modinfo) as $section) {
            if (!self::section_listed_in_pro($section)) {
                continue;
            }
            self::collect_section_activities(
                $out,
                $format,
                $modinfo,
                $section,
                $completion,
                $userid,
                $page,
                $courseid,
                0,
                $lightweight
            );
        }
        return $out;
    }

    /**
     * Append visible activities from a section (and nested subsections) to $out.
     *
     * @param array $out
     * @param format_base $format
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @param int $courseid
     * @param int $parentsectionid Parent section id when nested in a subsection
     * @param bool $lightweight Skip expensive per-quiz fail checks
     */
    private static function collect_section_activities(
        array &$out,
        format_base $format,
        $modinfo,
        $section,
        completion_info $completion,
        int $userid,
        moodle_page $page,
        int $courseid,
        int $parentsectionid = 0,
        bool $lightweight = false
    ): void {
        foreach (self::section_cms($modinfo, $section) as $cm) {
            if (($cm->modname ?? '') === 'subsection') {
                $child = self::delegated_section_for_cm($modinfo, $cm);
                if ($child && self::section_listed_in_pro($child)) {
                    foreach (self::section_cms($modinfo, $child) as $childcm) {
                        if (!self::cm_listed_in_pro($childcm)) {
                            continue;
                        }
                        $out[] = self::activity_row(
                            $childcm,
                            $child,
                            $completion,
                            $userid,
                            $page,
                            $courseid,
                            (int) $section->id,
                            $lightweight
                        );
                    }
                }
                continue;
            }
            if (!self::cm_listed_in_pro($cm)) {
                continue;
            }
            $out[] = self::activity_row(
                $cm,
                $section,
                $completion,
                $userid,
                $page,
                $courseid,
                $parentsectionid,
                $lightweight
            );
        }
    }

    /**
     * Flat prev/next order without per-activity completion work (cached per course per request).
     *
     * @param format_base $format
     * @param \course_modinfo $modinfo
     * @return array<int, array>
     */
    private static function flat_nav_items(format_base $format, $modinfo): array {
        static $cache = [];
        $courseid = (int) $format->get_course()->id;
        if (isset($cache[$courseid])) {
            return $cache[$courseid];
        }
        $out = [];
        foreach (self::listed_sections($modinfo) as $section) {
            if (!self::section_listed_in_pro($section)) {
                continue;
            }
            self::collect_section_nav_items($out, $modinfo, $section, $courseid, 0);
        }
        return $cache[$courseid] = $out;
    }

    /**
     * @param array $out
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @param int $courseid
     * @param int $parentsectionid
     */
    private static function collect_section_nav_items(
        array &$out,
        $modinfo,
        $section,
        int $courseid,
        int $parentsectionid = 0
    ): void {
        foreach (self::section_cms($modinfo, $section) as $cm) {
            if (($cm->modname ?? '') === 'subsection') {
                $child = self::delegated_section_for_cm($modinfo, $cm);
                if ($child && self::section_listed_in_pro($child)) {
                    foreach (self::section_cms($modinfo, $child) as $childcm) {
                        if (!self::cm_listed_in_pro($childcm)) {
                            continue;
                        }
                        $out[] = self::nav_item_row($childcm, $child, $courseid);
                    }
                }
                continue;
            }
            if (!self::cm_listed_in_pro($cm)) {
                continue;
            }
            $out[] = self::nav_item_row($cm, $section, $courseid);
        }
    }

    /**
     * @param \cm_info $cm
     * @param \section_info $section
     * @param int $courseid
     * @return array
     */
    private static function nav_item_row($cm, $section, int $courseid): array {
        $viewsection = (int) $section->section;
        $viewurl = (new moodle_url('/course/view.php', [
            'id' => $courseid,
            'section' => $viewsection > 0 ? $viewsection : 1,
            'cmid' => (int) $cm->id,
        ]))->out(false);
        return [
            'id' => (int) $cm->id,
            'name' => format_string($cm->name, true, ['context' => context_module::instance($cm->id)]),
            'viewurl' => $viewurl,
            'sectionnum' => (int) $section->section,
        ];
    }

    /**
     * Locate a listed activity and its outline section for pane export.
     *
     * @param \course_modinfo $modinfo
     * @param int $cmid
     * @return array{cm:\cm_info,section:\section_info,parentsectionid:int}|null
     */
    private static function resolve_cm_outline_context($modinfo, int $cmid): ?array {
        foreach (self::listed_sections($modinfo) as $section) {
            if (!self::section_listed_in_pro($section)) {
                continue;
            }
            foreach (self::section_cms($modinfo, $section) as $cm) {
                if ((int) $cm->id === $cmid && self::cm_listed_in_pro($cm)) {
                    return [
                        'cm' => $cm,
                        'section' => $section,
                        'parentsectionid' => 0,
                    ];
                }
                if (($cm->modname ?? '') === 'subsection') {
                    $child = self::delegated_section_for_cm($modinfo, $cm);
                    if ($child && self::section_listed_in_pro($child)) {
                        foreach (self::section_cms($modinfo, $child) as $childcm) {
                            if ((int) $childcm->id === $cmid && self::cm_listed_in_pro($childcm)) {
                                return [
                                    'cm' => $childcm,
                                    'section' => $child,
                                    'parentsectionid' => (int) $section->id,
                                ];
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param format_base $format
     * @param \course_modinfo $modinfo
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @param array $flat
     * @return array
     */
    private static function outline_sections(
        format_base $format,
        $modinfo,
        completion_info $completion,
        int $userid,
        moodle_page $page,
        array $flat
    ): array {
        unset($flat); // Structure is rebuilt from modinfo so subsections nest correctly.
        $sections = [];
        $i = 1;
        $courseid = (int) $format->get_course()->id;
        foreach (self::listed_sections($modinfo) as $section) {
            if (!self::section_listed_in_pro($section)) {
                continue;
            }
            $direct = [];
            $subsections = [];
            foreach (self::section_cms($modinfo, $section) as $cm) {
                if (($cm->modname ?? '') === 'subsection') {
                    $child = self::delegated_section_for_cm($modinfo, $cm);
                    if (!$child || !self::section_listed_in_pro($child)) {
                        continue;
                    }
                    $subacts = [];
                    foreach (self::section_cms($modinfo, $child) as $childcm) {
                        if (!self::cm_listed_in_pro($childcm)) {
                            continue;
                        }
                        $subacts[] = self::activity_row(
                            $childcm,
                            $child,
                            $completion,
                            $userid,
                            $page,
                            $courseid,
                            (int) $section->id
                        );
                    }
                    if (empty($subacts)) {
                        continue;
                    }
                    $subsections[] = self::outline_group(
                        $format,
                        $child,
                        $subacts,
                        (string) format_string($cm->name, true, [
                            'context' => context_module::instance($cm->id),
                        ])
                    );
                    continue;
                }
                if (!self::cm_listed_in_pro($cm)) {
                    continue;
                }
                $direct[] = self::activity_row($cm, $section, $completion, $userid, $page, $courseid);
            }
            if (empty($direct) && empty($subsections)) {
                continue;
            }
            $all = $direct;
            foreach ($subsections as $sub) {
                foreach ($sub['activities'] as $act) {
                    $all[] = $act;
                }
            }
            $group = self::outline_group($format, $section, $all, '');
            $group['shortlabel'] = get_string('seclabel', 'format_nexcoursepro', $i);
            $group['title'] = $group['shortlabel'] . ': ' . $group['name'];
            $group['activities'] = self::wire_activity_next($direct);
            $group['hasactivities'] = !empty($direct);
            $group['firstcmid'] = !empty($direct)
                ? (int) $direct[0]['id']
                : (!empty($all) ? (int) $all[0]['id'] : 0);
            $group['subsections'] = $subsections;
            $group['hassubsections'] = !empty($subsections);
            $group['isempty'] = false;
            $group['candelete'] = false;
            $group['addurl'] = (new moodle_url('/course/modedit.php', [
                'add' => 'page',
                'type' => '',
                'course' => $courseid,
                'section' => (int) $section->section,
                'return' => 0,
                'update' => 0,
            ]))->out(false);
            $sections[] = $group;
            $i++;
        }
        return $sections;
    }

    /**
     * @param format_base $format
     * @param \section_info $section
     * @param array $acts
     * @param string $namoverride
     * @return array
     */
    private static function outline_group(format_base $format, $section, array $acts, string $namoverride = ''): array {
        $acts = self::wire_activity_next($acts);
        $done = 0;
        foreach ($acts as $act) {
            if (!empty($act['completed'])) {
                $done++;
            }
        }
        $total = count($acts);
        $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $name = $namoverride !== '' ? $namoverride : $format->get_section_name($section);
        return [
            'sectionnum' => (int) $section->section,
            'sectionid' => (int) $section->id,
            'name' => $name,
            'shortlabel' => $name,
            'title' => $name,
            'activities' => $acts,
            'hasactivities' => $total > 0,
            'completedcount' => $done,
            'activitycount' => $total,
            'sectioncomplete' => $total > 0 && $done === $total,
            'progresspct' => $pct,
            'progresslabel' => get_string('sectionprogress', 'format_nexcoursepro', $pct),
            'hasprogress' => $total > 0,
            'expanded' => false,
            'subsections' => [],
            'hassubsections' => false,
        ];
    }

    /**
     * @param array $acts
     * @return array
     */
    private static function wire_activity_next(array $acts): array {
        $count = count($acts);
        for ($ai = 0; $ai < $count; $ai++) {
            $next = $acts[$ai + 1] ?? null;
            $acts[$ai]['hasnext'] = !empty($next);
            $acts[$ai]['nextid'] = $next ? (int) $next['id'] : 0;
        }
        return $acts;
    }

    /**
     * @param \cm_info $cm
     * @param \section_info $section
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @param int $courseid
     * @param int $parentsectionid Parent section id when nested in a subsection
     * @param bool $lightweight Skip expensive per-quiz fail checks
     * @return array
     */
    private static function activity_row(
        $cm,
        $section,
        completion_info $completion,
        int $userid,
        moodle_page $page,
        int $courseid,
        int $parentsectionid = 0,
        bool $lightweight = false
    ): array {
        unset($page);
        $completed = self::activity_rail_complete($cm, $completion, $userid, get_course($courseid));
        $failed = !$lightweight && !$completed && self::activity_is_failed($cm, $completion, $userid);
        $icon = '';
        try {
            $icon = $cm->get_icon_url()->out(false);
        } catch (\Throwable $e) {
            $icon = '';
        }
        $viewsection = (int) $section->section;
        $viewurl = (new moodle_url('/course/view.php', [
            'id' => $courseid,
            'section' => $viewsection > 0 ? $viewsection : 1,
            'cmid' => (int) $cm->id,
        ]))->out(false);
        $modurl = $cm->url ? $cm->url->out(false) : $viewurl;
        $name = format_string($cm->name, true, ['context' => context_module::instance($cm->id)]);

        return [
            'id' => (int) $cm->id,
            'name' => $name,
            'modname' => $cm->modname,
            'typelabel' => get_string('modulename', $cm->modname),
            'iconurl' => $icon !== '' ? $icon : '',
            'hasicon' => $icon !== '',
            'completed' => $completed,
            'failed' => $failed,
            'sectionnum' => (int) $section->section,
            'sectionid' => (int) $section->id,
            'parentsectionid' => $parentsectionid,
            'sectionname' => (string) ($section->name ?? ''),
            'viewurl' => $viewurl,
            'modurl' => $modurl,
            'editurl' => (new moodle_url('/course/modedit.php', ['update' => (int) $cm->id, 'return' => 1]))->out(false),
            'embeddable' => in_array($cm->modname, ['page', 'url', 'resource', 'book', 'label'], true),
            'active' => false,
            'isnested' => $parentsectionid > 0,
            'searchtext' => \core_text::strtolower($name . ' ' . $cm->modname),
            'hasnext' => false,
            'nextid' => 0,
        ];
    }

    /**
     * True when the activity was attempted but did not meet the pass requirement.
     *
     * @param \cm_info $cm
     * @param completion_info $completion
     * @param int $userid
     * @return bool
     */
    private static function activity_is_failed($cm, completion_info $completion, int $userid): bool {
        global $CFG, $DB;

        if ($userid < 1) {
            return false;
        }

        $cachekey = (int) $cm->id . ':' . $userid;
        if (array_key_exists($cachekey, self::$activityfailedcache)) {
            return self::$activityfailedcache[$cachekey];
        }

        try {
            if ($completion->is_enabled($cm)) {
                $data = $completion->get_data($cm, false, $userid);
                if ((int) ($data->completionstate ?? COMPLETION_INCOMPLETE) === COMPLETION_COMPLETE_FAIL) {
                    return self::$activityfailedcache[$cachekey] = true;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to quiz grade check.
        }

        if (($cm->modname ?? '') !== 'quiz') {
            return self::$activityfailedcache[$cachekey] = false;
        }

        try {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->libdir . '/gradelib.php');
            $attempts = quiz_get_user_attempts((int) $cm->instance, $userid, 'finished', true);
            if (empty($attempts)) {
                return self::$activityfailedcache[$cachekey] = false;
            }
            $quiz = $DB->get_record('quiz', ['id' => (int) $cm->instance], '*', IGNORE_MISSING);
            if (!$quiz) {
                return self::$activityfailedcache[$cachekey] = false;
            }
            $gradeitem = \grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => (int) $quiz->id,
                'itemnumber' => 0,
                'courseid' => (int) $cm->course,
            ]);
            if (!$gradeitem || !grade_floats_different((float) $gradeitem->gradepass, 0.0)) {
                // No pass mark — only completion-fail (above) counts as failed.
                return self::$activityfailedcache[$cachekey] = false;
            }
            $mygrade = quiz_get_best_grade($quiz, $userid);
            if ($mygrade === null) {
                return self::$activityfailedcache[$cachekey] = true;
            }
            return self::$activityfailedcache[$cachekey] =
                grade_floats_less_than((float) $mygrade, (float) $gradeitem->gradepass);
        } catch (\Throwable $e) {
            return self::$activityfailedcache[$cachekey] = false;
        }
    }

    /**
     * Record "viewed" completion for non-quiz activities (same as their view.php).
     *
     * Quizzes must use quiz_view() instead — see quiz_view_detail().
     * Viewed is a single user+cm flag; it is not tracked per quiz attempt.
     *
     * @param \stdClass $course
     * @param int $cmid
     */
    private static function mark_module_viewed($course, int $cmid): void {
        global $USER;

        if ($cmid < 1 || empty($USER->id) || isguestuser()) {
            return;
        }
        try {
            $modinfo = get_fast_modinfo($course);
            $cm = $modinfo->get_cm($cmid);
            if (!$cm || !$cm->uservisible) {
                return;
            }
            $completion = new completion_info($course);
            if (!$completion->is_enabled($cm)) {
                return;
            }
            $data = $completion->get_data($cm, false, (int) $USER->id);
            if ((int) ($data->viewed ?? 0) === COMPLETION_VIEWED) {
                return;
            }
            $completion->set_module_viewed($cm);
        } catch (\Throwable $e) {
            // Viewing must still work if completion write fails.
        }
    }

    /**
     * Fire quiz_view() only when the activity is not already marked viewed.
     *
     * @param \stdClass $quiz
     * @param \stdClass $course
     * @param \cm_info|\stdClass $cm
     * @param \context $context
     */
    private static function mark_quiz_viewed_if_needed($quiz, $course, $cm, $context): void {
        global $USER;

        try {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm)) {
                $data = $completion->get_data($cm, false, (int) $USER->id);
                if ((int) ($data->viewed ?? 0) === COMPLETION_VIEWED) {
                    return;
                }
            }
            quiz_view($quiz, $course, $cm, $context);
        } catch (\Throwable $e) {
            // Best effort — pane must still render.
        }
    }

    /**
     * Activity complete when Moodle completion conditions are satisfied (not fail).
     *
     * @param \cm_info $cm
     * @param completion_info $completion
     * @param int $userid
     * @return bool
     */
    private static function activity_is_complete($cm, completion_info $completion, int $userid): bool {
        if ($userid < 1 || !$completion->is_enabled($cm)) {
            return false;
        }
        try {
            $data = $completion->get_data($cm, false, $userid);
        } catch (\Throwable $e) {
            return false;
        }
        $state = (int) ($data->completionstate ?? COMPLETION_INCOMPLETE);
        if ($state === COMPLETION_COMPLETE_FAIL || $state === COMPLETION_INCOMPLETE) {
            return false;
        }
        return $state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS;
    }

    /**
     * True when every automatic completion criterion for this CM is satisfied.
     *
     * @param int $cmid
     * @param \stdClass $course
     * @param int $userid
     * @return bool
     */
    private static function activity_all_completion_criteria_done(int $cmid, $course, int $userid): bool {
        try {
            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cmid);
            if (!$cminfo || !class_exists('\\core_completion\\cm_completion_details')) {
                return false;
            }
            $details = \core_completion\cm_completion_details::get_instance($cminfo, $userid, true);
            if (!$details || !$details->has_completion()) {
                return false;
            }
            if ($details->is_manual()) {
                return $details->is_overall_complete();
            }
            $criteria = $details->get_details();
            if (empty($criteria)) {
                return $details->is_overall_complete();
            }
            foreach ($criteria as $rule => $detail) {
                if (!self::completion_criterion_display_done($cminfo, $detail, $userid, (string) $rule)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether tracked H5P has at least one stored attempt for this user.
     *
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    private static function h5p_has_user_attempt(int $cmid, int $userid): bool {
        if ($userid < 1 || !class_exists('\\mod_h5pactivity\\local\\manager')) {
            return false;
        }
        try {
            $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
            $manager = \mod_h5pactivity\local\manager::create_from_coursemodule($cm);
            if (!$manager->is_tracking_enabled()) {
                return false;
            }
            return !empty($manager->get_user_attempts($userid));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Re-evaluate H5P completion (grade/view) and mark viewed after interaction.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param int $userid
     */
    private static function refresh_h5p_completion_state($course, int $cmid, int $userid): void {
        if ($userid < 1 || $cmid < 1) {
            return;
        }
        try {
            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cmid);
            if (($cminfo->modname ?? '') !== 'h5pactivity') {
                return;
            }
            $completion = new completion_info($course);
            if ($completion->is_enabled($cminfo)) {
                try {
                    $completion->update_state($cminfo, COMPLETION_UNKNOWN, $userid);
                } catch (\Throwable $e) {
                    // Still return whatever Moodle currently has.
                }
            }
            if (class_exists('\\course_modinfo') && method_exists('\\course_modinfo', 'clear_instance_cache')) {
                \course_modinfo::clear_instance_cache((int) $course->id);
            }
            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cmid);
            self::maybe_mark_h5p_viewed($course, $cmid, $cminfo, $userid);
        } catch (\Throwable $e) {
            // Best-effort refresh only.
        }
    }

    /**
     * Mark H5P "viewed" only after interaction (attempt), not when the pane opens.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param \cm_info $cminfo
     * @param int $userid
     */
    private static function maybe_mark_h5p_viewed($course, int $cmid, $cminfo, int $userid): void {
        if ($userid < 1 || ($cminfo->modname ?? '') !== 'h5pactivity') {
            return;
        }
        if (!self::h5p_has_user_attempt($cmid, $userid)) {
            return;
        }
        self::mark_module_viewed($course, $cmid);
    }

    /**
     * Best scaled score (0–1) for a tracked H5P activity, or null when none.
     *
     * @param int $cmid
     * @param int $userid
     * @return float|null
     */
    private static function h5p_best_scaled_score(int $cmid, int $userid): ?float {
        if ($userid < 1 || !class_exists('\\mod_h5pactivity\\local\\manager')) {
            return null;
        }
        try {
            $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
            $manager = \mod_h5pactivity\local\manager::create_from_coursemodule($cm);
            if (!$manager->is_tracking_enabled()) {
                return null;
            }
            $scores = $manager->get_users_scaled_score($userid);
            if (empty($scores)) {
                return null;
            }
            $row = reset($scores);
            return isset($row->scaled) ? (float) $row->scaled : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stricter completion for the sidebar tick (H5P Interactive Book safe).
     *
     * Interactive Book sends xAPI on each page with score 0, which can satisfy
     * "Receive a grade" too early. Require all criteria plus a non-zero score
     * when H5P attempt tracking is enabled.
     *
     * @param \cm_info $cm
     * @param completion_info $completion
     * @param int $userid
     * @param \stdClass $course
     * @return bool
     */
    private static function activity_rail_complete($cm, completion_info $completion, int $userid, $course): bool {
        $mod = strtolower($cm->modname ?? '');
        if (!in_array($mod, ['h5pactivity', 'h5p', 'hvp'], true)) {
            return self::activity_is_complete($cm, $completion, $userid);
        }

        if (!self::activity_all_completion_criteria_done((int) $cm->id, $course, $userid)) {
            return false;
        }

        if ($mod !== 'h5pactivity' || !class_exists('\\mod_h5pactivity\\local\\manager')) {
            return self::activity_is_complete($cm, $completion, $userid);
        }

        try {
            $manager = \mod_h5pactivity\local\manager::create_from_coursemodule($cm);
            if (!$manager->is_tracking_enabled()) {
                return self::activity_is_complete($cm, $completion, $userid);
            }

            $scaled = self::h5p_best_scaled_score((int) $cm->id, $userid);
            if ($scaled === null || $scaled <= 0) {
                return false;
            }

            return self::activity_is_complete($cm, $completion, $userid);
        } catch (\Throwable $e) {
            return self::activity_is_complete($cm, $completion, $userid);
        }
    }

    /**
     * Whether tracked H5P has a meaningful grade (not page-flip 0-point packets).
     *
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    private static function h5p_grade_criterion_satisfied(int $cmid, int $userid): bool {
        $scaled = self::h5p_best_scaled_score($cmid, $userid);
        return $scaled !== null && $scaled > 0;
    }

    /**
     * @param string $desc Completion criterion label from Moodle.
     * @return bool
     */
    private static function is_h5p_grade_completion_description(string $desc): bool {
        $d = strtolower($desc);
        return str_contains($d, 'grade') || str_contains($d, 'score');
    }

    /**
     * @param string $rule cm_completion_details rule key.
     * @param string $desc Criterion label.
     * @return bool
     */
    private static function is_h5p_grade_completion_rule(string $rule, string $desc): bool {
        if (in_array($rule, ['completionusegrade', 'completionpassgrade'], true)) {
            return true;
        }
        return self::is_h5p_grade_completion_description($desc);
    }

    /**
     * Pro display override for H5P completion pills (header + AJAX).
     *
     * Moodle marks "Receive a grade" done on 0-point Interactive Book page xAPI.
     *
     * @param \cm_info $cminfo
     * @param \stdClass $detail
     * @param int $userid
     * @param string $rule cm_completion_details rule key
     * @return bool
     */
    private static function completion_criterion_display_done($cminfo, $detail, int $userid, string $rule = ''): bool {
        $status = (int) ($detail->status ?? COMPLETION_INCOMPLETE);
        $done = in_array($status, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
        if (($cminfo->modname ?? '') !== 'h5pactivity') {
            return $done;
        }
        $desc = trim((string) ($detail->description ?? ''));
        if ($rule === 'completionview') {
            if (!$done) {
                return false;
            }
            return self::h5p_has_user_attempt((int) $cminfo->id, $userid);
        }
        if (!self::is_h5p_grade_completion_rule($rule, $desc)) {
            return $done;
        }
        if (!$done) {
            return false;
        }
        return self::h5p_grade_criterion_satisfied((int) $cminfo->id, $userid);
    }

    /**
     * Render completion criteria pills for the left pane.
     *
     * Built from cm_completion_details::get_details() so it does not depend on
     * Moodle activity_information templates (which are easy to miss in SPA panes).
     *
     * @param int $cmid
     * @param moodle_page $page
     * @param \stdClass $course
     * @return string
     */
    private static function render_completion_html(int $cmid, moodle_page $page, $course): string {
        global $USER;
        try {
            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cmid);
            if (!$cminfo || !class_exists('\\core_completion\\cm_completion_details')) {
                return '';
            }
            $details = \core_completion\cm_completion_details::get_instance($cminfo, (int) $USER->id, true);
            if (!$details || !$details->has_completion()) {
                return '';
            }

            $pills = '';
            if ($details->is_automatic()) {
                foreach ($details->get_details() as $rule => $detail) {
                    $done = self::completion_criterion_display_done(
                        $cminfo,
                        $detail,
                        (int) $USER->id,
                        (string) $rule
                    );
                    $desc = trim((string) ($detail->description ?? ''));
                    if ($desc === '') {
                        continue;
                    }
                    // Match Moodle's "Done: View" badge wording.
                    $label = $done ? ('Done: ' . $desc) : $desc;
                    $pills .= '<span class="nxpro-completion-crit' . ($done ? ' nxpro-completion-crit--done' : '') . '">'
                        . ($done ? '<span class="nxpro-completion-crit__check" aria-hidden="true"></span>' : '')
                        . s($label)
                        . '</span>';
                }
            } else if ($details->is_manual()) {
                $done = $details->is_overall_complete();
                $label = $done
                    ? get_string('markedcompleted', 'format_nexcoursepro')
                    : get_string('markascompleted', 'format_nexcoursepro');
                $pills .= '<button type="button"'
                    . ' class="nxpro-completion-crit nxpro-completion-crit--manual'
                    . ($done ? ' nxpro-completion-crit--done' : ' nxpro-completion-crit--todo') . '"'
                    . ' data-action="nxpro-manual-complete"'
                    . ' data-cmid="' . (int) $cmid . '"'
                    . ' data-completed="' . ($done ? '1' : '0') . '"'
                    . ' aria-pressed="' . ($done ? 'true' : 'false') . '"'
                    . ' title="' . s(get_string($done ? 'markasincomplete' : 'markascompleted', 'format_nexcoursepro')) . '">'
                    . ($done ? '<span class="nxpro-completion-crit__check" aria-hidden="true"></span>' : '')
                    . '<span class="nxpro-completion-crit__label">' . s($label) . '</span>'
                    . '</button>';
            }

            if ($pills === '') {
                // Last resort: Moodle's activity_information output.
                if (class_exists('\\core_course\\output\\activity_information')) {
                    $activitydates = [];
                    if (class_exists('\\core\\activity_dates')) {
                        $activitydates = \core\activity_dates::get_dates_for_module($cminfo, (int) $USER->id);
                    }
                    $ainfo = new \core_course\output\activity_information($cminfo, $details, $activitydates);
                    $courserenderer = $page->get_renderer('core', 'course');
                    return (string) $courserenderer->render($ainfo);
                }
                return '';
            }

            return '<div class="nxpro-completion-crits" data-region="nxpro-completion-crits">' . $pills . '</div>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array $flat
     * @return int
     */
    private static function pick_default_cmid(array $flat): int {
        foreach ($flat as $item) {
            if (empty($item['completed'])) {
                return (int) $item['id'];
            }
        }
        return !empty($flat) ? (int) $flat[0]['id'] : 0;
    }

    /**
     * @param array $flat
     * @param int $cmid
     * @return array{prev:?array,next:?array}
     */
    private static function prev_next(array $flat, int $cmid): array {
        $idx = -1;
        foreach ($flat as $i => $item) {
            if ((int) $item['id'] === $cmid) {
                $idx = $i;
                break;
            }
        }
        return [
            'prev' => ($idx > 0) ? $flat[$idx - 1] : null,
            'next' => ($idx >= 0 && $idx < count($flat) - 1) ? $flat[$idx + 1] : null,
        ];
    }

    /**
     * Main content pane for the selected activity.
     *
     * @param \stdClass $course
     * @param array|null $current
     * @param moodle_page $page
     * @return array
     */
    private static function export_main_pane($course, ?array $current, moodle_page $page): array {
        $empty = self::empty_main_pane($course);
        if (!$current) {
            return $empty;
        }
        return self::build_activity_view($course, $current, $page);
    }

    /**
     * @param \stdClass $course
     * @return array
     */
    private static function empty_main_pane($course): array {
        $html = format_text($course->summary ?? '', $course->summaryformat ?? FORMAT_HTML);
        return [
            'hasactivity' => false,
            'kind' => 'course',
            'kindlabel' => get_string('pluginname', 'format_nexcoursepro'),
            'eyebrow' => get_string('pluginname', 'format_nexcoursepro'),
            'sectionlabel' => '',
            'title' => format_string($course->fullname),
            'html' => $html,
            'hashhtml' => trim(strip_tags($html)) !== '',
            'hasmedia' => false,
            'mediaurl' => '',
            'mediakind' => '',
            'isvideofile' => false,
            'isaudiofile' => false,
            'isexternallink' => false,
            'isembed' => false,
            'showcta' => false,
            'ctaurl' => '',
            'ctalabel' => '',
            'showsecondary' => false,
            'secondaryurl' => '',
            'secondarylabel' => '',
            'statusitems' => [],
            'hasstatus' => false,
            'completed' => false,
            'failed' => false,
            'completionhtml' => '',
            'hascompletion' => false,
            'hasactivitygrade' => false,
            'gradedisplay' => '',
            'modurl' => '',
            'modname' => '',
            'typelabel' => '',
            'iconurl' => '',
            'hasicon' => false,
            'cmid' => 0,
            'sectionnum' => 0,
            'viewurl' => '',
            'hasquiztabs' => false,
            'quizintro' => '',
            'hasquizintro' => false,
            'quizmessages' => [],
            'hasquizmessages' => false,
            'quizactionshtml' => '',
            'hasquizactions' => false,
            'quizbodyhtml' => '',
            'hasquizbody' => false,
            'quizsections' => [],
            'hasquizsections' => false,
            'quizcourseurl' => '',
            'quizattempts' => [],
            'hasquizattempts' => false,
            'quizattemptcount' => 0,
            'quizbestgrade' => '',
            'hasquizbestgrade' => false,
            // Legacy keys kept for older JS caches.
            'showlaunch' => false,
            'showembed' => false,
            'embedurl' => '',
            'launchlabel' => get_string('openactivity', 'format_nexcoursepro'),
        ];
    }

    /**
     * Native quiz/video/lesson view for the left pane (no iframe).
     *
     * @param \stdClass $course
     * @param array $current
     * @param moodle_page $page
     * @return array
     */
    private static function build_activity_view($course, array $current, moodle_page $page): array {
        global $USER;

        $cmid = (int) ($current['id'] ?? 0);
        $modname = (string) ($current['modname'] ?? '');
        $kind = self::activity_kind($modname);

        $sectionname = trim((string) ($current['sectionname'] ?? ''));
        if ($sectionname === '') {
            $sectionname = get_string('sectionname', 'format_nexcoursepro') . ' ' . (int) $current['sectionnum'];
        }

        $html = '';
        $mediaurl = '';
        $mediakind = '';
        $statusitems = [];

        $quiztabs = [
            'hasquiztabs' => false,
            'quizintro' => '',
            'hasquizintro' => false,
            'quizmessages' => [],
            'hasquizmessages' => false,
            'quizactionshtml' => '',
            'hasquizactions' => false,
            'quizbodyhtml' => '',
            'hasquizbody' => false,
            'quizsections' => [],
            'hasquizsections' => false,
            'quizcourseurl' => '',
            'quizattempts' => [],
            'hasquizattempts' => false,
            'quizattemptcount' => 0,
            'quizbestgrade' => '',
            'hasquizbestgrade' => false,
        ];

        if ($kind === 'page') {
            // Same moment as mod/page/view.php — not tied to quiz attempts.
            self::mark_module_viewed($course, $cmid);
            $html = self::page_html((int) $current['id']);
        } else if ($kind === 'quiz') {
            // View completion is recorded inside quiz_view_detail via quiz_view()
            // (identical to mod/quiz/view.php). Attempt pages do not mark view.
            $detail = self::quiz_view_detail((int) $current['id'], $page);
            $html = '';
            $statusitems = $detail['statusitems'] ?? [];
            $quiztabs = array_merge($quiztabs, $detail);
        } else if ($kind === 'h5p' || $modname === 'h5pactivity' || $modname === 'hvp') {
            // View completion for h5pactivity is deferred until an attempt exists
            // (maybe_mark_h5p_viewed inside export_cm_progress).
            if ($modname === 'hvp') {
                self::mark_module_viewed($course, $cmid);
            }
            $detail = self::h5p_view_detail((int) $current['id'], $modname);
            $html = $detail['intro'];
            $mediaurl = $detail['mediaurl'];
            $mediakind = $detail['mediakind'];
            $statusitems = $detail['statusitems'];
            if ($mediaurl !== '') {
                $kind = 'h5p';
            } else {
                $kind = 'activity';
                $statusitems = [[
                    'label' => get_string('activitytype', 'format_nexcoursepro'),
                    'value' => (string) ($current['typelabel'] ?? 'H5P'),
                ]];
            }
        } else if ($kind === 'video' || $modname === 'resource' || $modname === 'url') {
            self::mark_module_viewed($course, $cmid);
            $detail = self::video_view_detail((int) $current['id'], $modname);
            $html = $detail['intro'];
            $mediaurl = $detail['mediaurl'];
            $mediakind = $detail['mediakind'];
            $statusitems = $detail['statusitems'];
            if ($mediaurl !== '' || $kind === 'video') {
                $kind = 'video';
            } else {
                $kind = 'activity';
                $statusitems = [[
                    'label' => get_string('activitytype', 'format_nexcoursepro'),
                    'value' => (string) ($current['typelabel'] ?? $modname),
                ]];
            }
        } else if ($kind === 'nexinterview' || $modname === 'nexinterview') {
            self::mark_module_viewed($course, $cmid);
            $detail = self::nexinterview_view_detail((int) $current['id']);
            $html = (string) ($detail['html'] ?? '');
            $statusitems = $detail['statusitems'] ?? [];
            $kind = 'nexinterview';
            // Start interview CTA lives in the Pro status band when allowed.
            if (!empty($detail['canattempt']) && !empty($detail['starturl'])) {
                $current['modurl'] = (string) $detail['starturl'];
            }
            $current['_nexinterview_canattempt'] = !empty($detail['canattempt']);
        } else {
            self::mark_module_viewed($course, $cmid);
            $html = self::module_intro_html((int) $current['id'], $modname);
            $statusitems[] = [
                'label' => get_string('activitytype', 'format_nexcoursepro'),
                'value' => (string) ($current['typelabel'] ?? $modname),
            ];
        }

        // Refresh after possible view completion write (viewed is once per user+cm).
        if ($cmid > 0 && !empty($USER->id)) {
            try {
                if ($kind === 'h5p') {
                    self::refresh_h5p_completion_state($course, $cmid, (int) $USER->id);
                }
                $modinfo = get_fast_modinfo($course);
                $cm = $modinfo->get_cm($cmid);
                $completion = new completion_info($course);
                $current['completed'] = self::activity_rail_complete($cm, $completion, (int) $USER->id, $course);
                $current['failed'] = empty($current['completed'])
                    && self::activity_is_failed($cm, $completion, (int) $USER->id);
            } catch (\Throwable $e) {
                // Keep prior completed flag.
            }
        }

        $kindlabel = self::kind_label($kind, (string) ($current['typelabel'] ?? ''));

        // Status / Completed chip above the player — keep for quiz; skip for H5P
        // (rail + hero criteria already reflect completion).
        if (!empty($current['completed']) && $kind !== 'h5p') {
            array_unshift($statusitems, [
                'label' => get_string('completionstatus', 'format_nexcoursepro'),
                'value' => get_string('completed', 'format_nexcoursepro'),
            ]);
        }

        $ctaurl = (string) ($current['modurl'] ?? '');
        $ctalabel = self::cta_label($kind);
        // Quiz: all attempt/preview/grade UI comes from Moodle's view renderer — no extra CTA.
        $showcta = $ctaurl !== '' && $kind !== 'page' && $kind !== 'quiz';
        $showsecondary = ($kind === 'page' || $kind === 'quiz') && $ctaurl !== '';
        $secondaryurl = $ctaurl;
        $secondarylabel = $kind === 'quiz'
            ? get_string('openoriginal', 'format_nexcoursepro')
            : get_string('openoriginal', 'format_nexcoursepro');
        if ($kind === 'quiz') {
            // Prefer in-pane quiz view; keep a quiet secondary only if render failed.
            $showsecondary = trim(strip_tags($html)) === '' && $ctaurl !== '';
            $secondarylabel = get_string('openactivity', 'format_nexcoursepro');
        }
        if ($kind === 'nexinterview') {
            // Details render in-pane; Start interview is the primary CTA when allowed.
            $showcta = $ctaurl !== '' && !empty($current['_nexinterview_canattempt']);
            $showsecondary = false;
            unset($current['_nexinterview_canattempt']);
        }

        $hashhtml = trim(strip_tags($html)) !== '';
        $hasmedia = $mediaurl !== '';

        // In-pane video / H5P player replaces the open CTA.
        // Drop Type/Source chips — eyebrow already names the activity kind.
        if ($kind === 'video' || $kind === 'h5p') {
            $statusitems = array_values(array_filter($statusitems, static function ($chip) {
                $label = (string) ($chip['label'] ?? '');
                return $label !== get_string('activitytype', 'format_nexcoursepro')
                    && $label !== get_string('source', 'format_nexcoursepro')
                    && $label !== get_string('filename', 'format_nexcoursepro')
                    && $label !== get_string('completionstatus', 'format_nexcoursepro');
            }));
            if ($hasmedia) {
                $showcta = false;
            }
        }

        // Modern quiz tabs own the overview/actions/attempts UI.
        if ($kind === 'quiz' && !empty($quiztabs['hasquiztabs'])) {
            $showcta = false;
            $showsecondary = false;
            $hashhtml = false;
            $html = '';
            // Prefer chips built for the quiz, then re-apply completion.
            $statusitems = $quiztabs['statusitems'] ?? $statusitems;
            if (!empty($current['completed'])) {
                $already = false;
                foreach ($statusitems as $chip) {
                    if (($chip['label'] ?? '') === get_string('completionstatus', 'format_nexcoursepro')) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    array_unshift($statusitems, [
                        'label' => get_string('completionstatus', 'format_nexcoursepro'),
                        'value' => get_string('completed', 'format_nexcoursepro'),
                    ]);
                }
            }
            $quiztabs['statusitems'] = $statusitems;
            // Chips live inside Overview — keep hasstatus true when chips exist.
            if (!empty($statusitems)) {
                $quiztabs['hasstatus'] = true;
            }
        }

        $hasstatus = !empty($statusitems) || $showcta || !empty($current['completed']);
        if ($kind === 'quiz' && !empty($quiztabs['hasquiztabs'])) {
            $hasstatus = !empty($statusitems) || !empty($quiztabs['hasquizactions'])
                || !empty($quiztabs['hasquizintro']) || !empty($quiztabs['hasquizbody']);
        }
        // H5P: never keep the grey status band for a lone Completed chip.
        if ($kind === 'h5p') {
            $hasstatus = !empty($statusitems) || $showcta || $showsecondary;
        }

        // Quiz tabs embed completion in the Overview body — skip duplicate render.
        $completionhtml = '';
        $hascompletion = false;
        if (!($kind === 'quiz' && !empty($quiztabs['hasquiztabs']))) {
            $completionhtml = self::render_completion_html((int) $current['id'], $page, $course);
            $hascompletion = trim(strip_tags($completionhtml)) !== '';
        }
        // Completion criteria alone should not keep the grey status band open —
        // they live under the title in the hero now.
        if ($hascompletion && !($kind === 'quiz' && !empty($quiztabs['hasquiztabs']))) {
            if (empty($statusitems) && !$showcta && !$showsecondary && empty($current['completed'])) {
                $hasstatus = false;
            }
        }

        $gradeinfo = [
            'hasactivitygrade' => false,
            'gradedisplay' => '',
        ];
        if ($cmid > 0 && !empty($USER->id) && !empty($current['completed'])) {
            try {
                $modinfo = get_fast_modinfo($course);
                $cm = $modinfo->get_cm($cmid);
                $gradeinfo = self::activity_grade_display((int) $course->id, $cm, (int) $USER->id);
            } catch (\Throwable $e) {
                // Keep empty grade.
            }
        }

        return array_merge([
            'hasactivity' => true,
            'kind' => $kind,
            'kindlabel' => $kindlabel,
            'eyebrow' => $kindlabel,
            'sectionlabel' => $sectionname,
            'title' => $current['name'],
            'html' => $html,
            'hashhtml' => $hashhtml,
            'hasmedia' => $hasmedia,
            'mediaurl' => $mediaurl,
            'mediakind' => $mediakind,
            'isvideofile' => $mediakind === 'video',
            'isaudiofile' => $mediakind === 'audio',
            'isexternallink' => $mediakind === 'external',
            'isembed' => $mediakind === 'embed',
            'showcta' => $showcta,
            'ctaurl' => $ctaurl,
            'ctalabel' => $ctalabel,
            'showsecondary' => $showsecondary,
            'secondaryurl' => $secondaryurl,
            'secondarylabel' => $secondarylabel,
            'statusitems' => $statusitems,
            'hasstatus' => $hasstatus,
            'completed' => !empty($current['completed']),
            'failed' => !empty($current['failed']),
            'completionhtml' => $completionhtml,
            'hascompletion' => $hascompletion,
            'hasactivitygrade' => !empty($gradeinfo['hasactivitygrade']),
            'gradedisplay' => (string) ($gradeinfo['gradedisplay'] ?? ''),
            'modurl' => $ctaurl,
            'modname' => $modname,
            'typelabel' => (string) ($current['typelabel'] ?? ''),
            'iconurl' => (string) ($current['iconurl'] ?? ''),
            'hasicon' => !empty($current['hasicon']),
            'cmid' => (int) $current['id'],
            'sectionnum' => (int) $current['sectionnum'],
            'viewurl' => (string) ($current['viewurl'] ?? ''),
            'showlaunch' => false,
            'showembed' => false,
            'embedurl' => '',
            'launchlabel' => $ctalabel,
        ], $quiztabs);
    }

    /**
     * @param string $modname
     * @return string page|quiz|video|h5p|nexinterview|activity
     */
    private static function activity_kind(string $modname): string {
        $mod = strtolower($modname);
        if ($mod === 'page') {
            return 'page';
        }
        if ($mod === 'quiz') {
            return 'quiz';
        }
        if ($mod === 'nexinterview') {
            return 'nexinterview';
        }
        if ($mod === 'h5pactivity' || $mod === 'h5p' || $mod === 'hvp') {
            return 'h5p';
        }
        $videomods = [
            'videotime' => true,
            'interactivevideo' => true,
            'edwiservideo' => true,
            'edwvideo' => true,
            'remuivideo' => true,
            'edwiservideoactivity' => true,
        ];
        if (isset($videomods[$mod])) {
            return 'video';
        }
        $compact = str_replace(['_', '-'], '', $mod);
        if (str_contains($compact, 'edwiservideo')
                || str_contains($compact, 'edwvideo')
                || str_contains($compact, 'remuivideo')
                || (str_contains($compact, 'video') && (str_contains($compact, 'edwiser') || str_contains($compact, 'remui')))) {
            return 'video';
        }
        return 'activity';
    }

    /**
     * @param string $kind
     * @param string $fallback
     * @return string
     */
    private static function kind_label(string $kind, string $fallback): string {
        return match ($kind) {
            'quiz' => get_string('kindassessment', 'format_nexcoursepro'),
            'video' => get_string('kindvideo', 'format_nexcoursepro'),
            'h5p' => get_string('kindh5p', 'format_nexcoursepro'),
            'page' => get_string('kindlesson', 'format_nexcoursepro'),
            'nexinterview' => get_string('kindinterview', 'format_nexcoursepro'),
            default => ($fallback !== '' ? $fallback : get_string('kindactivity', 'format_nexcoursepro')),
        };
    }

    /**
     * @param string $kind
     * @return string
     */
    private static function cta_label(string $kind): string {
        return match ($kind) {
            'quiz' => get_string('startassessment', 'format_nexcoursepro'),
            'video' => get_string('watchvideo', 'format_nexcoursepro'),
            'nexinterview' => get_string('startinterview', 'format_nexcoursepro'),
            default => get_string('openactivity', 'format_nexcoursepro'),
        };
    }

    /**
     * NexInterview activity landing for the left pane (details + Start CTA).
     *
     * @param int $cmid
     * @return array{html:string, statusitems:array, canattempt:bool, starturl:string}
     */
    private static function nexinterview_view_detail(int $cmid): array {
        global $CFG, $OUTPUT, $PAGE;

        $empty = [
            'html' => '',
            'statusitems' => [],
            'canattempt' => false,
            'starturl' => '',
        ];

        try {
            if (!file_exists($CFG->dirroot . '/mod/nexinterview/lib.php')) {
                return $empty;
            }
            require_once($CFG->dirroot . '/mod/nexinterview/lib.php');
            if (!function_exists('nexinterview_export_view_context')) {
                return $empty;
            }

            $cm = get_coursemodule_from_id('nexinterview', $cmid, 0, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            require_capability('mod/nexinterview:view', $context);

            // Ensure pane styles apply when this activity is opened via AJAX.
            $PAGE->requires->css('/mod/nexinterview/styles.css');

            $ctx = nexinterview_export_view_context($cm, null, ['inpane' => true]);
            $html = $OUTPUT->render_from_template('mod_nexinterview/view', $ctx);

            $statusitems = [];
            if (!empty($ctx['hasprofile']) && !empty($ctx['profilename'])) {
                $statusitems[] = [
                    'label' => get_string('profilelabel', 'nexinterview'),
                    'value' => (string) $ctx['profilename'],
                ];
            }
            if (!empty($ctx['duration'])) {
                $statusitems[] = [
                    'label' => get_string('timelimit', 'format_nexcoursepro'),
                    'value' => get_string('minutes', 'nexinterview', (int) $ctx['duration']),
                ];
            }

            return [
                'html' => $html,
                'statusitems' => $statusitems,
                'canattempt' => !empty($ctx['canattempt']),
                'starturl' => (string) ($ctx['starturl'] ?? ''),
            ];
        } catch (\Throwable $e) {
            debugging('nexinterview pane failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Quiz Overview / Attempts payload — Moodle view HTML styled like NexCourse/LL Assessment.
     *
     * @param int $cmid
     * @param moodle_page $page
     * @return array
     */
    private static function quiz_view_detail(int $cmid, moodle_page $page): array {
        global $CFG, $USER, $OUTPUT;

        $empty = [
            'hasquiztabs' => true,
            'quizintro' => '',
            'hasquizintro' => false,
            'quizmessages' => [],
            'hasquizmessages' => false,
            'quizactionshtml' => '',
            'hasquizactions' => false,
            'quizbodyhtml' => '',
            'hasquizbody' => false,
            'quizsections' => [],
            'hasquizsections' => false,
            'quizcourseurl' => '',
            'quizattempts' => [],
            'hasquizattempts' => false,
            'quizattemptcount' => 0,
            'quizbestgrade' => '',
            'hasquizbestgrade' => false,
            'quizsections' => [],
            'hasquizsections' => false,
            'quizcourseurl' => '',
            'statusitems' => [],
        ];

        try {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->libdir . '/gradelib.php');

            if (!class_exists('\\mod_quiz\\quiz_settings')) {
                throw new \moodle_exception('quizmissing', 'format_nexcoursepro');
            }

            $quizobj = \mod_quiz\quiz_settings::create_for_cmid($cmid, (int) $USER->id);
            $quiz = $quizobj->get_quiz();
            $cm = $quizobj->get_cm();
            $course = $quizobj->get_course();
            $context = $quizobj->get_context();
            require_capability('mod/quiz:view', $context);

            // Native mod/quiz/view.php calls quiz_view() here — marks "Require view"
            // once per user (not per attempt). attempt.php / startattempt.php do not.
            self::mark_quiz_viewed_if_needed($quiz, $course, $cm, $context);

            $page->set_cm($cm, $course, $quiz);
            $page->set_context($context);
            $page->set_url('/mod/quiz/view.php', ['id' => $cm->id]);
            $page->set_title(format_string($quizobj->get_quiz_name()));
            $page->set_heading(format_string($course->fullname));
            $page->set_pagelayout('incourse');
            // Keep activity header enabled so completion requirements render in the pane.

            $canattempt = has_capability('mod/quiz:attempt', $context);
            $canreviewmine = has_capability('mod/quiz:reviewmyattempts', $context);
            $canpreview = has_capability('mod/quiz:preview', $context);

            $timenow = time();
            $accessmanager = new \mod_quiz\access_manager(
                $quizobj,
                $timenow,
                has_capability('mod/quiz:ignoretimelimits', $context, null, false)
            );

            $viewobj = new \mod_quiz\output\view_page();
            $viewobj->accessmanager = $accessmanager;
            $viewobj->canreviewmine = $canreviewmine || $canpreview;

            $attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
            $lastfinishedattempt = end($attempts);
            $unfinished = false;
            $unfinishedattemptid = null;
            if ($unfinishedattempt = quiz_get_user_attempt_unfinished($quiz->id, $USER->id)) {
                $attempts[] = $unfinishedattempt;
                $quizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);
                $unfinished = $unfinishedattempt->state == \mod_quiz\quiz_attempt::IN_PROGRESS
                    || $unfinishedattempt->state == \mod_quiz\quiz_attempt::OVERDUE;
                if (!$unfinished) {
                    $lastfinishedattempt = $unfinishedattempt;
                }
                $unfinishedattemptid = $unfinishedattempt->id;
            }
            $numattempts = count($attempts);

            $gradeitemmarks = [];
            if (method_exists($quizobj, 'get_grade_calculator')) {
                try {
                    $gradeitemmarks = $quizobj->get_grade_calculator()->compute_grade_item_totals_for_attempts(
                        array_column($attempts, 'uniqueid')
                    );
                } catch (\Throwable $e) {
                    $gradeitemmarks = [];
                }
            }

            $viewobj->attempts = $attempts;
            $viewobj->attemptobjs = [];
            foreach ($attempts as $attempt) {
                $attemptobj = new \mod_quiz\quiz_attempt($attempt, $quiz, $cm, $course, false);
                if (isset($gradeitemmarks[$attempt->uniqueid]) && method_exists($attemptobj, 'set_grade_item_totals')) {
                    $attemptobj->set_grade_item_totals($gradeitemmarks[$attempt->uniqueid]);
                }
                $viewobj->attemptobjs[] = $attemptobj;
            }

            if (class_exists('\\mod_quiz\\output\\list_of_attempts')) {
                $viewobj->attemptslist = new \mod_quiz\output\list_of_attempts($timenow);
                foreach (array_reverse($viewobj->attemptobjs) as $attemptobj) {
                    $viewobj->attemptslist->add_attempt($attemptobj);
                }
            }

            if (!$canpreview) {
                $mygrade = quiz_get_best_grade($quiz, $USER->id);
            } else if ($lastfinishedattempt) {
                $mygrade = quiz_rescale_grade($lastfinishedattempt->sumgrades, $quiz, false);
            } else {
                $mygrade = null;
            }

            $mygradeoverridden = false;
            $gradebookfeedback = '';
            $gradeitem = \grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id,
                'itemnumber' => 0,
                'courseid' => $course->id,
            ]);
            if (!$canpreview && $gradeitem) {
                $grade = $gradeitem->get_grade($USER->id, false);
                $mygrade = $grade->finalgrade;
                if ($grade->overridden) {
                    if ($gradeitem->needsupdate) {
                        $mygrade = 0;
                    }
                    $mygradeoverridden = true;
                }
                if (!empty($grade->feedback)) {
                    $gradebookfeedback = $grade->feedback;
                }
            }

            if ($attempts) {
                list($someoptions, $alloptions) = quiz_get_combined_reviewoptions($quiz, $attempts);
                $viewobj->attemptcolumn = $quiz->attempts != 1;
                $viewobj->gradecolumn = $someoptions->marks >= \question_display_options::MARK_AND_MAX
                    && quiz_has_grades($quiz);
                $viewobj->markcolumn = $viewobj->gradecolumn && ($quiz->grade != $quiz->sumgrades);
                $viewobj->overallstats = $lastfinishedattempt
                    && $alloptions->marks >= \question_display_options::MARK_AND_MAX;
                $viewobj->feedbackcolumn = quiz_has_feedback($quiz) && $alloptions->overallfeedback;
            }

            $viewobj->timenow = $timenow;
            $viewobj->numattempts = $numattempts;
            $viewobj->mygrade = $mygrade;
            $viewobj->moreattempts = $unfinished
                || !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
            $viewobj->mygradeoverridden = $mygradeoverridden;
            $viewobj->gradebookfeedback = $gradebookfeedback;
            $viewobj->lastfinishedattempt = $lastfinishedattempt;
            $viewobj->canedit = has_capability('mod/quiz:manage', $context);
            $viewobj->editurl = new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cm->id]);
            $viewobj->backtocourseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
            $viewobj->startattempturl = $quizobj->start_attempt_url();

            if ($accessmanager->is_preflight_check_required($unfinishedattemptid)) {
                $viewobj->preflightcheckform = $accessmanager->get_preflight_check_form(
                    $viewobj->startattempturl,
                    $unfinishedattemptid
                );
            }
            $viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
            $viewobj->popupoptions = $accessmanager->get_popup_options();

            $viewobj->infomessages = $viewobj->accessmanager->describe_rules();
            if ($quiz->attempts != 1) {
                $viewobj->infomessages[] = get_string(
                    'gradingmethod',
                    'quiz',
                    quiz_get_grading_option_name($quiz->grademethod)
                );
            }
            if ($gradeitem && grade_floats_different($gradeitem->gradepass, 0)) {
                $a = (object) [
                    'grade' => quiz_format_grade($quiz, $gradeitem->gradepass),
                    'maxgrade' => quiz_format_grade($quiz, $quiz->grade),
                ];
                $viewobj->infomessages[] = get_string('gradetopassoutof', 'quiz', $a);
            }

            $viewobj->quizhasquestions = $quizobj->has_questions();
            $viewobj->preventmessages = [];
            $viewobj->buttontext = '';
            if ($viewobj->quizhasquestions) {
                if ($unfinished) {
                    if ($canpreview) {
                        $viewobj->buttontext = get_string('continuepreview', 'quiz');
                    } else if ($canattempt) {
                        $viewobj->buttontext = get_string('continueattemptquiz', 'quiz');
                    }
                } else {
                    if ($canpreview) {
                        $viewobj->buttontext = get_string('previewquizstart', 'quiz');
                    } else if ($canattempt) {
                        $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                            $viewobj->numattempts,
                            $viewobj->lastfinishedattempt
                        );
                        if ($viewobj->preventmessages) {
                            $viewobj->buttontext = '';
                        } else if ($viewobj->numattempts == 0) {
                            $viewobj->buttontext = get_string('attemptquiz', 'quiz');
                        } else {
                            $viewobj->buttontext = get_string('reattemptquiz', 'quiz');
                        }
                    }
                }

                if ($canpreview) {
                    $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
                } else if ($viewobj->buttontext) {
                    if (!$viewobj->moreattempts) {
                        $viewobj->buttontext = '';
                    } else if ($canattempt) {
                        $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
                        if ($viewobj->preventmessages) {
                            $viewobj->buttontext = '';
                        }
                    }
                }

                if (method_exists($quizobj, 'get_all_question_types_used')
                        && in_array('missingtype', $quizobj->get_all_question_types_used(), true)) {
                    if (class_exists('\\core\\output\\notification')) {
                        $viewobj->preventmessages[] = $OUTPUT->notification(
                            get_string('quizinvalidquestions', 'mod_quiz'),
                            \core\output\notification::NOTIFY_ERROR,
                            false
                        );
                    }
                    $viewobj->buttontext = '';
                }
            }

            $viewobj->showbacktocourse = true;

            /** @var \mod_quiz\output\renderer $output */
            $output = $page->get_renderer('mod_quiz');

            if (isguestuser()) {
                $html = $output->view_page_guest($course, $quiz, $cm, $context, $viewobj->infomessages, $viewobj);
            } else if (!($canattempt || $canpreview || $viewobj->canreviewmine)) {
                $html = $output->view_page_notenrolled($course, $quiz, $cm, $context, $viewobj->infomessages, $viewobj);
            } else {
                $html = $output->view_page($course, $quiz, $cm, $context, $viewobj);
            }

            // Prefer Moodle's own activity-information block inside view_page HTML.
            // Only build a separate completion strip when the renderer omitted it
            // (avoids a second activity_information render on every quiz click).
            $activityinfohtml = '';
            if (!preg_match('/activity-information|completion-info|automatic-completion-conditions/i', $html)) {
                $activityinfohtml = self::render_completion_html((int) $cm->id, $page, $course);
            }

            // Section outline once (was previously computed twice + loaded the full
            // question bank structure — work native mod/quiz/view.php never does).
            $sections = self::quiz_section_outline((int) $cm->id, false);
            $outlinehtml = self::quiz_outline_html((int) $cm->id, $sections);
            $timinghtml = self::quiz_timing_html($quiz);
            $noticeshtml = self::quiz_notices_html($quiz);
            $courseurl = (new \moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false);

            $quizactionshtml = '';
            if (!empty($viewobj->buttontext)) {
                // Prefer Moodle's native start/preview control (keeps preflight AMD wiring).
                try {
                    $quizactionshtml = $output->start_attempt_button(
                        $viewobj->buttontext,
                        $viewobj->startattempturl,
                        $viewobj->preflightcheckform ?? null,
                        !empty($viewobj->popuprequired),
                        $viewobj->popupoptions ?? null
                    );
                } catch (\Throwable $e) {
                    $quizactionshtml = '';
                }
                // Guaranteed visible fallback if renderer returns nothing usable.
                if (trim(strip_tags($quizactionshtml)) === '') {
                    $quizactionshtml = '<div class="nxpro-quiz__cta-wrap singlebutton quizstartbuttondiv">'
                        . '<a class="btn btn-primary nxpro-av__cta" href="'
                        . s($viewobj->startattempturl->out(false)) . '">'
                        . s($viewobj->buttontext) . '</a></div>';
                }
            }

            $bodyhtml = '<div class="nxpro-qv" data-region="nxpro-qv" data-courseurl="'
                . s($courseurl) . '">'
                . $timinghtml
                . $noticeshtml
                . ($activityinfohtml !== ''
                    ? '<div class="nxpro-qv__activityinfo-src" data-region="nxpro-qv-activityinfo">'
                        . $activityinfohtml . '</div>'
                    : '')
                . '<div class="nxpro-qv__moodle" data-region="nxpro-qv-moodle">' . $html . '</div>'
                . $outlinehtml
                . '</div>';

            $statusitems = [];
            if (!empty($quiz->timeopen)) {
                $statusitems[] = [
                    'label' => get_string('quizstarttime', 'format_nexcoursepro'),
                    'value' => userdate((int) $quiz->timeopen),
                ];
            }
            if (!empty($quiz->timeclose)) {
                $statusitems[] = [
                    'label' => get_string('quizendtime', 'format_nexcoursepro'),
                    'value' => userdate((int) $quiz->timeclose),
                ];
            }

            $bestgrade = '';
            if ($mygrade !== null && quiz_has_grades($quiz)) {
                $bestgrade = quiz_format_grade($quiz, $mygrade);
                if (!empty($quiz->grade)) {
                    $bestgrade .= ' / ' . quiz_format_grade($quiz, $quiz->grade);
                }
            }

            return [
                'hasquiztabs' => true,
                'quizintro' => '',
                'hasquizintro' => false,
                'quizmessages' => [],
                'hasquizmessages' => false,
                'quizactionshtml' => $quizactionshtml,
                'hasquizactions' => trim(strip_tags($quizactionshtml)) !== '',
                'quizbodyhtml' => $bodyhtml,
                'hasquizbody' => true,
                'quizattempts' => [],
                'hasquizattempts' => $numattempts > 0,
                'quizattemptcount' => $numattempts,
                'quizbestgrade' => $bestgrade,
                'hasquizbestgrade' => $bestgrade !== '',
                'quizsections' => $sections,
                'hasquizsections' => !empty($sections),
                'quizcourseurl' => $courseurl,
                'statusitems' => $statusitems,
            ];
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro quiz_view_detail: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $intro = self::module_intro_html($cmid, 'quiz');
            $quizurl = (new \moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false);
            $empty['quizintro'] = $intro;
            $empty['hasquizintro'] = trim(strip_tags($intro)) !== '';
            $empty['quizactionshtml'] = '<p class="nxpro-quiz__cta-wrap"><a class="nxpro-av__cta" href="'
                . s($quizurl) . '">' . s(get_string('startassessment', 'format_nexcoursepro')) . '</a></p>';
            $empty['hasquizactions'] = true;
            $empty['quizmessages'] = [['text' => get_string('quizloaddetailhint', 'format_nexcoursepro')]];
            $empty['hasquizmessages'] = true;
            $empty['statusitems'] = [[
                'label' => get_string('activitytype', 'format_nexcoursepro'),
                'value' => get_string('kindassessment', 'format_nexcoursepro'),
            ]];
            return $empty;
        }
    }

    /**
     * Question outline rows for a quiz (section / questions / marks).
     *
     * Uses quiz_slots / quiz_sections only by default. Loading the full question
     * bank structure (qbank_helper) is expensive and is what native quiz view
     * does not do on /mod/quiz/view.php — keep it opt-in.
     *
     * @param int $cmid
     * @param bool $withqtypes Resolve question types (skips description items)
     * @return array
     */
    private static function quiz_section_outline(int $cmid, bool $withqtypes = false): array {
        global $DB;

        if ($cmid < 1) {
            return [];
        }
        try {
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return [];
            }
            $quizid = (int) $cm->instance;
            $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, sumgrades, decimalpoints, questiondecimalpoints');
            $quizcol = 'quizid';
            try {
                $cols = $DB->get_columns('quiz_slots');
                if (!isset($cols['quizid']) && isset($cols['quiz'])) {
                    $quizcol = 'quiz';
                }
            } catch (\Throwable $e) {
                $quizcol = 'quizid';
            }

            $slotrows = $DB->get_records_sql(
                "SELECT slot, maxmark FROM {quiz_slots} WHERE {$quizcol} = :quizid ORDER BY slot ASC",
                ['quizid' => $quizid]
            );
            if (!$slotrows) {
                return [];
            }

            $qtypes = [];
            $maxmarks = [];
            foreach ($slotrows as $row) {
                $n = (int) $row->slot;
                $maxmarks[$n] = (float) ($row->maxmark ?? 0);
            }
            // Optional: only when exact qtype filtering is required.
            if ($withqtypes && class_exists('\\mod_quiz\\question\\bank\\qbank_helper')) {
                try {
                    $ctx = \context_module::instance($cmid);
                    $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $ctx);
                    foreach ($slots as $slot) {
                        $n = (int) ($slot->slot ?? 0);
                        if ($n < 1) {
                            continue;
                        }
                        $qtype = strtolower(trim((string) ($slot->qtype ?? '')));
                        $qtypes[$n] = $qtype !== '' ? $qtype : 'other';
                        if (isset($slot->maxmark)) {
                            $maxmarks[$n] = (float) $slot->maxmark;
                        }
                    }
                } catch (\Throwable $e) {
                    // Keep SQL maxmarks.
                }
            }

            $sectioncol = 'quizid';
            try {
                $cols = $DB->get_columns('quiz_sections');
                if (!isset($cols['quizid']) && isset($cols['quiz'])) {
                    $sectioncol = 'quiz';
                }
            } catch (\Throwable $e) {
                $sectioncol = 'quizid';
            }
            $sections = $DB->get_records('quiz_sections', [$sectioncol => $quizid], 'firstslot ASC');
            if (!$sections) {
                $sections = [(object) ['heading' => '', 'firstslot' => 1]];
            }

            $ranges = array_values($sections);
            $out = [];
            $index = 1;
            foreach ($ranges as $i => $section) {
                $first = (int) ($section->firstslot ?? 1);
                $next = isset($ranges[$i + 1]) ? (int) $ranges[$i + 1]->firstslot : PHP_INT_MAX;
                $count = 0;
                $marks = 0.0;
                foreach ($maxmarks as $slotno => $maxmark) {
                    if ($slotno < $first || $slotno >= $next) {
                        continue;
                    }
                    $qtype = $qtypes[$slotno] ?? 'other';
                    if ($qtype === 'description') {
                        continue;
                    }
                    // Without qtypes, skip zero-mark slots (typical description items).
                    if (!$withqtypes && (float) $maxmark <= 0) {
                        continue;
                    }
                    $count++;
                    $marks += (float) $maxmark;
                }
                if ($count < 1) {
                    $index++;
                    continue;
                }
                $heading = trim(format_string((string) ($section->heading ?? '')));
                if ($heading === '') {
                    $heading = get_string('quizsectionn', 'format_nexcoursepro', $index);
                }
                $out[] = [
                    'name' => $heading,
                    'count' => $count,
                    'marks' => $marks,
                    'marksdisplay' => self::quiz_format_marks($quiz, $marks),
                ];
                $index++;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param string $qtype
     * @return string
     */
    private static function quiz_qtype_label(string $qtype): string {
        $qtype = strtolower(trim($qtype));
        $map = [
            'coderunner' => get_string('qtypecoding', 'format_nexcoursepro'),
            'multichoice' => get_string('qtypemultichoice', 'format_nexcoursepro'),
            'truefalse' => get_string('qtypetruefalse', 'format_nexcoursepro'),
            'shortanswer' => get_string('qtypeshortanswer', 'format_nexcoursepro'),
            'numerical' => get_string('qtypenumerical', 'format_nexcoursepro'),
            'essay' => get_string('qtypeessay', 'format_nexcoursepro'),
            'match' => get_string('qtypematch', 'format_nexcoursepro'),
        ];
        if (isset($map[$qtype])) {
            return $map[$qtype];
        }
        try {
            $label = get_string('pluginname', 'qtype_' . $qtype);
            if ($label !== '' && $label !== '[[pluginname]]') {
                return $label;
            }
        } catch (\Throwable $e) {
            // Fall through.
        }
        return $qtype !== '' ? ucfirst($qtype) : get_string('qtypeother', 'format_nexcoursepro');
    }

    /**
     * Start / end time chips when the quiz has open or close dates.
     *
     * @param \stdClass $quiz
     * @return string
     */
    private static function quiz_timing_html($quiz): string {
        $chips = '';
        if (!empty($quiz->timeopen)) {
            $chips .= '<div class="nxpro-av__chip">'
                . '<span class="nxpro-av__chip-label">' . s(get_string('quizstarttime', 'format_nexcoursepro')) . '</span>'
                . '<strong class="nxpro-av__chip-value">' . s(userdate((int) $quiz->timeopen)) . '</strong>'
                . '</div>';
        }
        if (!empty($quiz->timeclose)) {
            $chips .= '<div class="nxpro-av__chip">'
                . '<span class="nxpro-av__chip-label">' . s(get_string('quizendtime', 'format_nexcoursepro')) . '</span>'
                . '<strong class="nxpro-av__chip-value">' . s(userdate((int) $quiz->timeclose)) . '</strong>'
                . '</div>';
        }
        if ($chips === '') {
            return '';
        }
        return '<div class="nxpro-av__status-grid nxpro-quiz__timing" data-region="nxpro-quiz-timing">'
            . $chips . '</div>';
    }

    /**
     * Availability + proctoring notices for the Overview panel.
     *
     * @param \stdClass $quiz
     * @return string
     */
    private static function quiz_notices_html($quiz): string {
        global $CFG;

        $items = '';
        $now = time();

        if (!empty($quiz->timeopen) && $now < (int) $quiz->timeopen) {
            $items .= '<div class="nxpro-av__chip nxpro-quiz__notice nxpro-quiz__notice--warn" data-notice="availability">'
                . '<span class="nxpro-av__chip-label">' . s(get_string('quizavailability', 'format_nexcoursepro')) . '</span>'
                . '<strong class="nxpro-av__chip-value">'
                . s(get_string('quiznotavailableyet', 'format_nexcoursepro', userdate((int) $quiz->timeopen)))
                . '</strong></div>';
        } else if (!empty($quiz->timeclose) && $now > (int) $quiz->timeclose) {
            $items .= '<div class="nxpro-av__chip nxpro-quiz__notice nxpro-quiz__notice--warn" data-notice="availability">'
                . '<span class="nxpro-av__chip-label">' . s(get_string('quizavailability', 'format_nexcoursepro')) . '</span>'
                . '<strong class="nxpro-av__chip-value">'
                . s(get_string('quiznolongeravailable', 'format_nexcoursepro', userdate((int) $quiz->timeclose)))
                . '</strong></div>';
        }

        $proctortext = self::quiz_proctor_notice_text((int) ($quiz->id ?? 0));
        if ($proctortext !== '') {
            $items .= '<div class="nxpro-av__chip nxpro-quiz__notice nxpro-quiz__notice--proctor" data-notice="proctor">'
                . '<span class="nxpro-av__chip-label">' . s(get_string('quizproctoring', 'format_nexcoursepro')) . '</span>'
                . '<strong class="nxpro-av__chip-value">' . s($proctortext) . '</strong></div>';
        }

        if ($items === '') {
            return '';
        }
        return '<div class="nxpro-av__status-grid nxpro-quiz__notices" data-region="nxpro-quiz-notices">'
            . $items . '</div>';
    }

    /**
     * Build "This test requires …" from enabled NexProctor settings only.
     *
     * @param int $quizid
     * @return string
     */
    private static function quiz_proctor_notice_text(int $quizid): string {
        global $CFG;

        if ($quizid < 1) {
            return '';
        }
        $lib = $CFG->dirroot . '/local/nexproctor/lib.php';
        if (!is_readable($lib)) {
            return '';
        }
        try {
            require_once($lib);
            if (!function_exists('local_nexproctor_get_quiz_settings')) {
                return '';
            }
            $settings = local_nexproctor_get_quiz_settings($quizid);
            if (empty($settings->nexproctorenabled)) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        $map = [
            'requirecamera' => 'quizfeature_camera',
            'requiremic' => 'quizfeature_mic',
            'requirescreenshare' => 'quizfeature_screenshare',
            'requirefullscreen' => 'quizfeature_fullscreen',
            'blockmultimonitor' => 'quizfeature_multimonitor',
            'detectfaces' => 'quizfeature_faces',
            'detectnoise' => 'quizfeature_noise',
            'detecttabswitch' => 'quizfeature_tabswitch',
            'detectattention' => 'quizfeature_attention',
            'photoonviolation' => 'quizfeature_photo',
        ];
        $features = [];
        foreach ($map as $field => $stringid) {
            if (!empty($settings->$field)) {
                $features[] = get_string($stringid, 'format_nexcoursepro');
            }
        }
        if (!$features) {
            return get_string('quizproctored', 'format_nexcoursepro');
        }
        return get_string('quizproctorrequires', 'format_nexcoursepro', self::quiz_join_list($features));
    }

    /**
     * @param string[] $items
     * @return string
     */
    private static function quiz_join_list(array $items): string {
        $items = array_values($items);
        $n = count($items);
        if ($n === 1) {
            return $items[0];
        }
        if ($n === 2) {
            return $items[0] . ' and ' . $items[1];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }

    /**
     * Format a marks value for the outline table.
     *
     * @param \stdClass|false|null $quiz
     * @param float $marks
     * @return string
     */
    private static function quiz_format_marks($quiz, float $marks): string {
        if ($quiz && function_exists('quiz_format_grade')) {
            return quiz_format_grade($quiz, $marks);
        }
        if (function_exists('format_float')) {
            return format_float($marks, 2, true, true);
        }
        return rtrim(rtrim(number_format($marks, 2, '.', ''), '0'), '.');
    }

    /**
     * HTML for question outline table (JS moves it into the status panel).
     *
     * @param int $cmid
     * @param array|null $sections Precomputed outline rows (avoids a second DB/qbank pass)
     * @return string
     */
    private static function quiz_outline_html(int $cmid, ?array $sections = null): string {
        global $DB;

        if ($sections === null) {
            $sections = self::quiz_section_outline($cmid, false);
        }
        if (!$sections) {
            return '';
        }

        $totalq = 0;
        $totalm = 0.0;
        $rows = '';
        foreach ($sections as $section) {
            $totalq += (int) $section['count'];
            $totalm += (float) $section['marks'];
            $rows .= '<tr>'
                . '<td class="nxpro-quiz__outline-name">' . s($section['name']) . '</td>'
                . '<td class="nxpro-quiz__outline-count">' . s((string) ((int) $section['count'])) . '</td>'
                . '<td class="nxpro-quiz__outline-marks">' . s($section['marksdisplay']) . '</td>'
                . '</tr>';
        }

        $quiz = null;
        try {
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $quiz = $DB->get_record('quiz', ['id' => (int) $cm->instance], 'id, decimalpoints, questiondecimalpoints');
            }
        } catch (\Throwable $e) {
            $quiz = null;
        }

        $rows .= '<tr class="nxpro-quiz__outline-total">'
            . '<th scope="row" class="nxpro-quiz__outline-name">' . s(get_string('quizoutlinetotal', 'format_nexcoursepro')) . '</th>'
            . '<td class="nxpro-quiz__outline-count">' . s((string) $totalq) . '</td>'
            . '<td class="nxpro-quiz__outline-marks">' . s(self::quiz_format_marks($quiz, $totalm)) . '</td>'
            . '</tr>';

        return '<section class="nxpro-quiz__outline is-src" data-region="nxpro-qv-outline" hidden>'
            . '<h2 class="nxpro-quiz__section-label">' . s(get_string('quizsectionoutline', 'format_nexcoursepro')) . '</h2>'
            . '<div class="nxpro-quiz__outline-wrap">'
            . '<table class="nxpro-quiz__outline-table">'
            . '<thead><tr>'
            . '<th scope="col">' . s(get_string('quizsectioncol', 'format_nexcoursepro')) . '</th>'
            . '<th scope="col">' . s(get_string('quizoutlinequestions', 'format_nexcoursepro')) . '</th>'
            . '<th scope="col">' . s(get_string('quizoutlinemarks', 'format_nexcoursepro')) . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div></section>';
    }

    /**
     * In-pane H5P player (core mod_h5pactivity, or mod_hvp when present).
     *
     * Uses Moodle's /h5p/embed.php URL so SPA pane swaps keep a working player
     * without re-injecting core_h5p AMD assets into the course shell.
     *
     * @param int $cmid
     * @param string $modname
     * @return array{intro:string,mediaurl:string,mediakind:string,statusitems:array}
     */
    private static function h5p_view_detail(int $cmid, string $modname): array {
        global $CFG, $DB;

        $out = [
            'intro' => '',
            'mediaurl' => '',
            'mediakind' => '',
            'statusitems' => [],
        ];

        $modname = strtolower($modname);
        try {
            if ($modname === 'h5pactivity' || $modname === 'h5p') {
                if (!class_exists('\\mod_h5pactivity\\local\\manager') || !class_exists('\\core_h5p\\player')) {
                    return $out;
                }
                $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
                $manager = \mod_h5pactivity\local\manager::create_from_coursemodule($cm);
                $instance = $manager->get_instance();
                $context = $manager->get_context();
                $course = get_course((int) $cm->course);

                if (!empty($instance->intro)) {
                    $out['intro'] = format_module_intro('h5pactivity', $instance, $cm->id);
                }

                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
                $file = reset($files);
                if (!$file) {
                    return $out;
                }

                $fileurl = \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                );
                $embed = \core_h5p\player::get_embed_url($fileurl->out(false), 'mod_h5pactivity');
                $out['mediaurl'] = $embed instanceof \moodle_url ? $embed->out(false) : (string) $embed;
                $out['mediakind'] = 'embed';
                return $out;
            }

            // Third-party Interactive Content (mod_hvp).
            if ($modname === 'hvp') {
                $cm = get_coursemodule_from_id('hvp', $cmid, 0, false, MUST_EXIST);
                $record = $DB->get_record('hvp', ['id' => $cm->instance], '*', MUST_EXIST);
                if (!empty($record->intro)) {
                    $out['intro'] = format_module_intro('hvp', $record, $cm->id);
                }
                // Prefer embed endpoint when available.
                $embedpath = $CFG->dirroot . '/mod/hvp/embed.php';
                if (is_readable($embedpath)) {
                    $out['mediaurl'] = (new \moodle_url('/mod/hvp/embed.php', ['id' => $cm->id]))->out(false);
                    $out['mediakind'] = 'embed';
                }
                return $out;
            }
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro h5p_view_detail: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $out;
    }

    /**
     * Video / resource media for native pane.
     *
     * @param int $cmid
     * @param string $modname
     * @return array{intro:string,mediaurl:string,mediakind:string,statusitems:array}
     */
    private static function video_view_detail(int $cmid, string $modname): array {
        global $DB, $CFG;
        $intro = self::module_intro_html($cmid, $modname);
        $mediaurl = '';
        $mediakind = '';
        // Type / Source chips clutter the video pane — keep status empty.
        $status = [];

        try {
            if ($modname === 'resource') {
                require_once($CFG->dirroot . '/mod/resource/locallib.php');
                $cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
                $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
                $ctx = \context_module::instance($cm->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($ctx->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
                foreach ($files as $file) {
                    $mimetype = $file->get_mimetype();
                    if (str_starts_with((string) $mimetype, 'video/')) {
                        $mediaurl = \moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out(false);
                        $mediakind = 'video';
                        break;
                    }
                    if (str_starts_with((string) $mimetype, 'audio/')) {
                        $mediaurl = \moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out(false);
                        $mediakind = 'audio';
                        break;
                    }
                }
                if ($intro === '' && !empty($resource->intro)) {
                    $intro = format_module_intro('resource', $resource, $cm->id);
                }
            } else if ($modname === 'url') {
                $cm = get_coursemodule_from_id('url', $cmid, 0, false, MUST_EXIST);
                $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
                $external = trim((string) ($url->externalurl ?? ''));
                if ($external !== '') {
                    $resolved = self::resolve_playable_media($external);
                    $mediaurl = $resolved['url'];
                    $mediakind = $resolved['kind'];
                }
                if ($intro === '' && !empty($url->intro)) {
                    $intro = format_module_intro('url', $url, $cm->id);
                }
            } else if (self::activity_kind($modname) === 'video') {
                $detail = self::edwiser_video_media($cmid, $modname);
                if ($detail['mediaurl'] !== '') {
                    $mediaurl = $detail['mediaurl'];
                    $mediakind = $detail['mediakind'];
                }
                if ($detail['intro'] !== '') {
                    $intro = $detail['intro'];
                }
            }
        } catch (\Throwable $e) {
            // Keep CTA-only fallback.
        }

        return [
            'intro' => $intro,
            'mediaurl' => $mediaurl,
            'mediakind' => $mediakind,
            'statusitems' => $status,
        ];
    }

    /**
     * Resolve Edwiser Video Activity (and similar) media for in-pane playback.
     *
     * sourcetype: 1=upload (HTML5), 2=url (resolve), 3=embed iframe.
     *
     * @param int $cmid
     * @param string $modname
     * @return array{intro:string,mediaurl:string,mediakind:string,statusitems:array}
     */
    private static function edwiser_video_media(int $cmid, string $modname): array {
        global $DB;

        $out = [
            'intro' => '',
            'mediaurl' => '',
            'mediakind' => '',
            'statusitems' => [],
        ];

        try {
            $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
            $record = $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);
            $ctx = \context_module::instance($cm->id);

            if (!empty($record->intro)) {
                $out['intro'] = format_module_intro($modname, $record, $cm->id);
            }

            $sourcetype = (int) ($record->sourcetype ?? 0);
            $sourcepath = trim((string) ($record->sourcepath ?? ''));

            // 1) Upload — file area first, then stored pluginfile path.
            if ($sourcetype === 1) {
                $fs = get_file_storage();
                foreach (['mediafile', 'content', 'video', 'media'] as $area) {
                    $files = $fs->get_area_files(
                        $ctx->id,
                        'mod_' . $modname,
                        $area,
                        0,
                        'sortorder DESC, id ASC',
                        false
                    );
                    foreach ($files as $file) {
                        $mimetype = (string) $file->get_mimetype();
                        if (!str_starts_with($mimetype, 'video/') && !str_starts_with($mimetype, 'audio/')) {
                            continue;
                        }
                        $out['mediaurl'] = \moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out(false);
                        $out['mediakind'] = str_starts_with($mimetype, 'audio/') ? 'audio' : 'video';
                        return $out;
                    }
                }
                if ($sourcepath !== '' && str_contains($sourcepath, 'pluginfile.php')) {
                    $out['mediaurl'] = $sourcepath;
                    $out['mediakind'] = 'video';
                    return $out;
                }
            }

            if ($sourcepath === '') {
                return $out;
            }

            // 3) Embed HTML, or URL that is already an iframe snippet.
            if ($sourcetype === 3 || preg_match('/<iframe\b/i', $sourcepath)) {
                if (preg_match('/src=["\']([^"\']+)["\']/i', $sourcepath, $m)) {
                    $sourcepath = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                }
                $resolved = self::resolve_playable_media($sourcepath);
                // Embed sources must never fall back to HTML5 <video>.
                $out['mediaurl'] = $resolved['url'] !== '' ? $resolved['url'] : $sourcepath;
                $out['mediakind'] = 'embed';
                return $out;
            }

            // 2) External URL (Drive, YouTube, Vimeo, direct mp4, …).
            $resolved = self::resolve_playable_media($sourcepath);
            $out['mediaurl'] = $resolved['url'];
            $out['mediakind'] = $resolved['kind'];
        } catch (\Throwable $e) {
            // Leave empty — CTA fallback.
        }

        return $out;
    }

    /**
     * Map a raw video URL / embed src to an in-pane player kind.
     *
     * kind: video (HTML5), audio, embed (iframe), external (open link).
     *
     * @param string $raw
     * @return array{url:string,kind:string}
     */
    private static function resolve_playable_media(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return ['url' => '', 'kind' => ''];
        }

        // Moodle pluginfile / direct HTML5 media files.
        if (str_contains($raw, 'pluginfile.php')
                || preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)(\?|#|$)/i', $raw)) {
            return ['url' => $raw, 'kind' => 'video'];
        }
        if (preg_match('/\.(mp3|wav|m4a|aac|oga)(\?|#|$)/i', $raw)) {
            return ['url' => $raw, 'kind' => 'audio'];
        }

        // Google Drive share / open / preview → iframe preview player.
        if (preg_match('~drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~', $raw, $m)
                || preg_match('~drive\.google\.com/open\?id=([a-zA-Z0-9_-]+)~', $raw, $m)
                || preg_match('~docs\.google\.com/file/d/([a-zA-Z0-9_-]+)~', $raw, $m)) {
            return [
                'url' => 'https://drive.google.com/file/d/' . $m[1] . '/preview',
                'kind' => 'embed',
            ];
        }
        if (str_contains($raw, 'drive.google.com') && str_contains($raw, '/preview')) {
            return ['url' => $raw, 'kind' => 'embed'];
        }

        // YouTube → embed URL for iframe.
        if (preg_match(
            '~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i',
            $raw,
            $m
        )) {
            return [
                'url' => 'https://www.youtube.com/embed/' . $m[1] . '?rel=0&modestbranding=1',
                'kind' => 'embed',
            ];
        }

        // Vimeo.
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $raw, $m)) {
            return [
                'url' => 'https://player.vimeo.com/video/' . $m[1],
                'kind' => 'embed',
            ];
        }

        // Loom share → embed.
        if (preg_match('~loom\.com/(?:share|embed)/([A-Za-z0-9]+)~i', $raw, $m)) {
            return [
                'url' => 'https://www.loom.com/embed/' . $m[1],
                'kind' => 'embed',
            ];
        }

        // Generic embeddable player hosts / paths.
        if (preg_match('~/(embed|player|preview)/~i', $raw)
                || preg_match(
                    '~(youtube\.com|youtu\.be|vimeo\.com|loom\.com|wistia\.|brightcove|drive\.google\.com)~i',
                    $raw
                )) {
            return ['url' => $raw, 'kind' => 'embed'];
        }

        // http(s) page without a media extension → iframe (not <video src>).
        if (preg_match('~^https?://~i', $raw)) {
            return ['url' => $raw, 'kind' => 'embed'];
        }

        return ['url' => $raw, 'kind' => 'video'];
    }

    /**
     * @param int $cmid
     * @param string $modname
     * @return string
     */
    private static function module_intro_html(int $cmid, string $modname): string {
        global $DB;
        try {
            $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
            $record = $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);
            if (!empty($record->intro)) {
                return format_module_intro($modname, $record, $cm->id);
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * AJAX payload to swap the left pane without reloading the course shell.
     *
     * @param format_base $format
     * @param moodle_page $page
     * @param int $cmid
     * @return array
     */
    public static function export_activity_pane(format_base $format, moodle_page $page, int $cmid): array {
        global $USER;

        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $userid = (int) $USER->id;
        $completion = new completion_info($course);

        $ctx = self::resolve_cm_outline_context($modinfo, $cmid);
        if (!$ctx) {
            throw new \moodle_exception('invalidcoursemodule');
        }

        $courseid = (int) $course->id;
        $modname = (string) ($ctx['cm']->modname ?? '');
        $kind = self::activity_kind($modname);
        // Cache pages/resources only — skip quiz, video, H5P, interview (live attempt state).
        $usecache = ($modname !== ''
            && $modname !== 'quiz'
            && $modname !== 'nexinterview'
            && $kind !== 'video'
            && $kind !== 'h5p'
            && $kind !== 'nexinterview');
        $cache = null;
        $cachekey = '';
        if ($usecache) {
            try {
                $cache = \cache::make('format_nexcoursepro', 'activitypane');
                $cachekey = $courseid . '_' . $cmid . '_' . $userid;
                $hit = $cache->get($cachekey);
                if (is_array($hit) && !empty($hit['cmid'])) {
                    return $hit;
                }
            } catch (\Throwable $e) {
                $cache = null;
            }
        }

        // One full row for the active activity only — not the whole course outline.
        $current = self::activity_row(
            $ctx['cm'],
            $ctx['section'],
            $completion,
            $userid,
            $page,
            $courseid,
            (int) $ctx['parentsectionid'],
            true
        );

        $main = self::export_main_pane($course, $current, $page);

        // Prev/next are resolved client-side from the sidebar DOM (instant).
        // Skip another full-course walk on every pane request.
        $emptynav = ['id' => 0, 'viewurl' => '', 'name' => ''];

        // Fresh progress strip so H5P completion can update Grade obtained live.
        $stats = [
            'progresspct' => 0,
            'activitydisplay' => '',
            'items' => [],
        ];
        $hasstats = false;
        // Skip rebuilding the whole course outline on every pane open — too slow.
        // The shell already has a stats strip from the initial course load.

        $payload = [
            'courseid' => (int) $course->id,
            'cmid' => (int) $current['id'],
            'main' => $main,
            'prev' => $emptynav,
            'next' => $emptynav,
            'hasprev' => false,
            'hasnext' => false,
            'stats' => $stats,
            'hasstats' => $hasstats,
        ];

        if ($cache && $cachekey !== '') {
            try {
                $cache->set($cachekey, $payload);
            } catch (\Throwable $e) {
                // Cache is best-effort.
            }
        }

        return $payload;
    }

    /**
     * Render page module intro/content for embedding.
     *
     * @param int $cmid
     * @return string
     */
    private static function page_html(int $cmid): string {
        global $DB;
        try {
            $cm = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
            $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
            $ctx = context_module::instance($cm->id);
            $content = file_rewrite_pluginfile_urls(
                $page->content,
                'pluginfile.php',
                $ctx->id,
                'mod_page',
                'content',
                $page->revision ?? 0
            );
            return format_text($content, $page->contentformat, ['context' => $ctx]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param \course_modinfo $modinfo
     * @param moodle_page $page
     * @return array
     */
    private static function discussion_links($modinfo, moodle_page $page): array {
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname !== 'forum' || !$cm->uservisible) {
                continue;
            }
            $out[] = [
                'name' => $cm->get_formatted_name(),
                'url' => $cm->url ? $cm->url->out(false) : '#',
            ];
        }
        return $out;
    }

    /**
     * @param \course_modinfo $modinfo
     * @param moodle_page $page
     * @return array
     */
    private static function file_links($modinfo, moodle_page $page): array {
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!in_array($cm->modname, ['resource', 'folder', 'url'], true) || !$cm->uservisible) {
                continue;
            }
            $out[] = [
                'name' => $cm->get_formatted_name(),
                'url' => $cm->url ? $cm->url->out(false) : '#',
                'typelabel' => get_string('modulename', $cm->modname),
            ];
        }
        return $out;
    }

    /**
     * Delegated section for a subsection course module.
     *
     * @param \course_modinfo $modinfo
     * @param \cm_info $cm
     * @return \section_info|null
     */
    public static function delegated_section_for_cm($modinfo, $cm) {
        if (method_exists($modinfo, 'get_section_info_by_component')) {
            $info = $modinfo->get_section_info_by_component('mod_subsection', (int) $cm->instance);
            if ($info) {
                return $info;
            }
        }
        try {
            if (class_exists('\\mod_subsection\\manager')) {
                $manager = \mod_subsection\manager::create_from_coursemodule($cm);
                if ($manager && method_exists($manager, 'get_delegated_section_info')) {
                    return $manager->get_delegated_section_info();
                }
            }
        } catch (\Throwable $e) {
            // Subsection plugin missing / unavailable.
        }
        return null;
    }

    /**
     * Top-level sections (exclude delegated subsections).
     *
     * @param \course_modinfo $modinfo
     * @return \section_info[]
     */
    public static function listed_sections($modinfo): array {
        $out = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (method_exists($section, 'is_delegated') && $section->is_delegated()) {
                continue;
            }
            if (!empty($section->component)) {
                continue;
            }
            $out[] = $section;
        }
        return $out;
    }

    /**
     * Whether a section belongs in the Pro learner shell (never show Moodle-hidden).
     *
     * Teachers with viewhiddensections still must not see hidden items here —
     * Edit mode uses the native course editor for that.
     *
     * @param \section_info $section
     * @return bool
     */
    private static function section_listed_in_pro($section): bool {
        // General (0) stays out of the learner outline; delegated subsection
        // sections are included when walked via their parent.
        if (!is_object($section)) {
            return false;
        }
        if (empty($section->visible)) {
            return false;
        }
        if (empty($section->uservisible)) {
            return false;
        }
        // Top-level outline skips section 0; nested delegated sections may be any number.
        if (!(method_exists($section, 'is_delegated') && $section->is_delegated())
                && empty($section->component)
                && (int) ($section->section ?? 0) === 0) {
            return false;
        }
        return true;
    }

    /**
     * Whether a course module belongs in the Pro learner shell.
     *
     * @param \cm_info|null $cm
     * @return bool
     */
    private static function cm_listed_in_pro($cm): bool {
        if (!$cm) {
            return false;
        }
        if (!empty($cm->deletioninprogress)) {
            return false;
        }
        $modname = (string) ($cm->modname ?? '');
        if ($modname === 'label' || $modname === 'subsection') {
            return false;
        }
        if (empty($cm->visible)) {
            return false;
        }
        if (method_exists($cm, 'is_stealth') && $cm->is_stealth()) {
            return false;
        }
        if (empty($cm->uservisible)) {
            return false;
        }
        return true;
    }

    /**
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @return \cm_info[]
     */
    private static function section_cms($modinfo, $section): array {
        $cms = [];
        if (method_exists($section, 'get_sequence_cm_infos')) {
            $cms = $section->get_sequence_cm_infos();
        } else {
            $sectionnum = (int) $section->section;
            foreach ($modinfo->sections[$sectionnum] ?? [] as $cmid) {
                if (isset($modinfo->cms[$cmid])) {
                    $cms[] = $modinfo->cms[$cmid];
                }
            }
        }
        return array_values($cms ?: []);
    }
}
