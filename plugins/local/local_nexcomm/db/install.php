<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Post-install for local_nexcomm.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Seed placement activities after install.
 */
function xmldb_local_nexcomm_install() {
    \local_nexcomm\local\seed::install();
    \local_nexcomm\local\lesson_seed::install();
}
