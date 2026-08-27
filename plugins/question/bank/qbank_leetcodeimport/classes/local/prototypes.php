<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Discover CodeRunner prototypes available on this site.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * List unique coderunnertype values and flag duplicates.
 */
class prototypes {

    /**
     * Types that should use stdin/stdout (empty testcode).
     *
     * @param string $coderunnertype
     * @param bool $forcestdin
     * @return bool
     */
    public static function uses_stdin(string $coderunnertype, bool $forcestdin = false): bool {
        if ($forcestdin) {
            return true;
        }
        $t = strtolower(trim($coderunnertype));
        if ($t === '') {
            return true;
        }
        if (str_contains($t, 'multilanguage')) {
            return true;
        }
        // Function / method prototypes use testcode drivers.
        if (preg_match('/_function|_method|_class|sql/', $t)) {
            return false;
        }
        return in_array($t, [
            'python3', 'python', 'c', 'cpp', 'java', 'nodejs', 'octave', 'pascal', 'php',
        ], true);
    }

    /**
     * @return array{options: array<string,string>, duplicates: array<string,int>, warnings: string[]}
     */
    public static function catalogue(): array {
        global $DB;

        $options = [];
        $duplicates = [];
        $warnings = [];

        if (!$DB->get_manager()->table_exists('question_coderunner_options')) {
            $fallback = [
                'python3' => 'python3',
                'multilanguage' => 'multilanguage',
            ];
            return ['options' => $fallback, 'duplicates' => [], 'warnings' => [
                get_string('noprototypes', 'qbank_leetcodeimport'),
            ]];
        }

        $sql = "SELECT qco.coderunnertype, COUNT(DISTINCT q.id) AS cnt,
                       MIN(q.id) AS minid, MAX(q.id) AS maxid
                  FROM {question_coderunner_options} qco
                  JOIN {question} q ON q.id = qco.questionid
                 WHERE qco.prototypetype <> 0
                   AND qco.coderunnertype IS NOT NULL
                   AND qco.coderunnertype <> ''
              GROUP BY qco.coderunnertype
              ORDER BY qco.coderunnertype ASC";

        try {
            $rows = $DB->get_records_sql($sql);
        } catch (\Throwable $e) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $type = (string) $row->coderunnertype;
            $cnt = (int) $row->cnt;
            if ($cnt > 1) {
                $duplicates[$type] = $cnt;
                $options[$type] = $type . ' ⚠ duplicate ×' . $cnt;
                $warnings[] = get_string('duplicatetprototype', 'qbank_leetcodeimport', (object) [
                    'type' => $type,
                    'count' => $cnt,
                    'ids' => $row->minid . '…' . $row->maxid,
                ]);
            } else {
                $options[$type] = $type;
            }
        }

        if (!$options) {
            $options = [
                'python3' => 'python3',
                'multilanguage' => 'multilanguage',
            ];
            $warnings[] = get_string('noprototypes', 'qbank_leetcodeimport');
        }

        return [
            'options' => $options,
            'duplicates' => $duplicates,
            'warnings' => $warnings,
        ];
    }
}
