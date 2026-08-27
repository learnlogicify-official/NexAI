<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: set leaderboard avatar for the current user.
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
use core_external\external_single_structure;
use core_external\external_value;
use format_nexcoursepro\local\gamification;

/**
 * Set game avatar.
 */
class set_avatar extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'avatar' => new external_value(PARAM_INT, 'Avatar id 1-68'),
        ]);
    }

    public static function execute(int $courseid, int $avatar): array {
        global $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'avatar' => $avatar,
        ]);

        $course = get_course((int) $params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_login($course);

        require_once($CFG->libdir . '/gradelib.php');
        $score = null;
        try {
            $courseitem = \grade_item::fetch_course_item((int) $course->id);
            if ($courseitem && (float) $courseitem->grademax) {
                $grade = \grade_grade::fetch([
                    'itemid' => (int) $courseitem->id,
                    'userid' => (int) $USER->id,
                ]);
                if ($grade && $grade->finalgrade !== null && $grade->finalgrade !== '') {
                    $score = (float) $grade->finalgrade;
                }
            }
        } catch (\Throwable $e) {
            $score = null;
        }

        return gamification::set_avatar((int) $USER->id, (int) $params['avatar'], $score);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'success'),
            'avatar' => new external_value(PARAM_INT, 'avatar id'),
            'url' => new external_value(PARAM_RAW, 'avatar url'),
            'message' => new external_value(PARAM_TEXT, 'error or empty'),
        ]);
    }
}
