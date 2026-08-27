<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Output hooks for local_nexlogin.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexlogin\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Bootstrap NexLogin chrome on auth pages.
 */
class hooks {

    /**
     * Load plugin lib.
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
        \local_nexlogin_bootstrap();
    }

    /**
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_head(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        self::loadlib();
        \local_nexlogin_bootstrap();
        $html = \local_nexlogin_head_html();
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
        $html = \local_nexlogin_top_html();
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
        \local_nexlogin_bootstrap();
        $html = \local_nexlogin_footer_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }
}
