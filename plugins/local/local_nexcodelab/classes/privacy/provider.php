<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexcodelab.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nexcodelab_submission', [
            'userid' => 'privacy:metadata:userid',
            'problemid' => 'privacy:metadata:problemid',
            'code' => 'privacy:metadata:code',
            'status' => 'privacy:metadata:status',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:submission');
        $collection->add_database_table('local_nexcodelab_draft', [
            'userid' => 'privacy:metadata:userid',
            'code' => 'privacy:metadata:code',
        ], 'privacy:metadata:draft');
        $collection->add_database_table('local_nexcodelab_userxp', [
            'userid' => 'privacy:metadata:userid',
            'xp' => 'privacy:metadata:xp',
        ], 'privacy:metadata:userxp');
        $collection->add_database_table('local_nexcodelab_streak', [
            'userid' => 'privacy:metadata:userid',
            'currentstreak' => 'privacy:metadata:streak',
        ], 'privacy:metadata:streak');
        $collection->add_database_table('local_nexcodelab_xpevent', [
            'userid' => 'privacy:metadata:userid',
            'amount' => 'privacy:metadata:xp',
            'reason' => 'privacy:metadata:reason',
        ], 'privacy:metadata:xpevent');
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
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexcodelab_submission}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexcodelab_draft}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_nexcodelab_userxp}', []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->get_contexts())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $ctx = \context_system::instance();
        $subs = $DB->get_records('local_nexcodelab_submission', ['userid' => $userid]);
        if ($subs) {
            writer::with_context($ctx)->export_data(
                [get_string('submissions', 'local_nexcodelab')],
                (object) ['submissions' => array_values($subs)]
            );
        }
        $xp = $DB->get_record('local_nexcodelab_userxp', ['userid' => $userid]);
        if ($xp) {
            writer::with_context($ctx)->export_data(
                [get_string('xp', 'local_nexcodelab')],
                $xp
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $DB->delete_records('local_nexcodelab_submission');
        $DB->delete_records('local_nexcodelab_draft');
        $DB->delete_records('local_nexcodelab_userxp');
        $DB->delete_records('local_nexcodelab_xpevent');
        $DB->delete_records('local_nexcodelab_streak');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $DB->delete_records('local_nexcodelab_submission', ['userid' => $userid]);
        $DB->delete_records('local_nexcodelab_draft', ['userid' => $userid]);
        $DB->delete_records('local_nexcodelab_userxp', ['userid' => $userid]);
        $DB->delete_records('local_nexcodelab_xpevent', ['userid' => $userid]);
        $DB->delete_records('local_nexcodelab_streak', ['userid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $users = $userlist->get_userids();
        if (!$users) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($users);
        foreach (['submission', 'draft', 'userxp', 'xpevent', 'streak'] as $t) {
            $DB->delete_records_select('local_nexcodelab_' . $t, "userid {$insql}", $params);
        }
    }
}
