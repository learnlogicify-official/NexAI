<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: load one activity into the NexCoursePro left pane.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_courseformat\base as format_base;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_nexcoursepro\local\catalog;

/**
 * Return left-pane payload for a course module (no full page reload).
 */
class get_activity_pane extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    public static function execute(int $courseid, int $cmid): array {
        global $PAGE;

        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        // Enrolled students do not have moodle/course:view ("View courses without participation").
        // require_login($course) is the correct gate for learners.
        require_login($course);

        if (($course->format ?? '') !== 'nexcoursepro') {
            throw new \invalid_parameter_exception('Course is not using NexCoursePro format.');
        }

        $cm = get_coursemodule_from_id(null, $cmid, $course->id, false, MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cm->id);
        if (!$cminfo->uservisible) {
            throw new \moodle_exception('activityiscurrentlyhidden');
        }

        $format = format_base::instance($course);
        $PAGE->set_course($course);

        // Release the session lock before the heavy pane render so parallel
        // requests (prefetch / other tabs) are not blocked — same pattern as
        // long Moodle AJAX handlers. Auth/capability checks already ran above.
        \core\session\manager::write_close();

        return catalog::export_activity_pane($format, $PAGE, (int) $cm->id);
    }

    public static function execute_returns(): external_single_structure {
        $nav = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'cm id'),
            'viewurl' => new external_value(PARAM_RAW, 'course view url with cmid'),
            'name' => new external_value(PARAM_TEXT, 'activity name'),
        ]);

        $statusitem = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'value' => new external_value(PARAM_TEXT, 'value'),
        ]);

        $quizmessage = new external_single_structure([
            'text' => new external_value(PARAM_TEXT, 'message text'),
        ]);

        $quizattempt = new external_single_structure([
            'number' => new external_value(PARAM_INT, 'attempt number'),
            'title' => new external_value(PARAM_TEXT, 'attempt title'),
            'statelabel' => new external_value(PARAM_TEXT, 'state label'),
            'stateclass' => new external_value(PARAM_ALPHANUMEXT, 'state css class'),
            'badgekind' => new external_value(PARAM_ALPHANUMEXT, 'badge kind'),
            'timestart' => new external_value(PARAM_TEXT, 'start time'),
            'hastimestart' => new external_value(PARAM_BOOL, 'has start time'),
            'timefinish' => new external_value(PARAM_TEXT, 'finish time'),
            'hastimefinish' => new external_value(PARAM_BOOL, 'has finish time'),
            'grade' => new external_value(PARAM_TEXT, 'grade'),
            'hasgrade' => new external_value(PARAM_BOOL, 'has grade'),
            'reviewurl' => new external_value(PARAM_RAW, 'review or continue url'),
            'canreview' => new external_value(PARAM_BOOL, 'can open attempt'),
            'actionlabel' => new external_value(PARAM_TEXT, 'action label'),
            'iscontinue' => new external_value(PARAM_BOOL, 'is continue action'),
        ]);

        $main = new external_single_structure([
            'hasactivity' => new external_value(PARAM_BOOL, 'has activity'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'kind'),
            'kindlabel' => new external_value(PARAM_TEXT, 'kind label'),
            'eyebrow' => new external_value(PARAM_TEXT, 'eyebrow'),
            'sectionlabel' => new external_value(PARAM_TEXT, 'section label', VALUE_DEFAULT, ''),
            'title' => new external_value(PARAM_TEXT, 'title'),
            'html' => new external_value(PARAM_RAW, 'body html'),
            'hashhtml' => new external_value(PARAM_BOOL, 'has html'),
            'hasmedia' => new external_value(PARAM_BOOL, 'has media'),
            'mediaurl' => new external_value(PARAM_RAW, 'media url'),
            'mediakind' => new external_value(PARAM_ALPHANUMEXT, 'media kind'),
            'isvideofile' => new external_value(PARAM_BOOL, 'is video file'),
            'isaudiofile' => new external_value(PARAM_BOOL, 'is audio file'),
            'isexternallink' => new external_value(PARAM_BOOL, 'is external link'),
            'isembed' => new external_value(PARAM_BOOL, 'is iframe embed', VALUE_OPTIONAL),
            'showcta' => new external_value(PARAM_BOOL, 'show primary cta'),
            'ctaurl' => new external_value(PARAM_RAW, 'cta url'),
            'ctalabel' => new external_value(PARAM_TEXT, 'cta label'),
            'showsecondary' => new external_value(PARAM_BOOL, 'show secondary'),
            'secondaryurl' => new external_value(PARAM_RAW, 'secondary url'),
            'secondarylabel' => new external_value(PARAM_TEXT, 'secondary label'),
            'statusitems' => new external_multiple_structure($statusitem, 'status items'),
            'hasstatus' => new external_value(PARAM_BOOL, 'has status panel'),
            'completed' => new external_value(PARAM_BOOL, 'completed'),
            'failed' => new external_value(PARAM_BOOL, 'attempted but not passed', VALUE_OPTIONAL),
            'completionhtml' => new external_value(PARAM_RAW, 'completion criteria html', VALUE_OPTIONAL),
            'hascompletion' => new external_value(PARAM_BOOL, 'has completion criteria', VALUE_OPTIONAL),
            'hasactivitygrade' => new external_value(PARAM_BOOL, 'show activity score in hero', VALUE_OPTIONAL),
            'gradedisplay' => new external_value(PARAM_TEXT, 'activity score obtained / max', VALUE_OPTIONAL),
            'modurl' => new external_value(PARAM_RAW, 'mod url'),
            'modname' => new external_value(PARAM_TEXT, 'mod name'),
            'typelabel' => new external_value(PARAM_TEXT, 'type label'),
            'iconurl' => new external_value(PARAM_RAW, 'icon url'),
            'hasicon' => new external_value(PARAM_BOOL, 'has icon'),
            'cmid' => new external_value(PARAM_INT, 'cm id'),
            'sectionnum' => new external_value(PARAM_INT, 'section number'),
            'viewurl' => new external_value(PARAM_RAW, 'view url'),
            'showlaunch' => new external_value(PARAM_BOOL, 'legacy'),
            'showembed' => new external_value(PARAM_BOOL, 'legacy'),
            'embedurl' => new external_value(PARAM_RAW, 'legacy'),
            'launchlabel' => new external_value(PARAM_TEXT, 'legacy'),
            'hasquiztabs' => new external_value(PARAM_BOOL, 'show quiz overview/attempts tabs'),
            'quizintro' => new external_value(PARAM_RAW, 'quiz intro html'),
            'hasquizintro' => new external_value(PARAM_BOOL, 'has quiz intro'),
            'quizmessages' => new external_multiple_structure($quizmessage, 'quiz info messages'),
            'hasquizmessages' => new external_value(PARAM_BOOL, 'has quiz messages'),
            'quizactionshtml' => new external_value(PARAM_RAW, 'quiz start/continue buttons html'),
            'hasquizactions' => new external_value(PARAM_BOOL, 'has quiz actions'),
            'quizbodyhtml' => new external_value(PARAM_RAW, 'fallback quiz body html'),
            'hasquizbody' => new external_value(PARAM_BOOL, 'has quiz body html'),
            'quizsections' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'section name'),
                    'count' => new external_value(PARAM_INT, 'question count'),
                    'marks' => new external_value(PARAM_FLOAT, 'section marks', VALUE_DEFAULT, 0),
                    'marksdisplay' => new external_value(PARAM_TEXT, 'formatted marks', VALUE_DEFAULT, ''),
                    'types' => new external_multiple_structure(
                        new external_single_structure([
                            'qtype' => new external_value(PARAM_ALPHANUMEXT, 'qtype'),
                            'label' => new external_value(PARAM_TEXT, 'label'),
                            'count' => new external_value(PARAM_INT, 'count'),
                        ]),
                        'types',
                        VALUE_DEFAULT,
                        []
                    ),
                ]),
                'quiz sections',
                VALUE_DEFAULT,
                []
            ),
            'hasquizsections' => new external_value(PARAM_BOOL, 'has quiz sections'),
            'quizcourseurl' => new external_value(PARAM_RAW, 'course url for back link'),
            'quizattempts' => new external_multiple_structure($quizattempt, 'quiz attempts'),
            'hasquizattempts' => new external_value(PARAM_BOOL, 'has quiz attempts'),
            'quizattemptcount' => new external_value(PARAM_INT, 'quiz attempt count'),
            'quizbestgrade' => new external_value(PARAM_TEXT, 'best grade display'),
            'hasquizbestgrade' => new external_value(PARAM_BOOL, 'has best grade'),
        ]);

        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'cmid' => new external_value(PARAM_INT, 'cm id'),
            'main' => $main,
            'prev' => $nav,
            'next' => $nav,
            'hasprev' => new external_value(PARAM_BOOL, 'has previous'),
            'hasnext' => new external_value(PARAM_BOOL, 'has next'),
            'hasstats' => new external_value(PARAM_BOOL, 'has stats strip', VALUE_OPTIONAL),
            'stats' => new external_single_structure([
                'progresspct' => new external_value(PARAM_INT, 'progress percent'),
                'activitydisplay' => new external_value(PARAM_TEXT, 'activity progress label'),
                'items' => new external_multiple_structure(
                    new external_single_structure([
                        'key' => new external_value(PARAM_ALPHANUMEXT, 'stat key'),
                        'value' => new external_value(PARAM_TEXT, 'stat value'),
                        'label' => new external_value(PARAM_TEXT, 'stat label'),
                    ]),
                    'stat items'
                ),
            ], 'stats strip', VALUE_OPTIONAL),
        ]);
    }
}
