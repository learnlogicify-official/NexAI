<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web service definitions for local_nexdashboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexdashboard_get_dashboard' => [
        'classname' => 'local_nexdashboard\\external\\get_dashboard',
        'methodname' => 'execute',
        'description' => 'Student dashboard payload',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexdashboard_get_learning_time' => [
        'classname' => 'local_nexdashboard\\external\\get_learning_time',
        'methodname' => 'execute',
        'description' => 'Deferred learning time + Time Spent charts',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexdashboard_set_goal' => [
        'classname' => 'local_nexdashboard\\external\\set_goal',
        'methodname' => 'execute',
        'description' => 'Set weekly learning goal target (3, 5, or 7)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexdashboard_get_overall_leaderboard' => [
        'classname' => 'local_nexdashboard\\external\\get_overall_leaderboard',
        'methodname' => 'execute',
        'description' => 'Paginated overall student leaderboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
