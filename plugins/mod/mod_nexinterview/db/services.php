<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web services for mod_nexinterview.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_nexinterview_proxy' => [
        'classname' => 'mod_nexinterview\\external\\proxy',
        'methodname' => 'execute',
        'description' => 'Proxy signed requests to the AI interview service for a course activity',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
