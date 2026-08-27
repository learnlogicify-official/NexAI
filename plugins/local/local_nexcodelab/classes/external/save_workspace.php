<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: save workspace file.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\missions;

/**
 * Save workspace.
 */
class save_workspace extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
            'path' => new external_value(PARAM_TEXT, 'File path'),
            'content' => new external_value(PARAM_RAW, 'Content'),
        ]);
    }

    public static function execute(int $missionid, string $path, string $content): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:attempt', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('missionid', 'path', 'content'));
        return missions::save_workspace(
            (int) $USER->id,
            (int) $params['missionid'],
            (string) $params['path'],
            (string) $params['content']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'ok'),
            'timemodified' => new external_value(PARAM_INT, 'time'),
        ]);
    }
}
