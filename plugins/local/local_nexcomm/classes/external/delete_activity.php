<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

class delete_activity extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Activity id'),
        ]);
    }

    public static function execute(int $id): array {
        global $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:manage', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $id = (int) $params['id'];
        $DB->delete_records('local_nexcomm_question', ['activityid' => $id]);
        $DB->delete_records('local_nexcomm_activity', ['id' => $id]);
        return ['ok' => true, 'message' => get_string('activitydeleted', 'local_nexcomm')];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'ok'),
            'message' => new external_value(PARAM_TEXT, 'msg'),
        ]);
    }
}
