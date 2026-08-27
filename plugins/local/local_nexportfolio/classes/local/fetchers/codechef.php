<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * CodeChef profile scrape — simplified from NexAcademy nexPortfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local\fetchers;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\http;

/**
 * CodeChef.
 */
class codechef {

    /**
     * @param string $username
     * @return array
     */
    public static function fetch(string $username): array {
        $res = http::get('https://www.codechef.com/users/' . rawurlencode($username), [
            'Accept: text/html',
        ], 25);
        if ($res['code'] === 404 || $res['body'] === '') {
            throw new \moodle_exception('error', 'error', '', 'CodeChef user not found');
        }
        $html = $res['body'];

        $rating = 0;
        if (preg_match('/class="rating-number"[^>]*>\s*([\d]+)/i', $html, $m)) {
            $rating = (int) $m[1];
        }

        $ranks = self::extract_ranks($html);
        $globalrank = $ranks['global'];
        $countryrank = $ranks['country'];
        // Combined for DB ranktext / simple cards.
        $rankparts = [];
        if ($globalrank !== '') {
            $rankparts[] = 'Global ' . $globalrank;
        }
        if ($countryrank !== '') {
            $rankparts[] = 'Country ' . $countryrank;
        }
        $rank = implode(' · ', $rankparts);

        $fully = 0;
        if (preg_match('/Total\s+Problems\s+Solved[^0-9]*([\d]+)/i', $html, $m)
            || preg_match('/Fully\s+Solved[^0-9]*([\d]+)/i', $html, $m)
            || preg_match('/"fully_solved":\s*\{[^}]*"count":\s*(\d+)/i', $html, $m)
            || preg_match('/problemsFullySolved["\']?\s*[:=]\s*(\d+)/i', $html, $m)) {
            $fully = (int) $m[1];
        }
        // Fallback: count practice links.
        if ($fully === 0 && preg_match_all('/\/problems\/[A-Z0-9_]+/i', $html, $mm)) {
            $fully = count(array_unique($mm[0]));
        }

        $heatmap = [];
        if (preg_match('/heatmapData\s*=\s*(\{.*?\});/s', $html, $m)
            || preg_match('/"heatmap_data"\s*:\s*(\{.*?\})/s', $html, $m)) {
            $map = json_decode($m[1], true);
            if (is_array($map)) {
                foreach ($map as $date => $count) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                        $heatmap[] = ['date' => (string) $date, 'count' => (int) $count];
                    }
                }
            }
        }
        usort($heatmap, static function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $contests = 0;
        if (preg_match('/contest-participated-count[^<]*<b[^>]*>(\d+)/i', $html, $m)
            || preg_match('/Contests?\s*Participated[^0-9]*([\d]+)/i', $html, $m)) {
            $contests = (int) $m[1];
        }

        $contesthistory = self::extract_contest_history($html);
        if ($contests === 0 && $contesthistory) {
            $contests = count($contesthistory);
        }

        $ratinghistory = [];
        $chrono = $contesthistory;
        usort($chrono, static function ($a, $b) {
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });
        foreach ($chrono as $row) {
            if (!empty($row['rating'])) {
                $ratinghistory[] = [
                    'date' => $row['date'],
                    'rating' => (float) $row['rating'],
                ];
            }
        }

        $rawdiff = self::extract_difficulty($html);
        $bydiff = \local_nexportfolio\local\difficulty::to_emh($rawdiff);

        return [
            'platform' => 'codechef',
            'username' => $username,
            'totalSolved' => $fully,
            'rating' => (float) $rating,
            'rank' => $rank,
            'globalRank' => $globalrank,
            'countryRank' => $countryrank,
            'contests' => $contests,
            'contestHistory' => $contesthistory,
            'ratingHistory' => $ratinghistory,
            'problemsByDifficulty' => $bydiff,
            'problemsByDifficultyRaw' => $rawdiff,
            'activityHeatmap' => $heatmap,
            'stats' => [
                'totalActiveDays' => count(array_filter($heatmap, static function ($r) {
                    return ($r['count'] ?? 0) > 0;
                })),
                'streak' => 0,
            ],
        ];
    }

    /**
     * Extract CodeChef Global Rank and Country Rank from profile HTML.
     *
     * @param string $html
     * @return array{global:string, country:string}
     */
    private static function extract_ranks(string $html): array {
        $global = '';
        $country = '';

        // Preferred: .rating-ranks list items with Global Rank / Country Rank labels.
        if (preg_match(
            '/rating-ranks[\s\S]{0,1200}?Global\s*Rank[\s\S]{0,400}?Country\s*Rank/i',
            $html,
            $block
        )) {
            $chunk = $block[0];
            if (preg_match_all('/<strong>\s*([\d,]+)\s*<\/strong>/i', $chunk, $mm) && count($mm[1]) >= 2) {
                $global = str_replace(',', '', $mm[1][0]);
                $country = str_replace(',', '', $mm[1][1]);
            }
        }

        if ($global === '' && preg_match(
            '/<strong>\s*([\d,]+)\s*<\/strong>\s*<\/a>\s*Global\s*Rank/i',
            $html,
            $m
        )) {
            $global = str_replace(',', '', $m[1]);
        }
        if ($country === '' && preg_match(
            '/<strong>\s*([\d,]+)\s*<\/strong>\s*<\/a>\s*Country\s*Rank/i',
            $html,
            $m
        )) {
            $country = str_replace(',', '', $m[1]);
        }

        // Older / alternate markup.
        if ($global === '' && preg_match('/Global\s*Rank[^0-9]{0,40}([\d,]+)/i', $html, $m)) {
            $global = str_replace(',', '', $m[1]);
        }
        if ($country === '' && preg_match('/Country\s*Rank[^0-9]{0,40}([\d,]+)/i', $html, $m)) {
            $country = str_replace(',', '', $m[1]);
        }

        return ['global' => $global, 'country' => $country];
    }

    /**
     * Parse CodeChef `var all_rating = [...]` contest history from profile HTML.
     *
     * @param string $html
     * @return array
     */
    private static function extract_contest_history(string $html): array {
        $out = [];
        if (!preg_match('/var\s+all_rating\s*=\s*(\[[\s\S]*?\]);/', $html, $m)) {
            return $out;
        }
        $json = $m[1];
        $json = str_replace(["'", 'None'], ['"', 'null'], $json);
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $end = (string) ($row['end_date'] ?? '');
            $date = $end !== '' ? substr($end, 0, 10) : '';
            if ($date === '' && !empty($row['getyear']) && !empty($row['getmonth']) && !empty($row['getday'])) {
                $date = sprintf('%04d-%02d-%02d', (int) $row['getyear'], (int) $row['getmonth'], (int) $row['getday']);
            }
            $name = (string) ($row['name'] ?? $row['code'] ?? 'Contest');
            // Strip simple HTML entities left in contest titles.
            $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out[] = [
                'name' => $name,
                'code' => (string) ($row['code'] ?? ''),
                'date' => $date,
                'rank' => (int) ($row['rank'] ?? 0),
                'rating' => (float) ($row['rating'] ?? 0),
                'oldRating' => (float) ($row['old_rating'] ?? 0),
            ];
        }
        usort($out, static function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        return $out;
    }

    /**
     * Scrape CodeChef "Problem Solved By" school/easy/medium/hard lists when present.
     *
     * @param string $html
     * @return array
     */
    private static function extract_difficulty(string $html): array {
        $raw = [];
        // Common: Easy (123) / Medium (45) near practice stats.
        if (preg_match_all('/\b(School|Beginner|Easy|Medium|Hard|Challenge)\s*\(\s*(\d+)\s*\)/i', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                $key = strtolower($row[1]);
                $raw[$key] = max((int) ($raw[$key] ?? 0), (int) $row[2]);
            }
        }
        // JSON-ish: "easy": 12
        foreach (['school', 'beginner', 'easy', 'medium', 'hard', 'challenge'] as $key) {
            if (preg_match('/"' . $key . '"\s*:\s*(\d+)/i', $html, $m)) {
                $raw[$key] = max((int) ($raw[$key] ?? 0), (int) $m[1]);
            }
        }
        return $raw;
    }
}
