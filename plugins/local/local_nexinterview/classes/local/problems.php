<?php
namespace local_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Map interview tracks to NexPractice problem ids.
 */
class problems {

    /**
     * Random ready NexPractice problem this user has not solved (ACCEPTED).
     *
     * @param int $userid
     * @param string $track Unused role hint (kept for API compatibility).
     * @param array $excludeids Problem ids to skip (already used this interview).
     * @return array{id:int,name:string,difficulty:string}
     */
    public static function pick_unsolved_for_user(int $userid, string $track = '', array $excludeids = []): array {
        global $DB;

        $empty = ['id' => 0, 'name' => '', 'difficulty' => ''];
        if (!$DB->get_manager()->table_exists('local_learnlogic_problem')) {
            return $empty;
        }

        $ready = $DB->get_records_select(
            'local_learnlogic_problem',
            "status = 'ready'",
            null,
            'id ASC',
            'id, name, difficulty'
        );
        if (!$ready) {
            return self::from_map($track);
        }

        $exclude = [];
        foreach ($excludeids as $xid) {
            $exclude[(int) $xid] = true;
        }

        $solved = [];
        if ($userid > 0 && $DB->get_manager()->table_exists('local_learnlogic_submission')) {
            $solvedids = $DB->get_fieldset_sql(
                "SELECT DISTINCT problemid
                   FROM {local_learnlogic_submission}
                  WHERE userid = ? AND status = ?",
                [$userid, 'ACCEPTED']
            );
            foreach ($solvedids as $sid) {
                $solved[(int) $sid] = true;
            }
        }

        $unsolved = [];
        $fallback = [];
        foreach ($ready as $p) {
            $id = (int) $p->id;
            if (!empty($exclude[$id])) {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => (string) $p->name,
                'difficulty' => strtolower((string) ($p->difficulty ?? 'easy')),
            ];
            $fallback[] = $row;
            if (empty($solved[$id])) {
                $unsolved[] = $row;
            }
        }
        $pool = $unsolved ?: $fallback;
        if (!$pool) {
            // Everything excluded — allow fallback without exclude.
            foreach ($ready as $p) {
                $pool[] = [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'difficulty' => strtolower((string) ($p->difficulty ?? 'easy')),
                ];
            }
        }
        if (!$pool) {
            return self::from_map($track);
        }

        // Prefer easy/medium so campus interviews stay fair, then shuffle.
        $preferred = array_values(array_filter($pool, static function($r) {
            return in_array($r['difficulty'], ['easy', 'medium', ''], true);
        }));
        if ($preferred) {
            $pool = $preferred;
        }

        // Rotate away from the last problem this user was assigned in an interview.
        if ($userid > 0 && count($pool) > 1) {
            $lastid = (int) get_user_preferences('local_nexinterview_last_problemid', 0, $userid);
            if ($lastid > 0) {
                $rotated = array_values(array_filter($pool, static function($r) use ($lastid) {
                    return (int) $r['id'] !== $lastid;
                }));
                if ($rotated) {
                    $pool = $rotated;
                }
            }
        }

        shuffle($pool);
        return $pool[0];
    }

    /**
     * Admin problem-map fallback (role=id).
     *
     * @param string $track
     * @return array{id:int,name:string,difficulty:string}
     */
    private static function from_map(string $track): array {
        global $DB;

        $empty = ['id' => 0, 'name' => '', 'difficulty' => ''];
        $map = self::parse_map((string) get_config('local_nexinterview', 'problemmap'));
        $id = (int) ($map[$track] ?? 0);
        if ($id < 1 || !$DB->get_manager()->table_exists('local_learnlogic_problem')) {
            return $empty;
        }
        $rec = $DB->get_record('local_learnlogic_problem', ['id' => $id], 'id, name, difficulty');
        if (!$rec) {
            return $empty;
        }
        return [
            'id' => (int) $rec->id,
            'name' => (string) $rec->name,
            'difficulty' => strtolower((string) ($rec->difficulty ?? '')),
        ];
    }

    /**
     * Remember the last problem assigned so the next interview can rotate.
     */
    public static function remember_assigned(int $userid, int $problemid): void {
        if ($userid > 0 && $problemid > 0) {
            set_user_preference('local_nexinterview_last_problemid', $problemid, $userid);
        }
    }

    /**
     * @return int NexPractice problem id (0 if none)
     */
    public static function pick_for_track(string $track): int {
        global $USER;
        $picked = self::pick_unsolved_for_user((int) ($USER->id ?? 0), $track);
        return (int) ($picked['id'] ?? 0);
    }

    public static function is_ready(int $problemid): bool {
        global $DB;
        if ($problemid <= 0 || !$DB->get_manager()->table_exists('local_learnlogic_problem')) {
            return false;
        }
        $status = $DB->get_field('local_learnlogic_problem', 'status', ['id' => $problemid]);
        return $status === 'ready';
    }

    /**
     * @return array<string,int>
     */
    public static function parse_map(string $raw): array {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && ctype_digit($v)) {
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }
}
