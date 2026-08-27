<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexcomm.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add NexComm to the custom menu.
 *
 * @param global_navigation $nav
 */
function local_nexcomm_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexcomm', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexcomm:view', $context)) {
        return;
    }

    $url = '/local/nexcomm/index.php';
    $label = get_string('pluginname', 'local_nexcomm');

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
    $node = $nav->add($label, new moodle_url($url), navigation_node::TYPE_CUSTOM, 'nexcomm', 'nexcomm', $icon);
    $node->showinflatnavigation = true;
}

/**
 * @param global_navigation $nav
 */
function local_nexcomm_extends_navigation(global_navigation $nav): void {
    local_nexcomm_extend_navigation($nav);
}

/**
 * @param moodle_page $page
 */
function local_nexcomm_setup_page(moodle_page $page): void {
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexcomm/fonts.css');
    $page->add_body_class('path-local-nexcomm');
    $page->add_body_class('nc-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
}

/**
 * Header + stats strip context.
 *
 * @param int $userid
 * @return array
 */
function local_nexcomm_header_context(int $userid): array {
    global $USER;

    $stats = \local_nexcomm\local\gamification::user_stats($userid);
    $targets = \local_nexcomm\local\targets::summary($userid);
    $funnel = \local_nexcomm\local\catalog::funnel_counts($userid);
    $readiness = \local_nexcomm\local\catalog::readiness_pct($userid);

    return [
        'title' => get_string('pluginname', 'local_nexcomm'),
        'eyebrow' => get_string('commeyebrow', 'local_nexcomm'),
        'subtitle' => get_string('commsubtitle', 'local_nexcomm'),
        'displayname' => fullname($USER),
        'contentpct' => $readiness,
        'contentitems' => [
            [
                'key' => 'readiness',
                'label' => get_string('readiness', 'local_nexcomm'),
                'display' => $readiness . '%',
            ],
            [
                'key' => 'streak',
                'label' => get_string('streak', 'local_nexcomm'),
                'display' => (string) (int) ($stats['streak'] ?? 0),
            ],
            [
                'key' => 'xp',
                'label' => get_string('xp', 'local_nexcomm'),
                'display' => (string) (int) ($stats['xp'] ?? 0),
            ],
            [
                'key' => 'rank',
                'label' => get_string('rank', 'local_nexcomm'),
                'display' => !empty($stats['xp']) ? (string) (int) $stats['rank'] : 'N/A',
            ],
        ],
        'hasstats' => true,
        'stats' => [
            ['key' => 'completed', 'value' => $funnel['completed'], 'label' => get_string('statcompleted', 'local_nexcomm')],
            ['key' => 'inprogress', 'value' => $funnel['inprogress'], 'label' => get_string('statinprogress', 'local_nexcomm')],
            ['key' => 'notstarted', 'value' => $funnel['notstarted'], 'label' => get_string('statnotstarted', 'local_nexcomm')],
            ['key' => 'total', 'value' => $funnel['total'], 'label' => get_string('stattotal', 'local_nexcomm')],
        ],
        'targets' => $targets,
        'dailydone' => $targets['dailyDone'],
        'dailygoal' => $targets['dailyGoal'],
        'dailypct' => $targets['dailyPct'],
        'dailycomplete' => $targets['dailyComplete'],
        'weeklydone' => $targets['weeklyDone'],
        'weeklygoal' => $targets['weeklyGoal'],
        'weeklypct' => $targets['weeklyPct'],
        'weeklycomplete' => $targets['weeklyComplete'],
    ];
}

/**
 * File serving for speaking recordings.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_nexcomm_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    global $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    require_login();
    if ($filearea !== 'speech') {
        return false;
    }
    require_capability('local/nexcomm:view', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_nexcomm', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Own recordings, or managers.
    $attempt = $GLOBALS['DB']->get_record('local_nexcomm_attempt', ['id' => $itemid]);
    if ($attempt && (int) $attempt->userid !== (int) $USER->id
            && !has_capability('local/nexcomm:manage', $context)) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
