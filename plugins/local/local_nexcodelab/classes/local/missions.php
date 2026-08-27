<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Mission catalog and workspace helpers.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Mission Labs data access.
 */
class missions {

    /**
     * @param int $userid
     * @param array $filters
     * @return array
     */
    public static function list_missions(int $userid, array $filters = []): array {
        global $DB;

        $track = strtolower(trim((string) ($filters['track'] ?? '')));
        $userstatus = strtolower(trim((string) ($filters['userstatus'] ?? 'all')));
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(0, (int) ($filters['page'] ?? 0));
        $perpage = max(1, min(200, (int) ($filters['perpage'] ?? 12)));

        $params = [];
        // Seeded missions use "ready"; XML-imported packs use "published".
        $where = ["m.status IN ('ready', 'published')"];
        if (in_array($track, local_nexcodelab_tracks(), true)) {
            $where[] = 'm.track = :track';
            $params['track'] = $track;
        }
        if ($search !== '') {
            $where[] = '(' . $DB->sql_like('m.name', ':q', false)
                . ' OR ' . $DB->sql_like('m.scenario', ':q2', false) . ')';
            $params['q'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['q2'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        $sql = "SELECT m.* FROM {local_nexcodelab_mission} m WHERE "
            . implode(' AND ', $where) . " ORDER BY m.name ASC, m.id ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $items = [];
        $counts = ['all' => 0, 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0];
        foreach ($rows as $m) {
            $summary = self::export_mission_summary($m, $userid);
            $ustatus = $summary['userstatus'];
            $counts['all']++;
            $counts[$ustatus]++;
            if ($userstatus !== 'all' && $userstatus !== $ustatus) {
                continue;
            }
            $items[] = $summary;
        }

        // Stable catalog numbers 1..N across the filtered list (pagination-aware).
        foreach ($items as $i => $item) {
            $items[$i]['number'] = $i + 1;
        }

        $total = count($items);
        $slice = array_slice($items, $page * $perpage, $perpage);

        return [
            'missions' => array_values($slice),
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
            'counts' => $counts,
        ];
    }

    /**
     * @param \stdClass $m
     * @param int $userid
     * @return array
     */
    public static function export_mission_summary($m, int $userid = 0): array {
        global $DB;

        $stepcount = (int) $DB->count_records('local_nexcodelab_mission_step', ['missionid' => (int) $m->id]);
        $ustatus = 'notstarted';
        $passed = 0;
        if ($userid > 0) {
            $prog = $DB->get_record('local_nexcodelab_mission_progress', [
                'userid' => $userid,
                'missionid' => (int) $m->id,
            ]);
            if ($prog && !empty($prog->completed)) {
                $ustatus = 'completed';
                $passed = $stepcount;
            } else {
                $steps = $DB->get_fieldset_select('local_nexcodelab_mission_step', 'id', 'missionid = ?', [(int) $m->id]);
                if ($steps) {
                    list($insql, $inparams) = $DB->get_in_or_equal($steps, SQL_PARAMS_NAMED, 's');
                    $passed = (int) $DB->count_records_sql(
                        "SELECT COUNT(DISTINCT stepid)
                           FROM {local_nexcodelab_step_attempt}
                          WHERE userid = :uid AND status = 'pass' AND stepid {$insql}",
                        array_merge(['uid' => $userid], $inparams)
                    );
                }
                if ($passed > 0) {
                    $ustatus = 'inprogress';
                }
            }
        }

        return [
            'id' => (int) $m->id,
            'number' => 0,
            'name' => $m->name,
            'slug' => $m->slug,
            'scenario' => $m->scenario,
            'track' => $m->track,
            'estimateminutes' => (int) $m->estimateminutes,
            'coverkey' => $m->coverkey,
            'stepcount' => $stepcount,
            'passedsteps' => $passed,
            'userstatus' => $ustatus,
            'url' => (new \moodle_url('/local/nexcodelab/mission.php', ['id' => $m->id]))->out(false),
        ];
    }

    /**
     * Full mission payload for the lab bench.
     *
     * @param int $missionid
     * @param int $userid
     * @return array|null
     */
    public static function get_mission(int $missionid, int $userid = 0): ?array {
        global $DB;

        $m = $DB->get_record('local_nexcodelab_mission', ['id' => $missionid]);
        if (!$m || (!in_array($m->status, ['ready', 'published'], true)
                && !has_capability('local/nexcodelab:manageproblems', \context_system::instance()))) {
            return null;
        }

        $summary = self::export_mission_summary($m, $userid);
        $files = [];
        foreach ($DB->get_records('local_nexcodelab_mission_file', ['missionid' => $missionid], 'sortorder ASC') as $f) {
            $seed = (string) $f->content;
            $content = $seed;
            if ($userid > 0 && empty($f->readonly) && $f->role === 'code') {
                $draft = $DB->get_record('local_nexcodelab_workspace', [
                    'userid' => $userid,
                    'missionid' => $missionid,
                    'path' => $f->path,
                ]);
                if ($draft) {
                    $content = (string) $draft->content;
                }
            }
            $files[] = [
                'path' => $f->path,
                'role' => $f->role,
                'content' => $content,
                'seedcontent' => $seed,
                'readonly' => !empty($f->readonly),
                'sortorder' => (int) $f->sortorder,
            ];
        }

        $passedids = [];
        if ($userid > 0) {
            $stepids = $DB->get_fieldset_select('local_nexcodelab_mission_step', 'id', 'missionid = ?', [$missionid]);
            if ($stepids) {
                list($insql, $inparams) = $DB->get_in_or_equal($stepids, SQL_PARAMS_NAMED, 's');
                $passedids = $DB->get_fieldset_sql(
                    "SELECT DISTINCT stepid FROM {local_nexcodelab_step_attempt}
                      WHERE userid = :uid AND status = 'pass' AND stepid {$insql}",
                    array_merge(['uid' => $userid], $inparams)
                );
                $passedids = array_map('intval', $passedids);
            }
        }

        $steps = [];
        $order = 0;
        $unlocked = true;
        foreach ($DB->get_records('local_nexcodelab_mission_step', ['missionid' => $missionid], 'sortorder ASC') as $s) {
            $passed = in_array((int) $s->id, $passedids, true);
            $grader = json_decode((string) ($s->graderpayload ?? ''), true);
            if (!is_array($grader)) {
                $grader = [];
            }
            $fn = (string) ($grader['fn'] ?? '');
            $signature = (string) ($grader['signature'] ?? '');
            if ($signature === '' && $fn !== '') {
                $kind = (string) ($s->checkkind ?? 'frame');
                if ($kind === 'metric') {
                    $signature = "def {$fn}(df: pd.DataFrame) -> float:\n    \"\"\"Implement this step.\"\"\"\n    return 0.0\n";
                } else {
                    $signature = "def {$fn}(df: pd.DataFrame) -> pd.DataFrame:\n    \"\"\"Implement this step.\"\"\"\n    return df\n";
                }
            }
            $steps[] = [
                'id' => (int) $s->id,
                'sortorder' => (int) $s->sortorder,
                'number' => $order + 1,
                'title' => $s->title,
                'instructions' => $s->instructions,
                'hint' => (string) ($s->hint ?? ''),
                'checkkind' => $s->checkkind,
                'xp' => (int) $s->xp,
                'passed' => $passed,
                'locked' => !$unlocked && !$passed,
                'fn' => $fn,
                'signature' => $signature,
            ];
            if (!$passed && !empty($s->unlockprev)) {
                $unlocked = false;
            } else if ($passed) {
                $unlocked = true;
            }
            $order++;
        }

        // First incomplete unlocked step becomes current.
        $currentstepid = 0;
        foreach ($steps as $st) {
            if (!$st['locked'] && !$st['passed']) {
                $currentstepid = $st['id'];
                break;
            }
        }
        if ($currentstepid === 0 && $steps) {
            $currentstepid = (int) $steps[count($steps) - 1]['id'];
        }

        $csvpreview = self::csv_preview_from_files($files);

        return $summary + [
            'files' => $files,
            'steps' => $steps,
            'currentstepid' => $currentstepid,
            'csvpreview' => $csvpreview,
        ];
    }

    /**
     * @param array $files
     * @return array{headers:string[],rows:array,rowcount:int,colcount:int}
     */
    public static function csv_preview_from_files(array $files): array {
        $csv = '';
        foreach ($files as $f) {
            if (($f['role'] ?? '') === 'data' || ($f['path'] ?? '') === 'data.csv') {
                $csv = (string) ($f['content'] ?? '');
                break;
            }
        }
        return self::parse_csv_preview($csv, 50);
    }

    /**
     * @param string $csv
     * @param int $maxrows
     * @return array
     */
    public static function parse_csv_preview(string $csv, int $maxrows = 50): array {
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        if (!$lines || $lines[0] === '') {
            return ['headers' => [], 'rows' => [], 'rowcount' => 0, 'colcount' => 0];
        }
        $headers = str_getcsv(array_shift($lines));
        $rows = [];
        foreach (array_slice($lines, 0, $maxrows) as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        return [
            'headers' => $headers,
            'rows' => $rows,
            'rowcount' => count($lines),
            'colcount' => count($headers),
        ];
    }

    /**
     * Save editable workspace file(s).
     *
     * @param int $userid
     * @param int $missionid
     * @param string $path
     * @param string $content
     * @return array
     */
    public static function save_workspace(int $userid, int $missionid, string $path, string $content): array {
        global $DB;

        $file = $DB->get_record('local_nexcodelab_mission_file', [
            'missionid' => $missionid,
            'path' => $path,
        ], '*', MUST_EXIST);
        if (!empty($file->readonly)) {
            throw new \moodle_exception('filereadonly', 'local_nexcodelab');
        }

        $now = time();
        $existing = $DB->get_record('local_nexcodelab_workspace', [
            'userid' => $userid,
            'missionid' => $missionid,
            'path' => $path,
        ]);
        if ($existing) {
            $existing->content = $content;
            $existing->timemodified = $now;
            $DB->update_record('local_nexcodelab_workspace', $existing);
        } else {
            $DB->insert_record('local_nexcodelab_workspace', (object) [
                'userid' => $userid,
                'missionid' => $missionid,
                'path' => $path,
                'content' => $content,
                'timemodified' => $now,
            ]);
        }
        return ['ok' => true, 'timemodified' => $now];
    }

    /**
     * User progress across missions.
     *
     * @param int $userid
     * @return array
     */
    public static function user_mission_progress(int $userid): array {
        // Fetch the full filtered catalog so progress numbers stay 1..N.
        $data = self::list_missions($userid, ['page' => 0, 'perpage' => 200]);
        $completed = 0;
        $inprogress = 0;
        foreach ($data['missions'] as $m) {
            if ($m['userstatus'] === 'completed') {
                $completed++;
            } else if ($m['userstatus'] === 'inprogress') {
                $inprogress++;
            }
        }
        return [
            'missions' => $data['missions'],
            'completed' => $completed,
            'inprogress' => $inprogress,
            'total' => $data['counts']['all'] ?? count($data['missions']),
        ];
    }
}
