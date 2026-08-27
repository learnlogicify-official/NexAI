<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexcomm.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexcomm_upgrade($oldversion) {
    if ($oldversion < 2026080910) {
        \local_nexcomm\local\seed::ensure();
        upgrade_plugin_savepoint(true, 2026080910, 'local', 'nexcomm');
    }
    if ($oldversion < 2026080911) {
        upgrade_plugin_savepoint(true, 2026080911, 'local', 'nexcomm');
    }
    if ($oldversion < 2026080912) {
        \local_nexcomm\local\db_install_lessons::create_tables();
        \local_nexcomm\local\lesson_seed::ensure();
        upgrade_plugin_savepoint(true, 2026080912, 'local', 'nexcomm');
    }
    return true;
}
