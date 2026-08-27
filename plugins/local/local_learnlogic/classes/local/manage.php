<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Manage problems UI helpers.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Data for manage / import screens.
 */
class manage {

    /**
     * All problems for the manage list (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list_problems(): array {
        global $DB;

        $rows = $DB->get_records('local_learnlogic_problem', null, 'timemodified DESC');
        if (!$rows) {
            return [];
        }

        $ids = array_map('intval', array_keys($rows));
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $tagmap = [];
        $companymap = [];
        $hasekind = self::tag_kind_supported();
        $kindselect = $hasekind ? 't.kind' : "'topic' AS kind";
        $sql = "SELECT pt.id, pt.problemid, t.name, {$kindselect}
                  FROM {local_learnlogic_problem_tag} pt
                  JOIN {local_learnlogic_tag} t ON t.id = pt.tagid
                 WHERE pt.problemid $insql
              ORDER BY t.name ASC";
        foreach ($DB->get_records_sql($sql, $params) as $tr) {
            $pid = (int) $tr->problemid;
            $kind = self::normalize_tag_kind((string) ($tr->kind ?? 'topic'));
            if ($kind === 'company') {
                if (!isset($companymap[$pid])) {
                    $companymap[$pid] = [];
                }
                $companymap[$pid][] = $tr->name;
            } else {
                if (!isset($tagmap[$pid])) {
                    $tagmap[$pid] = [];
                }
                $tagmap[$pid][] = $tr->name;
            }
        }

        $sourceqids = [];
        foreach ($rows as $p) {
            $sqid = (int) ($p->sourcequestionid ?? 0);
            if ($sqid > 0) {
                $sourceqids[] = $sqid;
            }
        }
        $categorymap = importer::categories_for_question_ids($sourceqids);

        $out = [];
        foreach ($rows as $p) {
            $pid = (int) $p->id;
            $topics = $tagmap[$pid] ?? [];
            $companies = $companymap[$pid] ?? [];
            $sqid = (int) ($p->sourcequestionid ?? 0);
            $cat = ($sqid > 0 && isset($categorymap[$sqid])) ? $categorymap[$sqid] : null;
            $categoryname = $cat ? (string) $cat['name'] : '';
            $categorysearch = $categoryname !== '' ? \core_text::strtolower($categoryname) : '';
            $allnames = array_merge($topics, $companies);
            if ($categorysearch !== '') {
                $allnames[] = $categorysearch;
            }
            $out[] = [
                'id' => $pid,
                'name' => format_string($p->name),
                'namesearch' => \core_text::strtolower(format_string($p->name)),
                'difficulty' => $p->difficulty,
                'difficultylabel' => get_string($p->difficulty, 'local_learnlogic'),
                'status' => $p->status,
                'statuslabel' => get_string($p->status, 'local_learnlogic'),
                'tags' => array_map(static function ($n) {
                    return ['name' => $n];
                }, $topics),
                'companies' => array_map(static function ($n) {
                    return ['name' => self::tag_display_name($n, 'company')];
                }, $companies),
                'hastags' => !empty($topics),
                'hascompanies' => !empty($companies),
                'tagslabel' => !empty($topics) ? implode(', ', $topics) : '',
                'companieslabel' => !empty($companies)
                    ? implode(', ', array_map(static function ($n) {
                        return self::tag_display_name($n, 'company');
                    }, $companies))
                    : '',
                'categoryid' => $cat ? (int) $cat['id'] : 0,
                'category' => $categoryname,
                'categorysearch' => $categorysearch,
                'hascategory' => $categoryname !== '',
                'tagsearch' => implode(' ', $allnames),
                'islinked' => !empty($p->sourcequestionid),
                'sourcequestionid' => $sqid,
                'editurl' => (new \moodle_url('/local/learnlogic/manage/edit.php', ['id' => $pid]))->out(false),
                'deleteurl' => (new \moodle_url('/local/learnlogic/manage/delete.php', [
                    'id' => $pid,
                    'sesskey' => sesskey(),
                ]))->out(false),
                'viewurl' => (new \moodle_url('/local/learnlogic/problem.php', ['id' => $pid]))->out(false),
            ];
        }
        return $out;
    }

    /**
     * Unique question-bank categories used by linked problems (for manage filters).
     *
     * @param array<int, array<string, mixed>> $problems from list_problems()
     * @return array<int, array<string, mixed>>
     */
    public static function filter_categories_from_problems(array $problems): array {
        $counts = [];
        foreach ($problems as $p) {
            $key = (string) ($p['categorysearch'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'displayname' => (string) ($p['category'] ?? $key),
                    'namesearch' => $key,
                    'problemcount' => 0,
                    'hasproblems' => true,
                ];
            }
            $counts[$key]['problemcount']++;
        }
        $out = array_values($counts);
        usort($out, static function ($a, $b) {
            return strcasecmp((string) $a['displayname'], (string) $b['displayname']);
        });
        return $out;
    }

