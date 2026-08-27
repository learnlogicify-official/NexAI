<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin settings for qbank_leetcodeimport.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'qbank_leetcodeimport/settingsheading',
        get_string('settingsheading', 'qbank_leetcodeimport'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'qbank_leetcodeimport/openai_apikey',
        get_string('openai_apikey', 'qbank_leetcodeimport'),
        get_string('openai_apikey_desc', 'qbank_leetcodeimport'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_leetcodeimport/openai_model',
        get_string('openai_model', 'qbank_leetcodeimport'),
        get_string('openai_model_desc', 'qbank_leetcodeimport'),
        'gpt-4o',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_leetcodeimport/openai_baseurl',
        get_string('openai_baseurl', 'qbank_leetcodeimport'),
        get_string('openai_baseurl_desc', 'qbank_leetcodeimport'),
        'https://api.openai.com/v1',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_leetcodeimport/default_coderunnertype',
        get_string('default_coderunnertype', 'qbank_leetcodeimport'),
        get_string('default_coderunnertype_desc', 'qbank_leetcodeimport'),
        'multilanguage',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_leetcodeimport/default_language',
        get_string('default_language', 'qbank_leetcodeimport'),
        '',
        'python3',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'qbank_leetcodeimport/leetcode_session',
        get_string('leetcode_session', 'qbank_leetcodeimport'),
        get_string('leetcode_session_desc', 'qbank_leetcodeimport'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_leetcodeimport/leetcode_csrf',
        get_string('leetcode_csrf', 'qbank_leetcodeimport'),
        get_string('leetcode_csrf_desc', 'qbank_leetcodeimport'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
