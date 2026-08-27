<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * NexReports stores measured per-user time-on-page in nexreports_tracking.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('nexreports_tracking', [
            'userid' => 'privacy:metadata:tracking:userid',
            'courseid' => 'privacy:metadata:tracking:courseid',
            'cmid' => 'privacy:metadata:tracking:cmid',
            'timestart' => 'privacy:metadata:tracking:timestart',
            'timespent' => 'privacy:metadata:tracking:timespent',
            'lastping' => 'privacy:metadata:tracking:lastping',
        ], 'privacy:metadata:tracking');
        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists('nexreports_tracking', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {nexreports_tracking}', []);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $rows = $DB->get_records('nexreports_tracking', ['userid' => $userid], 'timestart ASC');
            $data = array_map(static function ($row) {
                return (object) [
                    'courseid' => (int) $row->courseid,
                    'cmid' => (int) $row->cmid,
                    'timestart' => transform::datetime((int) $row->timestart),
                    'timespentseconds' => (int) $row->timespent,
                ];
            }, array_values($rows));
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_nexreports')],
                (object) ['tracking' => $data]
            );
        }
    }

    /**
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('nexreports_tracking');
        }
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('nexreports_tracking', ['userid' => (int) $contextlist->get_user()->id]);
            }
        }
    }

    /**
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if ($userids) {
            [$insql, $params] = $DB->get_in_or_equal($userids);
            $DB->delete_records_select('nexreports_tracking', "userid $insql", $params);
        }
    }
}
