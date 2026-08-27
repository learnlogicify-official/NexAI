<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Refresh the NexCoursePro sidebar outline after structural edits.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use context_course;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use format_nexcoursepro\local\catalog;

/**
 * External API: get course outline for the Pro rail.
 */
class get_outline extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Active course module id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $cmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        $course = get_course((int) $params['courseid']);
        // Enrolled students do not have moodle/course:view.
        require_login($course);

        return catalog::export_outline((int) $params['courseid'], (int) $params['cmid']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $activity = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'cm id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'modname' => new external_value(PARAM_PLUGIN, 'mod name'),
            'typelabel' => new external_value(PARAM_TEXT, 'type label'),
            'iconurl' => new external_value(PARAM_RAW, 'icon', VALUE_OPTIONAL),
            'hasicon' => new external_value(PARAM_BOOL, 'has icon'),
            'completed' => new external_value(PARAM_BOOL, 'completed'),
            'failed' => new external_value(PARAM_BOOL, 'attempted but not passed', VALUE_OPTIONAL),
            'sectionnum' => new external_value(PARAM_INT, 'section number'),
            'sectionid' => new external_value(PARAM_INT, 'section id', VALUE_OPTIONAL),
            'sectionname' => new external_value(PARAM_TEXT, 'section name', VALUE_OPTIONAL),
            'viewurl' => new external_value(PARAM_RAW, 'view url'),
            'modurl' => new external_value(PARAM_RAW, 'mod url'),
            'editurl' => new external_value(PARAM_RAW, 'edit url', VALUE_OPTIONAL),
            'embeddable' => new external_value(PARAM_BOOL, 'embeddable'),
            'active' => new external_value(PARAM_BOOL, 'active'),
            'isnested' => new external_value(PARAM_BOOL, 'subsection', VALUE_OPTIONAL),
            'searchtext' => new external_value(PARAM_TEXT, 'search text'),
            'hasnext' => new external_value(PARAM_BOOL, 'has following activity', VALUE_OPTIONAL),
            'nextid' => new external_value(PARAM_INT, 'next activity cmid', VALUE_OPTIONAL),
        ]);

        $section = new external_single_structure([
            'sectionnum' => new external_value(PARAM_INT, 'section number'),
            'sectionid' => new external_value(PARAM_INT, 'section id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'shortlabel' => new external_value(PARAM_TEXT, 'short label'),
            'title' => new external_value(PARAM_TEXT, 'title'),
            'activities' => new external_multiple_structure($activity, 'activities'),
            'hasactivities' => new external_value(PARAM_BOOL, 'has activities'),
            'isempty' => new external_value(PARAM_BOOL, 'section has no activities', VALUE_OPTIONAL),
            'candelete' => new external_value(PARAM_BOOL, 'empty section can be deleted', VALUE_OPTIONAL),
            'firstcmid' => new external_value(PARAM_INT, 'first activity cmid', VALUE_OPTIONAL),
            'completedcount' => new external_value(PARAM_INT, 'completed count'),
            'activitycount' => new external_value(PARAM_INT, 'activity count'),
            'sectioncomplete' => new external_value(PARAM_BOOL, 'section complete'),
            'progresspct' => new external_value(PARAM_INT, 'progress percent', VALUE_OPTIONAL),
            'progresslabel' => new external_value(PARAM_TEXT, 'progress label', VALUE_OPTIONAL),
            'hasprogress' => new external_value(PARAM_BOOL, 'has progress', VALUE_OPTIONAL),
            'expanded' => new external_value(PARAM_BOOL, 'expanded'),
            'addurl' => new external_value(PARAM_RAW, 'add activity url', VALUE_OPTIONAL),
            'subsections' => new external_multiple_structure(
                new external_single_structure([
                    'sectionnum' => new external_value(PARAM_INT, 'section number'),
                    'sectionid' => new external_value(PARAM_INT, 'section id'),
                    'name' => new external_value(PARAM_TEXT, 'name'),
                    'shortlabel' => new external_value(PARAM_TEXT, 'short label', VALUE_OPTIONAL),
                    'title' => new external_value(PARAM_TEXT, 'title'),
                    'activities' => new external_multiple_structure($activity, 'activities'),
                    'hasactivities' => new external_value(PARAM_BOOL, 'has activities'),
                    'completedcount' => new external_value(PARAM_INT, 'completed count'),
                    'activitycount' => new external_value(PARAM_INT, 'activity count'),
                    'sectioncomplete' => new external_value(PARAM_BOOL, 'section complete'),
                    'progresspct' => new external_value(PARAM_INT, 'progress percent', VALUE_OPTIONAL),
                    'progresslabel' => new external_value(PARAM_TEXT, 'progress label', VALUE_OPTIONAL),
                    'hasprogress' => new external_value(PARAM_BOOL, 'has progress', VALUE_OPTIONAL),
                    'expanded' => new external_value(PARAM_BOOL, 'expanded', VALUE_OPTIONAL),
                ], 'subsection', VALUE_OPTIONAL),
                'subsections',
                VALUE_OPTIONAL
            ),
            'hassubsections' => new external_value(PARAM_BOOL, 'has subsections', VALUE_OPTIONAL),
        ]);

        $addmod = new external_single_structure([
            'modname' => new external_value(PARAM_PLUGIN, 'mod name'),
            'name' => new external_value(PARAM_TEXT, 'display name'),
            'isnested' => new external_value(PARAM_BOOL, 'creates nested section', VALUE_OPTIONAL),
        ]);

        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'sections' => new external_multiple_structure($section, 'sections'),
            'hassections' => new external_value(PARAM_BOOL, 'has sections'),
            'canedit' => new external_value(PARAM_BOOL, 'can edit'),
            'canmanageactivities' => new external_value(PARAM_BOOL, 'can manage activities'),
            'canupdatesection' => new external_value(PARAM_BOOL, 'can update sections'),
            'addmodules' => new external_multiple_structure($addmod, 'add modules', VALUE_OPTIONAL),
            'hasaddmodules' => new external_value(PARAM_BOOL, 'has add modules'),
            'hassubsection' => new external_value(PARAM_BOOL, 'subsection available'),
            'sesskey' => new external_value(PARAM_RAW, 'sesskey'),
        ]);
    }
}
