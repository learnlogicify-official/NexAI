<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Catalogue of all question bank contexts.
 *
 * @package    local_nexqbank
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexqbank\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Build a site-wide list of question banks.
 */
class catalog {

    public const LEVEL_ALL = 'all';
    public const LEVEL_SYSTEM = 'system';
    public const LEVEL_COURSECAT = 'coursecat';
    public const LEVEL_COURSE = 'course';
    public const LEVEL_ACTIVITY = 'activity';

    /**
     * @return array<string,string>
     */
    public static function level_options(): array {
        return [
            self::LEVEL_ALL => get_string('all', 'local_nexqbank'),
            self::LEVEL_SYSTEM => get_string('system', 'local_nexqbank'),
            self::LEVEL_COURSECAT => get_string('coursecat', 'local_nexqbank'),
            self::LEVEL_COURSE => get_string('course', 'local_nexqbank'),
            self::LEVEL_ACTIVITY => get_string('activity', 'local_nexqbank'),
        ];
    }

    /**
     * Map filter key → contextlevel.
     *
     * @param string $level
     * @return int[]|null null = all
     */
    public static function contextlevels_for_filter(string $level): ?array {
        switch ($level) {
            case self::LEVEL_SYSTEM:
                return [CONTEXT_SYSTEM];
            case self::LEVEL_COURSECAT:
                return [CONTEXT_COURSECAT];
            case self::LEVEL_COURSE:
                return [CONTEXT_COURSE];
            case self::LEVEL_ACTIVITY:
                return [CONTEXT_MODULE];
            default:
                return null;
        }
    }

    /**
     * @param string $level
     * @param string $search
     * @return array{banks: array, totals: array}
     */
    public static function get_banks(string $level = self::LEVEL_ALL, string $search = ''): array {
        global $DB;

        $search = trim($search);
        $levels = self::contextlevels_for_filter($level);

        $params = [];
        $levelsql = '';
        if ($levels !== null) {
            list($insql, $inparams) = $DB->get_in_or_equal($levels, SQL_PARAMS_NAMED, 'lvl');
            $levelsql = " AND ctx.contextlevel {$insql}";
            $params += $inparams;
        } else {
            list($insql, $inparams) = $DB->get_in_or_equal(
                [CONTEXT_SYSTEM, CONTEXT_COURSECAT, CONTEXT_COURSE, CONTEXT_MODULE],
                SQL_PARAMS_NAMED,
                'lvl'
            );
            $levelsql = " AND ctx.contextlevel {$insql}";
            $params += $inparams;
        }

        $hasentries = $DB->get_manager()->table_exists('question_bank_entries');

        if ($hasentries) {
            $qcountsql = "SELECT COUNT(DISTINCT qbe.id)
                            FROM {question_categories} qc2
                            JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc2.id
                           WHERE qc2.contextid = ctx.id
                             AND qc2.parent <> 0";
        } else {
            $qcountsql = "SELECT COUNT(DISTINCT q.id)
                            FROM {question_categories} qc2
                            JOIN {question} q ON q.category = qc2.id
                           WHERE qc2.contextid = ctx.id
                             AND qc2.parent <> 0
                             AND q.parent = 0";
        }

        $sql = "SELECT ctx.id AS contextid,
                       ctx.contextlevel,
                       ctx.instanceid,
                       ctx.path,
                       (SELECT COUNT(1)
                          FROM {question_categories} qc
                         WHERE qc.contextid = ctx.id
                           AND qc.parent <> 0) AS categorycount,
                       ($qcountsql) AS questioncount,
                       (SELECT MIN(qc3.id)
                          FROM {question_categories} qc3
                         WHERE qc3.contextid = ctx.id
                           AND qc3.parent <> 0) AS firstcategoryid,
                       (SELECT MIN(qc4.id)
                          FROM {question_categories} qc4
                         WHERE qc4.contextid = ctx.id
                           AND qc4.parent = 0) AS topcategoryid
                  FROM {context} ctx
                 WHERE EXISTS (
                        SELECT 1 FROM {question_categories} qx WHERE qx.contextid = ctx.id
                       )
                       $levelsql
              ORDER BY ctx.contextlevel ASC, ctx.id ASC";

        $rows = $DB->get_records_sql($sql, $params);
        $banks = [];
        $totals = [
            'banks' => 0,
            'questions' => 0,
            'categories' => 0,
        ];

        foreach ($rows as $row) {
            $bank = self::hydrate_bank($row);
            if ($search !== '' && !self::matches_search($bank, $search)) {
                continue;
            }
            $banks[] = $bank;
            $totals['banks']++;
            $totals['questions'] += (int) $bank['questioncount'];
            $totals['categories'] += (int) $bank['categorycount'];
        }

        return ['banks' => $banks, 'totals' => $totals];
    }

