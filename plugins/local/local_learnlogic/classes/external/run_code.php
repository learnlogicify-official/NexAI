<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: run sample or custom tests.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_learnlogic\local\runner;

/**
 * Run (samples or custom) — no XP / no submission.
 */
class run_code extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'problemid' => new external_value(PARAM_INT, 'Problem id'),
            'language' => new external_value(PARAM_TEXT, 'Language key'),
            'code' => new external_value(PARAM_RAW, 'Source code'),
            'mode' => new external_value(PARAM_ALPHA, 'sample or custom', VALUE_DEFAULT, 'sample'),
            'stdin' => new external_value(PARAM_RAW, 'Custom stdin', VALUE_DEFAULT, ''),
            'expected' => new external_value(PARAM_RAW, 'Custom expected', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $problemid,
        string $language,
        string $code,
        string $mode = 'sample',
        string $stdin = '',
        string $expected = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/learnlogic:attempt', $context);

        $params = self::validate_parameters(self::execute_parameters(), compact(
            'problemid', 'language', 'code', 'mode', 'stdin', 'expected'
        ));
        if ($params['code'] === '') {
            throw new \invalid_parameter_exception('Empty code');
        }
        $mode = ($params['mode'] === 'custom') ? 'custom' : 'sample';

        return runner::execute(
            (int) $params['problemid'],
            (string) $params['language'],
            (string) $params['code'],
            $mode,
            (string) $params['stdin'],
            (string) $params['expected']
        );
    }

    public static function execute_returns(): external_single_structure {
        return self::result_structure();
    }

    /**
     * Shared run/submit result shape.
     *
     * @return external_single_structure
     */
    public static function result_structure(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'success'),
            'message' => new external_value(PARAM_TEXT, 'message'),
            'results' => new external_multiple_structure(new external_single_structure([
                'input' => new external_value(PARAM_RAW, 'input'),
                'expected' => new external_value(PARAM_RAW, 'expected'),
                'actual' => new external_value(PARAM_RAW, 'actual'),
                'isCorrect' => new external_value(PARAM_BOOL, 'isCorrect'),
                'stderr' => new external_value(PARAM_RAW, 'stderr'),
                'status' => new external_value(PARAM_TEXT, 'status'),
                'display' => new external_value(PARAM_ALPHA, 'display'),
            ])),
            'allPassed' => new external_value(PARAM_BOOL, 'allPassed'),
            'passed' => new external_value(PARAM_INT, 'passed'),
            'total' => new external_value(PARAM_INT, 'total'),
            'submissionId' => new external_value(PARAM_INT, 'submissionId', VALUE_DEFAULT, 0),
            'xpAwarded' => new external_value(PARAM_INT, 'xpAwarded', VALUE_DEFAULT, 0),
            'statusLabel' => new external_value(PARAM_TEXT, 'statusLabel', VALUE_DEFAULT, ''),
        ]);
    }
}
