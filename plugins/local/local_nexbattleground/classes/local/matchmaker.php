<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Matchmaking and battle lifecycle.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Queue, challenges, and battle state.
 */
class matchmaker {

    /**
     * Default battle length in seconds (safe for AJAX — does not need lib.php).
     *
     * @return int
     */
    public static function duration(): int {
        $d = (int) (get_config('local_nexbattleground', 'battleduration') ?: 900);
        return max(60, min(7200, $d));
    }

    /**
     * Join queue; may immediately return a new battle id.
     *
     * @param int $userid
     * @param string $difficulty
     * @return array{queued:bool,battleid:int,message:string}
     */
    public static function join_queue(int $userid, string $difficulty = ''): array {
        global $DB;

        $difficulty = self::normalize_difficulty($difficulty);
        $now = time();

        // Already in an active / waiting battle?
        $open = self::open_battle_for_user($userid);
        if ($open) {
            return [
                'queued' => false,
                'battleid' => (int) $open->id,
                'message' => '',
            ];
        }

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records('local_nexbattleground_queue', ['userid' => $userid]);

        // Find an opponent waiting with compatible difficulty.
        $candidates = $DB->get_records_select(
            'local_nexbattleground_queue',
            'userid <> :uid',
            ['uid' => $userid],
            'timecreated ASC',
            '*',
            0,
            20
        );

        $opponent = null;
        foreach ($candidates as $row) {
            $odiff = (string) $row->difficulty;
            if ($difficulty === '' || $odiff === '' || $odiff === $difficulty) {
                $opponent = $row;
                break;
            }
        }

        if ($opponent) {
            $matchdiff = $difficulty !== '' ? $difficulty : (string) $opponent->difficulty;
            $battleid = self::create_active_battle($userid, (int) $opponent->userid, $matchdiff);
            $DB->delete_records('local_nexbattleground_queue', ['userid' => $userid]);
            $DB->delete_records('local_nexbattleground_queue', ['userid' => (int) $opponent->userid]);
            $transaction->allow_commit();
            return [
                'queued' => false,
                'battleid' => $battleid,
                'message' => '',
            ];
        }

        $DB->insert_record('local_nexbattleground_queue', (object) [
            'userid' => $userid,
            'difficulty' => $difficulty,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $transaction->allow_commit();

        return [
            'queued' => true,
            'battleid' => 0,
            'message' => get_string('searching', 'local_nexbattleground'),
        ];
    }

    /**
     * @param int $userid
     */
    public static function leave_queue(int $userid): void {
        global $DB;
        $DB->delete_records('local_nexbattleground_queue', ['userid' => $userid]);
    }

    /**
     * Challenge another user by username / email / idnumber.
     *
     * @param int $challengerid
     * @param string $username
     * @param string $difficulty
     * @return array
     */
    public static function challenge_user(int $challengerid, string $username, string $difficulty = ''): array {
        global $DB;

        $username = trim($username);
        if ($username === '') {
            throw new \invalid_parameter_exception('Empty username');
        }

        $user = $DB->get_record_select(
            'user',
            "deleted = 0 AND suspended = 0 AND (username = :u OR email = :e OR idnumber = :i)",
            ['u' => $username, 'e' => $username, 'i' => $username]
        );
        if (!$user) {
            throw new \moodle_exception('usernotfound', 'local_nexbattleground');
        }
        $inviteeid = (int) $user->id;
        if ($inviteeid === $challengerid || isguestuser($user)) {
            throw new \moodle_exception('cannotchallenge', 'local_nexbattleground');
        }

        $ctx = \context_system::instance();
        if (!has_capability('local/nexbattleground:battle', $ctx, $inviteeid)) {
            throw new \moodle_exception('cannotchallenge', 'local_nexbattleground');
        }

        $open = self::open_battle_for_user($challengerid);
        if ($open) {
            return [
                'battleid' => (int) $open->id,
                'status' => $open->status,
                'difficulty' => (string) ($open->difficulty ?? ''),
                'message' => '',
            ];
        }

        $difficulty = self::normalize_difficulty($difficulty);
        $now = time();
        $battleid = $DB->insert_record('local_nexbattleground_battle', (object) [
            'problemid' => 0,
            'status' => 'waiting',
            'outcome' => '',
            'winnerid' => 0,
            'duration' => self::duration(),
            'difficulty' => $difficulty,
            'challengerid' => $challengerid,
            'inviteeid' => $inviteeid,
            'roomcode' => '',
            'timestart' => 0,
            'timefinish' => 0,
            'timecreated' => $now,
        ]);

        $DB->insert_record('local_nexbattleground_player', (object) [
            'battleid' => $battleid,
            'userid' => $challengerid,
            'seat' => 1,
            'language' => 'python3',
            'code' => '',
            'attempts' => 0,
            'acceptedat' => 0,
            'result' => '',
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_nexbattleground_player', (object) [
            'battleid' => $battleid,
            'userid' => $inviteeid,
            'seat' => 2,
            'language' => 'python3',
            'code' => '',
            'attempts' => 0,
            'acceptedat' => 0,
            'result' => '',
            'timemodified' => $now,
        ]);

        self::leave_queue($challengerid);

        return [
            'battleid' => $battleid,
            'status' => 'waiting',
            'difficulty' => $difficulty,
            'message' => get_string('challengepending', 'local_nexbattleground'),
        ];
    }

    /**
     * Accept or decline a waiting challenge.
     *
     * @param int $userid
     * @param int $battleid
     * @param bool $accept
     * @return array
     */
    public static function respond_challenge(int $userid, int $battleid, bool $accept): array {
        global $DB;

        $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid], '*', MUST_EXIST);
        if ($battle->status !== 'waiting' || (int) $battle->inviteeid !== $userid) {
            throw new \moodle_exception('notparticipant', 'local_nexbattleground');
        }

        if (!$accept) {
            $DB->update_record('local_nexbattleground_battle', (object) [
                'id' => $battleid,
                'status' => 'finished',
                'outcome' => 'declined',
                'timefinish' => time(),
            ]);
            return ['battleid' => $battleid, 'status' => 'finished', 'accepted' => false];
        }

        $now = time();
        $problemid = problems::pick_random(
            (string) $battle->difficulty,
            [(int) $battle->challengerid, (int) $userid]
        );
        $DB->update_record('local_nexbattleground_battle', (object) [
            'id' => $battleid,
            'problemid' => $problemid,
            'status' => 'active',
            'timestart' => $now,
            'duration' => self::duration(),
        ]);
        self::leave_queue($userid);
        self::leave_queue((int) $battle->challengerid);

        return ['battleid' => $battleid, 'status' => 'active', 'accepted' => true];
    }

