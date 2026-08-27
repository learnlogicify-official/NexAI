<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Event observers for local_nexproctor.
 * @package local_nexproctor
 */
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => 'local_nexproctor_observer_attempt_submitted',
    ],
];
