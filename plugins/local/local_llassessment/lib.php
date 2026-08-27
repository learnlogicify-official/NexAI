<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for local_llassessment.
 *
 * @package    local_llassessment
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Whether the arena UI is enabled.
 *
 * @return bool
 */
function local_llassessment_arena_enabled(): bool {
    $enabled = get_config('local_llassessment', 'enablearena');
    return $enabled === false || $enabled === '' || (bool) $enabled;
}

/**
 * Resolve the course format name (PAGE->course->format is often empty on mod pages).
 *
 * @param \stdClass|null $course
 * @return string
 */
function local_llassessment_course_format(?stdClass $course = null): string {
    global $PAGE, $DB;

    $course = $course ?? ($PAGE->course ?? null);
    if (!$course || empty($course->id) || (int) $course->id <= 1) {
        return '';
    }
    if (!empty($course->format)) {
        return (string) $course->format;
    }
    try {
        $format = $DB->get_field('course', 'format', ['id' => (int) $course->id]);
        return $format ? (string) $format : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Plugin CSS URL with a cache-busting revision.
 *
 * Plugin stylesheets are served as plain static files, so without a revision
 * browsers keep serving the previous release's CSS after an upgrade.
 *
 * @param string $path Path under the plugin root, e.g. /local/llassessment/styles/view.css
 * @return moodle_url
 */
function local_llassessment_css_url(string $path): moodle_url {
    $rev = (int) get_config('local_llassessment', 'version');
    return new moodle_url($path, $rev > 0 ? ['v' => $rev] : []);
}

/**
 * @return string light|dark|auto
 */
function local_llassessment_colormode(): string {
    $mode = get_config('local_llassessment', 'colormode');
    if (!in_array($mode, ['light', 'dark', 'auto'], true)) {
        return 'light';
    }
    return $mode;
}

/**
 * True when the current request is a quiz attempt page.
 *
 * @return bool
 */
function local_llassessment_is_attempt_page(): bool {
    global $PAGE, $SCRIPT;

    // Strong signal: Moodle pagetype.
    if (!empty($PAGE) && !empty($PAGE->pagetype) && $PAGE->pagetype === 'mod-quiz-attempt') {
        return true;
    }

    $haystacks = [
        $SCRIPT ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['SCRIPT_FILENAME'] ?? '',
        $_SERVER['PATH_INFO'] ?? '',
        $_SERVER['QUERY_STRING'] ?? '',
    ];

    if (!empty($PAGE) && !empty($PAGE->url)) {
        try {
            $haystacks[] = $PAGE->url->out(false);
            $haystacks[] = $PAGE->url->get_path();
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    foreach ($haystacks as $h) {
        if ($h === '') {
            continue;
        }
        // Match attempt.php anywhere in the path (handles /public, subdirectory installs).
        if (preg_match('~(^|/)mod/quiz/attempt\.php(\?|$|/|$)~i', str_replace('\\', '/', $h))) {
            return true;
        }
        if (stripos($h, 'mod/quiz/attempt.php') !== false) {
            return true;
        }
    }

    // Basename fallback (front-controller setups).
    $base = basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($base === 'attempt.php' && optional_param('attempt', 0, PARAM_INT) > 0) {
        return true;
    }

    return false;
}

/**
 * True when the current request is a quiz review page (post-submit).
 *
 * @return bool
 */
function local_llassessment_is_review_page(): bool {
    global $PAGE, $SCRIPT;

    if (!empty($PAGE) && !empty($PAGE->pagetype) && $PAGE->pagetype === 'mod-quiz-review') {
        return true;
    }

    $haystacks = [
        $SCRIPT ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['SCRIPT_FILENAME'] ?? '',
        $_SERVER['PATH_INFO'] ?? '',
    ];

    if (!empty($PAGE) && !empty($PAGE->url)) {
        try {
            $haystacks[] = $PAGE->url->out(false);
            $haystacks[] = $PAGE->url->get_path();
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    foreach ($haystacks as $h) {
        if ($h === '') {
            continue;
        }
        $norm = str_replace('\\', '/', $h);
        if (preg_match('~(^|/)mod/quiz/review\.php(\?|$|/|$)~i', $norm)) {
            return true;
        }
        if (stripos($norm, 'mod/quiz/review.php') !== false) {
            return true;
        }
    }

    $base = basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($base === 'review.php' && optional_param('attempt', 0, PARAM_INT) > 0) {
        return true;
    }

    return false;
}

/**
 * True when the current request is a quiz activity landing page (view.php).
 *
 * @return bool
 */
function local_llassessment_is_view_page(): bool {
    global $PAGE, $SCRIPT;

    if (!empty($PAGE) && !empty($PAGE->pagetype) && $PAGE->pagetype === 'mod-quiz-view') {
        return true;
    }

    $haystacks = [
        $SCRIPT ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['SCRIPT_FILENAME'] ?? '',
        $_SERVER['PATH_INFO'] ?? '',
    ];

    if (!empty($PAGE) && !empty($PAGE->url)) {
        try {
            $haystacks[] = $PAGE->url->out(false);
            $haystacks[] = $PAGE->url->get_path();
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    foreach ($haystacks as $h) {
        if ($h === '') {
            continue;
        }
        $norm = str_replace('\\', '/', $h);
        if (preg_match('~(^|/)mod/quiz/view\.php(\?|$|/|$)~i', $norm)) {
            return true;
        }
        if (stripos($norm, 'mod/quiz/view.php') !== false) {
            return true;
        }
    }

    $base = basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($base === 'view.php' && !empty($PAGE) && !empty($PAGE->cm) && ($PAGE->cm->modname ?? '') === 'quiz') {
        return true;
    }

    return false;
}

/**
 * True on any quiz module page that shows the activity secondary nav
 * (Quiz / Settings / Questions / Results / Question bank / More).
 *
 * Attempt and review run the fullscreen arena instead.
 *
 * @return bool
 */
function local_llassessment_is_quiz_module_page(): bool {
    global $PAGE, $SCRIPT;

    if (local_llassessment_is_attempt_page() || local_llassessment_is_review_page()) {
        return false;
    }

    if (!empty($PAGE) && !empty($PAGE->cm) && ($PAGE->cm->modname ?? '') === 'quiz') {
        return true;
    }

    // The course module is not always resolved yet when the early hooks fire,
    // so fall back to the script path for anything under /mod/quiz/.
    $haystacks = [
        $SCRIPT ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_FILENAME'] ?? '',
    ];
    foreach ($haystacks as $haystack) {
        if (!is_string($haystack) || $haystack === '') {
            continue;
        }
        if (stripos(str_replace('\\', '/', $haystack), '/mod/quiz/') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Config array passed to AMD init.
 *
 * @return array
 */
function local_llassessment_js_config(): array {
    global $PAGE;

    $quizviewurl = '';
    $courseurl = '';
    $cmid = 0;
    $quizname = '';
    $coursename = '';

    // Prefer the current course-module context (this quiz only).
    if (!empty($PAGE) && !empty($PAGE->cm) && !empty($PAGE->cm->id)) {
        $cmid = (int) $PAGE->cm->id;
        if (!empty($PAGE->cm->name)) {
            $quizname = format_string($PAGE->cm->name);
        }
    }
    if (!$cmid) {
        $cmid = optional_param('cmid', 0, PARAM_INT);
    }
    if (!$cmid && local_llassessment_is_view_page()) {
        // view.php uses id=<cmid>.
        $cmid = optional_param('id', 0, PARAM_INT);
    }
    if (!$cmid) {
        // Some attempt URLs only carry attempt=; resolve via attempt record when possible.
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if ($attemptid) {
            try {
                global $DB;
                $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz', IGNORE_MISSING);
                if ($attempt && !empty($attempt->quiz)) {
                    $cm = get_coursemodule_from_instance('quiz', $attempt->quiz);
                    if ($cm) {
                        $cmid = (int) $cm->id;
                    }
                }
            } catch (Throwable $e) {
                // Ignore — JS fallbacks still apply.
            }
        }
    }
    if ($cmid) {
        $quizviewurl = (new moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false);
        if ($quizname === '') {
            try {
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    $quizname = format_string($cm->name);
                }
            } catch (Throwable $e) {
                // Ignore.
            }
        }
    }

    if (!empty($PAGE) && !empty($PAGE->course) && !empty($PAGE->course->id) && (int) $PAGE->course->id > 1) {
        $courseid = (int) $PAGE->course->id;
        $courseformat = local_llassessment_course_format($PAGE->course);
        $courseparams = ['id' => $courseid];
        // NexCoursePro: reopen the learn shell on this activity.
        if ($courseformat === 'nexcoursepro' && $cmid > 0) {
            $courseparams['cmid'] = $cmid;
        }
        $courseurl = (new moodle_url('/course/view.php', $courseparams))->out(false);
        $coursename = format_string($PAGE->course->fullname);
    } else {
        $courseformat = '';
    }

    $prefercourseback = ($courseformat === 'nexcoursepro');

    $mode = 'attempt';
    if (local_llassessment_is_review_page()) {
        $mode = 'review';
    } else if (local_llassessment_is_view_page()) {
        $mode = 'view';
    }

    // Slot → allocated max marks (for arena question header).
    $slotmarks = [];
    if ($cmid > 0) {
        try {
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
            if ($cm && !empty($cm->instance)) {
                $slotmarks = local_llassessment_quiz_slot_maxmarks((int) $cm->instance);
            }
        } catch (Throwable $e) {
            $slotmarks = [];
        }
    }

    return [
        'colorMode' => local_llassessment_colormode(),
        'brandColor' => get_config('local_llassessment', 'brandcolor') ?: '#2563eb',
        'finishLabel' => get_string('finishattempt', 'local_llassessment'),
        'questionsLabel' => get_string('questions', 'local_llassessment'),
        'toggleThemeLabel' => get_string('toggletheme', 'local_llassessment'),
        'backToCourseLabel' => get_string('backtocourse', 'local_llassessment'),
        'assessmentLabel' => get_string('assessmentlabel', 'local_llassessment'),
        'jumpLabel' => get_string('jumpto', 'local_llassessment'),
        'quizViewUrl' => $quizviewurl,
        'courseUrl' => $courseurl,
        'courseName' => $coursename,
        'quizName' => $quizname,
        'cmid' => $cmid,
        'preferCourseBack' => $prefercourseback,
        'courseFormat' => $courseformat,
        'mode' => $mode,
        'slotMarks' => $slotmarks,
        'sections' => $mode === 'view' && $cmid ? local_llassessment_quiz_section_outline($cmid) : [],
        'sectionsTitle' => get_string('sectionoutline', 'local_llassessment'),
        'sectionCol' => get_string('sectioncol', 'local_llassessment'),
        'questionsCol' => get_string('questions', 'local_llassessment'),
        'typesCol' => get_string('questiontypes', 'local_llassessment'),
    ];
}

/**
 * Human-readable question-type label.
 *
 * @param string $qtype
 * @return string
 */
function local_llassessment_qtype_label(string $qtype): string {
    $qtype = strtolower(trim($qtype));
    $aliases = [
        'coderunner' => get_string('qtypecoding', 'local_llassessment'),
        'multichoice' => get_string('qtypemultichoice', 'local_llassessment'),
        'truefalse' => get_string('qtypetruefalse', 'local_llassessment'),
        'shortanswer' => get_string('qtypeshortanswer', 'local_llassessment'),
        'numerical' => get_string('qtypenumerical', 'local_llassessment'),
        'match' => get_string('qtypematch', 'local_llassessment'),
        'essay' => get_string('qtypeessay', 'local_llassessment'),
        'random' => get_string('qtyperandom', 'local_llassessment'),
    ];
    if (isset($aliases[$qtype])) {
        return $aliases[$qtype];
    }
    try {
        $label = get_string('pluginname', 'qtype_' . $qtype);
        if ($label !== '' && $label !== '[[pluginname]]') {
            return $label;
        }
    } catch (Throwable $e) {
        // Fall through.
    }
    return $qtype !== '' ? ucfirst($qtype) : get_string('qtypeother', 'local_llassessment');
}

/**
 * quiz_slots quiz-id column (Moodle 5: quizid, older: quiz).
 *
 * @return string
 */
function local_llassessment_quiz_slots_quiz_column(): string {
    global $DB;
    static $col = null;
    if ($col !== null) {
        return $col;
    }
    try {
        $columns = $DB->get_columns('quiz_slots');
        if (isset($columns['quizid'])) {
            $col = 'quizid';
        } else if (isset($columns['quiz'])) {
            $col = 'quiz';
        } else {
            $col = 'quizid';
        }
    } catch (Throwable $e) {
        $col = 'quizid';
    }
    return $col;
}

/**
 * Map quiz slot number → max mark allocated (not earned).
 *
 * @param int $quizid
 * @return array<int, float> slot => maxmark
 */
function local_llassessment_quiz_slot_maxmarks(int $quizid): array {
    global $DB;

    if ($quizid < 1) {
        return [];
    }
    $map = [];
    try {
        $quizcol = local_llassessment_quiz_slots_quiz_column();
        $rows = $DB->get_records_sql(
            "SELECT slot, maxmark FROM {quiz_slots} WHERE {$quizcol} = :quizid ORDER BY slot ASC",
            ['quizid' => $quizid]
        );
        foreach ($rows ?: [] as $row) {
            $n = (int) ($row->slot ?? 0);
            if ($n > 0) {
                $map[$n] = (float) ($row->maxmark ?? 0);
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $map;
}

/**
 * Map quiz slot number → question type.
 *
 * @param int $quizid
 * @param int $cmid
 * @return array<int,string>
 */
function local_llassessment_quiz_slot_qtypes(int $quizid, int $cmid): array {
    global $DB;

    $map = [];
    if ($cmid > 0 && class_exists('\\mod_quiz\\question\\bank\\qbank_helper')) {
        try {
            $ctx = context_module::instance($cmid);
            $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $ctx);
            foreach ($slots as $slot) {
                $n = (int) ($slot->slot ?? 0);
                if ($n < 1) {
                    continue;
                }
                $qtype = strtolower(trim((string) ($slot->qtype ?? '')));
                $map[$n] = $qtype !== '' ? $qtype : 'other';
            }
            if ($map) {
                return $map;
            }
        } catch (Throwable $e) {
            $map = [];
        }
    }

    // SQL fallback for Moodle 5 question bank references.
    try {
        $quizcol = local_llassessment_quiz_slots_quiz_column();
        $params = ['quizid' => $quizid];
        $contextjoin = '';
        if ($cmid > 0) {
            $params['ctxid'] = (int) context_module::instance($cmid)->id;
            $contextjoin = ' AND qr.usingcontextid = :ctxid';
        }
        $sql = "SELECT slot.slot, q.qtype
                  FROM {quiz_slots} slot
                  JOIN {question_references} qr
                    ON qr.itemid = slot.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                       {$contextjoin}
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
             LEFT JOIN {question_versions} qv2
                    ON qv2.questionbankentryid = qbe.id
                   AND qv.version < qv2.version
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE slot.{$quizcol} = :quizid
                   AND qv2.id IS NULL
                   AND (qr.version IS NULL OR qv.version = qr.version)";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $n = (int) $row->slot;
            if ($n > 0) {
                $qtype = strtolower(trim((string) $row->qtype));
                $map[$n] = $qtype !== '' ? $qtype : 'other';
            }
        }
    } catch (Throwable $e) {
        // Keep whatever we have.
    }
    return $map;
}

/**
 * Per-section question counts and types for the quiz landing page.
 *
 * @param int $cmid
 * @return array
 */
function local_llassessment_quiz_section_outline(int $cmid): array {
    global $DB;

    if ($cmid < 1) {
        return [];
    }

    try {
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }
        $quizid = (int) $cm->instance;
        if ($quizid < 1) {
            return [];
        }

        $quizcol = local_llassessment_quiz_slots_quiz_column();
        $slotnums = $DB->get_fieldset_sql(
            "SELECT slot FROM {quiz_slots} WHERE {$quizcol} = :quizid ORDER BY slot ASC",
            ['quizid' => $quizid]
        );
        $slotnums = array_map('intval', $slotnums ?: []);
        if (!$slotnums) {
            return [];
        }

        $qtypes = local_llassessment_quiz_slot_qtypes($quizid, $cmid);
        $sectioncol = 'quizid';
        try {
            $cols = $DB->get_columns('quiz_sections');
            if (!isset($cols['quizid']) && isset($cols['quiz'])) {
                $sectioncol = 'quiz';
            }
        } catch (Throwable $e) {
            $sectioncol = 'quizid';
        }
        $sections = $DB->get_records('quiz_sections', [$sectioncol => $quizid], 'firstslot ASC');
        if (!$sections) {
            $sections = [(object) ['heading' => '', 'firstslot' => 1]];
        }

        $ranges = array_values($sections);
        $out = [];
        $index = 1;
        $onlyone = count($ranges) === 1;
        foreach ($ranges as $i => $section) {
            $first = (int) ($section->firstslot ?? 1);
            $next = isset($ranges[$i + 1]) ? (int) $ranges[$i + 1]->firstslot : PHP_INT_MAX;
            $types = [];
            $count = 0;
            foreach ($slotnums as $slotno) {
                if ($slotno < $first || $slotno >= $next) {
                    continue;
                }
                $qtype = $qtypes[$slotno] ?? 'other';
                if ($qtype === 'description') {
                    continue;
                }
                $count++;
                $types[$qtype] = ($types[$qtype] ?? 0) + 1;
            }
            if ($count < 1) {
                $index++;
                continue;
            }

            $heading = trim(format_string((string) ($section->heading ?? '')));
            if ($heading === '') {
                $heading = $onlyone
                    ? get_string('questions', 'local_llassessment')
                    : get_string('sectiondefault', 'local_llassessment', $index);
            }

            $typelist = [];
            foreach ($types as $qtype => $n) {
                $typelist[] = [
                    'qtype' => $qtype,
                    'label' => local_llassessment_qtype_label($qtype),
                    'count' => $n,
                ];
            }
            usort($typelist, static function(array $a, array $b): int {
                return ($b['count'] <=> $a['count']) ?: strcasecmp($a['label'], $b['label']);
            });

            $out[] = [
                'name' => $heading,
                'count' => $count,
                'types' => $typelist,
            ];
            $index++;
        }
        return $out;
    } catch (Throwable $e) {
        debugging('llassessment quiz outline: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return [];
    }
}

/**
 * Visible HTML for the quiz landing-page outline (moved into place by JS).
 *
 * @return string
 */
function local_llassessment_quiz_outline_html(): string {
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    if (!local_llassessment_is_view_page()) {
        return '';
    }

    $cmid = 0;
    if (!empty($GLOBALS['PAGE']->cm->id)) {
        $cmid = (int) $GLOBALS['PAGE']->cm->id;
    }
    if (!$cmid) {
        $cmid = optional_param('id', 0, PARAM_INT);
    }
    $sections = $cmid ? local_llassessment_quiz_section_outline($cmid) : [];
    if (!$sections) {
        return '';
    }
    $emitted = true;

    $esc = static function($value): string {
        return s((string) $value);
    };

    $rows = '';
    foreach ($sections as $section) {
        $chips = '';
        foreach ($section['types'] as $type) {
            $qtype = preg_replace('/[^a-z0-9_-]/', '', (string) ($type['qtype'] ?? ''));
            $chips .= '<span class="ll-qv-type ll-qv-type--' . $esc($qtype) . '">' .
                $esc($type['label']) .
                ((int) $type['count'] > 0 ? ' <em>' . $esc((string) $type['count']) . '</em>' : '') .
                '</span>';
        }
        $rows .= '<tr>' .
            '<td class="ll-qv-outline__name">' . $esc($section['name']) . '</td>' .
            '<td class="ll-qv-outline__count"><span>' . $esc((string) $section['count']) . '</span></td>' .
            '<td><div class="ll-qv-outline__types">' . ($chips !== '' ? $chips : '—') . '</div></td>' .
            '</tr>';
    }

    return '<section id="ll-qv-outline-src" class="ll-qv-outline" data-region="ll-qv-outline">' .
        '<h2 class="ll-qv-outline__title">' . $esc(get_string('sectionoutline', 'local_llassessment')) . '</h2>' .
        '<div class="ll-qv-table-wrap">' .
        '<table class="ll-qv-table ll-qv-outline__table">' .
        '<thead><tr>' .
        '<th>' . $esc(get_string('sectioncol', 'local_llassessment')) . '</th>' .
        '<th>' . $esc(get_string('questions', 'local_llassessment')) . '</th>' .
        '<th>' . $esc(get_string('questiontypes', 'local_llassessment')) . '</th>' .
        '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
}

/**
 * Queue body classes + AMD. Safe to call repeatedly.
 * Prefer calling from attempt_viewed observer (before output).
 *
 * @param bool $force Skip page detection (used by attempt_viewed observer).
 * @return bool
 */
function local_llassessment_bootstrap_arena(bool $force = false): bool {
    global $PAGE, $CFG;

    if (!local_llassessment_arena_enabled()) {
        return false;
    }
    if (!$force && !local_llassessment_is_attempt_page()) {
        return false;
    }

    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    if (empty($PAGE)) {
        return false;
    }

    $PAGE->add_body_class('ll-arena-attempt');
    $PAGE->add_body_class('ll-arena-mode-' . local_llassessment_colormode());

    // Marker class used by CSS even before AMD runs.
    $PAGE->add_body_class('ll-arena-boot');
    if (local_llassessment_course_format() === 'nexcoursepro') {
        $PAGE->add_body_class('format-nexcoursepro');
        $PAGE->add_body_class('ll-arena-back-course');
    }

    $PAGE->requires->css(local_llassessment_css_url('/local/llassessment/styles/arena.css'));
    $PAGE->requires->js_call_amd('local_llassessment/arena', 'init', [local_llassessment_js_config()]);

    // Ensure NexProctor monitor still boots under the arena shell.
    if (function_exists('local_nexproctor_bootstrap_on_attempt')) {
        local_nexproctor_bootstrap_on_attempt();
    } else {
        $np = $CFG->dirroot . '/local/nexproctor/lib.php';
        if (is_readable($np)) {
            require_once($np);
            if (function_exists('local_nexproctor_bootstrap_on_attempt')) {
                local_nexproctor_bootstrap_on_attempt();
            }
        }
    }

    return true;
}

/**
 * Redirect review pages to showall=1 so every question is in the DOM.
 * Section tabs then filter to the active section.
 *
 * @return void
 */
function local_llassessment_force_review_showall(): void {
    if (!local_llassessment_arena_enabled()) {
        return;
    }
    if (!local_llassessment_is_review_page()) {
        return;
    }

    $showall = optional_param('showall', null, PARAM_BOOL);
    if ($showall) {
        return;
    }

    $attemptid = optional_param('attempt', 0, PARAM_INT);
    if (!$attemptid) {
        return;
    }
    if (headers_sent()) {
        return;
    }

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $params = [
        'attempt' => $attemptid,
        'showall' => 1,
    ];
    $cmid = optional_param('cmid', 0, PARAM_INT);
    if ($cmid) {
        $params['cmid'] = $cmid;
    }
    redirect(new moodle_url('/mod/quiz/review.php', $params));
}

/**
 * Bootstrap review page styling (in-page, not fullscreen).
 *
 * @param bool $force
 * @return bool
 */
function local_llassessment_bootstrap_review(bool $force = false): bool {
    global $PAGE;

    if (!local_llassessment_arena_enabled()) {
        return false;
    }
    if (!$force && !local_llassessment_is_review_page()) {
        return false;
    }

    // Prefer all questions on one page so section tabs can show every Q in a section.
    local_llassessment_force_review_showall();

    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    if (empty($PAGE)) {
        return false;
    }

    $PAGE->add_body_class('ll-arena-review');
    $PAGE->add_body_class('ll-arena-mode-' . local_llassessment_colormode());
    $PAGE->add_body_class('ll-arena-boot');

    $PAGE->requires->css(local_llassessment_css_url('/local/llassessment/styles/arena.css'));
    $PAGE->requires->js_call_amd('local_llassessment/review', 'init', [local_llassessment_js_config()]);

    // NexProctor Overview / Proctoring tabs on review.
    if (function_exists('local_nexproctor_bootstrap_on_review')) {
        local_nexproctor_bootstrap_on_review();
    } else {
        global $CFG;
        $np = $CFG->dirroot . '/local/nexproctor/lib.php';
        if (is_readable($np)) {
            require_once($np);
            if (function_exists('local_nexproctor_bootstrap_on_review')) {
                local_nexproctor_bootstrap_on_review();
            }
        }
    }

    return true;
}

/**
 * Bootstrap quiz activity landing page (view.php) — themed like course + arena.
 *
 * @param bool $force
 * @return bool
 */
function local_llassessment_bootstrap_view(bool $force = false): bool {
    global $PAGE;

    if (!local_llassessment_arena_enabled()) {
        return false;
    }
    if (!$force && !local_llassessment_is_view_page()) {
        return false;
    }

    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    if (empty($PAGE)) {
        return false;
    }

    $PAGE->add_body_class('ll-quiz-view');
    $PAGE->add_body_class('ll-arena-mode-' . local_llassessment_colormode());
    $PAGE->add_body_class('ll-arena-boot');

    $PAGE->requires->css(local_llassessment_css_url('/local/llassessment/styles/view.css'));
    $PAGE->requires->js_call_amd('local_llassessment/view', 'init', [local_llassessment_js_config()]);

    return true;
}

/**
 * Bootstrap the quiz secondary-nav tab styling on every quiz module page.
 *
 * @return bool
 */
function local_llassessment_bootstrap_modnav(): bool {
    global $PAGE;

    if (!local_llassessment_arena_enabled() || !local_llassessment_is_quiz_module_page()) {
        return false;
    }

    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    if (empty($PAGE)) {
        return false;
    }

    // The body class is a nicety; the head fallback also sets it on <html>, and
    // the stylesheet matches either. Adding it after the header would throw.
    if ((int) $PAGE->state <= \moodle_page::STATE_BEFORE_HEADER) {
        $PAGE->add_body_class('ll-quiz-nav');
        $PAGE->requires->css(local_llassessment_css_url('/local/llassessment/styles/nav.css'));
    }

    return true;
}

/**
 * Boot attempt and/or review chrome as appropriate.
 *
 * @return void
 */
function local_llassessment_bootstrap_pages(): void {
    // Redirect early (before headers) when possible.
    local_llassessment_force_review_showall();
    local_llassessment_bootstrap_arena();
    local_llassessment_bootstrap_review();
    local_llassessment_bootstrap_view();
    local_llassessment_bootstrap_modnav();
}

/**
 * Observer: quiz attempt viewed — fires on attempt.php before HTML output.
 *
 * @param \mod_quiz\event\attempt_viewed $event
 */
function local_llassessment_observer_attempt_viewed(\mod_quiz\event\attempt_viewed $event): void {
    // Force=true: we know this is an attempt page; don't rely on URL sniffing.
    local_llassessment_bootstrap_arena(true);
}

/**
 * Observer: quiz attempt reviewed — fires on review.php.
 *
 * @param \mod_quiz\event\attempt_reviewed $event
 */
function local_llassessment_observer_attempt_reviewed(\mod_quiz\event\attempt_reviewed $event): void {
    local_llassessment_bootstrap_review(true);
}

/**
 * HTML injected into head / top-of-body as backup loader.
 *
 * @return string
 */
function local_llassessment_head_fallback_html(): string {
    if (!local_llassessment_arena_enabled()) {
        return '';
    }

    $isattempt = local_llassessment_is_attempt_page();
    $isreview = local_llassessment_is_review_page();
    $isview = local_llassessment_is_view_page();
    $isnav = local_llassessment_is_quiz_module_page();
    if (!$isattempt && !$isreview && !$isview && !$isnav) {
        return '';
    }

    if ($isattempt) {
        local_llassessment_bootstrap_arena();
    }
    if ($isreview) {
        local_llassessment_bootstrap_review();
    }
    if ($isview) {
        local_llassessment_bootstrap_view();
    }
    if ($isnav) {
        local_llassessment_bootstrap_modnav();
    }

    $config = json_encode(local_llassessment_js_config());
    $arenacss = local_llassessment_css_url('/local/llassessment/styles/arena.css')->out(false);
    $viewcss = local_llassessment_css_url('/local/llassessment/styles/view.css')->out(false);
    $navcss = local_llassessment_css_url('/local/llassessment/styles/nav.css')->out(false);

    $html = '<!-- ll-arena-boot -->';
    $html .= '<link rel="preconnect" href="https://fonts.googleapis.com" data-ll-arena="1" />';
    $html .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin data-ll-arena="1" />';
    $html .= '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" data-ll-arena="1" />';

    if ($isattempt || $isreview) {
        $html .= '<link rel="stylesheet" type="text/css" href="' . s($arenacss) . '" data-ll-arena="1" />';
    }
    if ($isview) {
        $html .= '<link rel="stylesheet" type="text/css" href="' . s($viewcss) . '" data-ll-arena="1" />';
    }
    if ($isnav) {
        $html .= '<link rel="stylesheet" type="text/css" href="' . s($navcss) . '" data-ll-arena="1" />';
    }

    $html .= '<script data-ll-arena="1">';

    if ($isnav) {
        // Tab icons are pure CSS — only the body class is needed.
        $html .= 'document.documentElement.classList.add("ll-quiz-nav");';
        $html .= 'document.addEventListener("DOMContentLoaded",function(){';
        $html .= 'document.body&&document.body.classList.add("ll-quiz-nav");});';
    }

    if ($isattempt) {
        $html .= 'document.documentElement.classList.add("ll-arena-attempt","ll-arena-boot");';
        $html .= 'document.addEventListener("DOMContentLoaded",function(){';
        $html .= 'document.body&&document.body.classList.add("ll-arena-attempt","ll-arena-boot");});';
        $html .= '(function boot(){if(window.__llArenaStarted){return;}';
        $html .= 'if(typeof require==="function"){window.__llArenaStarted=1;';
        $html .= 'require(["local_llassessment/arena"],function(A){A.init(' . $config . ');});return;}';
        $html .= 'setTimeout(boot,200);})();';
    }

    if ($isreview) {
        $html .= 'document.documentElement.classList.add("ll-arena-review","ll-arena-boot");';
        $html .= 'document.addEventListener("DOMContentLoaded",function(){';
        $html .= 'document.body&&document.body.classList.add("ll-arena-review","ll-arena-boot");});';
        $html .= '(function bootR(){if(window.__llReviewStarted){return;}';
        $html .= 'if(typeof require==="function"){window.__llReviewStarted=1;';
        $html .= 'require(["local_llassessment/review"],function(R){R.init(' . $config . ');});return;}';
        $html .= 'setTimeout(bootR,200);})();';
    }

    if ($isview) {
        $html .= 'document.documentElement.classList.add("ll-quiz-view","ll-arena-boot");';
        $html .= 'document.addEventListener("DOMContentLoaded",function(){';
        $html .= 'document.body&&document.body.classList.add("ll-quiz-view","ll-arena-boot");});';
        $html .= '(function bootV(){if(window.__llQuizViewStarted){return;}';
        $html .= 'if(typeof require==="function"){window.__llQuizViewStarted=1;';
        $html .= 'require(["local_llassessment/view"],function(V){V.init(' . $config . ');});return;}';
        $html .= 'setTimeout(bootV,200);})();';
    }

    $html .= '</script>';

    return $html;
}

/**
 * Legacy callbacks.
 */
function local_llassessment_before_http_headers(): void {
    local_llassessment_bootstrap_pages();
}

/**
 * @return string
 */
function local_llassessment_before_standard_html_head(): string {
    return local_llassessment_head_fallback_html();
}

/**
 * @return string
 */
function local_llassessment_before_standard_top_of_body_html(): string {
    return local_llassessment_head_fallback_html();
}

/**
 * @return void
 */
function local_llassessment_before_footer(): void {
    local_llassessment_bootstrap_pages();
}

/**
 * Apply NexCourse visual chrome on course secondary-nav tabs (via local hooks).
 */
function local_llassessment_nexcourse_chrome_bootstrap(): void {
    global $PAGE;
    if (!class_exists('\\format_nexcourse\\local\\chrome')) {
        return;
    }
    try {
        \format_nexcourse\local\chrome::bootstrap($PAGE);
    } catch (\Throwable $e) {
        debugging('llassessment nexcourse chrome bootstrap: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * @return string
 */
function local_llassessment_nexcourse_chrome_html(): string {
    global $PAGE;
    if (!class_exists('\\format_nexcourse\\local\\chrome')) {
        return '';
    }
    try {
        \format_nexcourse\local\chrome::bootstrap($PAGE);
        return \format_nexcourse\local\chrome::inject_html($PAGE);
    } catch (\Throwable $e) {
        debugging('llassessment nexcourse chrome html: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return '';
    }
}
