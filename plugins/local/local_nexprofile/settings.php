<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexprofile.
 *
 * @package   local_nexprofile
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexprofile', get_string('settings', 'local_nexprofile'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexprofile/replaceprofile',
        get_string('replaceprofile', 'local_nexprofile'),
        get_string('replaceprofile_desc', 'local_nexprofile'),
        1
    ));

    $ADMIN->add('localplugins', $settings);
}
