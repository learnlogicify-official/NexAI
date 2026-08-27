<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Version info for mod_nexinterview (course activity).
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_nexinterview';
$plugin->version   = 2026082703;
$plugin->requires  = 2025041400; // Moodle 5.0+.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.2';
$plugin->dependencies = [
    'local_nexinterview' => 2026082707,
];
