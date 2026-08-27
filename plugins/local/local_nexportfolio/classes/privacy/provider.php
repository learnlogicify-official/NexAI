<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\privacy;

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

    /**
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nexportfolio_handles', [
            'userid' => 'privacy:metadata:handles:userid',
            'platform' => 'privacy:metadata:handles:platform',
            'handle' => 'privacy:metadata:handles:handle',
        ], 'privacy:metadata:handles');

        $collection->add_database_table('local_nexportfolio_data', [
            'userid' => 'privacy:metadata:handles:userid',
            'platform' => 'privacy:metadata:handles:platform',
        ], 'privacy:metadata:data');

        $collection->add_database_table('local_nexportfolio_github', [
            'userid' => 'privacy:metadata:handles:userid',
            'github_login' => 'privacy:metadata:handles:handle',
        ], 'privacy:metadata:github');

        $collection->add_database_table('local_nexportfolio_projects', [
            'userid' => 'privacy:metadata:handles:userid',
            'fullname' => 'privacy:metadata:projects',
        ], 'privacy:metadata:projects');

        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    /**
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $userids = $DB->get_fieldset_select('local_nexportfolio_handles', 'DISTINCT userid', '1=1');
        if ($userids) {
            $userlist->add_users($userids);
        }
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel !== CONTEXT_SYSTEM) {
                continue;
            }
            $handles = $DB->get_records('local_nexportfolio_handles', ['userid' => $userid]);
            $data = $DB->get_records('local_nexportfolio_data', ['userid' => $userid]);
            $github = $DB->get_records('local_nexportfolio_github', ['userid' => $userid]);
            $projects = $DB->get_records('local_nexportfolio_projects', ['userid' => $userid]);
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_nexportfolio')],
                (object) [
                    'handles' => array_values($handles),
                    'data' => array_values($data),
                    'github' => array_values($github),
                    'projects' => array_values($projects),
                ]
            );
        }
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $DB->delete_records('local_nexportfolio_handles');
        $DB->delete_records('local_nexportfolio_data');
        $DB->delete_records('local_nexportfolio_github');
        $DB->delete_records('local_nexportfolio_projects');
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel !== CONTEXT_SYSTEM) {
                continue;
            }
            $DB->delete_records('local_nexportfolio_handles', ['userid' => $userid]);
            $DB->delete_records('local_nexportfolio_data', ['userid' => $userid]);
            $DB->delete_records('local_nexportfolio_github', ['userid' => $userid]);
            $DB->delete_records('local_nexportfolio_projects', ['userid' => $userid]);
        }
    }

    /**
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_nexportfolio_handles', "userid $insql", $params);
        $DB->delete_records_select('local_nexportfolio_data', "userid $insql", $params);
        $DB->delete_records_select('local_nexportfolio_github', "userid $insql", $params);
        $DB->delete_records_select('local_nexportfolio_projects', "userid $insql", $params);
    }
}
