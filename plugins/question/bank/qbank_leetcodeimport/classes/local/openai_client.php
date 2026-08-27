<?php
// This file is part of Moodle - http://moodle.org/
/**
 * OpenAI client for LeetCode → CodeRunner conversion.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Convert a LeetCode problem into structured CodeRunner fields via OpenAI.
 */
class openai_client {

    /**
     * Recommended models for the import form dropdown.
     *
     * @return array<string,string>
     */
    public static function model_options(): array {
        return [
            'gpt-4o' => 'gpt-4o (recommended — best quality)',
            'gpt-4.1' => 'gpt-4.1 (strong)',
            'gpt-4o-mini' => 'gpt-4o-mini (faster / cheaper)',
            'o4-mini' => 'o4-mini (reasoning, if available)',
            'o3-mini' => 'o3-mini (reasoning, if available)',
        ];
    }

    /**
     * @param array $problem From leetcode_client::fetch_problem
     * @param array $options language, coderunnertype, generatehiddentests, hiddentestcount, includeanswer, model, usestdin
     * @return array Validated conversion payload
     */
    public function convert_problem(array $problem, array $options): array {
        $apikey = trim((string) get_config('qbank_leetcodeimport', 'openai_apikey'));
        if ($apikey === '') {
            throw new \moodle_exception('missingapikey', 'qbank_leetcodeimport');
        }

        $base = rtrim((string) (get_config('qbank_leetcodeimport', 'openai_baseurl')
            ?: 'https://api.openai.com/v1'), '/');
        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = (string) (get_config('qbank_leetcodeimport', 'openai_model') ?: 'gpt-4o');
        }

        $language = (string) ($options['language'] ?? 'python3');
        $crtype = (string) ($options['coderunnertype'] ?? 'python3');
        $usestdin = !empty($options['usestdin'])
            || prototypes::uses_stdin($crtype, !empty($options['forcestdin']));
        $hidden = !empty($options['generatehiddentests']);
        $hiddencount = max(0, (int) ($options['hiddentestcount'] ?? 4));
        // Default ON — Answer box should get a full working solution.
        $includeanswer = !array_key_exists('includeanswer', $options) || !empty($options['includeanswer']);

        $snippet = $this->pick_snippet($problem, $language);
        $user = $this->build_user_prompt(
            $problem,
            $snippet,
            $language,
            $crtype,
            $usestdin,
            $hidden,
            $hiddencount,
            $includeanswer
        );

        $json = $this->chat_json($base, $apikey, $model, [
            ['role' => 'system', 'content' => $this->system_prompt($usestdin, $language)],
            ['role' => 'user', 'content' => $user],
        ], $problem);

        $normalized = $this->normalize($json, $problem, $snippet, $includeanswer, $usestdin);

        $mintests = 1 + ($hidden ? max(2, $hiddencount) : 2);
        $needsrepair = count($normalized['testcases']) < $mintests
            || ($includeanswer && trim((string) $normalized['answer']) === '');

        if ($needsrepair) {
            $repairuser = $this->build_repair_prompt($problem, $normalized, $language, $usestdin, $hiddencount, $includeanswer);
            $repaired = $this->chat_json($base, $apikey, $model, [
                ['role' => 'system', 'content' => $this->system_prompt($usestdin, $language)],
                ['role' => 'user', 'content' => $user],
                ['role' => 'assistant', 'content' => json_encode($json)],
                ['role' => 'user', 'content' => $repairuser],
            ], $problem);
            // Merge: prefer repaired answer/tests when present.
            if (!empty($repaired['answer'])) {
                $json['answer'] = $repaired['answer'];
            }
            if (!empty($repaired['testcases']) && is_array($repaired['testcases'])
                    && count($repaired['testcases']) >= count($json['testcases'] ?? [])) {
                $json['testcases'] = $repaired['testcases'];
            }
            if (!empty($repaired['examples']) && is_array($repaired['examples'])) {
                $json['examples'] = $repaired['examples'];
            }
            foreach (['problem_statement_html', 'input_format', 'output_format', 'constraints', 'rules', 'explanation_html'] as $k) {
                if (!empty($repaired[$k])) {
                    $json[$k] = $repaired[$k];
                }
            }
            $normalized = $this->normalize($json, $problem, $snippet, $includeanswer, $usestdin);
        }

