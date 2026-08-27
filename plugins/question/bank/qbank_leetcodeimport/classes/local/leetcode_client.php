<?php
// This file is part of Moodle - http://moodle.org/
/**
 * LeetCode GraphQL / API client.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Fetch LeetCode problems by frontend ID, slug, or URL.
 */
class leetcode_client {

    /** @var string */
    private string $graphql = 'https://leetcode.com/graphql';

    /** @var array|null Cached id→slug map */
    private static ?array $idmap = null;

    /**
     * Normalize user input to a title slug.
     *
     * @param string $raw
     * @return string
     */
    public function resolve_slug(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \invalid_parameter_exception('Empty problem id');
        }

        if (preg_match('#leetcode\.com/problems/([a-z0-9\-]+)#i', $raw, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^\d+$/', $raw)) {
            return $this->slug_from_frontend_id((int) $raw);
        }

        // Already a slug (possibly with spaces).
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        return trim($slug, '-');
    }

    /**
     * Fetch full problem payload.
     *
     * @param string $raw Id, slug, or URL.
     * @return array
     */
    public function fetch_problem(string $raw): array {
        $slug = $this->resolve_slug($raw);
        $query = <<<'GQL'
query questionData($titleSlug: String!) {
  question(titleSlug: $titleSlug) {
    questionId
    questionFrontendId
    title
    titleSlug
    content
    difficulty
    likes
    dislikes
    isPaidOnly
    sampleTestCase
    exampleTestcases
    hints
    acRate
    stats
    topicTags { name slug }
    codeSnippets { lang langSlug code }
  }
}
GQL;

        $data = $this->graphql($query, ['titleSlug' => $slug], 'questionData');
        if (empty($data['question']) || !is_array($data['question'])) {
            throw new \moodle_exception('fetchfailed', 'qbank_leetcodeimport', '', $raw);
        }

        $q = $data['question'];
        if (!empty($q['isPaidOnly']) && empty($q['content'])) {
            throw new \moodle_exception(
                'fetchfailed',
                'qbank_leetcodeimport',
                '',
                $raw . ' (paid/locked — set LeetCode session cookie in settings)'
            );
        }

        $q['_resolvedSlug'] = $slug;
        $q['_sourceInput'] = $raw;
        $q['_url'] = 'https://leetcode.com/problems/' . $slug . '/';
        return $q;
    }

    /**
     * Map frontend question id → titleSlug via public problem list.
     *
     * @param int $id
     * @return string
     */
    private function slug_from_frontend_id(int $id): string {
        $map = $this->frontend_id_map();
        if (!isset($map[$id])) {
            throw new \moodle_exception('fetchfailed', 'qbank_leetcodeimport', '', (string) $id);
        }
        return $map[$id];
    }

    /**
     * @return array<int,string>
     */
    private function frontend_id_map(): array {
        if (self::$idmap !== null) {
            return self::$idmap;
        }

        $cache = \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            'qbank_leetcodeimport',
            'idmap'
        );
        $cached = $cache->get('frontend');
        if (is_array($cached) && $cached) {
            self::$idmap = $cached;
            return self::$idmap;
        }

        $url = 'https://leetcode.com/api/problems/all/';
        $response = $this->http_json('GET', $url, null, false);
        $map = [];
        $pairs = $response['stat_status_pairs'] ?? [];
        foreach ($pairs as $row) {
            $stat = $row['stat'] ?? [];
            $fid = isset($stat['frontend_question_id']) ? (int) $stat['frontend_question_id'] : 0;
            $slug = (string) ($stat['question__title_slug'] ?? '');
            if ($fid > 0 && $slug !== '') {
                $map[$fid] = $slug;
            }
        }
        if (!$map) {
            throw new \moodle_exception('fetchfailed', 'qbank_leetcodeimport', '', 'problem list');
        }

        $cache->set('frontend', $map);
        self::$idmap = $map;
        return $map;
    }

    /**
     * @param string $query
     * @param array $variables
     * @param string $operation
     * @return array
     */
    private function graphql(string $query, array $variables, string $operation): array {
        $payload = [
            'operationName' => $operation,
            'variables' => $variables,
            'query' => $query,
        ];
        $json = $this->http_json('POST', $this->graphql, $payload, true);
        if (!empty($json['errors'])) {
            $msg = $json['errors'][0]['message'] ?? 'GraphQL error';
            throw new \moodle_exception('fetchfailed', 'qbank_leetcodeimport', '', $msg);
        }
        return $json['data'] ?? [];
    }

    /**
     * @param string $method
     * @param string $url
     * @param array|null $payload
     * @param bool $auth
     * @return array
     */
    private function http_json(string $method, string $url, ?array $payload, bool $auth): array {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; Moodle-qbank_leetcodeimport/0.1)',
            'Referer: https://leetcode.com/',
            'Origin: https://leetcode.com',
        ];

        $session = (string) get_config('qbank_leetcodeimport', 'leetcode_session');
        $csrf = (string) get_config('qbank_leetcodeimport', 'leetcode_csrf');
        if ($auth && ($session !== '' || $csrf !== '')) {
            $cookie = [];
            if ($csrf !== '') {
                $cookie[] = 'csrftoken=' . $csrf;
                $headers[] = 'x-csrftoken: ' . $csrf;
            }
            if ($session !== '') {
                $cookie[] = 'LEETCODE_SESSION=' . $session;
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookie);
        }

        $curl = new \curl();
        $curl->setHeader($headers);
        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ];

        if (strtoupper($method) === 'POST') {
            $raw = $curl->post($url, json_encode($payload), $options);
        } else {
            $raw = $curl->get($url, [], $options);
        }

        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($code < 200 || $code >= 300 || $raw === false || $raw === '') {
            throw new \moodle_exception(
                'fetchfailed',
                'qbank_leetcodeimport',
                '',
                'HTTP ' . $code
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('fetchfailed', 'qbank_leetcodeimport', '', 'invalid JSON');
        }
        return $decoded;
    }
}
