<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_learnlogic.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_learnlogic', get_string('pluginname', 'local_learnlogic'));

    $settings->add(new admin_setting_configcheckbox(
        'local_learnlogic/enablemenu',
        get_string('enablemenu', 'local_learnlogic'),
        get_string('enablemenu_desc', 'local_learnlogic'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_learnlogic/xpheading',
        get_string('xpsettings', 'local_learnlogic'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_easy',
        get_string('xp_easy', 'local_learnlogic'),
        '',
        25,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_medium',
        get_string('xp_medium', 'local_learnlogic'),
        '',
        50,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_hard',
        get_string('xp_hard', 'local_learnlogic'),
        '',
        100,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_veryhard',
        get_string('xp_veryhard', 'local_learnlogic'),
        '',
        100,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_firstbonus',
        get_string('xp_firstbonus', 'local_learnlogic'),
        '',
        15,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_learnlogic/xp_streakday',
        get_string('xp_streakday', 'local_learnlogic'),
        '',
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_learnlogic/crheading',
        get_string('coderunnersettings', 'local_learnlogic'),
        get_string('coderunnersettings_desc', 'local_learnlogic')
    ));

    foreach (local_learnlogic_languages() as $lang) {
        $settings->add(new admin_setting_configtext(
            'local_learnlogic/prototype_' . $lang,
            get_string('prototype_lang', 'local_learnlogic', $lang),
            get_string('prototype_lang_desc', 'local_learnlogic'),
            0,
            PARAM_INT
        ));
    }

    $ADMIN->add('localplugins', $settings);
}
