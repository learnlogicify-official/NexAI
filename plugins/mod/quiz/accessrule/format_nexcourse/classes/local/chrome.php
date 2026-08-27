<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Shared NexCourse chrome for course secondary-nav tabs.
 *
 * Course tab already renders header via format templates. Other tabs get the same
 * visual language (body classes, secondary nav AMD, optional header/stats).
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcourse\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Theme + header chrome for Participants / Settings / Grades / Activities / etc.
 */
class chrome {

    /** @var bool */
    private static bool $amdqueued = false;

    /** @var bool */
    private static bool $dominjected = false;

    /**
     * @param \moodle_page $page
     */
    public static function on_course_set(\moodle_page $page): void {
        self::bootstrap($page);
    }

    /**
     * @param \moodle_page $page
     * @return bool
     */
    public static function applies(\moodle_page $page): bool {
        $course = self::resolve_course($page);
        if (!$course || (int) $course->id < 2) {
            return false;
        }
        if (!self::course_is_nexcourse($course)) {
            return false;
        }

        $pagetype = strtolower((string) ($page->pagetype ?? ''));
        $path = self::page_path($page);

        // Course home / section already have header from format output.
        if (str_starts_with($pagetype, 'course-view')
                || str_contains($path, '/course/view.php')
                || str_contains($path, '/course/section.php')) {
            return false;
        }
        // Activity modules keep their own UI (arena etc.).
        if (str_starts_with($pagetype, 'mod-') || preg_match('#/mod/[a-z0-9_]+/#', $path)) {
            return false;
        }

        // Any other page in this course context should match Course-tab design.
        $ctx = $page->context ?? null;
        if ($ctx && (int) $ctx->contextlevel === CONTEXT_COURSE) {
            return true;
        }

        return self::is_known_course_tab($path, $pagetype);
    }

    /**
     * @param string $path
     * @param string $pagetype
     * @return bool
     */
    private static function is_known_course_tab(string $path, string $pagetype): bool {
        if (str_contains($pagetype, 'user-index') || str_contains($path, 'user/index')) {
            return true;
        }
        if ($pagetype === 'course-edit' || str_contains($path, 'course/edit.php')) {
            return true;
        }
        if (str_starts_with($pagetype, 'grade-') || str_contains($path, '/grade/')) {
            return true;
        }
        if ($pagetype === 'course-resources' || str_contains($path, 'course/resources')) {
            return true;
        }
        if (str_contains($pagetype, 'competenc') || str_contains($path, 'coursecompetencies')
                || str_contains($path, 'tool/lp/')) {
            return true;
        }
        if (str_contains($path, '/report/') || str_contains($path, '/badges/')
                || str_contains($path, '/question/') || str_contains($path, '/enrol/')
                || str_contains($path, '/backup/')) {
            return true;
        }
        return false;
    }

    /**
     * Body classes + AMD (safe before headers). Never throws after output starts.
     *
     * @param \moodle_page $page
     */
    public static function bootstrap(\moodle_page $page): void {
        if (!self::applies($page)) {
            return;
        }
        if ((int) $page->state <= (int) \moodle_page::STATE_BEFORE_HEADER) {
            $page->add_body_class('format-nexcourse');
            $page->add_body_class('nx-course-chrome');
        }
        self::queue_amd($page);
    }

    /**
     * Header/stats HTML + template for JS placement above secondary nav.
     * Skipped on Settings / Grades / Competencies (theme UI still applies).
     *
     * @param \moodle_page $page
     * @return string
     */
    public static function inject_html(\moodle_page $page): string {
        if (!self::applies($page)) {
            return '';
        }
        self::queue_amd($page);

        // Keep themed tables/forms/nav — only drop the banner + stats strip here.
        if (self::skip_header_strip($page)) {
            return '';
        }

        if (self::$dominjected) {
            return '';
        }

        $inner = self::render_header_inner_html($page);
        if ($inner === '') {
            return '';
        }

        self::$dominjected = true;
        return '<div id="nx-chrome-mount" class="nx-chrome-mount" data-region="nx-chrome-mount">'
            . $inner
            . '</div>'
            . '<template id="nx-chrome-src">' . $inner . '</template>';
    }

