<?php
// This file is part of Moodle - http://moodle.org/
/**
 * CodeRunner-backed execution for NexPractice problems.
 *
 * Linked problems (sourcequestionid) run against the live CodeRunner question —
 * same template, grader, and testcases as in the quiz bank.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Run sample / all / custom tests via qtype_coderunner_jobrunner.
 */
class runner {

    /**
     * True when CodeRunner plugin files/tables are present (no class bootstrap).
     *
     * @return bool
     */
    public static function coderunner_installed(): bool {
        global $CFG, $DB;
        $question = $CFG->dirroot . '/question/type/coderunner/question.php';
        if (!is_readable($question)) {
            return false;
        }
        try {
            return $DB->get_manager()->table_exists('question_coderunner_options');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return bool
     */
    public static function coderunner_available(): bool {
        if (!self::coderunner_installed()) {
            return false;
        }
        try {
            self::bootstrap_coderunner();
        } catch (\Throwable $e) {
            debugging('NexPractice CodeRunner bootstrap: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
        return class_exists('qtype_coderunner_question') && class_exists('qtype_coderunner_jobrunner');
    }

    /**
     * Load question engine + CodeRunner classes if present.
     */
    public static function bootstrap_coderunner(): void {
        global $CFG;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        if (!class_exists('question_behaviour_with_multiple_tries', false)) {
            $behaviourbase = $CFG->dirroot . '/question/behaviour/behaviourbase.php';
            if (is_readable($behaviourbase)) {
                require_once($behaviourbase);
            }
        }

        if (!class_exists('qtype_coderunner_question', false)) {
            $path = $CFG->dirroot . '/question/type/coderunner/question.php';
            if (is_readable($path)) {
                require_once($path);
            }
        }
        if (!class_exists('qtype_coderunner_jobrunner', false)) {
            $path = $CFG->dirroot . '/question/type/coderunner/classes/jobrunner.php';
            if (is_readable($path)) {
                require_once($path);
            }
        }
    }

    /**
     * Load the live CodeRunner question for a NexPractice problem.
     *
     * Always follows the question-bank entry to the **latest version**, so edits
     * in CodeRunner (new Moodle question id) show up in NexPractice without re-import.
     *
     * @param \stdClass $problem
     * @return \qtype_coderunner_question
     */
    public static function load_problem_question($problem): \qtype_coderunner_question {
        if (!self::coderunner_available()) {
            throw new \moodle_exception('nocoderunner', 'local_learnlogic');
        }

        $qid = (int) ($problem->sourcequestionid ?? 0);
        if ($qid < 1) {
            // Legacy fallback: per-language prototype / site default.
            $lang = (string) ($problem->defaultlanguage ?? 'python3');
            $qid = self::prototype_id($lang, 0);
        }
        if ($qid < 1) {
            throw new \moodle_exception('importnotcoderunner', 'local_learnlogic');
        }

        // Moodle 4+ question bank: editing creates a new questionid. Follow the entry.
        $latest = self::latest_question_id($qid);
        if ($latest > 0 && $latest !== $qid) {
            $qid = $latest;
            // Keep the NexPractice link pointed at the current version.
            if (!empty($problem->id)) {
                self::touch_source_question_id((int) $problem->id, $qid);
                $problem->sourcequestionid = $qid;
            }
        }

        $question = \question_bank::load_question($qid);
        if (!($question instanceof \qtype_coderunner_question)) {
            throw new \moodle_exception('importnotcoderunner', 'local_learnlogic');
        }

        // Quiz attempts call get_prototype() during display before grading.
        // NexPractice runs straight into jobrunner, which requires $question->prototype.
        self::ensure_prototype($question);

        return $question;
    }

    /**
     * Resolve a CodeRunner question id to the latest version on the same bank entry.
     *
     * @param int $questionid
     * @return int Latest question id (or the original if versions table missing)
     */
    public static function latest_question_id(int $questionid): int {
        global $DB;

        if ($questionid < 1) {
            return 0;
        }
        if (!$DB->get_manager()->table_exists('question_versions')
                || !$DB->get_manager()->table_exists('question_bank_entries')) {
            return $questionid;
        }

        try {
            // Prefer the newest *ready* version (same as quizzes); fall back to any newest.
            $sql = "SELECT qv2.questionid
                      FROM {question_versions} qv1
                      JOIN {question_versions} qv2
                        ON qv2.questionbankentryid = qv1.questionbankentryid
                     WHERE qv1.questionid = :qid
                       AND qv2.status = :status
                  ORDER BY qv2.version DESC";
            $latest = (int) $DB->get_field_sql($sql, [
                'qid' => $questionid,
                'status' => 'ready',
            ], IGNORE_MISSING);
            if ($latest > 0) {
                return $latest;
            }
            $sqlany = "SELECT qv2.questionid
                         FROM {question_versions} qv1
                         JOIN {question_versions} qv2
                           ON qv2.questionbankentryid = qv1.questionbankentryid
                        WHERE qv1.questionid = :qid
                     ORDER BY qv2.version DESC";
            $latest = (int) $DB->get_field_sql($sqlany, ['qid' => $questionid], IGNORE_MISSING);
            return $latest > 0 ? $latest : $questionid;
        } catch (\Throwable $e) {
            return $questionid;
        }
    }

    /**
     * Persist an updated sourcequestionid after resolving a newer CR version.
     *
     * @param int $problemid
     * @param int $questionid
     */
    private static function touch_source_question_id(int $problemid, int $questionid): void {
        global $DB;
        if ($problemid < 1 || $questionid < 1) {
            return;
        }
        try {
            $DB->set_field('local_learnlogic_problem', 'sourcequestionid', $questionid, ['id' => $problemid]);
            $DB->set_field('local_learnlogic_problem', 'timemodified', time(), ['id' => $problemid]);
            // Linked problems must not keep stale copied local tests.
            if ($DB->get_manager()->table_exists('local_learnlogic_testcase')) {
                $DB->delete_records('local_learnlogic_testcase', ['problemid' => $problemid]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — run still uses $questionid in-memory.
        }
    }

    /**
     * Ensure $question->prototype is a usable CodeRunner question object.
     *
     * load_question() inherits template fields from the prototype but does not
     * attach the prototype object itself (it is set on questiondata during
     * get_question_options, then dropped when options are flattened). Quiz
     * attempts call get_prototype() during display; NexPractice must do the same
     * before run_tests() or CodeRunner reports a missing prototype.
     *
     * @param \qtype_coderunner_question $question
     */
    public static function ensure_prototype(\qtype_coderunner_question $question): void {
        // A prototype question has no parent prototype.
        if ((int) ($question->prototypetype ?? 0) !== 0) {
            $question->prototype = null;
            self::ensure_question_contextid($question);
            return;
        }

        $usable = is_object($question->prototype ?? null)
            && !is_array($question->prototype)
            && !empty($question->prototype->id);

        if (!$usable) {
            // Force CodeRunner to re-resolve (null / duplicate-array both fail run_tests).
            unset($question->prototype);
            if (method_exists($question, 'get_prototype')) {
                $question->get_prototype();
            }
        }

        $proto = $question->prototype ?? null;
        if ($proto === null || is_array($proto) || !is_object($proto) || empty($proto->id)) {
            $proto = self::resolve_prototype_fallback((string) ($question->coderunnertype ?? ''), $question);
            if ($proto !== null) {
                $question->prototype = $proto;
                $qtype = \question_bank::get_qtype('coderunner');
                if ($qtype && method_exists($qtype, 'set_inherited_fields')) {
                    $qtype->set_inherited_fields($question, $proto);
                }
            }
        }

        self::ensure_question_contextid($question);

        if (empty($question->prototype) || is_array($question->prototype)) {
            throw new \moodle_exception(
                'missingprototype',
                'local_learnlogic',
                '',
                (object) ['crtype' => (string) ($question->coderunnertype ?? '?')]
            );
        }
    }

    /**
     * Find a single prototype for $coderunnertype when context lookup fails.
     *
     * Prefers the question's bank context, then system/site, then lowest id.
     *
     * @param string $coderunnertype
     * @param \qtype_coderunner_question $question
     * @return \qtype_coderunner_question|null
     */
    private static function resolve_prototype_fallback(string $coderunnertype, $question) {
        global $DB;

        if ($coderunnertype === '' || !$DB->get_manager()->table_exists('question_coderunner_options')) {
            return null;
        }

        $sql = "SELECT q.id, qc.contextid
                  FROM {question_coderunner_options} qco
                  JOIN {question} q ON qco.questionid = q.id
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qco.prototypetype != 0
                   AND qco.coderunnertype = ?
                   AND qv.version = (
                        SELECT MAX(v.version)
                          FROM {question_versions} v
                          JOIN {question_bank_entries} be ON be.id = v.questionbankentryid
                         WHERE be.id = qbe.id
                   )
              ORDER BY q.id ASC";

        try {
            $rows = $DB->get_records_sql($sql, [$coderunnertype]);
        } catch (\Throwable $e) {
            // Older schema without question_versions — try legacy join.
            $rows = [];
            try {
                $sqllegacy = "SELECT q.id, qc.contextid
                                FROM {question_coderunner_options} qco
                                JOIN {question} q ON qco.questionid = q.id
                                JOIN {question_categories} qc ON qc.id = q.category
                               WHERE qco.prototypetype != 0
                                 AND qco.coderunnertype = ?
                            ORDER BY q.id ASC";
                $rows = $DB->get_records_sql($sqllegacy, [$coderunnertype]);
            } catch (\Throwable $e2) {
                return null;
            }
        }

        if (empty($rows)) {
            return null;
        }

        $prefercontext = 0;
        try {
            if (class_exists('qtype_coderunner') && method_exists('qtype_coderunner', 'question_contextid')) {
                $prefercontext = (int) \qtype_coderunner::question_contextid($question);
            }
        } catch (\Throwable $e) {
            $prefercontext = (int) ($question->contextid ?? 0);
        }

        $chosen = null;
        if ($prefercontext > 0) {
            foreach ($rows as $row) {
                if ((int) $row->contextid === $prefercontext) {
                    $chosen = $row;
                    break;
                }
            }
        }
        if ($chosen === null) {
            // Prefer system / site course context (1) when present.
            foreach ($rows as $row) {
                try {
                    $ctx = \context::instance_by_id((int) $row->contextid, IGNORE_MISSING);
                    if ($ctx && ((int) $ctx->contextlevel === CONTEXT_SYSTEM
                            || ((int) $ctx->contextlevel === CONTEXT_COURSE && (int) $ctx->instanceid === SITEID))) {
                        $chosen = $row;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
        if ($chosen === null) {
            $chosen = reset($rows);
        }

        try {
            $proto = \question_bank::load_question((int) $chosen->id);
            if ($proto instanceof \qtype_coderunner_question) {
                return $proto;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Attach contextid used by CodeRunner file/sandbox helpers.
     *
     * @param \qtype_coderunner_question $question
     */
    private static function ensure_question_contextid($question): void {
        if (!empty($question->contextid)) {
            return;
        }
        try {
            if (class_exists('qtype_coderunner') && method_exists('qtype_coderunner', 'question_contextid')) {
                $question->contextid = (int) \qtype_coderunner::question_contextid($question);
            }
        } catch (\Throwable $e) {
            // Leave unset; CodeRunner will look it up when needed.
        }
    }

    /**
     * Resolve prototype question id for a language (legacy / site default).
     *
     * @param string $language
     * @param int $override
     * @return int
     */
    public static function prototype_id(string $language, int $override = 0): int {
        if ($override > 0) {
            return $override;
        }
        return (int) (get_config('local_learnlogic', 'prototype_' . $language) ?: 0);
    }

    /**
     * Execute against the linked CodeRunner question (CodeRunner-native path).
     *
     * @param int $problemid
     * @param string $language
     * @param string $code
     * @param string $mode sample|all|custom
     * @param string $customstdin
     * @param string $customexpected
     * @return array
     */
    public static function execute(
        int $problemid,
        string $language,
        string $code,
        string $mode = 'sample',
        string $customstdin = '',
        string $customexpected = ''
    ): array {
        global $DB, $USER;

        $problem = $DB->get_record('local_learnlogic_problem', ['id' => $problemid], '*', MUST_EXIST);
        $question = self::load_problem_question($problem);

        // Mirror quiz start_attempt(): expand template params / twig fields and
        // finalise the prototype before jobrunner runs.
        self::prepare_question_for_run($question);

        // Multi-lang Ace selection only; sandbox language always comes from the question.
        $runlang = self::answer_language_for_run($question, $language);

        $testcases = [];
        if ($mode === 'custom') {
            $testcases[] = self::make_testcase($customstdin, $customexpected, 'SHOW', '');
        } else {
            $testcases = self::testcases_for_mode($question, $mode === 'sample' ? 'sample' : 'all');
        }

        if (empty($testcases)) {
            return [
                'success' => false,
                'message' => get_string('importnoneinbank', 'local_learnlogic'),
                'results' => [],
                'allPassed' => false,
                'passed' => 0,
                'total' => 0,
            ];
        }

        // Attach a student object some Twig templates expect (quiz sets this in start_attempt).
        if (empty($question->student) && class_exists('qtype_coderunner_student') && !empty($USER)) {
            try {
                $question->student = new \qtype_coderunner_student($USER);
            } catch (\Throwable $e) {
                // Optional.
            }
        }

        // Prefer per-testcase isolation when any test has stdin. This matches how
        // CodeRunner runs classic C/Python stdin programs and avoids combinator
        // edge-cases that can feed the wrong stdin into later cases.
        $hasstdin = false;
        foreach ($testcases as $tc) {
            if (trim((string) ($tc->stdin ?? '')) !== '') {
                $hasstdin = true;
                break;
            }
        }

        if ($hasstdin && count($testcases) > 1) {
            $outcome = self::run_tests_isolated($question, $code, $testcases, $runlang);
        } else {
            $outcome = self::run_tests_with_retry($question, $code, $testcases, $runlang);
            $trcount = (is_object($outcome) && !empty($outcome->testresults) && is_array($outcome->testresults))
                ? count($outcome->testresults) : 0;
            if ($trcount < count($testcases) && count($testcases) > 1
                    && !self::is_transient_jobe_failure($outcome)) {
                $outcome = self::run_tests_isolated($question, $code, $testcases, $runlang);
            }
        }

        return self::normalize_outcome($outcome, $testcases);
    }

    /**
     * True when CodeRunner failed to reach Jobe (connection / cold-start), not a code error.
     *
     * @param mixed $outcome
     * @return bool
     */
    public static function is_transient_jobe_failure($outcome): bool {
        if (!is_object($outcome)) {
            return false;
        }
        $parts = [];
        foreach (['errormessage', 'errorMessage', 'message', 'sandboxmessage'] as $key) {
            if (!empty($outcome->$key)) {
                $parts[] = (string) $outcome->$key;
            }
        }
        if (method_exists($outcome, 'get_error_message')) {
            try {
                $parts[] = (string) $outcome->get_error_message();
            } catch (\Throwable $e) {
                // Ignore.
            }
        }
        if (!empty($outcome->testresults) && is_array($outcome->testresults)) {
            foreach ($outcome->testresults as $tr) {
                if (is_object($tr)) {
                    foreach (['stderr', 'got', 'output', 'message'] as $key) {
                        if (!empty($tr->$key) && is_string($tr->$key)) {
                            $parts[] = $tr->$key;
                        }
                    }
                }
            }
        }
        $hay = \core_text::strtolower(implode("\n", $parts));
        if ($hay === '') {
            // Empty results + run_failed often means sandbox never answered.
            if (method_exists($outcome, 'run_failed') && $outcome->run_failed()
                    && empty($outcome->testresults)) {
                return true;
            }
            return false;
        }
        // Permanent blocks — do not retry.
        if (strpos($hay, 'url is blocked') !== false || strpos($hay, 'url blocked') !== false) {
            return false;
        }
        $needles = [
            'jobe server request failed',
            'http response from jobe was 0',
            'http response from jobe was -1',
            'sandbox may be down',
            'sandbox server may be down',
            'could not connect',
            'connection refused',
            'connection timed out',
            'failed to connect',
            'operation timed out',
            'empty reply from server',
        ];
        foreach ($needles as $n) {
            if (strpos($hay, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Call jobrunner once; on transient Jobe failures, warm briefly and retry.
     *
     * @param \qtype_coderunner_question $question
     * @param string $code
     * @param array $testcases
     * @param string $runlang
     * @param int $maxattempts
     * @return mixed
     */
    private static function run_tests_with_retry($question, string $code, array $testcases, string $runlang, int $maxattempts = 3) {
        $maxattempts = max(1, min(4, $maxattempts));
        $outcome = null;
        for ($attempt = 1; $attempt <= $maxattempts; $attempt++) {
            if ($attempt > 1) {
                // Cold Jobe / first TCP often needs a beat before the next connect.
                usleep(350000 * ($attempt - 1)); // 0.35s, 0.7s, …
                self::warmup_jobe();
            }
            $runner = new \qtype_coderunner_jobrunner();
            $outcome = $runner->run_tests($question, $code, [], $testcases, false, $runlang, false);
            if (!self::is_transient_jobe_failure($outcome)) {
                return $outcome;
            }
            debugging(
                'NexPractice: transient Jobe failure on attempt ' . $attempt . '/' . $maxattempts,
                DEBUG_DEVELOPER
            );
        }
        return $outcome;
    }

    /**
     * Best-effort GET to Jobe /languages to wake a cold sandbox before retry.
     */
    private static function warmup_jobe(): void {
        try {
            if (!class_exists('\qtype_coderunner_jobe_sandbox', false)) {
                $path = null;
                global $CFG;
                $candidates = [
                    $CFG->dirroot . '/question/type/coderunner/classes/jobesandbox.php',
                    $CFG->dirroot . '/question/type/coderunner/sandbox/jobesandbox.php',
                ];
                foreach ($candidates as $c) {
                    if (is_readable($c)) {
                        require_once($c);
                        break;
                    }
                }
            }
            $host = get_config('qtype_coderunner', 'jobe_host');
            if ($host === false || $host === null || trim((string) $host) === '') {
                $host = get_config('qtype_coderunner', 'jobesandbox_host');
            }
            $host = trim((string) $host);
            if ($host === '') {
                return;
            }
            if (!preg_match('#^https?://#i', $host)) {
                $host = 'http://' . $host;
            }
            $host = rtrim($host, '/');
            $url = $host . '/jobe/index.php/restapi/languages';
            $curl = new \curl(['timeout' => 3, 'connecttimeout' => 2]);
            $curl->get($url);
        } catch (\Throwable $e) {
            // Warmup is optional.
        }
    }

    /**
     * Prepare a loaded CodeRunner question the same way a quiz attempt does.
     *
     * @param \qtype_coderunner_question $question
     */
    public static function prepare_question_for_run($question): void {
        self::ensure_prototype($question);
        if (method_exists($question, 'evaluate_question_for_display')) {
            try {
                $question->evaluate_question_for_display(mt_rand(), null);
            } catch (\Throwable $e) {
                debugging('NexPractice evaluate_question_for_display: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        // Re-ensure after evaluate (it calls get_prototype again).
        self::ensure_prototype($question);
    }

    /**
     * Ace/answer language for multilanguage questions; empty for single-lang.
     *
     * @param \qtype_coderunner_question $question
     * @param string $uilang
     * @return string
     */
    public static function answer_language_for_run($question, string $uilang): string {
        $ace = trim((string) ($question->acelang ?? ''));
        if ($ace === '' || strpos($ace, ',') === false) {
            // Single-language question — jobrunner uses $question->get_language().
            return '';
        }
        $uilang = strtolower(trim($uilang));
        $map = [
            'python3' => 'python3',
            'python' => 'python3',
            'java' => 'java',
            'cpp' => 'cpp',
            'c++' => 'cpp',
            'c' => 'c',
            'javascript' => 'javascript',
            'nodejs' => 'javascript',
            'js' => 'javascript',
            'php' => 'php',
        ];
        $want = $map[$uilang] ?? $uilang;
        foreach (preg_split('/\s*,\s*/', $ace) as $opt) {
            $opt = trim($opt);
            if ($opt === '') {
                continue;
            }
            if (strtolower(rtrim($opt, '*')) === $want || strtolower($opt) === $want) {
                return rtrim($opt, '*');
            }
        }
        return $want;
    }

    /**
     * Native CodeRunner testcase objects for Run (samples) or Submit (all).
     *
     * @param \qtype_coderunner_question $question
     * @param string $mode sample|all
     * @return \stdClass[]
     */
    public static function testcases_for_mode($question, string $mode): array {
        $precheckonly = 1; // qtype_coderunner\constants::TESTTYPE_PRECHECK

        if ($mode === 'sample' && method_exists($question, 'example_testcases')) {
            $raw = array_values($question->example_testcases());
            if (!empty($raw)) {
                return self::clone_testcases($raw);
            }
        }

        $parts = self::partition_testcases($question);
        if ($mode === 'sample') {
            return $parts['samples'];
        }

        // Submit = normal + both (exclude precheck-only), matching Check (not Precheck).
        $merged = array_merge($parts['samples'], $parts['hidden']);
        $merged = array_values(array_filter($merged, static function ($tc) use ($precheckonly) {
            return (int) ($tc->testtype ?? 0) !== $precheckonly;
        }));
        return $merged;
    }

    /**
     * @param array $raw
     * @return \stdClass[]
     */
    private static function clone_testcases(array $raw): array {
        $out = [];
        foreach ($raw as $t) {
            $tc = is_object($t) ? clone $t : (object) $t;
            foreach (['stdin', 'expected', 'testcode', 'extra'] as $field) {
                if (isset($tc->$field) && is_string($tc->$field)) {
                    $tc->$field = self::normalise_newlines($tc->$field);
                }
            }
            if (!isset($tc->mark) || $tc->mark === '' || $tc->mark === null) {
                $tc->mark = 1.0;
            } else {
                $tc->mark = (float) $tc->mark;
            }
            if (!isset($tc->display) || $tc->display === '') {
                $tc->display = !empty($tc->useasexample) ? 'SHOW' : 'HIDE';
            }
            if (!isset($tc->testtype)) {
                $tc->testtype = 0;
            }
            if (!isset($tc->hiderestiffail)) {
                $tc->hiderestiffail = 0;
            }
            // Drop ids so CodeRunner cache keys stay stable (jobrunner does this too).
            unset($tc->id, $tc->questionid);
            $out[] = $tc;
        }
        // Honour author ordering when present.
        usort($out, static function ($a, $b) {
            $oa = isset($a->ordering) ? (int) $a->ordering : 0;
            $ob = isset($b->ordering) ? (int) $b->ordering : 0;
            if ($oa === $ob) {
                return 0;
            }
            return $oa < $ob ? -1 : 1;
        });
        return $out;
    }

    /**
     * @param string $s
     * @return string
     */
    private static function normalise_newlines(string $s): string {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    /**
     * Run each testcase in its own jobrunner call and merge outcomes.
     *
     * @param \qtype_coderunner_question $question
     * @param string $code
     * @param array $testcases
     * @param string $runlang
     * @return object
     */
    private static function run_tests_isolated($question, string $code, array $testcases, string $runlang) {
        $maxmark = 0.0;
        foreach ($testcases as $tc) {
            $maxmark += (float) ($tc->mark ?? 1);
        }
        if ($maxmark <= 0) {
            $maxmark = 1.0;
        }
        $outcome = new \qtype_coderunner_testing_outcome($maxmark, count($testcases), false);
        foreach ($testcases as $tc) {
            $one = self::run_tests_with_retry($question, $code, [$tc], $runlang);
            if (!is_object($one)) {
                continue;
            }
            if (self::is_transient_jobe_failure($one)) {
                return $one;
            }
            if (method_exists($one, 'run_failed') && $one->run_failed()) {
                return $one;
            }
            if (method_exists($one, 'has_syntax_error') && $one->has_syntax_error()) {
                return $one;
            }
            if (!empty($one->testresults) && is_array($one->testresults)) {
                foreach ($one->testresults as $tr) {
                    if (is_object($tr)) {
                        $outcome->add_test_result($tr);
                    }
                }
            }
        }
        return $outcome;
    }

    /**
     * Split CodeRunner tests into sample (example) vs hidden suites.
     *
     * Samples = useasexample (author examples). SHOW alone is NOT a sample —
     * CodeRunner often marks many cases SHOW for quiz feedback.
     * If no examples are marked, the first case becomes the sample; the rest are hidden.
     *
     * @param \qtype_coderunner_question $question
     * @return array{samples:\stdClass[], hidden:\stdClass[]}
     */
    public static function partition_testcases($question): array {
        $raw = self::raw_test_rows($question);
        $samples = [];
        $hidden = [];

        foreach ($raw as $t) {
            if (self::is_example_case($t)) {
                $samples[] = $t;
            } else {
                $hidden[] = $t;
            }
        }

        // No author examples — promote the first case to sample only.
        if (empty($samples) && !empty($raw)) {
            $rows = array_values($raw);
            $samples[] = array_shift($rows);
            $hidden = $rows;
        }

        return [
            'samples' => self::mark_testcase_roles(self::clone_testcases($samples), 'sample'),
            'hidden' => self::mark_testcase_roles(self::clone_testcases($hidden), 'hidden'),
        ];
    }

    /**
     * Stamp sample vs hidden role for result redaction (CodeRunner SHOW ≠ sample).
     *
     * @param \stdClass[] $cases
     * @param string $role sample|hidden
     * @return \stdClass[]
     */
    private static function mark_testcase_roles(array $cases, string $role): array {
        foreach ($cases as $tc) {
            $tc->ll_role = $role;
            if ($role === 'sample') {
                $tc->useasexample = 1;
            } else {
                $tc->useasexample = 0;
            }
        }
        return $cases;
    }

    /**
     * True when this CodeRunner row is an author example (sample testcase).
     *
     * @param \stdClass $t
     * @return bool
     */
    public static function is_example_case($t): bool {
        if (!empty($t->useasexample)) {
            return true;
        }
        // Explicit NexPractice / legacy marker only — not plain SHOW.
        $display = strtoupper(trim((string) ($t->display ?? '')));
        return $display === 'SAMPLE' || $display === 'EXAMPLE';
    }

    /**
     * @param \qtype_coderunner_question $question
     * @return \stdClass[]
     */
    public static function raw_test_rows($question): array {
        global $DB;

        $raw = [];
        if (!empty($question->testcases) && is_iterable($question->testcases)) {
            foreach ($question->testcases as $t) {
                $raw[] = is_object($t) ? $t : (object) $t;
            }
        } else if ($DB->get_manager()->table_exists('question_coderunner_tests')) {
            $raw = array_values($DB->get_records(
                'question_coderunner_tests',
                ['questionid' => (int) $question->id],
                'id ASC'
            ));
        }

        // Prefer author ordering when available (matches CodeRunner quiz).
        usort($raw, static function ($a, $b) {
            $oa = isset($a->ordering) ? (int) $a->ordering : 0;
            $ob = isset($b->ordering) ? (int) $b->ordering : 0;
            if ($oa === $ob) {
                $ida = isset($a->id) ? (int) $a->id : 0;
                $idb = isset($b->id) ? (int) $b->id : 0;
                return $ida <=> $idb;
            }
            return $oa <=> $ob;
        });

        return $raw;
    }

    /**
     * @param \stdClass $t
     * @param string $display SHOW|HIDE
     * @return \stdClass
     */
    private static function testcase_from_row($t, string $display): \stdClass {
        return self::make_testcase(
            (string) ($t->stdin ?? ''),
            (string) ($t->expected ?? ''),
            $display,
            (string) ($t->testcode ?? ''),
            (string) ($t->extra ?? ''),
            isset($t->mark) ? (float) $t->mark : 1.0,
            !empty($t->hiderestiffail) ? 1 : 0,
            isset($t->testtype) ? (int) $t->testtype : 0
        );
    }

    /**
     * Native CodeRunner testcases from the question object / DB.
     *
     * @param \qtype_coderunner_question $question
     * @param bool $samplesonly
     * @return \stdClass[]
     */
    public static function question_testcases($question, bool $samplesonly = false): array {
        $parts = self::partition_testcases($question);
        if ($samplesonly) {
            return $parts['samples'];
        }
        return array_merge($parts['samples'], $parts['hidden']);
    }

    /**
     * @param string $stdin
     * @param string $expected
     * @param string $display SHOW|HIDE
     * @param string $testcode
     * @param string $extra
     * @param float $mark
     * @param int $hiderestiffail
     * @param int $testtype
     * @return \stdClass
     */
    private static function make_testcase(
        string $stdin,
        string $expected,
        string $display,
        string $testcode = '',
        string $extra = '',
        float $mark = 1.0,
        int $hiderestiffail = 0,
        int $testtype = 0
    ): \stdClass {
        return (object) [
            'testtype' => $testtype,
            'testcode' => $testcode,
            'stdin' => $stdin,
            'expected' => $expected,
            'extra' => $extra,
            'display' => $display,
            'useasexample' => ($display === 'SHOW') ? 1 : 0,
            'hiderestiffail' => $hiderestiffail,
            'mark' => $mark,
        ];
    }

    /**
     * @param mixed $outcome
     * @param array $testcases
     * @return array
     */
    private static function normalize_outcome($outcome, array $testcases): array {
        $results = [];
        $passed = 0;
        $total = count($testcases);

        $testresults = [];
        if (is_object($outcome) && !empty($outcome->testresults) && is_array($outcome->testresults)) {
            $testresults = $outcome->testresults;
        }

        $message = '';
        if (is_object($outcome)) {
            if (!empty($outcome->errorcode) || !empty($outcome->errormessage)) {
                $message = (string) ($outcome->errormessage ?? 'Execution error');
            }
        }
        if ($message === '' && self::is_transient_jobe_failure($outcome)) {
            $message = is_object($outcome) && !empty($outcome->errormessage)
                ? (string) $outcome->errormessage
                : 'Jobe server request failed.';
        }

        // Sandbox / global failure: do not invent per-case rows (would leak hidden I/O).
        if ($message !== '' && empty($testresults)) {
            return [
                'success' => false,
                'message' => $message,
                'results' => [],
                'allPassed' => false,
                'passed' => 0,
                'total' => $total,
            ];
        }

        foreach ($testcases as $i => $tc) {
            $issample = self::testcase_is_sample($tc);
            $tr = $testresults[$i] ?? null;
            $iscorrect = false;
            $actual = '';
            $stderr = '';
            $status = 'error';

            if (is_object($tr)) {
                if (isset($tr->iscorrect)) {
                    $iscorrect = (bool) $tr->iscorrect;
                } else if (isset($tr->mark) && isset($tr->awarded)) {
                    $iscorrect = ((float) $tr->awarded + 0.0001) >= (float) $tr->mark;
                }
                $got = $tr->got ?? ($tr->output ?? '');
                if (is_object($got)) {
                    if (method_exists($got, 'value')) {
                        $got = $got->value();
                    } else if (isset($got->value)) {
                        $got = $got->value;
                    } else {
                        $got = (string) $got;
                    }
                }
                $actual = (string) $got;
                $stderr = (string) ($tr->stderr ?? '');
                // Prefer per-result stdin/expected from CodeRunner (already tidied).
                if ($issample) {
                    if (isset($tr->stdin) && is_string($tr->stdin)) {
                        $tc->stdin = $tr->stdin;
                    }
                    if (isset($tr->expected) && is_string($tr->expected)) {
                        $tc->expected = $tr->expected;
                    }
                }
                $status = $iscorrect ? 'accepted' : 'wrong_answer';
                if ($stderr !== '' && $actual === '') {
                    $status = 'runtime_error';
                }
            } else if (is_object($outcome) && $issample) {
                // Only attach global sandbox output to sample rows — never to hidden.
                $actual = (string) ($outcome->actualoutput ?? '');
                if (method_exists($outcome, 'all_correct')) {
                    $iscorrect = (bool) $outcome->all_correct();
                }
                $status = $iscorrect ? 'accepted' : 'wrong_answer';
            }

            if ($iscorrect) {
                $passed++;
            }

            $row = [
                'input' => $issample ? (string) ($tc->stdin ?? '') : '',
                'expected' => $issample ? (string) ($tc->expected ?? '') : '',
                'actual' => $issample ? $actual : '',
                'isCorrect' => $iscorrect,
                'stderr' => $issample ? $stderr : '',
                'status' => $status,
                'display' => $issample ? 'sample' : 'hidden',
            ];
            $results[] = $row;
        }

        if (empty($testresults) && is_object($outcome) && method_exists($outcome, 'all_correct') && $total > 0) {
            $ok = (bool) $outcome->all_correct();
            if ($ok) {
                $passed = $total;
                foreach ($results as &$r) {
                    $r['isCorrect'] = true;
                    $r['status'] = 'accepted';
                }
                unset($r);
            }
        }

        $allpassed = ($total > 0 && $passed === $total);

        return [
            'success' => $message === '',
            'message' => $message,
            'results' => $results,
            'allPassed' => $allpassed,
            'passed' => $passed,
            'total' => $total,
        ];
    }

    /**
     * Whether a run-time testcase object is a public sample (vs hidden).
     *
     * @param \stdClass $tc
     * @return bool
     */
    private static function testcase_is_sample($tc): bool {
        if (isset($tc->ll_role)) {
            return $tc->ll_role === 'sample';
        }
        return !empty($tc->useasexample) || self::is_example_case($tc);
    }
}
