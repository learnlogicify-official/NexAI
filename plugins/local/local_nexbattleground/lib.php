<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexbattleground.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add NexBattleGround to the custom menu.
 *
 * @param global_navigation $nav
 */
function local_nexbattleground_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexbattleground', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexbattleground:view', $context)) {
        return;
    }

    $url = '/local/nexbattleground/index.php';
    $label = get_string('pluginname', 'local_nexbattleground');

    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        $haystack = (string) ($CFG->custommenuitems ?? '');
        if (stripos($haystack, $url) === false) {
            $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
            array_unshift($nodes, $label . '|' . $url);
            $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($line) {
                return trim((string) $line) !== '';
            }));
        }
        return;
    }

    $icon = new pix_icon('i/users', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexbattleground',
        'nexbattleground',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * @param global_navigation $nav
 */
function local_nexbattleground_extends_navigation(global_navigation $nav): void {
    local_nexbattleground_extend_navigation($nav);
}

/**
 * @param moodle_page $page
 */
function local_nexbattleground_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexbattleground/fonts.css');
    $page->add_body_class('path-local-nexbattleground');
    $page->add_body_class('nbg-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}

/**
 * Battle arena chrome + Ace when available.
 *
 * @param moodle_page $page
 * @return string Ace base URL
 */
function local_nexbattleground_setup_battle_page(moodle_page $page): string {
    global $CFG;

    local_nexbattleground_setup_page($page);
    $page->add_body_class('nbg-battle');

    $acebase = '';
    $acedir = $CFG->dirroot . '/question/type/coderunner/ace';
    if (is_readable($acedir . '/ace.js')) {
        $jsrev = empty($CFG->jsrev) ? -1 : $CFG->jsrev;
        $acebase = $CFG->wwwroot . '/lib/javascript.php/' . $jsrev . '/question/type/coderunner/ace';
        $page->requires->js(new moodle_url('/question/type/coderunner/ace/ace.js'), true);
        if (is_readable($acedir . '/ext-language_tools.js')) {
            $page->requires->js(new moodle_url('/question/type/coderunner/ace/ext-language_tools.js'), true);
        }
    }
    return $acebase;
}

/**
 * Default battle length in seconds.
 *
 * @return int
 */
function local_nexbattleground_duration(): int {
    return \local_nexbattleground\local\matchmaker::duration();
}

/**
 * Header + stats strip context (matches NexPractice / NexCodeLab chrome).
 *
 * @param int $userid
 * @return array
 */
function local_nexbattleground_header_context(int $userid): array {
    global $DB, $USER;

    $wins = 0;
    $losses = 0;
    $ties = 0;
    $total = 0;

    if ($userid > 0 && $DB->get_manager()->table_exists('local_nexbattleground_player')) {
        $wins = (int) $DB->count_records('local_nexbattleground_player', [
            'userid' => $userid,
            'result' => 'win',
        ]);
        $losses = (int) $DB->count_records('local_nexbattleground_player', [
            'userid' => $userid,
            'result' => 'loss',
        ]);
        $ties = (int) $DB->count_records('local_nexbattleground_player', [
            'userid' => $userid,
            'result' => 'tie',
        ]);
        $total = (int) $DB->count_records('local_nexbattleground_player', ['userid' => $userid]);
    }

    $decided = $wins + $losses;
    $pct = $decided > 0 ? (int) round(($wins / $decided) * 100) : 0;

    $xp = 0;
    if (class_exists('\\local_learnlogic\\local\\gamification')) {
        $stats = \local_learnlogic\local\gamification::user_stats($userid);
        $xp = (int) ($stats['xp'] ?? 0);
    } else if ($DB->get_manager()->table_exists('local_learnlogic_userxp')) {
        $xp = (int) ($DB->get_field('local_learnlogic_userxp', 'xp', ['userid' => $userid]) ?: 0);
    }

    // Same ranking as the leaderboard (battle wins), not global LearnLogic XP rank.
    $battlerank = \local_nexbattleground\local\leaderboard::rank_for($userid);
    $rank = $battlerank > 0 ? (string) $battlerank : 'N/A';

    return [
        'title' => get_string('pluginname', 'local_nexbattleground'),
        'eyebrow' => get_string('battleeyebrow', 'local_nexbattleground'),
        'subtitle' => get_string('lobbysubtitle', 'local_nexbattleground'),
        'displayname' => fullname($USER),
        'contentpct' => $pct,
        'contentitems' => [
            [
                'key' => 'wins',
                'label' => get_string('wins', 'local_nexbattleground'),
                'display' => (string) $wins,
            ],
            [
                'key' => 'losses',
                'label' => get_string('losses', 'local_nexbattleground'),
                'display' => (string) $losses,
            ],
            [
                'key' => 'xp',
                'label' => get_string('xp', 'local_nexbattleground'),
                'display' => (string) $xp,
            ],
            [
                'key' => 'rank',
                'label' => get_string('rank', 'local_nexbattleground'),
                'display' => $rank,
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'wins',
                'value' => $wins,
                'label' => get_string('statwins', 'local_nexbattleground'),
            ],
            [
                'key' => 'losses',
                'value' => $losses,
                'label' => get_string('statlosses', 'local_nexbattleground'),
            ],
            [
                'key' => 'ties',
                'value' => $ties,
                'label' => get_string('statties', 'local_nexbattleground'),
            ],
            [
                'key' => 'total',
                'value' => $total,
                'label' => get_string('stattotal', 'local_nexbattleground'),
            ],
        ],
    ];
}
