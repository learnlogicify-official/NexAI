<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexPractice submissions page.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:view', $context);

$PAGE->set_url(new moodle_url('/local/learnlogic/submissions.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('submissions', 'local_learnlogic'));
local_learnlogic_setup_page($PAGE);
$PAGE->requires->js_call_amd('local_learnlogic/submissions', 'init', [[]]);

$header = local_learnlogic_header_context((int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnlogic/submissions', array_merge($header, [
    'listurl' => (new moodle_url('/local/learnlogic/index.php'))->out(false),
    'leaderboardurl' => (new moodle_url('/local/learnlogic/leaderboard.php'))->out(false),
]));
echo $OUTPUT->footer();
