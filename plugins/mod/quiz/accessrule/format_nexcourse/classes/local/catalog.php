<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Helpers to build NexCourse module cards + LL-styled course header.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcourse\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

use completion_info;
use context_course;
use context_module;
use core_courseformat\base as format_base;
use grade_grade;
use grade_item;
use moodle_page;
use moodle_url;
use user_picture;

/**
 * Build template data for the course home (module cards + header).
 */
class catalog {

    /** @var array modname => badge bucket */
    private const TYPE_MAP = [
        'resource' => 'video',
        'url' => 'video',
        'page' => 'video',
        'book' => 'video',
        'h5pactivity' => 'video',
        'scorm' => 'video',
        'folder' => 'video',
        'videotime' => 'video',
        'interactivevideo' => 'video',
        'hvp' => 'video',
        'assign' => 'practice',
        'quiz' => 'practice',
        'workshop' => 'practice',
        'lesson' => 'practice',
        'lti' => 'practice',
        'forum' => 'discussion',
        'chat' => 'discussion',
        'choice' => 'practice',
        'feedback' => 'practice',
        'questionnaire' => 'practice',
        'data' => 'practice',
        'glossary' => 'practice',
        'wiki' => 'practice',
    ];

    /** @var array<string,bool> CodeRunner questions inside quizzes */
    private const CODING_QTYPES = [
        'coderunner' => true,
    ];

    /** @var array<string,bool> Multiple-choice questions inside quizzes */
    private const MCQ_QTYPES = [
        'multichoice' => true,
        'multichoiceset' => true,
    ];

    /**
     * Known Edwiser Video activity modnames (frankenstyle without mod_).
     * Also detected dynamically via name / modulename string.
     *
     * @var array<string,bool>
     */
    private const EDWISER_VIDEO_MODS = [
        'edwiservideo' => true,
        'edwvideo' => true,
        'remuivideo' => true,
        'edwiservideoactivity' => true,
    ];

    /**
     * Activity / marks progress for the course header (shared by home + chrome tabs).
     *
     * @param format_base $format
     * @param int|null $userid
     * @return array{progresspct:int,completedcount:int,activitycount:int,activitydisplay:string,
     *               marksobtained:float,markstotal:float,marksdisplay:string,hashmarks:bool,modules:array}
     */
    public static function export_course_progress(
        format_base $format,
        ?int $userid = null,
        ?\moodle_page $page = null
    ): array {
        global $USER, $PAGE;

        $userid = $userid ?? (int) $USER->id;
        $page = $page ?? $PAGE;
        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $context = context_course::instance($course->id);
        $completion = new completion_info($course);

        $modules = [];
        $coursecompleted = 0;
        $coursetotal = 0;
        $coursemarksobtained = 0.0;
        $coursemarkstotal = 0.0;

        foreach (self::listed_sections($modinfo) as $section) {
            // General (section 0) is reserved for a future Announcements tab — hide from cards.
            if ((int) $section->section === 0) {
                continue;
            }
            if (!$section->uservisible && empty($section->availableinfo)) {
                continue;
            }
            if (!$section->visible && !has_capability('moodle/course:viewhiddensections', $context)) {
                continue;
            }

            $card = self::section_card($format, $section, $modinfo, $completion, $userid, $page);
            $coursecompleted += $card['completedcount'];
            $coursetotal += $card['activitycount'];
            $coursemarksobtained += (float) ($card['marksobtained'] ?? 0);
            $coursemarkstotal += (float) ($card['markstotal'] ?? 0);
            $modules[] = $card;
        }

        // Renumber MODULE badges sequentially (skip gaps from hidden General).
        // Prefer the first incomplete module as the default selection in the rail.
        $i = 1;
        $activeindex = 0;
        $activeset = false;
        foreach ($modules as $idx => &$mod) {
            $mod['modulenolabel'] = get_string('modulenolabel', 'format_nexcourse', $i);
            $mod['paneid'] = 'nx-mod-' . (int) ($mod['sectionnum'] ?? $i);
            if (!$activeset && (int) ($mod['progresspct'] ?? 0) < 100
                    && (int) ($mod['activitycount'] ?? 0) > 0) {
                $activeindex = $idx;
                $activeset = true;
            }
            $i++;
        }
        unset($mod);
        foreach ($modules as $idx => &$mod) {
            $mod['active'] = ($idx === $activeindex);
        }
        unset($mod);

        $progresspct = $coursetotal > 0 ? (int) round(($coursecompleted / $coursetotal) * 100) : 0;
        $activitydisplay = get_string('activitiesprogress', 'format_nexcourse', (object) [
            'completed' => $coursecompleted,
            'total' => $coursetotal,
        ]);
        $marksdisplay = get_string('marksprogress', 'format_nexcourse', (object) [
            'obtained' => self::format_marks($coursemarksobtained),
            'total' => self::format_marks($coursemarkstotal),
        ]);

        $content = self::empty_content_stats();
        try {
            $content = self::export_content_breakdown(
                $format,
                $completion,
                $userid,
                $coursecompleted,
                $coursetotal,
                $progresspct
            );
        } catch (\Throwable $e) {
            $content = self::empty_content_stats();
            $content['contentpct'] = $progresspct;
            $content['donutdeg'] = (int) round(360 * ($progresspct / 100));
            $content['contentitems'] = [
                self::content_item_row('activities', $coursecompleted, $coursetotal),
                self::content_item_row('coding', 0, 0),
                self::content_item_row('mcq', 0, 0),
                self::content_item_row('videos', 0, 0),
            ];
        }
        $content['hascontentstats'] = true;

        return [
            'progresspct' => $progresspct,
            'completedcount' => $coursecompleted,
            'activitycount' => $coursetotal,
            'activitydisplay' => $activitydisplay,
            'marksobtained' => $coursemarksobtained,
            'markstotal' => $coursemarkstotal,
            'marksdisplay' => $marksdisplay,
            'hashmarks' => $coursemarkstotal > 0,
            'modules' => $modules,
            'contentstats' => $content,
        ];
    }

    /**
     * @param format_base $format
     * @param moodle_page $page
     * @return array
     */
    public static function export_home(format_base $format, moodle_page $page): array {
        $course = $format->get_course();
        $context = context_course::instance($course->id);
        $progress = self::export_course_progress($format, null, $page);
        $modules = $progress['modules'];
        unset($progress['modules']);

        $header = self::export_course_header($format, $page, $progress);
        $continue = self::export_continue_learning($modules, (int) ($progress['progresspct'] ?? 0));

        return [
            'courseid' => (int) $course->id,
            'coursename' => format_string($course->fullname, true, ['context' => $context]),
            'progresspct' => $progress['progresspct'],
            'completedcount' => $progress['completedcount'],
            'activitycount' => $progress['activitycount'],
            'activitydisplay' => $progress['activitydisplay'],
            'marksobtained' => $progress['marksobtained'],
            'markstotal' => $progress['markstotal'],
            'marksdisplay' => $progress['marksdisplay'],
            'hashmarks' => $progress['hashmarks'],
            'modulecount' => count($modules),
            'modules' => $modules,
            'hasmodules' => !empty($modules),
            'continue' => $continue,
            'hascontinue' => !empty($continue['hascontinue']),
            'editing' => $page->user_is_editing(),
            'courseheader' => $header,
        ];
    }

