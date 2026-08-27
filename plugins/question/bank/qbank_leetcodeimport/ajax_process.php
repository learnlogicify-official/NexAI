<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: process one LeetCode → CodeRunner import (always JSON).
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

// Capture fatals as JSON when possible.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $err['type'], $fatal, true)) {
        return;
    }
    // If something already printed HTML, still try to append JSON marker for the client.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'success' => false,
        'error' => 'PHP fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
        'data' => [
            'ok' => false,
            'status' => 'failed',
            'name' => '',
            'detail' => $err['message'],
            'input' => '',
            'xml' => '',
            'steps' => [],
        ],
    ]);
});

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/editlib.php');

use qbank_leetcodeimport\local\importer;
use qbank_leetcodeimport\local\prototypes;

/**
 * Emit JSON and exit (clears buffers so Moodle HTML cannot prefix the payload).
 *
 * @param array $payload
 * @param int $status
 */
function qbank_leetcodeimport_json_exit(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    die;
}

/**
 * @param Throwable $e
 * @param string $input
 */
function qbank_leetcodeimport_json_exception(Throwable $e, string $input = ''): void {
    $msg = $e->getMessage();
    if ($e instanceof moodle_exception) {
        // Prefer localized errorinfo when present.
        if (!empty($e->errorcode)) {
            try {
                $msg = get_string($e->errorcode, $e->module ?? 'error', $e->a ?? null);
            } catch (Throwable $ignored) {
                $msg = $e->getMessage();
            }
            if (!empty($e->debuginfo) && is_string($e->debuginfo)) {
                $msg .= ' — ' . $e->debuginfo;
            }
        }
    }
    qbank_leetcodeimport_json_exit([
        'success' => false,
        'error' => $msg,
        'data' => [
            'ok' => false,
            'status' => 'failed',
            'name' => '',
            'detail' => $msg,
            'input' => $input,
            'xml' => '',
            'steps' => [],
        ],
    ], 200);
}

$problem = '';

try {
    require_login();
    require_sesskey();

    if (!core_plugin_manager::instance()->get_plugin_info('qbank_leetcodeimport')) {
        throw new moodle_exception('noproblems', 'qbank_leetcodeimport');
    }
    // Soft-check enable flag when helper exists.
    if (class_exists('\core_question\local\bank\helper')
            && method_exists('\core_question\local\bank\helper', 'require_plugin_enabled')) {
        core_question\local\bank\helper::require_plugin_enabled('qbank_leetcodeimport');
    }

    $problem = required_param('problem', PARAM_RAW);
    $problem = trim($problem);
    $n = optional_param('n', 1, PARAM_INT);
    $total = optional_param('total', 1, PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $cat = required_param('cat', PARAM_RAW);
    $optionsjson = required_param('options', PARAM_RAW);

    if ($problem === '') {
        throw new invalid_parameter_exception('Empty problem id');
    }

    $options = json_decode($optionsjson, true);
    if (!is_array($options)) {
        throw new invalid_parameter_exception(
            'Invalid options JSON: ' . substr(json_last_error_msg(), 0, 120)
        );
    }

    $catparts = explode(',', $cat, 2);
    $catid = (int) ($catparts[0] ?? 0);
    $catcontext = (int) ($catparts[1] ?? 0);
    if ($catid <= 0 || $catcontext <= 0) {
        throw new moodle_exception('nocategory', 'question');
    }

    $category = $DB->get_record('question_categories', ['id' => $catid], '*', MUST_EXIST);
    if ((int) $category->contextid !== $catcontext) {
        throw new moodle_exception('nocategory', 'question');
    }
    $categorycontext = context::instance_by_id($category->contextid);
    $category->context = $categorycontext;

    $contexts = new core_question\local\bank\question_edit_contexts($categorycontext);
    $contexts->require_one_edit_tab_cap('import');

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    // Ensure course access without re-emitting HTML login pages.
    require_login($course, false);

    $crtype = (string) ($options['coderunnertype'] ?? '');
    $catalogue = prototypes::catalogue();
    if ($crtype !== '' && !empty($catalogue['duplicates'][$crtype]) && empty($options['dryrun'])) {
        throw new moodle_exception('duplicatetprototype_block', 'qbank_leetcodeimport', '', $crtype);
    }

    $options['usestdin'] = !empty($options['usestdin']) || prototypes::uses_stdin($crtype);
    $options['forcestdin'] = !empty($options['usestdin']);

    // Release session lock before slow LeetCode/OpenAI I/O.
    \core\session\manager::write_close();

    $pipe = new importer();
    $result = $pipe->process_one(
        $problem,
        $options,
        $category,
        $contexts->having_one_edit_tab_cap('import'),
        $course,
        max(1, $n),
        max(1, $total)
    );

    qbank_leetcodeimport_json_exit([
        'success' => true,
        'data' => $result,
    ]);
} catch (Throwable $e) {
    qbank_leetcodeimport_json_exception($e, $problem);
}
