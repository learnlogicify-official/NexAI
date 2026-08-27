<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Rename a course module from the NexCoursePro sidebar.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use context_module;
use core_courseformat\formatactions;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Inline rename of an activity / page name in the Pro rail.
 */
class rename_cm extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'New activity name'),
        ]);
    }

    /**
     * @param int $cmid
     * @param string $name
     * @return array
     */
    public static function execute(int $cmid, string $name): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'name' => $name,
        ]);

        $cm = get_coursemodule_from_id(null, (int) $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance((int) $cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        $paramcleaning = empty($CFG->formatstringstriptags) ? PARAM_CLEANHTML : PARAM_TEXT;
        $newname = trim(clean_param((string) $params['name'], $paramcleaning));
        if ($newname === '') {
            throw new \invalid_parameter_exception(get_string('emptyname', 'format_nexcoursepro'));
        }
        if (\core_text::strlen($newname) > 1333) {
            throw new moodle_exception('maximumchars', 'moodle', '', 1333);
        }

        $oldname = (string) $cm->name;
        if ($newname !== $oldname) {
            $updated = false;

            // Prefer core course-format rename when available.
            try {
                if (class_exists(formatactions::class)) {
                    $course = get_course((int) $cm->course);
                    $updated = (bool) formatactions::cm($course)->rename((int) $cm->id, $newname);
                }
            } catch (\Throwable $e) {
                $updated = false;
            }

            // Fallback: write the activity instance name directly (Moodle stores name there).
            if (!$updated) {
                $record = $DB->get_record($cm->modname, ['id' => $cm->instance], 'id, name', MUST_EXIST);
                if ((string) $record->name !== $newname) {
                    $record->name = $newname;
                    $DB->update_record($cm->modname, $record);
                }
                $cm->name = $newname;
                try {
                    \core\event\course_module_updated::create_from_cm($cm)->trigger();
                } catch (\Throwable $e) {
                    // Event is best-effort.
                }
                if (class_exists('\course_modinfo') && method_exists('\course_modinfo', 'purge_course_module_cache')) {
                    \course_modinfo::purge_course_module_cache((int) $cm->course, (int) $cm->id);
                }
                rebuild_course_cache((int) $cm->course, true);
                if (function_exists('course_module_update_calendar_events')) {
                    try {
                        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                        if ($instance) {
                            course_module_update_calendar_events($cm->modname, $instance, $cm);
                        }
                    } catch (\Throwable $e) {
                        // Calendar sync is best-effort.
                    }
                }
                $updated = true;
            } else {
                rebuild_course_cache((int) $cm->course, true);
            }
        }

        // Always return the requested display name (avoid stale modinfo reads).
        $displayname = format_string($newname, true, ['context' => $context]);

        return [
            'cmid' => (int) $cm->id,
            'name' => $displayname,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'Updated name'),
        ]);
    }
}