    /**
     * Import candidate row enriched for templates.
     *
     * @param array $candidate from importer::search_coderunner
     * @return array
     */
    public static function format_import_candidate(array $candidate): array {
        $tags = $candidate['tags'] ?? [];
        $tagitems = [];
        foreach ($tags as $tag) {
            $tagitems[] = ['name' => $tag];
        }
        return array_merge($candidate, [
            'tags' => $tagitems,
            'hastags' => !empty($tagitems),
            'tagslabel' => !empty($tags) ? implode(', ', $tags) : '',
            'typelabel' => trim(
                ($candidate['language'] ?? '') .
                (!empty($candidate['coderunnertype']) ? ' · ' . $candidate['coderunnertype'] : '')
            ),
            'multilanglabel' => !empty($candidate['multilanghint'])
                ? implode(', ', $candidate['multilanghint']) : '',
            'hasmultilang' => !empty($candidate['multilanghint']),
            'editurl' => !empty($candidate['problemid'])
                ? (new \moodle_url('/local/learnlogic/manage/edit.php', ['id' => $candidate['problemid']]))->out(false)
                : '',
        ]);
    }

    /**
     * Normalize a tag name (lowercase, trimmed).
     *
     * @param string $name
     * @return string
     */
    public static function normalize_tag_name(string $name): string {
        return \core_text::strtolower(trim($name));
    }

    /**
     * Normalize tag kind to topic|company.
     *
     * @param string $kind
     * @return string
     */
    public static function normalize_tag_kind(string $kind): string {
        $kind = \core_text::strtolower(trim($kind));
        return $kind === 'company' ? 'company' : 'topic';
    }

