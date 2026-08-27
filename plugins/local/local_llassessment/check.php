<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Diagnostic page — open as admin to verify install path and detection.
 *
 * @package    local_llassessment
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/llassessment/check.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LL Assessment Arena — check');
$PAGE->set_heading('LL Assessment Arena — install check');

$pluginman = core_plugin_manager::instance();
$plugin = $pluginman->get_plugin_info('local_llassessment');

$cssurl = new moodle_url('/local/llassessment/styles/arena.css');
$amdurl = new moodle_url('/local/llassessment/amd/build/arena.min.js');

echo $OUTPUT->header();
echo html_writer::tag('p', '<!-- ll-arena-boot-check -->');

echo html_writer::start_tag('ul');
echo html_writer::tag('li', 'Plugin directory: ' . s(__DIR__));
echo html_writer::tag('li', 'version.php release: ' .
    s($plugin ? ($plugin->release . ' (' . $plugin->versiondb . ')') : 'NOT FOUND'));
echo html_writer::tag('li', 'Enabled setting: ' . (local_llassessment_arena_enabled() ? 'yes' : 'no'));
echo html_writer::tag('li', 'Rootdir from plugin manager: ' .
    s($plugin ? $plugin->rootdir : 'n/a'));
echo html_writer::tag('li', 'lib.php exists: ' . (file_exists(__DIR__ . '/lib.php') ? 'yes' : 'no'));
echo html_writer::tag('li', 'db/events.php exists: ' . (file_exists(__DIR__ . '/db/events.php') ? 'yes' : 'no'));
echo html_writer::tag('li', 'db/hooks.php exists: ' . (file_exists(__DIR__ . '/db/hooks.php') ? 'yes' : 'no'));
echo html_writer::tag('li', 'amd/build/arena.min.js exists: ' .
    (file_exists(__DIR__ . '/amd/build/arena.min.js') ? 'yes' : 'no'));
echo html_writer::tag('li', 'CSS URL: ' . html_writer::link($cssurl, $cssurl->out(false)));
echo html_writer::tag('li', 'AMD URL: ' . html_writer::link($amdurl, $amdurl->out(false)));
echo html_writer::end_tag('ul');

echo $OUTPUT->notification(
    'After upgrading this plugin, purge caches, then open a quiz attempt and View Source for "ll-arena-boot". ' .
    'The browser error "Could not establish connection. Receiving end does not exist" is from a Chrome extension, not Moodle.',
    'info'
);

echo $OUTPUT->footer();
