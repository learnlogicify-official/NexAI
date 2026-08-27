<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Problem catalog helpers.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Read/write challenge catalog data.
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
        $track = strtolower(trim((string) ($filters['track'] ?? '')));
        $statusfilter = strtolower(trim((string) ($filters['userstatus'] ?? 'all')));
        $tagid = (int) ($filters['tagid'] ?? 0);
        $page = max(0, (int) ($filters['page'] ?? 0));
        $perpage = max(1, min(50, (int) ($filters['perpage'] ?? 20)));

        $params = [];
        $where = ["p.status = 'ready'"];
        if ($search !== '') {
            $where[] = '(' . $DB->sql_like('p.name', ':search', false)
                . ' OR EXISTS (SELECT 1 FROM {local_nexcodelab_problem_tag} pts
                      JOIN {local_nexcodelab_tag} ts ON ts.id = pts.tagid
                     WHERE pts.problemid = p.id AND '
                . $DB->sql_like('ts.name', ':searchtag', false) . '))';
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['searchtag'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        if (in_array($difficulty, ['easy', 'medium', 'hard', 'veryhard'], true)) {
            $where[] = 'p.difficulty = :diff';
            $params['diff'] = $difficulty;
        }
        if (in_array($track, local_nexcodelab_tracks(), true)) {
            $where[] = 'p.track = :track';
            $params['track'] = $track;
        }
        if ($tagid > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {local_nexcodelab_problem_tag} pt
                WHERE pt.problemid = p.id AND pt.tagid = :tagid)';
            $params['tagid'] = $tagid;
        }
        $sqlwhere = implode(' AND ', $where);

        $sql = "SELECT p.*
                  FROM {local_nexcodelab_problem} p
                 WHERE {$sqlwhere}
              ORDER BY p.timemodified DESC";
        $all = $DB->get_records_sql($sql, $params);

        $accepted = [];
        $attempted = [];
        if ($userid > 0 && $all) {
            $ids = array_keys($all);
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'pid');
            $paramsuid = array_merge(['uid' => $userid], $inparams);

            // Distinct problem ids — do NOT use get_records keyed by problemid+status
            // (Moodle keys on the first column, so multiple statuses for one problem overwrite).
            $attemptedids = $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_nexcodelab_submission}
                  WHERE userid = :uid AND problemid {$insql}",
                $paramsuid
            );
            $acceptedids = $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_nexcodelab_submission}
                  WHERE userid = :uid AND status = :st AND problemid {$insql}",
                array_merge($paramsuid, ['st' => 'ACCEPTED'])
            );
            foreach ($attemptedids as $pid) {
                $attempted[(int) $pid] = true;
            }
            foreach ($acceptedids as $pid) {
                $accepted[(int) $pid] = true;
            }
        }

        $filtered = [];
        foreach ($all as $p) {
            $pid = (int) $p->id;
            $ustatus = 'notstarted';
            if (!empty($accepted[$pid])) {
                $ustatus = 'completed';
            } else if (!empty($attempted[$pid])) {
                $ustatus = 'inprogress';
            }
            if ($statusfilter !== 'all' && $statusfilter !== $ustatus) {
                continue;
            }
            $p->userstatus = $ustatus;
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

        $counts = ['all' => 0, 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0];
        foreach ($all as $p) {
            $pid = (int) $p->id;
            $ustatus = !empty($accepted[$pid]) ? 'completed'
                : (!empty($attempted[$pid]) ? 'inprogress' : 'notstarted');
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
     * @param \stdClass $p
     * @param int $userid
     * @return array
     */
    public static function export_problem_summary($p, int $userid = 0): array {
        global $DB;
        $tags = $DB->get_records_sql(
            "SELECT t.id, t.name
               FROM {local_nexcodelab_tag} t
               JOIN {local_nexcodelab_problem_tag} pt ON pt.tagid = t.id
              WHERE pt.problemid = ?",
            [(int) $p->id]
        );
        $taglist = [];
        foreach ($tags as $t) {
            $taglist[] = ['id' => (int) $t->id, 'name' => $t->name];
        }

        $solvers = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {local_nexcodelab_submission}
              WHERE problemid = ? AND status = 'ACCEPTED'",
            [(int) $p->id]
        );
        $totalsubs = (int) $DB->count_records('local_nexcodelab_submission', ['problemid' => (int) $p->id]);
        $acceptedsubs = (int) $DB->count_records('local_nexcodelab_submission', [
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
            'track' => (string) ($p->track ?? 'wrangling'),
            'fixturepath' => (string) ($p->fixturepath ?? ''),
            'status' => $p->status,
            'userstatus' => $p->userstatus ?? 'notstarted',
            'tags' => $taglist,
            'url' => (new \moodle_url('/local/nexcodelab/problem.php', ['id' => $p->id]))->out(false),
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
        $p = $DB->get_record('local_nexcodelab_problem', ['id' => $problemid]);
        if (!$p) {
            return null;
        }

        $summary = self::export_problem_summary($p, $userid);
        if ($userid > 0) {
            if ($DB->record_exists('local_nexcodelab_submission', [
                'userid' => $userid, 'problemid' => $problemid, 'status' => 'ACCEPTED',
            ])) {
                $summary['userstatus'] = 'completed';
            } else if ($DB->record_exists('local_nexcodelab_submission', [
                'userid' => $userid, 'problemid' => $problemid,
            ])) {
                $summary['userstatus'] = 'inprogress';
            }
        }

        $drafts = [];
        if ($userid > 0) {
            foreach ($DB->get_records('local_nexcodelab_draft', ['userid' => $userid, 'problemid' => $problemid]) as $d) {
                $drafts[$d->language] = $d->code;
            }
        }

        // Prefer live CodeRunner question for statement / samples / languages.
        if (!empty($p->sourcequestionid) && runner::coderunner_available()) {
            try {
                $fromcr = self::hydrate_from_coderunner($p, $includetests);
                return array_merge($summary, $fromcr, [
                    'drafts' => (object) $drafts,
                    'sourcequestionid' => (int) $p->sourcequestionid,
                ]);
            } catch (\Throwable $e) {
                debugging('NexCodeLab hydrate CR: ' . $e->getMessage(), DEBUG_DEVELOPER);
                // Still try to surface CR tests + statement without full question load.
                $fallback = self::hydrate_from_local($p, $problemid, $includetests);
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
                return array_merge($summary, $fallback, [
                    'drafts' => (object) $drafts,
                    'sourcequestionid' => (int) $p->sourcequestionid,
                ]);
            }
        }

        return array_merge($summary, self::hydrate_from_local($p, $problemid, $includetests), [
            'drafts' => (object) $drafts,
            'sourcequestionid' => (int) ($p->sourcequestionid ?? 0),
        ]);
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
            'statement' => (string) ($q->questiontext ?? $p->statement),
            'defaultlanguage' => $defaultlang,
            'languages' => $languages,
            'samples' => $samples,
            'hiddenCount' => $hidden,
        ];
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

        $langs = $DB->get_records('local_nexcodelab_lang', ['problemid' => $problemid], 'language ASC');
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
        // Linked CR problems never use local_nexcodelab_testcase — live CR only.
        if ($includetests && empty($p->sourcequestionid)) {
            $tests = $DB->get_records('local_nexcodelab_testcase', ['problemid' => $problemid], 'sortorder ASC, id ASC');
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
            'statement' => $p->statement,
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

        $p = $DB->get_record('local_nexcodelab_problem', ['id' => $problemid], 'id, sourcequestionid');
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
        $sql = "SELECT * FROM {local_nexcodelab_testcase} WHERE problemid = :problemid";
        if ($display === 'sample' || $display === 'hidden') {
            $sql .= " AND display = :display";
            $params['display'] = $display;
        }
        $sql .= " ORDER BY sortorder ASC, id ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * @return array
     */
    public static function all_tags(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT t.id, t.name, COUNT(pt.problemid) AS usecount
               FROM {local_nexcodelab_tag} t
               LEFT JOIN {local_nexcodelab_problem_tag} pt ON pt.tagid = t.id
               LEFT JOIN {local_nexcodelab_problem} p ON p.id = pt.problemid AND p.status = 'ready'
           GROUP BY t.id, t.name
           ORDER BY usecount DESC, t.name ASC"
        );
        $out = [];
        foreach ($rows as $t) {
            $out[] = [
                'id' => (int) $t->id,
                'name' => $t->name,
                'count' => (int) $t->usecount,
            ];
        }
        return $out;
    }
}
