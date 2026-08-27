<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: mission progress summary.
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
use local_nexcodelab\local\gamification;
use local_nexcodelab\local\missions;

/**
 * Progress.
 */
class get_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:view', $context);
        $data = missions::user_mission_progress((int) $USER->id);
        $stats = gamification::user_stats((int) $USER->id);
        return $data + ['stats' => $stats];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'missions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'number' => new external_value(PARAM_INT, 'Catalog number (1-based)'),
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
            ])),
            'completed' => new external_value(PARAM_INT, 'completed'),
            'inprogress' => new external_value(PARAM_INT, 'inprogress'),
            'total' => new external_value(PARAM_INT, 'total'),
            'stats' => new external_single_structure([
                'xp' => new external_value(PARAM_INT, 'xp'),
                'streak' => new external_value(PARAM_INT, 'streak'),
                'longest' => new external_value(PARAM_INT, 'longest'),
                'rank' => new external_value(PARAM_INT, 'rank'),
                'solved' => new external_value(PARAM_INT, 'solved'),
            ]),
        ]);
    }
}
