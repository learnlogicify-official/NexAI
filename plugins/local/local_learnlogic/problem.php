<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexPractice problem IDE — full-screen split pane with CodeRunner Ace.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:view', $context);

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/learnlogic/problem.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_learnlogic'));
$acebase = local_learnlogic_setup_ide_page($PAGE);
$PAGE->requires->css('/local/learnlogic/styles.css');

$PAGE->requires->js_call_amd('local_learnlogic/problem', 'init', [[
    'problemId' => $id,
    'listUrl' => (new moodle_url('/local/learnlogic/index.php'))->out(false),
    'canAttempt' => has_capability('local/learnlogic:attempt', $context),
    'aceBaseUrl' => $acebase,
    'strings' => [
        'run' => get_string('run', 'local_learnlogic'),
        'runcode' => get_string('runcode', 'local_learnlogic'),
        'submit' => get_string('submit', 'local_learnlogic'),
        'running' => get_string('running', 'local_learnlogic'),
        'submitting' => get_string('submitting', 'local_learnlogic'),
        'accepted' => get_string('accepted', 'local_learnlogic'),
        'wronganswer' => get_string('wronganswer', 'local_learnlogic'),
        'nooutput' => get_string('nooutput', 'local_learnlogic'),
        'input' => get_string('input', 'local_learnlogic'),
        'expected' => get_string('expected', 'local_learnlogic'),
        'output' => get_string('output', 'local_learnlogic'),
        'youroutput' => get_string('youroutput', 'local_learnlogic'),
        'xpearned' => get_string('xpearned', 'local_learnlogic', '{xp}'),
        'sampletests' => get_string('sampletests', 'local_learnlogic'),
        'hiddentests' => get_string('hiddentests', 'local_learnlogic'),
        'customtest' => get_string('customtest', 'local_learnlogic'),
        'explanation' => get_string('explanation', 'local_learnlogic'),
        'completions' => get_string('completions', 'local_learnlogic'),
        'successrate' => get_string('successrate', 'local_learnlogic'),
        'light' => get_string('editorlight', 'local_learnlogic'),
        'dark' => get_string('editordark', 'local_learnlogic'),
        'fspage' => get_string('fs_page', 'local_learnlogic'),
        'fsdesc' => get_string('fs_desc', 'local_learnlogic'),
        'fsright' => get_string('fs_right', 'local_learnlogic'),
        'fseditor' => get_string('fs_editor', 'local_learnlogic'),
        'fsexit' => get_string('fs_exit', 'local_learnlogic'),
        'nosamples' => get_string('nosamples', 'local_learnlogic'),
        'nosolution' => get_string('nosolution', 'local_learnlogic'),
        'nohidden' => get_string('nohidden', 'local_learnlogic'),
        'aceunavailable' => get_string('aceunavailable', 'local_learnlogic'),
        'nexeditor' => get_string('nexeditor', 'local_learnlogic'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnlogic/problem', [
    'problemid' => $id,
    'listurl' => (new moodle_url('/local/learnlogic/index.php'))->out(false),
]);
echo $OUTPUT->footer();
