<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Orchestrate fetch → OpenAI → XML → question import.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * End-to-end import pipeline (one problem at a time).
 */
class importer {

    /**
     * Parse multiline problem list.
     *
     * @param string $raw
     * @return string[]
     */
    public static function parse_problem_list(string $raw): array {
        $lines = preg_split('/\R+/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', $line) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Process a single problem (fetch → OpenAI → import). Used by AJAX runner.
     *
     * @param string $raw
     * @param array $options
     * @param \stdClass $category
     * @param array $contexts
     * @param \stdClass $course
     * @param int $n 1-based index
     * @param int $total
     * @return array{ok:bool,status:string,name:string,detail:string,input:string,xml:string,steps:string[]}
     */
    public function process_one(
        string $raw,
        array $options,
        \stdClass $category,
        array $contexts,
        \stdClass $course,
        int $n = 1,
        int $total = 1
    ): array {
        if (!\core_component::get_plugin_directory('qtype', 'coderunner')) {
            throw new \moodle_exception('missingcoderunner', 'qbank_leetcodeimport');
        }

        \core_php_time_limit::raise(300);
        raise_memory_limit(MEMORY_EXTRA);

        $leetcode = new leetcode_client();
        $openai = new openai_client();
        $builder = new coderunner_builder();
        $dryrun = !empty($options['dryrun']);
        $steps = [];

        $out = [
            'ok' => false,
            'status' => 'failed',
            'name' => '',
            'detail' => '',
            'input' => $raw,
            'xml' => '',
            'steps' => [],
        ];

        $steps[] = get_string('progress_fetch', 'qbank_leetcodeimport', (object) [
            'n' => $n,
            'total' => $total,
            'id' => $raw,
        ]);

        $problem = $leetcode->fetch_problem($raw);
        $idnumber = self::make_idnumber($problem);

        // Same category + same LeetCode idnumber → do not import again.
        if (!$dryrun && $this->exists_in_category((int) $category->id, $idnumber, (string) ($problem['title'] ?? ''))) {
            $out['ok'] = true;
            $out['status'] = 'skipped';
            $out['name'] = (string) ($problem['title'] ?? $raw);
            $out['detail'] = get_string('skipped_exists', 'qbank_leetcodeimport', $idnumber);
            $steps[] = $out['detail'];
            $out['steps'] = $steps;
            return $out;
        }

        $steps[] = get_string('progress_openai', 'qbank_leetcodeimport', (object) [
            'n' => $n,
            'total' => $total,
            'title' => $problem['title'] ?? $raw,
        ]);

        $payload = $openai->convert_problem($problem, $options);
        $out['name'] = $payload['name'];

        // Recreate LeetCode figures as Moodle question files.
        $images = image_helper::collect_from_html(
            (string) ($problem['content'] ?? ''),
            (string) ($problem['titleSlug'] ?? 'lc')
        );
        if ($images) {
            $steps[] = get_string('progress_images', 'qbank_leetcodeimport', count($images));
            $payload['images'] = $images;
            $payload['questiontext_html'] = image_helper::inject_into_html(
                (string) ($payload['questiontext_html'] ?? ''),
                $images
            );
        } else {
            $payload['images'] = [];
        }

        $xmlone = $builder->build_quiz_xml([$payload], $options);
        $out['xml'] = $xmlone;

        if ($dryrun) {
            $out['ok'] = true;
            $out['status'] = 'ok';
            $out['detail'] = get_string('progress_dryrun', 'qbank_leetcodeimport', count($payload['testcases']));
            $steps[] = $out['detail'];
            $out['steps'] = $steps;
            return $out;
        }

        $steps[] = get_string('progress_import', 'qbank_leetcodeimport', (object) [
            'n' => $n,
            'total' => $total,
            'name' => $payload['name'],
        ]);
        $this->import_xml($xmlone, $category, $contexts, $course, false);
        try {
            $this->enforce_coderunner_flags($payload, $options, (int) $category->id);
        } catch (\Throwable $e) {
            $steps[] = 'Imported, but post-fix tweak skipped: ' . $e->getMessage();
        }

        $out['ok'] = true;
        $out['status'] = 'ok';
        $imgnote = $images ? (' · ' . count($images) . ' images') : '';
        $out['detail'] = ($payload['meta']['slug'] ?? '') . ' · '
            . count($payload['testcases']) . ' tests · imported' . $imgnote;
        $steps[] = $out['detail'];
        $out['steps'] = $steps;
        return $out;
    }

    /**
     * Stable idnumber for a LeetCode problem.
     *
     * @param array $problem
     * @return string
     */
    public static function make_idnumber(array $problem): string {
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower((string) ($problem['titleSlug'] ?? 'leetcode')));
        $fid = preg_replace('/\D+/', '', (string) ($problem['questionFrontendId'] ?? ''));
        return 'lc' . ($fid !== '' ? $fid : '') . '-' . trim((string) $slug, '-');
    }

