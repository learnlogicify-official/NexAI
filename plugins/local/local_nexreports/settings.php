<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexreports', get_string('pluginname', 'local_nexreports'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexreports/enablemenu',
        get_string('enablemenu', 'local_nexreports'),
        get_string('enablemenu_desc', 'local_nexreports'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexreports/cachettl',
        get_string('cachettl', 'local_nexreports'),
        get_string('cachettl_desc', 'local_nexreports'),
        600,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexreports/sessiongap',
        get_string('sessiongap', 'local_nexreports'),
        get_string('sessiongap_desc', 'local_nexreports'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexreports/enabletracking',
        get_string('enabletracking', 'local_nexreports'),
        get_string('enabletracking_desc', 'local_nexreports'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexreports/trackfrequency',
        get_string('trackfrequency', 'local_nexreports'),
        get_string('trackfrequency_desc', 'local_nexreports'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexreports/snapshotmaxage',
        get_string('snapshotmaxage', 'local_nexreports'),
        get_string('snapshotmaxage_desc', 'local_nexreports'),
        30,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('reports', new admin_externalpage(
        'local_nexreports',
        get_string('pluginname', 'local_nexreports'),
        new moodle_url('/local/nexreports/index.php'),
        'local/nexreports:viewsite'
    ));
}
