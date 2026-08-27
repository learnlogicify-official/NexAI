<?php
namespace local_nexinterview\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_nexinterview\local\resume;

/**
 * Extract resume text from paste or PDF (base64).
 */
class extract_resume extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'text' => new external_value(PARAM_RAW, 'Pasted resume text', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Draft file item id', VALUE_DEFAULT, 0),
            'pdfbase64' => new external_value(PARAM_RAW, 'PDF as base64 (ASCII-safe)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($text = '', $draftitemid = 0, $pdfbase64 = '') {
        global $USER;

        // pdfbase64 is ASCII — keep as-is until after validation; sanitize paste first.
        $text = resume::fix_utf8((string) $text);
        $pdfbase64 = preg_replace('/\s+/', '', (string) $pdfbase64) ?? '';
        // Only allow base64 alphabet so PARAM_RAW UTF-8 never trips.
        $pdfbase64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $pdfbase64) ?? '';

        $params = self::validate_parameters(self::execute_parameters(), [
            'text' => $text,
            'draftitemid' => (int) $draftitemid,
            'pdfbase64' => $pdfbase64,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nexinterview:attempt', $context);

        $out = resume::normalize((string) $params['text']);

        if ($params['pdfbase64'] !== '') {
            $pdftext = resume::from_pdf_base64((string) $params['pdfbase64']);
            if ($pdftext !== '') {
                $out = trim($out . "\n" . $pdftext);
            }
        }

        if ($out === '' && (int) $params['draftitemid'] > 0) {
            $out = resume::from_draft_itemid((int) $USER->id, (int) $params['draftitemid']);
        }

        $len = function_exists('mb_strlen') ? mb_strlen($out, 'UTF-8') : strlen($out);

        return [
            'ok' => $out !== '',
            'text' => $out,
            'chars' => (int) $len,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Extracted'),
            'text' => new external_value(PARAM_RAW, 'Resume text'),
            'chars' => new external_value(PARAM_INT, 'Character count'),
        ]);
    }
}