    /**
     * Create an immediate active duel between two users.
     *
     * @param int $userid1
     * @param int $userid2
     * @param string $difficulty
     * @return int battle id
     */
    public static function create_active_battle(int $userid1, int $userid2, string $difficulty = ''): int {
        global $DB;

        $difficulty = self::normalize_difficulty($difficulty);
        $problemid = problems::pick_random($difficulty, [$userid1, $userid2]);
        $now = time();
        $duration = self::duration();

        $battleid = $DB->insert_record('local_nexbattleground_battle', (object) [
            'problemid' => $problemid,
            'status' => 'active',
            'outcome' => '',
            'winnerid' => 0,
            'duration' => $duration,
            'difficulty' => $difficulty,
            'challengerid' => $userid1,
            'inviteeid' => $userid2,
            'roomcode' => '',
            'timestart' => $now,
            'timefinish' => 0,
            'timecreated' => $now,
        ]);

        foreach ([[$userid1, 1], [$userid2, 2]] as [$uid, $seat]) {
            $DB->insert_record('local_nexbattleground_player', (object) [
                'battleid' => $battleid,
                'userid' => $uid,
                'seat' => $seat,
                'language' => 'python3',
                'code' => '',
                'attempts' => 0,
                'acceptedat' => 0,
                'result' => '',
                'timemodified' => $now,
            ]);
        }

        return $battleid;
    }

