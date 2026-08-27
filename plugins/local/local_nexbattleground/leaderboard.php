<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexBattleGround leaderboard.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexbattleground:view', $context);

$PAGE->set_url(new moodle_url('/local/nexbattleground/leaderboard.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('leaderboard', 'local_nexbattleground'));
local_nexbattleground_setup_page($PAGE);
$PAGE->requires->css('/local/nexbattleground/styles.css');
$PAGE->requires->js_call_amd('local_nexbattleground/leaderboard', 'init', [[]]);

$header = local_nexbattleground_header_context((int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexbattleground/leaderboard', array_merge($header, [
    'lobbyurl' => (new moodle_url('/local/nexbattleground/index.php'))->out(false),
    'leaderboardurl' => (new moodle_url('/local/nexbattleground/leaderboard.php'))->out(false),
    'navlobby' => false,
    'navleaderboard' => true,
]));
echo $OUTPUT->footer();
