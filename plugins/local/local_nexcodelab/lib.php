<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexcodelab.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add NexCodeLab to the top custom menu (Moodle 4+/5 / RemUI).
 *
 * @param global_navigation $nav
 */
function local_nexcodelab_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexcodelab', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexcodelab:view', $context)) {
        return;
    }

    $url = '/local/nexcodelab/index.php';
    $label = get_string('menuname', 'local_nexcodelab');

    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        $haystack = (string) ($CFG->custommenuitems ?? '');
        // Prefer NexCodeLab label; rewrite older "CodeLab|…" entries if present.
        if (preg_match('/^CodeLab\|' . preg_quote($url, '/') . '$/m', $haystack)) {
            $CFG->custommenuitems = preg_replace(
                '/^CodeLab\|' . preg_quote($url, '/') . '$/m',
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

    $icon = new pix_icon('i/edit', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexcodelab',
        'nexcodelab',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_nexcodelab_extends_navigation(global_navigation $nav): void {
    local_nexcodelab_extend_navigation($nav);
}

/**
 * Supported language keys for CodeRunner prototypes (DS MVP = Python only).
 *
 * @return string[]
 */
function local_nexcodelab_languages(): array {
    return ['python3'];
}

/**
 * Catalog tracks for DS/ML challenges.
 *
 * @return string[]
 */
function local_nexcodelab_tracks(): array {
    return ['wrangling', 'eda', 'ml', 'nlp'];
}

/**
 * XP amount for a difficulty key.
 *
 * @param string $difficulty
 * @return int
 */
function local_nexcodelab_xp_for_difficulty(string $difficulty): int {
    $map = [
        'easy' => (int) (get_config('local_nexcodelab', 'xp_easy') ?: 25),
        'medium' => (int) (get_config('local_nexcodelab', 'xp_medium') ?: 50),
        'hard' => (int) (get_config('local_nexcodelab', 'xp_hard') ?: 100),
        'veryhard' => (int) (get_config('local_nexcodelab', 'xp_veryhard') ?: 100),
    ];
    return $map[$difficulty] ?? 25;
}

/**
 * Apply full-bleed NexCodeLab page chrome (body class + empty heading).
 *
 * @param moodle_page $page
 */
function local_nexcodelab_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexcodelab/fonts.css');
    $page->add_body_class('path-local-nexcodelab');
    $page->add_body_class('ncl-fullwidth');
    $page->set_pagelayout('standard');
    // Avoid Moodle's duplicate H1; banner carries the title.
    $page->set_heading('');
}

/**
 * Funcl-screen IDE chrome for the problem page (quiz-attempt style).
 * Hides Moodle navbar/drawers/footer; loads CodeRunner Ace when available.
 *
 * @param moodle_page $page
 * @return string Ace base URL (empty if CodeRunner Ace not present)
 */
function local_nexcodelab_setup_ide_page(moodle_page $page): string {
    global $CFG;

    local_nexcodelab_setup_page($page);
    $page->add_body_class('ncl-ide-attempt');
    $page->add_body_class('ncl-ide-boot');
    // Keep standard layout so AMD + Ace scripts still boot; CSS hides Moodle chrome.

    $acebase = '';
    $acedir = $CFG->dirroot . '/question/type/coderunner/ace';
    if (is_readable($acedir . '/ace.js')) {
        $jsrev = empty($CFG->jsrev) ? -1 : $CFG->jsrev;
        $acebase = $CFG->wwwroot . '/lib/javascript.php/' . $jsrev . '/question/type/coderunner/ace';
        $page->requires->js(new moodle_url('/question/type/coderunner/ace/ace.js'), true);
        if (is_readable($acedir . '/ext-language_tools.js')) {
            $page->requires->js(new moodle_url('/question/type/coderunner/ace/ext-language_tools.js'), true);
        }
        if (is_readable($acedir . '/ext-modelist.js')) {
            $page->requires->js(new moodle_url('/question/type/coderunner/ace/ext-modelist.js'), true);
        }
    }

    return $acebase;
}

/**
 * Header + stats-strip payload (NexPractice chrome, mission progress).
 *
 * @param int $userid
 * @return array
 */
function local_nexcodelab_header_context(int $userid): array {
    global $DB, $USER;

    $stats = \local_nexcodelab\local\gamification::user_stats($userid);
    $total = 0;
    if ($DB->get_manager()->table_exists('local_nexcodelab_mission')) {
        $total = (int) $DB->count_records('local_nexcodelab_mission', ['status' => 'ready']);
    }
    $solved = (int) ($stats['solved'] ?? 0);
    $pct = $total > 0 ? (int) round(($solved / $total) * 100) : 0;

    $completed = 0;
    $inprogress = 0;
    $notstarted = 0;
    if ($total > 0 && $DB->get_manager()->table_exists('local_nexcodelab_mission_progress')) {
        $ready = $DB->get_records_select(
            'local_nexcodelab_mission',
            "status IN ('ready', 'published')",
            null,
            '',
            'id'
        );
        $progress = $DB->get_records('local_nexcodelab_mission_progress', ['userid' => $userid], '', 'missionid, completed, currentstep');
        foreach ($ready as $m) {
            $mid = (int) $m->id;
            $row = $progress[$mid] ?? null;
            if ($row && (int) $row->completed === 1) {
                $completed++;
            } else if ($row && (int) $row->currentstep > 0) {
                $inprogress++;
            } else {
                $notstarted++;
            }
        }
    } else {
        $notstarted = $total;
    }

    $rank = !empty($stats['xp']) ? (string) (int) $stats['rank'] : 'N/A';

    return [
        'title' => get_string('pluginname', 'local_nexcodelab'),
        'eyebrow' => get_string('practiceeyebrow', 'local_nexcodelab'),
        'subtitle' => get_string('practicesubtitle', 'local_nexcodelab'),
        'displayname' => fullname($USER),
        'contentpct' => $pct,
        'contentitems' => [
            [
                'key' => 'solved',
                'label' => get_string('missionsdone', 'local_nexcodelab'),
                'display' => $solved . ' / ' . $total,
            ],
            [
                'key' => 'streak',
                'label' => get_string('streak', 'local_nexcodelab'),
                'display' => (string) (int) ($stats['streak'] ?? 0),
            ],
            [
                'key' => 'xp',
                'label' => get_string('xp', 'local_nexcodelab'),
                'display' => (string) (int) ($stats['xp'] ?? 0),
            ],
            [
                'key' => 'rank',
                'label' => get_string('rank', 'local_nexcodelab'),
                'display' => $rank,
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'completed',
                'value' => $completed,
                'label' => get_string('statcompleted', 'local_nexcodelab'),
            ],
            [
                'key' => 'inprogress',
                'value' => $inprogress,
                'label' => get_string('statinprogress', 'local_nexcodelab'),
            ],
            [
                'key' => 'notstarted',
                'value' => $notstarted,
                'label' => get_string('statnotstarted', 'local_nexcodelab'),
            ],
            [
                'key' => 'total',
                'value' => $total,
                'label' => get_string('stattotal', 'local_nexcodelab'),
            ],
        ],
        'userstats' => $stats + ['total' => $total],
    ];
}
