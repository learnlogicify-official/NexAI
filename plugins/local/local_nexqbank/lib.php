<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library hooks for local_nexqbank.
 *
 * @package    local_nexqbank
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add Question Bank to global navigation for site admins only.
 *
 * @param global_navigation $nav
 */
function local_nexqbank_extend_navigation(global_navigation $nav): void {
    if (!isloggedin() || isguestuser() || !is_siteadmin()) {
        return;
    }

    $url = new moodle_url('/local/nexqbank/index.php');
    $node = $nav->add(
        get_string('navlabel', 'local_nexqbank'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_nexqbank',
        new pix_icon('i/questions', '')
    );
    $node->showinflatnavigation = true;
    $node->mainnavonly = false;

    local_nexqbank_ensure_custom_menu();
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_nexqbank_extends_navigation(global_navigation $nav): void {
    local_nexqbank_extend_navigation($nav);
}

/**
 * Inject top custom-menu item for RemUI / classic themes (site admin only).
 */
function local_nexqbank_ensure_custom_menu(): void {
    global $CFG;

    if (!is_siteadmin()) {
        return;
    }

    $label = get_string('navlabel', 'local_nexqbank');
    $url = (new moodle_url('/local/nexqbank/index.php'))->out(false);
    $needle = '|/local/nexqbank/index.php';
    $haystack = (string) ($CFG->custommenuitems ?? '');
    if (strpos($haystack, $needle) !== false || strpos($haystack, $url) !== false) {
        return;
    }

    $line = $label . '|' . $url;
    $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
    $nodes[] = $line;
    $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($l) {
        return trim((string) $l) !== '';
    }));
}

/**
 * Require site administrator.
 *
 * @throws moodle_exception
 */
function local_nexqbank_require_siteadmin(): void {
    require_login();
    if (!is_siteadmin()) {
        throw new moodle_exception('siteadminonly', 'local_nexqbank');
    }
}
