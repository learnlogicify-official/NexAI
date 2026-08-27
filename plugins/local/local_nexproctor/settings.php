<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings.
 * @package local_nexproctor
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexproctor', get_string('pluginname', 'local_nexproctor'));
    $settings->add(new admin_setting_configtext(
        'local_nexproctor/retentiondays',
        get_string('retentiondays', 'local_nexproctor'),
        get_string('retentiondays_desc', 'local_nexproctor'),
        30,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexproctor/maxevidencesize',
        get_string('maxevidencesize', 'local_nexproctor'),
        get_string('maxevidencesize_desc', 'local_nexproctor'),
        2097152,
        PARAM_INT
    ));
    $ADMIN->add('localplugins', $settings);
}
