<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Library functions for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Supported platforms (id => label string key suffix).
 *
 * @return string[]
 */
function local_nexportfolio_platforms(): array {
    return [
        'leetcode' => 'platform_leetcode',
        'codechef' => 'platform_codechef',
        'codeforces' => 'platform_codeforces',
        'geeksforgeeks' => 'platform_geeksforgeeks',
        'codingninjas' => 'platform_codingninjas',
    ];
}

/**
 * Portfolio projects for a user (GitHub repos, etc.).
 *
 * @param int $userid
 * @return array
 */
function local_nexportfolio_get_projects(int $userid): array {
    require_once(__DIR__ . '/classes/local/projects.php');
    $rows = \local_nexportfolio\local\projects::get_for_user($userid);
    $out = [];
    foreach ($rows as $row) {
        $out[] = \local_nexportfolio\local\projects::export_row($row);
    }
    return $out;
}

/**
 * @param int $userid
 * @return int
 */
function local_nexportfolio_project_count(int $userid): int {
    global $DB;
    return (int) $DB->count_records('local_nexportfolio_projects', ['userid' => $userid]);
}

/**
 * @param int $userid
 * @return bool
 */
function local_nexportfolio_has_github(int $userid): bool {
    global $DB;
    if ($DB->record_exists('local_nexportfolio_handles', ['userid' => $userid, 'platform' => 'github'])) {
        return true;
    }
    return $DB->record_exists('local_nexportfolio_github', ['userid' => $userid]);
}

/**
 * Add NexPortfolio to the top custom menu (Moodle 4+/5 / RemUI).
 *
 * @param global_navigation $nav
 */
