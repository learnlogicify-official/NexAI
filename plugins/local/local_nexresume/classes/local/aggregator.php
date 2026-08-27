<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Collect resume data from Nex plugins and Moodle profile.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexresume\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Platform data aggregator for resume builder.
 */
class aggregator {

    /** @var int Maximum projects on the exported resume. */
    public const MAX_RESUME_PROJECTS = 3;

    /**
     * Build default resume payload from all available sources.
     *
     * @param int $userid
     * @return array
     */
    public static function collect(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        profile_get_custom_fields($userid);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

        $contact = self::contact_defaults($user);
        $education = self::education_defaults($user);
        $skills = self::skills_bundle($userid);
        $projects = self::projects($userid);
        $platforms = self::competitive_lines($userid);
        $sources = self::source_flags($userid);

        return [
            'template' => templates::DEFAULT,
            'contact' => $contact,
            'objective' => '',
            'education' => [$education],
            'skills' => $skills,
            'projects' => $projects,
            'platforms' => $platforms,
            'certifications' => [],
            'achievements' => [],
            'volunteering' => [],
            'sections' => [
                'objective' => false,
                'education' => true,
                'projects' => count($projects) > 0,
                'skills' => self::has_skill_content($skills),
                'certifications' => false,
                'competitive' => count($platforms) > 0,
                'achievements' => false,
                'volunteering' => false,
            ],
            'sources' => $sources,
            'meta' => [
                'completeness' => self::completeness($contact, $education, $skills, $projects, $platforms),
            ],
        ];
    }

    /**
     * @param \stdClass $user
     * @return array
     */
    private static function contact_defaults(\stdClass $user): array {
        $github = '';
        if (file_exists(__DIR__ . '/../../../nexportfolio/classes/local/github.php')) {
            require_once(__DIR__ . '/../../../nexportfolio/classes/local/github.php');
            if (class_exists('\local_nexportfolio\local\github')) {
                $profile = \local_nexportfolio\local\github::get_profile((int) $user->id);
                if ($profile && !empty($profile->github_login)) {
                    $github = (string) $profile->github_login;
                }
            }
        }
        if ($github === '' && file_exists(__DIR__ . '/../../../nexportfolio/lib.php')) {
            require_once(__DIR__ . '/../../../nexportfolio/lib.php');
            if (function_exists('local_nexportfolio_get_handles')) {
                $handles = local_nexportfolio_get_handles((int) $user->id);
                if (!empty($handles['github']->handle)) {
                    $github = (string) $handles['github']->handle;
                }
            }
        }

        $location = trim((string) ($user->city ?? ''));
        if ($location !== '' && !empty($user->country)) {
            $country = get_string($user->country, 'countries');
            if ($country && stripos($location, $country) === false) {
                $location .= ', ' . $country;
            }
        }
        if ($location === '' && !empty($user->institution)) {
            $location = trim((string) $user->institution);
        }

        return [
            'fullname' => fullname($user),
            'email' => (string) ($user->email ?? ''),
            'phone' => trim((string) ($user->phone1 ?? '')),
            'location' => $location,
            'linkedin' => '',
            'github' => $github,
            'portfolio' => '',
        ];
    }

    /**
     * @param \stdClass $user
     * @return array
     */
    private static function education_defaults(\stdClass $user): array {
        $school = trim((string) ($user->institution ?? ''));
        $degree = trim((string) ($user->department ?? ''));
        $dates = '';
        if (!empty($user->profile_field_passtext)) {
            $dates = trim((string) $user->profile_field_passtext);
        }

        return [
            'school' => $school,
            'degree' => $degree,
            'dates' => $dates,
            'gpa' => '',
            'coursework' => 'DSA, Computer Architecture, Computer Networks, Operating Systems, '
                . 'Database Management Systems, OOPS, Artificial Intelligence, Machine Learning.',
        ];
    }

