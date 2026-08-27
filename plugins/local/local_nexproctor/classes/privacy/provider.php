<?php
namespace local_nexproctor\privacy;

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
        $collection->add_database_table('local_nexproctor_sessions', [
            'userid' => 'privacy:metadata:sessions:userid',
        ], 'privacy:metadata:sessions');
        $collection->add_database_table('local_nexproctor_events', [
            'eventtype' => 'privacy:metadata:events',
        ], 'privacy:metadata:events');
        $collection->add_database_table('local_nexproctor_evidence', [
            'filearea' => 'privacy:metadata:evidence',
        ], 'privacy:metadata:evidence');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_nexproctor_sessions} s
                  JOIN {course_modules} cm ON cm.id = s.cmid
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :level
                 WHERE s.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid, 'level' => CONTEXT_MODULE]);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $sessions = $DB->get_records('local_nexproctor_sessions', [
                'userid' => $userid,
                'cmid' => $context->instanceid,
            ]);
            if ($sessions) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_nexproctor')],
                    (object) ['sessions' => array_values($sessions)]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $sessions = $DB->get_records('local_nexproctor_sessions', ['cmid' => $context->instanceid]);
        foreach ($sessions as $s) {
            self::delete_session($s->id, $context);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $sessions = $DB->get_records('local_nexproctor_sessions', [
                'userid' => $userid,
                'cmid' => $context->instanceid,
            ]);
            foreach ($sessions as $s) {
                self::delete_session($s->id, $context);
            }
        }
    }

    public static function get_users_in_context(userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $userids = $DB->get_fieldset_select('local_nexproctor_sessions', 'userid', 'cmid = ?', [$context->instanceid]);
        $userlist->add_users($userids);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            $sessions = $DB->get_records('local_nexproctor_sessions', [
                'userid' => $userid,
                'cmid' => $context->instanceid,
            ]);
            foreach ($sessions as $s) {
                self::delete_session($s->id, $context);
            }
        }
    }

    private static function delete_session(int $sessionid, \context $context): void {
        global $DB;
        $fs = get_file_storage();
        foreach (['snapshot', 'screengrab', 'audioclip', 'prestart'] as $area) {
            $fs->delete_area_files($context->id, 'local_nexproctor', $area, $sessionid);
        }
        $DB->delete_records('local_nexproctor_evidence', ['sessionid' => $sessionid]);
        $DB->delete_records('local_nexproctor_events', ['sessionid' => $sessionid]);
        $DB->delete_records('local_nexproctor_sessions', ['id' => $sessionid]);
    }
}
