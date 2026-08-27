<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Problem catalog helpers.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read/write problem catalog data.
 */
class catalog {

    /**
     * @param int $userid
     * @param array $filters
     * @return array
     */
    public static function list_problems(int $userid, array $filters = []): array {
        global $DB;

        $search = trim((string) ($filters['search'] ?? ''));
        $difficulty = strtolower(trim((string) ($filters['difficulty'] ?? '')));
        $statusfilter = strtolower(trim((string) ($filters['userstatus'] ?? 'all')));
        $tagid = (int) ($filters['tagid'] ?? 0);
        $tagids = $filters['tagids'] ?? [];
        if (!is_array($tagids)) {
            $tagids = [];
        }
        $tagids = array_values(array_unique(array_filter(array_map('intval', $tagids))));
        if ($tagid > 0) {
            $tagids[] = $tagid;
            $tagids = array_values(array_unique($tagids));
        }
        $companyids = $filters['companyids'] ?? [];
        if (!is_array($companyids)) {
            $companyids = [];
        }
        $companyids = array_values(array_unique(array_filter(array_map('intval', $companyids))));
        $page = max(0, (int) ($filters['page'] ?? 0));
        $perpage = max(1, min(50, (int) ($filters['perpage'] ?? 20)));

        $params = [];
        $where = ["p.status = 'ready'"];
        if ($search !== '') {
            $where[] = '(' . $DB->sql_like('p.name', ':search', false)
                . ' OR EXISTS (SELECT 1 FROM {local_learnlogic_problem_tag} pts
                      JOIN {local_learnlogic_tag} ts ON ts.id = pts.tagid
                     WHERE pts.problemid = p.id AND '
                . $DB->sql_like('ts.name', ':searchtag', false) . '))';
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['searchtag'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        if (in_array($difficulty, ['easy', 'medium', 'hard', 'veryhard'], true)) {
            $where[] = 'p.difficulty = :diff';
            $params['diff'] = $difficulty;
        }
        // Topic tags: match any selected (OR). Company tags: match any selected (OR).
        // Across kinds: both constraints apply (AND).
        if (!empty($tagids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($tagids, SQL_PARAMS_NAMED, 'filtag');
            $where[] = 'EXISTS (SELECT 1 FROM {local_learnlogic_problem_tag} pt
                WHERE pt.problemid = p.id AND pt.tagid ' . $insql . ')';
            $params = array_merge($params, $inparams);
        }
        if (!empty($companyids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED, 'filco');
            $where[] = 'EXISTS (SELECT 1 FROM {local_learnlogic_problem_tag} pt
                WHERE pt.problemid = p.id AND pt.tagid ' . $insql . ')';
            $params = array_merge($params, $inparams);
        }
        $sqlwhere = implode(' AND ', $where);

        $sql = "SELECT p.*
                  FROM {local_learnlogic_problem} p
                 WHERE {$sqlwhere}
              ORDER BY p.timemodified DESC";
        $all = $DB->get_records_sql($sql, $params);

        $accepted = [];
        $attempted = [];
        $battled = [];
        if ($userid > 0 && $all) {
            $ids = array_keys($all);
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'pid');
            $paramsuid = array_merge(['uid' => $userid], $inparams);

            // Distinct problem ids — do NOT use get_records keyed by problemid+status
            // (Moodle keys on the first column, so multiple statuses for one problem overwrite).
            $attemptedids = $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_learnlogic_submission}
                  WHERE userid = :uid AND problemid {$insql}",
                $paramsuid
            );
            $acceptedids = $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_learnlogic_submission}
                  WHERE userid = :uid AND status = :st AND problemid {$insql}",
                array_merge($paramsuid, ['st' => 'ACCEPTED'])
            );
            foreach ($attemptedids as $pid) {
                $attempted[(int) $pid] = true;
            }
            foreach ($acceptedids as $pid) {
                $accepted[(int) $pid] = true;
            }
            $battled = battle_progress::won_map($userid, array_map('intval', $ids));
        }

        $filtered = [];
        foreach ($all as $p) {
            $pid = (int) $p->id;
            $ustatus = self::user_status_for_problem($accepted, $attempted, $battled, $pid);
            if ($statusfilter !== 'all' && $statusfilter !== $ustatus) {
                continue;
            }
            $p->userstatus = $ustatus;
            $p->battled = ($ustatus === 'battled') || (!empty($battled[$pid]) && $ustatus !== 'completed');
            $filtered[] = $p;
        }

        $total = count($filtered);
        $slice = array_slice($filtered, $page * $perpage, $perpage);
        $items = [];
        $indexbase = $page * $perpage;
        foreach ($slice as $i => $p) {
            $summary = self::export_problem_summary($p, $userid);
            $summary['number'] = $indexbase + $i + 1;
            $items[] = $summary;
        }

        $counts = ['all' => 0, 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0, 'battled' => 0];
        foreach ($all as $p) {
            $pid = (int) $p->id;
            $ustatus = self::user_status_for_problem($accepted, $attempted, $battled, $pid);
            $counts['all']++;
            $counts[$ustatus]++;
        }

        return [
            'problems' => $items,
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
            'counts' => $counts,
        ];
    }

    /**
     * Resolve learner progress for one catalog problem.
     *
     * @param array<int, true> $accepted
     * @param array<int, true> $attempted
     * @param array<int, true> $battled
     * @param int $problemid
     * @return string completed|inprogress|battled|notstarted
     */
    private static function user_status_for_problem(
        array $accepted,
        array $attempted,
        array $battled,
        int $problemid
    ): string {
        if (!empty($accepted[$problemid])) {
            return 'completed';
        }
        if (!empty($attempted[$problemid])) {
            return 'inprogress';
        }
        if (!empty($battled[$problemid])) {
            return 'battled';
        }
        return 'notstarted';
    }

    /**
     * @param \stdClass $p
     * @param int $userid
     * @return array
     */
    public static function export_problem_summary($p, int $userid = 0): array {
        global $DB;
        $hasekind = manage::tag_kind_supported();
        $kindselect = $hasekind ? ', t.kind' : ", 'topic' AS kind";
        $tags = $DB->get_records_sql(
            "SELECT t.id, t.name{$kindselect}
               FROM {local_learnlogic_tag} t
               JOIN {local_learnlogic_problem_tag} pt ON pt.tagid = t.id
              WHERE pt.problemid = ?
           ORDER BY t.name ASC",
            [(int) $p->id]
        );
        $taglist = [];
        $companylist = [];
        foreach ($tags as $t) {
            $kind = manage::normalize_tag_kind((string) ($t->kind ?? 'topic'));
            $row = [
                'id' => (int) $t->id,
                'name' => manage::tag_display_name((string) $t->name, $kind),
                'kind' => $kind,
                'count' => 0,
            ];
            if ($kind === 'company') {
                $companylist[] = $row;
            } else {
                $taglist[] = $row;
            }
        }

        $solvers = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {local_learnlogic_submission}
              WHERE problemid = ? AND status = 'ACCEPTED'",
            [(int) $p->id]
        );
        $totalsubs = (int) $DB->count_records('local_learnlogic_submission', ['problemid' => (int) $p->id]);
        $acceptedsubs = (int) $DB->count_records('local_learnlogic_submission', [
            'problemid' => (int) $p->id,
            'status' => 'ACCEPTED',
        ]);
        $acceptance = $totalsubs > 0 ? (int) round(($acceptedsubs / $totalsubs) * 100) : 0;
        $estimates = ['easy' => 5, 'medium' => 15, 'hard' => 25, 'veryhard' => 40];

        return [
            'id' => (int) $p->id,
            'number' => 0,
            'name' => $p->name,
            'slug' => $p->slug,
            'difficulty' => $p->difficulty,
            'status' => $p->status,
            'userstatus' => $p->userstatus ?? 'notstarted',
            'battled' => !empty($p->battled),
            'tags' => $taglist,
            'companies' => $companylist,
            'url' => (new \moodle_url('/local/learnlogic/problem.php', ['id' => $p->id]))->out(false),
            'solvers' => $solvers,
            'acceptance' => $acceptance,
            'estimateminutes' => $estimates[$p->difficulty] ?? 15,
        ];
    }

    /**
     * @param int $problemid
     * @param int $userid
     * @param bool $includetests
     * @return array|null
     */
    public static function get_problem(int $problemid, int $userid = 0, bool $includetests = true): ?array {
        global $DB;
        $p = $DB->get_record('local_learnlogic_problem', ['id' => $problemid]);
        if (!$p) {
            return null;
        }

        $summary = self::export_problem_summary($p, $userid);
        if ($userid > 0) {
            if ($DB->record_exists('local_learnlogic_submission', [
                'userid' => $userid, 'problemid' => $problemid, 'status' => 'ACCEPTED',
            ])) {
                $summary['userstatus'] = 'completed';
                $summary['battled'] = battle_progress::won_map($userid, [$problemid]) !== [];
            } else if ($DB->record_exists('local_learnlogic_submission', [
                'userid' => $userid, 'problemid' => $problemid,
            ])) {
                $summary['userstatus'] = 'inprogress';
                $summary['battled'] = false;
            } else if (!empty(battle_progress::won_map($userid, [$problemid]))) {
                $summary['userstatus'] = 'battled';
                $summary['battled'] = true;
            } else {
                $summary['battled'] = false;
            }
        }

        $drafts = [];
        if ($userid > 0) {
            foreach ($DB->get_records('local_learnlogic_draft', ['userid' => $userid, 'problemid' => $problemid]) as $d) {
                $drafts[$d->language] = $d->code;
            }
        }

        // Prefer live CodeRunner question for statement / samples / languages.
        if (!empty($p->sourcequestionid) && runner::coderunner_available()) {
            try {
                $fromcr = self::hydrate_from_coderunner($p, $includetests);
                return self::attach_editorial_metadata($problemid, array_merge($summary, $fromcr, [
                    'drafts' => (object) $drafts,
                    'sourcequestionid' => (int) $p->sourcequestionid,
                ]));
            } catch (\Throwable $e) {
                debugging('NexPractice hydrate CR: ' . $e->getMessage(), DEBUG_DEVELOPER);
                // Linked problems must still use live questiontext, not a local duplicate.
                $fallback = self::hydrate_from_local($p, $problemid, $includetests);
                $fallback['statement'] = self::statement_from_question_id((int) $p->sourcequestionid)
                    ?: ($fallback['statement'] ?? '');
                $fallbackqid = runner::latest_question_id((int) $p->sourcequestionid) ?: (int) $p->sourcequestionid;
                $crname = $DB->get_field('question', 'name', ['id' => $fallbackqid]);
                $fallback['name'] = format_string($crname !== false ? $crname : ($p->name ?? ''));
                if ($includetests && empty($fallback['samples'])) {
                    $raw = self::coderunner_test_rows((int) $p->sourcequestionid);
                    $samples = [];
                    foreach (array_slice($raw, 0, 3) as $i => $t) {
                        $samples[] = [
                            'id' => $i + 1,
                            'stdin' => (string) ($t->stdin ?? ''),
                            'expected' => (string) ($t->expected ?? ''),
                            'display' => 'sample',
                            'explanation' => (string) ($t->extra ?? ''),
                        ];
                    }
                    $fallback['samples'] = $samples;
                    $fallback['hiddenCount'] = max(0, count($raw) - count($samples));
                }
                return self::attach_editorial_metadata($problemid, array_merge($summary, $fallback, [
                    'drafts' => (object) $drafts,
                    'sourcequestionid' => (int) $p->sourcequestionid,
                ]));
            }
        }

        return self::attach_editorial_metadata($problemid, array_merge($summary, self::hydrate_from_local($p, $problemid, $includetests), [
            'drafts' => (object) $drafts,
            'sourcequestionid' => (int) ($p->sourcequestionid ?? 0),
        ]));
    }

    /**
     * Attach NexPractice editorial solutions and sample explanation overrides.
     *
     * @param int $problemid
     * @param array $payload
     * @return array
     */
    private static function attach_editorial_metadata(int $problemid, array $payload): array {
        $solutions = array_values(solutions::for_problem($problemid));
        foreach ($solutions as &$sol) {
            $sol['explanation'] = self::format_editorial_html((string) ($sol['explanation'] ?? ''));
        }
        unset($sol);
        $payload['solutions'] = $solutions;
        if (!empty($payload['samples']) && is_array($payload['samples'])) {
            $payload['samples'] = solutions::merge_sample_explanations($problemid, $payload['samples']);
            foreach ($payload['samples'] as &$sample) {
                $sample['explanation'] = self::format_editorial_html((string) ($sample['explanation'] ?? ''));
            }
            unset($sample);
        }
        return $payload;
    }

    /**
     * Format teacher HTML (or plain CR extra text) for the IDE.
     *
     * @param string $text
     * @return string
     */
    private static function format_editorial_html(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $format = preg_match('/<[a-z][\s\S]*>/i', $text) ? FORMAT_HTML : FORMAT_MOODLE;
        return format_text($text, $format, ['filter' => false, 'para' => false]);
    }

    /**
     * Build IDE payload from the linked CodeRunner question.
     *
     * @param \stdClass $p
     * @param bool $includetests
     * @return array
     */
    private static function hydrate_from_coderunner($p, bool $includetests): array {
        $q = runner::load_problem_question($p);

        $defaultlang = importer::normalize_language((string) ($q->language ?? $p->defaultlanguage ?? 'python3'));
        $preload = (string) ($q->answerpreload ?? '');
        if ($preload === '' && !empty($q->answer)) {
            $preload = (string) $q->answer;
        }

        $languages = [];
        $acelangs = importer::parse_acelang((string) ($q->acelang ?? ''));
        if (empty($acelangs)) {
            $acelangs = [$defaultlang];
        }
        foreach ($acelangs as $lang) {
            $languages[] = [
                'language' => $lang,
                'preload' => ($lang === $defaultlang) ? $preload : '',
                'prototype' => (int) $q->id,
            ];
        }

        $samples = [];
        $hidden = 0;
        if ($includetests) {
            // Prefer CodeRunner's own example_testcases() (same as Precheck → Examples).
            runner::prepare_question_for_run($q);
            $parts = runner::partition_testcases($q);
            foreach ($parts['samples'] as $i => $t) {
                $samples[] = [
                    'id' => $i + 1,
                    'stdin' => (string) ($t->stdin ?? ''),
                    'expected' => (string) ($t->expected ?? ''),
                    'display' => 'sample',
                    'explanation' => (string) ($t->extra ?? ''),
                ];
            }
            $hidden = count($parts['hidden']);
        }

        return [
            'name' => format_string($q->name),
            'statement' => self::format_question_statement($q),
            'defaultlanguage' => $defaultlang,
            'languages' => $languages,
            'samples' => $samples,
            'hiddenCount' => $hidden,
        ];
    }

    /**
     * Format live CodeRunner / Moodle questiontext for the IDE (no local copy).
     *
     * @param \question_definition $q
     * @return string HTML
     */
    private static function format_question_statement(\question_definition $q): string {
        $text = (string) ($q->questiontext ?? '');
        $format = (int) ($q->questiontextformat ?? FORMAT_HTML);
        return self::format_questiontext_html((int) $q->id, $text, $format);
    }

    /**
     * Read + format questiontext from the question table (fallback when load_question fails).
     *
     * @param int $questionid
     * @return string HTML
     */
    private static function statement_from_question_id(int $questionid): string {
        global $DB;
        $questionid = runner::latest_question_id($questionid) ?: $questionid;
        if ($questionid < 1) {
            return '';
        }
        $row = $DB->get_record('question', ['id' => $questionid], 'id, questiontext, questiontextformat');
        if (!$row) {
            return '';
        }
        return self::format_questiontext_html(
            (int) $row->id,
            (string) ($row->questiontext ?? ''),
            (int) ($row->questiontextformat ?? FORMAT_HTML)
        );
    }

    /**
     * Rewrite pluginfile URLs and format_text for a question's questiontext.
     *
     * @param int $questionid
     * @param string $text
     * @param int $format
     * @return string HTML
     */
    private static function format_questiontext_html(int $questionid, string $text, int $format): string {
        global $CFG, $DB;
        if ($text === '') {
            return '';
        }
        require_once($CFG->libdir . '/filelib.php');

        $context = null;
        try {
            if ($DB->get_manager()->table_exists('question_versions')
                    && $DB->get_manager()->table_exists('question_bank_entries')) {
                $sql = "SELECT qc.contextid
                          FROM {question_versions} qv
                          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                          JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                         WHERE qv.questionid = :qid";
                $ctxid = (int) $DB->get_field_sql($sql, ['qid' => $questionid], IGNORE_MISSING);
                if ($ctxid > 0) {
                    $context = \context::instance_by_id($ctxid);
                }
            }
        } catch (\Throwable $e) {
            $context = null;
        }

        if ($context) {
            $text = file_rewrite_pluginfile_urls(
                $text,
                'pluginfile.php',
                $context->id,
                'question',
                'questiontext',
                $questionid
            );
        }

        $options = [
            'para' => false,
            'filter' => true,
            'noclean' => false,
            'overflowdiv' => false,
        ];
        if ($context) {
            $options['context'] = $context;
        }
        return format_text($text, $format, $options);
    }

    /**
     * Read CodeRunner testcase rows directly (works even if prototype merge failed).
     *
     * @param int $questionid
     * @return \stdClass[]
     */
    private static function coderunner_test_rows(int $questionid): array {
        global $DB;
        if ($questionid < 1 || !$DB->get_manager()->table_exists('question_coderunner_tests')) {
            return [];
        }
        // Always the latest bank version of this question.
        $questionid = runner::latest_question_id($questionid);
        $rows = array_values($DB->get_records(
            'question_coderunner_tests',
            ['questionid' => $questionid],
            'id ASC'
        ));
        usort($rows, static function ($a, $b) {
            $oa = isset($a->ordering) ? (int) $a->ordering : 0;
            $ob = isset($b->ordering) ? (int) $b->ordering : 0;
            if ($oa === $ob) {
                return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
            }
            return $oa <=> $ob;
        });
        return $rows;
    }

    /**
     * Legacy local catalog fields (non-linked / seed problems).
     *
     * @param \stdClass $p
     * @param int $problemid
     * @param bool $includetests
     * @return array
     */
    private static function hydrate_from_local($p, int $problemid, bool $includetests): array {
        global $DB;

        $langs = $DB->get_records('local_learnlogic_lang', ['problemid' => $problemid], 'language ASC');
        $languages = [];
        foreach ($langs as $l) {
            $languages[] = [
                'language' => $l->language,
                'preload' => (string) ($l->preload ?? ''),
                'prototype' => (int) $l->prototype,
            ];
        }

        $samples = [];
        $hidden = [];
        // Linked CR problems never use local_learnlogic_testcase — live CR only.
        if ($includetests && empty($p->sourcequestionid)) {
            $tests = $DB->get_records('local_learnlogic_testcase', ['problemid' => $problemid], 'sortorder ASC, id ASC');
            foreach ($tests as $t) {
                $row = [
                    'id' => (int) $t->id,
                    'stdin' => (string) ($t->stdin ?? ''),
                    'expected' => (string) ($t->expected ?? ''),
                    'display' => $t->display,
                    'explanation' => (string) ($t->explanation ?? ''),
                ];
                if ($t->display === 'sample') {
                    $samples[] = $row;
                } else {
                    $hidden[] = ['id' => $row['id'], 'display' => 'hidden'];
                }
            }
        }

        return [
            'statement' => empty($p->sourcequestionid) ? (string) ($p->statement ?? '') : '',
            'defaultlanguage' => $p->defaultlanguage,
            'languages' => $languages,
            'samples' => $samples,
            'hiddenCount' => count($hidden),
        ];
    }

    /**
     * @param int $problemid
     * @param string $display sample|hidden|all
     * @return \stdClass[]
     */
    public static function get_testcases(int $problemid, string $display = 'all'): array {
        global $DB;

        $p = $DB->get_record('local_learnlogic_problem', ['id' => $problemid], 'id, sourcequestionid');
        if ($p && !empty($p->sourcequestionid) && runner::coderunner_available()) {
            try {
                $q = runner::load_problem_question($p);
                runner::prepare_question_for_run($q);
                $parts = runner::partition_testcases($q);
                if ($display === 'sample') {
                    return $parts['samples'];
                }
                if ($display === 'hidden') {
                    return $parts['hidden'];
                }
                return array_merge($parts['samples'], $parts['hidden']);
            } catch (\Throwable $e) {
                $raw = self::coderunner_test_rows((int) $p->sourcequestionid);
                if ($display === 'sample') {
                    return array_values(array_filter($raw, static function ($t) {
                        return !empty($t->useasexample);
                    }));
                }
                if ($display === 'hidden') {
                    return array_values(array_filter($raw, static function ($t) {
                        return empty($t->useasexample);
                    }));
                }
                return $raw;
            }
        }

        $params = ['problemid' => $problemid];
        $sql = "SELECT * FROM {local_learnlogic_testcase} WHERE problemid = :problemid";
        if ($display === 'sample' || $display === 'hidden') {
            $sql .= " AND display = :display";
            $params['display'] = $display;
        }
        $sql .= " ORDER BY sortorder ASC, id ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Tags for filters, optionally limited by kind.
     *
     * @param string $kind all|topic|company
     * @return array
     */
    public static function all_tags(string $kind = 'topic'): array {
        global $DB;
        $hasekind = manage::tag_kind_supported();
        $kindselect = $hasekind ? 't.kind' : "'topic' AS kind";
        $groupkind = $hasekind ? ', t.kind' : '';
        $where = '';
        $params = [];
        $kind = \core_text::strtolower(trim($kind));
        if ($kind === 'topic' || $kind === 'company') {
            if ($hasekind) {
                $where = ' WHERE t.kind = :kind';
                $params['kind'] = $kind;
            } else if ($kind === 'company') {
                return [];
            }
        }
        $rows = $DB->get_records_sql(
            "SELECT t.id, t.name, {$kindselect}, COUNT(p.id) AS usecount
               FROM {local_learnlogic_tag} t
               LEFT JOIN {local_learnlogic_problem_tag} pt ON pt.tagid = t.id
               LEFT JOIN {local_learnlogic_problem} p ON p.id = pt.problemid AND p.status = 'ready'
              {$where}
           GROUP BY t.id, t.name{$groupkind}
           ORDER BY usecount DESC, t.name ASC",
            $params
        );
        $out = [];
        foreach ($rows as $t) {
            $tkind = manage::normalize_tag_kind((string) ($t->kind ?? 'topic'));
            $out[] = [
                'id' => (int) $t->id,
                'name' => manage::tag_display_name((string) $t->name, $tkind),
                'kind' => $tkind,
                'count' => (int) $t->usecount,
            ];
        }
        return $out;
    }
}
