<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_format_nexcoursepro_upgrade(int $oldversion): bool {
    // Services / AJAX functions are picked up via version bump + db/services.php.
    return true;
}
