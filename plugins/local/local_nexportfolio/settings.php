<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin settings for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexportfolio', get_string('pluginname', 'local_nexportfolio'));

    $settings->add(new admin_setting_heading(
        'local_nexportfolio/heading',
        get_string('settingsheading', 'local_nexportfolio'),
        get_string('codingportfolio_desc', 'local_nexportfolio')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexportfolio/enablemenu',
        get_string('enablemenu', 'local_nexportfolio'),
        get_string('enablemenu_desc', 'local_nexportfolio'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexportfolio/leetcodeapi',
        get_string('leetcodeapi', 'local_nexportfolio'),
        get_string('leetcodeapi_desc', 'local_nexportfolio'),
        'https://alfa-leetcode-api-production-16ec.up.railway.app',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexportfolio/cachettl',
        get_string('cachettl', 'local_nexportfolio'),
        get_string('cachettl_desc', 'local_nexportfolio'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexportfolio/codingninjasproxy',
        get_string('codingninjasproxy', 'local_nexportfolio'),
        get_string('codingninjasproxy_desc', 'local_nexportfolio'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_heading(
        'local_nexportfolio/githubheading',
        get_string('githubheading', 'local_nexportfolio'),
        get_string('githubheading_desc', 'local_nexportfolio')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexportfolio/githubenabled',
        get_string('githubenabled', 'local_nexportfolio'),
        get_string('githubenabled_desc', 'local_nexportfolio'),
        1
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_nexportfolio/githubapitoken',
        get_string('githubapitoken', 'local_nexportfolio'),
        get_string('githubapitoken_desc', 'local_nexportfolio'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);
}
