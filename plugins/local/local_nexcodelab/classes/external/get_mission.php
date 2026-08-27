<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: get one mission workspace.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\missions;
use moodle_exception;

/**
 * Mission detail for lab bench.
 */
class get_mission extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
        ]);
    }

    public static function execute(int $missionid): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['missionid' => $missionid]);
        $data = missions::get_mission((int) $params['missionid'], (int) $USER->id);
        if (!$data) {
            throw new moodle_exception('notfound', 'local_nexcodelab');
        }
        // Flatten csvpreview rows for WS.
        $preview = $data['csvpreview'];
        $rows = [];
        foreach ($preview['rows'] as $r) {
            $rows[] = ['cells' => array_map('strval', $r)];
        }
        $data['csvpreview'] = [
            'headers' => $preview['headers'],
            'rows' => $rows,
            'rowcount' => $preview['rowcount'],
            'colcount' => $preview['colcount'],
        ];
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'slug' => new external_value(PARAM_TEXT, 'slug'),
            'scenario' => new external_value(PARAM_RAW, 'scenario'),
            'track' => new external_value(PARAM_ALPHANUMEXT, 'track'),
            'estimateminutes' => new external_value(PARAM_INT, 'minutes'),
            'coverkey' => new external_value(PARAM_ALPHANUMEXT, 'cover'),
            'stepcount' => new external_value(PARAM_INT, 'steps'),
            'passedsteps' => new external_value(PARAM_INT, 'passed'),
            'userstatus' => new external_value(PARAM_ALPHANUMEXT, 'status'),
            'url' => new external_value(PARAM_URL, 'url'),
            'currentstepid' => new external_value(PARAM_INT, 'current'),
            'files' => new external_multiple_structure(new external_single_structure([
                'path' => new external_value(PARAM_TEXT, 'path'),
                'role' => new external_value(PARAM_ALPHANUMEXT, 'role'),
                'content' => new external_value(PARAM_RAW, 'content'),
                'seedcontent' => new external_value(PARAM_RAW, 'seed', VALUE_DEFAULT, ''),
                'readonly' => new external_value(PARAM_BOOL, 'readonly'),
                'sortorder' => new external_value(PARAM_INT, 'order'),
            ])),
            'steps' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'sortorder' => new external_value(PARAM_INT, 'order'),
                'number' => new external_value(PARAM_INT, 'number'),
                'title' => new external_value(PARAM_TEXT, 'title'),
                'instructions' => new external_value(PARAM_RAW, 'instructions'),
                'hint' => new external_value(PARAM_RAW, 'hint'),
                'checkkind' => new external_value(PARAM_ALPHANUMEXT, 'kind'),
                'xp' => new external_value(PARAM_INT, 'xp'),
                'passed' => new external_value(PARAM_BOOL, 'passed'),
                'locked' => new external_value(PARAM_BOOL, 'locked'),
            ])),
            'csvpreview' => new external_single_structure([
                'headers' => new external_multiple_structure(new external_value(PARAM_RAW, 'h')),
                'rows' => new external_multiple_structure(new external_single_structure([
                    'cells' => new external_multiple_structure(new external_value(PARAM_RAW, 'c')),
                ])),
                'rowcount' => new external_value(PARAM_INT, 'rows'),
                'colcount' => new external_value(PARAM_INT, 'cols'),
            ]),
        ]);
    }
}
