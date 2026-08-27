<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Hook registrations for local_nexlogin.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => [\local_nexlogin\local\hooks::class, 'before_http_headers'],
        'priority' => 1000,
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => [\local_nexlogin\local\hooks::class, 'before_head'],
        'priority' => 1000,
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_nexlogin\local\hooks::class, 'before_top_of_body'],
        'priority' => 1000,
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_nexlogin\local\hooks::class, 'before_footer'],
        'priority' => 1000,
    ],
];
