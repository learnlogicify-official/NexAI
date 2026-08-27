<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Coding Ninjas / Naukri Code360 fetcher.
 *
 * Uses public_section APIs. The handle must be the profile UUID/slug from
 * https://www.naukri.com/code360/profile/<id> (not always a display username).
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local\fetchers;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\http;

/**
 * Coding Ninjas (Code360).
 */
class codingninjas {

    /**
     * @param string $username Profile UUID / screen slug / full profile URL
     * @return array
     */
    public static function fetch(string $username): array {
        $uuid = self::normalize_id($username);
        if ($uuid === '') {
            throw new \InvalidArgumentException('Invalid Coding Ninjas / Code360 profile id');
        }

        // Optional NexAcademy / custom relay.
        $proxy = trim((string) get_config('local_nexportfolio', 'codingninjasproxy'));
        if ($proxy !== '') {
            $fromproxy = self::fetch_via_proxy($proxy, $uuid);
            if ($fromproxy !== null) {
                return $fromproxy;
            }
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
        ];

        $bases = [
            'https://api.codingninjas.com/api/v3/public_section',
            'https://www.naukri.com/code360/api/v3/public_section',
        ];

        $details = null;
        $detailserr = '';
        foreach ($bases as $base) {
            $url = $base . '/profile/user_details?uuid=' . rawurlencode($uuid);
            $res = http::get($url, $headers, 25);
            $json = self::decode($res['body'] ?? '');
            if (is_array($json) && !empty($json['data']) && is_array($json['data'])) {
                $details = $json['data'];
                break;
            }
            if (is_array($json)) {
                $detailserr = (string) ($json['message'] ?? $json['error']['message'] ?? ('HTTP ' . ($res['code'] ?? 0)));
            }
        }

        $solved = 0;
        $bydiff = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        $rawdiff = [];
        $displayname = '';

        // Primary source: profile/user_details → dsa_domain_data.problem_count_data
        // (view_solved_problems is often unauthorized for public callers).
        if (is_array($details)) {
            $displayname = (string) ($details['name'] ?? $details['profile']['name'] ?? '');
            $counts = self::extract_problem_counts($details);
            $solved = $counts['total'];
            $bydiff = $counts['bydiff'];
            $rawdiff = $counts['raw'];
        }

        // Fallback only if user_details lacked counts.
        if ($solved === 0) {
            foreach ($bases as $base) {
                $url = $base . '/profile/view_solved_problems?uuid=' . rawurlencode($uuid);
                $res = http::get($url, $headers, 25);
                $json = self::decode($res['body'] ?? '');
                if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
                    continue;
                }
                $data = $json['data'];
                if (isset($data['total_solved'])) {
                    $solved = (int) $data['total_solved'];
                } else if (isset($data['totalSolved'])) {
                    $solved = (int) $data['totalSolved'];
                } else if (isset($data['total_count'])) {
                    $solved = (int) $data['total_count'];
                }
                if ($solved > 0) {
                    break;
                }
            }
        }

