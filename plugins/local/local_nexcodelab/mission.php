<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCodeLab mission lab bench.
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

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nexcodelab/mission.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexcodelab'));
$acebase = local_nexcodelab_setup_ide_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexcodelab/styles.css', [
    'v' => (string) get_config('local_nexcodelab', 'version'),
]));

$PAGE->requires->js_call_amd('local_nexcodelab/mission', 'init', [[
    'missionId' => $id,
    'listUrl' => (new moodle_url('/local/nexcodelab/index.php'))->out(false),
    'canAttempt' => has_capability('local/nexcodelab:attempt', $context),
    'aceBaseUrl' => $acebase,
    'strings' => [
        'checkstep' => get_string('checkstep', 'local_nexcodelab'),
        'checking' => get_string('checking', 'local_nexcodelab'),
        'steppassed' => get_string('steppassed', 'local_nexcodelab'),
        'stepfailed' => get_string('stepfailed', 'local_nexcodelab'),
        'steplocked' => get_string('steplocked', 'local_nexcodelab'),
        'missioncomplete' => get_string('missioncomplete', 'local_nexcodelab'),
        'resetfiles' => get_string('resetfiles', 'local_nexcodelab'),
        'showhint' => get_string('showhint', 'local_nexcodelab'),
        'rawcsv' => get_string('rawcsv', 'local_nexcodelab'),
        'tablecsv' => get_string('tablecsv', 'local_nexcodelab'),
        'aceunavailable' => get_string('aceunavailable', 'local_nexcodelab'),
        'light' => get_string('editorlight', 'local_nexcodelab'),
        'dark' => get_string('editordark', 'local_nexcodelab'),
        'fileexplorer' => get_string('fileexplorer', 'local_nexcodelab'),
        'console' => get_string('console', 'local_nexcodelab'),
        'prevstep' => get_string('prevstep', 'local_nexcodelab'),
        'nextstep' => get_string('nextstep', 'local_nexcodelab'),
        'briefinedocs' => get_string('briefinedocs', 'local_nexcodelab'),
        'missions' => get_string('missions', 'local_nexcodelab'),
        'nomatch' => get_string('missionsnomatch', 'local_nexcodelab'),
        'xpearned' => get_string('xpearned', 'local_nexcodelab', '{xp}'),
        'accepted' => get_string('accepted', 'local_nexcodelab'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcodelab/mission_bench', [
    'missionid' => $id,
    'listurl' => (new moodle_url('/local/nexcodelab/index.php'))->out(false),
]);
echo $OUTPUT->footer();
