<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Create a course section or subsection from the NexCoursePro rail editor.
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
 * Create a new top-level section, or a subsection inside an existing section.
 */
class add_section extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Active cm id for outline refresh', VALUE_DEFAULT, 0),
            'aftersectionid' => new external_value(PARAM_INT, 'Insert after this section id (0 = append)', VALUE_DEFAULT, 0),
            'parentsectionnum' => new external_value(
                PARAM_INT,
                'When > 0, create a subsection inside this section number instead of a top-level section',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @param int $aftersectionid
     * @param int $parentsectionnum
     * @return array
     */
    public static function execute(
        int $courseid,
        int $cmid = 0,
        int $aftersectionid = 0,
        int $parentsectionnum = 0
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'aftersectionid' => $aftersectionid,
            'parentsectionnum' => $parentsectionnum,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        $course = get_course((int) $params['courseid']);
        $format = course_get_format($course);
        $parentnum = (int) $params['parentsectionnum'];

        if ($parentnum > 0) {
            require_capability('moodle/course:manageactivities', $context);
            if (!file_exists($CFG->dirroot . '/mod/subsection/lib.php')) {
                throw new moodle_exception('moduledisable');
            }

            $modinfo = get_fast_modinfo($course);
            $parent = $modinfo->get_section_info($parentnum, MUST_EXIST);
            if (method_exists($parent, 'is_delegated') && $parent->is_delegated()) {
                throw new moodle_exception('error');
            }

            // Quick-create subsection (FEATURE_QUICKCREATE) — no modedit form.
            list($module, $modulecontext, $cw, $cm, $data) = prepare_new_moduleinfo_data(
                $course,
                'subsection',
                $parentnum
            );
            unset($module, $modulecontext, $cw, $cm);
            if (empty($data->name)) {
                $data->name = get_string('quickcreatename', 'mod_subsection');
            }
            add_moduleinfo($data, $course);
        } else {
            require_capability('moodle/course:update', $context);

            if (method_exists($format, 'get_max_sections') && method_exists($format, 'get_last_section_number')) {
                $last = (int) $format->get_last_section_number();
                $max = (int) $format->get_max_sections();
                if ($max > 0 && $last >= $max) {
                    throw new moodle_exception('maxsectionslimit', 'moodle', '', $max);
                }
            }

            $position = 0;
            $afterid = (int) $params['aftersectionid'];
            if ($afterid > 0) {
                $after = $DB->get_record('course_sections', [
                    'id' => $afterid,
                    'course' => (int) $course->id,
                ], 'id, section', MUST_EXIST);
                $position = (int) $after->section + 1;
            }

            $section = course_create_section($course, $position, true);
            if (!$section || empty($section->id)) {
                throw new moodle_exception('cannotcreatelink', 'error');
            }
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
