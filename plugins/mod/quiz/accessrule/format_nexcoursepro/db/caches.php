<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Cache definitions for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Per-user left-pane payloads for non-quiz activities (stable HTML).
    // Quizzes are excluded — attempt/completion state changes too often.
    'activitypane' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 180,
        'staticacceleration' => true,
        'staticaccelerationsize' => 30,
    ],
];
