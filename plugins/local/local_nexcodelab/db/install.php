<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Post-install seed for local_nexcodelab (Mission Labs).
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Seed missions after first install.
 */
function xmldb_local_nexcodelab_install() {
    require_once(__DIR__ . '/../classes/local/mission_seed.php');
    \local_nexcodelab\local\mission_seed::seed();
}
