<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider (null) for local_nexnavbar.
 *
 * @package    local_nexnavbar
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexnavbar\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\null_provider;

/**
 * CSS-only plugin — no personal data stored.
 */
class provider implements null_provider {

    /**
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
