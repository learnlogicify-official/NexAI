<?php
// This file is part of Moodle - http://moodle.org/
/**
 * External services for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'format_nexcoursepro_get_activity_pane' => [
        'classname' => 'format_nexcoursepro\\external\\get_activity_pane',
        'methodname' => 'execute',
        'description' => 'Load an activity into the NexCoursePro left pane without reloading the course shell',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_get_cm_progress' => [
        'classname' => 'format_nexcoursepro\\external\\get_cm_progress',
        'methodname' => 'execute',
        'description' => 'Refresh completion criteria and progress strip for one activity (H5P live updates)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_get_outline' => [
        'classname' => 'format_nexcoursepro\\external\\get_outline',
        'methodname' => 'execute',
        'description' => 'Refresh the NexCoursePro sidebar outline after course structure edits',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_get_leaderboard' => [
        'classname' => 'format_nexcoursepro\\external\\get_leaderboard',
        'methodname' => 'execute',
        'description' => 'Load the NexCoursePro course grade leaderboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_set_avatar' => [
        'classname' => 'format_nexcoursepro\\external\\set_avatar',
        'methodname' => 'execute',
        'description' => 'Set the NexCoursePro leaderboard avatar for the current user',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_search_enrol_users' => [
        'classname' => 'format_nexcoursepro\\external\\search_enrol_users',
        'methodname' => 'execute',
        'description' => 'Search users to enrol with college / year / department filters',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_enrol_users' => [
        'classname' => 'format_nexcoursepro\\external\\enrol_users',
        'methodname' => 'execute',
        'description' => 'Bulk enrol selected users via manual enrolment',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_add_section' => [
        'classname' => 'format_nexcoursepro\\external\\add_section',
        'methodname' => 'execute',
        'description' => 'Create a section or subsection from the NexCoursePro sidebar editor',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_delete_section' => [
        'classname' => 'format_nexcoursepro\\external\\delete_section',
        'methodname' => 'execute',
        'description' => 'Delete an empty section from the NexCoursePro sidebar editor',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_nexcoursepro_rename_cm' => [
        'classname' => 'format_nexcoursepro\\external\\rename_cm',
        'methodname' => 'execute',
        'description' => 'Rename an activity from the NexCoursePro sidebar',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

$services = [];
