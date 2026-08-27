<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Full-screen learn chrome for NexCoursePro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Body classes + AMD for Pro shell / activity back bar / iframe embed.
 */
class chrome {

    /** @var bool */
    private static bool $amdqueued = false;

    /**
     * @param \moodle_page $page
     */
    public static function on_course_set(\moodle_page $page): void {
        $course = $page->course ?? null;
        if (!$course || ($course->format ?? '') !== 'nexcoursepro') {
            return;
        }
        $page->add_body_class('format-nexcoursepro');
        $page->add_body_class('nxpro-chrome');
        if (is_siteadmin()) {
            $page->add_body_class('nxpro-siteadmin');
        }
        $pagetype = strtolower((string) ($page->pagetype ?? ''));
        $iscourseview = str_starts_with($pagetype, 'course-view')
            || str_contains((string) $page->url, '/course/view.php');
        $editing = $page->user_is_editing();

        // Learn shell only on course view when not editing.
        if ($iscourseview && !$editing) {
            $page->add_body_class('nxpro-learn-page');
            self::queue_amd($page, false);
            return;
        }

        // Edit mode + Participants / Grades / Reports / Settings / etc.
        // Share Pro tabs + soft content chrome (same family as learn shell).
        $page->add_body_class('nxpro-native-edit');
        $path = (string) ($page->url ?? '');
        if (str_contains($pagetype, 'user-index') || str_contains($path, '/user/index.php')) {
            $page->add_body_class('nxpro-participants');
        }
        self::queue_amd($page, false);
    }

    /**
     * @param \moodle_page $page
     */
    public static function on_cm_set(\moodle_page $page): void {
        $course = $page->course ?? null;
        if (!$course || ($course->format ?? '') !== 'nexcoursepro') {
            return;
        }
        $page->add_body_class('format-nexcoursepro');
        $page->add_body_class('nxpro-chrome');
        if (is_siteadmin()) {
            $page->add_body_class('nxpro-siteadmin');
        }

        // Left-pane embeds (nxproembed=1) and Moodle H5P /h5p/embed.php both call
        // set_cm — treat them as embeds so we never inject "Back to course" above
        // the player inside the iframe.
        if (self::is_embed_context($page)) {
            $page->add_body_class('nxpro-embed');
            $page->add_body_class('nxpro-fullscreen');
            self::queue_amd($page, false);
            return;
        }

        // Quiz review: full-screen shell (no site navbar/sidebar), opened from Attempts.
        if (self::is_quiz_review_page($page)) {
            $page->add_body_class('nxpro-review-fullscreen');
            $page->add_body_class('nxpro-fullscreen');
            self::queue_amd($page, false, true);
            return;
        }

        $page->add_body_class('nxpro-mod-page');
        self::queue_amd($page, true);
    }

    /**
     * True when this is a quiz attempt review page.
     *
     * @param \moodle_page $page
     * @return bool
     */
    private static function is_quiz_review_page(\moodle_page $page): bool {
        $pagetype = strtolower((string) ($page->pagetype ?? ''));
        if (str_contains($pagetype, 'mod-quiz-review')) {
            return true;
        }
        $url = (string) ($page->url ?? '');
        if ($url === '' && !empty($page->url) && method_exists($page->url, 'out_omit_querystring')) {
            $url = $page->url->out_omit_querystring();
        }
        return str_contains($url, '/mod/quiz/review.php');
    }

    /**
     * True when this CM page is meant to render inside Pro's left-pane player.
     *
     * @param \moodle_page $page
     * @return bool
     */
    private static function is_embed_context(\moodle_page $page): bool {
        if (optional_param('nxproembed', 0, PARAM_INT)) {
            return true;
        }
        if (($page->pagelayout ?? '') === 'embedded') {
            return true;
        }
        $url = (string) ($page->url ?? '');
        if ($url === '' && !empty($page->url) && method_exists($page->url, 'out_omit_querystring')) {
            $url = $page->url->out_omit_querystring();
        }
        return str_contains($url, '/h5p/embed.php')
            || str_contains($url, '/mod/hvp/embed.php');
    }

    /**
     * Course URL that reopens Pro on the current activity (when known).
     *
     * @param \moodle_page $page
     * @return string
     */
    private static function course_back_url(\moodle_page $page): string {
        $course = $page->course ?? null;
        if (!$course) {
            return '';
        }
        $params = ['id' => (int) $course->id];
        if (!empty($page->cm) && !empty($page->cm->id)) {
            $params['cmid'] = (int) $page->cm->id;
        }
        return (new \moodle_url('/course/view.php', $params))->out(false);
    }

    /**
     * @param \moodle_page $page
     * @param bool $modpage
     * @param bool $reviewfullscreen
     */
    private static function queue_amd(\moodle_page $page, bool $modpage = false, bool $reviewfullscreen = false): void {
        if (self::$amdqueued) {
            return;
        }
        self::$amdqueued = true;
        $embed = self::is_embed_context($page);
        $pagetype = strtolower((string) ($page->pagetype ?? ''));
        $isquizattempt = $modpage && !$embed && (
            str_contains($pagetype, 'mod-quiz-attempt')
            || str_contains($pagetype, 'mod-quiz-summary')
            || str_contains($pagetype, 'mod-quiz-startattempt')
            || str_contains((string) $page->url, '/mod/quiz/attempt.php')
            || str_contains((string) $page->url, '/mod/quiz/summary.php')
            || str_contains((string) $page->url, '/mod/quiz/startattempt.php')
        );

        $page->requires->js_call_amd('format_nexcoursepro/ui', 'init', [[
            'modpage' => $modpage && !$embed && !$reviewfullscreen,
            'embed' => $embed,
            'backurl' => self::course_back_url($page),
            'backlabel' => get_string('backtocourse', 'format_nexcoursepro'),
            'issiteadmin' => is_siteadmin(),
            'quizattemptback' => $isquizattempt,
            'reviewFullscreen' => $reviewfullscreen,
            'courseid' => (int) ($page->course->id ?? 0),
        ]]);
    }
}