    /**
     * Settings, Grades, and Competencies: no nx-header / stats strip.
     *
     * @param \moodle_page $page
     * @return bool
     */
    public static function skip_header_strip(\moodle_page $page): bool {
        $pagetype = strtolower((string) ($page->pagetype ?? ''));
        $path = self::page_path($page);

        // Settings.
        if ($pagetype === 'course-edit'
                || str_contains($path, 'course/edit.php')
                || str_contains($path, 'course/editsection.php')) {
            return true;
        }
        // Grades.
        if (str_starts_with($pagetype, 'grade-') || str_contains($path, '/grade/')) {
            return true;
        }
        // Competencies.
        if (str_contains($pagetype, 'competenc')
                || str_contains($path, 'coursecompetencies')
                || str_contains($path, 'tool/lp/')
                || str_contains($path, '/competency/')) {
            return true;
        }
        return false;
    }

    /**
     * @param \moodle_page $page
     */
    private static function queue_amd(\moodle_page $page): void {
        if (self::$amdqueued) {
            return;
        }
        try {
            $page->requires->js_call_amd('format_nexcourse/ui', 'init', [['chrome' => true]]);
            self::$amdqueued = true;
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @param \moodle_page $page
     * @return string
     */
    private static function render_header_inner_html(\moodle_page $page): string {
        global $PAGE, $OUTPUT;

        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $course = self::resolve_course($page);
        if (!$course || !self::course_is_nexcourse($course)) {
            $cached = '';
            return $cached;
        }

        try {
            $format = course_get_format($course);
            if (!$format || $format->get_format() !== 'nexcourse') {
                $cached = '';
                return $cached;
            }
            $data = catalog::export_chrome_header($format, $PAGE);
            $html = $OUTPUT->render_from_template('format_nexcourse/local/course_header', $data);
            $cached = (is_string($html) && str_contains($html, 'nx-header')) ? $html : '';
        } catch (\Throwable $e) {
            debugging('format_nexcourse chrome header: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $cached = '';
        }
        return $cached;
    }

    /**
     * @param \stdClass $course
     * @return bool
     */
    private static function course_is_nexcourse(\stdClass $course): bool {
        $format = strtolower(trim((string) ($course->format ?? '')));
        if ($format === 'nexcourse' || str_contains($format, 'nexcourse')) {
            return true;
        }
        $id = (int) ($course->id ?? 0);
        if ($id < 2) {
            return false;
        }
        try {
            $full = get_course($id);
            $f = strtolower(trim((string) ($full->format ?? '')));
            return $f === 'nexcourse' || str_contains($f, 'nexcourse');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param \moodle_page $page
     * @return string
     */
    private static function page_path(\moodle_page $page): string {
        $bits = [];
        try {
            if ($page->url) {
                $bits[] = (string) ($page->url->get_path() ?? '');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $bits[] = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $bits[] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return strtolower(implode(' ', $bits));
    }

    /**
     * @param \moodle_page $page
     * @return \stdClass|null
     */
    private static function resolve_course(\moodle_page $page): ?\stdClass {
        global $COURSE;

        $courseid = 0;
        if (!empty($page->course->id)) {
            $courseid = (int) $page->course->id;
        } else if (!empty($COURSE->id)) {
            $courseid = (int) $COURSE->id;
        } else {
            try {
                if ($page->url) {
                    $courseid = (int) $page->url->param('id');
                    if ($courseid < 2) {
                        $courseid = (int) $page->url->param('courseid');
                    }
                }
            } catch (\Throwable $e) {
                $courseid = 0;
            }
            if ($courseid < 2) {
                $courseid = (int) optional_param('id', 0, PARAM_INT);
                if ($courseid < 2) {
                    $courseid = (int) optional_param('courseid', 0, PARAM_INT);
                }
            }
        }
        if ($courseid < 2) {
            return null;
        }
        try {
            return get_course($courseid);
        } catch (\Throwable $e) {
            return !empty($page->course) ? $page->course : (!empty($COURSE->id) ? $COURSE : null);
        }
    }
}
