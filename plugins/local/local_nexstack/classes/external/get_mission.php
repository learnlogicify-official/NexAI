<?php
namespace local_nexstack\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_multiple_structure;
use local_nexstack\local\missions;

/**
 * Load mission + workspace for the studio.
 */
class get_mission extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'missionid' => new external_value(PARAM_INT, 'Mission id'),
        ]);
    }

    public static function execute($missionid) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['missionid' => $missionid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexstack:attempt', $context);

        $mission = missions::get((int) $params['missionid']);
        if (!$mission || $mission->status !== 'ready') {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $ws = missions::ensure_workspace((int) $USER->id, (int) $mission->id);
        $files = missions::decode_files($ws->filesjson);
        if (!$files) {
            $files = missions::decode_files($mission->scaffoldjson);
            if ($files) {
                $ws = missions::save_files((int) $USER->id, (int) $mission->id, $files);
            }
        }
        $completed = [];
        if ($ws->completedsteps !== '') {
            foreach (explode(',', $ws->completedsteps) as $s) {
                if ($s !== '') {
                    $completed[] = (int) $s;
                }
            }
        }

        $filelist = [];
        foreach ($files as $path => $content) {
            $filelist[] = ['path' => $path, 'content' => $content];
        }

        return [
            'id' => (int) $mission->id,
            'name' => (string) $mission->name,
            'slug' => (string) $mission->slug,
            'track' => (string) $mission->track,
            'difficulty' => (string) $mission->difficulty,
            'runtime' => (string) $mission->runtime,
            'summary' => (string) ($mission->summary ?? ''),
            'briefmd' => (string) ($mission->briefmd ?? ''),
            'stepsjson' => (string) ($mission->stepsjson ?? '[]'),
            'files' => $filelist,
            'activestep' => (int) $ws->activestep,
            'completedcsv' => (string) ($ws->completedsteps ?? ''),
            'status' => (string) $ws->status,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, ''),
            'name' => new external_value(PARAM_TEXT, ''),
            'slug' => new external_value(PARAM_ALPHANUMEXT, ''),
            'track' => new external_value(PARAM_ALPHANUMEXT, ''),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, ''),
            'runtime' => new external_value(PARAM_ALPHANUMEXT, ''),
            'summary' => new external_value(PARAM_RAW, ''),
            'briefmd' => new external_value(PARAM_RAW, ''),
            'stepsjson' => new external_value(PARAM_RAW, ''),
            'files' => new external_multiple_structure(new external_single_structure([
                'path' => new external_value(PARAM_RAW, ''),
                'content' => new external_value(PARAM_RAW, ''),
            ])),
            'activestep' => new external_value(PARAM_INT, ''),
            'completedcsv' => new external_value(PARAM_TEXT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, ''),
        ]);
    }
}
