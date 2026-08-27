<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Language strings for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'NexPortfolio';
$string['codingportfolio'] = 'Coding Portfolio';
$string['codingportfolio_desc'] = 'Connect LeetCode, CodeChef, Codeforces, GeeksforGeeks, Coding Ninjas, and GitHub — track stats and showcase repository projects.';
$string['portfolioeyebrow'] = 'Portfolio';
$string['portfolioprogress'] = 'Your portfolio';
$string['dashboard'] = 'Dashboard';
$string['connect'] = 'Connect platforms';
$string['connecthandles'] = 'Platform usernames';
$string['connecthandles_help'] = 'Enter your public usernames. For Coding Ninjas, use the id from naukri.com/code360/profile/<id>.';
$string['savehandles'] = 'Save connections';
$string['refresh'] = 'Refresh data';
$string['refreshing'] = 'Fetching…';
$string['lastfetched'] = 'Last updated';
$string['never'] = 'Never';
$string['totalsolved'] = 'Problems solved';
$string['rating'] = 'Rating';
$string['rank'] = 'Rank';
$string['globalrank'] = 'Global';
$string['countryrank'] = 'Country';
$string['contests'] = 'Contests';
$string['streak'] = 'Current streak';
$string['currentstreak'] = 'Current streak';
$string['maxstreak'] = 'Max streak';
$string['activedays'] = 'Active days';
$string['heatmap'] = 'Activity heatmap';
$string['heatmap_hint'] = 'Combined coding platforms + GitHub contributions. Hover a square for the breakdown.';
$string['heatmap_tooltip_total'] = 'Total';
$string['heatmap_tooltip_none'] = 'No activity';
$string['heatmap_github_unit'] = 'contributions';
$string['heatmap_coding_unit'] = 'activities';
$string['platform_github'] = 'GitHub';
$string['platforms'] = 'Platforms';
$string['platformratings'] = 'Platform Ratings';
$string['problemssolved'] = 'Problems Solved';
$string['problemssolvedshort'] = 'problems solved';
$string['totalproblems'] = 'total problems';
$string['contestparticipation'] = 'Contest Participation';
$string['contestsjoined'] = 'contests joined';
$string['nocontests'] = 'No contests attended yet.';
$string['easy'] = 'Easy';
$string['medium'] = 'Medium';
$string['hard'] = 'Hard';
$string['others'] = 'Others';
$string['solved'] = 'Solved';
$string['noconnections'] = 'No platforms connected yet. Add your usernames to get started.';
$string['fetcherror'] = 'Could not fetch data for {$a}.';
$string['handlesaved'] = 'Connections saved.';
$string['platform_leetcode'] = 'LeetCode';
$string['platform_codechef'] = 'CodeChef';
$string['platform_codeforces'] = 'Codeforces';
$string['platform_geeksforgeeks'] = 'GeeksforGeeks';
$string['platform_codingninjas'] = 'Coding Ninjas (Code360)';
$string['username'] = 'Username';
$string['privacy:metadata'] = 'NexPortfolio stores platform usernames and cached performance stats for the logged-in user.';
$string['privacy:metadata:handles'] = 'Connected coding platform usernames.';
$string['privacy:metadata:handles:userid'] = 'The user ID.';
$string['privacy:metadata:handles:platform'] = 'Platform key (leetcode, codechef, …).';
$string['privacy:metadata:handles:handle'] = 'Public username on that platform.';
$string['privacy:metadata:data'] = 'Cached stats fetched from coding platforms.';
$string['nexportfolio:view'] = 'View coding portfolio';
$string['nexportfolio:manageown'] = 'Connect and refresh own coding platforms';
$string['settingsheading'] = 'NexPortfolio settings';
$string['enablemenu'] = 'Show NexPortfolio in the navigation menu';
$string['enablemenu_desc'] = 'Adds a NexPortfolio link to the top custom menu (RemUI / Moodle 4+).';
$string['leetcodeapi'] = 'LeetCode API base URL';
$string['leetcodeapi_desc'] = 'Alfa LeetCode API (or compatible) base URL without trailing slash.';
$string['cachettl'] = 'Cache TTL (minutes)';
$string['cachettl_desc'] = 'Minimum time between automatic refetch for the same platform.';
$string['codingninjasproxy'] = 'Coding Ninjas proxy URL';
$string['codingninjasproxy_desc'] = 'Optional relay when direct Code360 APIs fail. Use {username} placeholder, e.g. '
    . 'https://your-nexacademy.example/api/user/codingninjas-profile?username={username}.';