function local_nexportfolio_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexportfolio', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexportfolio:view', $context)) {
        return;
    }

    $url = '/local/nexportfolio/index.php';
    $label = get_string('pluginname', 'local_nexportfolio');

    // Moodle 4+ / RemUI: inject into custom menu like NexPractice / NexCodeLab.
    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        $haystack = (string) ($CFG->custommenuitems ?? '');
        // Rewrite older menu labels to NexPortfolio.
        if (preg_match('/^(Coding Portfolio|Nex Portfolio)\|' . preg_quote($url, '/') . '$/m', $haystack)) {
            $CFG->custommenuitems = preg_replace(
                '/^(Coding Portfolio|Nex Portfolio)\|' . preg_quote($url, '/') . '$/m',
                $label . '|' . $url,
                $haystack
            );
            return;
        }
        if (stripos($haystack, $url) === false) {
            $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
            array_unshift($nodes, $label . '|' . $url);
            $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($line) {
                return trim((string) $line) !== '';
            }));
        }
        return;
    }

    // Older Moodle: flat navigation node.
    $icon = new pix_icon('i/stats', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexportfolio',
        'nexportfolio',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * Alias used by some Moodle versions.
 *
 * @param global_navigation $nav
 */
function local_nexportfolio_extends_navigation(global_navigation $nav): void {
    local_nexportfolio_extend_navigation($nav);
}

/**
 * Full-bleed page chrome (match NexPractice / NexCodeLab).
 *
 * @param moodle_page $page
 */
function local_nexportfolio_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexportfolio/fonts.css');
    $page->add_body_class('path-local-nexportfolio');
    $page->add_body_class('nxf-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}

/**
 * @param int $userid
 * @return array
 */
function local_nexportfolio_get_handles(int $userid): array {
    global $DB;
    $rows = $DB->get_records('local_nexportfolio_handles', ['userid' => $userid], 'platform ASC');
    $out = [];
    foreach ($rows as $row) {
        $out[$row->platform] = $row;
    }
    return $out;
}

/**
 * @param int $userid
 * @return array
 */
function local_nexportfolio_get_cached_data(int $userid): array {
    global $DB;
    $rows = $DB->get_records('local_nexportfolio_data', ['userid' => $userid], 'platform ASC');
    $out = [];
    foreach ($rows as $row) {
        $out[$row->platform] = $row;
    }
    return $out;
}

/**
 * Aggregated portfolio stats for header chrome.
 *
 * @param int $userid
 * @return array
 */
function local_nexportfolio_user_summary(int $userid): array {
    $handles = local_nexportfolio_get_handles($userid);
    $cached = local_nexportfolio_get_cached_data($userid);
    $totalplatforms = count(local_nexportfolio_platforms());
    $connected = 0;
    $solved = 0;
    $contests = 0;
    $streak = 0;
    $maxstreak = 0;

    foreach (local_nexportfolio_platforms() as $key => $unused) {
        if (!empty($handles[$key]->handle)) {
            $connected++;
        }
        $d = $cached[$key] ?? null;
        if (!$d) {
            continue;
        }
        $solved += (int) $d->totalsolved;
        $contests += (int) $d->contests;
        $platstreak = (int) $d->streak;
        $platmax = $platstreak;
        if (!empty($d->datajson)) {
            $profile = json_decode($d->datajson, true) ?: [];
            $stats = is_array($profile['stats'] ?? null) ? $profile['stats'] : [];
            $platstreak = (int) ($stats['currentStreak'] ?? $stats['streak'] ?? $platstreak);
            $platmax = (int) ($stats['maxStreak'] ?? $platmax);
            if ($platmax < $platstreak) {
                $platmax = $platstreak;
            }
        }
        $streak = max($streak, $platstreak);
        $maxstreak = max($maxstreak, $platmax);
    }

    return [
        'connected' => $connected,
        'totalplatforms' => $totalplatforms,
        'totalsolved' => $solved,
        'totalcontests' => $contests,
        'currentstreak' => $streak,
        'maxstreak' => $maxstreak,
        'projectcount' => local_nexportfolio_project_count($userid),
        'pct' => $totalplatforms > 0 ? (int) round(($connected / $totalplatforms) * 100) : 0,
    ];
}

/**
 * Header + stats-strip payload (NexPractice chrome).
 *
 * @param int $userid
 * @return array
 */
function local_nexportfolio_header_context(int $userid): array {
    global $USER;

    $s = local_nexportfolio_user_summary($userid);

    return [
        'title' => get_string('pluginname', 'local_nexportfolio'),
        'eyebrow' => get_string('portfolioeyebrow', 'local_nexportfolio'),
        'subtitle' => get_string('codingportfolio_desc', 'local_nexportfolio'),
        'displayname' => fullname($USER),
        'contentpct' => $s['pct'],
        'projectcount' => $s['projectcount'],
        'contentitems' => [
            [
                'key' => 'solved',
                'label' => get_string('totalsolved', 'local_nexportfolio'),
                'display' => (string) $s['totalsolved'],
            ],
            [
                'key' => 'streak',
                'label' => get_string('currentstreak', 'local_nexportfolio'),
                'display' => (string) $s['currentstreak'],
            ],
            [
                'key' => 'contests',
                'label' => get_string('contests', 'local_nexportfolio'),
                'display' => (string) $s['totalcontests'],
            ],
            [
                'key' => 'platforms',
                'label' => get_string('platforms', 'local_nexportfolio'),
                'display' => $s['connected'] . ' / ' . $s['totalplatforms'],
            ],
            [
                'key' => 'projects',
                'label' => get_string('projects', 'local_nexportfolio'),
                'display' => (string) $s['projectcount'],
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'solved',
                'value' => $s['totalsolved'],
                'label' => get_string('totalsolved', 'local_nexportfolio'),
            ],
            [
                'key' => 'contests',
                'value' => $s['totalcontests'],
                'label' => get_string('contests', 'local_nexportfolio'),
            ],
            [
                'key' => 'streak',
                'value' => $s['currentstreak'],
                'label' => get_string('currentstreak', 'local_nexportfolio'),
            ],
            [
                'key' => 'maxstreak',
                'value' => $s['maxstreak'],
                'label' => get_string('maxstreak', 'local_nexportfolio'),
            ],
        ],
    ];
}
