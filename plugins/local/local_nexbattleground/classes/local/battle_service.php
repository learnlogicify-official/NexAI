<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Active battle operations (expire, submit, export).
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Battle runtime helpers.
 */
class battle_service {

    /**
     * If the clock expired with no winner, mark a tie.
     *
     * @param int $battleid
     * @return \stdClass battle record
     */
    public static function ensure_not_expired(int $battleid): \stdClass {
        global $DB;

        $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid], '*', MUST_EXIST);
        if ($battle->status !== 'active') {
            return $battle;
        }

        $deadline = (int) $battle->timestart + (int) $battle->duration;
        if (time() < $deadline) {
            return $battle;
        }

        $transaction = $DB->start_delegated_transaction();
        $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid], '*', MUST_EXIST);
        if ($battle->status === 'active' && empty($battle->winnerid)) {
            $now = time();
            $DB->update_record('local_nexbattleground_battle', (object) [
                'id' => $battleid,
                'status' => 'finished',
                'outcome' => 'tie',
                'winnerid' => 0,
                'timefinish' => $now,
            ]);
            $DB->set_field('local_nexbattleground_player', 'result', 'tie', ['battleid' => $battleid]);
            $DB->set_field('local_nexbattleground_player', 'timemodified', $now, ['battleid' => $battleid]);
            $battle->status = 'finished';
            $battle->outcome = 'tie';
            $battle->timefinish = $now;
        }
        $transaction->allow_commit();
        return $battle;
    }

    /**
     * Full battle payload for a participant.
     *
     * @param int $battleid
     * @param int $userid
     * @return array
     */
    public static function export(int $battleid, int $userid): array {
        global $DB;

        $battle = self::ensure_not_expired($battleid);
        $me = $DB->get_record('local_nexbattleground_player', [
            'battleid' => $battleid,
            'userid' => $userid,
        ]);
        if (!$me) {
            throw new \moodle_exception('notparticipant', 'local_nexbattleground');
        }

        $players = $DB->get_records('local_nexbattleground_player', ['battleid' => $battleid], 'seat ASC');
        $you = null;
        $opponent = null;
        foreach ($players as $p) {
            $u = $DB->get_record('user', ['id' => $p->userid], 'id, firstname, lastname, username');
            $row = [
                'userid' => (int) $p->userid,
                'seat' => (int) $p->seat,
                'displayname' => $u ? fullname($u) : get_string('opponent', 'local_nexbattleground'),
                'attempts' => (int) $p->attempts,
                'acceptedat' => (int) $p->acceptedat,
                'result' => (string) $p->result,
                'isyou' => (int) $p->userid === $userid,
            ];
            if ($row['isyou']) {
                $you = $row;
            } else {
                $opponent = $row;
            }
        }

        $problem = null;
        if ((int) $battle->problemid > 0 && $battle->status !== 'waiting') {
            $problem = problems::export((int) $battle->problemid, $userid);
            if ($problem) {
                // Never send hidden expected values.
                unset($problem['hidden']);
            }
        }

        $deadline = (int) $battle->timestart > 0
            ? (int) $battle->timestart + (int) $battle->duration
            : 0;
        $timeleft = $deadline > 0 ? max(0, $deadline - time()) : 0;

        $mysummary = self::summarize_for_user($battle, $userid);
        $diff = rewards::battle_difficulty($battle);

        return [
            'battleid' => (int) $battle->id,
            'status' => (string) $battle->status,
            'outcome' => (string) $battle->outcome,
            'winnerid' => (int) $battle->winnerid,
            'problemid' => (int) $battle->problemid,
            'difficulty' => $diff,
            'duration' => (int) $battle->duration,
            'timestart' => (int) $battle->timestart,
            'timefinish' => (int) $battle->timefinish,
            'deadline' => $deadline,
            'timeleft' => $timeleft,
            'servertime' => time(),
            'you' => $you,
            'opponent' => $opponent,
            'language' => (string) $me->language,
            'code' => (string) ($me->code ?? ''),
            'problem' => $problem,
            'summary' => $mysummary,
            'canact' => $battle->status === 'active',
            'roomcode' => (string) ($battle->roomcode ?? ''),
            'xpAwarded' => rewards::xp_for_viewer($battle, $userid),
        ];
    }

    /**
     * @param \stdClass $battle
     * @param int $userid
     * @return array
     */
    public static function summarize_for_user(\stdClass $battle, int $userid): array {
        global $DB;

        $players = $DB->get_records('local_nexbattleground_player', ['battleid' => $battle->id]);
        $oppname = get_string('opponent', 'local_nexbattleground');
        $myresult = '';
        foreach ($players as $p) {
            if ((int) $p->userid === $userid) {
                $myresult = (string) $p->result;
                continue;
            }
            $u = $DB->get_record('user', ['id' => $p->userid], 'id, firstname, lastname');
            if ($u) {
                $oppname = fullname($u);
            }
        }

        if ($myresult === '' && $battle->status === 'finished') {
            if (($battle->outcome ?? '') === 'tie') {
                $myresult = 'tie';
            } else if ((int) $battle->winnerid === $userid) {
                $myresult = 'win';
            } else if ((int) $battle->winnerid > 0) {
                $myresult = 'loss';
            }
        }

        $problemname = '';
        if ((int) $battle->problemid > 0) {
            $problemname = (string) $DB->get_field('local_learnlogic_problem', 'name', ['id' => $battle->problemid]);
        }

        return [
            'battleid' => (int) $battle->id,
            'status' => (string) $battle->status,
            'outcome' => (string) $battle->outcome,
            'result' => $myresult,
            'opponent' => $oppname,
            'problemname' => $problemname,
            'difficulty' => (string) $battle->difficulty,
            'timefinish' => (int) $battle->timefinish,
            'timecreated' => (int) $battle->timecreated,
            'url' => (new \moodle_url('/local/nexbattleground/battle.php', ['id' => $battle->id]))->out(false),
        ];
    }

    /**
     * Run sample tests (does not decide the battle).
     *
     * @param int $battleid
     * @param int $userid
     * @param string $language
     * @param string $code
     * @return array
     */
    public static function run(int $battleid, int $userid, string $language, string $code): array {
        global $DB;

        $battle = self::ensure_not_expired($battleid);
        self::require_participant($battleid, $userid);
        if ($battle->status !== 'active') {
            throw new \moodle_exception('battlenotactive', 'local_nexbattleground');
        }

        $DB->update_record('local_nexbattleground_player', (object) [
            'id' => $DB->get_field('local_nexbattleground_player', 'id', [
                'battleid' => $battleid, 'userid' => $userid,
            ]),
            'language' => $language,
            'code' => $code,
            'timemodified' => time(),
        ]);

        $result = \local_learnlogic\local\runner::execute(
            (int) $battle->problemid,
            $language,
            $code,
            'sample'
        );
        return self::sanitize_result($result);
    }

    /**
     * Submit all tests; first ACCEPTED wins.
     *
     * @param int $battleid
     * @param int $userid
     * @param string $language
     * @param string $code
     * @return array
     */
    public static function submit(int $battleid, int $userid, string $language, string $code): array {
        global $DB;

        $battle = self::ensure_not_expired($battleid);
        $player = self::require_participant($battleid, $userid);
        if ($battle->status !== 'active') {
            return array_merge(self::export($battleid, $userid), [
                'judge' => null,
                'won' => false,
                'statusLabel' => 'BATTLE_OVER',
            ]);
        }

        $result = \local_learnlogic\local\runner::execute(
            (int) $battle->problemid,
            $language,
            $code,
            'all'
        );

        $status = !empty($result['allPassed']) ? 'ACCEPTED' : 'WRONG_ANSWER';
        if (!empty($result['message'])) {
            $status = 'RUNTIME_ERROR';
        }

        $now = time();
        $DB->insert_record('local_nexbattleground_sub', (object) [
            'battleid' => $battleid,
            'userid' => $userid,
            'language' => $language,
            'code' => $code,
            'status' => $status,
            'passed' => (int) ($result['passed'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
            'timecreated' => $now,
        ]);

        $DB->update_record('local_nexbattleground_player', (object) [
            'id' => (int) $player->id,
            'language' => $language,
            'code' => $code,
            'attempts' => (int) $player->attempts + 1,
            'timemodified' => $now,
        ]);

        $won = false;
        if ($status === 'ACCEPTED') {
            $transaction = $DB->start_delegated_transaction();
            $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid], '*', MUST_EXIST);
            if ($battle->status === 'active' && empty($battle->winnerid)) {
                $DB->update_record('local_nexbattleground_battle', (object) [
                    'id' => $battleid,
                    'status' => 'finished',
                    'outcome' => 'win',
                    'winnerid' => $userid,
                    'timefinish' => $now,
                ]);
                $DB->set_field('local_nexbattleground_player', 'acceptedat', $now, [
                    'battleid' => $battleid, 'userid' => $userid,
                ]);
                $DB->set_field('local_nexbattleground_player', 'result', 'win', [
                    'battleid' => $battleid, 'userid' => $userid,
                ]);
                $DB->execute(
                    "UPDATE {local_nexbattleground_player}
                        SET result = 'loss', timemodified = :t
                      WHERE battleid = :b AND userid <> :u",
                    ['t' => $now, 'b' => $battleid, 'u' => $userid]
                );
                $battle->status = 'finished';
                $battle->outcome = 'win';
                $battle->winnerid = $userid;
                rewards::award_win($battle, $userid);
                $won = true;
            }
            $transaction->allow_commit();
        }

        $payload = self::export($battleid, $userid);
        $payload['judge'] = self::sanitize_result($result);
        $payload['judge']['statusLabel'] = $status;
        $payload['won'] = $won;
        return $payload;
    }

    /**
     * @param int $battleid
     * @param int $userid
     * @return array
     */
    public static function forfeit(int $battleid, int $userid): array {
        global $DB;

        $battle = self::ensure_not_expired($battleid);
        self::require_participant($battleid, $userid);
        if ($battle->status !== 'active') {
            return self::export($battleid, $userid);
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $battle = $DB->get_record('local_nexbattleground_battle', ['id' => $battleid], '*', MUST_EXIST);
        if ($battle->status === 'active') {
            $winner = $DB->get_field_select(
                'local_nexbattleground_player',
                'userid',
                'battleid = :b AND userid <> :u',
                ['b' => $battleid, 'u' => $userid]
            );
            $DB->update_record('local_nexbattleground_battle', (object) [
                'id' => $battleid,
                'status' => 'finished',
                'outcome' => 'forfeit',
                'winnerid' => (int) $winner,
                'timefinish' => $now,
            ]);
            $DB->set_field('local_nexbattleground_player', 'result', 'loss', [
                'battleid' => $battleid, 'userid' => $userid,
            ]);
            if ($winner) {
                $DB->set_field('local_nexbattleground_player', 'result', 'win', [
                    'battleid' => $battleid, 'userid' => (int) $winner,
                ]);
                $battle->status = 'finished';
                $battle->outcome = 'forfeit';
                $battle->winnerid = (int) $winner;
                rewards::award_win($battle, (int) $winner);
            }
        }
        $transaction->allow_commit();

        return self::export($battleid, $userid);
    }

    /**
     * @param int $battleid
     * @param int $userid
     * @return \stdClass
     */
    private static function require_participant(int $battleid, int $userid): \stdClass {
        global $DB;
        $player = $DB->get_record('local_nexbattleground_player', [
            'battleid' => $battleid,
            'userid' => $userid,
        ]);
        if (!$player) {
            throw new \moodle_exception('notparticipant', 'local_nexbattleground');
        }
        return $player;
    }

    /**
     * @param array $result
     * @return array
     */
    private static function sanitize_result(array $result): array {
        if (!empty($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as &$r) {
                if (($r['display'] ?? '') === 'hidden') {
                    $r['expected'] = '';
                    $r['input'] = '';
                }
            }
            unset($r);
        }
        return $result;
    }
}