$string['refreshall'] = 'Refresh all';
$string['overview'] = 'Overview';
$string['saving'] = 'Saving…';
$string['fetchandreturn'] = 'Fetching stats and returning to dashboard…';
$string['githubheading'] = 'GitHub projects';
$string['githubheading_desc'] = 'Learners enter their GitHub username to import public repositories as portfolio projects, plus contribution totals, repo counts, and follower stats.';
$string['githubenabled'] = 'Enable GitHub import';
$string['githubenabled_desc'] = 'Show GitHub on the Connect page and imported projects on the dashboard.';
$string['githubapitoken'] = 'GitHub API token (optional)';
$string['githubapitoken_desc'] = 'Optional fine-grained or classic PAT (public read is enough). Improves GraphQL rate limits for contribution details. The activity heatmap also works without a token via GitHub’s public contribution calendar.';
$string['githubconnect'] = 'GitHub projects';
$string['githubconnect_help'] = 'Enter your public GitHub username, then import repositories. We also pull contribution totals, public repo counts, followers, and README files.';
$string['github_stats_title'] = 'GitHub';
$string['github_viewprofile'] = 'View on GitHub';
$string['github_contributions'] = 'Contributions';
$string['github_contributions_hint'] = 'Last 12 months';
$string['github_repos'] = 'Repositories';
$string['github_followers'] = 'Followers';
$string['github_following'] = 'Following';
$string['github_gists'] = 'Gists';
$string['github_stars_received'] = 'Stars';
$string['github_forks_received'] = 'Forks';
$string['github_contributed_to'] = 'Contributed to';
$string['github_commits'] = 'Commits';
$string['github_prs'] = 'Pull requests';
$string['github_issues'] = 'Issues';
$string['github_reviews'] = 'Reviews';
$string['github_joined'] = 'Joined {$a}';
$string['githubdisconnected'] = 'GitHub disconnected and imported projects removed.';
$string['githubimport'] = 'Import repositories';
$string['githubimporting'] = 'Importing repositories and README files…';
$string['githubimported'] = 'Synced {$a->total} repositories ({$a->imported} new, {$a->updated} updated).';
$string['githubrefresh'] = 'Refresh projects';
$string['githubdisconnect'] = 'Remove GitHub projects';
$string['githubstatus_public'] = 'GitHub: @{$a}';
$string['githubstatus_none'] = 'No GitHub username saved yet';
$string['githubnotconnected'] = 'Enter your GitHub username first.';
$string['githubnorepos'] = 'No public repositories found for this GitHub account.';
$string['githubdisabled'] = 'GitHub import is disabled on this site.';
$string['githubuserfailed'] = 'Could not load GitHub profile for "{$a}". Check the username.';
$string['githubusername'] = 'GitHub username';
$string['githubusername_help'] = 'Your public GitHub handle (e.g. octocat). We fetch public profile stats, repos, and each README.md — no OAuth callback needed.';
$string['githubusernamerequired'] = 'Enter your GitHub username before importing.';
$string['projects'] = 'Projects';
$string['projects_empty'] = 'No projects yet. Connect GitHub on the Connect page to import repositories.';
$string['project_stars'] = 'Stars';
$string['project_forks'] = 'Forks';
$string['project_language'] = 'Primary language';
$string['project_stack'] = 'Tech stack';
$string['project_topics'] = 'Topics';
$string['project_updated'] = 'Last push';
$string['project_fork'] = 'Fork';
$string['project_private'] = 'Private';
$string['project_no_description'] = 'No README.md found for this repository.';
$string['privacy:metadata:github'] = 'GitHub username, avatar, contribution heatmap, and public profile stats for imported projects.';
$string['privacy:metadata:projects'] = 'Imported GitHub repositories shown as portfolio projects.';
