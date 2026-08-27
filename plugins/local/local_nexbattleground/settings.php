<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexbattleground.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexbattleground', get_string('settings', 'local_nexbattleground'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexbattleground/enablemenu',
        get_string('enablemenu', 'local_nexbattleground'),
        get_string('enablemenu_desc', 'local_nexbattleground'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexbattleground/battleduration',
        get_string('battleduration', 'local_nexbattleground'),
        get_string('battleduration_desc', 'local_nexbattleground'),
        900,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
