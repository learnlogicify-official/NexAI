<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Scheduled tasks.
 * @package local_nexproctor
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_nexproctor\\task\\cleanup_evidence',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