    /**
     * Create a private room with a 6-digit join code.
     *
     * @param int $userid
     * @param string $difficulty
     * @return array{battleid:int,roomcode:string,status:string,message:string}
     */
    public static function create_room(int $userid, string $difficulty = ''): array {
        global $DB;

        $open = self::open_battle_for_user($userid);
        if ($open) {
            $code = (string) ($open->roomcode ?? '');
            if ($open->status === 'waiting' && $code !== '' && (int) $open->challengerid === $userid) {
                return [
                    'battleid' => (int) $open->id,
                    'roomcode' => $code,
                    'status' => 'waiting',
                    'difficulty' => (string) ($open->difficulty ?? ''),
                    'message' => get_string('roomready', 'local_nexbattleground', $code),
                ];
            }
            return [
                'battleid' => (int) $open->id,
                'roomcode' => $code,
                'status' => (string) $open->status,
                'difficulty' => (string) ($open->difficulty ?? ''),
                'message' => '',
            ];
        }

        $difficulty = self::normalize_difficulty($difficulty);
        self::leave_queue($userid);
        $now = time();
        $code = self::generate_room_code();

        $battleid = $DB->insert_record('local_nexbattleground_battle', (object) [
            'problemid' => 0,
            'status' => 'waiting',
            'outcome' => '',
            'winnerid' => 0,
            'duration' => self::duration(),
            'difficulty' => $difficulty,
            'challengerid' => $userid,
            'inviteeid' => 0,
            'roomcode' => $code,
            'timestart' => 0,
            'timefinish' => 0,
            'timecreated' => $now,
        ]);

        $DB->insert_record('local_nexbattleground_player', (object) [
            'battleid' => $battleid,
            'userid' => $userid,
            'seat' => 1,
            'language' => 'python3',
            'code' => '',
            'attempts' => 0,
            'acceptedat' => 0,
            'result' => '',
            'timemodified' => $now,
        ]);

        return [
            'battleid' => $battleid,
            'roomcode' => $code,
            'status' => 'waiting',
            'difficulty' => $difficulty,
            'message' => get_string('roomready', 'local_nexbattleground', $code),
        ];
    }

    /**
     * Peek at a waiting room by code (for join preview).
     *
     * @param string $code
     * @return array{found:bool,roomcode:string,difficulty:string,host:string}
     */
    public static function peek_room(string $code): array {
        global $DB;

        $code = self::normalize_room_code($code);
        if (strlen($code) !== 6) {
            return [
                'found' => false,
                'roomcode' => $code,
                'difficulty' => '',
                'host' => '',
            ];
        }

        $battle = $DB->get_record_select(
            'local_nexbattleground_battle',
            "roomcode = :code AND status = 'waiting'",
            ['code' => $code]
        );
        if (!$battle) {
            return [
                'found' => false,
                'roomcode' => $code,
                'difficulty' => '',
                'host' => '',
            ];
        }

        $host = '';
        $u = $DB->get_record('user', ['id' => (int) $battle->challengerid], 'id, firstname, lastname');
        if ($u) {
            $host = fullname($u);
        }

        return [
            'found' => true,
            'roomcode' => $code,
            'difficulty' => (string) ($battle->difficulty ?? ''),
            'host' => $host,
        ];
    }

