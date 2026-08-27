<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexInterview hub.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:view', $context);

$PAGE->set_url(new moodle_url('/local/nexinterview/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$header = local_nexinterview_header_context((int) $USER->id);
$history = local_nexinterview_history_context((int) $USER->id, 6);
$tracks = local_nexinterview_tracks();
foreach ($tracks as &$t) {
    $t['url'] = (new moodle_url('/local/nexinterview/start.php', ['track' => $t['id']]))->out(false);
}
unset($t);

$custom = \local_nexinterview\local\interviewers::hub_cards();
$resumetracks = [];
$codingtracks = [];
foreach ($tracks as $t) {
    if (!empty($t['resumeonly'])) {
        $resumetracks[] = $t;
    } else {
        $codingtracks[] = $t;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/hub', array_merge($header, $history, [
    'tracks' => $codingtracks,
    'hasresume' => !empty($resumetracks),
    'resumetracks' => $resumetracks,
    'resumetitle' => get_string('resumetracks', 'local_nexinterview'),
    'resumesubhub' => get_string('resumetracks_sub', 'local_nexinterview'),
    'hascustom' => !empty($custom),
    'customtracks' => $custom,
    'customtitle' => get_string('custominterviewers', 'local_nexinterview'),
    'customsub' => get_string('custominterviewers_sub', 'local_nexinterview'),
    'defaulttitle' => get_string('defaulttracks', 'local_nexinterview'),
    'defaultsub' => get_string('defaulttracks_sub', 'local_nexinterview'),
]));
echo $OUTPUT->footer();
