<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexlogin.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexlogin', get_string('pluginname', 'local_nexlogin'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexlogin/enable',
        get_string('enable', 'local_nexlogin'),
        get_string('enable_desc', 'local_nexlogin'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexlogin/brandname',
        get_string('brandname', 'local_nexlogin'),
        get_string('brandname_desc', 'local_nexlogin'),
        get_string('brandname_default', 'local_nexlogin'),
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
