<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * NexPortfolio dashboard.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

global $DB;

$context = context_system::instance();
require_capability('local/nexportfolio:view', $context);

$PAGE->set_url(new moodle_url('/local/nexportfolio/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexportfolio'));
local_nexportfolio_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexportfolio/styles.css', [
    'v' => (string) get_config('local_nexportfolio', 'version'),
]));

$handles = local_nexportfolio_get_handles($USER->id);
$hasany = false;
foreach ($handles as $h) {
    if (!empty($h->handle)) {
        $hasany = true;
        break;
    }
}
if (!$hasany) {
    $hasany = $DB->record_exists('local_nexportfolio_projects', ['userid' => (int) $USER->id]);
}

$canmanage = has_capability('local/nexportfolio:manageown', $context);
$connecturl = (new moodle_url('/local/nexportfolio/connect.php'))->out(false);
$dashboardurl = (new moodle_url('/local/nexportfolio/index.php'))->out(false);
$header = local_nexportfolio_header_context((int) $USER->id);

$PAGE->requires->js_call_amd('local_nexportfolio/dashboard', 'init', [[
    'canManage' => $canmanage,
    'connectUrl' => $connecturl,
    'strings' => [
        'refresh' => get_string('refresh', 'local_nexportfolio'),
        'refreshing' => get_string('refreshing', 'local_nexportfolio'),
        'never' => get_string('never', 'local_nexportfolio'),
        'lastfetched' => get_string('lastfetched', 'local_nexportfolio'),
        'noconnections' => get_string('noconnections', 'local_nexportfolio'),
        'fetcherror' => get_string('fetcherror', 'local_nexportfolio', ''),
        'totalsolved' => get_string('totalsolved', 'local_nexportfolio'),
        'rating' => get_string('rating', 'local_nexportfolio'),
        'rank' => get_string('rank', 'local_nexportfolio'),
        'globalrank' => get_string('globalrank', 'local_nexportfolio'),
        'countryrank' => get_string('countryrank', 'local_nexportfolio'),
        'contests' => get_string('contests', 'local_nexportfolio'),
        'streak' => get_string('streak', 'local_nexportfolio'),
        'currentstreak' => get_string('currentstreak', 'local_nexportfolio'),
        'maxstreak' => get_string('maxstreak', 'local_nexportfolio'),
        'activedays' => get_string('activedays', 'local_nexportfolio'),
        'heatmap' => get_string('heatmap', 'local_nexportfolio'),
        'heatmap_hint' => get_string('heatmap_hint', 'local_nexportfolio'),
        'heatmap_tooltip_total' => get_string('heatmap_tooltip_total', 'local_nexportfolio'),
        'heatmap_tooltip_none' => get_string('heatmap_tooltip_none', 'local_nexportfolio'),
        'heatmap_github_unit' => get_string('heatmap_github_unit', 'local_nexportfolio'),
        'heatmap_coding_unit' => get_string('heatmap_coding_unit', 'local_nexportfolio'),
        'heatmapPlatforms' => [
            'github' => get_string('platform_github', 'local_nexportfolio'),
            'leetcode' => get_string('platform_leetcode', 'local_nexportfolio'),
            'codechef' => get_string('platform_codechef', 'local_nexportfolio'),
            'codeforces' => get_string('platform_codeforces', 'local_nexportfolio'),
            'geeksforgeeks' => get_string('platform_geeksforgeeks', 'local_nexportfolio'),
            'codingninjas' => get_string('platform_codingninjas', 'local_nexportfolio'),
        ],
        'refreshall' => get_string('refreshall', 'local_nexportfolio'),
        'overview' => get_string('overview', 'local_nexportfolio'),
        'platformratings' => get_string('platformratings', 'local_nexportfolio'),
        'platforms' => get_string('platforms', 'local_nexportfolio'),
        'problemssolved' => get_string('problemssolved', 'local_nexportfolio'),
        'problemssolvedshort' => get_string('problemssolvedshort', 'local_nexportfolio'),
        'totalproblems' => get_string('totalproblems', 'local_nexportfolio'),
        'contestparticipation' => get_string('contestparticipation', 'local_nexportfolio'),
        'contestsjoined' => get_string('contestsjoined', 'local_nexportfolio'),
        'nocontests' => get_string('nocontests', 'local_nexportfolio'),
        'easy' => get_string('easy', 'local_nexportfolio'),
        'medium' => get_string('medium', 'local_nexportfolio'),
        'hard' => get_string('hard', 'local_nexportfolio'),
        'others' => get_string('others', 'local_nexportfolio'),
        'solved' => get_string('solved', 'local_nexportfolio'),
        'projects' => get_string('projects', 'local_nexportfolio'),
        'projects_empty' => get_string('projects_empty', 'local_nexportfolio'),
        'project_stars' => get_string('project_stars', 'local_nexportfolio'),
        'project_forks' => get_string('project_forks', 'local_nexportfolio'),
        'project_stack' => get_string('project_stack', 'local_nexportfolio'),
        'project_topics' => get_string('project_topics', 'local_nexportfolio'),
        'project_updated' => get_string('project_updated', 'local_nexportfolio'),
        'project_fork' => get_string('project_fork', 'local_nexportfolio'),
        'project_private' => get_string('project_private', 'local_nexportfolio'),
        'project_no_description' => get_string('project_no_description', 'local_nexportfolio'),
        'github_stats_title' => get_string('github_stats_title', 'local_nexportfolio'),
        'github_viewprofile' => get_string('github_viewprofile', 'local_nexportfolio'),
        'github_contributions' => get_string('github_contributions', 'local_nexportfolio'),
        'github_contributions_hint' => get_string('github_contributions_hint', 'local_nexportfolio'),
        'github_repos' => get_string('github_repos', 'local_nexportfolio'),
        'github_followers' => get_string('github_followers', 'local_nexportfolio'),
        'github_following' => get_string('github_following', 'local_nexportfolio'),
        'github_gists' => get_string('github_gists', 'local_nexportfolio'),
        'github_stars_received' => get_string('github_stars_received', 'local_nexportfolio'),
        'github_forks_received' => get_string('github_forks_received', 'local_nexportfolio'),
        'github_contributed_to' => get_string('github_contributed_to', 'local_nexportfolio'),
        'github_commits' => get_string('github_commits', 'local_nexportfolio'),
        'github_prs' => get_string('github_prs', 'local_nexportfolio'),
        'github_issues' => get_string('github_issues', 'local_nexportfolio'),
        'github_reviews' => get_string('github_reviews', 'local_nexportfolio'),
        'github_joined' => get_string('github_joined', 'local_nexportfolio'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexportfolio/dashboard', array_merge($header, [
    'connecturl' => $connecturl,
    'dashboardurl' => $dashboardurl,
    'canmanage' => $canmanage,
    'hasconnections' => $hasany,
    'connectlabel' => get_string('connect', 'local_nexportfolio'),
    'noconnections' => get_string('noconnections', 'local_nexportfolio'),
]));
echo $OUTPUT->footer();
