<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Enrolled-course catalog for NexCourse hub.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcourse\local;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

/**
 * Build course list + header stats for the current user.
 */
final class catalog {

    public const PERPAGE = 12;

    /** Progress map cache TTL (seconds). */
    private const PROGRESS_TTL = 180;

    /** Pastel card tones (mockup palette). */
    private const TONES = ['rose', 'sky', 'butter', 'mint'];

    /** @var array<string,mixed> */
    private static $memo = [];

    /**
     * Fast first-paint shell — no completion scans. Cards load via AJAX.
     *
     * @param int $userid
     * @return array
     */
    public static function page_context(int $userid): array {
        global $USER;

        $meta = self::enrollment_meta($userid);
        $total = (int) $meta['total'];
        $counts = [
            'all' => $total,
            'completed' => 0,
            'inprogress' => 0,
            'notstarted' => $total,
        ];

        return array_merge(self::header_payload($USER, 0, $counts), [
            'counts' => $counts,
            'categories' => $meta['categories'],
            'hascategories' => !empty($meta['categories']),
            'courses' => [],
            'hascourses' => $total > 0,
            'total' => $total,
            'page' => 0,
            'perpage' => self::PERPAGE,
            'pages' => max(1, (int) ceil(max(1, $total) / self::PERPAGE)),
            'showpager' => false,
            'manage' => self::manage_actions(),
            'autoload' => $total > 0,
            'deferload' => true,
        ]);
    }

    /**
     * Create / manage course actions (same rules as core my/courses.php).
     *
     * @return array
     */
    public static function manage_actions(): array {
        $out = [
            'hasany' => false,
            'hasnewcourse' => false,
            'hasmanagecourses' => false,
            'hasrequest' => false,
            'newcourseurl' => '',
            'manageurl' => '',
            'requesturl' => '',
        ];

        if (!class_exists('\core_course_category')) {
            return $out;
        }

        try {
            $coursecat = \core_course_category::user_top();
            if (!$coursecat) {
                return $out;
            }

            $createcat = \core_course_category::get_nearest_editable_subcategory($coursecat, ['create']);
            if ($createcat) {
                $out['hasnewcourse'] = true;
                $out['newcourseurl'] = (new \moodle_url('/course/edit.php', [
                    'category' => $createcat->id,
                ]))->out(false);
            }

            $managecat = \core_course_category::get_nearest_editable_subcategory($coursecat, ['manage']);
            if ($managecat) {
                $out['hasmanagecourses'] = true;
                $out['manageurl'] = (new \moodle_url('/course/management.php', [
                    'categoryid' => $managecat->id,
                ]))->out(false);
            }

            $requestcat = \core_course_category::get_nearest_editable_subcategory(
                $coursecat,
                ['moodle/course:request']
            );
            if ($requestcat && $requestcat->can_request_course()) {
                $out['hasrequest'] = true;
                $out['requesturl'] = (new \moodle_url('/course/request.php', [
                    'categoryid' => $requestcat->id,
                ]))->out(false);
            }
        } catch (\Throwable $e) {
            return $out;
        }

        $out['hasany'] = $out['hasnewcourse'] || $out['hasmanagecourses'] || $out['hasrequest'];
        return $out;
    }

    /**
     * Paginated course fetch for AJAX.
     *
     * @param int $userid
     * @param int $page 0-based
     * @param int $perpage
     * @param string $search
     * @param string $status all|completed|inprogress|notstarted
     * @param int $categoryid
     * @return array
     */
    public static function fetch(
        int $userid,
        int $page,
        int $perpage,
        string $search,
        string $status,
        int $categoryid
    ): array {
        global $USER;

        $perpage = max(1, min(48, $perpage));
        $page = max(0, $page);

        $all = self::all_light($userid);
        $filtered = self::filter_rows($all, $search, $status, $categoryid);
        $total = count($filtered);
        $pages = max(1, (int) ceil(max(1, $total) / $perpage));
        if ($page > $pages - 1) {
            $page = max(0, $pages - 1);
        }
        $slice = array_slice($filtered, $page * $perpage, $perpage);
        $counts = self::status_counts($all);

        $progresssum = 0;
        foreach ($all as $c) {
            $progresssum += (int) $c['progress'];
        }
        $avg = $counts['all'] > 0 ? (int) round($progresssum / $counts['all']) : 0;

        return [
            'courses' => self::enrich($slice, $userid),
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
            'pages' => $pages,
            'counts' => $counts,
            'header' => self::header_payload($USER, $avg, $counts),
        ];
    }