    /**
     * @param \stdClass $row
     * @return array
     */
    private static function hydrate_bank(\stdClass $row): array {
        global $DB;

        $contextid = (int) $row->contextid;
        $level = (int) $row->contextlevel;
        $instanceid = (int) $row->instanceid;

        $levelkey = self::LEVEL_SYSTEM;
        $levellabel = get_string('system', 'local_nexqbank');
        $name = get_string('system', 'local_nexqbank');
        $pathlabel = get_string('system', 'local_nexqbank');
        $courseid = SITEID;
        $cmid = 0;
        $modname = '';
        $extra = '';

        try {
            $context = \context::instance_by_id($contextid, MUST_EXIST);
        } catch (\Throwable $e) {
            $context = null;
        }

        if ($level === CONTEXT_SYSTEM) {
            $levelkey = self::LEVEL_SYSTEM;
            $levellabel = get_string('system', 'local_nexqbank');
            $name = get_string('system', 'local_nexqbank');
            $pathlabel = $context ? $context->get_context_name(true, true) : $name;
            $courseid = SITEID;
        } else if ($level === CONTEXT_COURSECAT) {
            $levelkey = self::LEVEL_COURSECAT;
            $levellabel = get_string('coursecat', 'local_nexqbank');
            $cat = $DB->get_record('course_categories', ['id' => $instanceid]);
            $name = $cat ? format_string($cat->name) : ('Category #' . $instanceid);
            $pathlabel = $context ? $context->get_context_name(true, true) : $name;
            $courseid = SITEID;
        } else if ($level === CONTEXT_COURSE) {
            $levelkey = self::LEVEL_COURSE;
            $levellabel = get_string('course', 'local_nexqbank');
            $course = $DB->get_record('course', ['id' => $instanceid]);
            $name = $course ? format_string($course->fullname) : ('Course #' . $instanceid);
            $pathlabel = $course
                ? ($course->shortname . ' · ' . format_string($course->fullname))
                : $name;
            $courseid = $instanceid > 0 ? $instanceid : SITEID;
        } else if ($level === CONTEXT_MODULE) {
            $levelkey = self::LEVEL_ACTIVITY;
            $levellabel = get_string('activity', 'local_nexqbank');
            $cm = $DB->get_record('course_modules', ['id' => $instanceid]);
            if ($cm) {
                $courseid = (int) $cm->course;
                $cmid = (int) $cm->id;
                $modname = (string) ($DB->get_field('modules', 'name', ['id' => $cm->module]) ?: '');
                $activityname = '';
                if ($modname && $DB->get_manager()->table_exists($modname)) {
                    $activityname = (string) $DB->get_field($modname, 'name', ['id' => $cm->instance]);
                }
                $coursename = (string) $DB->get_field('course', 'shortname', ['id' => $courseid]);
                $name = $activityname !== '' ? format_string($activityname) : ($modname . ' #' . $cm->instance);
                $pathlabel = trim($coursename . ' → ' . ($modname ? $modname . ': ' : '') . $name);
                $extra = $modname;
            } else {
                $name = 'CM #' . $instanceid;
                $pathlabel = $name;
            }
        }

        $catid = (int) ($row->firstcategoryid ?: $row->topcategoryid ?: 0);
        $openurl = self::bank_url($level, $courseid, $cmid, $catid, $contextid);

        return [
            'contextid' => $contextid,
            'contextlevel' => $level,
            'levelkey' => $levelkey,
            'levellabel' => $levellabel,
            'name' => $name,
            'path' => $pathlabel,
            'modname' => $extra,
            'categorycount' => (int) $row->categorycount,
            'questioncount' => (int) $row->questioncount,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'openurl' => $openurl->out(false),
            'isempty' => ((int) $row->questioncount === 0),
            'searchblob' => strtolower($name . ' ' . $pathlabel . ' ' . $extra . ' ' . $contextid . ' ' . $levellabel),
        ];
    }