        $rating = 0.0;
        $contests = 0;
        $rank = '';
        foreach ($bases as $base) {
            $url = $base . '/user_rating_data?uuid=' . rawurlencode($uuid);
            $res = http::get($url, $headers, 25);
            $json = self::decode($res['body'] ?? '');
            if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
                continue;
            }
            $data = $json['data'];
            if (isset($data['current_user_rating'])) {
                $rating = (float) $data['current_user_rating'];
            }
            if (!empty($data['rating_group']['group'])) {
                $rank = (string) $data['rating_group']['group'];
            }
            if (!empty($data['user_rating_data']) && is_array($data['user_rating_data'])) {
                $series = $data['user_rating_data'];
                $contests = count($series);
                if (!$rating) {
                    $last = end($series);
                    if (is_array($last)) {
                        $rating = (float) ($last['rating'] ?? 0);
                    }
                }
            }
            if ($rating || $contests || $rank !== '') {
                break;
            }
        }

        if ($details === null && $solved === 0 && !$rating && $contests === 0) {
            throw new \RuntimeException(
                'Coding Ninjas / Code360 profile not found for "' . $uuid . '". '
                . 'Use the id from https://www.naukri.com/code360/profile/<id>'
                . ($detailserr !== '' ? ' (' . $detailserr . ')' : '')
                . '. Or set a Coding Ninjas proxy URL in plugin settings.'
            );
        }

        return [
            'platform' => 'codingninjas',
            'username' => $uuid,
            'displayName' => $displayname,
            'totalSolved' => $solved,
            'rating' => $rating,
            'rank' => $rank,
            'contests' => $contests,
            'problemsByDifficulty' => $bydiff,
            'problemsByDifficultyRaw' => $rawdiff,
            'activityHeatmap' => [],
            'stats' => [
                'totalActiveDays' => 0,
                'streak' => 0,
                'xp' => is_array($details) ? (int) ($details['user_exp'] ?? 0) : 0,
                'level' => is_array($details) ? (string) ($details['user_level_name'] ?? '') : '',
            ],
            'profileUrl' => 'https://www.naukri.com/code360/profile/' . rawurlencode($uuid),
        ];
    }

    /**
     * Pull solved totals from domain problem_count_data blocks.
     *
     * @param array $details
     * @return array{total:int, bydiff:array, raw:array}
     */
    private static function extract_problem_counts(array $details): array {
        $raw = ['easy' => 0, 'moderate' => 0, 'hard' => 0, 'ninja' => 0];
        $total = 0;

        // Prefer DSA domain (what the public profile highlights), then add other domains.
        $domains = [];
        foreach (['dsa_domain_data', 'web_domain_data', 'analytics_domain_data'] as $key) {
            if (!empty($details[$key]) && is_array($details[$key])) {
                $domains[] = $details[$key];
            }
        }

        foreach ($domains as $domain) {
            $pcd = $domain['problem_count_data'] ?? null;
            if (!is_array($pcd)) {
                continue;
            }
            if (isset($pcd['total_count'])) {
                $total += (int) $pcd['total_count'];
            }
            if (!empty($pcd['difficulty_data']) && is_array($pcd['difficulty_data'])) {
                foreach ($pcd['difficulty_data'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $level = strtolower((string) ($row['level'] ?? ''));
                    $count = (int) ($row['count'] ?? 0);
                    if ($level === '') {
                        continue;
                    }
                    if ($level === 'medium') {
                        $level = 'moderate';
                    }
                    $raw[$level] = ($raw[$level] ?? 0) + $count;
                }
            }
        }

        $bydiff = \local_nexportfolio\local\difficulty::to_emh($raw);
        // If total_count missing, derive from EMH buckets.
        if ($total === 0) {
            $total = $bydiff['easy'] + $bydiff['medium'] + $bydiff['hard'];
        }

        return ['total' => $total, 'bydiff' => $bydiff, 'raw' => $raw];
    }

    /**
     * @param string $raw
     * @return string
     */
    private static function normalize_id(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Full URL → last path segment.
        if (preg_match('#https?://#i', $raw)) {
            $path = parse_url($raw, PHP_URL_PATH);
            $parts = array_values(array_filter(explode('/', (string) $path)));
            $raw = $parts ? end($parts) : $raw;
        }
        $raw = ltrim($raw, '@/');
        // Allow UUID and alphanumeric slugs.
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $raw)) {
            return '';
        }
        return $raw;
    }

    /**
     * @param string $body
     * @return array|null
     */
    private static function decode(string $body): ?array {
        if ($body === '') {
            return null;
        }
        // Some responses are gzipped oddly; try plain JSON first.
        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }
        if (function_exists('gzdecode')) {
            $unzipped = @gzdecode($body);
            if (is_string($unzipped)) {
                $data = json_decode($unzipped, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * @param string $proxy
     * @param string $uuid
     * @return array|null
     */
    private static function fetch_via_proxy(string $proxy, string $uuid): ?array {
        $url = str_replace(
            ['{username}', '{handle}', '{uuid}'],
            [rawurlencode($uuid), rawurlencode($uuid), rawurlencode($uuid)],
            $proxy
        );
        if (strpos($proxy, '{username}') === false && strpos($proxy, '{handle}') === false
            && strpos($proxy, '{uuid}') === false) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . 'username=' . rawurlencode($uuid);
        }
        $res = http::get($url, ['Accept: application/json'], 40);
        $data = self::decode($res['body'] ?? '');
        if (!$data) {
            return null;
        }
        $profile = $data;
        if (isset($data['profile']) && is_array($data['profile'])) {
            $profile = $data['profile'];
        } else if (isset($data['platforms']['codingninjas']) && is_array($data['platforms']['codingninjas'])) {
            $profile = $data['platforms']['codingninjas'];
        }
        if (!empty($profile['error']) && empty($profile['totalSolved']) && empty($profile['rating'])) {
            return null;
        }
        $contests = 0;
        $rating = (float) ($profile['rating'] ?? 0);
        if (isset($profile['contests']) && is_array($profile['contests'])) {
            $rating = (float) ($profile['contests']['rating'] ?? $rating);
            $contests = (int) ($profile['contests']['attended'] ?? 0);
        } else if (isset($profile['contests']) && is_numeric($profile['contests'])) {
            $contests = (int) $profile['contests'];
        }
        return [
            'platform' => 'codingninjas',
            'username' => $uuid,
            'displayName' => (string) ($profile['profileName'] ?? $profile['displayName'] ?? ''),
            'totalSolved' => (int) ($profile['totalSolved'] ?? 0),
            'rating' => $rating,
            'rank' => (string) ($profile['rank'] ?? $profile['contests']['rank'] ?? ''),
            'contests' => $contests,
            'problemsByDifficulty' => \local_nexportfolio\local\difficulty::to_emh(
                is_array($profile['problemsByDifficulty'] ?? null) ? $profile['problemsByDifficulty'] : []
            ),
            'problemsByDifficultyRaw' => is_array($profile['problemsByDifficulty'] ?? null)
                ? $profile['problemsByDifficulty'] : [],
            'activityHeatmap' => $profile['activityHeatmap'] ?? [],
            'stats' => [
                'totalActiveDays' => (int) ($profile['stats']['totalActiveDays'] ?? 0),
                'streak' => (int) ($profile['stats']['streak'] ?? 0),
            ],
            'note' => 'Loaded via configured Coding Ninjas proxy.',
        ];
    }
}
