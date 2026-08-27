<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LeetCode fetcher via Alfa API — aligned with NexAcademy nexPortfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local\fetchers;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\http;

/**
 * LeetCode.
 */
class leetcode {

    /**
     * @param string $username
     * @return array
     */
    public static function fetch(string $username): array {
        $username = trim($username);
        $base = rtrim((string) get_config('local_nexportfolio', 'leetcodeapi'), '/');
        if ($base === '') {
            $base = 'https://alfa-leetcode-api-production-16ec.up.railway.app';
        }
        $u = rawurlencode($username);

        $profile = http::get_json("$base/$u", [], 20);
        $solved = http::get_json("$base/$u/solved", [], 20);
        $contest = http::get_json("$base/$u/contest", [], 20);
        $history = http::get_json("$base/$u/contest/history", [], 20);
        $calendar = http::get_json("$base/$u/calendar", [], 20);

        if (!$profile && !$solved && !$contest) {
            throw new \RuntimeException('LeetCode profile not found: ' . $username);
        }

        // Solved counts — Alfa uses solvedProblem / easySolved / …
        $easy = (int) ($solved['easySolved'] ?? $profile['easySolved'] ?? 0);
        $medium = (int) ($solved['mediumSolved'] ?? $profile['mediumSolved'] ?? 0);
        $hard = (int) ($solved['hardSolved'] ?? $profile['hardSolved'] ?? 0);
        $total = (int) ($solved['solvedProblem'] ?? $solved['totalSolved'] ?? $profile['totalSolved'] ?? 0);
        if ($total === 0) {
            $total = $easy + $medium + $hard;
        }

        // Contest rating / global contest rank (NOT contribution ranking).
        $rating = 0.0;
        $rank = '';
        $contests = 0;
        $toppercent = null;
        $contesthistory = [];

        if (is_array($contest)) {
            $rating = (float) ($contest['contestRating'] ?? $contest['rating'] ?? 0);
            if (!empty($contest['contestGlobalRanking'])) {
                $rank = (string) (int) $contest['contestGlobalRanking'];
            } else if (!empty($contest['globalRanking'])) {
                $rank = (string) (int) $contest['globalRanking'];
            }
            $contests = (int) ($contest['contestAttend'] ?? $contest['attendedContestsCount'] ?? 0);
            if (isset($contest['contestTopPercentage'])) {
                $toppercent = (float) $contest['contestTopPercentage'];
            }
            if (!empty($contest['contestParticipation']) && is_array($contest['contestParticipation'])) {
                $contesthistory = self::map_contest_rows($contest['contestParticipation']);
            }
        }

        if (!$contesthistory && is_array($history)) {
            $rows = $history['contestHistory'] ?? (is_array($history) && isset($history[0]) ? $history : []);
            if (is_array($rows)) {
                $contesthistory = self::map_contest_rows($rows);
            }
        }

        if ($contests === 0 && $contesthistory) {
            $contests = count($contesthistory);
        }

        // Most recent first.
        usort($contesthistory, static function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        // Calendar → heatmap, current-year active days, current + max streak.
        $heatmap = [];
        $apistreak = 0;
        if (is_array($calendar)) {
            $apistreak = (int) ($calendar['streak'] ?? 0);
            $subcal = $calendar['submissionCalendar'] ?? null;
            if (is_string($subcal)) {
                $subcal = json_decode($subcal, true) ?: [];
            }
            if (is_array($subcal)) {
                foreach ($subcal as $ts => $count) {
                    if (!is_numeric($count) || (int) $count <= 0) {
                        continue;
                    }
                    if (ctype_digit((string) $ts)) {
                        $date = gmdate('Y-m-d', (int) $ts);
                    } else {
                        $date = (string) $ts;
                    }
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        continue;
                    }
                    $heatmap[] = ['date' => $date, 'count' => (int) $count];
                }
            }
        }
        usort($heatmap, static function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $year = (int) gmdate('Y');
        $activedaysyear = 0;
        $activedates = [];
        foreach ($heatmap as $pt) {
            $activedates[] = $pt['date'];
            if ((int) substr($pt['date'], 0, 4) === $year) {
                $activedaysyear++;
            }
        }
        $activedates = array_values(array_unique($activedates));
        sort($activedates);

        $streaks = self::compute_streaks($activedates);
        $currentstreak = $streaks['current'];
        $maxstreak = max($streaks['max'], $apistreak);

        // Rating sparkline from contest history (oldest → newest).
        $ratinghistory = [];
        $chronohistory = $contesthistory;
        usort($chronohistory, static function ($a, $b) {
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });
        foreach ($chronohistory as $row) {
            if (!empty($row['rating'])) {
                $ratinghistory[] = [
                    'date' => $row['date'],
                    'rating' => (float) $row['rating'],
                ];
            }
        }

        return [
            'platform' => 'leetcode',
            'username' => $username,
            'totalSolved' => $total,
            'rating' => $rating,
            'rank' => $rank,
            'contests' => $contests,
            'topPercentage' => $toppercent,
            'problemsByDifficulty' => [
                'easy' => $easy,
                'medium' => $medium,
                'hard' => $hard,
            ],
            'contestHistory' => $contesthistory,
            'ratingHistory' => $ratinghistory,
            'activityHeatmap' => $heatmap,
            'stats' => [
                // Current streak (consecutive days ending today/yesterday).
                'streak' => $currentstreak,
                'currentStreak' => $currentstreak,
                // Longest streak (from calendar + API).
                'maxStreak' => $maxstreak,
                // Active days in the current calendar year only.
                'totalActiveDays' => $activedaysyear,
                'activeDaysYear' => $activedaysyear,
                'activeYear' => $year,
            ],
        ];
    }

    /**
     * @param array $rows
     * @return array
     */
    private static function map_contest_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Skip contests not attended when the flag is present.
            if (array_key_exists('attended', $row) && !$row['attended']) {
                continue;
            }
            $contest = is_array($row['contest'] ?? null) ? $row['contest'] : [];
            $start = (int) ($contest['startTime'] ?? $row['startTime'] ?? 0);
            $date = $start > 0 ? gmdate('Y-m-d', $start) : '';
            $out[] = [
                'name' => (string) ($contest['title'] ?? $row['title'] ?? $row['contestTitle'] ?? 'Contest'),
                'date' => $date,
                'rank' => (int) ($row['ranking'] ?? $row['rank'] ?? 0),
                'rating' => round((float) ($row['rating'] ?? 0), 2),
                'problemsSolved' => (int) ($row['problemsSolved'] ?? 0),
                'totalProblems' => (int) ($row['totalProblems'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param string[] $activedates Y-m-d sorted unique
     * @return array{current:int, max:int}
     */
    private static function compute_streaks(array $activedates): array {
        if (!$activedates) {
            return ['current' => 0, 'max' => 0];
        }

        $set = array_fill_keys($activedates, true);
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);

        $current = 0;
        $cursor = isset($set[$today]) ? $today : (isset($set[$yesterday]) ? $yesterday : null);
        while ($cursor !== null && isset($set[$cursor])) {
            $current++;
            $cursor = gmdate('Y-m-d', strtotime($cursor . ' UTC') - 86400);
        }

        $max = 0;
        $run = 0;
        $prev = null;
        foreach ($activedates as $d) {
            if ($prev !== null && (strtotime($d . ' UTC') - strtotime($prev . ' UTC')) === 86400) {
                $run++;
            } else {
                $run = 1;
            }
            $max = max($max, $run);
            $prev = $d;
        }

        return ['current' => $current, 'max' => $max];
    }
}