    /**
     * Join a room by 6-digit code and start the battle.
     *
     * @param int $userid
     * @param string $code
     * @return array{battleid:int,status:string,roomcode:string,difficulty:string}
     */
    public static function join_room(int $userid, string $code): array {
        global $DB;

        $code = self::normalize_room_code($code);
        if (strlen($code) !== 6) {
            throw new \moodle_exception('invalidroomcode', 'local_nexbattleground');
        }

        $open = self::open_battle_for_user($userid);
        if ($open && $open->status === 'active') {
            return [
                'battleid' => (int) $open->id,
                'status' => 'active',
                'roomcode' => (string) ($open->roomcode ?? ''),
                'difficulty' => (string) ($open->difficulty ?? ''),
            ];
        }

        $transaction = $DB->start_delegated_transaction();

        $battle = $DB->get_record_select(
            'local_nexbattleground_battle',
            "roomcode = :code AND status = 'waiting'",
            ['code' => $code],
            '*',
            IGNORE_MISSING
        );
        if (!$battle) {
            $transaction->allow_commit();
            throw new \moodle_exception('roomnotfound', 'local_nexbattleground');
        }
        if ((int) $battle->challengerid === $userid) {
            $transaction->allow_commit();
            throw new \moodle_exception('cannotjoinownroom', 'local_nexbattleground');
        }
        if ((int) $battle->inviteeid > 0) {
            $transaction->allow_commit();
            throw new \moodle_exception('roomfull', 'local_nexbattleground');
        }

        // Re-check open battle inside transaction.
        if (self::open_battle_for_user($userid)) {
            $transaction->allow_commit();
            throw new \moodle_exception('alreadyinbattle', 'local_nexbattleground');
        }

        $now = time();
        $problemid = problems::pick_random(
            (string) $battle->difficulty,
            [(int) $battle->challengerid, $userid]
        );

        $DB->insert_record('local_nexbattleground_player', (object) [
            'battleid' => (int) $battle->id,
            'userid' => $userid,
            'seat' => 2,
            'language' => 'python3',
            'code' => '',
            'attempts' => 0,
            'acceptedat' => 0,
            'result' => '',
            'timemodified' => $now,
        ]);

        $DB->update_record('local_nexbattleground_battle', (object) [
            'id' => (int) $battle->id,
            'inviteeid' => $userid,
            'problemid' => $problemid,
            'status' => 'active',
            'timestart' => $now,
            'duration' => self::duration(),
        ]);

        self::leave_queue($userid);
        self::leave_queue((int) $battle->challengerid);
        $transaction->allow_commit();

        return [
            'battleid' => (int) $battle->id,
            'status' => 'active',
            'roomcode' => $code,
            'difficulty' => (string) ($battle->difficulty ?? ''),
        ];
    }

