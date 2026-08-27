<?php
namespace local_nexcomm\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nexcomm_attempt', [
            'userid' => 'privacy:metadata:attempt',
            'responsetext' => 'privacy:metadata:attempt',
        ], 'privacy:metadata:attempt');
        $collection->add_database_table('local_nexcomm_userxp', [
            'userid' => 'privacy:metadata:userxp',
            'xp' => 'privacy:metadata:userxp',
        ], 'privacy:metadata:userxp');
        $collection->add_database_table('local_nexcomm_targetday', [
            'userid' => 'privacy:metadata:targets',
        ], 'privacy:metadata:targets');
        $collection->add_database_table('local_nexcomm_streak', [
            'userid' => 'privacy:metadata:streak',
        ], 'privacy:metadata:streak');
        $collection->add_database_table('local_nexcomm_xpevent', [
            'userid' => 'privacy:metadata:xpevent',
        ], 'privacy:metadata:xpevent');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_system_context();
        return $list;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_nexcomm')],
                (object) [
                    'attempts' => array_values($DB->get_records('local_nexcomm_attempt', ['userid' => $userid])),
                    'xp' => $DB->get_record('local_nexcomm_userxp', ['userid' => $userid]),
                ]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('local_nexcomm_attempt');
        $DB->delete_records('local_nexcomm_userxp');
        $DB->delete_records('local_nexcomm_xpevent');
        $DB->delete_records('local_nexcomm_streak');
        $DB->delete_records('local_nexcomm_targetday');
        $DB->delete_records('local_nexcomm_targetweek');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_nexcomm_attempt', ['userid' => $userid]);
        $DB->delete_records('local_nexcomm_userxp', ['userid' => $userid]);
        $DB->delete_records('local_nexcomm_xpevent', ['userid' => $userid]);
        $DB->delete_records('local_nexcomm_streak', ['userid' => $userid]);
        $DB->delete_records('local_nexcomm_targetday', ['userid' => $userid]);
        $DB->delete_records('local_nexcomm_targetweek', ['userid' => $userid]);
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexcomm_attempt}', []);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        foreach (['attempt', 'userxp', 'xpevent', 'streak', 'targetday', 'targetweek'] as $table) {
            $DB->delete_records_select('local_nexcomm_' . $table, "userid {$insql}", $params);
        }
    }
}
