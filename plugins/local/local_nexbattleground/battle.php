<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexBattleGround arena — NexPractice IDE + battle chrome.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexbattleground:view', $context);

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nexbattleground/battle.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexbattleground'));

// Reuse NexPractice full-screen IDE chrome + Ace when available.
$acebase = '';
$learllib = $CFG->dirroot . '/local/learnlogic/lib.php';
if (is_readable($learllib)) {
    require_once($learllib);
    if (function_exists('local_learnlogic_setup_ide_page')) {
        $acebase = local_learnlogic_setup_ide_page($PAGE);
    }
}
if ($acebase === '') {
    $acebase = local_nexbattleground_setup_battle_page($PAGE);
} else {
    $PAGE->add_body_class('path-local-nexbattleground');
    $PAGE->add_body_class('nbg-battle');
    $PAGE->add_body_class('ll-np-battle');
}
// Always mark battle arena pages for shell CSS, even if Ace setup path differed.
$PAGE->add_body_class('nbg-battle');
$PAGE->add_body_class('ll-np-battle');

$PAGE->requires->css('/local/learnlogic/styles.css');
$PAGE->requires->css('/local/nexbattleground/styles.css');

$lobbyurl = (new moodle_url('/local/nexbattleground/index.php'))->out(false);
$canbattle = has_capability('local/nexbattleground:battle', $context);

$PAGE->requires->js_call_amd('local_nexbattleground/battle', 'init', [[
    'battleId' => $id,
    'lobbyUrl' => $lobbyurl,
    'canBattle' => $canbattle,
    'aceBaseUrl' => $acebase,
    'strings' => [
        'forfeit' => get_string('forfeit', 'local_nexbattleground'),
        'forfeitconfirm' => get_string('forfeitconfirm', 'local_nexbattleground'),
        'youwin' => get_string('youwin', 'local_nexbattleground'),
        'youlose' => get_string('youlose', 'local_nexbattleground'),
        'itsatie' => get_string('itsatie', 'local_nexbattleground'),
        'battleover' => get_string('battleover', 'local_nexbattleground'),
        'timeleft' => get_string('timeleft', 'local_nexbattleground'),
        'backtolobby' => get_string('backtolobby', 'local_nexbattleground'),
        'waitingforopponent' => get_string('waitingforopponent', 'local_nexbattleground'),
        'waitingtitle' => get_string('waitingtitle', 'local_nexbattleground'),
        'sharecode' => get_string('sharecode', 'local_nexbattleground'),
        'you' => get_string('you', 'local_nexbattleground'),
        'opponent' => get_string('opponent', 'local_nexbattleground'),
        'opponentsolved' => get_string('opponentsolved', 'local_nexbattleground'),
        'timeouttie' => get_string('timeouttie', 'local_nexbattleground'),
        'victory' => get_string('victory', 'local_nexbattleground'),
        'xpearned' => get_string('xpearned', 'local_nexbattleground'),
        'returningtolobby' => get_string('returningtolobby', 'local_nexbattleground'),
        'difficulty' => get_string('difficulty', 'local_nexbattleground'),
        'anydifficulty' => get_string('anydifficulty', 'local_nexbattleground'),
        'easy' => get_string('easy', 'local_nexbattleground'),
        'medium' => get_string('medium', 'local_nexbattleground'),
        'hard' => get_string('hard', 'local_nexbattleground'),
        'veryhard' => get_string('veryhard', 'local_nexbattleground'),
    ],
    'practiceStrings' => [
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
        'sampletests' => get_string('sampletests', 'local_learnlogic'),
        'hiddentests' => get_string('hiddentests', 'local_learnlogic'),
        'customtest' => get_string('customtest', 'local_learnlogic'),
        'explanation' => get_string('explanation', 'local_learnlogic'),
        'light' => get_string('editorlight', 'local_learnlogic'),
        'dark' => get_string('editordark', 'local_learnlogic'),
        'fspage' => get_string('fs_page', 'local_learnlogic'),
        'fsdesc' => get_string('fs_desc', 'local_learnlogic'),
        'fsright' => get_string('fs_right', 'local_learnlogic'),
        'fseditor' => get_string('fs_editor', 'local_learnlogic'),
        'fsexit' => get_string('fs_exit', 'local_learnlogic'),
        'nosamples' => get_string('nosamples', 'local_learnlogic'),
        'nohidden' => get_string('nohidden', 'local_learnlogic'),
        'aceunavailable' => get_string('aceunavailable', 'local_learnlogic'),
        'nexeditor' => get_string('nexeditor', 'local_learnlogic'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexbattleground/battle', [
    'battleid' => $id,
    'lobbyurl' => $lobbyurl,
    'problemid' => 0,
    'listurl' => $lobbyurl,
]);
echo $OUTPUT->footer();
