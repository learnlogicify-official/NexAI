<?php
namespace mod_nexinterview\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

/**
 * Privacy provider for NexInterview course activity attempts.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('nexinterview_attempts', [
            'userid' => 'privacy:metadata:userid',
            'sessionid' => 'privacy:metadata:sessionid',
            'overallscore' => 'privacy:metadata:overallscore',
            'reportjson' => 'privacy:metadata:reportjson',
        ], 'privacy:metadata:attempts');
        $collection->add_external_location_link('interview_service', [
            'userid' => 'privacy:metadata:userid',
            'answers' => 'privacy:metadata:answers',
            'code' => 'privacy:metadata:code',
        ], 'privacy:metadata:service');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modulelevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'nexinterview'
                  JOIN {nexinterview_attempts} a ON a.activityid = cm.instance
                 WHERE a.userid = :userid";
        $list = new contextlist();
        $list->add_from_sql($sql, [
            'modulelevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);
        return $list;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {nexinterview_attempts} a
                  JOIN {course_modules} cm ON cm.instance = a.activityid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'nexinterview'
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('nexinterview', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $rows = $DB->get_records('nexinterview_attempts', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            ]);
            foreach ($rows as $row) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'nexinterview'), 'attempt-' . $row->id],
                    (object) [
                        'sessionid' => $row->sessionid,
                        'status' => $row->status,
                        'overallscore' => $row->overallscore,
                        'timecreated' => transform::datetime($row->timecreated),
                    ]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('nexinterview', $context->instanceid);
        if ($cm) {
            $DB->delete_records('nexinterview_attempts', ['activityid' => $cm->instance]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('nexinterview', $context->instanceid);
            if ($cm) {
                $DB->delete_records('nexinterview_attempts', [
                    'activityid' => $cm->instance,
                    'userid' => $userid,
                ]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('nexinterview', $context->instanceid);
        if (!$cm) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['aid'] = $cm->instance;
        $DB->delete_records_select(
            'nexinterview_attempts',
            "activityid = :aid AND userid $insql",
            $params
        );
    }
}
