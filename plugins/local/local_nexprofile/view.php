<?php
// This file is part of Moodle - http://moodle.org/
/**
 * LeetCode-style coding profile.
 *
 * @package   local_nexprofile
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$id = optional_param('id', 0, PARAM_INT);
if ($id < 1) {
    $id = (int) $USER->id;
}

$user = core_user::get_user($id, '*', MUST_EXIST);
if ($user->deleted) {
    throw new moodle_exception('userdeleted');
}
if (!user_can_view_profile($user)) {
    throw new moodle_exception('cannotviewprofile');
}

$syscontext = context_system::instance();
require_capability('local/nexprofile:view', $syscontext);

$usercontext = context_user::instance($user->id);
$PAGE->set_context($usercontext);
$PAGE->set_url(new moodle_url('/local/nexprofile/view.php', ['id' => $user->id]));
$PAGE->set_title(fullname($user) . ' · ' . get_string('pluginname', 'local_nexprofile'));
local_nexprofile_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexprofile/styles.css', [
    'v' => (string) get_config('local_nexprofile', 'version'),
]));
$PAGE->requires->js_call_amd('local_nexprofile/profile', 'init');

$data = \local_nexprofile\local\profile::context($user, $PAGE);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexprofile/profile', $data);
echo $OUTPUT->footer();
