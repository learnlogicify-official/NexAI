<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexprofile.
 *
 * @package   local_nexprofile
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Replace Moodle /user/profile.php with NexProfile when enabled.
 */
function local_nexprofile_before_http_headers(): void {
    global $SCRIPT, $USER;

    if (CLI_SCRIPT || AJAX_SCRIPT || during_initial_install()) {
        return;
    }
    if (!isloggedin() || isguestuser()) {
        return;
    }
    if (get_config('local_nexprofile', 'replaceprofile') === '0') {
        return;
    }
    $script = (string) $SCRIPT;
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $isprofile = ($script === '/user/profile.php')
        || (strpos($uri, '/user/profile.php') !== false);
    if (!$isprofile) {
        return;
    }
    if (optional_param('nxp', '', PARAM_ALPHANUMEXT) === 'classic') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexprofile:view', $context)) {
        return;
    }

    $id = optional_param('id', 0, PARAM_INT);
    if ($id < 1) {
        $id = (int) $USER->id;
    }

    redirect(new moodle_url('/local/nexprofile/view.php', ['id' => $id]));
}

/**
 * Link from the classic Moodle profile tree.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_nexprofile_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $context = context_system::instance();
    if (!has_capability('local/nexprofile:view', $context)) {
        return;
    }
    $url = new moodle_url('/local/nexprofile/view.php', ['id' => (int) $user->id]);
    $node = new core_user\output\myprofile\node(
        'miscellaneous',
        'nexprofile',
        get_string('pluginname', 'local_nexprofile'),
        null,
        $url
    );
    $tree->add_node($node);
}

/**
 * Coding-profile chrome.
 *
 * @param moodle_page $page
 */
function local_nexprofile_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexprofile/fonts.css');
    $page->add_body_class('path-local-nexprofile');
    $page->add_body_class('nxp-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}
