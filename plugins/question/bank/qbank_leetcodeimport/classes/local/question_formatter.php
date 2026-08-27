<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Render Paper-style question HTML (no &lt;code&gt; tags).
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Build structured problem HTML matching the exam Paper layout.
 */
class question_formatter {

    /**
     * @param array $structured From OpenAI structured fields
     * @param array $problem Raw LeetCode payload
     * @return string HTML
     */
    public static function render(array $structured, array $problem): string {
        $statement = self::clean_html((string) ($structured['problem_statement_html'] ?? ''));
        if (trim(strip_tags($statement)) === '') {
            $plain = trim(html_entity_decode(strip_tags((string) ($problem['content'] ?? '')), ENT_QUOTES, 'UTF-8'));
            $statement = $plain !== '' ? '<p>' . self::esc($plain) . '</p>' : '<p></p>';
        }

        $rulesintro = trim((string) ($structured['rules_intro'] ?? ''));
        $rules = self::string_list($structured['rules'] ?? []);
        $constraints = self::string_list($structured['constraints'] ?? []);
        $inputformat = self::string_list($structured['input_format'] ?? []);
        $outputformat = self::string_list($structured['output_format'] ?? []);
        $examples = is_array($structured['examples'] ?? null) ? $structured['examples'] : [];
        $explanation = self::clean_html((string) ($structured['explanation_html'] ?? ''));

        $html = '';
        $html .= '<p><strong>Problem Statement:</strong></p>';
        $html .= $statement;

        // Images are injected later via image_helper (@@PLUGINFILE@@).

        if ($rulesintro !== '') {
            $html .= '<p><strong>' . self::esc($rulesintro) . '</strong></p>';
        }
        if ($rules) {
            $html .= '<ul>';
            foreach ($rules as $r) {
                $html .= '<li>' . self::esc($r) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($constraints) {
            $html .= '<p><strong>Constraints:</strong></p><ul>';
            foreach ($constraints as $c) {
                $html .= '<li>' . self::esc($c) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($inputformat) {
            $html .= '<p><strong>Input Format:</strong></p><ul>';
            foreach ($inputformat as $line) {
                $html .= '<li>' . self::esc($line) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($outputformat) {
            $html .= '<p><strong>Output Format:</strong></p><ul>';
            foreach ($outputformat as $line) {
                $html .= '<li>' . self::esc($line) . '</li>';
            }
            $html .= '</ul>';
        }

        $ei = 1;
        $examplecount = 0;
        foreach ($examples as $ex) {
            if (is_array($ex) && ((string) ($ex['input'] ?? '') !== '' || (string) ($ex['output'] ?? '') !== '')) {
                $examplecount++;
            }
        }

        foreach ($examples as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $in = (string) ($ex['input'] ?? '');
            $out = (string) ($ex['output'] ?? '');
            if ($in === '' && $out === '') {
                continue;
            }

            $html .= '<p><strong>Example ' . $ei . '</strong></p>';
            $html .= '<p><strong>Input Format:</strong></p>';
            $html .= '<pre class="qbank-lc-io">' . self::esc($in) . '</pre>';
            $html .= '<p><strong>Output Format:</strong></p>';
            $html .= '<pre class="qbank-lc-io">' . self::esc($out) . '</pre>';

            $exexplain = self::clean_html((string) ($ex['explanation_html'] ?? ''));
            if ($exexplain !== '' && trim(strip_tags($exexplain)) !== '') {
                $html .= '<p><strong>Explanation:</strong></p>';
                $html .= $exexplain;
            }
            $ei++;
        }

        if ($explanation !== '' && trim(strip_tags($explanation)) !== '') {
            $html .= '<p><strong>Explanation:</strong></p>';
            $html .= $explanation;
        }

        return $html;
    }

    /**
     * Allow only simple HTML; strip code/script/style tags (unwrap content).
     *
     * @param string $html
     * @return string
     */
    public static function clean_html(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Unwrap disallowed tags but keep inner text.
        $html = preg_replace('#<\s*(code|kbd|samp|tt|script|style)(\s[^>]*)?>#i', '', $html) ?? $html;
        $html = preg_replace('#<\s*/\s*(code|kbd|samp|tt|script|style)\s*>#i', '', $html) ?? $html;
        // Drop on* attributes.
        $html = preg_replace('/\son\w+\s*=\s*("|\').*?\1/i', '', $html) ?? $html;
        return $html;
    }

    /**
     * Collect LeetCode topic names for CodeRunner/Moodle tags.
     *
     * @param array $problem
     * @param array $structured
     * @return string[]
     */
    public static function topic_tags(array $problem, array $structured = []): array {
        $tags = [];
        foreach ($problem['topicTags'] ?? [] as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name !== '') {
                $tags[] = $name;
            }
        }
        foreach ($structured['tags'] ?? [] as $t) {
            $name = trim((string) $t);
            if ($name !== '') {
                $tags[] = $name;
            }
        }
        $difficulty = trim((string) ($problem['difficulty'] ?? ''));
        if ($difficulty !== '') {
            $tags[] = $difficulty;
        }
        $tags = array_values(array_unique(array_map(static function ($t) {
            return trim((string) $t);
        }, $tags)));
        return array_values(array_filter($tags, static fn($t) => $t !== ''));
    }

    /**
     * @param mixed $list
     * @return string[]
     */
    private static function string_list($list): array {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * @param string $s
     * @return string
     */
    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