    /**
     * Next incomplete activity for the Continue learning hero.
     *
     * @param array $modules Home module cards (with nested groups/activities).
     * @param int $progresspct Course progress 0–100.
     * @return array{hascontinue:bool,iscomplete:bool,...}
     */
    public static function export_continue_learning(array $modules, int $progresspct = 0): array {
        $firstplayable = null;
        $trackedincomplete = null;

        foreach ($modules as $mod) {
            foreach ($mod['groups'] ?? [] as $group) {
                foreach ($group['activities'] ?? [] as $act) {
                    $url = (string) ($act['url'] ?? '');
                    if ($url === '' || $url === '#') {
                        continue;
                    }
                    $row = [$mod, $group, $act];
                    if ($firstplayable === null) {
                        $firstplayable = $row;
                    }
                    if (!empty($act['completionenabled']) && empty($act['completed'])) {
                        $trackedincomplete = $row;
                        break 3;
                    }
                }
            }
        }

        if ($trackedincomplete) {
            return self::continue_payload($trackedincomplete[0], $trackedincomplete[1], $trackedincomplete[2], false);
        }

        if ($progresspct >= 100 && $firstplayable) {
            return self::continue_payload($firstplayable[0], $firstplayable[1], $firstplayable[2], true);
        }

        if ($firstplayable) {
            // No completion tracking yet — point at the first activity.
            return self::continue_payload($firstplayable[0], $firstplayable[1], $firstplayable[2], false);
        }

        return ['hascontinue' => false, 'iscomplete' => false];
    }

    /**
     * @param array $mod
     * @param array $group
     * @param array $act
     * @param bool $iscomplete
     * @return array
     */
    private static function continue_payload(array $mod, array $group, array $act, bool $iscomplete): array {
        $groupid = (string) ($group['id'] ?? '');
        $groupname = (string) ($group['name'] ?? '');
        $showgroup = $groupname !== '' && !str_ends_with($groupid, '-direct') && $groupid !== 'direct';

        return [
            'hascontinue' => true,
            'iscomplete' => $iscomplete,
            'eyebrow' => $iscomplete
                ? get_string('coursecomplete', 'format_nexcourse')
                : get_string('continuelearning', 'format_nexcourse'),
            'title' => (string) ($act['name'] ?? ''),
            'modulenolabel' => (string) ($mod['modulenolabel'] ?? ''),
            'modulename' => (string) ($mod['name'] ?? ''),
            'groupname' => $groupname,
            'hasgroupname' => $showgroup,
            'modnamelabel' => (string) ($act['modnamelabel'] ?? ''),
            'url' => (string) ($act['url'] ?? '#'),
            'ctalabel' => $iscomplete
                ? get_string('reviewactivity', 'format_nexcourse')
                : ((int) ($mod['progresspct'] ?? 0) > 0
                    ? get_string('continuemodule', 'format_nexcourse')
                    : get_string('startactivity', 'format_nexcourse')),
            'paneid' => (string) ($mod['paneid'] ?? ''),
            'iconurl' => (string) ($act['iconurl'] ?? ''),
            'hasicon' => !empty($act['hasicon']),
            'modname' => (string) ($act['modname'] ?? ''),
        ];
    }

    /**
     * Header payload for Participants / Grades / Activities / Competencies chrome.
     *
     * @param format_base $format
     * @param moodle_page $page
     * @return array
     */
    public static function export_chrome_header(format_base $format, moodle_page $page): array {
        $progress = self::export_course_progress($format);
        unset($progress['modules']);
        return self::export_course_header($format, $page, $progress);
    }

    /**
     * Module (single section) view: subsections as tabs + activity lists.
     *
     * @param format_base $format
     * @param moodle_page $page
     * @param int $sectionnum
     * @return array|null
     */
    public static function export_section_panel(format_base $format, moodle_page $page, int $sectionnum): ?array {
        global $USER;

        $modinfo = $format->get_modinfo();
        $completion = new completion_info($format->get_course());
        $section = $modinfo->get_section_info($sectionnum);
        if (!$section) {
            return null;
        }

        $tabs = [];
        $direct = [];
        $first = true;

        $cms = [];
        if (method_exists($section, 'get_sequence_cm_infos')) {
            $cms = $section->get_sequence_cm_infos();
        } else {
            foreach ($modinfo->sections[$sectionnum] ?? [] as $cmid) {
                if (isset($modinfo->cms[$cmid])) {
                    $cms[] = $modinfo->cms[$cmid];
                }
            }
        }

        foreach ($cms as $cm) {
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            if ($cm->modname === 'subsection') {
                $child = self::delegated_section_for_cm($modinfo, $cm);
                $activities = $child
                    ? self::export_activity_list($child, $modinfo, $completion, (int) $USER->id, $page)
                    : [];
                $tabid = 'sub-' . (int) $cm->id;
                $tabs[] = [
                    'id' => $tabid,
                    'name' => $cm->get_formatted_name(),
                    'activitycount' => count($activities),
                    'countlabel' => get_string('activitycount', 'format_nexcourse', count($activities)),
                    'activities' => $activities,
                    'hasactivities' => !empty($activities),
                    'active' => $first,
                    'tabindex' => $first ? '0' : '-1',
                ];
                $first = false;
                continue;
            }
            if ($cm->modname === 'label') {
                continue;
            }
            $direct[] = self::export_activity_item($cm, $completion, (int) $USER->id, $page);
        }

        if (!empty($direct)) {
            $tab = [
                'id' => 'direct',
                'name' => get_string('moduleactivities', 'format_nexcourse'),
                'activitycount' => count($direct),
                'countlabel' => get_string('activitycount', 'format_nexcourse', count($direct)),
                'activities' => $direct,
                'hasactivities' => true,
                'active' => empty($tabs),
                'tabindex' => empty($tabs) ? '0' : '-1',
            ];
            if (empty($tabs)) {
                $tabs[] = $tab;
            } else {
                array_unshift($tabs, $tab);
                foreach ($tabs as $i => &$t) {
                    $t['active'] = ($i === 0);
                    $t['tabindex'] = ($i === 0) ? '0' : '-1';
                }
                unset($t);
            }
        }

        if (empty($tabs)) {
            $tabs[] = [
                'id' => 'empty',
                'name' => get_string('sectionname', 'format_nexcourse'),
                'activitycount' => 0,
                'countlabel' => get_string('activitycount', 'format_nexcourse', 0),
                'activities' => [],
                'hasactivities' => false,
                'active' => true,
                'tabindex' => '0',
            ];
        }

        return [
            'sectionnum' => $sectionnum,
            'sectionname' => $format->get_section_name($section),
            'tabs' => $tabs,
            'hastabs' => count($tabs) > 0,
            'hasmultipletabs' => count($tabs) > 1,
        ];
    }

    /**
     * Activities inside a section (non-subsection, non-label).
     *
     * @param \section_info $section
     * @param \course_modinfo $modinfo
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @return array
     */
    public static function export_activity_list(
        $section,
        $modinfo,
        completion_info $completion,
        int $userid,
        moodle_page $page
    ): array {
        $out = [];
        $cms = [];
        if (method_exists($section, 'get_sequence_cm_infos')) {
            $cms = $section->get_sequence_cm_infos();
        } else {
            $snum = (int) $section->section;
            foreach ($modinfo->sections[$snum] ?? [] as $cmid) {
                if (isset($modinfo->cms[$cmid])) {
                    $cms[] = $modinfo->cms[$cmid];
                }
            }
        }
        foreach ($cms as $cm) {
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            if ($cm->modname === 'label' || $cm->modname === 'subsection') {
                continue;
            }
            $out[] = self::export_activity_item($cm, $completion, $userid, $page);
        }
        return $out;
    }

