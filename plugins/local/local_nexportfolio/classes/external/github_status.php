<?php
// This file is part of Moodle - http://moodle.org/
/**
 * GitHub connection status for the current user.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexportfolio\local\github;

/**
 * GitHub status AJAX.
 */
class github_status extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Feature enabled'),
            'connected' => new external_value(PARAM_BOOL, 'Has GitHub username'),
            'login' => new external_value(PARAM_TEXT, 'GitHub login'),
            'avatarurl' => new external_value(PARAM_URL, 'Avatar', VALUE_DEFAULT, ''),
            'projectcount' => new external_value(PARAM_INT, 'Imported projects'),
        ]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        global $USER, $DB;

        self::validate_parameters(self::execute_parameters(), []);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:view', $context);

        $profile = github::get_profile((int) $USER->id);
        $login = $profile ? (string) $profile->github_login : '';
        if ($login === '') {
            $handle = $DB->get_record('local_nexportfolio_handles', [
                'userid' => (int) $USER->id,
                'platform' => 'github',
            ]);
            $login = $handle ? (string) $handle->handle : '';
        }

        $count = $DB->count_records('local_nexportfolio_projects', [
            'userid' => (int) $USER->id,
            'source' => 'github',
        ]);

        return [
            'enabled' => github::enabled(),
            'connected' => $login !== '',
            'login' => $login,
            'avatarurl' => $profile ? (string) ($profile->avatar_url ?? '') : '',
            'projectcount' => (int) $count,
        ];
    }
}
