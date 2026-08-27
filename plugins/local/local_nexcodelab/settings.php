<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for local_nexcodelab.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nexcodelab', get_string('pluginname', 'local_nexcodelab'));

    $settings->add(new admin_setting_configcheckbox(
        'local_nexcodelab/enablemenu',
        get_string('enablemenu', 'local_nexcodelab'),
        get_string('enablemenu_desc', 'local_nexcodelab'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_nexcodelab/xpheading',
        get_string('xpsettings', 'local_nexcodelab'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_easy',
        get_string('xp_easy', 'local_nexcodelab'),
        '',
        25,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_medium',
        get_string('xp_medium', 'local_nexcodelab'),
        '',
        50,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_hard',
        get_string('xp_hard', 'local_nexcodelab'),
        '',
        100,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_veryhard',
        get_string('xp_veryhard', 'local_nexcodelab'),
        '',
        100,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_firstbonus',
        get_string('xp_firstbonus', 'local_nexcodelab'),
        '',
        15,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_nexcodelab/xp_streakday',
        get_string('xp_streakday', 'local_nexcodelab'),
        '',
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_nexcodelab/crheading',
        get_string('coderunnersettings', 'local_nexcodelab'),
        get_string('coderunnersettings_desc', 'local_nexcodelab')
    ));

    foreach (local_nexcodelab_languages() as $lang) {
        $settings->add(new admin_setting_configtext(
            'local_nexcodelab/prototype_' . $lang,
            get_string('prototype_lang', 'local_nexcodelab', $lang),
            get_string('prototype_lang_desc', 'local_nexcodelab'),
            0,
            PARAM_INT
        ));
    }

    $settings->add(new admin_setting_heading(
        'local_nexcodelab/dsheading',
        get_string('dsprototypeheading', 'local_nexcodelab'),
        get_string('dsprototypeheading_desc', 'local_nexcodelab')
    ));

    $ADMIN->add('localplugins', $settings);
}
