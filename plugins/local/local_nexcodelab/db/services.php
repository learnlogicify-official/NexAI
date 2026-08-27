<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web service definitions for local_nexcodelab.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexcodelab_get_problems' => [
        'classname' => 'local_nexcodelab\\external\\get_problems',
        'methodname' => 'execute',
        'description' => 'List NexCodeLab problems',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_get_problem' => [
        'classname' => 'local_nexcodelab\\external\\get_problem',
        'methodname' => 'execute',
        'description' => 'Get one NexCodeLab problem',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_run_code' => [
        'classname' => 'local_nexcodelab\\external\\run_code',
        'methodname' => 'execute',
        'description' => 'Run sample or custom tests via CodeRunner',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_submit_code' => [
        'classname' => 'local_nexcodelab\\external\\submit_code',
        'methodname' => 'execute',
        'description' => 'Submit code against all tests via CodeRunner',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_save_draft' => [
        'classname' => 'local_nexcodelab\\external\\save_draft',
        'methodname' => 'execute',
        'description' => 'Save code draft',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_get_leaderboard' => [
        'classname' => 'local_nexcodelab\\external\\get_leaderboard',
        'methodname' => 'execute',
        'description' => 'XP leaderboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_get_submissions' => [
        'classname' => 'local_nexcodelab\\external\\get_submissions',
        'methodname' => 'execute',
        'description' => 'User submission history',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    // Mission APIs retained for optional mission.php / progress pages.
    'local_nexcodelab_get_missions' => [
        'classname' => 'local_nexcodelab\\external\\get_missions',
        'methodname' => 'execute',
        'description' => 'List CodeLab missions',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_get_mission' => [
        'classname' => 'local_nexcodelab\\external\\get_mission',
        'methodname' => 'execute',
        'description' => 'Get one mission workspace',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_check_step' => [
        'classname' => 'local_nexcodelab\\external\\check_step',
        'methodname' => 'execute',
        'description' => 'Check a mission step via CodeRunner',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_save_workspace' => [
        'classname' => 'local_nexcodelab\\external\\save_workspace',
        'methodname' => 'execute',
        'description' => 'Autosave workspace file',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexcodelab_get_progress' => [
        'classname' => 'local_nexcodelab\\external\\get_progress',
        'methodname' => 'execute',
        'description' => 'Mission progress summary',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
