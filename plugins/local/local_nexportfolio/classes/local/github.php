<?php
// This file is part of Moodle - http://moodle.org/
/**
 * GitHub REST helpers for NexPortfolio (username connect — no OAuth callback).
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local;

defined('MOODLE_INTERNAL') || die();

/**
 * GitHub public API client.
 */
class github {

    public const API = 'https://api.github.com';

    /**
     * @return bool
     */
    public static function enabled(): bool {
        return (string) get_config('local_nexportfolio', 'githubenabled') !== '0';
    }

    /**
     * Optional site-wide PAT for higher rate limits (no OAuth callback needed).
     *
     * @return string
     */
    public static function api_token(): string {
        return trim((string) get_config('local_nexportfolio', 'githubapitoken'));
    }

    /**
     * @param bool $raw
     * @return string[]
     */
    public static function api_headers(bool $raw = false): array {
        $headers = [
            'Accept: ' . ($raw ? 'application/vnd.github.raw' : 'application/vnd.github+json'),
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: NexPortfolio-Moodle',
        ];
        $token = self::api_token();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * @param int $userid
     * @return \stdClass|null Cached GitHub profile row (login + avatar).
     */
    public static function get_profile(int $userid): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]);
        return $row ?: null;
    }

    /**
     * @param int $userid
     * @param array $user GitHub /users/{login} payload.
     * @return \stdClass
     */
    public static function save_profile(int $userid, array $user): \stdClass {
        global $DB;

        $login = trim((string) ($user['login'] ?? ''));
        if ($login === '') {
            throw new \moodle_exception('githubuserfailed', 'local_nexportfolio');
        }

        $now = time();
        $record = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]);
        if (!$record) {
            $record = (object) [
                'userid' => $userid,
                'timecreated' => $now,
                'access_token' => '',
            ];
        }
        $record->github_user_id = (int) ($user['id'] ?? 0);
        $record->github_login = \core_text::substr($login, 0, 100);
        $record->avatar_url = \core_text::substr((string) ($user['avatar_url'] ?? ''), 0, 255);
        $record->scope = 'public';
        $record->timemodified = $now;
        if (empty($record->heatmap_json)) {
            $record->heatmap_json = null;
            $record->heatmap_fetch = 0;
        }

        if (!empty($record->id)) {
            $DB->update_record('local_nexportfolio_github', $record);
        } else {
            $record->id = $DB->insert_record('local_nexportfolio_github', $record);
        }

        self::upsert_github_handle($userid, $login);
        return $record;
    }

    /**
     * @param int $userid
     * @param array<int, array{date:string, count:int}> $points
     */
    public static function save_heatmap(int $userid, array $points): void {
        global $DB;

        $record = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]);
        if (!$record) {
            return;
        }
        $record->heatmap_json = json_encode(array_values($points));
        $record->heatmap_fetch = time();
        $record->timemodified = time();
        $DB->update_record('local_nexportfolio_github', $record);
    }

    /**
     * Persist public GitHub profile stats (repos, followers, contributions).
     *
     * @param int $userid
     * @param array $stats
     */
    public static function save_stats(int $userid, array $stats): void {
        global $DB;

        $record = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]);
        if (!$record) {
            return;
        }

        $clean = self::empty_stats();
        foreach ($clean as $key => $default) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            if (is_bool($default)) {
                $clean[$key] = !empty($stats[$key]);
            } else if (is_int($default)) {
                $clean[$key] = (int) $stats[$key];
            } else {
                $limit = $key === 'bio' ? 1000 : 255;
                $clean[$key] = \core_text::substr((string) $stats[$key], 0, $limit);
            }
        }

        $record->stats_json = json_encode($clean);
        $record->stats_fetch = time();
        $record->timemodified = time();
        $DB->update_record('local_nexportfolio_github', $record);
    }

    /**
     * @return array
     */
    public static function empty_stats(): array {
        return [
            'name' => '',
            'bio' => '',
            'company' => '',
            'location' => '',
            'profileurl' => '',
            'createdat' => 0,
            'contributionsyear' => 0,
            'commitsyear' => 0,
            'issuesyear' => 0,
            'prsyear' => 0,
            'reviewsyear' => 0,
            'publicrepos' => 0,
            'contributedto' => 0,
            'followers' => 0,
            'following' => 0,
            'gists' => 0,
            'starsreceived' => 0,
            'forksreceived' => 0,
            'hasgraphql' => false,
        ];
    }

    /**
     * @param \stdClass|null $record
     * @return array
     */
    public static function export_stats(?\stdClass $record): array {
        $out = self::empty_stats();
        if (!$record || empty($record->stats_json)) {
            return $out;
        }
        $decoded = json_decode((string) $record->stats_json, true);
        if (!is_array($decoded)) {
            return $out;
        }
        foreach ($out as $key => $default) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            if (is_bool($default)) {
                $out[$key] = !empty($decoded[$key]);
            } else if (is_int($default)) {
                $out[$key] = (int) $decoded[$key];
            } else {
                $out[$key] = (string) $decoded[$key];
            }
        }
        return $out;
    }

    /**
     * Merge REST profile, GraphQL totals, and repo rollups into a stats payload.
     *
     * @param array $restuser GitHub REST /users/{login} payload
     * @param array $gqluser GraphQL user payload
     * @param array $repos REST repo list
     * @param array $previous Previously stored stats
     * @return array
     */
    public static function build_stats(
        array $restuser,
        array $gqluser = [],
        array $repos = [],
        array $previous = []
    ): array {
        $out = self::empty_stats();
        if ($previous) {
            foreach ($out as $key => $default) {
                if (array_key_exists($key, $previous)) {
                    $out[$key] = $previous[$key];
                }
            }
        }

        $login = trim((string) ($restuser['login'] ?? $gqluser['login'] ?? ''));
        $name = trim((string) ($restuser['name'] ?? $gqluser['name'] ?? ''));
        if ($name !== '') {
            $out['name'] = $name;
        }
        $bio = trim((string) ($restuser['bio'] ?? $gqluser['bio'] ?? ''));
        if ($bio !== '') {
            $out['bio'] = $bio;
        }
        $company = trim((string) ($restuser['company'] ?? $gqluser['company'] ?? ''));
        if ($company !== '') {
            $out['company'] = $company;
        }
        $location = trim((string) ($restuser['location'] ?? $gqluser['location'] ?? ''));
        if ($location !== '') {
            $out['location'] = $location;
        }

        $profileurl = trim((string) ($restuser['html_url'] ?? $gqluser['url'] ?? ''));
        if ($profileurl === '' && $login !== '') {
            $profileurl = 'https://github.com/' . $login;
        }
        if ($profileurl !== '') {
            $out['profileurl'] = $profileurl;
        }

        $created = self::parse_time($restuser['created_at'] ?? ($gqluser['createdAt'] ?? 0));
        if ($created > 0) {
            $out['createdat'] = $created;
        }

        if (array_key_exists('public_repos', $restuser)) {
            $out['publicrepos'] = (int) $restuser['public_repos'];
        } else if (self::count_of($gqluser['repositories'] ?? null) > 0) {
            $out['publicrepos'] = self::count_of($gqluser['repositories']);
        }
        if (array_key_exists('followers', $restuser) && !is_array($restuser['followers'])) {
            $out['followers'] = (int) $restuser['followers'];
        } else if (self::count_of($gqluser['followers'] ?? null) > 0 || !empty($gqluser)) {
            $out['followers'] = self::count_of($gqluser['followers'] ?? null);
        }
        if (array_key_exists('following', $restuser) && !is_array($restuser['following'])) {
            $out['following'] = (int) $restuser['following'];
        } else if (self::count_of($gqluser['following'] ?? null) > 0 || !empty($gqluser)) {
            $out['following'] = self::count_of($gqluser['following'] ?? null);
        }
        if (array_key_exists('public_gists', $restuser)) {
            $out['gists'] = (int) $restuser['public_gists'];
        } else if (!empty($gqluser)) {
            $out['gists'] = self::count_of($gqluser['gists'] ?? null);
        }

        if ($gqluser) {
            $out['hasgraphql'] = true;
            $out['contributedto'] = self::count_of($gqluser['repositoriesContributedTo'] ?? null);
            $collection = is_array($gqluser['contributionsCollection'] ?? null)
                ? $gqluser['contributionsCollection']
                : [];
            $calendar = is_array($collection['contributionCalendar'] ?? null)
                ? $collection['contributionCalendar']
                : [];
            $out['contributionsyear'] = (int) ($calendar['totalContributions'] ?? 0);
            $out['commitsyear'] = (int) ($collection['totalCommitContributions'] ?? 0);
            $out['issuesyear'] = (int) ($collection['totalIssueContributions'] ?? 0);
            $out['prsyear'] = (int) ($collection['totalPullRequestContributions'] ?? 0);
            $out['reviewsyear'] = (int) ($collection['totalPullRequestReviewContributions'] ?? 0);
        }

        if ($repos) {
            $stars = 0;
            $forks = 0;
            foreach ($repos as $repo) {
                if (!is_array($repo)) {
                    continue;
                }
                $stars += (int) ($repo['stargazers_count'] ?? 0);
                $forks += (int) ($repo['forks_count'] ?? 0);
            }
            $out['starsreceived'] = $stars;
            $out['forksreceived'] = $forks;
            if ($out['publicrepos'] === 0) {
                $out['publicrepos'] = count($repos);
            }
        }

        return $out;
    }

    /**
     * GitHub contribution calendar + profile totals for the last ~year.
     * Tries GraphQL first (needs token / rate limit), then public HTML calendar.
     *
     * @param string $username
     * @return array{points: array<int, array{date:string, count:int}>, user: array}
     */
    public static function fetch_contribution_data(string $username): array {
        $empty = ['points' => [], 'user' => []];
        $username = trim($username);
        if ($username === '') {
            return $empty;
        }

        $from = gmdate('Y-m-d\TH:i:s\Z', strtotime('-370 days'));
        $to = gmdate('Y-m-d\TH:i:s\Z');
        $query = <<<'GQL'
query($login: String!, $from: DateTime!, $to: DateTime!) {
  user(login: $login) {
    login
    name
    bio
    company
    location
    url
    createdAt
    followers { totalCount }
    following { totalCount }
    gists { totalCount }
    repositories(ownerAffiliations: OWNER) { totalCount }
    repositoriesContributedTo(
      contributionTypes: [COMMIT, ISSUE, PULL_REQUEST, REPOSITORY]
      includeUserRepositories: false
    ) { totalCount }
    contributionsCollection(from: $from, to: $to) {
      totalCommitContributions
      totalIssueContributions
      totalPullRequestContributions
      totalPullRequestReviewContributions
      contributionCalendar {
        totalContributions
        weeks {
          contributionDays {
            contributionCount
            date
          }
        }
      }
    }
  }
}
GQL;

        $headers = self::api_headers(false);
        $data = http::post_body_json(self::API . '/graphql', [
            'query' => $query,
            'variables' => [
                'login' => $username,
                'from' => $from,
                'to' => $to,
            ],
        ], $headers, 30);

        $user = is_array($data['data']['user'] ?? null) ? $data['data']['user'] : [];
        $out = [];
        if ($user) {
            $weeks = $user['contributionsCollection']['contributionCalendar']['weeks'] ?? [];
            if (is_array($weeks)) {
                foreach ($weeks as $week) {
                    if (!is_array($week['contributionDays'] ?? null)) {
                        continue;
                    }
                    foreach ($week['contributionDays'] as $day) {
                        if (!is_array($day) || empty($day['date'])) {
                            continue;
                        }
                        $count = (int) ($day['contributionCount'] ?? 0);
                        if ($count <= 0) {
                            continue;
                        }
                        $date = substr((string) $day['date'], 0, 10);
                        $out[] = ['date' => $date, 'count' => $count];
                    }
                }
                usort($out, static function (array $a, array $b): int {
                    return strcmp($a['date'], $b['date']);
                });
            }
        }

        $gqltotal = (int) ($user['contributionsCollection']['contributionCalendar']['totalContributions'] ?? 0);
        // GraphQL often fails without a PAT (auth / rate limit). Fall back to the public calendar page.
        if (!$out) {
            $html = self::fetch_contribution_calendar_html($username);
            if (!empty($html['ok'])) {
                $out = $html['points'];
                if (!$user) {
                    $user = [
                        'login' => $username,
                        'contributionsCollection' => [
                            'contributionCalendar' => [
                                'totalContributions' => (int) ($html['total'] ?? 0),
                            ],
                        ],
                    ];
                } else if ($gqltotal <= 0) {
                    if (!empty($html['total'])) {
                        $user['contributionsCollection']['contributionCalendar']['totalContributions'] =
                            (int) $html['total'];
                    } else if ($out) {
                        $sum = 0;
                        foreach ($out as $pt) {
                            $sum += (int) ($pt['count'] ?? 0);
                        }
                        $user['contributionsCollection']['contributionCalendar']['totalContributions'] = $sum;
                    }
                }
            }
        }

        if (!$user && !$out) {
            return $empty;
        }

        return ['points' => $out, 'user' => $user];
    }

    /**
     * Scrape public GitHub contribution calendar HTML (no API token required).
     *
     * @param string $username
     * @return array{points: array<int, array{date:string, count:int}>, total: int, ok: bool}
     */
    public static function fetch_contribution_calendar_html(string $username): array {
        $empty = ['points' => [], 'total' => 0, 'ok' => false];
        $username = trim($username);
        if ($username === '') {
            return $empty;
        }

        $url = 'https://github.com/users/' . rawurlencode($username) . '/contributions';
        $res = http::get($url, [
            'Accept: text/html,application/xhtml+xml',
            'User-Agent: Mozilla/5.0 (compatible; NexPortfolio/1.0; +https://moodle.org)',
        ], 25);
        $html = (string) ($res['body'] ?? '');
        $code = (int) ($res['code'] ?? 0);
        if ($html === '' || ($code > 0 && $code >= 400)) {
            return $empty;
        }

        $total = 0;
        if (preg_match('/([\d,]+)\s+contributions?\s+in the last year/i', $html, $tm)) {
            $total = (int) str_replace(',', '', $tm[1]);
        }

        $tipcounts = [];
        if (preg_match_all(
            '/for="(contribution-day-component-[^"]+)"[^>]*>\s*(No|[0-9,]+)\s+contributions?\s+on/i',
            $html,
            $tips,
            PREG_SET_ORDER
        )) {
            foreach ($tips as $tip) {
                $id = $tip[1];
                if (stripos($tip[2], 'No') === 0) {
                    $tipcounts[$id] = 0;
                } else {
                    $tipcounts[$id] = (int) str_replace(',', '', $tip[2]);
                }
            }
        }

        $out = [];
        $daycells = 0;
        if (preg_match_all('/<td\b[^>]*\bContributionCalendar-day\b[^>]*>/i', $html, $cells)) {
            foreach ($cells[0] as $cell) {
                $daycells++;
                if (!preg_match('/data-date="(\d{4}-\d{2}-\d{2})"/', $cell, $dm)) {
                    continue;
                }
                $date = $dm[1];
                $id = '';
                if (preg_match('/\bid="([^"]+)"/', $cell, $im)) {
                    $id = $im[1];
                }
                $level = 0;
                if (preg_match('/data-level="(\d+)"/', $cell, $lm)) {
                    $level = (int) $lm[1];
                }
                $count = ($id !== '' && array_key_exists($id, $tipcounts))
                    ? (int) $tipcounts[$id]
                    : 0;
                if ($count <= 0 && $level > 0) {
                    // Intensity only — better than dropping the day from the heatmap.
                    $count = $level;
                }
                if ($count <= 0) {
                    continue;
                }
                $out[] = ['date' => $date, 'count' => $count];
            }
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($a['date'], $b['date']);
        });

        if ($total <= 0 && $out) {
            $total = 0;
            foreach ($out as $pt) {
                $total += (int) ($pt['count'] ?? 0);
            }
        }

        $ok = $daycells > 0 || $total > 0 || !empty($out);
        return ['points' => $out, 'total' => $total, 'ok' => $ok];
    }

    /**
     * GitHub contribution calendar for the last ~year (GraphQL).
     *
     * @param string $username
     * @return array<int, array{date:string, count:int}>
     */
    public static function fetch_contribution_calendar(string $username): array {
        return self::fetch_contribution_data($username)['points'];
    }

    /**
     * Load cached heatmap or fetch fresh data for a user.
     *
     * @param int $userid
     * @param string $username
     * @param bool $force
     * @return array<int, array{date:string, count:int}>
     */
    public static function heatmap_for_user(int $userid, string $username, bool $force = false): array {
        global $DB;

        $username = trim($username);
        if ($username === '') {
            return [];
        }

        $record = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]);
        $ttl = 6 * 3600;
        $now = time();
        $cached = [];
        if ($record && !empty($record->heatmap_json)) {
            $decoded = json_decode((string) $record->heatmap_json, true);
            $cached = is_array($decoded) ? $decoded : [];
        }
        $stats = $record ? self::export_stats($record) : self::empty_stats();
        $heatok = $record && (time() - (int) ($record->heatmap_fetch ?? 0)) < $ttl;
        $statsok = $record && !empty($record->stats_json)
            && ($now - (int) ($record->stats_fetch ?? 0)) < $ttl;
        // Refresh when contribution totals exist but day cells were never stored (common before HTML fallback).
        $heatmapmissing = ((int) ($stats['contributionsyear'] ?? 0) > 0) && !$cached;
        if (!$force && $heatok && $statsok && !$heatmapmissing) {
            return $cached;
        }

        $user = self::fetch_public_user($username);
        if ($user) {
            self::save_profile($userid, $user);
            $record = $DB->get_record('local_nexportfolio_github', ['userid' => $userid]) ?: $record;
        }

        $contrib = self::fetch_contribution_data($username);
        $points = $contrib['points'];
        // Persist even an empty calendar when the fetch succeeded, so we don't refetch every page load.
        if ($points || !empty($contrib['user'])) {
            self::save_heatmap($userid, $points);
        } else if (!$record && $user) {
            $record = self::get_profile($userid);
        }

        $previous = $record ? self::export_stats($record) : self::empty_stats();
        self::save_stats($userid, self::build_stats($user ?: [], $contrib['user'] ?? [], [], $previous));

        return $points;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function parse_time($value): int {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts ? (int) $ts : 0;
    }

    /**
     * @param mixed $node
     * @return int
     */
    private static function count_of($node): int {
        if (is_array($node) && isset($node['totalCount'])) {
            return (int) $node['totalCount'];
        }
        if (is_numeric($node)) {
            return (int) $node;
        }
        return 0;
    }

    /**
     * @param int $userid
     * @param string $login
     */
    public static function upsert_github_handle(int $userid, string $login): void {
        global $DB;

        $login = trim($login);
        if ($login === '') {
            return;
        }
        $now = time();
        $existing = $DB->get_record('local_nexportfolio_handles', [
            'userid' => $userid,
            'platform' => 'github',
        ]);
        if ($existing) {
            $existing->handle = \core_text::substr($login, 0, 100);
            $existing->verified = 1;
            $existing->timemodified = $now;
            $DB->update_record('local_nexportfolio_handles', $existing);
            return;
        }
        $DB->insert_record('local_nexportfolio_handles', (object) [
            'userid' => $userid,
            'platform' => 'github',
            'handle' => \core_text::substr($login, 0, 100),
            'verified' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * @param int $userid
     */
    public static function disconnect(int $userid): void {
        global $DB;
        $DB->delete_records('local_nexportfolio_github', ['userid' => $userid]);
        $DB->delete_records('local_nexportfolio_handles', ['userid' => $userid, 'platform' => 'github']);
        $DB->delete_records('local_nexportfolio_projects', ['userid' => $userid, 'source' => 'github']);
    }

    /**
     * @param string $path
     * @param array $query
     * @return array|null
     */
    public static function api_get(string $path, array $query = []): ?array {
        $url = self::API . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return http::get_json($url, self::api_headers(false), 25);
    }

    /**
     * @param string $username
     * @return array|null
     */
    public static function fetch_public_user(string $username): ?array {
        $username = trim($username);
        if ($username === '') {
            return null;
        }
        $user = self::api_get('/users/' . rawurlencode($username));
        return (is_array($user) && !empty($user['login'])) ? $user : null;
    }

    /**
     * @param string $username
     * @param int $maxpages
     * @return array<int, array>
     */
    public static function list_repos(string $username, int $maxpages = 5): array {
        $username = trim($username);
        if ($username === '') {
            return [];
        }
        $repos = [];
        for ($page = 1; $page <= $maxpages; $page++) {
            $chunk = self::api_get('/users/' . rawurlencode($username) . '/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'updated',
                'direction' => 'desc',
            ]);
            if (!is_array($chunk) || !$chunk) {
                break;
            }
            foreach ($chunk as $repo) {
                if (is_array($repo)) {
                    $repos[] = $repo;
                }
            }
            if (count($chunk) < 100) {
                break;
            }
        }
        return $repos;
    }

    /**
     * @param string $owner
     * @param string $repo
     * @return array<string, int>
     */
    public static function repo_languages(string $owner, string $repo): array {
        $data = self::api_get('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/languages');
        return is_array($data) ? $data : [];
    }

    /**
     * Fetch README.md raw text for a repository.
     *
     * @param string $owner
     * @param string $repo
     * @return string Markdown or empty if missing.
     */
    public static function repo_readme_raw(string $owner, string $repo): string {
        $owner = trim($owner);
        $repo = trim($repo);
        if ($owner === '' || $repo === '') {
            return '';
        }
        $url = self::API . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/readme';
        $res = http::get($url, self::api_headers(true), 20);
        if (empty($res['body']) || (int) ($res['code'] ?? 0) === 404) {
            return '';
        }
        if ((int) ($res['code'] ?? 0) >= 400) {
            return '';
        }
        return (string) $res['body'];
    }

    /**
     * Strip markdown to readable plain text.
     *
     * @param string $markdown
     * @return string
     */
    public static function markdown_to_plain(string $markdown): string {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = preg_replace('/<!--[\s\S]*?-->/', '', $text) ?? $text;
        $text = preg_replace('/```[\s\S]*?```/', ' ', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^>\s?/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*+]\s+/m', '• ', $text) ?? $text;
        $text = preg_replace('/^\s*\d+\.\s+/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|~~)/', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * @param string $markdown
     * @param int $limit
     * @return string Card excerpt from README.
     */
    public static function readme_excerpt(string $markdown, int $limit = 1200): string {
        $plain = self::markdown_to_plain($markdown);
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($plain, 'UTF-8') > $limit) {
            return rtrim(mb_substr($plain, 0, $limit, 'UTF-8')) . '…';
        }
        if (strlen($plain) > $limit) {
            return rtrim(substr($plain, 0, $limit)) . '…';
        }
        return $plain;
    }

    /**
     * @param array<string, int> $languages
     * @param int $limit
     * @return array<int, array{name:string, bytes:int, pct:int}>
     */
    public static function normalize_languages(array $languages, int $limit = 6): array {
        if (!$languages) {
            return [];
        }
        arsort($languages);
        $total = array_sum($languages);
        if ($total <= 0) {
            return [];
        }
        $out = [];
        foreach (array_slice($languages, 0, $limit, true) as $name => $bytes) {
            $out[] = [
                'name' => (string) $name,
                'bytes' => (int) $bytes,
                'pct' => (int) round(((int) $bytes / $total) * 100),
            ];
        }
        return $out;
    }
}
