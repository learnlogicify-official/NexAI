<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Get portfolio snapshot.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexportfolio\local\github;
use local_nexportfolio\local\projects;

/**
 * Get portfolio AJAX.
 */
class get_portfolio extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'platforms' => new external_multiple_structure(
                new external_single_structure([
                    'platform' => new external_value(PARAM_ALPHANUMEXT, 'Key'),
                    'label' => new external_value(PARAM_TEXT, 'Label'),
                    'handle' => new external_value(PARAM_TEXT, 'Handle'),
                    'connected' => new external_value(PARAM_BOOL, 'Has handle'),
                    'totalsolved' => new external_value(PARAM_INT, 'Solved'),
                    'rating' => new external_value(PARAM_FLOAT, 'Rating'),
                    'ranktext' => new external_value(PARAM_TEXT, 'Rank'),
                    'contests' => new external_value(PARAM_INT, 'Contests'),
                    'streak' => new external_value(PARAM_INT, 'Current streak'),
                    'maxstreak' => new external_value(PARAM_INT, 'Max streak'),
                    'activedays' => new external_value(PARAM_INT, 'Active days (current year)'),
                    'lastfetch' => new external_value(PARAM_INT, 'Last fetch'),
                    'lasterror' => new external_value(PARAM_RAW, 'Error'),
                    'heatmap' => new external_value(PARAM_RAW, 'JSON heatmap'),
                    'datajson' => new external_value(PARAM_RAW, 'Full cached profile JSON'),
                    'note' => new external_value(PARAM_RAW, 'Status note'),
                    'needsimport' => new external_value(PARAM_BOOL, 'Needs browser HTML import'),
                ])
            ),
            'totalsolved' => new external_value(PARAM_INT, 'Sum solved'),
            'totalcontests' => new external_value(PARAM_INT, 'Sum contests'),
            'currentstreak' => new external_value(PARAM_INT, 'Best current streak across platforms'),
            'maxstreak' => new external_value(PARAM_INT, 'Best max streak across platforms'),
            'projectcount' => new external_value(PARAM_INT, 'Imported projects count'),
            'mergedheatmap' => new external_value(PARAM_RAW, 'Merged heatmap JSON'),
            'github' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                'connected' => new external_value(PARAM_BOOL, 'OAuth connected'),
                'login' => new external_value(PARAM_TEXT, 'Login'),
                'avatarurl' => new external_value(PARAM_URL, 'Avatar', VALUE_DEFAULT, ''),
                'profileurl' => new external_value(PARAM_URL, 'GitHub profile URL', VALUE_DEFAULT, ''),
                'projectcount' => new external_value(PARAM_INT, 'Project count'),
                'stats' => new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Display name', VALUE_DEFAULT, ''),
                    'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_DEFAULT, ''),
                    'company' => new external_value(PARAM_TEXT, 'Company', VALUE_DEFAULT, ''),
                    'location' => new external_value(PARAM_TEXT, 'Location', VALUE_DEFAULT, ''),
                    'profileurl' => new external_value(PARAM_URL, 'Profile URL', VALUE_DEFAULT, ''),
                    'createdat' => new external_value(PARAM_INT, 'Account created unix time'),
                    'contributionsyear' => new external_value(PARAM_INT, 'Contributions in the last year'),
                    'commitsyear' => new external_value(PARAM_INT, 'Commits in the last year'),
                    'issuesyear' => new external_value(PARAM_INT, 'Issue contributions in the last year'),
                    'prsyear' => new external_value(PARAM_INT, 'Pull requests in the last year'),
                    'reviewsyear' => new external_value(PARAM_INT, 'Reviews in the last year'),
                    'publicrepos' => new external_value(PARAM_INT, 'Public repositories'),
                    'contributedto' => new external_value(PARAM_INT, 'Repositories contributed to'),
                    'followers' => new external_value(PARAM_INT, 'Followers'),
                    'following' => new external_value(PARAM_INT, 'Following'),
                    'gists' => new external_value(PARAM_INT, 'Public gists'),
                    'starsreceived' => new external_value(PARAM_INT, 'Stars on owned repos'),
                    'forksreceived' => new external_value(PARAM_INT, 'Forks of owned repos'),
                    'hasgraphql' => new external_value(PARAM_BOOL, 'GraphQL totals available'),
                ]),
            ]),
            'projects' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Id'),
                    'source' => new external_value(PARAM_ALPHANUMEXT, 'Source'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'url' => new external_value(PARAM_URL, 'URL'),
                    'homepage' => new external_value(PARAM_URL, 'Homepage', VALUE_DEFAULT, ''),
                    'description' => new external_value(PARAM_RAW, 'Description from README excerpt'),
                    'readme' => new external_value(PARAM_RAW, 'Full README plain text', VALUE_DEFAULT, ''),
                    'has_readme' => new external_value(PARAM_BOOL, 'Has README'),
                    'primary_language' => new external_value(PARAM_TEXT, 'Language'),
                    'stars' => new external_value(PARAM_INT, 'Stars'),
                    'forks' => new external_value(PARAM_INT, 'Forks'),
                    'topics' => new external_value(PARAM_RAW, 'Topics JSON'),
                    'languages' => new external_value(PARAM_RAW, 'Languages JSON'),
                    'visibility' => new external_value(PARAM_ALPHANUMEXT, 'Visibility'),
                    'is_fork' => new external_value(PARAM_BOOL, 'Fork'),
                    'lastpush' => new external_value(PARAM_INT, 'Last push'),
                ])
            ),
        ]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/local/nexportfolio/lib.php');

        self::validate_parameters(self::execute_parameters(), []);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:view', $context);

        $handles = local_nexportfolio_get_handles($USER->id);
        $cached = local_nexportfolio_get_cached_data($USER->id);
        $platforms = [];
        $totalsolved = 0;
        $totalcontests = 0;
        $currentstreak = 0;
        $maxstreak = 0;
        $merged = [];

        $mergeheat = static function (array &$bucket, string $date, string $platform, int $count) {
            if ($date === '' || $count <= 0) {
                return;
            }
            if (!isset($bucket[$date])) {
                $bucket[$date] = ['total' => 0];
            }
            $bucket[$date][$platform] = ($bucket[$date][$platform] ?? 0) + $count;
            $bucket[$date]['total'] += $count;
        };

        foreach (local_nexportfolio_platforms() as $key => $str) {
            $h = $handles[$key] ?? null;
            $d = $cached[$key] ?? null;
            $heatmap = [];
            $note = '';
            $needsimport = false;
            $profile = [];
            $platmax = 0;
            $platcurrent = 0;
            if ($d && !empty($d->datajson)) {
                $profile = json_decode($d->datajson, true) ?: [];
                $heatmap = $profile['activityHeatmap'] ?? [];
                $note = (string) ($profile['note'] ?? '');
                $needsimport = !empty($profile['needsBrowserImport']) || !empty($profile['blocked']);
                $stats = is_array($profile['stats'] ?? null) ? $profile['stats'] : [];
                $platcurrent = (int) ($stats['currentStreak'] ?? $stats['streak'] ?? ($d->streak ?? 0));
                $platmax = (int) ($stats['maxStreak'] ?? 0);
                foreach ($heatmap as $pt) {
                    $date = $pt['date'] ?? '';
                    if ($date === '') {
                        continue;
                    }
                    $mergeheat($merged, $date, $key, (int) ($pt['count'] ?? 0));
                }
            }
            $solved = $d ? (int) $d->totalsolved : 0;
            $contests = $d ? (int) $d->contests : 0;
            $streak = $d ? (int) $d->streak : 0;
            if ($platcurrent === 0) {
                $platcurrent = $streak;
            }
            if ($platmax === 0) {
                $platmax = $platcurrent;
            }
            $totalsolved += $solved;
            $totalcontests += $contests;
            $currentstreak = max($currentstreak, $platcurrent);
            $maxstreak = max($maxstreak, $platmax);

            $platforms[] = [
                'platform' => $key,
                'label' => get_string($str, 'local_nexportfolio'),
                'handle' => $h ? (string) $h->handle : '',
                'connected' => !empty($h->handle),
                'totalsolved' => $solved,
                'rating' => $d ? (float) $d->rating : 0.0,
                'ranktext' => $d ? (string) ($d->ranktext ?? '') : '',
                'contests' => $contests,
                'streak' => $platcurrent,
                'maxstreak' => $platmax,
                'activedays' => $d ? (int) $d->activedays : 0,
                'lastfetch' => $d ? (int) $d->lastfetch : 0,
                'lasterror' => $d ? (string) ($d->lasterror ?? '') : '',
                'heatmap' => json_encode($heatmap),
                'datajson' => $d ? (string) ($d->datajson ?? '{}') : '{}',
                'note' => $note,
                'needsimport' => $needsimport,
            ];
        }

        $mergedlist = [];
        foreach ($merged as $date => $row) {
            $total = (int) ($row['total'] ?? 0);
            $breakdown = $row;
            unset($breakdown['total']);
            $mergedlist[] = [
                'date' => $date,
                'count' => $total,
                'breakdown' => $breakdown,
            ];
        }
        usort($mergedlist, static function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $profile = github::get_profile((int) $USER->id);
        $ghlogin = $profile ? (string) $profile->github_login : '';
        if ($ghlogin === '' && !empty($handles['github']->handle)) {
            $ghlogin = (string) $handles['github']->handle;
        }

        if ($ghlogin !== '' && github::enabled()) {
            $ghpoints = github::heatmap_for_user((int) $USER->id, $ghlogin);
            $profile = github::get_profile((int) $USER->id) ?: $profile;
            foreach ($ghpoints as $pt) {
                $mergeheat($merged, (string) ($pt['date'] ?? ''), 'github', (int) ($pt['count'] ?? 0));
            }
            // Rebuild merged list after GitHub merge.
            $mergedlist = [];
            foreach ($merged as $date => $row) {
                $total = (int) ($row['total'] ?? 0);
                $breakdown = $row;
                unset($breakdown['total']);
                $mergedlist[] = [
                    'date' => $date,
                    'count' => $total,
                    'breakdown' => $breakdown,
                ];
            }
            usort($mergedlist, static function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });
        }

        $projectrows = [];
        foreach (projects::get_for_user((int) $USER->id) as $row) {
            $p = projects::export_row($row);
            $projectrows[] = [
                'id' => $p['id'],
                'source' => $p['source'],
                'fullname' => $p['fullname'],
                'url' => $p['url'],
                'homepage' => $p['homepage'],
                'description' => $p['description'],
                'readme' => $p['readme'],
                'has_readme' => $p['has_readme'],
                'primary_language' => $p['primary_language'],
                'stars' => $p['stars'],
                'forks' => $p['forks'],
                'topics' => json_encode($p['topics']),
                'languages' => json_encode($p['languages']),
                'visibility' => $p['visibility'],
                'is_fork' => $p['is_fork'],
                'lastpush' => $p['lastpush'],
            ];
        }

        $ghstats = github::export_stats($profile);
        $ghprofileurl = (string) ($ghstats['profileurl'] ?? '');
        if ($ghprofileurl === '' && $ghlogin !== '') {
            $ghprofileurl = 'https://github.com/' . $ghlogin;
        }

        return [
            'platforms' => $platforms,
            'totalsolved' => $totalsolved,
            'totalcontests' => $totalcontests,
            'currentstreak' => $currentstreak,
            'maxstreak' => $maxstreak,
            'projectcount' => count($projectrows),
            'mergedheatmap' => json_encode($mergedlist),
            'github' => [
                'enabled' => github::enabled(),
                'connected' => $ghlogin !== '',
                'login' => $ghlogin,
                'avatarurl' => $profile ? (string) ($profile->avatar_url ?? '') : '',
                'profileurl' => $ghprofileurl,
                'projectcount' => count($projectrows),
                'stats' => $ghstats,
            ],
            'projects' => $projectrows,
        ];
    }
}
