<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Version info for local_nexlogin (NexLogin).
 *
 * Restyles Moodle login / signup pages to a light centered card UI.
 * Works alongside Edwiser RemUI — does not replace the site theme.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_nexlogin';
$plugin->version   = 2026082001;
$plugin->requires  = 2025041400; // Moodle 5.0+.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.3.3';
