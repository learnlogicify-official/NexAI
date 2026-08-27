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
 * Persist client-side step check result.
 */
class check_step extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
            'stepid' => new external_value(PARAM_INT, 'Step index'),
            'passed' => new external_value(PARAM_BOOL, 'Whether checks passed'),
            'completedcsv' => new external_value(PARAM_TEXT, 'Comma-separated completed step ids', VALUE_DEFAULT, ''),
            'detail' => new external_value(PARAM_RAW, 'Optional check detail JSON', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($missionid, $stepid, $passed, $completedcsv = '', $detail = '') {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'missionid' => $missionid,
            'stepid' => $stepid,
            'passed' => $passed,
            'completedcsv' => $completedcsv,
            'detail' => $detail,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexstack:attempt', $context);

        $mission = missions::get((int) $params['missionid']);
        if (!$mission) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $completed = [];
        foreach (explode(',', (string) $params['completedcsv']) as $s) {
            $s = trim($s);
            if ($s !== '' && ctype_digit($s)) {
                $completed[] = (int) $s;
            }
        }
        $ws = missions::mark_step(
            (int) $USER->id,
            (int) $mission->id,
            (int) $params['stepid'],
            (bool) $params['passed'],
            $completed
        );
        return [
            'ok' => true,
            'passed' => (bool) $params['passed'],
            'activestep' => (int) $ws->activestep,
            'completedcsv' => (string) $ws->completedsteps,
            'status' => (string) $ws->status,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, ''),
            'passed' => new external_value(PARAM_BOOL, ''),
            'activestep' => new external_value(PARAM_INT, ''),
            'completedcsv' => new external_value(PARAM_TEXT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, ''),
        ]);
    }
}
