<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * English Central–style Watch / Learn / Speak lessons.
 */
class lesson {

    /**
     * @param int $userid
     * @return array{items:array,total:int}
     */
    public static function list_lessons(int $userid): array {
        global $DB;
        $rows = $DB->get_records('local_nexcomm_lesson', ['status' => 'ready'], 'difficulty ASC, title ASC');
        $items = [];
        foreach ($rows as $r) {
            $items[] = self::card($r, $userid);
        }
        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * @param \stdClass $r
     * @param int $userid
     * @return array
     */
    public static function card(\stdClass $r, int $userid): array {
        global $DB;
        $prog = $userid ? $DB->get_record('local_nexcomm_lessonprog', [
            'userid' => $userid,
            'lessonid' => $r->id,
        ]) : false;
        $linecount = (int) $DB->count_records('local_nexcomm_lessonline', ['lessonid' => $r->id]);
        $wordcount = (int) $DB->count_records('local_nexcomm_lessonword', ['lessonid' => $r->id]);
        return [
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            'difficulty' => (string) $r->difficulty,
            'summary' => (string) ($r->summary ?? ''),
            'topic' => (string) ($r->topic ?? ''),
            'videourl' => (string) ($r->videourl ?? ''),
            'lineCount' => $linecount,
            'wordCount' => $wordcount,
            'watched' => $prog ? (int) $prog->watched === 1 : false,
            'wordsLearned' => $prog ? (int) $prog->wordslearned : 0,
            'linesSpoken' => $prog ? (int) $prog->linesspoken : 0,
            'complete' => $prog ? (int) $prog->complete === 1 : false,
            'learnScore' => $prog ? (float) $prog->learnscore : 0,
            'speakScore' => $prog ? (float) $prog->speakscore : 0,
            'url' => (new \moodle_url('/local/nexcomm/lesson.php', ['id' => $r->id]))->out(false),
        ];
    }

    /**
     * @param int $lessonid
     * @param int $userid
     * @return array
     */
    public static function get_lesson(int $lessonid, int $userid): array {
        global $DB;
        $r = $DB->get_record('local_nexcomm_lesson', ['id' => $lessonid], '*', MUST_EXIST);
        if ($r->status !== 'ready' && !has_capability('local/nexcomm:manage', \context_system::instance())) {
            throw new \moodle_exception('notfound', 'local_nexcomm');
        }
        $lines = [];
        foreach ($DB->get_records('local_nexcomm_lessonline', ['lessonid' => $lessonid], 'sortorder ASC') as $line) {
            $lines[] = [
                'id' => (int) $line->id,
                'speaker' => (string) $line->speaker,
                'text' => (string) $line->linetext,
                'sortorder' => (int) $line->sortorder,
            ];
        }
        $words = [];
        foreach ($DB->get_records('local_nexcomm_lessonword', ['lessonid' => $lessonid], 'sortorder ASC') as $w) {
            $blank = preg_replace('/\b' . preg_quote($w->word, '/') . '\b/i', '_____', $w->sentence, 1);
            $words[] = [
                'id' => (int) $w->id,
                'word' => (string) $w->word,
                'hint' => (string) $w->hint,
                'sentence' => (string) $blank,
                'answerLength' => strlen((string) $w->word),
            ];
        }
        $card = self::card($r, $userid);
        $prog = $DB->get_record('local_nexcomm_lessonprog', ['userid' => $userid, 'lessonid' => $lessonid]);
        return array_merge($card, [
            'lines' => $lines,
            'words' => $words,
            'speakJson' => $prog && $prog->speakjson ? (string) $prog->speakjson : '{}',
            'learnJson' => $prog && $prog->learnjson ? (string) $prog->learnjson : '{}',
            'videosUrl' => (new \moodle_url('/local/nexcomm/videos.php'))->out(false),
        ]);
    }

    /**
     * @param int $userid
     * @param int $lessonid
     * @param array $payload
     * @return array
     */
    public static function save_progress(int $userid, int $lessonid, array $payload): array {
        global $DB;

        $lesson = $DB->get_record('local_nexcomm_lesson', ['id' => $lessonid, 'status' => 'ready'], '*', MUST_EXIST);
        $now = time();
        $prog = $DB->get_record('local_nexcomm_lessonprog', ['userid' => $userid, 'lessonid' => $lessonid]);
        if (!$prog) {
            $prog = (object) [
                'userid' => $userid,
                'lessonid' => $lessonid,
                'watched' => 0,
                'wordslearned' => 0,
                'linesspoken' => 0,
                'learnscore' => 0,
                'speakscore' => 0,
                'learnjson' => '{}',
                'speakjson' => '{}',
                'complete' => 0,
                'timemodified' => $now,
            ];
            $prog->id = $DB->insert_record('local_nexcomm_lessonprog', $prog);
        }

        $mode = (string) ($payload['mode'] ?? '');
        $xpawarded = 0;

        if ($mode === 'watch') {
            $prog->watched = 1;
        } else if ($mode === 'learn') {
            $answers = $payload['answers'] ?? [];
            if (is_string($answers)) {
                $answers = json_decode($answers, true) ?: [];
            }
            $result = self::grade_learn($lessonid, $answers);
            $prog->learnjson = json_encode($result['detail']);
            $prog->learnscore = $result['score'];
            $prog->wordslearned = $result['correct'];
        } else if ($mode === 'speak') {
            $lineid = (int) ($payload['lineid'] ?? 0);
            $transcript = trim((string) ($payload['transcript'] ?? ''));
            $line = $DB->get_record('local_nexcomm_lessonline', ['id' => $lineid, 'lessonid' => $lessonid], '*', MUST_EXIST);
            $score = self::pronunciation_score((string) $line->linetext, $transcript);
            $speak = json_decode($prog->speakjson ?: '{}', true) ?: [];
            $prev = isset($speak[(string) $lineid]['score']) ? (float) $speak[(string) $lineid]['score'] : 0;
            if ($score >= $prev) {
                $speak[(string) $lineid] = [
                    'score' => $score,
                    'transcript' => $transcript,
                    'passed' => $score >= 60,
                ];
            }
            $prog->speakjson = json_encode($speak);
            $passed = 0;
            $sum = 0;
            $n = 0;
            foreach ($speak as $row) {
                $n++;
                $sum += (float) $row['score'];
                if (!empty($row['passed'])) {
                    $passed++;
                }
            }
            $prog->linesspoken = $passed;
            $prog->speakscore = $n > 0 ? round($sum / $n, 2) : 0;
        } else {
            throw new \invalid_parameter_exception('Invalid mode');
        }

        $linecount = (int) $DB->count_records('local_nexcomm_lessonline', ['lessonid' => $lessonid]);
        $wordcount = (int) $DB->count_records('local_nexcomm_lessonword', ['lessonid' => $lessonid]);
        $wascomplete = (int) $prog->complete === 1;
        $prog->complete = (
            (int) $prog->watched === 1
            && ($wordcount < 1 || (float) $prog->learnscore >= 70)
            && ($linecount < 1 || (int) $prog->linesspoken >= max(1, (int) ceil($linecount * 0.7)))
        ) ? 1 : 0;
        $prog->timemodified = $now;
        $DB->update_record('local_nexcomm_lessonprog', $prog);

        if (!$wascomplete && (int) $prog->complete === 1) {
            $amount = gamification::xp_for((string) $lesson->difficulty, 'speaking');
            $reason = 'lesson_' . $lessonid;
            $before = $DB->record_exists('local_nexcomm_xpevent', ['userid' => $userid, 'reason' => $reason]);
            gamification::add_xp($userid, $amount, 0, $reason);
            if (!$before && $DB->record_exists('local_nexcomm_xpevent', ['userid' => $userid, 'reason' => $reason])) {
                $xpawarded = $amount;
            }
            targets::record_completion($userid, 1000000 + $lessonid); // Synthetic activity id space for lessons.
        }

        return [
            'watched' => (int) $prog->watched === 1,
            'wordsLearned' => (int) $prog->wordslearned,
            'linesSpoken' => (int) $prog->linesspoken,
            'learnScore' => (float) $prog->learnscore,
            'speakScore' => (float) $prog->speakscore,
            'complete' => (int) $prog->complete === 1,
            'speakJson' => (string) $prog->speakjson,
            'xpAwarded' => $xpawarded,
            'goals' => self::goals_summary($userid),
            'lineScore' => isset($score) ? (float) $score : 0,
        ];
    }

    /**
     * @param int $lessonid
     * @param array $answers wordid => answer
     * @return array
     */
    public static function grade_learn(int $lessonid, array $answers): array {
        global $DB;
        $words = $DB->get_records('local_nexcomm_lessonword', ['lessonid' => $lessonid]);
        $total = count($words);
        $correct = 0;
        $detail = [];
        foreach ($words as $w) {
            $given = isset($answers[(string) $w->id]) ? trim((string) $answers[(string) $w->id])
                : (isset($answers[$w->id]) ? trim((string) $answers[$w->id]) : '');
            $ok = strcasecmp($given, (string) $w->word) === 0;
            if ($ok) {
                $correct++;
            }
            $detail[(string) $w->id] = ['ok' => $ok, 'answer' => (string) $w->word];
        }
        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 100;
        return ['score' => $score, 'correct' => $correct, 'total' => $total, 'detail' => $detail];
    }

    /**
     * Approximate pronunciation via transcript similarity (0–100).
     *
     * @param string $expected
     * @param string $said
     * @return float
     */
    public static function pronunciation_score(string $expected, string $said): float {
        $normalize = static function (string $s): string {
            $s = strtolower($s);
            $s = preg_replace("/[^a-z0-9\s']/", ' ', $s) ?? '';
            $s = preg_replace('/\s+/', ' ', trim($s)) ?? '';
            return $s;
        };
        $a = $normalize($expected);
        $b = $normalize($said);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);
        $aw = array_filter(explode(' ', $a));
        $bw = array_filter(explode(' ', $b));
        $hit = 0;
        $bset = array_count_values($bw);
        foreach ($aw as $w) {
            if (!empty($bset[$w])) {
                $hit++;
                $bset[$w]--;
            }
        }
        $overlap = count($aw) > 0 ? ($hit / count($aw)) * 100 : 0;
        return round(max(0, min(100, ($pct * 0.45) + ($overlap * 0.55))), 2);
    }

