<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexstack.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexstack', get_string('pluginname', 'local_nexstack'));

    // Keep setting for admins who want a manual note; nav injection removed from lib.php.
    $settings->add(new admin_setting_configcheckbox(
        'local_nexstack/enablemenu',
        get_string('enablemenu', 'local_nexstack'),
        get_string('enablemenu_desc', 'local_nexstack'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexstack/sandbox_enabled',
        get_string('sandbox_enabled', 'local_nexstack'),
        get_string('sandbox_enabled_desc', 'local_nexstack'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexstack/sandbox_url',
        get_string('sandbox_url', 'local_nexstack'),
        get_string('sandbox_url_desc', 'local_nexstack'),
        'http://127.0.0.1:7077',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_nexstack/sandbox_token',
        get_string('sandbox_token', 'local_nexstack'),
        get_string('sandbox_token_desc', 'local_nexstack'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexstack/webcontainers',
        get_string('webcontainers', 'local_nexstack'),
        get_string('webcontainers_desc', 'local_nexstack'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
