<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Portfolio projects (GitHub repos imported as projects).
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Project sync + read helpers.
 */
class projects {

    /**
     * Import/sync GitHub repositories as portfolio projects.
     *
     * @param int $userid
     * @param string $username GitHub login
     * @param bool $fetchlanguages
     * @param bool $fetchreadmes
     * @return array{imported:int, updated:int, total:int, login:string}
     */
    public static function sync_github(
        int $userid,
        string $username = '',
        bool $fetchlanguages = true,
        bool $fetchreadmes = true
    ): array {
        global $DB;

        @set_time_limit(180);

        $username = trim($username);
        if ($username === '') {
            $handle = $DB->get_record('local_nexportfolio_handles', [
                'userid' => $userid,
                'platform' => 'github',
            ]);
            $username = $handle ? trim((string) $handle->handle) : '';
        }
        if ($username === '') {
            throw new \moodle_exception('githubnotconnected', 'local_nexportfolio');
        }

        $profile = github::fetch_public_user($username);
        if (!$profile) {
            throw new \moodle_exception('githubuserfailed', 'local_nexportfolio', '', $username);
        }
        github::save_profile($userid, $profile);
        $login = (string) $profile['login'];

        $contrib = github::fetch_contribution_data($login);
        if (!empty($contrib['points'])) {
            github::save_heatmap($userid, $contrib['points']);
        }

        $repos = github::list_repos($login);
        github::save_stats($userid, github::build_stats(
            $profile,
            $contrib['user'] ?? [],
            $repos
        ));
        if (!$repos) {
            throw new \moodle_exception('githubnorepos', 'local_nexportfolio');
        }

        usort($repos, static function (array $a, array $b): int {
            return ((int) ($b['stargazers_count'] ?? 0)) <=> ((int) ($a['stargazers_count'] ?? 0));
        });

        $now = time();
        $imported = 0;
        $updated = 0;
        $seenids = [];

        foreach ($repos as $repo) {
            $githubid = (int) ($repo['id'] ?? 0);
            if ($githubid <= 0) {
                continue;
            }
            $seenids[] = $githubid;

            $owner = (string) (($repo['owner']['login'] ?? '') ?: explode('/', (string) ($repo['full_name'] ?? ''))[0]);
            $name = (string) ($repo['name'] ?? '');
            $fullname = (string) ($repo['full_name'] ?? ($owner . '/' . $name));

            $languages = [];
            if ($fetchlanguages && $owner !== '' && $name !== '') {
                $rawlangs = github::repo_languages($owner, $name);
                $languages = github::normalize_languages($rawlangs);
            } else if (!empty($repo['language'])) {
                $languages = [['name' => (string) $repo['language'], 'bytes' => 0, 'pct' => 100]];
            }

            $topics = [];
            if (!empty($repo['topics']) && is_array($repo['topics'])) {
                $topics = array_values(array_filter(array_map('strval', $repo['topics'])));
            }

            $pushed = 0;
            if (!empty($repo['pushed_at'])) {
                $pushed = strtotime((string) $repo['pushed_at']) ?: 0;
            }

            $readmemd = '';
            $readmeplain = '';
            $description = trim((string) ($repo['description'] ?? ''));
            if ($fetchreadmes && $owner !== '' && $name !== '') {
                $readmemd = github::repo_readme_raw($owner, $name);
                if ($readmemd !== '') {
                    $readmeplain = github::markdown_to_plain($readmemd);
                    $description = github::readme_excerpt($readmemd, 2000);
                }
            }
            if ($description === '') {
                $description = trim((string) ($repo['description'] ?? ''));
            }

            $record = $DB->get_record('local_nexportfolio_projects', [
                'userid' => $userid,
                'github_id' => $githubid,
            ]);

            $row = $record ?: (object) [
                'userid' => $userid,
                'source' => 'github',
                'github_id' => $githubid,
                'timecreated' => $now,
            ];

            $row->owner = \core_text::substr($owner, 0, 100);
            $row->name = \core_text::substr($name, 0, 100);
            $row->fullname = \core_text::substr($fullname, 0, 200);
            $row->url = \core_text::substr((string) ($repo['html_url'] ?? ''), 0, 255);
            $row->homepage = \core_text::substr((string) ($repo['homepage'] ?? ''), 0, 255);
            $row->description = \core_text::substr($description, 0, 4000);
            $row->readme = self::truncate_readme($readmeplain !== '' ? $readmeplain : $readmemd);
            $row->primary_language = \core_text::substr((string) ($repo['language'] ?? ''), 0, 60);
            $row->stars = (int) ($repo['stargazers_count'] ?? 0);
            $row->forks = (int) ($repo['forks_count'] ?? 0);
            $row->watchers = (int) ($repo['watchers_count'] ?? 0);
            $row->open_issues = (int) ($repo['open_issues_count'] ?? 0);
            $row->topics_json = json_encode($topics);
            $row->languages_json = json_encode($languages);
            $row->visibility = !empty($repo['private']) ? 'private' : 'public';
            $row->is_fork = !empty($repo['fork']) ? 1 : 0;
            $row->lastpush = $pushed;
            $row->importedjson = json_encode($repo);
            $row->timemodified = $now;

            if (!empty($record->id)) {
                $row->id = $record->id;
                $DB->update_record('local_nexportfolio_projects', $row);
                $updated++;
            } else {
                $row->pinned = 0;
                $DB->insert_record('local_nexportfolio_projects', $row);
                $imported++;
            }
        }

        if ($seenids) {
            list($insql, $params) = $DB->get_in_or_equal($seenids, SQL_PARAMS_NAMED, 'gid', false);
            $params['userid'] = $userid;
            $params['source'] = 'github';
            $DB->delete_records_select(
                'local_nexportfolio_projects',
                "userid = :userid AND source = :source AND github_id $insql",
                $params
            );
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'total' => count($repos),
            'login' => $login,
        ];
    }

