<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexcomm.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexcomm', get_string('settings', 'local_nexcomm'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexcomm/enablemenu',
        get_string('enablemenu', 'local_nexcomm'),
        get_string('enablemenu_desc', 'local_nexcomm'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcomm/dailytarget',
        get_string('dailytarget', 'local_nexcomm'),
        get_string('dailytarget_desc', 'local_nexcomm'),
        4,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcomm/weeklytarget',
        get_string('weeklytarget', 'local_nexcomm'),
        get_string('weeklytarget_desc', 'local_nexcomm'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcomm/dailybonus',
        get_string('dailybonus', 'local_nexcomm'),
        get_string('dailybonus_desc', 'local_nexcomm'),
        15,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcomm/weeklybonus',
        get_string('weeklybonus', 'local_nexcomm'),
        get_string('weeklybonus_desc', 'local_nexcomm'),
        75,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcomm/watchgoal',
        get_string('watchgoal', 'local_nexcomm'),
        get_string('watchgoal_desc', 'local_nexcomm'),
        3,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcomm/learngoal',
        get_string('learngoal', 'local_nexcomm'),
        get_string('learngoal_desc', 'local_nexcomm'),
        20,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcomm/speakgoal',
        get_string('speakgoal', 'local_nexcomm'),
        get_string('speakgoal_desc', 'local_nexcomm'),
        15,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}