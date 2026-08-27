<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcomm:manage', $context);

$PAGE->set_url(new moodle_url('/local/nexcomm/manage.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('manage', 'local_nexcomm'));
local_nexcomm_setup_page($PAGE);
$PAGE->requires->css('/local/nexcomm/styles.css');
$PAGE->requires->js_call_amd('local_nexcomm/manage', 'init', [[]]);

$header = local_nexcomm_header_context((int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcomm/manage', array_merge($header, [
    'homeurl' => (new moodle_url('/local/nexcomm/index.php'))->out(false),
    'catalogurl' => (new moodle_url('/local/nexcomm/catalog.php'))->out(false),
    'videosurl' => (new moodle_url('/local/nexcomm/videos.php'))->out(false),
    'leaderboardurl' => (new moodle_url('/local/nexcomm/leaderboard.php'))->out(false),
    'manageurl' => (new moodle_url('/local/nexcomm/manage.php'))->out(false),
    'navhome' => false,
    'navcatalog' => false,
    'navvideos' => false,
    'navleaderboard' => false,
    'navmanage' => true,
    'canmanage' => true,
]));
echo $OUTPUT->footer();
