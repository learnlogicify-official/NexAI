<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexstack — intentionally no site-wide navigation/CSS hooks.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Persistently remove NexStack lines that older builds wrote into the custom menu.
 * Call from upgrade only (not every page request).
 */
function local_nexstack_purge_custom_menu_leftovers(): void {
    $raw = get_config('core', 'custommenuitems');
    if (!is_string($raw) || stripos($raw, '/local/nexstack') === false) {
        return;
    }
    $nodes = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    $kept = [];
    foreach ($nodes as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (stripos($trim, '/local/nexstack') !== false) {
            continue;
        }
        $kept[] = $line;
    }
    set_config('custommenuitems', implode("\n", $kept));
}

/**
 * @param moodle_page $page
 */
function local_nexstack_setup_page(moodle_page $page): void {
    $page->add_body_class('path-local-nexstack');
    $page->add_body_class('nxs-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}

/**
 * Header + stats strip for the catalog (NexCodeLab-style chrome).
 *
 * @param int $userid
 * @param array $missions Catalog rows from missions::catalog_for_user()
 * @return array
 */
function local_nexstack_header_context(int $userid, array $missions = []): array {
    global $USER;

    $total = count($missions);
    $completed = 0;
    $inprogress = 0;
    $notstarted = 0;
    $stepsdone = 0;
    $wc = 0;

    foreach ($missions as $m) {
        $status = (string) ($m['status'] ?? 'new');
        if ($status === 'completed') {
            $completed++;
        } else if ($status === 'inprogress' || $status === 'in_progress') {
            $inprogress++;
        } else {
            $notstarted++;
        }
        $stepsdone += (int) ($m['completedcount'] ?? 0);
        if (($m['runtime'] ?? '') === 'webcontainer') {
            $wc++;
        }
    }

    $pct = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

    return [
        'title' => get_string('pluginname', 'local_nexstack'),
        'eyebrow' => get_string('practiceeyebrow', 'local_nexstack'),
        'subtitle' => get_string('practicesubtitle', 'local_nexstack'),
        'displayname' => fullname($USER),
        'contentpct' => $pct,
        'contentitems' => [
            [
                'key' => 'solved',
                'label' => get_string('missionsdone', 'local_nexstack'),
                'display' => $completed . ' / ' . $total,
            ],
            [
                'key' => 'steps',
                'label' => get_string('stepscompleted', 'local_nexstack'),
                'display' => (string) $stepsdone,
            ],
            [
                'key' => 'runtime',
                'label' => get_string('wcmissions', 'local_nexstack'),
                'display' => (string) $wc,
            ],
            [
                'key' => 'open',
                'label' => get_string('statinprogress', 'local_nexstack'),
                'display' => (string) $inprogress,
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'completed',
                'value' => $completed,
                'label' => get_string('statcompleted', 'local_nexstack'),
            ],
            [
                'key' => 'inprogress',
                'value' => $inprogress,
                'label' => get_string('statinprogress', 'local_nexstack'),
            ],
            [
                'key' => 'notstarted',
                'value' => $notstarted,
                'label' => get_string('statnotstarted', 'local_nexstack'),
            ],
            [
                'key' => 'total',
                'value' => $total,
                'label' => get_string('stattotal', 'local_nexstack'),
            ],
        ],
    ];
}
