<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexbattleground.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nexbattleground_queue', [
            'userid' => 'privacy:metadata:userid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:queue');
        $collection->add_database_table('local_nexbattleground_player', [
            'userid' => 'privacy:metadata:userid',
            'code' => 'privacy:metadata:code',
        ], 'privacy:metadata:player');
        $collection->add_database_table('local_nexbattleground_sub', [
            'userid' => 'privacy:metadata:userid',
            'code' => 'privacy:metadata:code',
            'status' => 'privacy:metadata:status',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:sub');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_system_context();
        return $list;
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexbattleground_queue}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexbattleground_player}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexbattleground_sub}', []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->get_contexts())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $ctx = \context_system::instance();
        $subs = $DB->get_records('local_nexbattleground_sub', ['userid' => $userid]);
        if ($subs) {
            writer::with_context($ctx)->export_data(
                [get_string('pluginname', 'local_nexbattleground')],
                (object) ['submissions' => array_values($subs)]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $DB->delete_records('local_nexbattleground_queue');
        $DB->delete_records('local_nexbattleground_sub');
        $DB->delete_records('local_nexbattleground_player');
        $DB->delete_records('local_nexbattleground_battle');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $DB->delete_records('local_nexbattleground_queue', ['userid' => $userid]);
        $DB->delete_records('local_nexbattleground_sub', ['userid' => $userid]);
        $DB->delete_records('local_nexbattleground_player', ['userid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $users = $userlist->get_userids();
        if (!$users) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($users);
        $DB->delete_records_select('local_nexbattleground_queue', "userid {$insql}", $params);
        $DB->delete_records_select('local_nexbattleground_sub', "userid {$insql}", $params);
        $DB->delete_records_select('local_nexbattleground_player', "userid {$insql}", $params);
    }
}
