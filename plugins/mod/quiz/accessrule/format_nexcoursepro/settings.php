<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for format_nexcoursepro (leaderboard levels).
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'format_nexcoursepro/leaderboardheading',
        get_string('leaderboardsettings', 'format_nexcoursepro'),
        get_string('leaderboardsettings_desc', 'format_nexcoursepro')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'format_nexcoursepro/show_level',
        get_string('config_show_level', 'format_nexcoursepro'),
        get_string('config_show_level_desc', 'format_nexcoursepro'),
        1
    ));

    $leveloptions = [4 => 4, 6 => 6, 8 => 8, 10 => 10, 12 => 12, 15 => 15];
    $settings->add(new admin_setting_configselect(
        'format_nexcoursepro/level_number',
        get_string('config_level_number', 'format_nexcoursepro'),
        get_string('config_level_number_desc', 'format_nexcoursepro'),
        6,
        $leveloptions
    ));

    // Grade-score thresholds to reach each level (same idea as block_game level_up*).
    // Marks are compared against the learner's course total grade (the Score on the card).
    $leveluppoints = [
        1 => 40, 2 => 55, 3 => 70, 4 => 80, 5 => 90, 6 => 100,
        7 => 120, 8 => 140, 9 => 160, 10 => 180, 11 => 200, 12 => 220,
        13 => 240, 14 => 260, 15 => 300,
    ];
    for ($i = 1; $i <= 15; $i++) {
        $setting = new admin_setting_configtext(
            'format_nexcoursepro/level_up' . $i,
            get_string('config_level_up', 'format_nexcoursepro', $i),
            get_string('config_level_up_desc', 'format_nexcoursepro', $i),
            $leveluppoints[$i],
            PARAM_INT
        );
        $settings->add($setting);
    }
}
