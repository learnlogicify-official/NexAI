<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: submit all tests + award XP.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\gamification;
use local_nexcodelab\local\runner;

/**
 * Submit against all tests.
 */
class submit_code extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'problemid' => new external_value(PARAM_INT, 'Problem id'),
            'language' => new external_value(PARAM_TEXT, 'Language key'),
            'code' => new external_value(PARAM_RAW, 'Source code'),
        ]);
    }

    public static function execute(int $problemid, string $language, string $code): array {
        global $DB, $USER;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:attempt', $context);

        $params = self::validate_parameters(self::execute_parameters(), compact('problemid', 'language', 'code'));
        if ($params['code'] === '') {
            throw new \invalid_parameter_exception('Empty code');
        }

        $problem = $DB->get_record('local_nexcodelab_problem', [
            'id' => $params['problemid'],
            'status' => 'ready',
        ], '*', MUST_EXIST);

        $result = runner::execute(
            (int) $params['problemid'],
            (string) $params['language'],
            (string) $params['code'],
            'all'
        );

        $status = !empty($result['allPassed']) ? 'ACCEPTED' : 'WRONG_ANSWER';
        if (!empty($result['message'])) {
            $status = 'RUNTIME_ERROR';
        }

        $sid = $DB->insert_record('local_nexcodelab_submission', (object) [
            'userid' => (int) $USER->id,
            'problemid' => (int) $problem->id,
            'language' => (string) $params['language'],
            'code' => (string) $params['code'],
            'status' => $status,
            'passed' => (int) ($result['passed'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
            'runtime' => '',
            'memory' => '',
            'timecreated' => time(),
        ]);

        $xp = 0;
        if ($status === 'ACCEPTED') {
            $award = gamification::award_accept((int) $USER->id, $problem);
            $xp = (int) ($award['awarded'] ?? 0);
        }

        // Hide expected for hidden cases in client response.
        foreach ($result['results'] as &$r) {
            if (($r['display'] ?? '') === 'hidden') {
                $r['expected'] = '';
                $r['input'] = '';
            }
        }
        unset($r);

        $result['submissionId'] = (int) $sid;
        $result['xpAwarded'] = $xp;
        $result['statusLabel'] = $status;
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return run_code::result_structure();
    }
}
