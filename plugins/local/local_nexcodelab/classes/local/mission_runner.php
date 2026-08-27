<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Step checking via CodeRunner site prototype.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Grade one mission step.
 */
class mission_runner {

    /**
     * @param int $userid
     * @param int $missionid
     * @param int $stepid
     * @param string $code Student main.py
     * @return array
     */
    public static function check_step(int $userid, int $missionid, int $stepid, string $code): array {
        global $DB;

        $mission = $DB->get_record('local_nexcodelab_mission', ['id' => $missionid], '*', MUST_EXIST);
        $step = $DB->get_record('local_nexcodelab_mission_step', [
            'id' => $stepid,
            'missionid' => $missionid,
        ], '*', MUST_EXIST);

        // Unlock gate.
        $payload = self::get_mission_steps_state($userid, $missionid);
        foreach ($payload as $st) {
            if ((int) $st->id === $stepid) {
                if (!empty($st->locked)) {
                    return [
                        'success' => false,
                        'passed' => false,
                        'message' => get_string('steplocked', 'local_nexcodelab'),
                        'output' => '',
                        'xpAwarded' => 0,
                        'missionCompleted' => false,
                    ];
                }
                break;
            }
        }

        $datafile = $DB->get_record('local_nexcodelab_mission_file', [
            'missionid' => $missionid,
            'path' => 'data.csv',
        ]);
        $csv = $datafile ? (string) $datafile->content : '';

        $grader = json_decode((string) ($step->graderpayload ?? '{}'), true) ?: [];
        $kind = (string) ($grader['kind'] ?? $step->checkkind ?? 'stdout');

        $result = self::run_grader($code, $csv, $kind, $grader);
        $pass = !empty($result['passed']);

        $DB->insert_record('local_nexcodelab_step_attempt', (object) [
            'userid' => $userid,
            'stepid' => $stepid,
            'status' => $pass ? 'pass' : 'fail',
            'code_snapshot' => $code,
            'output' => (string) ($result['output'] ?? ''),
            'timecreated' => time(),
        ]);

        // Persist workspace.
        missions::save_workspace($userid, $missionid, 'main.py', $code);

        $xp = 0;
        $missioncompleted = false;
        if ($pass) {
            $prior = (int) $DB->count_records('local_nexcodelab_step_attempt', [
                'userid' => $userid,
                'stepid' => $stepid,
                'status' => 'pass',
            ]);
            // Count includes the row we just inserted.
            if ($prior === 1) {
                $xp = gamification::award_step_xp($userid, (int) $step->xp, $missionid, $stepid);
            }
            $missioncompleted = self::refresh_progress($userid, $missionid);
            if ($missioncompleted && $prior === 1) {
                $bonus = (int) (get_config('local_nexcodelab', 'xp_firstbonus') ?: 15);
                if ($bonus > 0) {
                    gamification::add_xp($userid, $bonus, $missionid, 'missioncomplete');
                    $xp += $bonus;
                }
                gamification::bump_streak($userid);
            }
        }

        return [
            'success' => true,
            'passed' => $pass,
            'message' => $pass
                ? get_string('steppassed', 'local_nexcodelab')
                : (string) ($result['message'] ?? get_string('stepfailed', 'local_nexcodelab')),
            'output' => (string) ($result['output'] ?? ''),
            'xpAwarded' => $xp,
            'missionCompleted' => $missioncompleted,
            'expected' => (string) ($result['expected'] ?? ''),
            'actual' => (string) ($result['actual'] ?? ''),
        ];
    }

