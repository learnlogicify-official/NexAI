<?php
namespace local_nexproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;
use mod_quiz\quiz_attempt;

/**
 * Start or resume a proctoring session for an in-progress attempt.
 *
 * Each quiz attempt gets its own session + trust score.
 * Leaving/resuming the SAME attempt continues that session;
 * a NEW attempt always starts fresh at 100.
 */
class start_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * End leftover active sessions for this user+quiz that belong to other attempts.
     */
    protected static function end_stale_actives(int $quizid, int $userid, int $keepattemptid): void {
        global $DB;
        $now = time();
        $actives = $DB->get_records('local_nexproctor_sessions', [
            'quizid' => $quizid,
            'userid' => $userid,
            'status' => 'active',
        ]);
        foreach ($actives as $row) {
            $aid = (int) $row->attemptid;
            // Keep the session for the attempt we are starting/resuming.
            if ($keepattemptid > 0 && $aid === $keepattemptid) {
                continue;
            }
            // Unlinked active while we already know a new attempt id → stale.
            if ($keepattemptid > 0 && $aid === 0) {
                $row->status = 'ended';
                $row->endedat = $now;
                $row->timemodified = $now;
                $DB->update_record('local_nexproctor_sessions', $row);
                local_nexproctor_recalc_trust((int) $row->id);
                continue;
            }
            // Active session tied to a different attempt.
            if ($aid > 0 && $aid !== $keepattemptid) {
                $row->status = 'ended';
                $row->endedat = $now;
                $row->timemodified = $now;
                $DB->update_record('local_nexproctor_sessions', $row);
                local_nexproctor_recalc_trust((int) $row->id);
            }
        }
    }

    public static function execute(int $cmid, int $quizid, int $attemptid = 0): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/nexproctor/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'quizid' => $quizid,
            'attemptid' => $attemptid,
        ]);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/nexproctor:takesession', $context);

        $now = time();
        $attemptid = (int) $params['attemptid'];
        $quizid = (int) $params['quizid'];

        $attemptinprogress = true;
        if ($attemptid > 0) {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, userid, state, quiz');
            if (!$attempt || (int) $attempt->userid !== (int) $USER->id) {
                throw new \moodle_exception('nopermissions', 'error');
            }
            $attemptinprogress = ($attempt->state === quiz_attempt::IN_PROGRESS
                || $attempt->state === quiz_attempt::OVERDUE);
        }

        // Close any active session from a previous attempt before starting/resuming this one.
        if ($attemptid > 0) {
            self::end_stale_actives($quizid, (int) $USER->id, $attemptid);
        }

        $existing = null;

        // Resume ONLY the session that already belongs to this attempt.
        if ($attemptid > 0) {
            $rows = $DB->get_records(
                'local_nexproctor_sessions',
                ['attemptid' => $attemptid, 'userid' => $USER->id],
                'id DESC',
                '*',
                0,
                1
            );
            $existing = $rows ? reset($rows) : null;
        }

        // Optional: unlinked active for this quiz (attempt id not known yet).
        if (!$existing && $attemptid === 0) {
            $existing = $DB->get_record('local_nexproctor_sessions', [
                'quizid' => $quizid,
                'userid' => $USER->id,
                'status' => 'active',
                'attemptid' => 0,
            ]);
        }

        if ($existing && $attemptinprogress) {
            // Never reassign a session from attempt A onto attempt B.
            if ($attemptid > 0 && (int) $existing->attemptid > 0 && (int) $existing->attemptid !== $attemptid) {
                $existing = null;
            }
        }

        if ($existing && $attemptinprogress) {
            $existing->status = 'active';
            $existing->endedat = 0;
            $existing->timemodified = $now;
            if ($attemptid > 0 && !(int) $existing->attemptid) {
                $existing->attemptid = $attemptid;
            }
            if (!(int) $existing->cmid) {
                $existing->cmid = $params['cmid'];
            }
            $DB->update_record('local_nexproctor_sessions', $existing);
            $score = local_nexproctor_recalc_trust((int) $existing->id);
            return [
                'sessionid' => (int) $existing->id,
                'trustscore' => (int) $score,
            ];
        }

        // Brand-new attempt → brand-new session at 100.
        $rec = (object) [
            'quizid' => $quizid,
            'cmid' => $params['cmid'],
            'attemptid' => $attemptid,
            'userid' => $USER->id,
            'status' => 'active',
            'trustscore' => 100,
            'consentat' => $now,
            'startedat' => $now,
            'endedat' => 0,
            'timemodified' => $now,
        ];
        $id = (int) $DB->insert_record('local_nexproctor_sessions', $rec);
        return ['sessionid' => $id, 'trustscore' => 100];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sessionid' => new external_value(PARAM_INT, 'Session id'),
            'trustscore' => new external_value(PARAM_INT, 'Trust score'),
        ]);
    }
}
