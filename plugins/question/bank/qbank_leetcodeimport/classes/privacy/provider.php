<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy null provider.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Plugin does not store personal data.
 */
class provider implements null_provider {

    /**
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
