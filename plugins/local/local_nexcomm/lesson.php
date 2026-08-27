<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcomm:view', $context);
require_capability('local/nexcomm:attempt', $context);

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nexcomm/lesson.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('videolab', 'local_nexcomm'));
local_nexcomm_setup_page($PAGE);
$PAGE->requires->css('/local/nexcomm/styles.css');
$PAGE->requires->js_call_amd('local_nexcomm/videolesson', 'init', [[
    'lessonId' => $id,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcomm/lesson', [
    'lessonid' => $id,
    'videosurl' => (new moodle_url('/local/nexcomm/videos.php'))->out(false),
]);
echo $OUTPUT->footer();