    /**
     * Cancel a waiting room you host.
     *
     * @param int $userid
     * @param int $battleid
     * @return array{ok:bool}
     */
    public static function cancel_room(int $userid, int $battleid = 0): array {
        global $DB;

        $battle = null;
        if ($battleid > 0) {
            $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid]);
        } else {
            $battle = self::open_battle_for_user($userid);
        }
        if (!$battle || $battle->status !== 'waiting') {
            return ['ok' => true];
        }
        if ((int) $battle->challengerid !== $userid) {
            throw new \moodle_exception('notparticipant', 'local_nexbattleground');
        }

        $DB->update_record('local_nexbattleground_battle', (object) [
            'id' => (int) $battle->id,
            'status' => 'finished',
            'outcome' => 'cancelled',
            'timefinish' => time(),
        ]);
        return ['ok' => true];
    }

    /**
     * @return string six-digit code
     */
    public static function generate_room_code(): string {
        global $DB;
        for ($i = 0; $i < 40; $i++) {
            $code = (string) random_int(100000, 999999);
            $exists = $DB->record_exists_select(
                'local_nexbattleground_battle',
                "roomcode = :c AND status = 'waiting'",
                ['c' => $code]
            );
            if (!$exists) {
                return $code;
            }
        }
        // Extremely unlikely fallback.
        return (string) random_int(100000, 999999);
    }

    /**
     * @param string $code
     * @return string
     */
    public static function normalize_room_code(string $code): string {
        return preg_replace('/\D+/', '', trim($code)) ?? '';
    }

    /**
     * @param int $userid
     * @return \stdClass|null
     */
    public static function open_battle_for_user(int $userid): ?\stdClass {
        global $DB;
        $sql = "SELECT b.*
                  FROM {local_nexbattleground_battle} b
                  JOIN {local_nexbattleground_player} p ON p.battleid = b.id
                 WHERE p.userid = :uid AND b.status IN ('waiting', 'active')
              ORDER BY b.timecreated DESC";
        $battle = $DB->get_record_sql($sql, ['uid' => $userid]);
        return $battle ?: null;
    }

    /**
     * Finished battles for a user (paginated).
     *
     * @param int $userid
     * @param int $page 0-based
     * @param int $perpage
     * @return array{items: array, total: int, page: int, perpage: int}
     */
    public static function recent_battles(int $userid, int $page = 0, int $perpage = 8): array {
        global $DB;

        $page = max(0, $page);
        $perpage = max(1, min(50, $perpage));

        $where = "p.userid = :uid AND b.status = 'finished'
                   AND b.outcome NOT IN ('declined', 'cancelled')";
        $params = ['uid' => $userid];

        $total = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_nexbattleground_battle} b
               JOIN {local_nexbattleground_player} p ON p.battleid = b.id
              WHERE {$where}",
            $params
        );

        $pages = max(1, (int) ceil($total / $perpage));
        if ($page > $pages - 1) {
            $page = max(0, $pages - 1);
        }
        $offset = $page * $perpage;

        $items = [];
        if ($total > 0) {
            $sql = "SELECT b.*
                      FROM {local_nexbattleground_battle} b
                      JOIN {local_nexbattleground_player} p ON p.battleid = b.id
                     WHERE {$where}
                  ORDER BY b.timefinish DESC, b.timecreated DESC";
            foreach ($DB->get_records_sql($sql, $params, $offset, $perpage) as $b) {
                $items[] = battle_service::summarize_for_user($b, $userid);
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
        ];
    }

    /**
     * Lobby poll payload.
     *
     * @param int $userid
     * @param int $recentpage
     * @param int $recentperpage
     * @return array
     */
    public static function lobby_state(int $userid, int $recentpage = 0, int $recentperpage = 8): array {
        global $DB;

        $queued = $DB->record_exists('local_nexbattleground_queue', ['userid' => $userid]);
        $open = self::open_battle_for_user($userid);
        if ($open && $open->status === 'active') {
            battle_service::ensure_not_expired((int) $open->id);
            $open = $DB->get_record('local_nexbattleground_battle', ['id' => $open->id]);
        }

        $incoming = [];
        $waiting = $DB->get_records_select(
            'local_nexbattleground_battle',
            "inviteeid = :uid AND status = 'waiting' AND roomcode = ''",
            ['uid' => $userid],
            'timecreated DESC',
            '*',
            0,
            10
        );
        foreach ($waiting as $b) {
            $challenger = $DB->get_record('user', ['id' => $b->challengerid], 'id, firstname, lastname, username');
            $incoming[] = [
                'battleid' => (int) $b->id,
                'from' => $challenger ? fullname($challenger) : get_string('opponent', 'local_nexbattleground'),
                'username' => $challenger->username ?? '',
                'difficulty' => (string) $b->difficulty,
                'timecreated' => (int) $b->timecreated,
            ];
        }

        $recent = self::recent_battles($userid, $recentpage, $recentperpage);

        $roomcode = '';
        $roomdifficulty = '';
        if ($open && $open->status === 'waiting' && (string) ($open->roomcode ?? '') !== '') {
            $roomcode = (string) $open->roomcode;
            $roomdifficulty = (string) ($open->difficulty ?? '');
        }

        return [
            'queued' => $queued,
            'battleid' => $open ? (int) $open->id : 0,
            'battlestatus' => $open ? (string) $open->status : '',
            'roomcode' => $roomcode,
            'roomDifficulty' => $roomdifficulty,
            'incoming' => $incoming,
            'recent' => $recent['items'],
            'recentTotal' => $recent['total'],
            'recentPage' => $recent['page'],
            'recentPerpage' => $recent['perpage'],
            'servertime' => time(),
        ];
    }

    /**
     * @param string $difficulty
     * @return string
     */
    public static function normalize_difficulty(string $difficulty): string {
        $difficulty = strtolower(trim($difficulty));
        if (in_array($difficulty, ['easy', 'medium', 'hard', 'veryhard'], true)) {
            return $difficulty;
        }
        return '';
    }
}