    /**
     * @param int $userid
     * @return array
     */
    private static function skills_bundle(int $userid): array {
        $languages = [];
        $fundamentals = [];

        if (class_exists('\local_nexprofile\local\profile')) {
            foreach (\local_nexprofile\local\profile::languages($userid) as $row) {
                $name = self::clean_language_name((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $languages[] = $name;
                }
            }
            foreach (\local_nexprofile\local\profile::skills($userid) as $row) {
                $fundamentals[] = (string) ($row['name'] ?? '');
            }
        }

        foreach (self::project_languages($userid) as $lang) {
            if ($lang !== '' && !in_array($lang, $languages, true)) {
                $languages[] = $lang;
            }
        }

        if (!$fundamentals) {
            $fundamentals = ['DSA', 'Object-Oriented Programming (OOPS)', 'Debugging'];
        }

        $frameworks = self::infer_frameworks($languages);

        return [
            'languages' => self::join_list($languages),
            'frameworks' => self::join_list($frameworks),
            'tools' => self::join_list(['Git', 'GitHub', 'Linux', 'PostgreSQL', 'Docker']),
            'fundamentals' => self::join_list($fundamentals),
        ];
    }

    /**
     * @param int $userid
     * @return string[]
     */
    private static function project_languages(int $userid): array {
        $langs = [];
        foreach (self::portfolio_project_rows($userid) as $p) {
            foreach (self::language_names_from_project($p) as $name) {
                if ($name !== '' && !in_array($name, $langs, true)) {
                    $langs[] = $name;
                }
            }
        }
        return $langs;
    }

    /**
     * @param array $p
     * @return string[]
     */
    private static function language_names_from_project(array $p): array {
        $langs = [];
        if (!empty($p['languages']) && is_array($p['languages'])) {
            foreach ($p['languages'] as $item) {
                if (is_array($item) && !empty($item['name'])) {
                    $name = self::clean_language_name((string) $item['name']);
                } else if (is_string($item)) {
                    $name = self::clean_language_name($item);
                } else {
                    continue;
                }
                if ($name !== '') {
                    $langs[] = $name;
                }
            }
        }
        if (empty($langs) && !empty($p['primary_language'])) {
            $name = self::clean_language_name((string) $p['primary_language']);
            if ($name !== '') {
                $langs[] = $name;
            }
        }
        return $langs;
    }

    /**
     * @param string $name
     * @return string
     */
    private static function clean_language_name(string $name): string {
        $name = trim($name);
        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return '';
        }
        $name = preg_replace('/\s*\(\d+\)\s*$/', '', $name) ?? $name;
        $map = [
            'python3' => 'Python',
            'python' => 'Python',
            'py' => 'Python',
            'cpp' => 'C++',
            'c++' => 'C++',
            'java' => 'Java',
            'javascript' => 'JavaScript',
            'js' => 'JavaScript',
            'typescript' => 'TypeScript',
            'csharp' => 'C#',
            'go' => 'Go',
            'rust' => 'Rust',
        ];
        $key = strtolower($name);
        return $map[$key] ?? $name;
    }

    /**
     * @param string[] $projectlangs
     * @return string[]
     */
    private static function infer_frameworks(array $projectlangs): array {
        $frameworks = [];
        $map = [
            'TypeScript' => 'React.js',
            'JavaScript' => 'Node.js',
            'Dart' => 'Flutter',
            'Python' => 'REST APIs',
            'Java' => 'REST APIs',
            'C#' => '.NET Core',
        ];
        foreach ($projectlangs as $lang) {
            if (isset($map[$lang]) && !in_array($map[$lang], $frameworks, true)) {
                $frameworks[] = $map[$lang];
            }
        }
        if (!$frameworks) {
            $frameworks = ['REST APIs'];
        }
        return $frameworks;
    }

    /**
     * @param int $userid
     * @return array
     */
    private static function projects(int $userid): array {
        $raw = self::portfolio_project_rows($userid);
        $out = [];
        $rank = 0;
        foreach ($raw as $p) {
            $stack = self::join_list(self::language_names_from_project($p));
            $bullets = self::bullets_from_project($p);
            $out[] = [
                'id' => (int) ($p['id'] ?? 0),
                'name' => (string) ($p['fullname'] ?? $p['name'] ?? ''),
                'stack' => $stack,
                'date' => self::format_project_date((int) ($p['lastpush'] ?? 0)),
                'bullets' => $bullets,
                'included' => $rank < self::MAX_RESUME_PROJECTS,
                'url' => (string) ($p['url'] ?? ''),
            ];
            $rank++;
        }
        return $out;
    }

