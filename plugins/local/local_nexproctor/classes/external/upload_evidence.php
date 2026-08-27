<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * Upload evidence (base64).
 */
class upload_evidence extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Session id'),
            'eventid' => new external_value(PARAM_INT, 'Related event id', VALUE_DEFAULT, 0),
            'filearea' => new external_value(PARAM_ALPHA, 'File area'),
            'mimetype' => new external_value(PARAM_TEXT, 'MIME type', VALUE_DEFAULT, 'image/jpeg'),
            'data' => new external_value(PARAM_RAW, 'Base64 data'),
            'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Optional event to create', VALUE_DEFAULT, ''),
            'severity' => new external_value(PARAM_ALPHA, 'Severity if creating event', VALUE_DEFAULT, 'warning'),
        ]);
    }

    public static function execute(
        int $sessionid,
        int $eventid,
        string $filearea,
        string $mimetype,
        string $data,
        string $eventtype = '',
        string $severity = 'warning'
    ): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'eventid' => $eventid,
            'filearea' => $filearea,
            'mimetype' => $mimetype,
            'data' => $data,
            'eventtype' => $eventtype,
            'severity' => $severity,
        ]);

        $session = $DB->get_record('local_nexproctor_sessions', ['id' => $params['sessionid']], '*', MUST_EXIST);
        if ((int) $session->userid !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $cm = get_coursemodule_from_id('quiz', $session->cmid, 0, false, MUST_EXIST);
        self::validate_context(context_module::instance($cm->id));

        $eid = (int) $params['eventid'];
        if ($eid === 0 && $params['eventtype'] !== '') {
            $penalties = local_nexproctor_penalties();
            $pen = $penalties[$params['eventtype']] ?? 0;
            $eid = (int) $DB->insert_record('local_nexproctor_events', (object) [
                'sessionid' => $params['sessionid'],
                'eventtype' => $params['eventtype'],
                'severity' => $params['severity'],
                'payload' => '',
                'penalty' => $pen,
                'timecreated' => time(),
            ]);
        }

        $evidenceid = local_nexproctor_store_evidence(
            $params['sessionid'],
            $eid,
            $params['filearea'],
            $params['mimetype'],
            $params['data']
        );
        $score = local_nexproctor_recalc_trust($params['sessionid']);

        return [
            'evidenceid' => $evidenceid,
            'eventid' => $eid,
            'trustscore' => $score,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'evidenceid' => new external_value(PARAM_INT, 'Evidence id'),
            'eventid' => new external_value(PARAM_INT, 'Event id'),
            'trustscore' => new external_value(PARAM_INT, 'Trust score'),
        ]);
    }
}