        return $normalized;
    }

    /**
     * @param string $base
     * @param string $apikey
     * @param string $model
     * @param array $messages
     * @param array $problem
     * @return array
     */
    private function chat_json(string $base, string $apikey, string $model, array $messages, array $problem): array {
        $body = [
            'model' => $model,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];
        // Reasoning models often reject temperature / prefer max_completion_tokens.
        $isreasoning = (bool) preg_match('/^o[0-9]/i', $model);
        if ($isreasoning) {
            $body['max_completion_tokens'] = 16000;
        } else {
            $body['temperature'] = 0.15;
            $body['max_tokens'] = 12000;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey,
        ]);
        $raw = $curl->post($base . '/chat/completions', json_encode($body), [
            'CURLOPT_TIMEOUT' => 300,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ]);
        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($code < 200 || $code >= 300 || !$raw) {
            $detail = is_string($raw) ? substr($raw, 0, 400) : '';
            throw new \moodle_exception(
                'openaifailed',
                'qbank_leetcodeimport',
                '',
                ($problem['titleSlug'] ?? '?') . " HTTP {$code} {$detail}"
            );
        }

        $decoded = json_decode($raw, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') {
            throw new \moodle_exception(
                'openaifailed',
                'qbank_leetcodeimport',
                '',
                ($problem['titleSlug'] ?? '?') . ' empty content'
            );
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new \moodle_exception(
                'openaifailed',
                'qbank_leetcodeimport',
                '',
                ($problem['titleSlug'] ?? '?') . ' invalid JSON'
            );
        }
        return $json;
    }

    /**
     * @param bool $usestdin
     * @param string $language
     * @return string
     */
    private function system_prompt(bool $usestdin, string $language = 'python3'): string {
        $lang = $language !== '' ? $language : 'python3';
        $iotule = $usestdin
            ? <<<IO
I/O MODE (REQUIRED):
- Student writes a FULL program in {$lang} that reads stdin and writes stdout.
- Every testcase MUST have non-empty "stdin" and exact "expected" stdout.
- "testcode" MUST always be an empty string "".
- Redesign LeetCode function-style problems as competitive-programming stdin/stdout.
- Do NOT keep LeetCode class Solution signatures in the student-facing statement.
- The "answer" field MUST be a complete, runnable {$lang} program (not a fragment) that solves the problem and matches every testcase expected output.
IO
            : <<<FN
FUNCTION MODE:
- Use testcode drivers that call the student function/method.
- stdin may be empty when unused.
- The "answer" field MUST be a complete correct solution in {$lang}.
FN;

        return <<<PROMPT
You are an expert competitive programming author converting LeetCode problems into Moodle CodeRunner questions.

{$iotule}

Return ONLY a JSON object with this shape:
{
  "name": "short title (problem name only)",
  "difficulty": "Easy|Medium|Hard|Veryhard",
  "likes": 0,
  "success_rate": "50.0%",
  "problem_statement_html": "<p>Narrative rewritten clearly…</p>",
  "rules_intro": "optional bold intro before rules bullets",
  "rules": ["bullet rule 1"],
  "constraints": ["1 <= n <= 1e5"],
  "input_format": ["The First line contains …"],
  "output_format": ["The First line contains …"],
  "examples": [
    { "input": "…", "output": "…", "explanation_html": "<p>…</p>" }
  ],
  "explanation_html": "<p>…</p>",
  "answerpreload": "",
  "answer": "FULL working source code",
  "testcases": [
    {
      "testcode": "",
      "stdin": "…",
      "expected": "…",
      "extra": "",
      "display": "SHOW",
      "useasexample": 1,
      "hiderestiffail": 0,
      "mark": 1.0
    }
  ]
}

FORMATTING RULES:
- problem_statement_html: <p> only. NEVER use <code> or <pre>.
- constraints / input_format / output_format / rules: plain strings, no HTML tags.
- Input Format bullets: "The First line contains…"
- examples must align with the first useasexample testcases.

ANSWER RULES (CRITICAL):
- "answer" is REQUIRED and must be COMPLETE (imports, full read of stdin, full algorithm, print results).
- It must pass EVERY testcase you emit (expected must match what the answer program prints).
- Prefer clear, correct O(optimal) solutions; no pseudocode; no placeholders like "..." or "TODO".
- Leave answerpreload empty (or a tiny stub) so the Answer field holds the real solution.

TESTCASE RULES (CRITICAL):
- Emit ALL LeetCode public examples as useasexample=1, display=SHOW, hiderestiffail=0.
- Add at least 3–5 more tests (edge cases + larger cases): useasexample=0, display=SHOW, hiderestiffail=1.
- Cover edges: empty/minimal input, single element, duplicates, extremes from constraints.
- Large tests should stress efficient algorithms (near constraints when feasible).
- expected must be exact stdout (no trailing spaces on lines; newlines only as needed).
- Never invent contradictory expected values — compute them from the algorithm.
- Minimum total testcases: 4 (more for Hard problems).
PROMPT;
    }

    /**
     * @param array $problem
     * @param array $normalized
     * @param string $language
     * @param bool $usestdin
     * @param int $hiddencount
     * @param bool $includeanswer
     * @return string
     */
    private function build_repair_prompt(
        array $problem,
        array $normalized,
        string $language,
        bool $usestdin,
        int $hiddencount,
        bool $includeanswer
    ): string {
        $issues = [];
        if ($includeanswer && trim((string) ($normalized['answer'] ?? '')) === '') {
            $issues[] = 'answer is empty — provide a FULL working ' . $language . ' solution';
        } else if ($includeanswer && strlen(trim((string) $normalized['answer'])) < 40) {
            $issues[] = 'answer looks incomplete — expand to a full runnable program';
        }
        $tc = count($normalized['testcases'] ?? []);
        if ($tc < 4) {
            $issues[] = "only {$tc} testcases — expand to at least 4–" . (4 + $hiddencount)
                . ' with correct expected outputs';
        }
        $issues[] = 'Re-check every expected against the answer program.';
        $issues[] = $usestdin
            ? 'Keep stdin/stdout style; testcode must be empty.'
            : 'Keep function/testcode drivers consistent.';

        return "Your previous JSON is incomplete or weak.\nFix ALL of these issues and return the FULL JSON again:\n- "
            . implode("\n- ", $issues)
            . "\n\nCurrent answer length: " . strlen((string) ($normalized['answer'] ?? ''))
            . "\nCurrent testcase count: {$tc}\nProblem: " . ($problem['title'] ?? '');
    }

    /**
     * @param array $problem
     * @param string $snippet
     * @param string $language
     * @param string $crtype
     * @param bool $usestdin
     * @param bool $hidden
     * @param int $hiddencount
     * @param bool $includeanswer
     * @return string
     */
    private function build_user_prompt(
        array $problem,
        string $snippet,
        string $language,
        string $crtype,
        bool $usestdin,
        bool $hidden,
        int $hiddencount,
        bool $includeanswer
    ): string {
        $tags = [];
        foreach ($problem['topicTags'] ?? [] as $t) {
            if (!empty($t['name'])) {
                $tags[] = $t['name'];
            }
        }

        $parts = [];
        $parts[] = 'CodeRunner type: ' . $crtype;
        $parts[] = 'Language for answer program: ' . $language;
        $parts[] = 'Test style: ' . ($usestdin ? 'STDIN/STDOUT only (testcode empty)' : 'function/testcode drivers');
        $parts[] = 'Reference answer in "answer" field: '
            . ($includeanswer ? 'REQUIRED — full working program' : 'optional');
        $parts[] = 'Extra efficiency/edge tests: ' . ($hidden
            ? ('yes — at least ' . max(3, $hiddencount) . ' non-example tests with display=SHOW and hiderestiffail=1')
            : 'add at least 2 non-example edge tests');
        $parts[] = 'Title: ' . ($problem['title'] ?? '');
        $parts[] = 'Frontend ID: ' . ($problem['questionFrontendId'] ?? '');
        $parts[] = 'Slug: ' . ($problem['titleSlug'] ?? '');
        $parts[] = 'Difficulty: ' . ($problem['difficulty'] ?? '');
        $parts[] = 'Likes: ' . ($problem['likes'] ?? 0);
        $parts[] = 'acRate: ' . ($problem['acRate'] ?? '');
        $parts[] = 'Tags: ' . implode(', ', $tags);
        $parts[] = "Original LeetCode HTML (rewrite into Paper style; do not copy layout):\n"
            . ($problem['content'] ?? '');
        $parts[] = "exampleTestcases:\n" . ($problem['exampleTestcases'] ?? '');
        $parts[] = "sampleTestCase:\n" . ($problem['sampleTestCase'] ?? '');
        if (!empty($problem['hints']) && is_array($problem['hints'])) {
            $parts[] = "hints:\n" . implode("\n", $problem['hints']);
        }
        if (!$usestdin) {
            $parts[] = "Official snippet (you may adapt):\n" . $snippet;
        } else {
            $parts[] = 'answerpreload: leave empty. Put the complete ' . $language
                . ' stdin/stdout solution only in "answer".';
        }
        $parts[] = 'Quality bar: answer must compile/run and match every expected; tests must be diverse and complete.';

        return implode("\n\n", $parts);
    }

    /**
     * @param array $problem
     * @param string $language
     * @return string
     */
    private function pick_snippet(array $problem, string $language): string {
        $want = strtolower($language);
        $aliases = [
            'python3' => ['python3', 'python'],
            'python' => ['python3', 'python'],
            'java' => ['java'],
            'java_method' => ['java'],
            'cpp' => ['cpp', 'c++'],
            'cpp_function' => ['cpp', 'c++'],
            'c' => ['c'],
            'c_function' => ['c'],
            'javascript' => ['javascript', 'js'],
            'nodejs' => ['javascript', 'js'],
            'multilanguage' => ['python3', 'python'],
        ];
        $targets = $aliases[$want] ?? [$want];

        $snippets = $problem['codeSnippets'] ?? [];
        foreach ($targets as $t) {
            foreach ($snippets as $s) {
                $slug = strtolower((string) ($s['langSlug'] ?? ''));
                $lang = strtolower((string) ($s['lang'] ?? ''));
                if ($slug === $t || $lang === $t) {
                    return (string) ($s['code'] ?? '');
                }
            }
        }
        return (string) ($snippets[0]['code'] ?? '');
    }

    /**
     * @param array $json
     * @param array $problem
     * @param string $snippet
     * @param bool $includeanswer
     * @param bool $usestdin
     * @return array
     */
    private function normalize(
        array $json,
        array $problem,
        string $snippet,
        bool $includeanswer,
        bool $usestdin
    ): array {
        $name = trim((string) ($json['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($problem['title'] ?? 'Problem'));
        }

        $html = question_formatter::render($json, $problem);
        if (trim(strip_tags($html)) === '') {
            $html = (string) ($json['questiontext_html'] ?? $problem['content'] ?? '<p>Problem</p>');
        }

        $preload = (string) ($json['answerpreload'] ?? '');
        if ($usestdin) {
            $isleetstub = stripos($preload, 'class Solution') !== false
                || (stripos($preload, 'def ') !== false
                    && stripos($preload, 'input(') === false
                    && stripos($preload, 'stdin') === false);
            if ($isleetstub) {
                $preload = '';
            }
        } else if (trim($preload) === '') {
            $preload = $snippet;
        }

        $answer = $includeanswer ? trim((string) ($json['answer'] ?? '')) : '';
        // If model put the only solution in preload, move it to answer.
        if ($includeanswer && $answer === '' && trim($preload) !== '' && strlen($preload) > 60) {
            $answer = $preload;
            $preload = '';
        }

        $tests = [];
        $rawtests = $json['testcases'] ?? [];
        if (!is_array($rawtests) || !$rawtests) {
            $tests[] = [
                'testcode' => '',
                'stdin' => (string) ($problem['sampleTestCase'] ?? ''),
                'expected' => '',
                'extra' => '',
                'display' => 'SHOW',
                'useasexample' => 1,
                'hiderestiffail' => 0,
                'mark' => 1.0,
            ];
        } else {
            foreach ($rawtests as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $testcode = (string) ($t['testcode'] ?? '');
                $stdin = (string) ($t['stdin'] ?? '');
                if ($usestdin) {
                    $testcode = '';
                }
                $useasexample = !empty($t['useasexample']) ? 1 : 0;
                $expected = (string) ($t['expected'] ?? '');
                if ($usestdin && $stdin === '' && $expected === '') {
                    continue;
                }
                $tests[] = [
                    'testcode' => $testcode,
                    'stdin' => $stdin,
                    'expected' => $expected,
                    'extra' => (string) ($t['extra'] ?? ''),
                    'display' => 'SHOW',
                    'useasexample' => $useasexample,
                    'hiderestiffail' => $useasexample ? 0 : 1,
                    'mark' => (float) ($t['mark'] ?? 1.0),
                ];
            }
        }

        if (!empty($json['examples']) && is_array($json['examples'])) {
            $haveexample = false;
            foreach ($tests as $t) {
                if (!empty($t['useasexample'])) {
                    $haveexample = true;
                    break;
                }
            }
            if (!$haveexample && $usestdin) {
                $extra = [];
                foreach ($json['examples'] as $ex) {
                    if (!is_array($ex)) {
                        continue;
                    }
                    $extra[] = [
                        'testcode' => '',
                        'stdin' => (string) ($ex['input'] ?? ''),
                        'expected' => (string) ($ex['output'] ?? ''),
                        'extra' => '',
                        'display' => 'SHOW',
                        'useasexample' => 1,
                        'hiderestiffail' => 0,
                        'mark' => 1.0,
                    ];
                }
                $tests = array_merge($extra, $tests);
            }
        }

        foreach ($tests as &$tc) {
            $tc['display'] = 'SHOW';
            $tc['hiderestiffail'] = !empty($tc['useasexample']) ? 0 : 1;
        }
        unset($tc);

        return [
            'name' => $name,
            'questiontext_html' => question_formatter::clean_html($html),
            'answerpreload' => $preload,
            'answer' => $answer,
            'testcases' => $tests,
            'tags' => question_formatter::topic_tags($problem, $json),
            'meta' => [
                'frontend_id' => (string) ($problem['questionFrontendId'] ?? ''),
                'slug' => (string) ($problem['titleSlug'] ?? ''),
                'url' => (string) ($problem['_url'] ?? ''),
                'difficulty' => (string) ($json['difficulty'] ?? $problem['difficulty'] ?? ''),
                'usestdin' => $usestdin ? 1 : 0,
                'model' => '',
            ],
        ];
    }
}
