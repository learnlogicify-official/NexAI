<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Hook callbacks for local_llassessment (+ NexCourse chrome bridge).
 *
 * @package    local_llassessment
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_llassessment\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Output hooks for arena bootstrap + NexCourse secondary-tab chrome.
 */
class hooks {

    /**
     * Load plugin lib.php relative to this file.
     */
    private static function loadlib(): void {
        require_once(dirname(__DIR__, 2) . '/lib.php');
    }

    /**
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(
        \core\hook\output\before_http_headers $hook
    ): void {
        self::loadlib();
        \local_llassessment_bootstrap_pages();
        \local_llassessment_nexcourse_chrome_bootstrap();
    }

    /**
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_head(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        self::loadlib();
        \local_llassessment_nexcourse_chrome_bootstrap();
        $html = \local_llassessment_head_fallback_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function before_top_of_body(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        self::loadlib();
        $html = \local_llassessment_head_fallback_html();
        $chrome = \local_llassessment_nexcourse_chrome_html();
        if ($chrome !== '') {
            $html .= $chrome;
        }
        $outline = \local_llassessment_quiz_outline_html();
        if ($outline !== '') {
            $html .= $outline;
        }
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        self::loadlib();
        \local_llassessment_bootstrap_pages();
        $chrome = \local_llassessment_nexcourse_chrome_html();
        if ($chrome !== '') {
            $hook->add_html($chrome);
        }
        $outline = \local_llassessment_quiz_outline_html();
        if ($outline !== '') {
            $hook->add_html($outline);
        }
    }
}
