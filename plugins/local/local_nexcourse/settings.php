<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Settings for local_nexcourse.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexcourse', get_string('pluginname', 'local_nexcourse'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_nexcourse/replacemycourses',
        get_string('replacemycourses', 'local_nexcourse'),
        get_string('replacemycourses_desc', 'local_nexcourse'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexcourse/enablemenu',
        get_string('enablemenu', 'local_nexcourse'),
        get_string('enablemenu_desc', 'local_nexcourse'),
        0
    ));
}
