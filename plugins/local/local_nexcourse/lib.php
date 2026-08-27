<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexcourse.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Replace Moodle /my/courses.php with NexCourse when enabled.
 */
function local_nexcourse_before_http_headers(): void {
    global $SCRIPT;

    if (CLI_SCRIPT || AJAX_SCRIPT || during_initial_install()) {
        return;
    }
    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (get_config('local_nexcourse', 'replacemycourses') === '0') {
        return;
    }

    $script = (string) $SCRIPT;
    $ismycourses = ($script === '/my/courses.php' || $script === '/my/courses');
    if (!$ismycourses) {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexcourse:view', $context)) {
        return;
    }

    redirect(new moodle_url('/local/nexcourse/index.php'));
}

/**
 * Optionally inject NexCourse into the custom menu.
 *
 * @param global_navigation $nav
 */
function local_nexcourse_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (get_config('local_nexcourse', 'enablemenu') !== '1') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexcourse:view', $context)) {
        return;
    }

    $url = '/local/nexcourse/index.php';
    $label = get_string('pluginname', 'local_nexcourse');
    $haystack = (string) ($CFG->custommenuitems ?? '');
    if (stripos($haystack, $url) !== false) {
        return;
    }
    $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
    array_unshift($nodes, $label . '|' . $url);
    $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($line) {
        return trim((string) $line) !== '';
    }));
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_nexcourse_extends_navigation(global_navigation $nav): void {
    local_nexcourse_extend_navigation($nav);
}

/**
 * Full-bleed page chrome (Practice / CodeLab pattern).
 * Present as Moodle My courses so primary nav marks “My courses” active.
 *
 * @param moodle_page $page
 */
function local_nexcourse_setup_page(moodle_page $page): void {
    global $USER;

    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexcourse/fonts.css');
    // Match core /my/courses.php so RemUI / Boost highlight “My courses”.
    $page->set_context(context_user::instance($USER->id));
    $page->set_url(new moodle_url('/my/courses.php'));
    $page->set_pagelayout('standard');
    $page->set_pagetype('my-index');
    $page->set_heading('');
    navigation_node::override_active_url(new moodle_url('/my/courses.php'));

    $page->add_body_class('path-my');
    $page->add_body_class('path-my-courses');
    $page->add_body_class('path-local-nexcourse');
    $page->add_body_class('nxc-fullwidth');
    $page->add_body_class('nxc-bleed');
}
