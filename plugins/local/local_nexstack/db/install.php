<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Post-install seed for local_nexstack.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_nexstack_install() {
    \local_nexstack\local\seed::install_defaults();
}