    /**
     * Load GitHub/portfolio projects from NexPortfolio tables (no lib.php required).
     *
     * @param int $userid
     * @return array
     */
    private static function portfolio_project_rows(int $userid): array {
        global $CFG, $DB;

        if ($DB->get_manager()->table_exists('local_nexportfolio_projects')) {
            $records = $DB->get_records(
                'local_nexportfolio_projects',
                ['userid' => $userid],
                'stars DESC, lastpush DESC'
            );
            if ($records) {
                $classfile = $CFG->dirroot . '/local/nexportfolio/classes/local/projects.php';
                if (file_exists($classfile)) {
                    require_once($classfile);
                }
                $out = [];
                foreach ($records as $row) {
                    if (class_exists('\local_nexportfolio\local\projects')) {
                        $out[] = \local_nexportfolio\local\projects::export_row($row);
                    } else {
                        $langs = json_decode($row->languages_json ?? '[]', true);
                        $out[] = [
                            'id' => (int) $row->id,
                            'name' => (string) ($row->name ?? ''),
                            'fullname' => (string) ($row->fullname ?? $row->name ?? ''),
                            'url' => (string) ($row->url ?? ''),
                            'description' => (string) ($row->description ?? ''),
                            'readme' => (string) ($row->readme ?? ''),
                            'primary_language' => (string) ($row->primary_language ?? ''),
                            'languages' => is_array($langs) ? $langs : [],
                            'lastpush' => (int) ($row->lastpush ?? 0),
                        ];
                    }
                }
                return $out;
            }
        }

        if (file_exists($CFG->dirroot . '/local/nexportfolio/lib.php')) {
            require_once($CFG->dirroot . '/local/nexportfolio/lib.php');
            if (function_exists('local_nexportfolio_get_projects')) {
                return local_nexportfolio_get_projects($userid);
            }
        }
        return [];
    }

    /**
     * @param array $p
     * @return string[]
     */
    private static function bullets_from_project(array $p): array {
        $desc = trim((string) ($p['description'] ?? ''));
        $readme = trim((string) ($p['readme'] ?? ''));
        $text = $desc !== '' ? $desc : $readme;
        if ($text === '') {
            $name = (string) ($p['fullname'] ?? 'Project');
            return [
                'Designed and implemented ' . $name . ' with a focus on clean architecture and maintainability.',
                'Applied engineering best practices including automated validation and performance monitoring.',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $bullets = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^[\-*•]\s*/', '', trim($line)) ?? '');
            if ($line === '' || strlen($line) < 20) {
                continue;
            }
            if (preg_match('/^(#+|!\[|```|\|)/', $line)) {
                continue;
            }
            $bullets[] = $line;
            if (count($bullets) >= 3) {
                break;
            }
        }
        if (!$bullets) {
            $bullets[] = \core_text::substr($text, 0, 220);
        }
        return $bullets;
    }

    /**
     * @param int $timestamp
     * @return string
     */
    private static function format_project_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        return userdate($timestamp, '%b %Y');
    }

    /**
     * @param int $userid
     * @return string[]
     */
    private static function competitive_lines(int $userid): array {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/local/nexportfolio/lib.php')) {
            return [];
        }
        require_once($CFG->dirroot . '/local/nexportfolio/lib.php');
        if (!function_exists('local_nexportfolio_get_handles') || !function_exists('local_nexportfolio_get_cached_data')) {
            return [];
        }

        $handles = local_nexportfolio_get_handles($userid);
        $cached = local_nexportfolio_get_cached_data($userid);
        $lines = [];
        $totalsolved = 0;

        foreach (local_nexportfolio_platforms() as $key => $strkey) {
            $h = $handles[$key] ?? null;
            if (!$h || trim((string) ($h->handle ?? '')) === '') {
                continue;
            }
            $d = $cached[$key] ?? null;
            if (!$d) {
                continue;
            }

            $label = get_string($strkey, 'local_nexportfolio');
            $solved = (int) $d->totalsolved;
            $totalsolved += $solved;
            $rating = (float) $d->rating;
            $ranktext = trim((string) ($d->ranktext ?? ''));

            $profile = [];
            if (!empty($d->datajson)) {
                $profile = json_decode($d->datajson, true) ?: [];
            }
            $stats = is_array($profile['stats'] ?? null) ? $profile['stats'] : [];

            $parts = [];
            if ($rating > 0) {
                $tier = self::rating_tier($key, $rating);
                $parts[] = 'Max Rating ' . rtrim(rtrim(number_format($rating, 0), '0'), '.') . ($tier ? ' (' . $tier . ')' : '');
            }
            if ($solved > 0) {
                $parts[] = 'Solved ' . $solved . '+ Problems';
            }
            if ($ranktext !== '') {
                $parts[] = $ranktext;
            } else if (!empty($stats['globalRank'])) {
                $parts[] = 'Global Rank ' . (int) $stats['globalRank'];
            } else if (!empty($profile['globalRank'])) {
                $parts[] = 'Global Rank ' . (int) $profile['globalRank'];
            }

            if ($parts) {
                $lines[] = $label . ': ' . implode(' | ', $parts);
            }
        }

