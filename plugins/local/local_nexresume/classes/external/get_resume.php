<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Get resume AJAX.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexresume\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexresume\local\document;
use local_nexresume\local\export;
use local_nexresume\local\templates;

/**
 * Fetch merged resume document.
 */
class get_resume extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'refresh' => new external_value(PARAM_BOOL, 'Refresh platform data', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * @param bool $refresh
     * @return array
     */
    public static function execute(bool $refresh = false): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['refresh' => $refresh]);
        self::validate_context(context_system::instance());
        require_capability('local/nexresume:view', context_system::instance());

        $doc = !empty($params['refresh'])
            ? document::refresh((int) $USER->id)
            : document::get_merged((int) $USER->id);
        return [
            'resumejson' => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'previewhtml' => export::preview($doc),
            'printhtml' => export::html($doc),
            'templatesjson' => json_encode(templates::list_for_ui(), JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resumejson' => new external_value(PARAM_RAW, 'Resume JSON'),
            'previewhtml' => new external_value(PARAM_RAW, 'Preview HTML fragment'),
            'printhtml' => new external_value(PARAM_RAW, 'Print HTML document'),
            'templatesjson' => new external_value(PARAM_RAW, 'Available templates JSON'),
        ]);
    }
}
