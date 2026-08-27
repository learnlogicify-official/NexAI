<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Refresh one platform.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexportfolio\local\fetcher;

/**
 * Refresh platform AJAX.
 */
class refresh_platform extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'platform' => new external_value(PARAM_ALPHANUMEXT, 'Platform key'),
            'force' => new external_value(PARAM_BOOL, 'Ignore cache TTL', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success without fetch error'),
            'platform' => new external_value(PARAM_ALPHANUMEXT, 'Platform'),
            'totalsolved' => new external_value(PARAM_INT, 'Solved'),
            'rating' => new external_value(PARAM_FLOAT, 'Rating'),
            'ranktext' => new external_value(PARAM_TEXT, 'Rank'),
            'contests' => new external_value(PARAM_INT, 'Contests'),
            'streak' => new external_value(PARAM_INT, 'Streak'),
            'activedays' => new external_value(PARAM_INT, 'Active days'),
            'lastfetch' => new external_value(PARAM_INT, 'Unix time'),
            'lasterror' => new external_value(PARAM_RAW, 'Error if any'),
            'heatmap' => new external_value(PARAM_RAW, 'JSON heatmap'),
            'datajson' => new external_value(PARAM_RAW, 'Full JSON'),
        ]);
    }

    /**
     * @param string $platform
     * @param bool $force
     * @return array
     */
    public static function execute(string $platform, bool $force = true): array {
        global $DB, $USER;

        self::validate_parameters(self::execute_parameters(), [
            'platform' => $platform,
            'force' => $force,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:manageown', $context);

        $platform = strtolower(trim($platform));
        $handle = $DB->get_record('local_nexportfolio_handles', [
            'userid' => $USER->id,
            'platform' => $platform,
        ]);
        if (!$handle || trim($handle->handle) === '') {
            throw new \moodle_exception('error', 'error', '', 'No handle saved for ' . $platform);
        }

        $row = fetcher::refresh($USER->id, $platform, $handle->handle, $force);
        $profile = json_decode($row->datajson ?? '{}', true) ?: [];
        $heatmap = isset($profile['activityHeatmap']) ? json_encode($profile['activityHeatmap']) : '[]';

        return [
            'ok' => empty($row->lasterror),
            'platform' => $platform,
            'totalsolved' => (int) $row->totalsolved,
            'rating' => (float) $row->rating,
            'ranktext' => (string) ($row->ranktext ?? ''),
            'contests' => (int) $row->contests,
            'streak' => (int) $row->streak,
            'activedays' => (int) $row->activedays,
            'lastfetch' => (int) $row->lastfetch,
            'lasterror' => (string) ($row->lasterror ?? ''),
            'heatmap' => $heatmap,
            'datajson' => (string) ($row->datajson ?? '{}'),
        ];
    }
}
