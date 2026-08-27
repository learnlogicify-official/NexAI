<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web service definitions for local_nexcourse.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexcourse_get_courses' => [
        'classname' => 'local_nexcourse\\external\\get_courses',
        'methodname' => 'execute',
        'description' => 'Paginated enrolled courses for NexCourse hub',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
