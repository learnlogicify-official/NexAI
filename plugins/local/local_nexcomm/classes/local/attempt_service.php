<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Attempt submit / grade.
 */
class attempt_service {

    /**
     * Submit answers for an activity.
     *
     * @param int $userid
     * @param int $activityid
     * @param array $payload answers / text / draftitemid / transcript / duration
     * @return array
     */
    public static function submit(int $userid, int $activityid, array $payload): array {
        global $DB;

        $activity = $DB->get_record('local_nexcomm_activity', ['id' => $activityid], '*', MUST_EXIST);
        if ($activity->status !== 'ready') {
            throw new \moodle_exception('cannotattempt', 'local_nexcomm');
        }

        $skill = (string) $activity->skill;
        $now = time();
        $score = 0.0;
        $status = 'submitted';
        $answersjson = '';
        $responsetext = '';
        $analysis = null;

        if ($skill === 'reading' || $skill === 'listening') {
            $answers = $payload['answers'] ?? [];
            if (is_string($answers)) {
                $answers = json_decode($answers, true) ?: [];
            }
            $grade = self::grade_mcq($activityid, $answers);
            $score = $grade['score'];
            $status = $score >= (int) $activity->passmark ? 'passed' : 'failed';
            $answersjson = json_encode($answers);
        } else if ($skill === 'writing') {
            $responsetext = trim((string) ($payload['text'] ?? ''));
            $words = self::word_count($responsetext);
            $min = (int) $activity->minwords;
            if ($min > 0 && $words < $min) {
                $score = 0;
                $status = 'failed';
            } else {
                $score = 100;
                $status = 'submitted';
            }
        } else if ($skill === 'speaking') {
            $transcript = trim((string) ($payload['transcript'] ?? $payload['text'] ?? ''));
            $duration = max(0, (int) ($payload['duration'] ?? 0));
            $analysis = speech_analysis::analyze(
                $transcript,
                (string) ($activity->prompt ?? ''),
                $duration,
                (int) $activity->passmark
            );
            $responsetext = $analysis['transcript'];
            $score = (float) $analysis['score'];
            $status = (string) $analysis['status'];
            $answersjson = json_encode([
                'analysis' => $analysis,
                'duration' => $duration,
            ]);
        } else {
            throw new \moodle_exception('cannotattempt', 'local_nexcomm');
        }

        $attemptid = $DB->insert_record('local_nexcomm_attempt', (object) [
            'userid' => $userid,
            'activityid' => $activityid,
            'status' => $status,
            'score' => $score,
            'answersjson' => $answersjson,
            'responsetext' => $responsetext,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        if ($skill === 'speaking') {
            global $CFG;
            require_once($CFG->libdir . '/filelib.php');
            $draft = (int) ($payload['draftitemid'] ?? 0);
            if ($draft > 0) {
                $context = \context_system::instance();
                file_save_draft_area_files(
                    $draft,
                    $context->id,
                    'local_nexcomm',
                    'speech',
                    $attemptid,
                    ['subdirs' => 0, 'maxfiles' => 1]
                );
            }
            $fs = get_file_storage();
            $files = $fs->get_area_files(
                \context_system::instance()->id,
                'local_nexcomm',
                'speech',
                $attemptid,
                'itemid',
                false
            );
            if (!$files) {
                $DB->delete_records('local_nexcomm_attempt', ['id' => $attemptid]);
                throw new \moodle_exception('norecording', 'local_nexcomm');
            }
            // Audio present: if analysis failed only on empty transcript with short audio, keep status.
            if ($status === 'failed' && $score < (int) $activity->passmark) {
                // Keep failed — student can retry.
            } else if (in_array($status, ['passed', 'submitted'], true)) {
                // ok
            }
        }

        $xpawarded = 0;
        $countsfortarget = false;
        $counts = $status === 'passed'
            || ($status === 'submitted' && in_array($skill, ['speaking', 'writing'], true));
        // Speaking: also count toward target when score meets passmark (passed).
        if ($skill === 'speaking' && $status === 'passed') {
            $counts = true;
        }
        if ($counts) {
            $reason = 'activity_' . $activityid;
            $before = $DB->record_exists('local_nexcomm_xpevent', [
                'userid' => $userid,
                'reason' => $reason,
            ]);
            $amount = gamification::xp_for((string) $activity->difficulty, $skill);
            gamification::add_xp($userid, $amount, $activityid, $reason);
            if (!$before && $DB->record_exists('local_nexcomm_xpevent', [
                'userid' => $userid,
                'reason' => $reason,
            ])) {
                $xpawarded = $amount;
            }
            $countsfortarget = true;
        }

        $targetbonus = ['dailyBonus' => 0, 'weeklyBonus' => 0];
        if ($countsfortarget) {
            $targetbonus = targets::record_completion($userid, $activityid);
        }

        $out = [
            'attemptid' => $attemptid,
            'status' => $status,
            'score' => $score,
            'passmark' => (int) $activity->passmark,
            'xpAwarded' => $xpawarded,
            'dailyBonus' => $targetbonus['dailyBonus'],
            'weeklyBonus' => $targetbonus['weeklyBonus'],
            'targets' => targets::summary($userid),
            'transcript' => $responsetext,
            'analysisJson' => $analysis ? json_encode($analysis) : '',
        ];
        return $out;
    }

    /**
     * @param int $activityid
     * @param array $answers map questionid => choice key
     * @return array{score:float,correct:int,total:int}
     */
    public static function grade_mcq(int $activityid, array $answers): array {
        global $DB;
        $questions = $DB->get_records('local_nexcomm_question', ['activityid' => $activityid]);
        $total = count($questions);
        if ($total < 1) {
            return ['score' => 0.0, 'correct' => 0, 'total' => 0];
        }
        $correct = 0;
        foreach ($questions as $q) {
            $given = isset($answers[(string) $q->id]) ? (string) $answers[(string) $q->id]
                : (isset($answers[$q->id]) ? (string) $answers[$q->id] : '');
            if ($given !== '' && $given === (string) $q->correctkey) {
                $correct++;
            }
        }
        $score = round(($correct / $total) * 100, 2);
        return ['score' => $score, 'correct' => $correct, 'total' => $total];
    }

    /**
     * @param string $text
     * @return int
     */
    public static function word_count(string $text): int {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }
        return count(explode(' ', $text));
    }
}
