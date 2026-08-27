<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: list missions.
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
 * List Mission Labs.
 */
class get_missions extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_TEXT, 'Search', VALUE_DEFAULT, ''),
            'track' => new external_value(PARAM_ALPHANUMEXT, 'Track', VALUE_DEFAULT, ''),
            'userstatus' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'all'),
            'page' => new external_value(PARAM_INT, 'Page (0-based)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 12),
        ]);
    }

    public static function execute(
        string $search = '',
        string $track = '',
        string $userstatus = 'all',
        int $page = 0,
        int $perpage = 12
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:view', $context);
        $params = self::validate_parameters(
            self::execute_parameters(),
            compact('search', 'track', 'userstatus', 'page', 'perpage')
        );
        $data = missions::list_missions((int) $USER->id, $params);
        $stats = gamification::user_stats((int) $USER->id);
        $stats['total'] = (int) ($data['counts']['all'] ?? 0);
        return [
            'missions' => $data['missions'],
            'total' => $data['total'],
            'page' => $data['page'],
            'perpage' => $data['perpage'],
            'counts' => $data['counts'],
            'stats' => $stats,
        ];
    }

    public static function execute_returns(): external_single_structure {
        $mission = new external_single_structure([
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
        ]);
        return new external_single_structure([
            'missions' => new external_multiple_structure($mission),
            'total' => new external_value(PARAM_INT, 'total'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'perpage'),
            'counts' => new external_single_structure([
                'all' => new external_value(PARAM_INT, 'all'),
                'completed' => new external_value(PARAM_INT, 'completed'),
                'inprogress' => new external_value(PARAM_INT, 'inprogress'),
                'notstarted' => new external_value(PARAM_INT, 'notstarted'),
            ]),
            'stats' => new external_single_structure([
                'xp' => new external_value(PARAM_INT, 'xp'),
                'streak' => new external_value(PARAM_INT, 'streak'),
                'longest' => new external_value(PARAM_INT, 'longest'),
                'rank' => new external_value(PARAM_INT, 'rank'),
                'solved' => new external_value(PARAM_INT, 'solved'),
                'total' => new external_value(PARAM_INT, 'total'),
            ]),
        ]);
    }
}
