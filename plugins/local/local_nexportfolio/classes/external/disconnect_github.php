<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Disconnect GitHub and remove imported projects.
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
 * GitHub disconnect AJAX.
 */
class disconnect_github extends external_api {

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
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), []);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:manageown', $context);

        github::disconnect((int) $USER->id);

        return [
            'ok' => true,
            'message' => get_string('githubdisconnected', 'local_nexportfolio'),
        ];
    }
}
