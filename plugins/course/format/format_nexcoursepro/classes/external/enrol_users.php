<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: queue bulk enrol (returns immediately — work continues in background).
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_nexcoursepro\local\enrol_roster;
use format_nexcoursepro\task\enrol_users_adhoc;

/**
 * External API: enrol users in the background.
 */
class enrol_users extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'user id'),
                'Users to enrol'
            ),
            'roleid' => new external_value(PARAM_INT, 'Role to assign', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid, array $userids, int $roleid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userids' => $userids,
            'roleid' => $roleid,
        ]);

        $course = get_course((int) $params['courseid']);
        if (($course->format ?? '') !== 'nexcoursepro') {
            throw new \invalid_parameter_exception('Course is not using NexCoursePro format.');
        }
        $context = enrol_roster::require_enrol_capability($course);
        self::validate_context($context);
        require_login($course);

        $courseid = (int) $course->id;
        $roleid = enrol_roster::resolve_roleid($courseid, (int) $params['roleid']);
        $ids = [];
        foreach ($params['userids'] as $userid) {
            $userid = (int) $userid;
            if ($userid > 1) {
                $ids[$userid] = $userid;
            }
        }
        $ids = array_values($ids);
        $queued = count($ids);

        if ($queued < 1) {
            return [
                'enrolled' => 0,
                'queued' => 0,
                'skipped' => 0,
                'errors' => [],
                'roleid' => $roleid,
            ];
        }

        // Release session lock immediately so the rest of the site stays interactive.
        \core\session\manager::write_close();

        // Safety net via cron if the shutdown worker cannot finish the request.
        $task = new enrol_users_adhoc();
        $task->set_custom_data([
            'courseid' => $courseid,
            'userids' => $ids,
            'roleid' => $roleid,
        ]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);

        // After the AJAX response is flushed, enrol in this same PHP worker (no UI wait).
        register_shutdown_function(static function() use ($courseid, $ids, $roleid) {
            @ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                \core_php_time_limit::raise(300);
                raise_memory_limit(MEMORY_EXTRA);
                enrol_roster::enrol_users($courseid, $ids, $roleid);
                // Mark done so the adhoc cron copy becomes a no-op.
                $key = 'nxpro_enrol_done_' . sha1($courseid . ':' . $roleid . ':' . implode(',', $ids));
                set_config($key, (string) time(), 'format_nexcoursepro');
            } catch (\Throwable $e) {
                debugging('format_nexcoursepro background enrol: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        });

        return [
            'enrolled' => 0,
            'queued' => $queued,
            'skipped' => 0,
            'errors' => [],
            'roleid' => $roleid,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enrolled' => new external_value(PARAM_INT, 'enrolled count (0 when queued)'),
            'queued' => new external_value(PARAM_INT, 'users accepted for background enrol'),
            'skipped' => new external_value(PARAM_INT, 'skipped count'),
            'errors' => new external_multiple_structure(new external_value(PARAM_TEXT, 'error')),
            'roleid' => new external_value(PARAM_INT, 'role used for enrolment', VALUE_DEFAULT, 0),
        ]);
    }
}
