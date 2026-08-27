<?php
namespace local_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Local attempt tracking for hub stats.
 */
class attempts {

    public static function create(int $userid, string $sessionid, string $roletrack): int {
        global $DB;
        $rec = (object) [
            'userid' => $userid,
            'sessionid' => $sessionid,
            'roletrack' => $roletrack,
            'status' => 'inprogress',
            'overallscore' => 0,
            'timecreated' => time(),
            'timecompleted' => 0,
        ];
        return (int) $DB->insert_record('local_nexinterview_attempt', $rec);
    }

    public static function latest_inprogress(int $userid): ?\stdClass {
        global $DB;
        $recs = $DB->get_records(
            'local_nexinterview_attempt',
            ['userid' => $userid, 'status' => 'inprogress'],
            'id DESC',
            '*',
            0,
            1
        );
        if (!$recs) {
            return null;
        }
        return reset($recs) ?: null;
    }

    /**
     * Mark a Moodle attempt as abandoned (left without completing).
     *
     * @param int $attemptid
     */
    public static function mark_abandoned(int $attemptid): void {
        global $DB;
        $rec = $DB->get_record('local_nexinterview_attempt', ['id' => $attemptid]);
        if (!$rec || (string) $rec->status !== 'inprogress') {
            return;
        }
        $rec->status = 'abandoned';
        $rec->timecompleted = time();
        $DB->update_record('local_nexinterview_attempt', $rec);
    }

    /**
     * Abandon every in-progress attempt for a user (optionally end remote sessions).
     *
     * @param int $userid
     * @param client|null $client
     * @param string $exceptsessionid Keep this session if still in progress
     */
    public static function abandon_all_inprogress(
        int $userid,
        ?client $client = null,
        string $exceptsessionid = ''
    ): void {
        $rows = self::list_for_user($userid, 50, 'inprogress');
        foreach ($rows as $rec) {
            $sid = (string) ($rec->sessionid ?? '');
            if ($exceptsessionid !== '' && $sid === $exceptsessionid) {
                continue;
            }
            if ($client && $sid !== '') {
                try {
                    $client->end($sid);
                } catch (\Throwable $e) {
                    // Remote may already be gone — still abandon locally.
                }
            }
            self::mark_abandoned((int) $rec->id);
        }
    }

    public static function sync_completed(array $view): void {
        global $DB;
        $sid = (string) ($view['session_id'] ?? '');
        if ($sid === '' || ($view['status'] ?? '') !== 'completed') {
            return;
        }
        $rec = $DB->get_record('local_nexinterview_attempt', ['sessionid' => $sid]);
        if (!$rec) {
            return;
        }
        $report = is_array($view['report'] ?? null) ? $view['report'] : [];
        $scores = is_array($view['scores'] ?? null) ? $view['scores'] : [];
        $dims = self::dimension_scores_from_view($view);
        $rec->status = 'completed';
        $rec->overallscore = (float) (
            $report['overall_score']
            ?? $view['overall_score']
            ?? $scores['overall']
            ?? $rec->overallscore
            ?? 0
        );
        if (property_exists($rec, 'scoresjson') || $DB->get_manager()->field_exists('local_nexinterview_attempt', 'scoresjson')) {
            $rec->scoresjson = json_encode($dims, JSON_UNESCAPED_UNICODE);
        }
        $rec->timecompleted = time();
        $DB->update_record('local_nexinterview_attempt', $rec);
    }

    /**
     * Normalized dimension scores (0–100) from a session view / report payload.
     *
     * @param array $view
     * @return array{
     *     conceptual:float,
     *     problem_solving:float,
     *     coding:float,
     *     explanation:float,
     *     communication:float,
     *     independence:float
     * }
     */
    public static function dimension_scores_from_view(array $view): array {
        $report = $view['report'] ?? [];
        if (is_string($report)) {
            $decoded = json_decode($report, true);
            $report = is_array($decoded) ? $decoded : [];
        } else if (is_object($report)) {
            $report = (array) $report;
        } else if (!is_array($report)) {
            $report = [];
        }

        $scores = $view['scores'] ?? [];
        if (is_object($scores)) {
            $scores = (array) $scores;
        } else if (!is_array($scores)) {
            $scores = [];
        }

        $dims = $report['dimensions'] ?? [];
        if (is_object($dims)) {
            $dims = (array) $dims;
        } else if (!is_array($dims)) {
            $dims = [];
        }

        $indepmeta = $report['independence'] ?? [];
        if (is_object($indepmeta)) {
            $indepmeta = (array) $indepmeta;
        } else if (!is_array($indepmeta)) {
            $indepmeta = [];
        }

        $pick = static function (array $keys) use ($dims, $scores): float {
            foreach ($keys as $key) {
                if (array_key_exists($key, $dims) && is_numeric($dims[$key])) {
                    return max(0.0, min(100.0, (float) $dims[$key]));
                }
                if (array_key_exists($key, $scores) && is_numeric($scores[$key])) {
                    return max(0.0, min(100.0, (float) $scores[$key]));
                }
            }
            return 0.0;
        };

        $independence = $pick(['independence']);
        if ($independence <= 0 && isset($indepmeta['independence_score']) && is_numeric($indepmeta['independence_score'])) {
            $independence = max(0.0, min(100.0, (float) $indepmeta['independence_score']));
        }

        return [
            'conceptual' => $pick(['conceptual']),
            'problem_solving' => $pick(['problem_solving', 'idea']),
            'coding' => $pick(['coding']),
            'explanation' => $pick(['explanation', 'explain']),
            'communication' => $pick(['communication']),
            'independence' => $independence,
        ];
    }