    /**
     * @param \cm_info $cm
     * @param completion_info $completion
     * @param int $userid
     * @param moodle_page $page
     * @return array
     */
    private static function export_activity_item($cm, completion_info $completion, int $userid, moodle_page $page): array {
        $completed = false;
        $completionenabled = false;
        $manualquizcompletion = false;
        if ($completion->is_enabled($cm)) {
            $completionenabled = true;
            $cdata = $completion->get_data($cm, false, $userid);
            $completed = !empty($cdata->completionstate);
            // Only tracked users get the toggle; core hides it from teachers too.
            $manualquizcompletion = $cm->modname === 'quiz'
                && (int) $cm->completion === COMPLETION_TRACKING_MANUAL
                && $completion->is_tracked_user($userid);
        }
        $iconurl = '';
        try {
            $iconurl = $cm->get_icon_url()->out(false);
        } catch (\Throwable $e) {
            $iconurl = '';
        }
        return [
            'id' => (int) $cm->id,
            'name' => $cm->get_formatted_name(),
            'url' => $cm->url ? $cm->url->out(false) : '#',
            'modname' => $cm->modname,
            'modnamelabel' => get_string('modulename', $cm->modname),
            'iconurl' => $iconurl,
            'hasicon' => $iconurl !== '',
            'completed' => $completed,
            'completionenabled' => $completionenabled,
            'showcompletionstatus' => $completionenabled && !$manualquizcompletion,
            'manualquizcompletion' => $manualquizcompletion,
        ];
    }

    /**
     * Full LL-styled course header (banner, staff, progress, enrollment stats).
     *
     * @param format_base $format
     * @param moodle_page $page
     * @param array $progress
     * @return array
     */
    public static function export_course_header(format_base $format, moodle_page $page, array $progress = []): array {
        global $DB;

        $course = $format->get_course();
        $context = context_course::instance($course->id);
        $coursename = format_string($course->fullname, true, ['context' => $context]);

        $categoryname = '';
        try {
            $category = \core_course_category::get($course->category, IGNORE_MISSING);
            if ($category) {
                $categoryname = $category->get_formatted_name();
            }
        } catch (\Throwable $e) {
            $categoryname = '';
        }

        $imageurl = self::course_image_url($course);
        $hasimage = $imageurl !== '';

        if (empty($progress)) {
            $progress = [
                'progresspct' => 0,
                'completedcount' => 0,
                'activitycount' => 0,
                'activitydisplay' => get_string('activitiesprogress', 'format_nexcourse', (object) [
                    'completed' => 0,
                    'total' => 0,
                ]),
                'marksdisplay' => get_string('marksprogress', 'format_nexcourse', (object) [
                    'obtained' => 0,
                    'total' => 0,
                ]),
                'hashmarks' => false,
                'contentstats' => self::empty_content_stats(),
            ];
        }

        $staff = [];
        $stats = [];
        try {
            $staff = self::course_staff($context, $page);
        } catch (\Throwable $e) {
            $staff = [];
        }
        try {
            $stats = self::enrollment_stats($course, $context);
        } catch (\Throwable $e) {
            $stats = [];
        }

        $completedcount = (int) ($progress['completedcount'] ?? 0);
        $activitycount = (int) ($progress['activitycount'] ?? 0);
        $activitydisplay = (string) ($progress['activitydisplay'] ?? get_string(
            'activitiesprogress',
            'format_nexcourse',
            (object) ['completed' => $completedcount, 'total' => $activitycount]
        ));
        $marksdisplay = (string) ($progress['marksdisplay'] ?? '');
        $hashmarks = !empty($progress['hashmarks']);
        $contentstats = $progress['contentstats'] ?? self::empty_content_stats();

        return [
            'coursename' => $coursename,
            'categoryname' => $categoryname,
            'hascategory' => $categoryname !== '',
            'imageurl' => $imageurl,
            'hasimage' => $hasimage,
            'progresspct' => (int) ($progress['progresspct'] ?? 0),
            'completedcount' => $completedcount,
            'activitycount' => $activitycount,
            'activitydisplay' => $activitydisplay,
            'marksdisplay' => $marksdisplay,
            'hashmarks' => $hashmarks,
            'staff' => $staff,
            'hasstaff' => !empty($staff),
            'stats' => $stats,
            'hasstats' => !empty($stats),
            'contentstats' => $contentstats,
            // Always render donut + Coding/MCQ/Videos (never fall back to linear bar).
            'hascontentstats' => true,
            'contentpct' => (int) ($contentstats['contentpct'] ?? $progress['progresspct'] ?? 0),
            'contentitems' => $contentstats['contentitems'] ?? [
                self::content_item_row('activities', $completedcount, $activitycount),
                self::content_item_row('coding', 0, 0),
                self::content_item_row('mcq', 0, 0),
                self::content_item_row('videos', 0, 0),
            ],
            'donutdeg' => (int) ($contentstats['donutdeg'] ?? 0),
        ];
    }

    /**
     * @param \stdClass $course
     * @return string
     */
    private static function course_image_url($course): string {
        try {
            if (class_exists('\\core_course\\external\\course_summary_exporter')
                    && method_exists('\\core_course\\external\\course_summary_exporter', 'get_course_image')) {
                $url = \core_course\external\course_summary_exporter::get_course_image($course);
                if (!empty($url)) {
                    return (string) $url;
                }
            }

            $course = new \core_course_list_element($course);
            foreach ($course->get_course_overviewfiles() as $file) {
                if ($file->is_valid_image()) {
                    return moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        null,
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out(false);
                }
            }
        } catch (\Throwable $e) {
            // Missing overview image must never break Participants / Settings chrome.
            return '';
        }
        return '';
    }

    /**
     * Teachers, managers, admins assigned in the course.
     *
     * @param \context_course $context
     * @param moodle_page $page
     * @return array
     */
    private static function course_staff($context, moodle_page $page): array {
        global $DB;

        $want = ['manager', 'coursecreator', 'editingteacher', 'teacher'];
        $rolelabels = [
            'manager' => get_string('rolemanager', 'format_nexcourse'),
            'coursecreator' => get_string('roleadmin', 'format_nexcourse'),
            'editingteacher' => get_string('roleteacher', 'format_nexcourse'),
            'teacher' => get_string('roleteacher', 'format_nexcourse'),
        ];

        $roles = $DB->get_records_list('role', 'shortname', $want, 'sortorder ASC');
        if (empty($roles)) {
            return [];
        }

        $seen = [];
        $staff = [];
        foreach ($roles as $role) {
            $users = get_role_users(
                $role->id,
                $context,
                false,
                'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.picture, u.imagealt, u.email',
                'u.lastname ASC, u.firstname ASC'
            );
            foreach ($users as $user) {
                if (isset($seen[$user->id])) {
                    continue;
                }
                $seen[$user->id] = true;
                $pic = new user_picture($user);
                $pic->size = 64;
                $pic->link = false;
                $staff[] = [
                    'id' => (int) $user->id,
                    'fullname' => fullname($user),
                    'role' => $rolelabels[$role->shortname] ?? $role->shortname,
                    'roleshort' => $role->shortname,
                    'avatarurl' => $pic->get_url($page)->out(false),
                    'profileurl' => (new moodle_url('/user/view.php', [
                        'id' => $user->id,
                        'course' => $context->instanceid,
                    ]))->out(false),
                ];
                if (count($staff) >= 8) {
                    return $staff;
                }
            }
        }
        return $staff;
    }

    /**
     * Empty content-stats payload for the header donut panel.
     *
     * @return array
     */
    private static function empty_content_stats(): array {
        $items = [
            self::content_item_row('activities', 0, 0),
            self::content_item_row('coding', 0, 0),
            self::content_item_row('mcq', 0, 0),
            self::content_item_row('videos', 0, 0),
        ];
        return [
            'contentpct' => 0,
            'contenttotal' => 0,
            'contentcompleted' => 0,
            'donutdeg' => 0,
            'hascontentstats' => true,
            'contentitems' => $items,
        ];
    }

