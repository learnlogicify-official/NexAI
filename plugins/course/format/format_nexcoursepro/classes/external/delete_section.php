<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Delete an empty course section from the NexCoursePro rail editor.
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
use external_single_structure;
use external_value;
use format_nexcoursepro\local\catalog;
use moodle_exception;

/**
 * Delete a section that has no activities.
 */
class delete_section extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Section id to delete'),
            'cmid' => new external_value(PARAM_INT, 'Active cm id for outline refresh', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $sectionid, int $cmid = 0): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'cmid' => $cmid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);
        require_capability('moodle/course:movesections', $context);

        $course = get_course((int) $params['courseid']);
        $modinfo = get_fast_modinfo($course);
        $section = $modinfo->get_section_info_by_id((int) $params['sectionid'], MUST_EXIST);

        if ((int) $section->section === 0) {
            throw new moodle_exception('cannotdeletecoursemodule');
        }
        if (method_exists($section, 'is_delegated') && $section->is_delegated()) {
            throw new moodle_exception('error');
        }
        if (!course_can_delete_section($course, $section)) {
            throw new moodle_exception('nopermissions', 'error', '', get_string('deletesection'));
        }

        // Activity modules only — a non-empty HTML summary must not block delete.
        $cmids = [];
        if (method_exists($section, 'get_sequence_cm_infos')) {
            foreach ($section->get_sequence_cm_infos() as $cm) {
                if ($cm && !empty($cm->id)) {
                    $cmids[] = (int) $cm->id;
                }
            }
        } else {
            foreach ($modinfo->sections[(int) $section->section] ?? [] as $cmid) {
                $cmids[] = (int) $cmid;
            }
        }
        if (!empty($cmids)) {
            throw new moodle_exception('sectionnotempty', 'format_nexcoursepro');
        }

        // Core refuses delete when summary is set unless forced. We already verified no modules.
        $deleted = false;
        if (class_exists('\\core_courseformat\\formatactions')) {
            $deleted = (bool) \core_courseformat\formatactions::section($course)->delete($section, true, false);
        }
        if (!$deleted) {
            $deleted = (bool) course_delete_section($course, $section, true, true);
        }
        if (!$deleted) {
            // Last resort: clear summary/sequence then delete.
            $DB->set_field('course_sections', 'summary', '', ['id' => $section->id]);
            $DB->set_field('course_sections', 'sequence', '', ['id' => $section->id]);
            rebuild_course_cache((int) $course->id, true);
            $modinfo = get_fast_modinfo($course);
            $section = $modinfo->get_section_info_by_id((int) $params['sectionid'], MUST_EXIST);
            $deleted = (bool) course_delete_section($course, $section, true, true);
        }
        if (!$deleted) {
            throw new moodle_exception('error');
        }

        rebuild_course_cache((int) $course->id, true);

        return catalog::export_outline((int) $course->id, (int) $params['cmid']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_outline::execute_returns();
    }
}
