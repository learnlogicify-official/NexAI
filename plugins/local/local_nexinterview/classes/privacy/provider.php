<?php
namespace local_nexinterview\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nexinterview_attempt', [
            'userid' => 'privacy:metadata',
            'sessionid' => 'privacy:metadata',
            'roletrack' => 'privacy:metadata',
            'overallscore' => 'privacy:metadata',
            'scoresjson' => 'privacy:metadata',
        ], 'privacy:metadata');
        $collection->add_external_location_link('interview_service', [
            'userid' => 'privacy:metadata',
            'resume' => 'privacy:metadata',
            'transcript' => 'privacy:metadata',
        ], 'privacy:metadata');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_system_context();
        return $list;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $rows = $DB->get_records('local_nexinterview_attempt', ['userid' => $userid]);
        if ($rows) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_nexinterview')],
                (object) ['attempts' => array_values($rows)]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            $DB->delete_records('local_nexinterview_attempt');
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $DB->delete_records('local_nexinterview_attempt', ['userid' => (int) $contextlist->get_user()->id]);
    }

    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexinterview_attempt}', []);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $ids = $userlist->get_userids();
        if (!$ids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('local_nexinterview_attempt', "userid $insql", $params);
    }
}
