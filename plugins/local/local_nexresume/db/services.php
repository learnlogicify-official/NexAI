<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web service definitions for local_nexresume.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexresume_get_resume' => [
        'classname' => 'local_nexresume\external\get_resume',
        'methodname' => 'execute',
        'description' => 'Get merged resume (platform data + saved edits)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexresume:view',
    ],
    'local_nexresume_save_resume' => [
        'classname' => 'local_nexresume\external\save_resume',
        'methodname' => 'execute',
        'description' => 'Save resume edits',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexresume:manageown',
    ],
];
