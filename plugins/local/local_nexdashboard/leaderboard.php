<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Overall student leaderboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexdashboard:view', $context);

$PAGE->set_title(get_string('overallleaderboard', 'local_nexdashboard'));
local_nexdashboard_setup_leaderboard_page($PAGE);
$PAGE->requires->css('/local/nexdashboard/styles.css');
$PAGE->requires->js_call_amd('local_nexdashboard/leaderboard', 'init', [[]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexdashboard/leaderboard', []);
echo $OUTPUT->footer();