    /**
     * Decode stored dimension scores for a Moodle attempt row.
     *
     * @param \stdClass $rec
     * @return array{
     *     conceptual:float,
     *     problem_solving:float,
     *     coding:float,
     *     explanation:float,
     *     communication:float,
     *     independence:float
     * }|null
     */
    public static function dimension_scores_from_record(\stdClass $rec): ?array {
        $raw = trim((string) ($rec->scoresjson ?? ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $keys = ['conceptual', 'problem_solving', 'coding', 'explanation', 'communication', 'independence'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = isset($decoded[$key]) && is_numeric($decoded[$key])
                ? max(0.0, min(100.0, (float) $decoded[$key]))
                : 0.0;
        }
        return $out;
    }

    /**
     * True when every dimension is zero / missing.
     *
     * @param array<string,float>|null $dims
     * @return bool
     */
    public static function dimensions_are_empty(?array $dims): bool {
        if ($dims === null) {
            return true;
        }
        foreach ($dims as $val) {
            if ((float) $val > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Persist dimension scores onto an attempt when they were missing.
     *
     * @param int $attemptid
     * @param array $dims
     */
    public static function save_dimension_scores(int $attemptid, array $dims): void {
        global $DB;
        if ($attemptid <= 0 || !$dims) {
            return;
        }
        if (!$DB->get_manager()->field_exists('local_nexinterview_attempt', 'scoresjson')) {
            return;
        }
        $DB->set_field(
            'local_nexinterview_attempt',
            'scoresjson',
            json_encode($dims, JSON_UNESCAPED_UNICODE),
            ['id' => $attemptid]
        );
    }

    /**
     * @return array{attempts:int,completed:int,avg:float,best:float}
     */
    public static function user_stats(int $userid): array {
        global $DB;
        $attempts = (int) $DB->count_records_select(
            'local_nexinterview_attempt',
            "userid = ? AND status <> 'abandoned'",
            [$userid]
        );
        $completed = (int) $DB->count_records('local_nexinterview_attempt', [
            'userid' => $userid,
            'status' => 'completed',
        ]);
        $avg = 0.0;
        $best = 0.0;
        if ($completed > 0) {
            $avg = (float) $DB->get_field_sql(
                "SELECT AVG(overallscore) FROM {local_nexinterview_attempt}
                  WHERE userid = ? AND status = 'completed'",
                [$userid]
            );
            $best = (float) $DB->get_field_sql(
                "SELECT MAX(overallscore) FROM {local_nexinterview_attempt}
                  WHERE userid = ? AND status = 'completed'",
                [$userid]
            );
        }
        return [
            'attempts' => $attempts,
            'completed' => $completed,
            'avg' => $avg,
            'best' => $best,
        ];
    }

    /**
     * Recent attempts for hub / reports list.
     *
     * @param int $userid
     * @param int $limit
     * @param string $status completed|inprogress|all
     * @return array<int, \stdClass>
     */
    public static function list_for_user(int $userid, int $limit = 20, string $status = 'completed'): array {
        global $DB;
        $params = ['userid' => $userid];
        $where = 'userid = :userid';
        if ($status !== 'all') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        $limit = max(1, min(100, $limit));
        return array_values($DB->get_records_select(
            'local_nexinterview_attempt',
            $where,
            $params,
            'timecompleted DESC, timecreated DESC, id DESC',
            '*',
            0,
            $limit
        ));
    }

    /**
     * Completed attempts across the site (admin / teacher reports).
     *
     * @param int $limit
     * @return array<int, \stdClass>
     */
    public static function list_completed_all(int $limit = 80): array {
        global $DB;

        $limit = max(1, min(200, $limit));
        $sql = "SELECT a.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email, u.institution
                  FROM {local_nexinterview_attempt} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE a.status = :status AND u.deleted = 0
              ORDER BY a.timecompleted DESC, a.timecreated DESC, a.id DESC";
        return array_values($DB->get_records_sql($sql, ['status' => 'completed'], 0, $limit));
    }

    public static function get_by_session(string $sessionid): ?\stdClass {
        global $DB;
        $rec = $DB->get_record('local_nexinterview_attempt', ['sessionid' => $sessionid]);
        return $rec ?: null;
    }
}
