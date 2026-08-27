<?php
namespace mod_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Local attempt mirror + grade sync for course activities.
 */
class attempts {

    public static function count_for_user(int $activityid, int $userid): int {
        global $DB;
        return (int) $DB->count_records_select(
            'nexinterview_attempts',
            "activityid = ? AND userid = ? AND status <> 'abandoned'",
            [$activityid, $userid]
        );
    }

    public static function latest(int $activityid, int $userid): ?\stdClass {
        global $DB;
        $rec = $DB->get_records(
            'nexinterview_attempts',
            ['activityid' => $activityid, 'userid' => $userid],
            'id DESC',
            '*',
            0,
            1
        );
        if (!$rec) {
            return null;
        }
        return reset($rec) ?: null;
    }

    public static function create(int $activityid, int $userid, string $sessionid): \stdClass {
        global $DB;
        $no = self::count_for_user($activityid, $userid) + 1;
        $rec = (object) [
            'activityid' => $activityid,
            'userid' => $userid,
            'attemptno' => $no,
            'sessionid' => $sessionid,
            'status' => 'inprogress',
            'overallscore' => 0,
            'recommendation' => '',
            'reportjson' => '',
            'timecreated' => time(),
            'timecompleted' => 0,
        ];
        $rec->id = $DB->insert_record('nexinterview_attempts', $rec);
        return $rec;
    }

    public static function by_session(string $sessionid): ?\stdClass {
        global $DB;
        $rec = $DB->get_record('nexinterview_attempts', ['sessionid' => $sessionid]);
        return $rec ?: null;
    }

    public static function mark_abandoned(int $attemptid): void {
        global $DB;
        $rec = $DB->get_record('nexinterview_attempts', ['id' => $attemptid]);
        if (!$rec || (string) $rec->status !== 'inprogress') {
            return;
        }
        $rec->status = 'abandoned';
        $rec->timecompleted = time();
        $DB->update_record('nexinterview_attempts', $rec);
    }

    /**
     * @param \stdClass $instance nexinterview row
     * @param array $view session view from service
     */
    public static function sync_completed(\stdClass $instance, array $view): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/nexinterview/lib.php');
        $sessionid = (string) ($view['session_id'] ?? '');
        if ($sessionid === '' || ($view['status'] ?? '') !== 'completed') {
            return;
        }
        $attempt = self::by_session($sessionid);
        if (!$attempt) {
            return;
        }
        $report = $view['report'] ?? null;
        $attempt->status = 'completed';
        $attempt->overallscore = (float) ($view['scores']['overall'] ?? ($report['overall_score'] ?? 0));
        $attempt->recommendation = (string) ($report['recommendation'] ?? '');
        $attempt->reportjson = json_encode($report ?? new \stdClass());
        $attempt->timecompleted = time();
        $DB->update_record('nexinterview_attempts', $attempt);
        nexinterview_update_grades($instance, (int) $attempt->userid, (float) $attempt->overallscore);
    }
}
