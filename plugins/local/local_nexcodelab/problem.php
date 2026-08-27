<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCodeLab problem IDE — full-screen split pane with CodeRunner Ace.
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

$PAGE->set_url(new moodle_url('/local/nexcodelab/problem.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexcodelab'));
$acebase = local_nexcodelab_setup_ide_page($PAGE);
$PAGE->requires->css('/local/nexcodelab/styles.css');

$PAGE->requires->js_call_amd('local_nexcodelab/problem', 'init', [[
    'problemId' => $id,
    'listUrl' => (new moodle_url('/local/nexcodelab/index.php'))->out(false),
    'canAttempt' => has_capability('local/nexcodelab:attempt', $context),
    'aceBaseUrl' => $acebase,
    'strings' => [
        'run' => get_string('run', 'local_nexcodelab'),
        'runcode' => get_string('runcode', 'local_nexcodelab'),
        'submit' => get_string('submit', 'local_nexcodelab'),
        'running' => get_string('running', 'local_nexcodelab'),
        'submitting' => get_string('submitting', 'local_nexcodelab'),
        'accepted' => get_string('accepted', 'local_nexcodelab'),
        'wronganswer' => get_string('wronganswer', 'local_nexcodelab'),
        'nooutput' => get_string('nooutput', 'local_nexcodelab'),
        'input' => get_string('input', 'local_nexcodelab'),
        'expected' => get_string('expected', 'local_nexcodelab'),
        'output' => get_string('output', 'local_nexcodelab'),
        'youroutput' => get_string('youroutput', 'local_nexcodelab'),
        'xpearned' => get_string('xpearned', 'local_nexcodelab', '{xp}'),
        'sampletests' => get_string('sampletests', 'local_nexcodelab'),
        'hiddentests' => get_string('hiddentests', 'local_nexcodelab'),
        'customtest' => get_string('customtest', 'local_nexcodelab'),
        'explanation' => get_string('explanation', 'local_nexcodelab'),
        'completions' => get_string('completions', 'local_nexcodelab'),
        'successrate' => get_string('successrate', 'local_nexcodelab'),
        'light' => get_string('editorlight', 'local_nexcodelab'),
        'dark' => get_string('editordark', 'local_nexcodelab'),
        'fspage' => get_string('fs_page', 'local_nexcodelab'),
        'fsdesc' => get_string('fs_desc', 'local_nexcodelab'),
        'fsright' => get_string('fs_right', 'local_nexcodelab'),
        'fseditor' => get_string('fs_editor', 'local_nexcodelab'),
        'fsexit' => get_string('fs_exit', 'local_nexcodelab'),
        'nosamples' => get_string('nosamples', 'local_nexcodelab'),
        'nohidden' => get_string('nohidden', 'local_nexcodelab'),
        'aceunavailable' => get_string('aceunavailable', 'local_nexcodelab'),
        'nexeditor' => get_string('nexeditor', 'local_nexcodelab'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcodelab/problem', [
    'problemid' => $id,
    'listurl' => (new moodle_url('/local/nexcodelab/index.php'))->out(false),
]);
echo $OUTPUT->footer();
