<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import GitHub repositories as portfolio projects.
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
use local_nexportfolio\local\projects;

/**
 * GitHub import AJAX.
 */
class import_github extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_TEXT, 'GitHub username', VALUE_DEFAULT, ''),
            'fetchlanguages' => new external_value(PARAM_BOOL, 'Fetch language breakdown', VALUE_DEFAULT, true),
            'fetchreadmes' => new external_value(PARAM_BOOL, 'Fetch README.md per repo', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'imported' => new external_value(PARAM_INT, 'New projects'),
            'updated' => new external_value(PARAM_INT, 'Updated projects'),
            'total' => new external_value(PARAM_INT, 'Repos fetched'),
            'login' => new external_value(PARAM_TEXT, 'GitHub login'),
        ]);
    }

    /**
     * @param string $username
     * @param bool $fetchlanguages
     * @param bool $fetchreadmes
     * @return array
     */
    public static function execute(string $username = '', bool $fetchlanguages = true, bool $fetchreadmes = true): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'username' => $username,
            'fetchlanguages' => $fetchlanguages,
            'fetchreadmes' => $fetchreadmes,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:manageown', $context);

        if (!github::enabled()) {
            throw new \moodle_exception('githubdisabled', 'local_nexportfolio');
        }

        $username = trim($params['username']);
        if ($username === '') {
            throw new \moodle_exception('githubusernamerequired', 'local_nexportfolio');
        }

        $result = projects::sync_github(
            (int) $USER->id,
            $username,
            !empty($params['fetchlanguages']),
            !empty($params['fetchreadmes'])
        );

        return [
            'ok' => true,
            'message' => get_string('githubimported', 'local_nexportfolio', (object) $result),
            'imported' => (int) $result['imported'],
            'updated' => (int) $result['updated'],
            'total' => (int) $result['total'],
            'login' => (string) $result['login'],
        ];
    }
}
