<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexresume.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Full-bleed page chrome.
 *
 * @param moodle_page $page
 */
function local_nexresume_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexresume/fonts.css');
    $page->add_body_class('path-local-nexresume');
    $page->add_body_class('nxf-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}

/**
 * @param int $userid
 * @return array
 */
function local_nexresume_user_summary(int $userid): array {
    require_once(__DIR__ . '/classes/local/aggregator.php');
    require_once(__DIR__ . '/classes/local/document.php');

    $doc = \local_nexresume\local\document::get_merged($userid);
    $sources = $doc['sources'] ?? [];
    $meta = $doc['meta'] ?? [];

    return [
        'completeness' => (int) ($meta['completeness'] ?? 0),
        'projectcount' => (int) ($sources['projectcount'] ?? 0),
        'platformcount' => (int) ($sources['platformcount'] ?? 0),
        'skillcount' => (int) ($sources['skillcount'] ?? 0),
        'sections' => count(array_filter($doc['sections'] ?? [])),
    ];
}

/**
 * Header + stats strip context.
 *
 * @param int $userid
 * @return array
 */
function local_nexresume_header_context(int $userid): array {
    global $USER;

    $s = local_nexresume_user_summary($userid);

    return [
        'title' => get_string('pluginname', 'local_nexresume'),
        'eyebrow' => get_string('resumeeyebrow', 'local_nexresume'),
        'subtitle' => get_string('resumebuilder_desc', 'local_nexresume'),
        'displayname' => fullname($USER),
        'contentpct' => $s['completeness'],
        'projectcount' => $s['projectcount'],
        'contentitems' => [
            [
                'key' => 'completeness',
                'label' => get_string('completeness', 'local_nexresume'),
                'display' => $s['completeness'] . '%',
            ],
            [
                'key' => 'projects',
                'label' => get_string('projects_count', 'local_nexresume'),
                'display' => (string) $s['projectcount'],
            ],
            [
                'key' => 'platforms',
                'label' => get_string('platforms_count', 'local_nexresume'),
                'display' => (string) $s['platformcount'],
            ],
            [
                'key' => 'skills',
                'label' => get_string('skills_count', 'local_nexresume'),
                'display' => (string) $s['skillcount'],
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'completeness',
                'value' => $s['completeness'] . '%',
                'label' => get_string('completeness', 'local_nexresume'),
            ],
            [
                'key' => 'projects',
                'value' => $s['projectcount'],
                'label' => get_string('projects_count', 'local_nexresume'),
            ],
            [
                'key' => 'platforms',
                'value' => $s['platformcount'],
                'label' => get_string('platforms_count', 'local_nexresume'),
            ],
            [
                'key' => 'skills',
                'value' => $s['skillcount'],
                'label' => get_string('skills_count', 'local_nexresume'),
            ],
        ],
    ];
}

/**
 * @param global_navigation $nav
 */
function local_nexresume_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (get_config('local_nexresume', 'enablemenu') === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexresume:view', $context)) {
        return;
    }

    $url = '/local/nexresume/index.php';
    $label = get_string('pluginname', 'local_nexresume');

    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        $haystack = (string) ($CFG->custommenuitems ?? '');
        if (strpos($haystack, $url) === false) {
            $CFG->custommenuitems = trim($haystack . "\n" . $label . '|' . $url);
        }
        return;
    }

    $icon = new pix_icon('i/user', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexresume',
        'nexresume',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * @param global_navigation $nav
 */
function local_nexresume_extends_navigation(global_navigation $nav): void {
    local_nexresume_extend_navigation($nav);
}
