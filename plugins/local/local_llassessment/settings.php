<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for local_llassessment.
 *
 * @package    local_llassessment
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_llassessment',
        get_string('pluginname', 'local_llassessment')
    );

    $settings->add(new admin_setting_heading(
        'local_llassessment/arenaheading',
        get_string('arenaheading', 'local_llassessment'),
        get_string('arenaheading_desc', 'local_llassessment')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_llassessment/enablearena',
        get_string('enablearena', 'local_llassessment'),
        get_string('enablearena_desc', 'local_llassessment'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'local_llassessment/colormode',
        get_string('colormode', 'local_llassessment'),
        get_string('colormode_desc', 'local_llassessment'),
        'light',
        [
            'light' => get_string('colormode_light', 'local_llassessment'),
            'dark' => get_string('colormode_dark', 'local_llassessment'),
            'auto' => get_string('colormode_auto', 'local_llassessment'),
        ]
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_llassessment/brandcolor',
        get_string('brandcolor', 'local_llassessment'),
        get_string('brandcolor_desc', 'local_llassessment'),
        '#0f766e'
    ));

    $ADMIN->add('localplugins', $settings);
}