    /**
     * @param array $bank
     * @param string $search
     * @return bool
     */
    private static function matches_search(array $bank, string $search): bool {
        $q = strtolower($search);
        return strpos((string) $bank['searchblob'], $q) !== false;
    }

    /**
     * Build a Moodle question bank edit URL for a context.
     *
     * @param int $level
     * @param int $courseid
     * @param int $cmid
     * @param int $categoryid
     * @param int $contextid
     * @return \moodle_url
     */
    public static function bank_url(
        int $level,
        int $courseid,
        int $cmid,
        int $categoryid,
        int $contextid
    ): \moodle_url {
        $params = [];
        if ($level === CONTEXT_MODULE && $cmid > 0) {
            $params['cmid'] = $cmid;
        } else {
            $params['courseid'] = $courseid > 0 ? $courseid : SITEID;
        }
        if ($categoryid > 0 && $contextid > 0) {
            $params['cat'] = $categoryid . ',' . $contextid;
        }
        return new \moodle_url('/question/edit.php', $params);
    }

    /**
     * Mustache-ready export.
     *
     * @param array $banks
     * @param array $totals
     * @param string $level
     * @param string $search
     * @return array
     */
    public static function export_for_template(array $banks, array $totals, string $level, string $search): array {
        $filters = [];
        foreach (self::level_options() as $key => $label) {
            $filters[] = [
                'key' => $key,
                'label' => $label,
                'selected' => ($key === $level),
                'url' => (new \moodle_url('/local/nexqbank/index.php', [
                    'level' => $key,
                    'q' => $search,
                ]))->out(false),
            ];
        }

        $rows = [];
        foreach ($banks as $b) {
            $rows[] = [
                'contextid' => $b['contextid'],
                'levelkey' => $b['levelkey'],
                'levellabel' => $b['levellabel'],
                'name' => $b['name'],
                'path' => $b['path'],
                'modname' => $b['modname'],
                'hasmodname' => $b['modname'] !== '',
                'categorycount' => $b['categorycount'],
                'questioncount' => $b['questioncount'],
                'openurl' => $b['openurl'],
                'isempty' => $b['isempty'],
            ];
        }

        return [
            'title' => get_string('pagetitle', 'local_nexqbank'),
            'subtitle' => get_string('pagesubtitle', 'local_nexqbank'),
            'search' => $search,
            'searchplaceholder' => get_string('searchplaceholder', 'local_nexqbank'),
            'searchlabel' => get_string('search', 'local_nexqbank'),
            'formaction' => (new \moodle_url('/local/nexqbank/index.php'))->out(false),
            'level' => $level,
            'filters' => $filters,
            'banks' => $rows,
            'hasbanks' => !empty($rows),
            'nobanks' => get_string('nobanks', 'local_nexqbank'),
            'summary' => get_string('countsummary', 'local_nexqbank', (object) $totals),
            'label_level' => get_string('level', 'local_nexqbank'),
            'label_name' => get_string('name', 'local_nexqbank'),
            'label_path' => get_string('path', 'local_nexqbank'),
            'label_categories' => get_string('categories', 'local_nexqbank'),
            'label_questions' => get_string('questions', 'local_nexqbank'),
            'label_actions' => get_string('actions', 'local_nexqbank'),
            'label_open' => get_string('open', 'local_nexqbank'),
            'label_empty' => get_string('emptybank', 'local_nexqbank'),
            'label_contextid' => get_string('contextid', 'local_nexqbank'),
            'sesskey' => sesskey(),
        ];
    }
}