    /**
     * True if this LeetCode question is already in the category.
     *
     * @param int $categoryid
     * @param string $idnumber
     * @param string $title
     * @return bool
     */
    public function exists_in_category(int $categoryid, string $idnumber, string $title = ''): bool {
        global $DB;

        if ($categoryid <= 0) {
            return false;
        }

        if ($DB->get_manager()->table_exists('question_bank_entries') && $idnumber !== '') {
            $sql = "SELECT qbe.id
                      FROM {question_bank_entries} qbe
                     WHERE qbe.questioncategoryid = ?
                       AND qbe.idnumber = ?";
            if ($DB->record_exists_sql($sql, [$categoryid, $idnumber])) {
                return true;
            }
        }

        // Fallback: same question name in this category (coderunner).
        if ($title !== '' && $DB->get_manager()->table_exists('question_bank_entries')) {
            $sql = "SELECT q.id
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid = ?
                       AND q.qtype = 'coderunner'
                       AND q.name = ?";
            if ($DB->record_exists_sql($sql, [$categoryid, $title])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Force all-or-nothing, SHOW display, hide-rest-if-fail, and topic tags after XML import.
     *
     * @param array $payload
     * @param array $options
     * @param int $categoryid
     */
    public function enforce_coderunner_flags(array $payload, array $options, int $categoryid): void {
        global $DB;

        $idnumber = '';
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower((string) ($payload['meta']['slug'] ?? 'leetcode')));
        $fid = preg_replace('/\D+/', '', (string) ($payload['meta']['frontend_id'] ?? ''));
        $idnumber = 'lc' . ($fid !== '' ? $fid : '') . '-' . trim((string) $slug, '-');

        $questionid = 0;
        // Modern question bank (versions + entries).
        if ($DB->get_manager()->table_exists('question_bank_entries')) {
            $sql = "SELECT q.id
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid = ?
                       AND qbe.idnumber = ?
                  ORDER BY qv.version DESC, q.id DESC";
            $questionid = (int) $DB->get_field_sql($sql, [$categoryid, $idnumber], IGNORE_MISSING);
        }
        if (!$questionid) {
            $questionid = (int) $DB->get_field('question', 'id', [
                'name' => (string) ($payload['name'] ?? ''),
                'qtype' => 'coderunner',
            ], IGNORE_MISSING);
        }
        if (!$questionid) {
            return;
        }

        $allornothing = 1;
        if (array_key_exists('allornothing', $options)) {
            $raw = $options['allornothing'];
            $allornothing = ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') ? 0 : 1;
        }

        if ($DB->get_manager()->table_exists('question_coderunner_options')) {
            $DB->set_field('question_coderunner_options', 'allornothing', $allornothing, [
                'questionid' => $questionid,
            ]);
            // Ensure precheck = Examples when requested.
            if (isset($options['precheck'])) {
                $DB->set_field('question_coderunner_options', 'precheck', (int) $options['precheck'], [
                    'questionid' => $questionid,
                ]);
            }
        }

        if ($DB->get_manager()->table_exists('question_coderunner_tests')) {
            $tests = $DB->get_records('question_coderunner_tests', ['questionid' => $questionid], 'id ASC');
            $i = 0;
            foreach ($tests as $test) {
                $useasexample = !empty($test->useasexample) ? 1 : 0;
                // If import lost flags, treat first N example-marked from payload.
                if (isset($payload['testcases'][$i])) {
                    $useasexample = !empty($payload['testcases'][$i]['useasexample']) ? 1 : 0;
                }
                $test->display = 'SHOW';
                $test->useasexample = $useasexample;
                $test->hiderestiffail = $useasexample ? 0 : 1;
                $DB->update_record('question_coderunner_tests', $test);
                $i++;
            }
        }

        $tags = $payload['tags'] ?? [];
        if (is_array($tags) && $tags && class_exists('\core_tag_tag')) {
            try {
                $ctx = null;
                if ($DB->get_manager()->table_exists('question_bank_entries')) {
                    $ctxid = $DB->get_field_sql(
                        "SELECT qc.contextid
                           FROM {question_versions} qv
                           JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                           JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                          WHERE qv.questionid = ?
                       ORDER BY qv.version DESC",
                        [$questionid],
                        IGNORE_MISSING
                    );
                    if ($ctxid) {
                        $ctx = \context::instance_by_id((int) $ctxid, IGNORE_MISSING);
                    }
                }
                if ($ctx) {
                    \core_tag_tag::set_item_tags('core_question', 'question', $questionid, $ctx, $tags);
                }
            } catch (\Throwable $e) {
                // Tags are best-effort; question itself already imported.
            }
        }
    }

    /**
     * Process problems one-by-one; import each immediately so timeouts keep partial work.
     *
     * @param string[] $problems
     * @param array $options
     * @param \stdClass $category
     * @param \context[] $contexts
     * @param \stdClass $course
     * @param callable|null $progress function(string $message, array $row): void
     * @return array{xml:string,results:array,imported:int,failed:int,skipped:int,total:int}
     */
    public function process(
        array $problems,
        array $options,
        \stdClass $category,
        array $contexts,
        \stdClass $course,
        ?callable $progress = null
    ): array {
        if (!\core_component::get_plugin_directory('qtype', 'coderunner')) {
            throw new \moodle_exception('missingcoderunner', 'qbank_leetcodeimport');
        }

        $leetcode = new leetcode_client();
        $openai = new openai_client();
        $builder = new coderunner_builder();

        $converted = [];
        $results = [];
        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $total = count($problems);
        $dryrun = !empty($options['dryrun']);

        foreach ($problems as $i => $raw) {
            $n = $i + 1;
            // Fresh budget per problem (OpenAI can take a while).
            \core_php_time_limit::raise(300);

            $row = [
                'input' => $raw,
                'status' => 'failed',
                'detail' => '',
                'name' => '',
            ];

            if ($progress) {
                $progress(get_string('progress_fetch', 'qbank_leetcodeimport', (object) [
                    'n' => $n,
                    'total' => $total,
                    'id' => $raw,
                ]), $row);
            }

            try {
                $problem = $leetcode->fetch_problem($raw);

                if ($progress) {
                    $progress(get_string('progress_openai', 'qbank_leetcodeimport', (object) [
                        'n' => $n,
                        'total' => $total,
                        'title' => $problem['title'] ?? $raw,
                    ]), $row);
                }

                $payload = $openai->convert_problem($problem, $options);
                $converted[] = $payload;
                $row['name'] = $payload['name'];

                $xmlone = $builder->build_quiz_xml([$payload], $options);

                if ($dryrun) {
                    $row['status'] = 'ok';
                    $row['detail'] = get_string('progress_dryrun', 'qbank_leetcodeimport', count($payload['testcases']));
                    $skipped++;
                } else {
                    if ($progress) {
                        $progress(get_string('progress_import', 'qbank_leetcodeimport', (object) [
                            'n' => $n,
                            'total' => $total,
                            'name' => $payload['name'],
                        ]), $row);
                    }
                    $this->import_xml($xmlone, $category, $contexts, $course, false);
                    $row['status'] = 'ok';
                    $row['detail'] = ($payload['meta']['slug'] ?? '') . ' · '
                        . count($payload['testcases']) . ' tests · imported';
                    $imported++;
                }
            } catch (\Throwable $e) {
                $row['detail'] = $e->getMessage();
                $failed++;
                if ($progress) {
                    $progress(get_string('progress_failed', 'qbank_leetcodeimport', (object) [
                        'n' => $n,
                        'total' => $total,
                        'error' => $e->getMessage(),
                    ]), $row);
                }
                if (!empty($options['stoponerror'])) {
                    $results[] = $row;
                    break;
                }
            }

            $results[] = $row;
        }

        $xml = $converted ? $builder->build_quiz_xml($converted, $options) : '';

        return [
            'xml' => $xml,
            'results' => $results,
            'imported' => $imported,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => $total,
        ];
    }

    /**
     * Import Moodle XML string into a category.
     *
     * @param string $xml
     * @param \stdClass $category
     * @param array $contexts
     * @param \stdClass $course
     * @param bool $stoponerror
     */
    public function import_xml(
        string $xml,
        \stdClass $category,
        array $contexts,
        \stdClass $course,
        bool $stoponerror = false
    ): void {
        $tmpdir = make_request_directory();
        $filename = 'leetcode_coderunner_import.xml';
        $path = $tmpdir . '/' . $filename;
        file_put_contents($path, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts($contexts);
        $qformat->setCourse($course);
        $qformat->setFilename($path);
        $qformat->setRealfilename($filename);
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror($stoponerror);

        if (!$qformat->importpreprocess()) {
            throw new \moodle_exception('importfailed', 'qbank_leetcodeimport');
        }
        if (!$qformat->importprocess()) {
            throw new \moodle_exception('importfailed', 'qbank_leetcodeimport');
        }
        if (!$qformat->importpostprocess()) {
            throw new \moodle_exception('importfailed', 'qbank_leetcodeimport');
        }

        $eventparams = [
            'contextid' => $category->contextid,
            'other' => ['format' => 'xml', 'categoryid' => $category->id],
        ];
        $event = \core\event\questions_imported::create($eventparams);
        $event->trigger();
    }
}
