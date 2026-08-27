<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexportfolio_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082001) {
        $table = new xmldb_table('local_nexportfolio_github');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('access_token', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('github_user_id', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL);
        $table->add_field('github_login', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('avatar_url', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_UNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_nexportfolio_projects');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'github');
        $table->add_field('github_id', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('owner', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '200', null, XMLDB_NOTNULL);
        $table->add_field('url', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('homepage', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('primary_language', XMLDB_TYPE_CHAR, '60', null, null, null);
        $table->add_field('stars', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('forks', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('watchers', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('open_issues', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('topics_json', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('languages_json', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('visibility', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'public');
        $table->add_field('is_fork', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('pinned', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastpush', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('importedjson', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('useridgithub', XMLDB_KEY_UNIQUE, ['userid', 'github_id']);

        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('source', XMLDB_INDEX_NOTUNIQUE, ['source']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082001, 'local', 'nexportfolio');
    }

    if ($oldversion < 2026082002) {
        $table = new xmldb_table('local_nexportfolio_projects');
        $field = new xmldb_field('readme', XMLDB_TYPE_TEXT, null, null, null, null, null, 'description');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082002, 'local', 'nexportfolio');
    }

    if ($oldversion < 2026082003) {
        $table = new xmldb_table('local_nexportfolio_github');
        $field = new xmldb_field('heatmap_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('heatmap_fetch', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'heatmap_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082003, 'local', 'nexportfolio');
    }

    if ($oldversion < 2026082006) {
        $table = new xmldb_table('local_nexportfolio_github');
        $field = new xmldb_field('stats_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'heatmap_fetch');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('stats_fetch', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'stats_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082006, 'local', 'nexportfolio');
    }

    return true;
}