    /**
     * Display label for a stored tag name.
     *
     * @param string $name
     * @param string $kind
     * @return string
     */
    public static function tag_display_name(string $name, string $kind = 'topic'): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (self::normalize_tag_kind($kind) === 'company') {
            return \core_text::strtotitle(str_replace(['-', '_'], ' ', $name));
        }
        return $name;
    }

    /**
     * Whether the tag table has a kind column (after upgrade).
     *
     * @return bool
     */
    public static function tag_kind_supported(): bool {
        global $DB;
        static $supported = null;
        if ($supported === null) {
            $supported = $DB->get_manager()->field_exists('local_learnlogic_tag', 'kind');
        }
        return $supported;
    }

    /**
     * All tags for the manage-tags screen.
     *
     * @param string $kindfilter all|topic|company
     * @return array<int, array<string, mixed>>
     */
    public static function list_tags(string $kindfilter = 'all'): array {
        global $DB;

        $hasekind = self::tag_kind_supported();
        $kindselect = $hasekind ? 't.kind' : "'topic' AS kind";
        $groupkind = $hasekind ? ', t.kind' : '';
        $where = '';
        $params = [];
        $kindfilter = \core_text::strtolower(trim($kindfilter));
        if ($kindfilter === 'topic' || $kindfilter === 'company') {
            if ($hasekind) {
                $where = ' WHERE t.kind = :kind';
                $params['kind'] = $kindfilter;
            } else if ($kindfilter === 'company') {
                return [];
            }
        }

        $rows = $DB->get_records_sql(
            "SELECT t.id, t.name, {$kindselect}, COUNT(pt.problemid) AS problemcount
               FROM {local_learnlogic_tag} t
               LEFT JOIN {local_learnlogic_problem_tag} pt ON pt.tagid = t.id
              {$where}
           GROUP BY t.id, t.name{$groupkind}
           ORDER BY t.name ASC",
            $params
        );
        if (!$rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $t) {
            $tid = (int) $t->id;
            $count = (int) $t->problemcount;
            $name = (string) $t->name;
            $kind = self::normalize_tag_kind((string) ($t->kind ?? 'topic'));
            $out[] = [
                'id' => $tid,
                'name' => $name,
                'displayname' => self::tag_display_name($name, $kind),
                'namesearch' => \core_text::strtolower($name),
                'kind' => $kind,
                'iskindtopic' => $kind === 'topic',
                'iskindcompany' => $kind === 'company',
                'kindlabel' => get_string('tagkind_' . $kind, 'local_learnlogic'),
                'problemcount' => $count,
                'hasproblems' => $count > 0,
                'deleteurl' => (new \moodle_url('/local/learnlogic/manage/tags.php', [
                    'action' => 'delete',
                    'id' => $tid,
                ]))->out(false),
                'settopicurl' => (new \moodle_url('/local/learnlogic/manage/tags.php', [
                    'action' => 'setkind',
                    'id' => $tid,
                    'kind' => 'topic',
                    'sesskey' => sesskey(),
                ]))->out(false),
                'setcompanyurl' => (new \moodle_url('/local/learnlogic/manage/tags.php', [
                    'action' => 'setkind',
                    'id' => $tid,
                    'kind' => 'company',
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }
        return $out;
    }

    /**
     * Create a tag if it does not exist.
     *
     * @param string $name
     * @param string $kind topic|company
     * @return int new tag id
     */
    public static function create_tag(string $name, string $kind = 'topic'): int {
        global $DB;

        $name = self::normalize_tag_name($name);
        $kind = self::normalize_tag_kind($kind);
        if ($name === '') {
            throw new \moodle_exception('tagnameempty', 'local_learnlogic');
        }
        if (\core_text::strlen($name) > 100) {
            throw new \moodle_exception('tagnametoolong', 'local_learnlogic');
        }
        if ($DB->record_exists('local_learnlogic_tag', ['name' => $name])) {
            throw new \moodle_exception('tagexists', 'local_learnlogic', '', $name);
        }
        $rec = (object) ['name' => $name];
        if (self::tag_kind_supported()) {
            $rec->kind = $kind;
        }
        return (int) $DB->insert_record('local_learnlogic_tag', $rec);
    }

    /**
     * Change a tag's kind (topic ↔ company).
     *
     * @param int $tagid
     * @param string $kind
     * @return string tag name
     */
    public static function set_tag_kind(int $tagid, string $kind): string {
        global $DB;
        if (!self::tag_kind_supported()) {
            throw new \moodle_exception('tagkindunavailable', 'local_learnlogic');
        }
        $tag = $DB->get_record('local_learnlogic_tag', ['id' => $tagid], '*', MUST_EXIST);
        $tag->kind = self::normalize_tag_kind($kind);
        $DB->update_record('local_learnlogic_tag', $tag);
        return (string) $tag->name;
    }

    /**
     * Replace all tags of a given kind on a problem.
     *
     * @param int $problemid
     * @param string[] $names
     * @param string $kind
     */
    public static function sync_problem_tag_names(int $problemid, array $names, string $kind = 'topic'): void {
        global $DB;
        $kind = self::normalize_tag_kind($kind);
        $normalized = [];
        foreach ($names as $tname) {
            $tname = self::normalize_tag_name((string) $tname);
            if ($tname !== '') {
                $normalized[$tname] = true;
            }
        }
        $normalized = array_keys($normalized);

        // Unlink existing tags of this kind.
        if (self::tag_kind_supported()) {
            $existing = $DB->get_records_sql(
                "SELECT pt.id, pt.tagid
                   FROM {local_learnlogic_problem_tag} pt
                   JOIN {local_learnlogic_tag} t ON t.id = pt.tagid
                  WHERE pt.problemid = :pid AND t.kind = :kind",
                ['pid' => $problemid, 'kind' => $kind]
            );
            foreach ($existing as $row) {
                $DB->delete_records('local_learnlogic_problem_tag', ['id' => (int) $row->id]);
            }
        } else if ($kind === 'topic') {
            $DB->delete_records('local_learnlogic_problem_tag', ['problemid' => $problemid]);
        }

        foreach ($normalized as $tname) {
            $tag = $DB->get_record('local_learnlogic_tag', ['name' => $tname]);
            if (!$tag) {
                $tid = self::create_tag($tname, $kind);
            } else {
                $tid = (int) $tag->id;
                if (self::tag_kind_supported() && self::normalize_tag_kind((string) ($tag->kind ?? 'topic')) !== $kind) {
                    self::set_tag_kind($tid, $kind);
                }
            }
            if (!$DB->record_exists('local_learnlogic_problem_tag', ['problemid' => $problemid, 'tagid' => $tid])) {
                $DB->insert_record('local_learnlogic_problem_tag', (object) [
                    'problemid' => $problemid,
                    'tagid' => $tid,
                ]);
            }
        }
    }

    /**
     * Rename a tag, merging into an existing tag when the new name already exists.
     *
     * @param int $tagid
     * @param string $newname
     * @return array{action: string, name: string, from?: string}
     */
    public static function rename_tag(int $tagid, string $newname): array {
        global $DB;

        $tag = $DB->get_record('local_learnlogic_tag', ['id' => $tagid], '*', MUST_EXIST);
        $oldname = (string) $tag->name;
        $newname = self::normalize_tag_name($newname);

        if ($newname === '') {
            throw new \moodle_exception('tagnameempty', 'local_learnlogic');
        }
        if (\core_text::strlen($newname) > 100) {
            throw new \moodle_exception('tagnametoolong', 'local_learnlogic');
        }
        if ($newname === $oldname) {
            return ['action' => 'unchanged', 'name' => $newname];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $existing = $DB->get_record('local_learnlogic_tag', ['name' => $newname]);
            if ($existing && (int) $existing->id !== $tagid) {
                $targetid = (int) $existing->id;
                $links = $DB->get_records('local_learnlogic_problem_tag', ['tagid' => $tagid]);
                foreach ($links as $link) {
                    $pid = (int) $link->problemid;
                    if (!$DB->record_exists('local_learnlogic_problem_tag', [
                        'problemid' => $pid,
                        'tagid' => $targetid,
                    ])) {
                        $DB->insert_record('local_learnlogic_problem_tag', (object) [
                            'problemid' => $pid,
                            'tagid' => $targetid,
                        ]);
                    }
                }
                $DB->delete_records('local_learnlogic_problem_tag', ['tagid' => $tagid]);
                $DB->delete_records('local_learnlogic_tag', ['id' => $tagid]);
                $transaction->allow_commit();
                return ['action' => 'merged', 'name' => $newname, 'from' => $oldname];
            }

            $tag->name = $newname;
            $DB->update_record('local_learnlogic_tag', $tag);
            $transaction->allow_commit();
            return ['action' => 'renamed', 'name' => $newname, 'from' => $oldname];
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Add tags of a given kind to a problem without removing existing ones.
     *
     * @param int $problemid
     * @param string[] $names
     * @param string $kind
     */
    public static function add_problem_tag_names(int $problemid, array $names, string $kind = 'topic'): void {
        global $DB;
        $kind = self::normalize_tag_kind($kind);
        foreach ($names as $raw) {
            $tname = self::normalize_tag_name((string) $raw);
            if ($tname === '') {
                continue;
            }
            $tag = $DB->get_record('local_learnlogic_tag', ['name' => $tname]);
            if (!$tag) {
                $tid = self::create_tag($tname, $kind);
            } else {
                $tid = (int) $tag->id;
                if (self::tag_kind_supported()
                        && self::normalize_tag_kind((string) ($tag->kind ?? 'topic')) !== $kind) {
                    self::set_tag_kind($tid, $kind);
                }
            }
            if (!$DB->record_exists('local_learnlogic_problem_tag', [
                'problemid' => $problemid,
                'tagid' => $tid,
            ])) {
                $DB->insert_record('local_learnlogic_problem_tag', (object) [
                    'problemid' => $problemid,
                    'tagid' => $tid,
                ]);
            }
        }
    }

    /**
     * Bulk add or replace company tags on many problems.
     *
     * @param int[] $problemids
     * @param string[] $companynames
     * @param string $mode add|replace
     * @return int number of problems updated
     */
    public static function bulk_update_company_tags(array $problemids, array $companynames, string $mode = 'add'): int {
        global $DB;

        $mode = \core_text::strtolower(trim($mode));
        if ($mode !== 'replace') {
            $mode = 'add';
        }

        $problemids = array_values(array_unique(array_filter(array_map('intval', $problemids))));
        if (empty($problemids)) {
            throw new \moodle_exception('bulkcompaniesnone', 'local_learnlogic');
        }

        $normalized = [];
        foreach ($companynames as $name) {
            $name = self::normalize_tag_name((string) $name);
            if ($name !== '') {
                $normalized[$name] = true;
            }
        }
        $normalized = array_keys($normalized);
        if ($mode === 'add' && empty($normalized)) {
            throw new \moodle_exception('bulkcompaniesempty', 'local_learnlogic');
        }

        list($insql, $params) = $DB->get_in_or_equal($problemids, SQL_PARAMS_NAMED);
        $existing = $DB->get_records_select('local_learnlogic_problem', "id $insql", $params, '', 'id');
        $validids = array_map('intval', array_keys($existing));
        if (empty($validids)) {
            throw new \moodle_exception('bulkcompaniesnone', 'local_learnlogic');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($validids as $pid) {
                if ($mode === 'replace') {
                    self::sync_problem_tag_names($pid, $normalized, 'company');
                } else {
                    self::add_problem_tag_names($pid, $normalized, 'company');
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return count($validids);
    }

    /**
     * Delete a tag and unlink it from all problems.
     *
     * @param int $tagid
     * @return string deleted tag name
     */
    public static function delete_tag(int $tagid): string {
        global $DB;

        $tag = $DB->get_record('local_learnlogic_tag', ['id' => $tagid], '*', MUST_EXIST);
        $DB->delete_records('local_learnlogic_problem_tag', ['tagid' => $tagid]);
        $DB->delete_records('local_learnlogic_tag', ['id' => $tagid]);
        return (string) $tag->name;
    }

    /**
     * Problem count for a tag.
     *
     * @param int $tagid
     * @return int
     */
    public static function tag_problem_count(int $tagid): int {
        global $DB;
        return (int) $DB->count_records('local_learnlogic_problem_tag', ['tagid' => $tagid]);
    }
}
