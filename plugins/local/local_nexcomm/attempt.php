<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcomm:view', $context);
require_capability('local/nexcomm:attempt', $context);

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nexcomm/attempt.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexcomm'));
local_nexcomm_setup_page($PAGE);
$PAGE->requires->css('/local/nexcomm/styles.css');

$draftitemid = file_get_unused_draft_itemid();

$PAGE->requires->js_call_amd('local_nexcomm/attempt', 'init', [[
    'activityId' => $id,
    'draftItemId' => $draftitemid,
    'catalogUrl' => (new moodle_url('/local/nexcomm/catalog.php'))->out(false),
    'sesskey' => sesskey(),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcomm/attempt', [
    'activityid' => $id,
    'catalogurl' => (new moodle_url('/local/nexcomm/catalog.php'))->out(false),
    'draftitemid' => $draftitemid,
]);
echo $OUTPUT->footer();
