<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Analyze speaking transcripts (placement English heuristics).
 */
class speech_analysis {

    /** @var string[] */
    private const FILLERS = [
        'um', 'uh', 'erm', 'hmm', 'like', 'you know', 'basically', 'actually',
        'sort of', 'kind of', 'i mean', 'right', 'okay so',
    ];

    /**
     * @param string $transcript
     * @param string $prompt
     * @param int $durationsec
     * @param int $passmark
     * @return array
     */
    public static function analyze(string $transcript, string $prompt, int $durationsec, int $passmark = 70): array {
        $transcript = trim(preg_replace('/\s+/', ' ', $transcript) ?? '');
        $words = $transcript === '' ? [] : preg_split('/\s+/', strtolower($transcript)) ?: [];
        $wordcount = count($words);
        $durationsec = max(0, $durationsec);
        $wpm = $durationsec > 0 ? (int) round(($wordcount / $durationsec) * 60) : 0;

        $fillercount = 0;
        $lower = strtolower($transcript);
        foreach (self::FILLERS as $f) {
            $fillercount += substr_count($lower, $f);
        }
        $fillerratio = $wordcount > 0 ? round(($fillercount / $wordcount) * 100, 1) : 0.0;

        $promptwords = self::keywords($prompt);
        $hit = 0;
        foreach ($promptwords as $kw) {
            if (str_contains($lower, $kw)) {
                $hit++;
            }
        }
        $coverage = count($promptwords) > 0
            ? (int) round(($hit / count($promptwords)) * 100)
            : ($wordcount >= 40 ? 80 : 40);

        // Heuristic score 0–100.
        $score = 0;
        if ($wordcount >= 80) {
            $score += 35;
        } else if ($wordcount >= 40) {
            $score += 25;
        } else if ($wordcount >= 20) {
            $score += 12;
        }

        if ($durationsec >= 45) {
            $score += 20;
        } else if ($durationsec >= 20) {
            $score += 14;
        } else if ($durationsec >= 10) {
            $score += 6;
        }

        if ($wpm >= 110 && $wpm <= 160) {
            $score += 20;
        } else if ($wpm >= 90 && $wpm <= 180) {
            $score += 12;
        } else if ($wpm > 0) {
            $score += 5;
        }

        $score += (int) round($coverage * 0.20);
        if ($fillerratio <= 3) {
            $score += 10;
        } else if ($fillerratio <= 6) {
            $score += 5;
        }
        $score = max(0, min(100, $score));

        $feedback = [];
        if ($transcript === '') {
            $feedback[] = 'No transcript was captured. Allow microphone access and speak clearly (Chrome works best).';
        } else {
            if ($wordcount < 40) {
                $feedback[] = 'Speak longer — aim for at least 40–60 words for a placement answer.';
            }
            if ($durationsec > 0 && $durationsec < 20) {
                $feedback[] = 'Recording was short. Target 45–90 seconds for HR answers.';
            }
            if ($wpm > 0 && $wpm < 90) {
                $feedback[] = 'Pace is slow. Practise a slightly faster, confident delivery.';
            }
            if ($wpm > 180) {
                $feedback[] = 'Pace is fast. Slow down so an interviewer can follow you.';
            }
            if ($fillerratio > 6) {
                $feedback[] = 'Too many filler words (um/like/you know). Pause instead of filling silence.';
            }
            if ($coverage < 50 && count($promptwords) > 0) {
                $feedback[] = 'Address more of the prompt directly (situation, action, result).';
            }
            if (!$feedback) {
                $feedback[] = 'Solid attempt — clear length and structure for placement practice.';
            }
        }

        $status = ($transcript !== '' && $score >= $passmark) ? 'passed' : 'failed';
        if ($transcript === '' && $durationsec >= 10) {
            // Audio-only fallback: credit submit but mark needs transcript.
            $score = max($score, 60);
            $status = $score >= $passmark ? 'submitted' : 'failed';
            $feedback[] = 'Audio saved. Transcript missing — analysis is limited.';
        }

        return [
            'transcript' => $transcript,
            'wordCount' => $wordcount,
            'durationSec' => $durationsec,
            'wpm' => $wpm,
            'fillerCount' => $fillercount,
            'fillerRatio' => $fillerratio,
            'promptCoverage' => $coverage,
            'score' => (float) $score,
            'status' => $status,
            'feedback' => $feedback,
        ];
    }

    /**
     * @param string $prompt
     * @return string[]
     */
    private static function keywords(string $prompt): array {
        $stop = ['the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'your', 'you',
            'me', 'my', 'is', 'are', 'be', 'that', 'this', 'from', 'as', 'at', 'by', 'it', 'about',
            'one', 'two', 'short', 'give', 'share', 'tell', 'describe', 'explain', 'answer', 'keep'];
        $words = preg_split('/[^a-z0-9]+/', strtolower($prompt)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (strlen($w) < 4 || in_array($w, $stop, true)) {
                continue;
            }
            $out[$w] = true;
            if (count($out) >= 8) {
                break;
            }
        }
        return array_keys($out);
    }
}
