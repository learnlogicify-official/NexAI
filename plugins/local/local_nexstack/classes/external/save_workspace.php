<?php
namespace local_nexstack\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_nexstack\local\missions;

/**
 * Autosave workspace files (JSON map path→content).
 */
class save_workspace extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
            'filesjson' => new external_value(PARAM_RAW, 'JSON object of path → content'),
        ]);
    }

    public static function execute($missionid, $filesjson) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'missionid' => $missionid,
            'filesjson' => $filesjson,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexstack:attempt', $context);

        $mission = missions::get((int) $params['missionid']);
        if (!$mission) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $decoded = json_decode((string) $params['filesjson'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('filesjson must be a JSON object');
        }
        $ws = missions::save_files((int) $USER->id, (int) $mission->id, $decoded);
        return [
            'ok' => true,
            'timemodified' => (int) $ws->timemodified,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, ''),
            'timemodified' => new external_value(PARAM_INT, ''),
        ]);
    }
}
