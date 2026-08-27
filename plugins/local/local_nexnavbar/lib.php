<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexnavbar — RemUI top bar → left sidebar.
 *
 * @package   local_nexnavbar
 * @copyright 2026 Nex Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject collapsible left sidebar on every logged-in page.
 */
function local_nexnavbar_before_footer(): void {
    global $PAGE, $USER;

    if (CLI_SCRIPT || AJAX_SCRIPT || during_initial_install()) {
        return;
    }
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Skip plain login / public landing chrome.
    $path = (string) ($PAGE->url ? $PAGE->url->get_path() : '');
    if (preg_match('#/(login|admin/index)\.php$#', $path)) {
        return;
    }

    $PAGE->requires->js_call_amd('local_nexnavbar/sidebar', 'init', [local_nexnavbar_js_config()]);
}

/**
 * Config payload for the sidebar AMD module.
 *
 * @return array
 */
function local_nexnavbar_js_config(): array {
    global $CFG, $USER, $PAGE;

    $pic = new user_picture($USER);
    $pic->size = 128;
    $avatar = $pic->get_url($PAGE)->out(false);

    $profileurl = (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(false);
    if (file_exists($CFG->dirroot . '/local/nexprofile/view.php')) {
        $profileurl = (new moodle_url('/local/nexprofile/view.php', ['id' => $USER->id]))->out(false);
    }

    $email = '';
    if (!empty($USER->email)) {
        $email = (string) $USER->email;
    }

    $collapsed = false;

    return [
        'user' => [
            'fullname' => fullname($USER),
            'email' => $email,
            'avatar' => $avatar,
            'profileurl' => $profileurl,
        ],
        'items' => local_nexnavbar_nav_items(),
        'messagesurl' => (new moodle_url('/message/index.php'))->out(false),
        'settingsurl' => (new moodle_url('/user/preferences.php'))->out(false),
        'logouturl' => (new moodle_url('/login/logout.php', [
            'sesskey' => sesskey(),
        ]))->out(false),
        'collapsed' => $collapsed,
        'strings' => [
            'toggle' => get_string('togglesidebar', 'local_nexnavbar'),
            'expand' => get_string('expandsidebar', 'local_nexnavbar'),
            'collapse' => get_string('collapsesidebar', 'local_nexnavbar'),
            'messages' => get_string('messages', 'local_nexnavbar'),
            'notifications' => get_string('notifications', 'local_nexnavbar'),
            'settings' => get_string('settings', 'local_nexnavbar'),
            'logout' => get_string('logout', 'local_nexnavbar'),
            'profile' => get_string('profile', 'local_nexnavbar'),
        ],
    ];
}

/**
 * Nex product suite links (only plugins that exist on disk).
 *
 * @return array
 */
function local_nexnavbar_nav_items(): array {
    global $CFG, $PAGE;

    $candidates = [
        [
            'key' => 'dashboard',
            'label' => get_string('nav_dashboard', 'local_nexnavbar'),
            'path' => '/local/nexdashboard/index.php',
            'match' => '/local/nexdashboard/',
            'icon' => 'dashboard',
        ],
        [
            'key' => 'course',
            'label' => get_string('nav_course', 'local_nexnavbar'),
            'path' => '/local/nexcourse/index.php',
            'match' => '/local/nexcourse/',
            'icon' => 'course',
        ],
        [
            'key' => 'practice',
            'label' => get_string('nav_practice', 'local_nexnavbar'),
            'path' => '/local/learnlogic/index.php',
            'match' => '/local/learnlogic/',
            'icon' => 'practice',
        ],
        [
            'key' => 'codelab',
            'label' => get_string('nav_codelab', 'local_nexnavbar'),
            'path' => '/local/nexcodelab/index.php',
            'match' => '/local/nexcodelab/',
            'icon' => 'codelab',
        ],
        [
            'key' => 'battleground',
            'label' => get_string('nav_battleground', 'local_nexnavbar'),
            'path' => '/local/nexbattleground/index.php',
            'match' => '/local/nexbattleground/',
            'icon' => 'battle',
        ],
        [
            'key' => 'interview',
            'label' => get_string('nav_interview', 'local_nexnavbar'),
            'path' => '/local/nexinterview/index.php',
            'match' => '/local/nexinterview/',
            'icon' => 'interview',
        ],
        [
            'key' => 'reports',
            'label' => get_string('nav_reports', 'local_nexnavbar'),
            'path' => '/local/nexreports/index.php',
            'match' => '/local/nexreports/',
            'icon' => 'reports',
        ],
        [
            'key' => 'portfolio',
            'label' => get_string('nav_portfolio', 'local_nexnavbar'),
            'path' => '/local/nexportfolio/index.php',
            'match' => '/local/nexportfolio/',
            'icon' => 'portfolio',
        ],
        [
            'key' => 'profile',
            'label' => get_string('nav_profile', 'local_nexnavbar'),
            'path' => '/local/nexprofile/view.php',
            'match' => '/local/nexprofile/',
            'icon' => 'profile',
        ],
    ];

    $current = '';
    if ($PAGE->url) {
        $current = (string) $PAGE->url->out_as_local_url(false);
    }
    if ($current === '') {
        $current = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    $items = [];
    foreach ($candidates as $c) {
        $full = $CFG->dirroot . $c['path'];
        if (!file_exists($full)) {
            // Fallbacks for alternate entry points.
            if ($c['key'] === 'course' && file_exists($CFG->dirroot . '/my/courses.php')) {
                $c['path'] = '/my/courses.php';
                $c['match'] = '/my/courses';
            } else if ($c['key'] === 'profile' && file_exists($CFG->dirroot . '/user/profile.php')) {
                $c['path'] = '/user/profile.php';
                $c['match'] = '/user/profile';
            } else {
                continue;
            }
        }
        $url = (new moodle_url($c['path']))->out(false);
        $active = ($c['match'] !== '' && strpos($current, $c['match']) !== false);
        $items[] = [
            'key' => $c['key'],
            'label' => $c['label'],
            'url' => $url,
            'icon' => $c['icon'],
            'active' => $active,
        ];
    }
    return $items;
}