    /**
     * @param string $key activities|coding|mcq|videos
     * @param int $completed
     * @param int $total
     * @return array
     */
    private static function content_item_row(string $key, int $completed, int $total): array {
        $labels = [
            'activities' => get_string('statactivities', 'format_nexcourse'),
            'coding' => get_string('statcoding', 'format_nexcourse'),
            'mcq' => get_string('statmcq', 'format_nexcourse'),
            'videos' => get_string('statvideos', 'format_nexcourse'),
        ];
        $completed = max(0, min($completed, $total));
        return [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'completed' => $completed,
            'total' => $total,
            'display' => get_string('activitiesprogress', 'format_nexcourse', (object) [
                'completed' => $completed,
                'total' => $total,
            ]),
            'hasany' => $total > 0,
        ];
    }

    /**
     * Coding / MCQ / video breakdown + activity donut for the course header.
     *
     * Donut % = completed activities / total activities (pass-grade aware).
     * Coding numerator = correct CodeRunner only.
     * MCQ numerator = option at least selected (right or wrong).
     * Videos = Edwiser Video activities completed (pass-grade aware).
     *
     * @param format_base $format
     * @param completion_info $completion
     * @param int $userid
     * @param int $activitycompleted
     * @param int $activitytotal
     * @param int $activitypct
     * @return array
     */
    private static function export_content_breakdown(
        format_base $format,
        completion_info $completion,
        int $userid,
        int $activitycompleted = 0,
        int $activitytotal = 0,
        int $activitypct = 0
    ): array {
        $modinfo = $format->get_modinfo();

        $codingtotal = 0;
        $codingdone = 0;
        $mcqtotal = 0;
        $mcqdone = 0;
        $videototal = 0;
        $videodone = 0;

        foreach ($modinfo->cms as $cm) {
            if (!$cm || empty($cm->id) || !empty($cm->deletioninprogress)) {
                continue;
            }

            if ($cm->modname === 'quiz') {
                $buckets = self::quiz_question_bucket_counts((int) $cm->instance, (int) $cm->id);
                $codingtotal += $buckets['coding'];
                $mcqtotal += $buckets['mcq'];

                // Per-question rules (never inflate to full quiz on activity complete):
                // coding = correct only; MCQ = option selected.
                $progress = self::quiz_progress_bucket_counts((int) $cm->instance, $userid);
                $codingdone += min($progress['coding'], $buckets['coding']);
                $mcqdone += min($progress['mcq'], $buckets['mcq']);
                continue;
            }

            if (self::is_edwiser_video_activity($cm)) {
                $videototal++;
                $marks = self::activity_marks($cm, $userid);
                if (self::activity_is_complete($cm, $completion, $userid, $marks)) {
                    $videodone++;
                }
            }
        }

        $codingdone = min($codingdone, $codingtotal);
        $mcqdone = min($mcqdone, $mcqtotal);
        $videodone = min($videodone, $videototal);

        return [
            // Donut always reflects activity completion (pass mark rules included upstream).
            'contentpct' => $activitypct,
            'contenttotal' => $activitytotal,
            'contentcompleted' => $activitycompleted,
            'donutdeg' => (int) round(360 * ($activitypct / 100)),
            'hascontentstats' => true,
            'contentitems' => [
                self::content_item_row('activities', $activitycompleted, $activitytotal),
                self::content_item_row('coding', $codingdone, $codingtotal),
                self::content_item_row('mcq', $mcqdone, $mcqtotal),
                self::content_item_row('videos', $videodone, $videototal),
            ],
        ];
    }

