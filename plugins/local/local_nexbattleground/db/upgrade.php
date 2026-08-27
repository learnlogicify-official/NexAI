<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexbattleground.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexbattleground_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080902) {
        $table = new xmldb_table('local_nexbattleground_battle');
        $field = new xmldb_field('roomcode', XMLDB_TYPE_CHAR, '6', null, XMLDB_NOTNULL, null, '', 'inviteeid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('roomcode_ix', XMLDB_INDEX_NOTUNIQUE, ['roomcode', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026080902, 'local', 'nexbattleground');
    }

    return true;
}
