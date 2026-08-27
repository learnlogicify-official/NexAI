<?php
namespace local_nexstack\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

/**
 * Boot / control remote sandbox sessions.
 */
class sandbox_session extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'action' => new \external_value(PARAM_ALPHA, 'boot|sync|exec|status|destroy'),
            'missionid' => new \external_value(PARAM_INT, 'Mission id'),
            'sessionid' => new \external_value(PARAM_RAW, 'Sandbox session id', VALUE_DEFAULT, ''),
            'filesjson' => new \external_value(PARAM_RAW, 'JSON object of path=>content', VALUE_DEFAULT, '{}'),
            'cmd' => new \external_value(PARAM_TEXT, 'Exec command', VALUE_DEFAULT, ''),
            'argsjson' => new \external_value(PARAM_RAW, 'JSON array of args', VALUE_DEFAULT, '[]'),
        ]);
    }

    public static function execute(string $action, int $missionid, string $sessionid = '', string $filesjson = '{}',
            string $cmd = '', string $argsjson = '[]'): array {
        global $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexstack:attempt', $context);

        if (!\local_nexstack\local\sandbox::enabled()) {
            throw new \moodle_exception('sandboxdisabled', 'local_nexstack');
        }

        $mission = \local_nexstack\local\missions::get($missionid);
        if (!$mission || $mission->status !== 'ready') {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $files = json_decode($filesjson, true);
        if (!is_array($files)) {
            $files = [];
        }
        $args = json_decode($argsjson, true);
        if (!is_array($args)) {
            $args = [];
        }

        $action = strtolower($action);
        if ($action === 'boot') {
            // Prefer latest workspace files when empty payload.
            if (!$files) {
                $ws = \local_nexstack\local\missions::ensure_workspace((int) $USER->id, $missionid);
                $files = \local_nexstack\local\missions::decode_files($ws->filesjson);
                if (!$files) {
                    $files = \local_nexstack\local\missions::decode_files($mission->scaffoldjson);
                }
            }
            $resp = \local_nexstack\local\sandbox::boot_session((int) $USER->id, $missionid, $files);
            return self::pack($resp);
        }
        if ($sessionid === '') {
            throw new \invalid_parameter_exception('sessionid required');
        }
        if ($action === 'sync') {
            return self::pack(\local_nexstack\local\sandbox::sync_files($sessionid, $files));
        }
        if ($action === 'exec') {
            if ($cmd === '') {
                throw new \invalid_parameter_exception('cmd required');
            }
            $result = \local_nexstack\local\sandbox::exec($sessionid, $cmd, $args);
            return [
                'ok' => (($result['exitCode'] ?? 1) === 0),
                'sessionid' => $sessionid,
                'status' => 'exec',
                'previewurl' => '',
                'error' => '',
                'logs' => (string) (($result['stdout'] ?? '') . ($result['stderr'] ?? '')),
                'exitcode' => (int) ($result['exitCode'] ?? 0),
            ];
        }
        if ($action === 'status') {
            return self::pack(\local_nexstack\local\sandbox::status($sessionid));
        }
        if ($action === 'destroy') {
            \local_nexstack\local\sandbox::destroy($sessionid);
            return [
                'ok' => true,
                'sessionid' => $sessionid,
                'status' => 'destroyed',
                'previewurl' => '',
                'error' => '',
                'logs' => '',
                'exitcode' => 0,
            ];
        }
        throw new \invalid_parameter_exception('Unknown action');
    }

    protected static function pack(array $resp): array {
        return [
            'ok' => (($resp['status'] ?? '') === 'running' || ($resp['status'] ?? '') === 'starting'),
            'sessionid' => (string) ($resp['id'] ?? ''),
            'status' => (string) ($resp['status'] ?? ''),
            'previewurl' => (string) ($resp['previewUrl'] ?? ''),
            'error' => (string) ($resp['error'] ?? ''),
            'logs' => (string) ($resp['logs'] ?? ''),
            'exitcode' => 0,
        ];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'ok' => new \external_value(PARAM_BOOL, ''),
            'sessionid' => new \external_value(PARAM_RAW, ''),
            'status' => new \external_value(PARAM_TEXT, ''),
            'previewurl' => new \external_value(PARAM_RAW, ''),
            'error' => new \external_value(PARAM_RAW, ''),
            'logs' => new \external_value(PARAM_RAW, ''),
            'exitcode' => new \external_value(PARAM_INT, ''),
        ]);
    }
}
