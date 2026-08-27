<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: refresh one CM's completion + course progress strip (H5P live updates).
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_nexcoursepro\local\catalog;

/**
 * Lightweight progress refresh for in-pane H5P (no player remount).
 */
class get_cm_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    public static function execute(int $courseid, int $cmid): array {
        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);
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

        \core\session\manager::write_close();

        return catalog::export_cm_progress((int) $course->id, (int) $cm->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'cmid' => new external_value(PARAM_INT, 'cm id'),
            'completed' => new external_value(PARAM_BOOL, 'activity complete'),
            'failed' => new external_value(PARAM_BOOL, 'attempted but not passed'),
            'hasgrade' => new external_value(PARAM_BOOL, 'user has a gradebook grade'),
            'hasactivitygrade' => new external_value(PARAM_BOOL, 'show activity score in hero'),
            'gradedisplay' => new external_value(PARAM_TEXT, 'activity score obtained / max'),
            'completionhtml' => new external_value(PARAM_RAW, 'completion criteria html'),
            'hascompletion' => new external_value(PARAM_BOOL, 'has completion criteria'),
            'hasstats' => new external_value(PARAM_BOOL, 'has stats strip'),
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
            ], 'stats strip'),
        ]);
    }
}
