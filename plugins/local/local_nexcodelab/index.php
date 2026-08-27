<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCodeLab mission catalog (NexPractice-style UI).
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:view', $context);

$PAGE->set_url(new moodle_url('/local/nexcodelab/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexcodelab'));
local_nexcodelab_setup_page($PAGE);
$PAGE->requires->css('/local/nexcodelab/styles.css');

$canmanage = has_capability('local/nexcodelab:manageproblems', $context);
$header = local_nexcodelab_header_context((int) $USER->id);

$PAGE->requires->js_call_amd('local_nexcodelab/catalog', 'init', [[]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcodelab/mission_catalog', array_merge($header, [
    'canmanage' => $canmanage,
    'manageurl' => (new moodle_url('/local/nexcodelab/manage/index.php'))->out(false),
    'listurl' => (new moodle_url('/local/nexcodelab/index.php'))->out(false),
    'progressurl' => (new moodle_url('/local/nexcodelab/progress.php'))->out(false),
    'leaderboardurl' => (new moodle_url('/local/nexcodelab/leaderboard.php'))->out(false),
]));
echo $OUTPUT->footer();
