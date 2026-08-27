<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Cache definitions for local_nexcourse.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'courseprogress' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 180,
        'staticacceleration' => true,
        'staticaccelerationsize' => 64,
    ],
];
