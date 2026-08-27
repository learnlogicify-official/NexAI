<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexstack.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexstack_upgrade($oldversion) {
    global $CFG;

    if ($oldversion < 2026081607) {
        require_once($CFG->dirroot . '/local/nexstack/lib.php');
        local_nexstack_purge_custom_menu_leftovers();
        set_config('enablemenu', 0, 'local_nexstack');

        // Overwrite/remove leftovers that zip upgrades may leave behind.
        $dead = [
            $CFG->dirroot . '/local/nexstack/classes/local/hook_callbacks.php',
        ];
        foreach ($dead as $path) {
            if (is_readable($path)) {
                @unlink($path);
            }
        }

        upgrade_plugin_savepoint(true, 2026081607, 'local', 'nexstack');
    }

    if ($oldversion < 2026081617) {
        \local_nexstack\local\seed::refresh_mission_copy();
        upgrade_plugin_savepoint(true, 2026081617, 'local', 'nexstack');
    }

    return true;
}
