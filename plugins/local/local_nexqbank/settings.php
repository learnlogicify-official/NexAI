<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings / external page for local_nexqbank.
 *
 * @package    local_nexqbank
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_externalpage(
        'local_nexqbank',
        get_string('pluginname', 'local_nexqbank'),
        new moodle_url('/local/nexqbank/index.php'),
        'moodle/site:config'
    ));
}
