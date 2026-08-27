<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Editorial solutions and sample explanations for NexPractice.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-language solutions and sample-test explanations (NexPractice metadata).
 */
class solutions {

    /**
     * All editorial solutions for a problem keyed by language.
     *
     * @param int $problemid
     * @return array<string, array{language:string,code:string,explanation:string}>
     */
    public static function for_problem(int $problemid): array {
        global $DB;
        if ($problemid < 1 || !self::tables_exist()) {
            return [];
        }
        $out = [];
        foreach ($DB->get_records('local_learnlogic_solution', ['problemid' => $problemid], 'language ASC') as $row) {
            $lang = (string) $row->language;
            $out[$lang] = [
                'language' => $lang,
                'code' => (string) ($row->code ?? ''),
                'explanation' => (string) ($row->explanation ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Sample explanation overrides keyed by 0-based sample index.
     *
     * @param int $problemid
     * @return array<int, string>
     */
    public static function sample_explanations(int $problemid): array {
        global $DB;
        if ($problemid < 1 || !self::tables_exist()) {
            return [];
        }
        $out = [];
        foreach ($DB->get_records('local_learnlogic_sample_explanation', ['problemid' => $problemid], 'sampleindex ASC') as $row) {
            $out[(int) $row->sampleindex] = (string) ($row->explanation ?? '');
        }
        return $out;
    }

    /**
     * Merge NexPractice sample explanations onto sample rows (keeps CodeRunner extra when empty).
     *
     * @param int $problemid
     * @param array<int, array<string, mixed>> $samples
     * @return array<int, array<string, mixed>>
     */
    public static function merge_sample_explanations(int $problemid, array $samples): array {
        $overrides = self::sample_explanations($problemid);
        if (!$overrides) {
            return $samples;
        }
        foreach ($samples as $i => $sample) {
            if (isset($overrides[$i]) && trim($overrides[$i]) !== '') {
                $samples[$i]['explanation'] = $overrides[$i];
            }
        }
        return $samples;
    }

    /**
     * Save per-language solutions.
     *
     * @param int $problemid
     * @param array<string, array{code?:string,explanation?:string}> $bylang
     */
    public static function save_for_problem(int $problemid, array $bylang): void {
        global $DB;
        if ($problemid < 1 || !self::tables_exist()) {
            return;
        }
        $DB->delete_records('local_learnlogic_solution', ['problemid' => $problemid]);
        $now = time();
        foreach ($bylang as $lang => $payload) {
            $lang = importer::normalize_language((string) $lang);
            $code = trim((string) ($payload['code'] ?? ''));
            $explanation = trim((string) ($payload['explanation'] ?? ''));
            if ($code === '' && $explanation === '') {
                continue;
            }
            $DB->insert_record('local_learnlogic_solution', (object) [
                'problemid' => $problemid,
                'language' => $lang,
                'code' => $code,
                'explanation' => $explanation,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Save sample explanation overrides.
     *
     * @param int $problemid
     * @param array<int, string> $byindex
     */
    public static function save_sample_explanations(int $problemid, array $byindex): void {
        global $DB;
        if ($problemid < 1 || !self::tables_exist()) {
            return;
        }
        $DB->delete_records('local_learnlogic_sample_explanation', ['problemid' => $problemid]);
        foreach ($byindex as $index => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $DB->insert_record('local_learnlogic_sample_explanation', (object) [
                'problemid' => $problemid,
                'sampleindex' => (int) $index,
                'explanation' => $text,
            ]);
        }
    }

    /**
     * Extract model answer(s) from a CodeRunner question.
     *
     * @param int $questionid
     * @return array<string, array{code:string,explanation:string}>
     */
    public static function from_coderunner_question(int $questionid): array {
        if ($questionid < 1 || !runner::coderunner_available()) {
            return [];
        }
        runner::bootstrap_coderunner();
        $questionid = runner::latest_question_id($questionid) ?: $questionid;
        try {
            $question = \question_bank::load_question($questionid);
        } catch (\Throwable $e) {
            return [];
        }
        if (!($question instanceof \qtype_coderunner_question)) {
            return [];
        }

        $defaultlang = importer::normalize_language((string) ($question->language ?? 'python3'));
        $answers = [];

        $code = trim((string) ($question->answer ?? ''));
        if ($code !== '') {
            $answers[$defaultlang] = ['code' => $code, 'explanation' => ''];
        }

        // Multilanguage questions may store per-language snippets in template parameters.
        if (!empty($question->templateparams)) {
            $params = json_decode((string) $question->templateparams, true);
            if (is_array($params)) {
                foreach ($params as $key => $value) {
                    if (!is_string($value) || trim($value) === '') {
                        continue;
                    }
                    if (preg_match('/^answer[_-]?(.+)$/i', (string) $key, $m)) {
                        $lang = importer::normalize_language($m[1]);
                        $answers[$lang] = ['code' => trim($value), 'explanation' => ''];
                    }
                }
                if (!empty($params['answer_language']) && !empty($params['answer'])) {
                    $lang = importer::normalize_language((string) $params['answer_language']);
                    $answers[$lang] = ['code' => trim((string) $params['answer']), 'explanation' => ''];
                }
            }
        }

        $langs = importer::parse_acelang((string) ($question->acelang ?? ''));
        if (empty($langs)) {
            $langs = [$defaultlang];
        }
        foreach ($langs as $lang) {
            if (!isset($answers[$lang])) {
                $answers[$lang] = ['code' => '', 'explanation' => ''];
            }
        }

        return $answers;
    }

    /**
     * Import CodeRunner model answer into NexPractice solutions.
     *
     * @param int $problemid
     * @param int $questionid
     * @param bool $overwrite replace existing language entries
     * @return int number of languages imported
     */
    public static function import_from_coderunner(int $problemid, int $questionid, bool $overwrite = false): int {
        $incoming = self::from_coderunner_question($questionid);
        if (!$incoming) {
            return 0;
        }
        $existing = self::for_problem($problemid);
        $merged = [];
        foreach ($incoming as $lang => $payload) {
            if (trim($payload['code']) === '') {
                continue;
            }
            if (!$overwrite && isset($existing[$lang]) && trim($existing[$lang]['code']) !== '') {
                $merged[$lang] = [
                    'code' => $existing[$lang]['code'],
                    'explanation' => $existing[$lang]['explanation'],
                ];
                continue;
            }
            $merged[$lang] = [
                'code' => $payload['code'],
                'explanation' => $existing[$lang]['explanation'] ?? '',
            ];
        }
        if (!$overwrite) {
            foreach ($existing as $lang => $payload) {
                if (!isset($merged[$lang])) {
                    $merged[$lang] = [
                        'code' => $payload['code'],
                        'explanation' => $payload['explanation'],
                    ];
                }
            }
        }
        self::save_for_problem($problemid, $merged);
        return count(array_filter($merged, static function ($p) {
            return trim($p['code']) !== '';
        }));
    }

    /**
     * Sample rows for the manage edit form (linked CR or local).
     *
     * @param \stdClass $problem
     * @return array<int, array{stdin:string,expected:string,explanation:string}>
     */
    public static function samples_for_edit(\stdClass $problem): array {
        $pid = (int) $problem->id;
        $overrides = self::sample_explanations($pid);
        $samples = [];

        if (!empty($problem->sourcequestionid) && runner::coderunner_available()) {
            try {
                $q = runner::load_problem_question($problem);
                runner::prepare_question_for_run($q);
                $parts = runner::partition_testcases($q);
                foreach ($parts['samples'] as $i => $t) {
                    $crnote = trim((string) ($t->extra ?? ''));
                    $samples[] = [
                        'stdin' => (string) ($t->stdin ?? ''),
                        'expected' => (string) ($t->expected ?? ''),
                        'explanation' => $overrides[$i] ?? $crnote,
                    ];
                }
            } catch (\Throwable $e) {
                debugging('NexPractice sample load: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        } else {
            global $DB;
            $rows = $DB->get_records('local_learnlogic_testcase', [
                'problemid' => $pid,
                'display' => 'sample',
            ], 'sortorder ASC, id ASC');
            foreach ($rows as $i => $t) {
                $samples[] = [
                    'stdin' => (string) ($t->stdin ?? ''),
                    'expected' => (string) ($t->expected ?? ''),
                    'explanation' => (string) ($t->explanation ?? ''),
                ];
            }
        }
        return $samples;
    }

    /**
     * Remove all solution metadata for a problem.
     *
     * @param int $problemid
     */
    public static function delete_for_problem(int $problemid): void {
        global $DB;
        if ($problemid < 1 || !self::tables_exist()) {
            return;
        }
        $DB->delete_records('local_learnlogic_solution', ['problemid' => $problemid]);
        $DB->delete_records('local_learnlogic_sample_explanation', ['problemid' => $problemid]);
    }

    /**
     * @return bool
     */
    public static function tables_exist(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_learnlogic_solution')
            && $dbman->table_exists('local_learnlogic_sample_explanation');
    }
}
