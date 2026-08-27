<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\catalog;

class get_activities extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'skill' => new external_value(PARAM_ALPHANUMEXT, 'Skill', VALUE_DEFAULT, ''),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'Difficulty', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Search', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 24),
        ]);
    }

    public static function execute(
        string $skill = '',
        string $difficulty = '',
        string $search = '',
        int $page = 0,
        int $perpage = 24
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'skill', 'difficulty', 'search', 'page', 'perpage'
        ));
        $data = catalog::list_activities(
            (int) $USER->id,
            (string) $params['skill'],
            (string) $params['difficulty'],
            (string) $params['search'],
            (int) $params['page'],
            (int) $params['perpage']
        );
        foreach ($data['items'] as &$item) {
            $item['score'] = $item['score'] === null ? -1 : (float) $item['score'];
        }
        unset($item);
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'total'),
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'skill' => new external_value(PARAM_TEXT, 'skill'),
                'difficulty' => new external_value(PARAM_TEXT, 'diff'),
                'title' => new external_value(PARAM_TEXT, 'title'),
                'tags' => new external_value(PARAM_TEXT, 'tags'),
                'userstatus' => new external_value(PARAM_TEXT, 'status'),
                'score' => new external_value(PARAM_FLOAT, 'score or -1'),
                'url' => new external_value(PARAM_URL, 'url'),
            ])),
        ]);
    }
}