    /**
     * Shared header fields for mustache / AJAX.
     *
     * @param \stdClass $user
     * @param int $avg
     * @param array $counts
     * @return array
     */
    private static function header_payload(\stdClass $user, int $avg, array $counts): array {
        return [
            'eyebrow' => get_string('eyebrow', 'local_nexcourse'),
            'title' => get_string('pagetitle', 'local_nexcourse'),
            'subtitle' => get_string('pagesubtitle', 'local_nexcourse'),
            'displayname' => fullname($user),
            'contentpct' => $avg,
            'contentitems' => [
                [
                    'key' => 'enrolled',
                    'label' => get_string('enrolled', 'local_nexcourse'),
                    'display' => (string) ($counts['all'] ?? 0),
                ],
                [
                    'key' => 'complete',
                    'label' => get_string('complete', 'local_nexcourse'),
                    'display' => (string) ($counts['completed'] ?? 0),
                ],
                [
                    'key' => 'started',
                    'label' => get_string('started', 'local_nexcourse'),
                    'display' => (string) ($counts['inprogress'] ?? 0),
                ],
                [
                    'key' => 'todo',
                    'label' => get_string('todo', 'local_nexcourse'),
                    'display' => (string) ($counts['notstarted'] ?? 0),
                ],
            ],
            'hasstats' => true,
            'stats' => [
                [
                    'key' => 'enrolled',
                    'value' => (string) ($counts['all'] ?? 0),
                    'label' => get_string('enrolled', 'local_nexcourse'),
                ],
                [
                    'key' => 'avg',
                    'value' => $avg . '%',
                    'label' => get_string('avgprogress', 'local_nexcourse'),
                ],
                [
                    'key' => 'complete',
                    'value' => (string) ($counts['completed'] ?? 0),
                    'label' => get_string('completed', 'local_nexcourse'),
                ],
                [
                    'key' => 'progress',
                    'value' => (string) ($counts['inprogress'] ?? 0),
                    'label' => get_string('inprogress', 'local_nexcourse'),
                ],
            ],
        ];
    }

