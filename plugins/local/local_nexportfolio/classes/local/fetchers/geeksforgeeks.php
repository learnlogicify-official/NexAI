<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * GeeksforGeeks profile scrape — from NexAcademy nexPortfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local\fetchers;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\http;

/**
 * GeeksforGeeks.
 */
class geeksforgeeks {

    /**
     * @param string $username
     * @return array
     */
    public static function fetch(string $username): array {
        $username = ltrim(trim($username), '@/');
        $username = preg_replace('#^.*/(?:user|profile)/#i', '', $username);
        $username = rtrim($username, '/');
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            throw new \InvalidArgumentException('Invalid GeeksforGeeks username');
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ];

        $html = '';
        foreach ([
            'https://www.geeksforgeeks.org/user/' . rawurlencode($username) . '/',
            'https://www.geeksforgeeks.org/profile/' . rawurlencode($username),
            'https://www.geeksforgeeks.org/profile/' . rawurlencode($username) . '?tab=activity',
        ] as $url) {
            $res = http::get($url, $headers, 25);
            if (($res['code'] ?? 0) >= 200 && ($res['code'] ?? 0) < 400 && strlen($res['body'] ?? '') > 1000) {
                $html = $res['body'];
                // Prefer a page that embeds practice stats.
                if (strpos($html, 'total_problems_solved') !== false) {
                    break;
                }
            }
        }

        if ($html === '') {
            throw new \RuntimeException('Could not reach GeeksforGeeks for "' . $username . '"');
        }

        if (stripos($html, 'Page Not Found') !== false && strpos($html, 'total_problems_solved') === false) {
            throw new \RuntimeException('GeeksforGeeks user not found: ' . $username);
        }

        $info = self::extract_user_info($html);
        if (!$info) {
            throw new \RuntimeException('Could not parse GeeksforGeeks profile for "' . $username . '"');
        }

        $solved = (int) ($info['total_problems_solved'] ?? 0);
        $score = (float) ($info['score'] ?? 0);
        $rank = isset($info['institute_rank']) && $info['institute_rank'] !== '' && $info['institute_rank'] !== null
            ? (string) $info['institute_rank']
            : '';
        $streak = (int) ($info['pod_solved_current_streak'] ?? 0);
        $displayname = (string) ($info['name'] ?? '');

        // Fallback regexes if JSON object was partial.
        if ($solved === 0 && preg_match('/Problems\s+Solved[^0-9]{0,80}(\d{1,5})/i', $html, $m)) {
            $solved = (int) $m[1];
        }
        if (!$score && preg_match('/Coding\s+Score[^0-9]{0,80}(\d{1,5})/i', $html, $m)) {
            $score = (float) $m[1];
        }

        $heatmap = self::extract_heatmap($html);
        $rawdiff = self::extract_difficulty($html);
        $bydiff = \local_nexportfolio\local\difficulty::to_emh($rawdiff);

        return [
            'platform' => 'geeksforgeeks',
            'username' => $username,
            'displayName' => $displayname,
            'totalSolved' => $solved,
            'rating' => $score,
            'score' => $score,
            'rank' => $rank,
            'contests' => 0,
            'problemsByDifficulty' => $bydiff,
            'problemsByDifficultyRaw' => $rawdiff,
            'activityHeatmap' => $heatmap,
            'stats' => [
                'totalActiveDays' => count(array_filter($heatmap, static function ($r) {
                    return ($r['count'] ?? 0) > 0;
                })),
                'streak' => $streak,
            ],
        ];
    }

    /**
     * Extract GFG school/basic/easy/medium/hard counts from profile HTML.
     *
     * @param string $html
     * @return array
     */
    private static function extract_difficulty(string $html): array {
        $raw = [];
        // Navbar / text: SCHOOL (12), BASIC (5), …
        if (preg_match_all('/\b(SCHOOL|BASIC|EASY|MEDIUM|HARD)\s*\(\s*(\d+)\s*\)/i', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                $key = strtolower($row[1]);
                $raw[$key] = max((int) ($raw[$key] ?? 0), (int) $row[2]);
            }
        }
        // Embedded JSON keys.
        foreach (['school', 'basic', 'easy', 'medium', 'hard'] as $key) {
            if (preg_match('/"' . $key . '"\s*:\s*(\d+)/i', $html, $m)) {
                $raw[$key] = max((int) ($raw[$key] ?? 0), (int) $m[1]);
            }
        }
        return $raw;
    }

    /**
     * @param string $html
     * @return array|null
     */
    private static function extract_user_info(string $html): ?array {
        $unescaped = str_replace(['\\"', '\\/', '\\u003c', '\\u003e'], ['"', '/', '<', '>'], $html);
        $pos = strpos($unescaped, '"total_problems_solved"');
        if ($pos === false) {
            $pos = strpos($unescaped, '"monthly_score"');
        }
        if ($pos === false) {
            return null;
        }

        // Walk left to opening brace of the object.
        $start = null;
        $depth = 0;
        for ($i = $pos; $i >= 0; $i--) {
            $ch = $unescaped[$i];
            if ($ch === '}') {
                $depth++;
            } else if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($start === null) {
            return null;
        }

        $depth = 0;
        $end = null;
        $len = strlen($unescaped);
        $max = min($len, $start + 8000);
        for ($i = $start; $i < $max; $i++) {
            $ch = $unescaped[$i];
            if ($ch === '{') {
                $depth++;
            } else if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }
        if ($end === null) {
            return null;
        }

        $data = json_decode(substr($unescaped, $start, $end - $start), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $html
     * @return array
     */
    private static function extract_heatmap(string $html): array {
        $heatmap = [];
        // Date→count maps sometimes appear in embedded activity payloads.
        if (preg_match_all('/"(20\d{2}-\d{2}-\d{2})"\s*:\s*(\d{1,4})/', $html, $mm, PREG_SET_ORDER)) {
            $map = [];
            foreach ($mm as $row) {
                $count = (int) $row[2];
                // Ignore huge year-like false positives.
                if ($count > 500) {
                    continue;
                }
                $map[$row[1]] = ($map[$row[1]] ?? 0) + $count;
            }
            foreach ($map as $date => $count) {
                if ($count > 0) {
                    $heatmap[] = ['date' => $date, 'count' => $count];
                }
            }
            usort($heatmap, static function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });
            // Keep last ~400 days worth if oversized.
            if (count($heatmap) > 400) {
                $heatmap = array_slice($heatmap, -400);
            }
        }
        return $heatmap;
    }
}