    /**
     * @param int $userid
     * @param int $missionid
     * @return \stdClass[]
     */
    private static function get_mission_steps_state(int $userid, int $missionid): array {
        $data = missions::get_mission($missionid, $userid);
        $out = [];
        foreach (($data['steps'] ?? []) as $s) {
            $out[] = (object) $s;
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param int $missionid
     * @return bool Mission newly or already completed
     */
    private static function refresh_progress(int $userid, int $missionid): bool {
        global $DB;

        $steps = $DB->get_records('local_nexcodelab_mission_step', ['missionid' => $missionid], 'sortorder ASC');
        $passed = 0;
        $current = 0;
        foreach ($steps as $s) {
            $ok = $DB->record_exists('local_nexcodelab_step_attempt', [
                'userid' => $userid,
                'stepid' => (int) $s->id,
                'status' => 'pass',
            ]);
            if ($ok) {
                $passed++;
                $current = (int) $s->sortorder + 1;
            }
        }
        $completed = ($passed === count($steps) && count($steps) > 0) ? 1 : 0;
        $now = time();
        $row = $DB->get_record('local_nexcodelab_mission_progress', [
            'userid' => $userid,
            'missionid' => $missionid,
        ]);
        if ($row) {
            $row->currentstep = $current;
            $row->completed = $completed;
            $row->timemodified = $now;
            $DB->update_record('local_nexcodelab_mission_progress', $row);
        } else {
            $DB->insert_record('local_nexcodelab_mission_progress', (object) [
                'userid' => $userid,
                'missionid' => $missionid,
                'currentstep' => $current,
                'completed' => $completed,
                'timemodified' => $now,
            ]);
        }
        return (bool) $completed;
    }

    /**
     * Execute step grader via CodeRunner prototype when available, else local PHP fallback for dry-dev.
     *
     * @param string $code
     * @param string $csv
     * @param string $kind
     * @param array $grader
     * @return array
     */
    private static function run_grader(string $code, string $csv, string $kind, array $grader): array {
        $fn = (string) ($grader['fn'] ?? 'solve');
        $preprocess = (string) ($grader['preprocess'] ?? '');
        $expectcsv = (string) ($grader['expect_csv'] ?? '');
        $expect = (string) ($grader['expect'] ?? '');
        $floor = isset($grader['floor']) ? (float) $grader['floor'] : null;

        $driver = self::build_step_driver($fn, $preprocess, $kind, $expectcsv, $expect, $floor);
        $fullcode = rtrim($code) . "\n\n" . $driver;

        // Prefer CodeRunner.
        if (runner::coderunner_available() && runner::prototype_id('python3', 0) > 0) {
            try {
                return self::run_via_coderunner($fullcode, $csv, $kind, $expectcsv, $expect, $floor);
            } catch (\Throwable $e) {
                debugging('NexCodeLab mission CR: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return [
                    'passed' => false,
                    'message' => $e->getMessage(),
                    'output' => '',
                ];
            }
        }

        return [
            'passed' => false,
            'message' => get_string('noprototype', 'local_nexcodelab', 'python3'),
            'output' => '',
        ];
    }

    /**
     * @param string $fullcode
     * @param string $csv
     * @param string $kind
     * @param string $expectcsv
     * @param string $expect
     * @param float|null $floor
     * @return array
     */
    private static function run_via_coderunner(
        string $fullcode,
        string $csv,
        string $kind,
        string $expectcsv,
        string $expect,
        ?float $floor
    ): array {
        $proto = (object) [
            'id' => 0,
            'sourcequestionid' => 0,
            'defaultlanguage' => 'python3',
        ];
        $question = runner::load_problem_question($proto);
        runner::prepare_question_for_run($question);

        $expectedout = $kind === 'frame' ? $expectcsv : ($expect !== '' ? $expect : '');
        // For metric floor-only, expected can be empty; we parse stdout.
        $tc = self::make_testcase($csv, $expectedout);

        $job = new \qtype_coderunner_jobrunner();
        $outcome = $job->run_tests($question, $fullcode, [], [$tc], false, '', false);
        $actual = '';
        $stderr = '';
        $iscorrect = false;
        if (is_object($outcome) && !empty($outcome->testresults[0])) {
            $tr = $outcome->testresults[0];
            $actual = (string) ($tr->got ?? $tr->actual ?? '');
            $stderr = (string) ($tr->stderr ?? '');
            $iscorrect = !empty($tr->iscorrect) || !empty($tr->isCorrect);
        }

        if ($kind === 'metric' && $floor !== null) {
            $num = null;
            if (preg_match('/-?\d+(?:\.\d+)?/', $actual, $m)) {
                $num = (float) $m[0];
            }
            $pass = $num !== null && $num + 1e-9 >= $floor;
            if ($expect !== '') {
                $pass = $pass && (trim($actual) === trim($expect) || abs((float) trim($actual) - (float) $expect) < 1e-3);
            }
            return [
                'passed' => $pass,
                'output' => $actual . ($stderr ? "\n" . $stderr : ''),
                'message' => $pass ? '' : 'Metric check failed',
                'expected' => $expect !== '' ? $expect : (string) $floor,
                'actual' => $actual,
            ];
        }

        if ($kind === 'frame') {
            $pass = self::csv_equal($actual, $expectcsv) || $iscorrect;
            return [
                'passed' => $pass,
                'output' => $actual . ($stderr ? "\n" . $stderr : ''),
                'message' => $pass ? '' : 'DataFrame check failed',
                'expected' => $expectcsv,
                'actual' => $actual,
            ];
        }

        $pass = $iscorrect || (trim($actual) === trim($expectedout));
        return [
            'passed' => $pass,
            'output' => $actual . ($stderr ? "\n" . $stderr : ''),
            'message' => $pass ? '' : 'Output mismatch',
            'expected' => $expectedout,
            'actual' => $actual,
        ];
    }

    /**
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function csv_equal(string $a, string $b): bool {
        $na = preg_replace("/\r\n|\r/", "\n", trim($a));
        $nb = preg_replace("/\r\n|\r/", "\n", trim($b));
        if ($na === $nb) {
            return true;
        }
        // Float-tolerant line compare.
        $la = explode("\n", $na);
        $lb = explode("\n", $nb);
        if (count($la) !== count($lb)) {
            return false;
        }
        foreach ($la as $i => $line) {
            $ca = str_getcsv($line);
            $cb = str_getcsv($lb[$i]);
            if (count($ca) !== count($cb)) {
                return false;
            }
            foreach ($ca as $j => $cell) {
                $x = trim((string) $cell);
                $y = trim((string) $cb[$j]);
                if ($x === $y) {
                    continue;
                }
                if (is_numeric($x) && is_numeric($y) && abs((float) $x - (float) $y) < 1e-4) {
                    continue;
                }
                return false;
            }
        }
        return true;
    }

    /**
     * Python driver appended to student code.
     */
    private static function build_step_driver(
        string $fn,
        string $preprocess,
        string $kind,
        string $expectcsv,
        string $expect,
        ?float $floor
    ): string {
        $fnesc = addcslashes($fn, "'\\");
        $preesc = addcslashes($preprocess, "'\\");
        return <<<PY
# --- NexCodeLab mission step driver ---
import sys, io
import pandas as pd

def __ncl_step():
    raw = sys.stdin.read()
    df = pd.read_csv(io.StringIO(raw)) if str(raw).strip() else pd.DataFrame()
    pre = '{$preesc}'
    if pre and pre in globals() and callable(globals()[pre]):
        df = globals()[pre](df)
    fn = '{$fnesc}'
    if fn not in globals() or not callable(globals()[fn]):
        raise NameError('Missing function ' + fn)
    out = globals()[fn](df)
    if isinstance(out, pd.DataFrame):
        sys.stdout.write(out.to_csv(index=False))
    elif isinstance(out, float):
        print('{:.4f}'.format(out))
    else:
        print(out)

__ncl_step()
PY;
    }

    /**
     * @param string $stdin
     * @param string $expected
     * @return \stdClass
     */
    private static function make_testcase(string $stdin, string $expected): \stdClass {
        return (object) [
            'testtype' => 0,
            'testcode' => '',
            'stdin' => $stdin,
            'expected' => $expected,
            'extra' => '',
            'display' => 'SHOW',
            'useasexample' => 1,
            'hiderestiffail' => 0,
            'mark' => 1.0,
        ];
    }
}
