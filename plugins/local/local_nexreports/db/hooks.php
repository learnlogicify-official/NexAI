<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Hook registrations for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_nexreports\local\hooks::class, 'before_footer'],
        'priority' => 500,
    ],
];