    /**
     * True only for the Edwiser Video activity module (not page/url/file/H5P).
     *
     * @param \cm_info $cm
     * @return bool
     */
    private static function is_edwiser_video_activity($cm): bool {
        $mod = strtolower((string) $cm->modname);
        if ($mod === '' || $mod === 'quiz' || $mod === 'label' || $mod === 'subsection') {
            return false;
        }
        if (isset(self::EDWISER_VIDEO_MODS[$mod])) {
            return true;
        }

        $compact = str_replace(['_', '-'], '', $mod);
        if (str_contains($compact, 'edwiservideo')
                || str_contains($compact, 'edwvideo')
                || str_contains($compact, 'remuivideo')) {
            return true;
        }
        if ((str_contains($mod, 'edwiser') || str_contains($mod, 'remui'))
                && str_contains($mod, 'video')) {
            return true;
        }

        try {
            $label = strtolower(trim(get_string('modulename', $mod)));
            if ($label !== '' && str_contains($label, 'edwiser') && str_contains($label, 'video')) {
                return true;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return false;
    }

    /**
     * Moodle 5 uses quiz_slots.quizid; older branches used quiz_slots.quiz.
     *
     * @return string
     */
    private static function quiz_slots_quiz_column(): string {
        global $DB;
        static $col = null;
        if ($col !== null) {
            return $col;
        }
        try {
            $columns = $DB->get_columns('quiz_slots');
            if (isset($columns['quizid'])) {
                $col = 'quizid';
            } else if (isset($columns['quiz'])) {
                $col = 'quiz';
            } else {
                $col = 'quizid';
            }
        } catch (\Throwable $e) {
            $col = 'quizid';
        }
        return $col;
    }

    /**
     * Count CodeRunner + multichoice questions slotted into a quiz.
     *
     * @param int $quizid
     * @param int $cmid course-module id (needed for Moodle 5 qbank context join)
     * @return array{coding:int,mcq:int}
     */
    private static function quiz_question_bucket_counts(int $quizid, int $cmid = 0): array {
        global $DB, $CFG;

        static $cache = [];
        $cachekey = $quizid . ':' . $cmid;
        if (isset($cache[$cachekey])) {
            return $cache[$cachekey];
        }

        $out = ['coding' => 0, 'mcq' => 0];
        if ($quizid < 1) {
            $cache[$cachekey] = $out;
            return $out;
        }

        // 1) Official Moodle 4+/5 helper — includes qtype on each slot.
        if ($cmid > 0 && class_exists('\\mod_quiz\\question\\bank\\qbank_helper')) {
            try {
                $modcontext = \context_module::instance($cmid);
                $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $modcontext);
                foreach ($slots as $slot) {
                    $qtype = strtolower((string) ($slot->qtype ?? ''));
                    if ($qtype === '' || $qtype === 'random' || $qtype === 'missingtype') {
                        continue;
                    }
                    if (isset(self::CODING_QTYPES[$qtype])) {
                        $out['coding']++;
                    } else if (isset(self::MCQ_QTYPES[$qtype])) {
                        $out['mcq']++;
                    }
                }
                $cache[$cachekey] = $out;
                return $out;
            } catch (\Throwable $e) {
                $out = ['coding' => 0, 'mcq' => 0];
            }
        }

        // 2) SQL matching Moodle 5 qbank_helper joins (quizid + usingcontextid).
        try {
            $quizcol = self::quiz_slots_quiz_column();
            $params = ['quizid' => $quizid];
            $contextjoin = '';
            if ($cmid > 0) {
                $modcontext = \context_module::instance($cmid);
                $params['ctxid'] = (int) $modcontext->id;
                $contextjoin = ' AND qr.usingcontextid = :ctxid';
            }

            $sql = "SELECT q.qtype, COUNT(1) AS cnt
                      FROM {quiz_slots} slot
                      JOIN {question_references} qr
                        ON qr.itemid = slot.id
                       AND qr.component = 'mod_quiz'
                       AND qr.questionarea = 'slot'
                       $contextjoin
                      JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                      JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                      LEFT JOIN {question_versions} qv2
                        ON qv2.questionbankentryid = qbe.id
                       AND qv.version < qv2.version
                      JOIN {question} q ON q.id = qv.questionid
                     WHERE slot.{$quizcol} = :quizid
                       AND qv2.id IS NULL
                       AND (
                            qr.version IS NULL
                            OR qv.version = qr.version
                       )
                  GROUP BY q.qtype";
            $rows = $DB->get_records_sql($sql, $params);
            foreach ($rows as $row) {
                $qtype = strtolower((string) $row->qtype);
                $cnt = (int) $row->cnt;
                if (isset(self::CODING_QTYPES[$qtype])) {
                    $out['coding'] += $cnt;
                } else if (isset(self::MCQ_QTYPES[$qtype])) {
                    $out['mcq'] += $cnt;
                }
            }
        } catch (\Throwable $e) {
            // keep $out
        }

        // 3) Last resort: quiz structure API.
        if ($out['coding'] === 0 && $out['mcq'] === 0) {
            try {
                if (!empty($CFG->dirroot) && is_readable($CFG->dirroot . '/mod/quiz/locallib.php')) {
                    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                }
                $quiz = $DB->get_record('quiz', ['id' => $quizid]);
                if ($quiz && class_exists('\\mod_quiz\\structure')) {
                    $structure = \mod_quiz\structure::create_for_quiz($quiz);
                    foreach ($structure->get_slots() as $slot) {
                        $slotno = (int) ($slot->slot ?? 0);
                        if ($slotno < 1) {
                            continue;
                        }
                        if (method_exists($structure, 'is_real_question') && !$structure->is_real_question($slotno)) {
                            continue;
                        }
                        $qtype = '';
                        if (method_exists($structure, 'get_question_type_for_slot')) {
                            $qtype = strtolower((string) $structure->get_question_type_for_slot($slotno));
                        }
                        if ($qtype === '' || $qtype === 'random') {
                            continue;
                        }
                        if (isset(self::CODING_QTYPES[$qtype])) {
                            $out['coding']++;
                        } else if (isset(self::MCQ_QTYPES[$qtype])) {
                            $out['mcq']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $cache[$cachekey] = $out;
        return $out;
    }

    /**
     * Per-question progress in a quiz for the current user.
     *
     * - coding: only fully correct CodeRunner answers
     * - mcq: any multichoice with an option selected (right or wrong)
     *
     * @param int $quizid
     * @param int $userid
     * @return array{coding:int,mcq:int}
     */
    private static function quiz_progress_bucket_counts(int $quizid, int $userid): array {
        global $DB, $CFG;

        $out = ['coding' => 0, 'mcq' => 0];
        if ($quizid < 1 || $userid < 1) {
            return $out;
        }

        // 1) Question engine.
        try {
            if (!empty($CFG->dirroot) && is_readable($CFG->dirroot . '/mod/quiz/locallib.php')) {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            }
            require_once($CFG->libdir . '/questionlib.php');

            if (function_exists('quiz_get_user_attempts') && class_exists('\\question_engine')) {
                $attempts = quiz_get_user_attempts($quizid, $userid, 'all', true);
                $codingslots = [];
                $mcqslots = [];

                foreach ($attempts as $attempt) {
                    if (!empty($attempt->preview)) {
                        continue;
                    }
                    try {
                        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
                    } catch (\Throwable $e) {
                        continue;
                    }

                    foreach ($quba->get_slots() as $slot) {
                        try {
                            $qa = $quba->get_question_attempt($slot);
                        } catch (\Throwable $e) {
                            continue;
                        }

                        $qtype = self::question_attempt_qtype($qa);
                        if ($qtype === '') {
                            continue;
                        }

                        if (isset(self::CODING_QTYPES[$qtype])) {
                            if (self::question_attempt_is_correct($qa)) {
                                $codingslots[(int) $slot] = true;
                            }
                        } else if (isset(self::MCQ_QTYPES[$qtype])) {
                            if (self::question_attempt_was_answered($qa)) {
                                $mcqslots[(int) $slot] = true;
                            }
                        }
                    }
                }

                $out['coding'] = count($codingslots);
                $out['mcq'] = count($mcqslots);
                // Prefer engine results even when zero (accurate empty state).
                return $out;
            }
        } catch (\Throwable $e) {
            $out = ['coding' => 0, 'mcq' => 0];
        }

        // 2) SQL fallback.
        try {
            // Coding: fully correct only.
            $sqlcoding = "SELECT COUNT(DISTINCT qa.slot) AS cnt
                            FROM {quiz_attempts} att
                            JOIN {question_attempts} qa ON qa.questionusageid = att.uniqueid
                            JOIN {question} q ON q.id = qa.questionid
                           WHERE att.quiz = :quizid
                             AND att.userid = :userid
                             AND att.preview = 0
                             AND att.state <> 'abandoned'
                             AND q.qtype = 'coderunner'
                             AND qa.fraction IS NOT NULL
                             AND qa.fraction >= 0.999";
            $out['coding'] = (int) $DB->get_field_sql($sqlcoding, [
                'quizid' => $quizid,
                'userid' => $userid,
            ]);

            // MCQ: option selected (any saved response step).
            $sqlmcq = "SELECT COUNT(DISTINCT qa.slot) AS cnt
                         FROM {quiz_attempts} att
                         JOIN {question_attempts} qa ON qa.questionusageid = att.uniqueid
                         JOIN {question} q ON q.id = qa.questionid
                        WHERE att.quiz = :quizid
                          AND att.userid = :userid
                          AND att.preview = 0
                          AND att.state <> 'abandoned'
                          AND q.qtype IN ('multichoice', 'multichoiceset')
                          AND (
                               EXISTS (
                                    SELECT 1
                                      FROM {question_attempt_steps} qas
                                     WHERE qas.questionattemptid = qa.id
                                       AND qas.sequencenumber > 0
                               )
                               OR qa.fraction IS NOT NULL
                               OR (qa.responsesummary IS NOT NULL AND qa.responsesummary <> '')
                               OR qa.state NOT IN ('todo', 'unprocessed', 'invalid')
                          )";
            $out['mcq'] = (int) $DB->get_field_sql($sqlmcq, [
                'quizid' => $quizid,
                'userid' => $userid,
            ]);
        } catch (\Throwable $e) {
            return ['coding' => 0, 'mcq' => 0];
        }

        return $out;
    }

    /**
     * @param \question_attempt $qa
     * @return string
     */
    private static function question_attempt_qtype($qa): string {
        try {
            $question = $qa->get_question(false);
            if ($question && method_exists($question, 'get_type_name')) {
                return strtolower((string) $question->get_type_name());
            }
        } catch (\Throwable $e) {
            // continue
        }
        try {
            $question = $qa->get_question();
            if ($question && isset($question->qtype)) {
                if (is_object($question->qtype) && method_exists($question->qtype, 'name')) {
                    return strtolower((string) $question->qtype->name());
                }
                return strtolower((string) $question->qtype);
            }
        } catch (\Throwable $e) {
            // continue
        }
        return '';
    }

    /**
     * Fully correct question attempt (for coding progress).
     *
     * @param \question_attempt $qa
     * @return bool
     */
    private static function question_attempt_is_correct($qa): bool {
        try {
            $fraction = $qa->get_fraction();
            if ($fraction !== null && (float) $fraction >= 0.999) {
                return true;
            }
        } catch (\Throwable $e) {
            // continue
        }

        try {
            $state = $qa->get_state();
            if ($state !== null) {
                if (method_exists($state, 'is_correct') && $state->is_correct()) {
                    return true;
                }
                $name = method_exists($state, '__toString') ? (string) $state : '';
                if ($name === 'gradedright' || $name === 'mangrright') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // continue
        }

        return false;
    }

    /**
     * Whether the learner has selected/given any answer (MCQ: option selected).
     *
     * @param \question_attempt $qa
     * @return bool
     */
    private static function question_attempt_was_answered($qa): bool {
        try {
            if (method_exists($qa, 'get_num_steps') && $qa->get_num_steps() > 1) {
                return true;
            }
        } catch (\Throwable $e) {
            // continue
        }

        try {
            if ($qa->get_fraction() !== null) {
                return true;
            }
        } catch (\Throwable $e) {
            // continue
        }

        try {
            $summary = $qa->get_response_summary();
            if ($summary !== null && trim((string) $summary) !== '') {
                return true;
            }
        } catch (\Throwable $e) {
            // continue
        }

        try {
            $state = $qa->get_state();
            if ($state !== null) {
                $name = method_exists($state, '__toString') ? (string) $state : '';
                if ($name !== '' && $name !== 'todo' && $name !== 'unprocessed' && $name !== 'invalid') {
                    return true;
                }
                if (method_exists($state, 'is_finished') && $state->is_finished()) {
                    return true;
                }
                if (method_exists($state, 'is_graded') && $state->is_graded()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // continue
        }

        try {
            if (method_exists($qa, 'get_last_qt_data')) {
                $data = $qa->get_last_qt_data();
                if (is_array($data)) {
                    foreach ($data as $val) {
                        if ($val === null || $val === '' || $val === []) {
                            continue;
                        }
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // continue
        }

        return false;
    }

    /**
     * Enrollment funnel stats for the stats strip.
     *
     * @param \stdClass $course
     * @param \context_course $context
     * @return array
     */
    private static function enrollment_stats($course, $context): array {
        global $DB;

        $enrolled = 0;
        $completed = 0;
        $inprogress = 0;
        $yettostart = 0;

        $teacherids = [];
        foreach (['manager', 'coursecreator', 'editingteacher', 'teacher'] as $short) {
            $role = $DB->get_record('role', ['shortname' => $short], 'id');
            if (!$role) {
                continue;
            }
            foreach (get_role_users($role->id, $context, false, 'u.id') as $u) {
                $teacherids[(int) $u->id] = true;
            }
        }

        $users = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
        $completion = new completion_info($course);
        $coursecompletionenabled = $completion->is_enabled() && $completion->has_criteria();

        foreach ($users as $user) {
            $uid = (int) $user->id;
            if (isset($teacherids[$uid])) {
                continue;
            }
            $enrolled++;

            $iscomplete = false;
            if ($coursecompletionenabled) {
                try {
                    $ccompletion = new \completion_completion([
                        'userid' => $uid,
                        'course' => $course->id,
                    ]);
                    $iscomplete = $ccompletion->is_complete();
                } catch (\Throwable $e) {
                    $iscomplete = false;
                }
            }

            if ($iscomplete) {
                $completed++;
                continue;
            }

            $started = false;
            $sql = "SELECT 1
                      FROM {course_modules_completion} cmc
                      JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                     WHERE cm.course = :courseid AND cmc.userid = :userid
                       AND cmc.completionstate > 0";
            if ($DB->record_exists_sql($sql, ['courseid' => $course->id, 'userid' => $uid])) {
                $started = true;
            } else if ($DB->record_exists('user_lastaccess', ['courseid' => $course->id, 'userid' => $uid])) {
                $started = true;
            }

            if ($started) {
                $inprogress++;
            } else {
                $yettostart++;
            }
        }

        return [
            [
                'key' => 'enrolled',
                'value' => $enrolled,
                'label' => get_string('statenrolled', 'format_nexcourse'),
            ],
            [
                'key' => 'completed',
                'value' => $completed,
                'label' => get_string('statcompleted', 'format_nexcourse'),
            ],
            [
                'key' => 'inprogress',
                'value' => $inprogress,
                'label' => get_string('statinprogress', 'format_nexcourse'),
            ],
            [
                'key' => 'yettostart',
                'value' => $yettostart,
                'label' => get_string('statyettostart', 'format_nexcourse'),
            ],
        ];
    }

    /**
     * Top-level course sections only (exclude delegated subsections).
     *
     * @param \course_modinfo $modinfo
     * @return \section_info[]
     */
    public static function listed_sections($modinfo): array {
        if (method_exists($modinfo, 'get_listed_section_info_all')) {
            return array_values($modinfo->get_listed_section_info_all());
        }
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
     * Recursively tally activities + grades inside a section (including subsections).
     *
     * Progress: every real activity counts toward total; completed when Moodle
     * completion is done, or when a grade has been awarded (e.g. finished quiz).
     * Marks: sum of gradebook max / obtained for graded activities.
     *
     * @param \section_info $section
     * @param \course_modinfo $modinfo
     * @param completion_info $completion
     * @param int $userid
     * @return array{types:array,completed:int,tracked:int,submodules:int,marksobtained:float,markstotal:float}
     */
    public static function tally_section($section, $modinfo, completion_info $completion, int $userid): array {
        $types = ['video' => 0, 'practice' => 0, 'discussion' => 0, 'other' => 0, 'submodule' => 0];
        $completed = 0;
        $tracked = 0;
        $submodules = 0;
        $marksobtained = 0.0;
        $markstotal = 0.0;

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

        foreach ($cms as $cm) {
            if (!$cm || !$cm->uservisible) {
                continue;
            }

            if ($cm->modname === 'subsection') {
                $submodules++;
                $types['submodule']++;
                $child = self::delegated_section_for_cm($modinfo, $cm);
                if ($child) {
                    $inner = self::tally_section($child, $modinfo, $completion, $userid);
                    foreach ($inner['types'] as $k => $v) {
                        if ($k === 'submodule') {
                            $types['submodule'] += $v;
                        } else {
                            $types[$k] = ($types[$k] ?? 0) + $v;
                        }
                    }
                    $completed += $inner['completed'];
                    $tracked += $inner['tracked'];
                    $submodules += $inner['submodules'];
                    $marksobtained += $inner['marksobtained'];
                    $markstotal += $inner['markstotal'];
                }
                continue;
            }

            if ($cm->modname === 'label') {
                continue;
            }

            $bucket = self::TYPE_MAP[$cm->modname] ?? 'other';
            $types[$bucket]++;
            $tracked++;

            $marks = self::activity_marks($cm, $userid);
            $marksobtained += $marks['obtained'];
            $markstotal += $marks['total'];

            if (self::activity_is_complete($cm, $completion, $userid, $marks)) {
                $completed++;
            }
        }

        return [
            'types' => $types,
            'completed' => $completed,
            'tracked' => $tracked,
            'submodules' => $submodules,
            'marksobtained' => $marksobtained,
            'markstotal' => $markstotal,
        ];
    }

    /**
     * Whether this activity counts as completed for NexCourse progress.
     *
     * Respects Moodle completion + require-passing-grade. For quizzes, a score
     * only counts as done when it meets/exceeds the grade-to-pass (when set).
     *
     * @param \cm_info $cm
     * @param completion_info $completion
     * @param int $userid
     * @param array $marks from activity_marks()
     * @return bool
     */
    private static function activity_is_complete(
        $cm,
        completion_info $completion,
        int $userid,
        array $marks
    ): bool {
        $requirespass = self::cm_requires_pass_grade($cm);
        $requiresgrade = self::cm_requires_grade($cm) || $requirespass;

        if ($completion->is_enabled($cm)) {
            $cdata = $completion->get_data($cm, false, $userid);
            $state = (int) ($cdata->completionstate ?? COMPLETION_INCOMPLETE);

            // Explicit fail when pass grade is required — never treat as done.
            if ($state === COMPLETION_COMPLETE_FAIL) {
                return false;
            }
            if ($state === COMPLETION_COMPLETE_PASS) {
                return true;
            }
            if ($state === COMPLETION_COMPLETE) {
                // Moodle marked complete; if pass is required, double-check score.
                if ($requirespass) {
                    return self::marks_meet_pass_grade($cm, $marks);
                }
                return true;
            }

            // Incomplete in Moodle — evaluate ourselves (handles gradebook lag).
            if ($requirespass) {
                return self::marks_meet_pass_grade($cm, $marks);
            }
            if ($requiresgrade) {
                return !empty($marks['hasgrade']);
            }
            return false;
        }

        // No completion tracking configured.
        if ($requirespass || self::activity_gradepass($cm) > 0) {
            return self::marks_meet_pass_grade($cm, $marks);
        }
        if ($marks['total'] > 0) {
            return !empty($marks['hasgrade']);
        }
        return false;
    }

    /**
     * @param \cm_info $cm
     * @return bool
     */
    private static function cm_requires_pass_grade($cm): bool {
        if (!empty($cm->completionpassgrade)) {
            return true;
        }
        if ($cm->modname === 'quiz') {
            global $DB;
            try {
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, completionpass', IGNORE_MISSING);
                if ($quiz && !empty($quiz->completionpass)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return false;
    }

    /**
     * @param \cm_info $cm
     * @return bool
     */
    private static function cm_requires_grade($cm): bool {
        // completion = auto and a grade item is required (completiongradeitemnumber set).
        if (isset($cm->completiongradeitemnumber) && $cm->completiongradeitemnumber !== null
                && $cm->completiongradeitemnumber !== '' && (int) $cm->completiongradeitemnumber >= 0) {
            return true;
        }
        return false;
    }

    /**
     * Grade-to-pass threshold for the activity (0 if none).
     *
     * @param \cm_info $cm
     * @return float
     */
    private static function activity_gradepass($cm): float {
        try {
            $item = self::main_grade_item($cm);
            if ($item && isset($item->gradepass) && (float) $item->gradepass > 0) {
                return (float) $item->gradepass;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        if ($cm->modname === 'quiz') {
            global $DB;
            try {
                // Some sites store pass only on grade_item; quiz table has no gradepass.
                $item = self::main_grade_item($cm);
                if ($item && (float) $item->gradepass > 0) {
                    return (float) $item->gradepass;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return 0.0;
    }

    /**
     * @param \cm_info $cm
     * @param array $marks
     * @return bool
     */
    private static function marks_meet_pass_grade($cm, array $marks): bool {
        if (empty($marks['hasgrade']) || $marks['total'] <= 0) {
            return false;
        }
        $pass = self::activity_gradepass($cm);
        if ($pass <= 0) {
            // Pass required in completion but no threshold set — any grade counts.
            return true;
        }
        return (float) $marks['obtained'] + 0.00001 >= $pass;
    }

    /**
     * Grade obtained / max for a single activity (0/0 if not graded).
     *
     * Uses the main grade item only (itemnumber 0). For quizzes, respects the
     * quiz grading method (highest / average / first / last attempt).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array{obtained:float,total:float,hasgrade:bool}
     */
    private static function activity_marks($cm, int $userid): array {
        $empty = ['obtained' => 0.0, 'total' => 0.0, 'hasgrade' => false];
        try {
            // Quizzes: prefer quiz grade method calculation.
            if ($cm->modname === 'quiz') {
                $quizmarks = self::quiz_user_marks($cm, $userid);
                if ($quizmarks['total'] > 0) {
                    return $quizmarks;
                }
            }

            $item = self::main_grade_item($cm);
            if (!$item) {
                return $empty;
            }

            if ((int) $item->gradetype === GRADE_TYPE_NONE) {
                return $empty;
            }
            if ((int) $item->gradetype !== GRADE_TYPE_VALUE && (int) $item->gradetype !== GRADE_TYPE_SCALE) {
                return $empty;
            }

            $max = (float) $item->grademax;
            if ($max <= 0) {
                return $empty;
            }

            if (!empty($item->needsupdate)) {
                try {
                    grade_regrade_final_grades((int) $cm->course, $userid, $item);
                    $item = self::main_grade_item($cm) ?: $item;
                } catch (\Throwable $e) {
                    // Continue.
                }
            }

            $obtained = null;
            $hasgrade = false;
            if (method_exists($item, 'get_final')) {
                $final = $item->get_final($userid);
                if (is_object($final) && $final->finalgrade !== null && $final->finalgrade !== '') {
                    $obtained = (float) $final->finalgrade;
                    $hasgrade = true;
                } else if (is_object($final) && isset($final->rawgrade)
                        && $final->rawgrade !== null && $final->rawgrade !== '') {
                    $obtained = (float) $final->rawgrade;
                    $hasgrade = true;
                }
            }
            if ($obtained === null) {
                $grade = grade_grade::fetch(['itemid' => (int) $item->id, 'userid' => $userid]);
                if ($grade) {
                    if ($grade->finalgrade !== null && $grade->finalgrade !== '') {
                        $obtained = (float) $grade->finalgrade;
                        $hasgrade = true;
                    } else if ($grade->rawgrade !== null && $grade->rawgrade !== '') {
                        $obtained = (float) $grade->rawgrade;
                        $hasgrade = true;
                    }
                }
            }

            if ($obtained === null) {
                $obtained = 0.0;
            }
            if ($obtained < 0) {
                $obtained = 0.0;
            }
            if ($obtained > $max) {
                $obtained = $max;
            }
            return ['obtained' => $obtained, 'total' => $max, 'hasgrade' => $hasgrade];
        } catch (\Throwable $e) {
            if ($cm->modname === 'quiz') {
                try {
                    return self::quiz_user_marks($cm, $userid);
                } catch (\Throwable $e2) {
                    return $empty;
                }
            }
            return $empty;
        }
    }

    /**
     * Main (itemnumber 0) grade item for an activity — safe when multiple items exist.
     *
     * @param \cm_info $cm
     * @return grade_item|null
     */
    private static function main_grade_item($cm): ?grade_item {
        if (function_exists('grade_get_grade_items_for_activity')) {
            $items = grade_get_grade_items_for_activity($cm, true);
            if (!empty($items)) {
                $item = reset($items);
                return $item instanceof grade_item ? $item : null;
            }
        }

        // Avoid grade_item::fetch() — it throws when more than one row matches.
        $items = grade_item::fetch_all([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
            'itemnumber' => 0,
        ]);
        if (!empty($items)) {
            $item = reset($items);
            return $item instanceof grade_item ? $item : null;
        }

        $items = grade_item::fetch_all([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);
        if (!empty($items)) {
            foreach ($items as $candidate) {
                if ((int) $candidate->itemnumber === 0) {
                    return $candidate;
                }
            }
            $item = reset($items);
            return $item instanceof grade_item ? $item : null;
        }
        return null;
    }

    /**
     * Quiz marks for a user, respecting quiz grading method.
     *
     * Uses quiz_grades when present (Moodle already applied highest/first/last/average),
     * otherwise computes from finished non-preview attempts.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array{obtained:float,total:float,hasgrade:bool}
     */
    private static function quiz_user_marks($cm, int $userid): array {
        global $DB, $CFG;
        $empty = ['obtained' => 0.0, 'total' => 0.0, 'hasgrade' => false];
        try {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', IGNORE_MISSING);
            if (!$quiz) {
                return $empty;
            }
            $max = (float) $quiz->grade;
            if ($max <= 0) {
                return $empty;
            }

            // Authoritative per-user quiz grade (already respects grademethod).
            $quizgrade = $DB->get_record('quiz_grades', [
                'quiz' => (int) $quiz->id,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);
            if ($quizgrade && $quizgrade->grade !== null && $quizgrade->grade !== '') {
                $obtained = (float) $quizgrade->grade;
                if ($obtained < 0) {
                    $obtained = 0.0;
                }
                if ($obtained > $max) {
                    $obtained = $max;
                }
                return ['obtained' => $obtained, 'total' => $max, 'hasgrade' => true];
            }

            // Compute from finished student attempts using quiz grademethod.
            $attempts = $DB->get_records_select(
                'quiz_attempts',
                'quiz = :quiz AND userid = :userid AND preview = 0 AND state = :state',
                [
                    'quiz' => (int) $quiz->id,
                    'userid' => $userid,
                    'state' => 'finished',
                ],
                'attempt ASC'
            );
            if (!$attempts) {
                return $empty;
            }

            $quizsum = (float) $quiz->sumgrades;
            if ($quizsum <= 0) {
                return ['obtained' => 0.0, 'total' => $max, 'hasgrade' => true];
            }

            $raw = self::quiz_combine_attempt_sumgrades($quiz, array_values($attempts));
            if ($raw === null) {
                return $empty;
            }
            $obtained = ($raw / $quizsum) * $max;
            if ($obtained < 0) {
                $obtained = 0.0;
            }
            if ($obtained > $max) {
                $obtained = $max;
            }
            return ['obtained' => $obtained, 'total' => $max, 'hasgrade' => true];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Combine attempt sumgrades using the quiz grading method.
     *
     * @param \stdClass $quiz
     * @param array $attempts finished attempts ordered by attempt ASC
     * @return float|null raw sumgrades-equivalent, or null if none
     */
    private static function quiz_combine_attempt_sumgrades($quiz, array $attempts): ?float {
        if (empty($attempts)) {
            return null;
        }

        // Ensure constants exist (from locallib.php).
        if (!defined('QUIZ_GRADEHIGHEST')) {
            define('QUIZ_GRADEHIGHEST', 1);
        }
        if (!defined('QUIZ_GRADEAVERAGE')) {
            define('QUIZ_GRADEAVERAGE', 2);
        }
        if (!defined('QUIZ_ATTEMPTFIRST')) {
            define('QUIZ_ATTEMPTFIRST', 3);
        }
        if (!defined('QUIZ_ATTEMPTLAST')) {
            define('QUIZ_ATTEMPTLAST', 4);
        }

        $method = (int) ($quiz->grademethod ?? QUIZ_GRADEHIGHEST);
        $sumgrades = [];
        foreach ($attempts as $attempt) {
            if ($attempt->sumgrades === null || $attempt->sumgrades === '') {
                continue;
            }
            $sumgrades[] = (float) $attempt->sumgrades;
        }
        if (empty($sumgrades)) {
            return 0.0;
        }

        switch ($method) {
            case QUIZ_ATTEMPTFIRST:
                return $sumgrades[0];
            case QUIZ_ATTEMPTLAST:
                return $sumgrades[count($sumgrades) - 1];
            case QUIZ_GRADEAVERAGE:
                return array_sum($sumgrades) / count($sumgrades);
            case QUIZ_GRADEHIGHEST:
            default:
                return max($sumgrades);
        }
    }

    /**
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
     * @param format_base $format
     * @param \section_info $section
     * @param \course_modinfo $modinfo
     * @param completion_info $completion
     * @param int $userid
     * @param \moodle_page|null $page When set, nest submodule/activity outline for home accordions.
     * @return array
     */
    private static function section_card(
        format_base $format,
        $section,
        $modinfo,
        completion_info $completion,
        int $userid,
        ?\moodle_page $page = null
    ): array {
        $course = $format->get_course();
        $sectionnum = (int) $section->section;
        $name = $format->get_section_name($section);

        $summary = '';
        if (!empty($section->summary)) {
            $summary = shorten_text(
                html_to_text(
                    format_text($section->summary, $section->summaryformat, [
                        'context' => context_course::instance($course->id),
                        'overflowdiv' => false,
                    ]),
                    0,
                    false
                ),
                140
            );
        }

        $tally = self::tally_section($section, $modinfo, $completion, $userid);
        $types = $tally['types'];
        $completed = $tally['completed'];
        $activitycount = $tally['tracked'];
        $submodules = $tally['submodules'];
        $marksobtained = (float) ($tally['marksobtained'] ?? 0);
        $markstotal = (float) ($tally['markstotal'] ?? 0);

        $pct = $activitycount > 0 ? (int) round(($completed / $activitycount) * 100) : 0;

        $sectionurl = $format->get_view_url($sectionnum, ['navigation' => true])->out(false);
        $actionlabel = $pct > 0
            ? get_string('continuemodule', 'format_nexcourse')
            : get_string('startmodule', 'format_nexcourse');

        $activitydisplay = get_string('activitiesprogress', 'format_nexcourse', (object) [
            'completed' => $completed,
            'total' => $activitycount,
        ]);
        $marksdisplay = get_string('marksprogress', 'format_nexcourse', (object) [
            'obtained' => self::format_marks($marksobtained),
            'total' => self::format_marks($markstotal),
        ]);
        $hashmarks = $markstotal > 0;

        $badges = [];
        if ($submodules > 0) {
            $badges[] = [
                'label' => get_string('badgesubmodules', 'format_nexcourse', $submodules),
                'type' => 'submodule',
            ];
        }
        if ($types['video'] > 0) {
            $badges[] = [
                'label' => get_string('badgevideos', 'format_nexcourse', $types['video']),
                'type' => 'video',
            ];
        }
        if ($types['practice'] > 0) {
            $badges[] = [
                'label' => get_string('badgepractices', 'format_nexcourse', $types['practice']),
                'type' => 'practice',
            ];
        }
        if ($types['discussion'] > 0) {
            $badges[] = [
                'label' => get_string('badgediscussions', 'format_nexcourse', $types['discussion']),
                'type' => 'discussion',
            ];
        }
        if ($types['other'] > 0) {
            $badges[] = [
                'label' => get_string('badgeactivities', 'format_nexcourse', $types['other']),
                'type' => 'other',
            ];
        }
        if (empty($badges)) {
            $badges[] = [
                'label' => get_string('noactivities', 'format_nexcourse'),
                'type' => 'empty',
            ];
        }

        $groups = [];
        $hasmultiplegroups = false;
        if ($page) {
            $panel = self::export_section_panel($format, $page, $sectionnum);
            if (is_array($panel) && !empty($panel['tabs'])) {
                foreach ($panel['tabs'] as $tab) {
                    $tabid = (string) ($tab['id'] ?? '');
                    if ($tabid === 'empty' && empty($tab['activities'])) {
                        continue;
                    }
                    $tab['id'] = 'm' . $sectionnum . '-' . $tabid;
                    // Only show a heading for the lone "Module activities" group when it is alone.
                    $tab['showgroupheading'] = ($tabid !== 'direct');
                    $groups[] = $tab;
                }
                $hasmultiplegroups = count($groups) > 1;
            }
        }

        return [
            'sectionnum' => $sectionnum,
            'modulenolabel' => get_string('modulenolabel', 'format_nexcourse', $sectionnum),
            'name' => $name,
            'summary' => $summary,
            'hassummary' => $summary !== '',
            'progresspct' => $pct,
            'actionlabel' => $actionlabel,
            'sectionurl' => $sectionurl,
            'badges' => $badges,
            'activitycount' => $activitycount,
            'completedcount' => $completed,
            'activitydisplay' => $activitydisplay,
            'marksobtained' => $marksobtained,
            'markstotal' => $markstotal,
            'marksdisplay' => $marksdisplay,
            'hashmarks' => $hashmarks,
            'submodulecount' => $submodules,
            'hasprogress' => $pct > 0,
            'groups' => $groups,
            'hasgroups' => !empty($groups),
            'hasmultiplegroups' => $hasmultiplegroups,
        ];
    }

    /**
     * Pretty-print marks (drop trailing .00).
     *
     * @param float $n
     * @return string
     */
    private static function format_marks(float $n): string {
        if (abs($n - round($n)) < 0.001) {
            return (string) (int) round($n);
        }
        return format_float($n, 2);
    }
}
