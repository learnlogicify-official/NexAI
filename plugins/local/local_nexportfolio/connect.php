<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Connect coding platform usernames.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_nexportfolio\local\github;

require_login();

$context = context_system::instance();
require_capability('local/nexportfolio:manageown', $context);

$PAGE->set_url(new moodle_url('/local/nexportfolio/connect.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('connect', 'local_nexportfolio'));
local_nexportfolio_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexportfolio/styles.css', [
    'v' => (string) get_config('local_nexportfolio', 'version'),
]));

$handles = local_nexportfolio_get_handles($USER->id);
$platformrows = [];
foreach (local_nexportfolio_platforms() as $key => $str) {
    $h = $handles[$key] ?? null;
    $platformrows[] = [
        'platform' => $key,
        'label' => get_string($str, 'local_nexportfolio'),
        'handle' => $h ? $h->handle : '',
        'cssclass' => 'np-platform--' . $key,
    ];
}

$dashboardurl = (new moodle_url('/local/nexportfolio/index.php'))->out(false);
$header = local_nexportfolio_header_context((int) $USER->id);

$profile = github::get_profile((int) $USER->id);
$ghlogin = $profile ? (string) $profile->github_login : '';
if ($ghlogin === '' && !empty($handles['github']->handle)) {
    $ghlogin = (string) $handles['github']->handle;
}

$PAGE->requires->js_call_amd('local_nexportfolio/connect', 'init', [[
    'dashboardUrl' => $dashboardurl,
    'strings' => [
        'savehandles' => get_string('savehandles', 'local_nexportfolio'),
        'saving' => get_string('saving', 'local_nexportfolio'),
        'handlesaved' => get_string('handlesaved', 'local_nexportfolio'),
        'refreshing' => get_string('refreshing', 'local_nexportfolio'),
        'fetchandreturn' => get_string('fetchandreturn', 'local_nexportfolio'),
        'githubimport' => get_string('githubimport', 'local_nexportfolio'),
        'githubimporting' => get_string('githubimporting', 'local_nexportfolio'),
        'githubdisconnect' => get_string('githubdisconnect', 'local_nexportfolio'),
        'githubusername' => get_string('githubusername', 'local_nexportfolio'),
        'githubusernamerequired' => get_string('githubusernamerequired', 'local_nexportfolio'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexportfolio/connect', array_merge($header, [
    'heading' => get_string('connecthandles', 'local_nexportfolio'),
    'help' => get_string('connecthandles_help', 'local_nexportfolio'),
    'platforms' => $platformrows,
    'usernamelabel' => get_string('username', 'local_nexportfolio'),
    'savelabel' => get_string('savehandles', 'local_nexportfolio'),
    'dashboardurl' => $dashboardurl,
    'githubenabled' => github::enabled(),
    'githublogin' => $ghlogin,
    'githubavatar' => $profile ? (string) ($profile->avatar_url ?? '') : '',
    'githubheading' => get_string('githubconnect', 'local_nexportfolio'),
    'githubhelp' => get_string('githubconnect_help', 'local_nexportfolio'),
    'githubusernamelabel' => get_string('githubusername', 'local_nexportfolio'),
    'githubuserhelp' => get_string('githubusername_help', 'local_nexportfolio'),
]));
echo $OUTPUT->footer();
