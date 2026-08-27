<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Codeforces fetcher (official API) — from NexAcademy nexPortfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local\fetchers;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\http;

/**
 * Codeforces.
 */
class codeforces {

    /**
     * @param string $username
     * @return array
     */
    public static function fetch(string $username): array {
        $info = http::get_json('https://codeforces.com/api/user.info?handles=' . rawurlencode($username));
        if (!$info || ($info['status'] ?? '') !== 'OK' || empty($info['result'][0])) {
            throw new \moodle_exception('error', 'error', '', 'Codeforces user not found');
        }
        $user = $info['result'][0];

        // Paginate submissions so difficulty buckets cover more than the first page.
        $submissions = [];
        $from = 1;
        $pagesize = 5000;
        for ($page = 0; $page < 4; $page++) {
            $status = http::get_json(
                'https://codeforces.com/api/user.status?handle=' . rawurlencode($username)
                    . '&from=' . $from . '&count=' . $pagesize,
                [],
                35
            );
            $chunk = ($status && ($status['status'] ?? '') === 'OK') ? ($status['result'] ?? []) : [];
            if (!$chunk) {
                break;
            }
            foreach ($chunk as $row) {
                $submissions[] = $row;
            }
            if (count($chunk) < $pagesize) {
                break;
            }
            $from += $pagesize;
        }

        $solved = []; // key => rating|null
        $activity = [];
        foreach ($submissions as $sub) {
            if (($sub['verdict'] ?? '') === 'OK' && !empty($sub['problem'])) {
                $key = ($sub['problem']['contestId'] ?? 'x') . '-' . ($sub['problem']['index'] ?? '');
                if (!array_key_exists($key, $solved)) {
                    $solved[$key] = isset($sub['problem']['rating']) ? (float) $sub['problem']['rating'] : null;
                }
            }
            if (!empty($sub['creationTimeSeconds'])) {
                $date = gmdate('Y-m-d', (int) $sub['creationTimeSeconds']);
                $activity[$date] = ($activity[$date] ?? 0) + 1;
            }
        }

        $bydiff = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        foreach ($solved as $probrating) {
            if ($probrating === null) {
                continue;
            }
            $bucket = \local_nexportfolio\local\difficulty::cf_rating_bucket((float) $probrating);
            $bydiff[$bucket]++;
        }

        $ratinghist = http::get_json(
            'https://codeforces.com/api/user.rating?handle=' . rawurlencode($username)
        );
        $contests = ($ratinghist && ($ratinghist['status'] ?? '') === 'OK') ? ($ratinghist['result'] ?? []) : [];
        $contestHistory = [];
        foreach (array_reverse($contests) as $c) {
            $contestHistory[] = [
                'name' => $c['contestName'] ?? 'Contest',
                'date' => !empty($c['ratingUpdateTimeSeconds'])
                    ? gmdate('Y-m-d', (int) $c['ratingUpdateTimeSeconds']) : '',
                'rank' => (int) ($c['rank'] ?? 0),
                'rating' => (int) ($c['newRating'] ?? 0),
            ];
        }

        $oneyear = gmdate('Y-m-d', time() - 365 * DAYSECS);
        $heatmap = [];
        foreach ($activity as $date => $count) {
            if ($date >= $oneyear) {
                $heatmap[] = ['date' => $date, 'count' => (int) $count];
            }
        }
        usort($heatmap, static function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return [
            'platform' => 'codeforces',
            'username' => $username,
            'totalSolved' => count($solved),
            'rating' => (float) ($user['rating'] ?? 0),
            'rank' => $user['rank'] ?? '',
            'contests' => count($contestHistory),
            'contestHistory' => $contestHistory,
            'problemsByDifficulty' => $bydiff,
            'activityHeatmap' => $heatmap,
            'stats' => [
                'maxRating' => (float) ($user['maxRating'] ?? 0),
                'totalActiveDays' => count($heatmap),
                'streak' => self::calc_streak($heatmap),
            ],
        ];
    }

    /**
     * @param array $heatmap
     * @return int
     */
    private static function calc_streak(array $heatmap): int {
        if (!$heatmap) {
            return 0;
        }
        $bydate = [];
        foreach ($heatmap as $row) {
            if (($row['count'] ?? 0) > 0) {
                $bydate[$row['date']] = true;
            }
        }
        $streak = 0;
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        // Allow streak to start from yesterday if nothing today.
        if (empty($bydate[$day->format('Y-m-d')])) {
            $day = $day->modify('-1 day');
        }
        while (!empty($bydate[$day->format('Y-m-d')])) {
            $streak++;
            $day = $day->modify('-1 day');
        }
        return $streak;
    }
}
