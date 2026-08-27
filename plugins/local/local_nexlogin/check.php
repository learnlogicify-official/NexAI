<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Diagnostics for local_nexlogin (admin only).
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/nexlogin/check.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('NexLogin check');

echo $OUTPUT->header();
echo $OUTPUT->heading('NexLogin diagnostics');

$plugin = core_plugin_manager::instance()->get_plugin_info('local_nexlogin');
$rows = [
    'Plugin installed' => $plugin ? 'yes (' . $plugin->versiondb . ')' : 'NO — reinstall nexlogin.zip',
    'Enabled setting' => local_nexlogin_enabled() ? 'yes' : 'no',
    'styles.css exists' => file_exists(__DIR__ . '/styles.css') ? 'yes' : 'no',
    'login-inline.js exists' => file_exists(__DIR__ . '/amd/src/login-inline.js') ? 'yes' : 'no',
    'login.min.js exists' => file_exists(__DIR__ . '/amd/build/login.min.js') ? 'yes' : 'no',
    'hero.jpg exists' => file_exists(__DIR__ . '/pix/hero.jpg') ? 'yes' : 'no',
    'hooks.php exists' => file_exists(__DIR__ . '/db/hooks.php') ? 'yes' : 'no',
    'CSS URL' => local_nexlogin_file_url('/local/nexlogin/styles.css')->out(false),
    'Logo URL' => local_nexlogin_resolve_logo_url() ?: '(none — using fallback mark)',
];

echo html_writer::start_tag('ul');
foreach ($rows as $k => $v) {
    echo html_writer::tag('li', s($k) . ': ' . s((string) $v));
}
echo html_writer::end_tag('ul');

echo html_writer::div(
    'Open ' . html_writer::link(new moodle_url('/login/index.php'), '/login/index.php') .
    ' in a private window after purging caches. View page source and search for NEXLOGIN_CFG — it must appear.'
);

echo $OUTPUT->footer();
