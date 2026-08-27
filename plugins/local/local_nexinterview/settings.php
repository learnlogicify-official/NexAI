<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexinterview.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexinterview', get_string('pluginname', 'local_nexinterview'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexinterview/enablemenu',
        get_string('enablemenu', 'local_nexinterview'),
        get_string('enablemenu_desc', 'local_nexinterview'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexinterview/serviceurl',
        get_string('serviceurl', 'local_nexinterview'),
        get_string('serviceurl_desc', 'local_nexinterview'),
        'https://interviewservice-production.up.railway.app',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_nexinterview/sharedsecret',
        get_string('sharedsecret', 'local_nexinterview'),
        get_string('sharedsecret_desc', 'local_nexinterview'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexinterview/voicelang',
        get_string('voicelang', 'local_nexinterview'),
        get_string('voicelang_desc', 'local_nexinterview'),
        'en-IN',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexinterview/realtimevoice',
        get_string('realtimevoice', 'local_nexinterview'),
        get_string('realtimevoice_desc', 'local_nexinterview'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexinterview/durationminutes',
        get_string('durationminutes', 'local_nexinterview'),
        get_string('durationminutes_desc', 'local_nexinterview'),
        17,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_nexinterview/problemmap',
        get_string('problemmap', 'local_nexinterview'),
        get_string('problemmap_desc', 'local_nexinterview'),
        "sde_intern=\nfrontend=\nbackend=\nai_engineer=\n",
        PARAM_RAW
    ));

    $ADMIN->add('localplugins', $settings);
}