    /**
     * EC-style study goals.
     *
     * @param int $userid
     * @return array
     */
    public static function goals_summary(int $userid): array {
        global $DB;
        $watchgoal = max(1, (int) (get_config('local_nexcomm', 'watchgoal') ?: 3));
        $learngoal = max(1, (int) (get_config('local_nexcomm', 'learngoal') ?: 20));
        $speakgoal = max(1, (int) (get_config('local_nexcomm', 'speakgoal') ?: 15));

        $watched = (int) $DB->count_records_select('local_nexcomm_lessonprog', 'userid = ? AND watched = 1', [$userid]);
        $words = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(wordslearned),0) FROM {local_nexcomm_lessonprog} WHERE userid = ?",
            [$userid]
        );
        $lines = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(linesspoken),0) FROM {local_nexcomm_lessonprog} WHERE userid = ?",
            [$userid]
        );

        return [
            'watchDone' => $watched,
            'watchGoal' => $watchgoal,
            'watchPct' => min(100, (int) round(($watched / $watchgoal) * 100)),
            'learnDone' => $words,
            'learnGoal' => $learngoal,
            'learnPct' => min(100, (int) round(($words / $learngoal) * 100)),
            'speakDone' => $lines,
            'speakGoal' => $speakgoal,
            'speakPct' => min(100, (int) round(($lines / $speakgoal) * 100)),
        ];
    }
}
