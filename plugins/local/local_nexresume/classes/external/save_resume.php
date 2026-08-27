<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Save resume AJAX.
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

/**
 * Persist resume edits.
 */
class save_resume extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resumejson' => new external_value(PARAM_RAW, 'Resume JSON payload'),
        ]);
    }

    /**
     * @param string $resumejson
     * @return array
     */
    public static function execute(string $resumejson): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'resumejson' => $resumejson,
        ]);
        self::validate_context(context_system::instance());
        require_capability('local/nexresume:manageown', context_system::instance());

        $data = json_decode($params['resumejson'], true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('Invalid resume JSON');
        }

        $doc = document::save((int) $USER->id, $data);
        return [
            'resumejson' => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'previewhtml' => export::preview($doc),
            'printhtml' => export::html($doc),
            'status' => 'ok',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resumejson' => new external_value(PARAM_RAW, 'Saved resume JSON'),
            'previewhtml' => new external_value(PARAM_RAW, 'Preview HTML fragment'),
            'printhtml' => new external_value(PARAM_RAW, 'Print HTML document'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
        ]);
    }
}
