<?php
// This file is part of Moodle - http://moodle.org/
/**
 * External services for local_nexstack.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexstack_get_mission' => [
        'classname' => 'local_nexstack\\external\\get_mission',
        'methodname' => 'execute',
        'description' => 'Load a NexStack mission + workspace',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexstack_save_workspace' => [
        'classname' => 'local_nexstack\\external\\save_workspace',
        'methodname' => 'execute',
        'description' => 'Autosave NexStack workspace files',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexstack_check_step' => [
        'classname' => 'local_nexstack\\external\\check_step',
        'methodname' => 'execute',
        'description' => 'Record step check results for a NexStack mission',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexstack_sandbox_session' => [
        'classname' => 'local_nexstack\\external\\sandbox_session',
        'methodname' => 'execute',
        'description' => 'Boot/sync/exec remote NexStack sandbox sessions',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

$services = [];
