<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Settings for local_nexdashboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexdashboard', get_string('pluginname', 'local_nexdashboard'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_nexdashboard/replacemyhome',
        get_string('replacemyhome', 'local_nexdashboard'),
        get_string('replacemyhome_desc', 'local_nexdashboard'),
        1
    ));
}
