<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexlogin.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexlogin\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Null provider — no personal data stored.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