        if ($totalsolved > 0 && count($lines) > 1) {
            $lines[] = 'Solved ' . $totalsolved . '+ total algorithmic problems across platforms.';
        }

        return $lines;
    }

    /**
     * @param string $platform
     * @param float $rating
     * @return string
     */
    private static function rating_tier(string $platform, float $rating): string {
        if ($platform === 'codeforces') {
            if ($rating >= 2400) {
                return 'Grandmaster';
            }
            if ($rating >= 2100) {
                return 'Master';
            }
            if ($rating >= 1900) {
                return 'Candidate Master';
            }
            if ($rating >= 1600) {
                return 'Expert';
            }
        }
        if ($platform === 'codechef') {
            if ($rating >= 2200) {
                return '7-Star';
            }
            if ($rating >= 1800) {
                return '5-Star';
            }
            if ($rating >= 1600) {
                return '4-Star';
            }
        }
        return '';
    }

    /**
     * @param int $userid
     * @return array
     */
    private static function source_flags(int $userid): array {
        global $CFG, $DB;

        $portfolio = file_exists($CFG->dirroot . '/local/nexportfolio/index.php');
        $practice = $DB->get_manager()->table_exists('local_learnlogic_submission');
        $codelab = $DB->get_manager()->table_exists('local_nexcodelab_submission');
        $projectcount = 0;
        $platformcount = 0;

        if ($DB->get_manager()->table_exists('local_nexportfolio_projects')) {
            $projectcount = (int) $DB->count_records('local_nexportfolio_projects', ['userid' => $userid]);
        }
        if ($portfolio && file_exists($CFG->dirroot . '/local/nexportfolio/lib.php')) {
            require_once($CFG->dirroot . '/local/nexportfolio/lib.php');
            if (function_exists('local_nexportfolio_get_handles')) {
                foreach (local_nexportfolio_get_handles($userid) as $h) {
                    if (!empty($h->handle)) {
                        $platformcount++;
                    }
                }
            }
        }

        $skillcount = 0;
        if (class_exists('\local_nexprofile\local\profile')) {
            $skillcount = count(\local_nexprofile\local\profile::skills($userid));
        }

        return [
            'portfolio' => $portfolio,
            'practice' => $practice,
            'codelab' => $codelab,
            'projectcount' => $projectcount,
            'platformcount' => $platformcount,
            'skillcount' => $skillcount,
        ];
    }

    /**
     * @param array $skills
     * @return bool
     */
    private static function has_skill_content(array $skills): bool {
        foreach (['languages', 'frameworks', 'tools', 'fundamentals'] as $key) {
            if (trim((string) ($skills[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $contact
     * @param array $education
     * @param array $skills
     * @param array $projects
     * @param array $platforms
     * @return int
     */
    public static function completeness(array $contact, array $education, array $skills, array $projects, array $platforms): int {
        $score = 0;
        $total = 10;
        if (trim((string) ($contact['fullname'] ?? '')) !== '') {
            $score++;
        }
        if (trim((string) ($contact['email'] ?? '')) !== '') {
            $score++;
        }
        if (trim((string) ($contact['phone'] ?? '')) !== '') {
            $score++;
        }
        $edu = $education;
        if (isset($edu[0]) && is_array($edu[0])) {
            $edu = $edu[0];
        }
        if (trim((string) ($edu['school'] ?? '')) !== '') {
            $score++;
        }
        if (trim((string) ($edu['degree'] ?? '')) !== '') {
            $score++;
        }
        if (self::has_skill_content($skills)) {
            $score++;
        }
        if (count($projects) > 0) {
            $score++;
        }
        if (count($platforms) > 0) {
            $score++;
        }
        if (trim((string) ($contact['github'] ?? '')) !== '') {
            $score++;
        }
        if (trim((string) ($contact['location'] ?? '')) !== '') {
            $score++;
        }
        return (int) round(($score / $total) * 100);
    }

    /**
     * @param string[] $items
     * @return string
     */
    private static function join_list(array $items): string {
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));
        return implode(', ', $items);
    }
}
