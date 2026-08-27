<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web services for local_nexinterview.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexinterview_proxy' => [
        'classname' => 'local_nexinterview\\external\\proxy',
        'methodname' => 'execute',
        'description' => 'Proxy signed requests to the AI interview service',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexinterview_extract_resume' => [
        'classname' => 'local_nexinterview\\external\\extract_resume',
        'methodname' => 'execute',
        'description' => 'Extract plain text from an uploaded resume PDF/text',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