    /**
     * Enrollment list without completion (categories + total only).
     *
     * @param int $userid
     * @return array{total:int,categories:array}
     */
    private static function enrollment_meta(int $userid): array {
        $memokey = 'meta:' . $userid;
        if (isset(self::$memo[$memokey])) {
            return self::$memo[$memokey];
        }

        $raw = enrol_get_users_courses(
            $userid,
            true,
            'id, fullname, shortname, summary, visible, startdate, category'
        );
        $total = 0;
        $catmap = [];
        foreach ($raw as $c) {
            if (empty($c->visible) && !has_capability(
                'moodle/course:viewhiddencourses',
                \context_course::instance((int) $c->id)
            )) {
                continue;
            }
            $total++;
            $categoryid = (int) ($c->category ?? 0);
            if ($categoryid <= 0) {
                continue;
            }
            if (!isset($catmap[$categoryid])) {
                $catmap[$categoryid] = [
                    'id' => $categoryid,
                    'name' => self::category_name($categoryid),
                    'count' => 0,
                ];
            }
            $catmap[$categoryid]['count']++;
        }
        $categories = array_values($catmap);
        usort($categories, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        $out = ['total' => $total, 'categories' => $categories];
        self::$memo[$memokey] = $out;
        return $out;
    }

    /**
     * Rows with progress (cached). Used by AJAX fetch.
     *
     * @param int $userid
     * @return array[]
     */
    public static function all_light(int $userid): array {
        $memokey = 'all:' . $userid;
        if (isset(self::$memo[$memokey])) {
            return self::$memo[$memokey];
        }

        $raw = enrol_get_users_courses(
            $userid,
            true,
            'id, fullname, shortname, summary, visible, startdate, category'
        );
        $progressmap = self::progress_map($userid, $raw);
        $out = [];
        foreach ($raw as $c) {
            if (empty($c->visible) && !has_capability(
                'moodle/course:viewhiddencourses',
                \context_course::instance((int) $c->id)
            )) {
                continue;
            }

            $progress = (int) ($progressmap[(int) $c->id] ?? 0);
            if ($progress >= 100) {
                $status = 'completed';
                $cta = get_string('review', 'local_nexcourse');
            } else if ($progress > 0) {
                $status = 'inprogress';
                $cta = get_string('continue', 'local_nexcourse');
            } else {
                $status = 'notstarted';
                $cta = get_string('start', 'local_nexcourse');
            }

            $fullname = format_string($c->fullname);
            $shortname = format_string($c->shortname);
            $categoryid = (int) ($c->category ?? 0);
            $category = self::category_name($categoryid);
            $summary = trim(html_to_text($c->summary ?? '', 0, false));
            if (\core_text::strlen($summary) > 110) {
                $summary = \core_text::substr($summary, 0, 107) . '…';
            }
            if ($summary === '') {
                $summary = get_string('coursedefaultsummary', 'local_nexcourse', $shortname);
            }

            $out[] = [
                'id' => (int) $c->id,
                'name' => $fullname,
                'shortname' => $shortname,
                'summary' => $summary,
                'initials' => self::initials($fullname),
                'tone' => self::TONES[((int) $c->id) % count(self::TONES)],
                'progress' => $progress,
                'status' => $status,
                'statuslabel' => get_string($status, 'local_nexcourse'),
                'badge' => $category !== '' ? $category : get_string('coursebadge', 'local_nexcourse'),
                'categoryid' => $categoryid,
                'category' => $category,
                'hascategory' => $category !== '',
                'startdate' => !empty($c->startdate)
                    ? userdate((int) $c->startdate, get_string('strftimedate', 'langconfig'))
                    : '',
                'url' => (new \moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
                'cta' => $cta,
                'search' => \core_text::strtolower($fullname . ' ' . $shortname . ' ' . $category . ' ' . $summary),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $rank = ['inprogress' => 0, 'notstarted' => 1, 'completed' => 2];
            $ra = $rank[$a['status']] ?? 9;
            $rb = $rank[$b['status']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int) $b['progress']) <=> ((int) $a['progress']);
        });

        self::$memo[$memokey] = $out;
        return $out;
    }

    /**
     * courseid => progress % (application cache + request memo).
     *
     * @param int $userid
     * @param array|\Traversable $courses
     * @return array<int,int>
     */
    private static function progress_map(int $userid, $courses): array {
        $memokey = 'prog:' . $userid;
        if (isset(self::$memo[$memokey])) {
            return self::$memo[$memokey];
        }

        $cache = \cache::make('local_nexcourse', 'courseprogress');
        $cachekey = 'u' . $userid;
        $cached = $cache->get($cachekey);
        if (is_array($cached) && isset($cached['map'], $cached['expires'])
                && (int) $cached['expires'] > time()) {
            self::$memo[$memokey] = $cached['map'];
            return $cached['map'];
        }

        $map = [];
        foreach ($courses as $c) {
            $cid = (int) $c->id;
            $map[$cid] = self::progress_pct($c, $userid);
        }

        $cache->set($cachekey, [
            'map' => $map,
            'expires' => time() + self::PROGRESS_TTL,
        ]);
        self::$memo[$memokey] = $map;
        return $map;
    }

    /**
     * @param array[] $rows
     * @param string $search
     * @param string $status
     * @param int $categoryid
     * @return array[]
     */
    private static function filter_rows(array $rows, string $search, string $status, int $categoryid): array {
        $q = \core_text::strtolower(trim($search));
        $out = [];
        foreach ($rows as $r) {
            if ($status !== 'all' && ($r['status'] ?? '') !== $status) {
                continue;
            }
            if ($categoryid > 0 && (int) ($r['categoryid'] ?? 0) !== $categoryid) {
                continue;
            }
            if ($q !== '' && strpos((string) ($r['search'] ?? ''), $q) === false) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Attach light foot stats for a page of rows only (no full CM completion scan).
     *
     * @param array[] $rows
     * @param int $userid
     * @return array[]
     */
    private static function enrich(array $rows, int $userid): array {
        $out = [];
        foreach ($rows as $r) {
            $sections = 0;
            $activities = 0;
            try {
                $course = get_course((int) $r['id']);
                $modinfo = get_fast_modinfo($course, $userid);
                $sectionhas = [];
                foreach ($modinfo->get_cms() as $cm) {
                    if ($cm->deletioninprogress || !$cm->uservisible) {
                        continue;
                    }
                    if ($cm->modname === 'label') {
                        continue;
                    }
                    $activities++;
                    $sectionhas[(int) $cm->sectionnum] = true;
                }
                foreach ($modinfo->get_section_info_all() as $section) {
                    if ((int) $section->section === 0) {
                        continue;
                    }
                    if (!empty($sectionhas[(int) $section->section])) {
                        $sections++;
                    }
                }
            } catch (\Throwable $e) {
                $activities = 0;
                $sections = 0;
            }

            // Estimate completed from course progress % (avoids per-CM get_data).
            $completed = 0;
            if ($activities > 0 && (int) $r['progress'] > 0) {
                $completed = (int) round($activities * ((int) $r['progress'] / 100));
                $completed = max(0, min($activities, $completed));
            }

            $r['activities'] = $activities;
            $r['completed'] = $completed;
            $r['sections'] = $sections;
            $r['activitieslabel'] = get_string('activitiescount', 'local_nexcourse', $activities);
            $r['sectionslabel'] = get_string('sectionscount', 'local_nexcourse', $sections);
            $r['hassections'] = $sections > 0;

            if ($activities > 0) {
                $r['footlabel'] = get_string('activitieslabel', 'local_nexcourse');
                $r['footvalue'] = $completed . '/' . $activities;
            } else if (!empty($r['startdate'])) {
                $r['footlabel'] = get_string('startdatelabel', 'local_nexcourse');
                $r['footvalue'] = $r['startdate'];
            } else {
                $r['footlabel'] = get_string('activitieslabel', 'local_nexcourse');
                $r['footvalue'] = '0/0';
            }
            $r['hasfootvalue'] = true;
            unset($r['startdate'], $r['search']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @param array[] $rows
     * @return array{all:int,completed:int,inprogress:int,notstarted:int}
     */
    private static function status_counts(array $rows): array {
        $counts = ['all' => count($rows), 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0];
        foreach ($rows as $r) {
            $s = $r['status'] ?? 'notstarted';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
        return $counts;
    }

    /**
     * @param int $categoryid
     * @return string
     */
    private static function category_name(int $categoryid): string {
        if ($categoryid <= 0) {
            return '';
        }
        $memokey = 'cat:' . $categoryid;
        if (array_key_exists($memokey, self::$memo)) {
            return (string) self::$memo[$memokey];
        }
        $name = '';
        try {
            if (class_exists('\core_course_category')) {
                $cat = \core_course_category::get($categoryid, IGNORE_MISSING, true);
                if ($cat) {
                    $name = $cat->get_formatted_name();
                }
            }
        } catch (\Throwable $e) {
            $name = '';
        }
        self::$memo[$memokey] = $name;
        return $name;
    }

    /**
     * @param string $name
     * @return string
     */
    private static function initials(string $name): string {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= \core_text::strtoupper(\core_text::substr($part, 0, 1));
            if (\core_text::strlen($letters) >= 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : 'C';
    }

    /**
     * @param \stdClass $course
     * @param int $userid
     * @return int
     */
    private static function progress_pct(\stdClass $course, int $userid): int {
        if (!class_exists('\core_completion\progress')) {
            return 0;
        }
        try {
            $pct = \core_completion\progress::get_course_progress_percentage($course, $userid);
            return (int) round($pct ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
