<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_learnlogic.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add NexPractice to the top custom menu (Moodle 4+/5 / RemUI).
 *
 * @param global_navigation $nav
 */
function local_learnlogic_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_learnlogic', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/learnlogic:view', $context)) {
        return;
    }

    $url = '/local/learnlogic/index.php';
    $label = get_string('pluginname', 'local_learnlogic');

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

    $icon = new pix_icon('i/edit', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'learnlogic',
        'learnlogic',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_learnlogic_extends_navigation(global_navigation $nav): void {
    local_learnlogic_extend_navigation($nav);
}

/**
 * Supported language keys for CodeRunner prototypes.
 *
 * @return string[]
 */
function local_learnlogic_languages(): array {
    return ['python3', 'java', 'cpp', 'c', 'javascript', 'php'];
}

/**
 * XP amount for a difficulty key.
 *
 * @param string $difficulty
 * @return int
 */
function local_learnlogic_xp_for_difficulty(string $difficulty): int {
    $map = [
        'easy' => (int) (get_config('local_learnlogic', 'xp_easy') ?: 25),
        'medium' => (int) (get_config('local_learnlogic', 'xp_medium') ?: 50),
        'hard' => (int) (get_config('local_learnlogic', 'xp_hard') ?: 100),
        'veryhard' => (int) (get_config('local_learnlogic', 'xp_veryhard') ?: 100),
    ];
    return $map[$difficulty] ?? 25;
}

/**
 * Apply full-bleed NexPractice page chrome (body class + empty heading).
 *
 * @param moodle_page $page
 */
function local_learnlogic_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/learnlogic/fonts.css');
    $page->requires->css(new moodle_url('/local/learnlogic/styles.css', ['rev' => 2026082223]));
    $page->add_body_class('path-local-learnlogic');
    $page->add_body_class('ll-fullwidth');
    $page->set_pagelayout('standard');
    // Avoid Moodle's duplicate H1; banner carries the title.
    $page->set_heading('');
}

/**
 * Shared chrome for manage / import / edit screens.
 *
 * @param moodle_page $page
 */
function local_learnlogic_setup_manage_page(moodle_page $page): void {
    $page->requires->css('/local/learnlogic/fonts.css');
    $page->requires->css(new moodle_url('/local/learnlogic/styles.css', ['rev' => 2026082223]));
    $page->add_body_class('path-local-learnlogic');
    $page->add_body_class('ll-manage-page');
    $page->requires->js_call_amd('local_learnlogic/manage', 'init');
}

/**
 * Load CodeRunner Ace on a page (manage edit code fields, IDE).
 *
 * @param moodle_page $page
 * @return string Ace base URL (empty if unavailable)
 */
function local_learnlogic_load_ace(moodle_page $page): string {
    global $CFG;

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
 * Extract HTML/text from a Moodle form editor element or plain string.
 *
 * @param mixed $value
 * @return string
 */
function local_learnlogic_editor_text($value): string {
    if (is_array($value)) {
        return (string) ($value['text'] ?? '');
    }
    return (string) $value;
}

/**
 * Full-screen IDE chrome for the problem page (quiz-attempt style).
 * Hides Moodle navbar/drawers/footer; loads CodeRunner Ace when available.
 *
 * @param moodle_page $page
 * @return string Ace base URL (empty if CodeRunner Ace not present)
 */
function local_learnlogic_setup_ide_page(moodle_page $page): string {
    local_learnlogic_setup_page($page);
    $page->add_body_class('ll-ide-attempt');
    $page->add_body_class('ll-ide-boot');
    // Keep standard layout so AMD + Ace scripts still boot; CSS hides Moodle chrome.
    return local_learnlogic_load_ace($page);
}

/**
 * Header + stats-strip payload (NexCourse-style).
 *
 * @param int $userid
 * @return array
 */
function local_learnlogic_header_context(int $userid): array {
    global $DB, $USER;

    $stats = \local_learnlogic\local\gamification::user_stats($userid);
    $total = (int) $DB->count_records('local_learnlogic_problem', ['status' => 'ready']);
    $solved = (int) ($stats['solved'] ?? 0);
    $pct = $total > 0 ? (int) round(($solved / $total) * 100) : 0;

    // Funnel counts for current user (mirror course enrollment strip).
    // At least one ACCEPTED submission ⇒ completed (even if later attempts failed).
    $accepted = [];
    $attempted = [];
    if ($userid > 0 && $total > 0) {
        $attemptedids = $DB->get_fieldset_sql(
            "SELECT DISTINCT problemid
               FROM {local_learnlogic_submission}
              WHERE userid = ?",
            [$userid]
        );
        $acceptedids = $DB->get_fieldset_sql(
            "SELECT DISTINCT problemid
               FROM {local_learnlogic_submission}
              WHERE userid = ? AND status = ?",
            [$userid, 'ACCEPTED']
        );
        foreach ($attemptedids as $pid) {
            $attempted[(int) $pid] = true;
        }
        foreach ($acceptedids as $pid) {
            $accepted[(int) $pid] = true;
        }
    }
    $completed = count($accepted);
    $inprogress = 0;
    $notstarted = 0;
    $battledcount = 0;
    $battledmap = \local_learnlogic\local\battle_progress::won_map($userid);
    $ready = $DB->get_records('local_learnlogic_problem', ['status' => 'ready'], '', 'id');
    foreach ($ready as $p) {
        $pid = (int) $p->id;
        if (!empty($accepted[$pid])) {
            continue;
        }
        if (!empty($attempted[$pid])) {
            $inprogress++;
        } else if (!empty($battledmap[$pid])) {
            $battledcount++;
        } else {
            $notstarted++;
        }
    }

    $rank = !empty($stats['xp']) ? (string) (int) $stats['rank'] : 'N/A';
    $displayname = fullname($USER);

    return [
        'title' => get_string('pluginname', 'local_learnlogic'),
        'eyebrow' => get_string('practiceeyebrow', 'local_learnlogic'),
        'subtitle' => get_string('practicesubtitle', 'local_learnlogic'),
        'displayname' => $displayname,
        'contentpct' => $pct,
        'contentitems' => [
            [
                'key' => 'solved',
                'label' => get_string('solved', 'local_learnlogic'),
                'display' => $solved . ' / ' . $total,
            ],
            [
                'key' => 'streak',
                'label' => get_string('streak', 'local_learnlogic'),
                'display' => (string) (int) ($stats['streak'] ?? 0),
            ],
            [
                'key' => 'xp',
                'label' => get_string('xp', 'local_learnlogic'),
                'display' => (string) (int) ($stats['xp'] ?? 0),
            ],
            [
                'key' => 'rank',
                'label' => get_string('rank', 'local_learnlogic'),
                'display' => $rank,
            ],
        ],
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'completed',
                'value' => $completed,
                'label' => get_string('statcompleted', 'local_learnlogic'),
            ],
            [
                'key' => 'inprogress',
                'value' => $inprogress,
                'label' => get_string('statinprogress', 'local_learnlogic'),
            ],
            [
                'key' => 'battled',
                'value' => $battledcount,
                'label' => get_string('statbattled', 'local_learnlogic'),
            ],
            [
                'key' => 'notstarted',
                'value' => $notstarted,
                'label' => get_string('statnotstarted', 'local_learnlogic'),
            ],
            [
                'key' => 'total',
                'value' => $total,
                'label' => get_string('stattotal', 'local_learnlogic'),
            ],
        ],
        'userstats' => $stats + ['total' => $total],
    ];
}
