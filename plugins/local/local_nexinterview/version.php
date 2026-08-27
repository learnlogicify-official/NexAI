<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Version info for local_nexinterview (NexInterview).
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_nexinterview';
$plugin->version   = 2026082713;
$plugin->requires  = 2025041400; // Moodle 5.0+.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.6.11';
$plugin->dependencies = [
    'local_learnlogic' => ANY_VERSION,
];
