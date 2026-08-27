<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Run a custom CodeRunner testcase via the question Twig template.
 *
 * @package    local_llassessment
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_llassessment\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;
use moodle_exception;
use qtype_coderunner_jobrunner;
use qtype_coderunner_question;
use qtype_coderunner_testing_outcome;

/**
 * AJAX external: template-aware custom test runner.
 */
class run_custom_test extends external_api {

    /** Max custom runs per user per hour (session throttle). */
    private const MAX_HOURLY = 120;

    /** Soft length caps (bytes). */
    private const MAX_ANSWER = 200000;
    private const MAX_FIELD = 50000;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slot' => new external_value(PARAM_INT, 'Question slot'),
            'answer' => new external_value(PARAM_RAW, 'Student source code'),
            'stdin' => new external_value(PARAM_RAW, 'Custom stdin', VALUE_DEFAULT, ''),
            'testcode' => new external_value(PARAM_RAW, 'Custom test code (function-style)', VALUE_DEFAULT, ''),
            'expected' => new external_value(PARAM_RAW, 'Optional expected output', VALUE_DEFAULT, ''),
            'language' => new external_value(PARAM_TEXT, 'Multilang answer language', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when the sandbox run completed without sandbox/syntax abort'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'ok|syntax|runtime|sandbox|error|missing'),
            'output' => new external_value(PARAM_RAW, 'Program stdout / got'),
            'expected' => new external_value(PARAM_RAW, 'Expected used for compare'),
            'matched' => new external_value(PARAM_BOOL, 'Whether output matched expected (false if no expected)'),
            'compared' => new external_value(PARAM_BOOL, 'Whether an expected value was supplied'),
            'stderr' => new external_value(PARAM_RAW, 'Error / stderr text'),
            'cmpinfo' => new external_value(PARAM_RAW, 'Compiler info when relevant'),
            'message' => new external_value(PARAM_TEXT, 'Short status message'),
            'iscombinator' => new external_value(PARAM_BOOL, 'Whether the question uses a combinator template'),
        ]);
    }

    /**
     * Run one synthetic testcase through the CodeRunner Twig template.
     *
     * @param int $attemptid
     * @param int $cmid
     * @param int $slot
     * @param string $answer
     * @param string $stdin
     * @param string $testcode
     * @param string $expected
     * @param string $language
     * @return array
     */
    public static function execute(
        int $attemptid,
        int $cmid,
        int $slot,
        string $answer,
        string $stdin = '',
        string $testcode = '',
        string $expected = '',
        string $language = ''
    ): array {
        global $CFG, $SESSION;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'cmid' => $cmid,
            'slot' => $slot,
            'answer' => $answer,
            'stdin' => $stdin,
            'testcode' => $testcode,
            'expected' => $expected,
            'language' => $language,
        ]);

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        // Ensure CodeRunner classes are loadable.
        if (!class_exists('qtype_coderunner_question', false)) {
            $crquestion = $CFG->dirroot . '/question/type/coderunner/question.php';
            if (is_readable($crquestion)) {
                require_once($crquestion);
            }
        }
        if (!class_exists('qtype_coderunner_jobrunner', false)) {
            $crrunner = $CFG->dirroot . '/question/type/coderunner/classes/jobrunner.php';
            if (is_readable($crrunner)) {
                require_once($crrunner);
            }
        }

        if (!class_exists('qtype_coderunner_question') || !class_exists('qtype_coderunner_jobrunner')) {
            throw new moodle_exception('customtestnocoderunner', 'local_llassessment');
        }

        $attemptobj = \quiz_create_attempt_handling_errors($params['attemptid'], $params['cmid']);
        $cm = $attemptobj->get_cm();
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:attempt', $context);
        require_capability('local/llassessment:runcustomtest', $context);

        if (!$attemptobj->is_own_attempt() && !$attemptobj->is_preview_user()) {
            throw new moodle_exception('notyourattempt', 'quiz');
        }
        if ($attemptobj->is_finished()) {
            throw new moodle_exception('customtestfinished', 'local_llassessment');
        }

        self::throttle();

        $answer = (string) $params['answer'];
        $stdin = (string) $params['stdin'];
        $testcode = (string) $params['testcode'];
        $expected = (string) $params['expected'];
        $language = trim((string) $params['language']);

        if ($answer === '') {
            throw new moodle_exception('customtestemptyanswer', 'local_llassessment');
        }
        if (strlen($answer) > self::MAX_ANSWER
            || strlen($stdin) > self::MAX_FIELD
            || strlen($testcode) > self::MAX_FIELD
            || strlen($expected) > self::MAX_FIELD) {
            throw new moodle_exception('customtesttoolarge', 'local_llassessment');
        }

        try {
            $qa = $attemptobj->get_question_attempt($params['slot']);
        } catch (\Throwable $e) {
            throw new moodle_exception('customtestbadslot', 'local_llassessment');
        }

        $question = $qa->get_question();
        if (!($question instanceof qtype_coderunner_question)) {
            throw new moodle_exception('customtestnotcoderunner', 'local_llassessment');
        }

        // Synthetic SHOW testcase — same shape as Precheck Empty, with user fields.
        $testcase = (object) [
            'testtype' => 0,
            'testcode' => $testcode,
            'stdin' => $stdin,
            'expected' => $expected,
            'extra' => '',
            'display' => 'SHOW',
            'useasexample' => 0,
            'hiderestiffail' => 0,
            'mark' => 1.0,
        ];

        $iscombinator = false;
        if (method_exists($question, 'get_is_combinator')) {
            $iscombinator = (bool) $question->get_is_combinator();
        } else if (!empty($question->iscombinatortemplate)) {
            $iscombinator = true;
        }

        try {
            $runner = new qtype_coderunner_jobrunner();
            /** @var qtype_coderunner_testing_outcome $outcome */
            $outcome = $runner->run_tests(
                $question,
                $answer,
                [],
                [$testcase],
                false,
                $language,
                false
            );
        } catch (\Throwable $e) {
            return self::pack(
                false,
                'error',
                '',
                $expected,
                false,
                $expected !== '',
                $e->getMessage(),
                '',
                get_string('customtestrunfailed', 'local_llassessment'),
                $iscombinator
            );
        }

        return self::outcome_to_result($outcome, $expected, $iscombinator);
    }

    /**
     * @param qtype_coderunner_testing_outcome $outcome
     * @param string $expected
     * @param bool $iscombinator
     * @return array
     */
    private static function outcome_to_result($outcome, string $expected, bool $iscombinator): array {
        $compared = ($expected !== '');
        $stderr = '';
        $cmpinfo = '';
        $output = '';
        $matched = false;
        $ok = false;
        $status = 'error';
        $message = get_string('customtestrunfailed', 'local_llassessment');

        if ($outcome->run_failed() || $outcome->invalid()) {
            $status = 'sandbox';
            $stderr = (string) ($outcome->errormessage ?? '');
            $message = $stderr !== '' ? $stderr : get_string('customtestsandbox', 'local_llassessment');
            return self::pack(false, $status, '', $expected, false, $compared, $stderr, '', $message, $iscombinator);
        }

        if ($outcome->has_syntax_error()) {
            $status = 'syntax';
            $cmpinfo = (string) ($outcome->errormessage ?? '');
            $message = get_string('customtestsyntax', 'local_llassessment');
            return self::pack(false, $status, '', $expected, false, $compared, '', $cmpinfo, $message, $iscombinator);
        }

        if ($outcome->combinator_error()) {
            $status = 'error';
            $stderr = (string) ($outcome->errormessage ?? '');
            $message = $stderr !== '' ? $stderr : get_string('customtestcombinator', 'local_llassessment');
            return self::pack(false, $status, '', $expected, false, $compared, $stderr, '', $message, $iscombinator);
        }

        if (!empty($outcome->testresults)) {
            $tr = $outcome->testresults[0];
            $output = isset($tr->got) ? (string) $tr->got : '';
            if (isset($tr->stderr) && $tr->stderr !== '' && $tr->stderr !== null) {
                $stderr = (string) $tr->stderr;
            }
            if ($compared && isset($tr->iscorrect)) {
                $matched = (bool) $tr->iscorrect;
            } else if ($compared) {
                $matched = self::outputs_match($output, $expected);
            }

            $looksruntime = !empty($tr->iserror)
                || (is_string($output) && str_starts_with(ltrim($output), '***'));
            if ($looksruntime && !$matched) {
                $status = 'runtime';
                $ok = false;
                $message = get_string('customtestruntime', 'local_llassessment');
            } else {
                $ok = true;
                $status = 'ok';
                if ($compared) {
                    $message = $matched
                        ? get_string('customtestpassed', 'local_llassessment')
                        : get_string('customtestfailed', 'local_llassessment');
                } else {
                    $message = get_string('customtestdone', 'local_llassessment');
                }
            }
        } else if (method_exists($outcome, 'get_raw_output')) {
            try {
                $output = (string) $outcome->get_raw_output();
                $ok = true;
                $status = 'ok';
                $matched = $compared && self::outputs_match($output, $expected);
                $message = $compared
                    ? ($matched
                        ? get_string('customtestpassed', 'local_llassessment')
                        : get_string('customtestfailed', 'local_llassessment'))
                    : get_string('customtestdone', 'local_llassessment');
            } catch (\Throwable $e) {
                $status = 'error';
                $stderr = $e->getMessage();
                $message = get_string('customtestrunfailed', 'local_llassessment');
            }
        }

        return self::pack($ok, $status, $output, $expected, $matched, $compared, $stderr, $cmpinfo, $message, $iscombinator);
    }

    /**
     * @param bool $ok
     * @param string $status
     * @param string $output
     * @param string $expected
     * @param bool $matched
     * @param bool $compared
     * @param string $stderr
     * @param string $cmpinfo
     * @param string $message
     * @param bool $iscombinator
     * @return array
     */
    private static function pack(
        bool $ok,
        string $status,
        string $output,
        string $expected,
        bool $matched,
        bool $compared,
        string $stderr,
        string $cmpinfo,
        string $message,
        bool $iscombinator
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'output' => $output,
            'expected' => $expected,
            'matched' => $matched,
            'compared' => $compared,
            'stderr' => $stderr,
            'cmpinfo' => $cmpinfo,
            'message' => \core_text::substr($message, 0, 255),
            'iscombinator' => $iscombinator,
        ];
    }

    /**
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function outputs_match(string $a, string $b): bool {
        $norm = static function (string $s): string {
            $s = str_replace("\r\n", "\n", $s);
            $s = preg_replace('/[ \t]+$/m', '', $s) ?? $s;
            return trim(rtrim($s, "\n"));
        };
        return $norm($a) === $norm($b);
    }

    /**
     * Simple per-session hourly throttle.
     */
    private static function throttle(): void {
        global $SESSION;
        $now = time();
        $bucket = [];
        if (!empty($SESSION->local_llassessment_custom_runs)
            && is_array($SESSION->local_llassessment_custom_runs)) {
            $bucket = $SESSION->local_llassessment_custom_runs;
        }
        $bucket = array_values(array_filter($bucket, static function ($t) use ($now) {
            return is_int($t) && ($now - $t) < HOURSECS;
        }));
        if (count($bucket) >= self::MAX_HOURLY) {
            throw new moodle_exception('customtestrate', 'local_llassessment');
        }
        $bucket[] = $now;
        $SESSION->local_llassessment_custom_runs = $bucket;
    }
}
