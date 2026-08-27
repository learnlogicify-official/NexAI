<?php
// This file is part of Moodle - http://moodle.org/
/**
 * External services for local_nexbattleground.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexbattleground_join_queue' => [
        'classname' => 'local_nexbattleground\\external\\join_queue',
        'methodname' => 'execute',
        'description' => 'Join the matchmaking queue',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_leave_queue' => [
        'classname' => 'local_nexbattleground\\external\\leave_queue',
        'methodname' => 'execute',
        'description' => 'Leave the matchmaking queue',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_poll_lobby' => [
        'classname' => 'local_nexbattleground\\external\\poll_lobby',
        'methodname' => 'execute',
        'description' => 'Poll lobby / queue / recent battles',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_challenge_user' => [
        'classname' => 'local_nexbattleground\\external\\challenge_user',
        'methodname' => 'execute',
        'description' => 'Challenge another user by username',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_respond_challenge' => [
        'classname' => 'local_nexbattleground\\external\\respond_challenge',
        'methodname' => 'execute',
        'description' => 'Accept or decline a challenge',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_get_battle' => [
        'classname' => 'local_nexbattleground\\external\\get_battle',
        'methodname' => 'execute',
        'description' => 'Get battle state and problem',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_run_code' => [
        'classname' => 'local_nexbattleground\\external\\run_code',
        'methodname' => 'execute',
        'description' => 'Run sample tests in a battle',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_submit_code' => [
        'classname' => 'local_nexbattleground\\external\\submit_code',
        'methodname' => 'execute',
        'description' => 'Submit solution in a battle',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_forfeit' => [
        'classname' => 'local_nexbattleground\\external\\forfeit',
        'methodname' => 'execute',
        'description' => 'Forfeit an active battle',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_create_room' => [
        'classname' => 'local_nexbattleground\\external\\create_room',
        'methodname' => 'execute',
        'description' => 'Create a private room with a 6-digit code',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_join_room' => [
        'classname' => 'local_nexbattleground\\external\\join_room',
        'methodname' => 'execute',
        'description' => 'Join a room by 6-digit code',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_peek_room' => [
        'classname' => 'local_nexbattleground\\external\\peek_room',
        'methodname' => 'execute',
        'description' => 'Preview a private room difficulty before joining',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_get_leaderboard' => [
        'classname' => 'local_nexbattleground\\external\\get_leaderboard',
        'methodname' => 'execute',
        'description' => 'Battle wins leaderboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexbattleground_cancel_room' => [
        'classname' => 'local_nexbattleground\\external\\cancel_room',
        'methodname' => 'execute',
        'description' => 'Cancel a waiting room you host',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
