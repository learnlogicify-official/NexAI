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

class get_activity extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'activityid' => new external_value(PARAM_INT, 'Activity id'),
        ]);
    }

    public static function execute(int $activityid): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['activityid' => $activityid]);
        $data = catalog::get_activity((int) $params['activityid'], (int) $USER->id);
        $data['questionsJson'] = json_encode($data['questions']);
        unset($data['questions']);
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'skill' => new external_value(PARAM_TEXT, 'skill'),
            'difficulty' => new external_value(PARAM_TEXT, 'diff'),
            'title' => new external_value(PARAM_TEXT, 'title'),
            'body' => new external_value(PARAM_RAW, 'body'),
            'prompt' => new external_value(PARAM_RAW, 'prompt'),
            'audiourl' => new external_value(PARAM_RAW, 'audio'),
            'passmark' => new external_value(PARAM_INT, 'pass'),
            'minwords' => new external_value(PARAM_INT, 'min words'),
            'timelimit' => new external_value(PARAM_INT, 'limit'),
            'tags' => new external_value(PARAM_TEXT, 'tags'),
            'questionsJson' => new external_value(PARAM_RAW, 'questions'),
            'userstatus' => new external_value(PARAM_TEXT, 'status'),
            'catalogurl' => new external_value(PARAM_URL, 'catalog'),
        ]);
    }
}