    /**
     * @param string $text
     * @return string|null
     */
    private static function truncate_readme(string $text): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 24000) {
            return mb_substr($text, 0, 24000, 'UTF-8');
        }
        if (strlen($text) > 24000) {
            return substr($text, 0, 24000);
        }
        return $text;
    }

    /**
     * @param int $userid
     * @return array<int, \stdClass>
     */
    public static function get_for_user(int $userid): array {
        global $DB;
        return $DB->get_records('local_nexportfolio_projects', ['userid' => $userid], 'stars DESC, lastpush DESC');
    }

    /**
     * @param \stdClass $row
     * @return array
     */
    public static function export_row(\stdClass $row): array {
        $topics = json_decode($row->topics_json ?? '[]', true);
        $languages = json_decode($row->languages_json ?? '[]', true);
        if (!is_array($topics)) {
            $topics = [];
        }
        if (!is_array($languages)) {
            $languages = [];
        }
        $readme = (string) ($row->readme ?? '');
        $description = (string) ($row->description ?? '');
        if ($description === '' && $readme !== '') {
            $description = github::readme_excerpt($readme, 2000);
        }
        return [
            'id' => (int) $row->id,
            'source' => (string) $row->source,
            'github_id' => (int) $row->github_id,
            'owner' => (string) $row->owner,
            'name' => (string) $row->name,
            'fullname' => (string) $row->fullname,
            'url' => (string) $row->url,
            'homepage' => (string) ($row->homepage ?? ''),
            'description' => $description,
            'readme' => $readme,
            'has_readme' => $readme !== '',
            'primary_language' => (string) ($row->primary_language ?? ''),
            'stars' => (int) $row->stars,
            'forks' => (int) $row->forks,
            'watchers' => (int) $row->watchers,
            'open_issues' => (int) $row->open_issues,
            'topics' => array_values($topics),
            'languages' => array_values($languages),
            'visibility' => (string) ($row->visibility ?? 'public'),
            'is_fork' => !empty($row->is_fork),
            'pinned' => !empty($row->pinned),
            'lastpush' => (int) ($row->lastpush ?? 0),
            'timemodified' => (int) ($row->timemodified ?? 0),
        ];
    }
}
