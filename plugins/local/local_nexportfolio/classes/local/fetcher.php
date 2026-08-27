<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Fetch + cache orchestrator (ported from NexAcademy nexPortfolio).
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local;

defined('MOODLE_INTERNAL') || die();

use local_nexportfolio\local\fetchers\codechef;
use local_nexportfolio\local\fetchers\codeforces;
use local_nexportfolio\local\fetchers\codingninjas;
use local_nexportfolio\local\fetchers\geeksforgeeks;
use local_nexportfolio\local\fetchers\leetcode;

/**
 * Platform fetch orchestrator.
 */
class fetcher {

    /**
     * @param string $platform
     * @param string $handle
     * @return array Standardized profile
     */
    public static function fetch(string $platform, string $handle): array {
        $platform = strtolower(trim($platform));
        // Aliases used in NexAcademy / older handles.
        if ($platform === 'gfg') {
            $platform = 'geeksforgeeks';
        } else if ($platform === 'code360' || $platform === 'codestudio') {
            $platform = 'codingninjas';
        }
        $handle = trim($handle);
        if ($handle === '') {
            throw new \moodle_exception('invalidusername', 'error');
        }

        switch ($platform) {
            case 'leetcode':
                return leetcode::fetch($handle);
            case 'codeforces':
                return codeforces::fetch($handle);
            case 'codechef':
                return codechef::fetch($handle);
            case 'geeksforgeeks':
                return geeksforgeeks::fetch($handle);
            case 'codingninjas':
                return codingninjas::fetch($handle);
            default:
                throw new \moodle_exception('error', 'error', '', 'Unsupported platform: ' . $platform);
        }
    }

    /**
     * Fetch and upsert cached row for a user/platform.
     *
     * @param int $userid
     * @param string $platform
     * @param string $handle
     * @param bool $force Ignore TTL
     * @return \stdClass Cached data row
     */
    public static function refresh(int $userid, string $platform, string $handle, bool $force = false): \stdClass {
        global $DB;

        $platform = strtolower(trim($platform));
        if ($platform === 'gfg') {
            $platform = 'geeksforgeeks';
        } else if ($platform === 'code360' || $platform === 'codestudio') {
            $platform = 'codingninjas';
        }
        $now = time();
        $ttlmin = (int) get_config('local_nexportfolio', 'cachettl');
        if ($ttlmin <= 0) {
            $ttlmin = 60;
        }

        $existing = $DB->get_record('local_nexportfolio_data', [
            'userid' => $userid,
            'platform' => $platform,
        ]);

        if (!$force && $existing && ($now - (int) $existing->lastfetch) < ($ttlmin * 60)
            && empty($existing->lasterror)) {
            return $existing;
        }

        try {
            $profile = self::fetch($platform, $handle);
            $record = $existing ?: (object) [
                'userid' => $userid,
                'platform' => $platform,
                'timecreated' => $now,
            ];
            $record->totalsolved = (int) ($profile['totalSolved'] ?? 0);
            $record->rating = (float) ($profile['rating'] ?? 0);
            $record->ranktext = isset($profile['rank']) ? (string) $profile['rank'] : null;
            $record->contests = (int) ($profile['contests'] ?? 0);
            // Persist current streak in the streak column; max streak lives in datajson.stats.
            $record->streak = (int) ($profile['stats']['currentStreak'] ?? $profile['stats']['streak'] ?? 0);
            $record->activedays = (int) ($profile['stats']['activeDaysYear']
                ?? $profile['stats']['totalActiveDays'] ?? 0);
            $record->datajson = json_encode($profile);
            $record->lasterror = null;
            $record->lastfetch = $now;
            $record->timemodified = $now;

            if (!empty($existing->id)) {
                $record->id = $existing->id;
                $DB->update_record('local_nexportfolio_data', $record);
            } else {
                $record->id = $DB->insert_record('local_nexportfolio_data', $record);
            }
            return $record;
        } catch (\Throwable $e) {
            $record = $existing ?: (object) [
                'userid' => $userid,
                'platform' => $platform,
                'timecreated' => $now,
                'totalsolved' => 0,
                'rating' => 0,
                'contests' => 0,
                'streak' => 0,
                'activedays' => 0,
            ];
            $record->lasterror = $e->getMessage();
            $record->lastfetch = $now;
            $record->timemodified = $now;
            if (!empty($existing->id)) {
                $record->id = $existing->id;
                $DB->update_record('local_nexportfolio_data', $record);
            } else {
                $record->id = $DB->insert_record('local_nexportfolio_data', $record);
            }
            return $record;
        }
    }
}
