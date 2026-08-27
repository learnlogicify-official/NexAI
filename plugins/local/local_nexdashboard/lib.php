<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexdashboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Replace Moodle's /my/ Dashboard with NexDashboard when enabled.
 *
 * Called from core early in every request (plugin callback).
 */
function local_nexdashboard_before_http_headers(): void {
    global $SCRIPT, $CFG;

    if (CLI_SCRIPT || AJAX_SCRIPT || during_initial_install()) {
        return;
    }
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Drop leftover Dashboard custom-menu rows; keep / inject Leaderboard.
    local_nexdashboard_scrub_custom_menu();
    local_nexdashboard_ensure_leaderboard_menu();

    if (get_config('local_nexdashboard', 'replacemyhome') === '0') {
        return;
    }

    $script = (string) $SCRIPT;
    $ismyhome = ($script === '/my/index.php' || $script === '/my/' || $script === '/my');
    if (!$ismyhome) {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexdashboard:view', $context)) {
        return;
    }

    redirect(new moodle_url('/local/nexdashboard/index.php'));
}

/**
 * Remove custom-menu rows that point at the NexDashboard home page so Moodle
 * keeps a single native “Dashboard” (/my/) entry. Leaderboard stays.
 */
function local_nexdashboard_scrub_custom_menu(): void {
    global $CFG;

    $haystack = (string) ($CFG->custommenuitems ?? '');
    if ($haystack === '' || stripos($haystack, '/local/nexdashboard/') === false) {
        return;
    }

    $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
    $kept = [];
    foreach ($nodes as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (stripos($trim, '/local/nexdashboard/leaderboard.php') !== false) {
            $kept[] = $line;
            continue;
        }
        if (stripos($trim, '/local/nexdashboard/') !== false) {
            continue;
        }
        $kept[] = $line;
    }
    $CFG->custommenuitems = implode("\n", $kept);
}

/**
 * Add Leaderboard to the top custom menu (Moodle 4+/5 / RemUI).
 */
function local_nexdashboard_ensure_leaderboard_menu(): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexdashboard:view', $context)) {
        return;
    }

    $url = '/local/nexdashboard/leaderboard.php';
    $label = get_string('navleaderboard', 'local_nexdashboard');
    $haystack = (string) ($CFG->custommenuitems ?? '');
    if (stripos($haystack, $url) !== false) {
        return;
    }

    $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
    $nodes[] = $label . '|' . $url;
    $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($line) {
        return trim((string) $line) !== '';
    }));
}

/**
 * Do not inject a second Dashboard into the custom menu.
 * Moodle / RemUI already expose Dashboard → /my/ (redirected to NexDashboard).
 * Leaderboard is a separate top-navbar item.
 *
 * @param global_navigation $nav
 */
function local_nexdashboard_extend_navigation(global_navigation $nav): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }
    local_nexdashboard_scrub_custom_menu();
    local_nexdashboard_ensure_leaderboard_menu();
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_nexdashboard_extends_navigation(global_navigation $nav): void {
    local_nexdashboard_extend_navigation($nav);
}

/**
 * Full-bleed page chrome.
 * Present as Moodle Dashboard so primary nav marks “Dashboard” active.
 *
 * @param moodle_page $page
 */
function local_nexdashboard_setup_page(moodle_page $page): void {
    global $USER;

    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexdashboard/fonts.css');
    // Match core /my/ so RemUI / Boost highlight “Dashboard”.
    $page->set_context(context_user::instance($USER->id));
    $page->set_url(new moodle_url('/my/index.php'));
    $page->set_pagelayout('standard');
    $page->set_pagetype('my-index');
    $page->set_heading('');
    navigation_node::override_active_url(new moodle_url('/my/index.php'));

    $page->add_body_class('path-my');
    $page->add_body_class('path-my-index');
    $page->add_body_class('path-local-nexdashboard');
    $page->add_body_class('nxd-fullwidth');
    $page->add_body_class('nxd-bleed');
}

/**
 * Overall leaderboard chrome. Keeps its own URL (not /my/).
 *
 * @param moodle_page $page
 */
function local_nexdashboard_setup_leaderboard_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexdashboard/fonts.css');
    $page->set_context(context_system::instance());
    $page->set_url(new moodle_url('/local/nexdashboard/leaderboard.php'));
    $page->set_pagelayout('standard');
    $page->set_heading('');
    $page->add_body_class('path-local-nexdashboard');
    $page->add_body_class('nxd-fullwidth');
    $page->add_body_class('nxd-bleed');
}

/**
 * Time-of-day greeting string.
 *
 * @return string
 */
function local_nexdashboard_greeting(): string {
    $hour = (int) userdate(time(), '%H');
    if ($hour < 12) {
        return get_string('greetingmorning', 'local_nexdashboard');
    }
    if ($hour < 17) {
        return get_string('greetingafternoon', 'local_nexdashboard');
    }
    return get_string('greetingevening', 'local_nexdashboard');
}
