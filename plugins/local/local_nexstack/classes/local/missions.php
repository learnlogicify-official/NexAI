<?php
namespace local_nexstack\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Mission + workspace helpers.
 */
class missions {

    /**
     * @return array<int,\stdClass>
     */
    public static function list_ready(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexstack_mission')) {
            return [];
        }
        return $DB->get_records_select(
            'local_nexstack_mission',
            "status = 'ready'",
            null,
            'sortorder ASC, name ASC'
        ) ?: [];
    }

    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        $row = $DB->get_record('local_nexstack_mission', ['id' => $id]);
        return $row ?: null;
    }

    public static function get_by_slug(string $slug): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_nexstack_mission', ['slug' => $slug]);
        return $row ?: null;
    }

    /**
     * @return array<string,string>
     */
    public static function decode_files(?string $json): array {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $path => $content) {
            $path = ltrim(str_replace('\\', '/', (string) $path), '/');
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            $out[$path] = (string) $content;
        }
        return $out;
    }

    /**
     * @return array<int,array>
     */
    public static function decode_steps(?string $json): array {
        $data = json_decode((string) $json, true);
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Ensure workspace exists; clone scaffold on first open.
     */
    public static function ensure_workspace(int $userid, int $missionid): \stdClass {
        global $DB;
        $existing = $DB->get_record('local_nexstack_workspace', [
            'userid' => $userid,
            'missionid' => $missionid,
        ]);
        if ($existing) {
            return $existing;
        }
        $mission = self::get($missionid);
        if (!$mission) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $now = time();
        $id = $DB->insert_record('local_nexstack_workspace', (object) [
            'userid' => $userid,
            'missionid' => $missionid,
            'filesjson' => $mission->scaffoldjson,
            'activestep' => 0,
            'completedsteps' => '',
            'status' => 'inprogress',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $DB->get_record('local_nexstack_workspace', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * @param array<string,string> $files
     */
    public static function save_files(int $userid, int $missionid, array $files): \stdClass {
        global $DB;
        $ws = self::ensure_workspace($userid, $missionid);
        $clean = [];
        foreach ($files as $path => $content) {
            $path = ltrim(str_replace('\\', '/', (string) $path), '/');
            if ($path === '' || str_contains($path, '..') || strlen($path) > 200) {
                continue;
            }
            $clean[$path] = mb_substr((string) $content, 0, 200000);
        }
        $ws->filesjson = json_encode($clean, JSON_UNESCAPED_SLASHES);
        $ws->timemodified = time();
        $DB->update_record('local_nexstack_workspace', $ws);
        return $ws;
    }

    /**
     * @param int[] $completed
     */
    public static function mark_step(int $userid, int $missionid, int $stepid, bool $passed, array $completed): \stdClass {
        global $DB;
        $ws = self::ensure_workspace($userid, $missionid);
        $set = [];
        foreach ($completed as $s) {
            $set[(int) $s] = true;
        }
        if ($passed) {
            $set[$stepid] = true;
        }
        $ids = array_keys($set);
        sort($ids);
        $ws->completedsteps = implode(',', $ids);
        $ws->activestep = $passed ? ($stepid + 1) : (int) $ws->activestep;
        $mission = self::get($missionid);
        $steps = self::decode_steps($mission->stepsjson ?? '[]');
        if (count($ids) >= count($steps) && count($steps) > 0) {
            $ws->status = 'completed';
        }
        $ws->timemodified = time();
        $DB->update_record('local_nexstack_workspace', $ws);
        return $ws;
    }

    /**
     * Catalog cards for hub.
     *
     * @return array<int,array>
     */
    public static function catalog_for_user(int $userid): array {
        global $DB;
        $out = [];
        foreach (self::list_ready() as $m) {
            $ws = $DB->get_record('local_nexstack_workspace', [
                'userid' => $userid,
                'missionid' => (int) $m->id,
            ]);
            $completed = [];
            if ($ws && $ws->completedsteps !== '') {
                foreach (explode(',', $ws->completedsteps) as $s) {
                    if ($s !== '') {
                        $completed[] = (int) $s;
                    }
                }
            }
            $steps = self::decode_steps($m->stepsjson);
            $out[] = [
                'id' => (int) $m->id,
                'name' => (string) $m->name,
                'slug' => (string) $m->slug,
                'track' => (string) $m->track,
                'difficulty' => (string) $m->difficulty,
                'runtime' => (string) $m->runtime,
                'summary' => (string) ($m->summary ?? ''),
                'estimatedmins' => (int) $m->estimatedmins,
                'stepcount' => count($steps),
                'completedcount' => count($completed),
                'status' => $ws ? (string) $ws->status : 'new',
                'url' => (new \moodle_url('/local/nexstack/studio.php', ['id' => $m->id]))->out(false),
            ];
        }
        return $out;
    }
}
