<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexBattleGround lobby.
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

$PAGE->set_url(new moodle_url('/local/nexbattleground/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexbattleground'));
local_nexbattleground_setup_page($PAGE);
$PAGE->requires->css('/local/nexbattleground/styles.css');

$canbattle = has_capability('local/nexbattleground:battle', $context);
$battleurl = (new moodle_url('/local/nexbattleground/battle.php'))->out(false);

$PAGE->requires->js_call_amd('local_nexbattleground/lobby', 'init', [[
    'canBattle' => $canbattle,
    'battleUrl' => $battleurl,
    'strings' => [
        'findmatch' => get_string('findmatch', 'local_nexbattleground'),
        'cancelqueue' => get_string('cancelqueue', 'local_nexbattleground'),
        'searching' => get_string('searching', 'local_nexbattleground'),
        'accept' => get_string('accept', 'local_nexbattleground'),
        'decline' => get_string('decline', 'local_nexbattleground'),
        'win' => get_string('win', 'local_nexbattleground'),
        'loss' => get_string('loss', 'local_nexbattleground'),
        'tie' => get_string('tie', 'local_nexbattleground'),
        'nobattles' => get_string('nobattles', 'local_nexbattleground'),
        'copied' => get_string('copied', 'local_nexbattleground'),
        'prev' => get_string('prev', 'local_nexbattleground'),
        'next' => get_string('next', 'local_nexbattleground'),
        'showingrange' => 'Showing {from}–{to} of {total}',
        'justnow' => get_string('justnow', 'local_nexbattleground'),
        'minutesago' => '{n}m ago',
        'hoursago' => '{n}h ago',
        'daysago' => '{n}d ago',
        'easy' => get_string('easy', 'local_nexbattleground'),
        'medium' => get_string('medium', 'local_nexbattleground'),
        'hard' => get_string('hard', 'local_nexbattleground'),
        'veryhard' => get_string('veryhard', 'local_nexbattleground'),
        'anydifficulty' => get_string('anydifficulty', 'local_nexbattleground'),
        'challengedifficulty' => 'Wants to battle · {diff}',
        'roompeek' => '{host} · {diff}',
        'opponent' => get_string('opponent', 'local_nexbattleground'),
    ],
]]);

echo $OUTPUT->header();
$contextdata = local_nexbattleground_header_context((int) $USER->id);
$contextdata['canbattle'] = $canbattle;
$contextdata['lobbyurl'] = (new moodle_url('/local/nexbattleground/index.php'))->out(false);
$contextdata['leaderboardurl'] = (new moodle_url('/local/nexbattleground/leaderboard.php'))->out(false);
$contextdata['navlobby'] = true;
$contextdata['navleaderboard'] = false;
echo $OUTPUT->render_from_template('local_nexbattleground/lobby', $contextdata);
echo $OUTPUT->footer();
