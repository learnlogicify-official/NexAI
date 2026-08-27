<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexprofile.
 *
 * @package   local_nexprofile
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexprofile\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * No stored personal data — NexProfile only displays existing records.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
