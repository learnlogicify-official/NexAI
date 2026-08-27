<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import CodeRunner questions into NexCodeLab problems.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

/**
 * CodeRunner → NexCodeLab importer.
 *
 * One CodeRunner question has a single sandbox language/template. We import that
 * language with the source question as its prototype, then authors add other
 * language prototypes / preloads on the edit screen.
 */
class importer {

    /**
     * Map CodeRunner language / Ace labels to NexCodeLab keys.
     *
     * @param string $raw
     * @return string
     */
    public static function normalize_language(string $raw): string {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/\*$/', '', $raw) ?? $raw;
        $map = [
            'python3' => 'python3',
            'python' => 'python3',
            'py' => 'python3',
            'java' => 'java',
            'cpp' => 'cpp',
            'c++' => 'cpp',
            'c' => 'c',
            'javascript' => 'javascript',
            'nodejs' => 'javascript',
            'js' => 'javascript',
            'php' => 'php',
        ];
        return $map[$raw] ?? (in_array($raw, local_nexcodelab_languages(), true) ? $raw : 'python3');
    }

    /**
     * Question banks (contexts) that contain at least one CodeRunner question.
     *
     * @return array{contextid:int,name:string,count:int}[]
     */
    public static function list_banks(): array {
        global $DB;

        if (!runner::coderunner_installed()) {
            return [];
        }
        if (!$DB->get_manager()->table_exists('question_bank_entries')) {
            return self::list_banks_legacy();
        }

        $sql = "SELECT qc.contextid, COUNT(DISTINCT qbe.id) AS qcount
                  FROM {question_categories} qc
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'coderunner'
                  JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                 WHERE opt.prototypetype = 0
              GROUP BY qc.contextid
              ORDER BY qcount DESC";
        $rows = $DB->get_records_sql($sql);
        $out = [];
        foreach ($rows as $row) {
            $contextid = (int) $row->contextid;
            $out[] = [
                'contextid' => $contextid,
                'name' => self::bank_name($contextid),
                'count' => (int) $row->qcount,
            ];
        }
        usort($out, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Fallback when question bank entries table is absent.
     *
     * @return array
     */
    private static function list_banks_legacy(): array {
        global $DB;
        // Pre-qbank: categories hold questions directly via question.category.
        if (!$DB->get_manager()->field_exists('question', 'category')) {
            return [[
                'contextid' => 0,
                'name' => get_string('importallbanks', 'local_nexcodelab'),
                'count' => (int) $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {question} q
                       JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                      WHERE q.qtype = 'coderunner' AND opt.prototypetype = 0"
                ),
            ]];
        }
        $sql = "SELECT qc.contextid, COUNT(DISTINCT q.id) AS qcount
                  FROM {question} q
                  JOIN {question_categories} qc ON qc.id = q.category
                  JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                 WHERE q.qtype = 'coderunner' AND opt.prototypetype = 0
              GROUP BY qc.contextid";
        $rows = $DB->get_records_sql($sql);
        $out = [];
        foreach ($rows as $row) {
            $contextid = (int) $row->contextid;
            $out[] = [
                'contextid' => $contextid,
                'name' => self::bank_name($contextid),
                'count' => (int) $row->qcount,
            ];
        }
        return $out;
    }

    /**
     * Human label for a question-bank context.
     *
     * @param int $contextid
     * @return string
     */
    public static function bank_name(int $contextid): string {
        if ($contextid < 1) {
            return get_string('importallbanks', 'local_nexcodelab');
        }
        try {
            $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$ctx) {
                return get_string('importbankcontext', 'local_nexcodelab', $contextid);
            }
            return $ctx->get_context_name(true, true);
        } catch (\Throwable $e) {
            return get_string('importbankcontext', 'local_nexcodelab', $contextid);
        }
    }

