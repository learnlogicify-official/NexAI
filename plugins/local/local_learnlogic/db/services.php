<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web service definitions for local_learnlogic.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_learnlogic_get_problems' => [
        'classname' => 'local_learnlogic\\external\\get_problems',
        'methodname' => 'execute',
        'description' => 'List NexPractice problems',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_get_problem' => [
        'classname' => 'local_learnlogic\\external\\get_problem',
        'methodname' => 'execute',
        'description' => 'Get one NexPractice problem',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_run_code' => [
        'classname' => 'local_learnlogic\\external\\run_code',
        'methodname' => 'execute',
        'description' => 'Run sample or custom tests via CodeRunner',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_submit_code' => [
        'classname' => 'local_learnlogic\\external\\submit_code',
        'methodname' => 'execute',
        'description' => 'Submit code against all tests via CodeRunner',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_save_draft' => [
        'classname' => 'local_learnlogic\\external\\save_draft',
        'methodname' => 'execute',
        'description' => 'Save code draft',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_get_leaderboard' => [
        'classname' => 'local_learnlogic\\external\\get_leaderboard',
        'methodname' => 'execute',
        'description' => 'XP leaderboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_learnlogic_get_submissions' => [
        'classname' => 'local_learnlogic\\external\\get_submissions',
        'methodname' => 'execute',
        'description' => 'User submission history',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
