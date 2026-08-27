<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: course grade leaderboard for NexCoursePro.
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
use format_nexcoursepro\local\leaderboard as lb;

/**
 * External API: get course leaderboard.
 */
class get_leaderboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'institution' => new external_value(PARAM_TEXT, 'College filter', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, '0-based page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'rows per page', VALUE_DEFAULT, lb::PERPAGE),
        ]);
    }

    public static function execute(
        int $courseid,
        string $institution = '',
        int $page = 0,
        int $perpage = lb::PERPAGE
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'institution' => $institution,
            'page' => $page,
            'perpage' => $perpage,
        ]);

        $course = get_course((int) $params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_login($course);

        if (($course->format ?? '') !== 'nexcoursepro') {
            throw new \invalid_parameter_exception('Course is not using NexCoursePro format.');
        }

        \core\session\manager::write_close();

        $data = catalog::export_leaderboard(
            (int) $course->id,
            (string) $params['institution'],
            (int) $params['page'],
            (int) $params['perpage']
        );
        if (empty($data['hasme']) || empty($data['me'])) {
            unset($data['me']);
            $data['hasme'] = false;
        } else if (!empty($data['me']['avatarchoices'])) {
            // Keep choices for picker.
        }
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        $choice = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'avatar id'),
            'url' => new external_value(PARAM_URL, 'avatar url'),
            'unlocked' => new external_value(PARAM_BOOL, 'unlocked'),
            'requiredlevel' => new external_value(PARAM_INT, 'required level'),
            'selected' => new external_value(PARAM_BOOL, 'selected'),
        ]);

        $me = new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'user id'),
            'name' => new external_value(PARAM_TEXT, 'display name'),
            'username' => new external_value(PARAM_TEXT, 'username', VALUE_OPTIONAL),
            'usernamehandle' => new external_value(PARAM_TEXT, '@username', VALUE_OPTIONAL),
            'institution' => new external_value(PARAM_TEXT, 'college', VALUE_OPTIONAL),
            'avatar' => new external_value(PARAM_INT, 'avatar id', VALUE_OPTIONAL),
            'avatarurl' => new external_value(PARAM_URL, 'avatar url', VALUE_OPTIONAL),
            'avatarchoices' => new external_multiple_structure($choice, 'avatar choices', VALUE_OPTIONAL),
            'coursename' => new external_value(PARAM_TEXT, 'course name', VALUE_OPTIONAL),
            'score' => new external_value(PARAM_TEXT, 'score', VALUE_OPTIONAL),
            'grade' => new external_value(PARAM_TEXT, 'grade'),
            'grademax' => new external_value(PARAM_TEXT, 'max'),
            'gradedisplay' => new external_value(PARAM_TEXT, 'obtained / max'),
            'percent' => new external_value(PARAM_TEXT, 'percent'),
            'overallrank' => new external_value(PARAM_INT, 'overall rank', VALUE_OPTIONAL),
            'overallvalue' => new external_value(PARAM_TEXT, 'overall rank display', VALUE_OPTIONAL),
            'collegerank' => new external_value(PARAM_INT, 'college rank', VALUE_OPTIONAL),
            'collegevalue' => new external_value(PARAM_TEXT, 'college rank display', VALUE_OPTIONAL),
            'level' => new external_value(PARAM_INT, 'level', VALUE_OPTIONAL),
            'levelicon' => new external_value(PARAM_URL, 'level icon', VALUE_OPTIONAL),
            'levelpercent' => new external_value(PARAM_FLOAT, 'level progress percent', VALUE_OPTIONAL),
            'nextlevel' => new external_value(PARAM_TEXT, 'next level text', VALUE_OPTIONAL),
            'showlevel' => new external_value(PARAM_BOOL, 'show level UI', VALUE_OPTIONAL),
            'progresspct' => new external_value(PARAM_INT, 'activity progress'),
            'activitydisplay' => new external_value(PARAM_TEXT, 'activity label'),
            'scoreicon' => new external_value(PARAM_URL, 'score icon', VALUE_OPTIONAL),
            'rankicon' => new external_value(PARAM_URL, 'rank icon', VALUE_OPTIONAL),
            'collegeicon' => new external_value(PARAM_URL, 'college icon', VALUE_OPTIONAL),
            'labelscore' => new external_value(PARAM_TEXT, 'score label', VALUE_OPTIONAL),
            'labeloverall' => new external_value(PARAM_TEXT, 'overall label', VALUE_OPTIONAL),
            'labelcollege' => new external_value(PARAM_TEXT, 'college label', VALUE_OPTIONAL),
            'labellevel' => new external_value(PARAM_TEXT, 'level label', VALUE_OPTIONAL),
            'changeavatar' => new external_value(PARAM_TEXT, 'change avatar label', VALUE_OPTIONAL),
        ], 'viewer card', VALUE_OPTIONAL);

        $entry = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'rank'),
            'ranklabel' => new external_value(PARAM_TEXT, 'rank label'),
            'userid' => new external_value(PARAM_INT, 'user id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'username' => new external_value(PARAM_TEXT, 'username'),
            'institution' => new external_value(PARAM_TEXT, 'college'),
            'avatarurl' => new external_value(PARAM_URL, 'avatar url', VALUE_OPTIONAL),
            'grade' => new external_value(PARAM_TEXT, 'grade'),
            'percent' => new external_value(PARAM_TEXT, 'percent'),
            'percentnum' => new external_value(PARAM_INT, 'percent number'),
            'level' => new external_value(PARAM_INT, 'level', VALUE_OPTIONAL),
            'isme' => new external_value(PARAM_BOOL, 'is current user'),
        ]);

        $college = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'college name'),
            'selected' => new external_value(PARAM_BOOL, 'selected'),
        ]);

        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'available'),
            'unavailablemessage' => new external_value(PARAM_TEXT, 'message'),
            'me' => $me,
            'hasme' => new external_value(PARAM_BOOL, 'has me'),
            'entries' => new external_multiple_structure($entry, 'entries'),
            'hasentries' => new external_value(PARAM_BOOL, 'has entries'),
            'playercount' => new external_value(PARAM_INT, 'overall players'),
            'total' => new external_value(PARAM_INT, 'filtered total'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'per page'),
            'grademax' => new external_value(PARAM_TEXT, 'max', VALUE_OPTIONAL),
            'institutions' => new external_multiple_structure($college, 'colleges', VALUE_OPTIONAL),
            'institution' => new external_value(PARAM_TEXT, 'filter', VALUE_OPTIONAL),
            'coursename' => new external_value(PARAM_TEXT, 'course', VALUE_OPTIONAL),
            'scoreicon' => new external_value(PARAM_URL, 'icon', VALUE_OPTIONAL),
            'rankicon' => new external_value(PARAM_URL, 'icon', VALUE_OPTIONAL),
            'collegeicon' => new external_value(PARAM_URL, 'icon', VALUE_OPTIONAL),
        ]);
    }
}
