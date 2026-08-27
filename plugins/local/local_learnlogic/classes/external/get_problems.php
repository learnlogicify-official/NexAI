<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: list problems.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_learnlogic\local\catalog;
use local_learnlogic\local\gamification;

/**
 * List practice problems with filters.
 */
class get_problems extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_TEXT, 'Search', VALUE_DEFAULT, ''),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty', VALUE_DEFAULT, ''),
            'userstatus' => new external_value(PARAM_ALPHANUMEXT, 'User status filter', VALUE_DEFAULT, 'all'),
            'tagid' => new external_value(PARAM_INT, 'Tag id (legacy single filter)', VALUE_DEFAULT, 0),
            'tagids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Topic tag id'),
                'Topic tag ids (OR filter)',
                VALUE_DEFAULT,
                []
            ),
            'companyids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Company tag id'),
                'Company tag ids (OR filter)',
                VALUE_DEFAULT,
                []
            ),
            'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 20),
        ]);
    }

    public static function execute(
        string $search = '',
        string $difficulty = '',
        string $userstatus = 'all',
        int $tagid = 0,
        array $tagids = [],
        array $companyids = [],
        int $page = 0,
        int $perpage = 20
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/learnlogic:view', $context);

        $params = self::validate_parameters(self::execute_parameters(), compact(
            'search', 'difficulty', 'userstatus', 'tagid', 'tagids', 'companyids', 'page', 'perpage'
        ));

        $data = catalog::list_problems((int) $USER->id, $params);
        $stats = gamification::user_stats((int) $USER->id);
        $stats['total'] = (int) ($data['counts']['all'] ?? 0);

        return [
            'problems' => $data['problems'],
            'total' => $data['total'],
            'page' => $data['page'],
            'perpage' => $data['perpage'],
            'counts' => $data['counts'],
            'stats' => $stats,
            'tags' => catalog::all_tags('topic'),
            'companies' => catalog::all_tags('company'),
        ];
    }

    public static function execute_returns(): external_single_structure {
        $tag = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'kind', VALUE_DEFAULT, 'topic'),
            'count' => new external_value(PARAM_INT, 'count', VALUE_DEFAULT, 0),
        ]);
        $problem = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'number' => new external_value(PARAM_INT, 'display number'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'slug' => new external_value(PARAM_TEXT, 'slug'),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'difficulty'),
            'status' => new external_value(PARAM_ALPHA, 'status'),
            'userstatus' => new external_value(PARAM_ALPHANUMEXT, 'userstatus'),
            'battled' => new external_value(PARAM_BOOL, 'Won in NexBattleGround'),
            'tags' => new external_multiple_structure($tag),
            'companies' => new external_multiple_structure($tag),
            'url' => new external_value(PARAM_URL, 'url'),
            'solvers' => new external_value(PARAM_INT, 'solvers'),
            'acceptance' => new external_value(PARAM_INT, 'acceptance percent'),
            'estimateminutes' => new external_value(PARAM_INT, 'estimate minutes'),
        ]);
        return new external_single_structure([
            'problems' => new external_multiple_structure($problem),
            'total' => new external_value(PARAM_INT, 'total'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'perpage'),
            'counts' => new external_single_structure([
                'all' => new external_value(PARAM_INT, 'all'),
                'completed' => new external_value(PARAM_INT, 'completed'),
                'inprogress' => new external_value(PARAM_INT, 'inprogress'),
                'notstarted' => new external_value(PARAM_INT, 'notstarted'),
                'battled' => new external_value(PARAM_INT, 'battled'),
            ]),
            'stats' => new external_single_structure([
                'xp' => new external_value(PARAM_INT, 'xp'),
                'streak' => new external_value(PARAM_INT, 'streak'),
                'longest' => new external_value(PARAM_INT, 'longest'),
                'rank' => new external_value(PARAM_INT, 'rank'),
                'solved' => new external_value(PARAM_INT, 'solved'),
                'total' => new external_value(PARAM_INT, 'total ready problems'),
            ]),
            'tags' => new external_multiple_structure($tag),
            'companies' => new external_multiple_structure($tag),
        ]);
    }
}