    /**
     * Categories inside a bank that contain CodeRunner questions.
     *
     * @param int $contextid
     * @return array{id:int,name:string,count:int}[]
     */
    public static function list_categories(int $contextid): array {
        global $DB;

        if ($contextid < 1 || !runner::coderunner_installed()) {
            return [];
        }
        if (!$DB->get_manager()->table_exists('question_bank_entries')) {
            return [];
        }

        $sql = "SELECT qc.id, qc.name, qc.parent, COUNT(DISTINCT qbe.id) AS qcount
                  FROM {question_categories} qc
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'coderunner'
                  JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                 WHERE qc.contextid = :ctx
                   AND opt.prototypetype = 0
              GROUP BY qc.id, qc.name, qc.parent
              HAVING COUNT(DISTINCT qbe.id) > 0
              ORDER BY qc.name ASC";
        $rows = $DB->get_records_sql($sql, ['ctx' => $contextid]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => format_string($row->name),
                'count' => (int) $row->qcount,
            ];
        }
        return $out;
    }

    /**
     * Latest CodeRunner questions in a bank (one row per bank entry).
     *
     * @param int $contextid Required bank context
     * @param int $categoryid Optional category filter (0 = all in bank)
     * @param string $search
     * @param int $limit
     * @return array
     */
    public static function search_coderunner(
        int $contextid,
        int $categoryid = 0,
        string $search = '',
        int $limit = 200
    ): array {
        global $DB;

        if (!runner::coderunner_installed() || $contextid < 1) {
            return [];
        }
        if (!$DB->get_manager()->table_exists('question_coderunner_options')) {
            return [];
        }

        $limit = max(1, min(300, $limit));
        $params = ['ctx' => $contextid];
        $searchsql = '';
        if (trim($search) !== '') {
            $searchsql = ' AND (' . $DB->sql_like('q.name', ':qname', false)
                . ' OR ' . $DB->sql_like('opt.language', ':qlang', false) . ')';
            $params['qname'] = '%' . $DB->sql_like_escape(trim($search)) . '%';
            $params['qlang'] = '%' . $DB->sql_like_escape(trim($search)) . '%';
        }
        $catsql = '';
        if ($categoryid > 0) {
            $catsql = ' AND qc.id = :catid';
            $params['catid'] = $categoryid;
        }

        $hasversions = $DB->get_manager()->table_exists('question_versions');
        if ($hasversions) {
            // One row per question bank entry = latest version only (fixes duplicate listings).
            $sql = "SELECT q.id, q.name, opt.language, opt.acelang, opt.coderunnertype,
                           qv.version, qbe.id AS entryid, qc.id AS categoryid, qc.name AS categoryname
                      FROM {question_bank_entries} qbe
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                      JOIN (
                            SELECT questionbankentryid, MAX(version) AS maxversion
                              FROM {question_versions}
                          GROUP BY questionbankentryid
                           ) latest ON latest.questionbankentryid = qbe.id
                      JOIN {question_versions} qv
                           ON qv.questionbankentryid = qbe.id
                          AND qv.version = latest.maxversion
                      JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'coderunner'
                      JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                     WHERE qc.contextid = :ctx
                       AND opt.prototypetype = 0
                       {$catsql}
                       {$searchsql}
                  ORDER BY qc.name ASC, q.name ASC";
        } else {
            $sql = "SELECT q.id, q.name, opt.language, opt.acelang, opt.coderunnertype,
                           0 AS version, q.id AS entryid, qc.id AS categoryid, qc.name AS categoryname
                      FROM {question} q
                      JOIN {question_categories} qc ON qc.id = q.category
                      JOIN {question_coderunner_options} opt ON opt.questionid = q.id
                     WHERE qc.contextid = :ctx
                       AND q.qtype = 'coderunner'
                       AND opt.prototypetype = 0
                       {$catsql}
                       {$searchsql}
                  ORDER BY qc.name ASC, q.name ASC";
        }

        $rows = $DB->get_records_sql($sql, $params, 0, $limit);

        // Belt-and-suspenders: unique by bank entry id.
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $entryid = (int) ($row->entryid ?? $row->id);
            if (isset($seen[$entryid])) {
                continue;
            }
            $seen[$entryid] = true;

            $imported = self::find_imported_for_entry($entryid, (int) $row->id);
            $lang = self::normalize_language((string) ($row->language ?? 'python3'));
            $out[] = [
                'id' => (int) $row->id,
                'entryid' => $entryid,
                'name' => format_string($row->name),
                'language' => $lang,
                'coderunnertype' => (string) ($row->coderunnertype ?? ''),
                'acelang' => (string) ($row->acelang ?? ''),
                'multilanghint' => self::parse_acelang((string) ($row->acelang ?? '')),
                'category' => format_string((string) ($row->categoryname ?? '')),
                'version' => (int) ($row->version ?? 0),
                'imported' => $imported !== null,
                'problemid' => $imported ? (int) $imported->id : 0,
                'problemstatus' => $imported ? (string) $imported->status : '',
            ];
        }
        return $out;
    }

    /**
     * Find an existing NexCodeLab problem imported from this bank entry (any version).
     *
     * @param int $entryid
     * @param int $questionid fallback
     * @return \stdClass|null
     */
    private static function find_imported_for_entry(int $entryid, int $questionid): ?\stdClass {
        global $DB;

        $direct = $DB->get_record('local_nexcodelab_problem', [
            'sourcequestionid' => $questionid,
        ], 'id, name, status');
        if ($direct) {
            return $direct;
        }

        if (!$DB->get_manager()->table_exists('question_versions') || $entryid < 1) {
            return null;
        }

        $sql = "SELECT p.id, p.name, p.status
                  FROM {local_nexcodelab_problem} p
                  JOIN {question_versions} qv ON qv.questionid = p.sourcequestionid
                 WHERE qv.questionbankentryid = :eid
              ORDER BY p.timemodified DESC";
        $rec = $DB->get_record_sql($sql, ['eid' => $entryid]);
        return $rec ?: null;
    }

    /**
     * @param string $acelang
     * @return string[] NexCodeLab language keys hinted by Ace multi-lang
     */
    public static function parse_acelang(string $acelang): array {
        if (trim($acelang) === '' || strpos($acelang, ',') === false) {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $acelang) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $key = self::normalize_language($p);
            if (!in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Link a CodeRunner question into NexCodeLab (does not copy tests/templates).
     * Run/Submit use the live CodeRunner question.
     *
     * @param int $questionid
     * @param int $userid
     * @param array $options difficulty, status, skipifexists
     * @return array{problemid:int,created:bool,language:string,message:string}
     */
    public static function import_question(int $questionid, int $userid, array $options = []): array {
        global $DB;

        if (!runner::coderunner_available()) {
            throw new \moodle_exception('nocoderunner', 'local_nexcodelab');
        }

        // Always link the latest version of this bank entry.
        $questionid = runner::latest_question_id($questionid) ?: $questionid;

        $skipifexists = !empty($options['skipifexists']);
        $existing = self::find_problem_for_question($questionid);
        if ($existing && $skipifexists) {
            // Still refresh the link to the latest CR version.
            if ((int) $existing->sourcequestionid !== $questionid) {
                $DB->set_field('local_nexcodelab_problem', 'sourcequestionid', $questionid, ['id' => $existing->id]);
                $DB->delete_records('local_nexcodelab_testcase', ['problemid' => $existing->id]);
            }
            return [
                'problemid' => (int) $existing->id,
                'created' => false,
                'language' => (string) $existing->defaultlanguage,
                'message' => 'already_imported',
            ];
        }

        runner::bootstrap_coderunner();
        $question = \question_bank::load_question($questionid);
        if (!($question instanceof \qtype_coderunner_question)) {
            throw new \moodle_exception('importnotcoderunner', 'local_nexcodelab');
        }

        $lang = self::normalize_language((string) ($question->language ?? 'python3'));
        $name = format_string($question->name);
        // Cached copy for list/search only — IDE always reads live CR questiontext + tests.
        $statement = (string) ($question->questiontext ?? '');
        $slug = self::unique_slug($name, $questionid);
        $now = time();
        $difficulty = in_array($options['difficulty'] ?? '', ['easy', 'medium', 'hard', 'veryhard'], true)
            ? $options['difficulty'] : 'medium';
        // Default ready so linked CR questions appear immediately.
        $status = ($options['status'] ?? 'ready') === 'draft' ? 'draft' : 'ready';

        if ($existing) {
            $existing->name = $name;
            $existing->statement = $statement;
            $existing->defaultlanguage = $lang;
            $existing->sourcequestionid = $questionid;
            $existing->timemodified = $now;
            $existing->usermodified = $userid;
            if (!empty($options['status'])) {
                $existing->status = $status;
            }
            if (!empty($options['difficulty'])) {
                $existing->difficulty = $difficulty;
            }
            $DB->update_record('local_nexcodelab_problem', $existing);
            $pid = (int) $existing->id;
            $created = false;
        } else {
            $pid = (int) $DB->insert_record('local_nexcodelab_problem', (object) [
                'name' => $name,
                'slug' => $slug,
                'statement' => $statement,
                'difficulty' => $difficulty,
                'track' => (string) ($options['track'] ?? 'wrangling'),
                'fixturepath' => (string) ($options['fixturepath'] ?? ''),
                'status' => $status,
                'defaultlanguage' => $lang,
                'sourcequestionid' => $questionid,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
            $created = true;
        }

        // Linked problems never keep local test copies — live CR is the only source.
        $DB->delete_records('local_nexcodelab_testcase', ['problemid' => $pid]);

        // Optional metadata only — execution ignores these and uses sourcequestionid.
        $DB->delete_records('local_nexcodelab_lang', ['problemid' => $pid]);
        $DB->insert_record('local_nexcodelab_lang', (object) [
            'problemid' => $pid,
            'language' => $lang,
            'preload' => (string) ($question->answerpreload ?? ''),
            'prototype' => $questionid,
        ]);
        foreach (self::parse_acelang((string) ($question->acelang ?? '')) as $extralang) {
            if ($extralang === $lang) {
                continue;
            }
            $DB->insert_record('local_nexcodelab_lang', (object) [
                'problemid' => $pid,
                'language' => $extralang,
                'preload' => '',
                'prototype' => $questionid,
            ]);
        }

        self::ensure_tag($pid, 'coderunner');
        if (!empty($question->coderunnertype)) {
            self::ensure_tag($pid, \core_text::strtolower((string) $question->coderunnertype));
        }

        return [
            'problemid' => $pid,
            'created' => $created,
            'language' => $lang,
            'message' => $created ? 'created' : 'updated',
        ];
    }

    /**
     * Find a NexCodeLab problem linked to this CR question or any version of its bank entry.
     *
     * @param int $questionid
     * @return \stdClass|null
     */
    public static function find_problem_for_question(int $questionid): ?\stdClass {
        global $DB;

        $direct = $DB->get_record('local_nexcodelab_problem', ['sourcequestionid' => $questionid]);
        if ($direct) {
            return $direct;
        }

        if (!$DB->get_manager()->table_exists('question_versions') || $questionid < 1) {
            return null;
        }

        try {
            $sql = "SELECT p.*
                      FROM {local_nexcodelab_problem} p
                      JOIN {question_versions} qv_link ON qv_link.questionid = p.sourcequestionid
                      JOIN {question_versions} qv_new ON qv_new.questionbankentryid = qv_link.questionbankentryid
                     WHERE qv_new.questionid = :qid
                  ORDER BY p.timemodified DESC";
            return $DB->get_record_sql($sql, ['qid' => $questionid], IGNORE_MISSING) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string $name
     * @param int $questionid
     * @return string
     */
    private static function unique_slug(string $name, int $questionid): string {
        global $DB;
        $base = \core_text::strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'problem';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'cr-' . $questionid;
        }
        $slug = \core_text::substr($base, 0, 80);
        $candidate = $slug;
        $n = 1;
        while ($DB->record_exists('local_nexcodelab_problem', ['slug' => $candidate])) {
            $candidate = \core_text::substr($slug, 0, 70) . '-' . $questionid . ($n > 1 ? '-' . $n : '');
            $n++;
        }
        return $candidate;
    }

    /**
     * @param int $problemid
     * @param string $tagname
     */
    private static function ensure_tag(int $problemid, string $tagname): void {
        global $DB;
        $tagname = \core_text::strtolower(trim($tagname));
        if ($tagname === '') {
            return;
        }
        $tag = $DB->get_record('local_nexcodelab_tag', ['name' => $tagname]);
        if (!$tag) {
            $tid = $DB->insert_record('local_nexcodelab_tag', (object) ['name' => $tagname]);
        } else {
            $tid = (int) $tag->id;
        }
        if (!$DB->record_exists('local_nexcodelab_problem_tag', ['problemid' => $problemid, 'tagid' => $tid])) {
            $DB->insert_record('local_nexcodelab_problem_tag', (object) [
                'problemid' => $problemid,
                'tagid' => $tid,
            ]);
        }
    }
}
