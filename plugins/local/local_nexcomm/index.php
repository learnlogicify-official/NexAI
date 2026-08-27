<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcomm:view', $context);

$PAGE->set_url(new moodle_url('/local/nexcomm/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexcomm'));
local_nexcomm_setup_page($PAGE);
$PAGE->requires->css('/local/nexcomm/styles.css');

$canmanage = has_capability('local/nexcomm:manage', $context);
$header = local_nexcomm_header_context((int) $USER->id);
$catalogurl = (new moodle_url('/local/nexcomm/catalog.php'))->out(false);
$videosurl = (new moodle_url('/local/nexcomm/videos.php'))->out(false);
$goals = \local_nexcomm\local\lesson::goals_summary((int) $USER->id);

$data = array_merge($header, $goals, [
    'homeurl' => (new moodle_url('/local/nexcomm/index.php'))->out(false),
    'catalogurl' => $catalogurl,
    'videosurl' => $videosurl,
    'leaderboardurl' => (new moodle_url('/local/nexcomm/leaderboard.php'))->out(false),
    'manageurl' => (new moodle_url('/local/nexcomm/manage.php'))->out(false),
    'navhome' => true,
    'navcatalog' => false,
    'navvideos' => false,
    'navleaderboard' => false,
    'navmanage' => false,
    'canmanage' => $canmanage,
    'skills' => [
        ['key' => 'reading', 'label' => get_string('reading', 'local_nexcomm'), 'hint' => 'Verbal + JD comprehension', 'url' => (new moodle_url('/local/nexcomm/catalog.php', ['skill' => 'reading']))->out(false)],
        ['key' => 'listening', 'label' => get_string('listening', 'local_nexcomm'), 'hint' => 'HR briefings & instructions', 'url' => (new moodle_url('/local/nexcomm/catalog.php', ['skill' => 'listening']))->out(false)],
        ['key' => 'speaking', 'label' => get_string('speaking', 'local_nexcomm'), 'hint' => 'Interview answers', 'url' => (new moodle_url('/local/nexcomm/catalog.php', ['skill' => 'speaking']))->out(false)],
        ['key' => 'writing', 'label' => get_string('writing', 'local_nexcomm'), 'hint' => 'Professional emails', 'url' => (new moodle_url('/local/nexcomm/catalog.php', ['skill' => 'writing']))->out(false)],
    ],
]);

$PAGE->requires->js_call_amd('local_nexcomm/home', 'init', [[]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcomm/home', $data);
echo $OUTPUT->footer();
