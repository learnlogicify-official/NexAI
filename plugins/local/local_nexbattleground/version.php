<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Version metadata for local_nexbattleground (NexBattleGround).
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_nexbattleground';
$plugin->version   = 2026082001;
$plugin->requires  = 2025041400; // Moodle 5.0+.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.19';
$plugin->dependencies = [
    'local_learnlogic' => 2026080511,
];
