<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexresume.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexresume', get_string('pluginname', 'local_nexresume'));

    $settings->add(new admin_setting_heading(
        'local_nexresume/heading',
        get_string('settingsheading', 'local_nexresume'),
        get_string('resumebuilder_desc', 'local_nexresume')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexresume/enablemenu',
        get_string('enablemenu', 'local_nexresume'),
        get_string('enablemenu_desc', 'local_nexresume'),
        1
    ));

    $ADMIN->add('localplugins', $settings);
}
