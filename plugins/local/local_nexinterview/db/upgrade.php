<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexinterview.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexinterview_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081512) {
        $table = new xmldb_table('local_nexinterview_interviewer');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '120', null, XMLDB_NOTNULL, null, '');
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('roletrack', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'sde_intern');
        $table->add_field('topics', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('durationminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '17');
        $table->add_field('style', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'friendly');
        $table->add_field('briefing', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('includecoding', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('enabled_ix', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081512, 'local', 'nexinterview');
    }

    if ($oldversion < 2026082701) {
        $table = new xmldb_table('local_nexinterview_interviewer');

        $style = new xmldb_field('style', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'friendly');
        if ($dbman->field_exists($table, $style)) {
            $dbman->change_field_precision($table, $style);
        }

        $fields = [
            new xmldb_field('difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'intermediate'),
            new xmldb_field('pace', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'standard'),
            new xmldb_field('questionmix', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'conceptual'),
            new xmldb_field('followupdepth', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moderate'),
            new xmldb_field('avoidtopics', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, ''),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026082701, 'local', 'nexinterview');
    }

    if ($oldversion < 2026082704) {
        $table = new xmldb_table('local_nexinterview_interviewer');
        $field = new xmldb_field('qaminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026082704, 'local', 'nexinterview');
    }

    if ($oldversion < 2026082709) {
        $table = new xmldb_table('local_nexinterview_attempt');
        $field = new xmldb_field('scoresjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026082709, 'local', 'nexinterview');
    }

    return true;
}
