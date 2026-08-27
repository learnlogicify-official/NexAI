<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_learnlogic.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_learnlogic_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080424) {
        $table = new xmldb_table('local_learnlogic_problem');
        $field = new xmldb_field('sourcequestionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'defaultlanguage');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('sourceq_ix', XMLDB_INDEX_NOTUNIQUE, ['sourcequestionid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026080424, 'local', 'learnlogic');
    }

    if ($oldversion < 2026081300) {
        // Linked CR problems must use live questiontext — drop cached statement duplicates.
        $DB->execute(
            "UPDATE {local_learnlogic_problem}
                SET statement = ''
              WHERE sourcequestionid > 0"
        );
        upgrade_plugin_savepoint(true, 2026081300, 'local', 'learnlogic');
    }

    if ($oldversion < 2026082213) {
        $table = new xmldb_table('local_learnlogic_solution');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('problemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('language', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $table->add_field('code', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('explanation', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('prob_lang_uix', XMLDB_INDEX_UNIQUE, ['problemid', 'language']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_learnlogic_sample_explanation');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('problemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sampleindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('explanation', XMLDB_TYPE_TEXT, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('prob_sample_uix', XMLDB_INDEX_UNIQUE, ['problemid', 'sampleindex']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082213, 'local', 'learnlogic');
    }

    if ($oldversion < 2026082217) {
        $table = new xmldb_table('local_learnlogic_tag');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'topic', 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('kind_ix', XMLDB_INDEX_NOTUNIQUE, ['kind']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $DB->execute("UPDATE {local_learnlogic_tag} SET kind = 'topic' WHERE kind IS NULL OR kind = ''");
        upgrade_plugin_savepoint(true, 2026082217, 'local', 'learnlogic');
    }

    return true;
}
