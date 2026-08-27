<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Resume + device gate before live interview.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:attempt', $context);

$track = optional_param('track', 'sde_intern', PARAM_ALPHANUMEXT);
$interviewerid = optional_param('interviewerid', 0, PARAM_INT);

$custom = $interviewerid > 0 ? \local_nexinterview\local\interviewers::get($interviewerid) : null;
if ($custom && !(int) $custom->enabled) {
    $custom = null;
    $interviewerid = 0;
}

$tracks = local_nexinterview_tracks();
$selected = null;
foreach ($tracks as $t) {
    if ($t['id'] === $track) {
        $selected = $t;
        break;
    }
}
if ($custom) {
    $track = (string) $custom->roletrack;
    $selected = [
        'id' => $track,
        'title' => (string) $custom->name,
        'topics' => (string) $custom->topics,
    ];
} else if (!$selected) {
    $selected = $tracks[0];
    $track = $selected['id'];
}

$problemid = local_nexinterview_is_resume_track($track)
    ? 0
    : \local_nexinterview\local\problems::pick_for_track($track);

$PAGE->set_url(new moodle_url('/local/nexinterview/start.php', array_filter([
    'track' => $track,
    'interviewerid' => $interviewerid ?: null,
])));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$roomurl = (new moodle_url('/local/nexinterview/room.php'))->out(false);
$PAGE->requires->js_call_amd('local_nexinterview/start', 'init', [[
    'track' => $track,
    'topics' => $selected['topics'],
    'problemid' => $problemid,
    'interviewerid' => $interviewerid,
    'roomurl' => $roomurl,
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'minResumeChars' => local_nexinterview_is_resume_track($track) ? 120 : 40,
    'strings' => [
        'needresume' => local_nexinterview_is_resume_track($track)
            ? get_string('needresume_deep', 'local_nexinterview')
            : get_string('needresume', 'local_nexinterview'),
    ],
]]);

$ongoing = local_nexinterview_ongoing_context((int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/start', array_merge($ongoing, [
    'tracktitle' => $selected['title'],
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'resumesub' => local_nexinterview_is_resume_track($track)
        ? get_string('resumesub_deep', 'local_nexinterview')
        : get_string('resumesub', 'local_nexinterview'),
]));
echo $OUTPUT->footer();
