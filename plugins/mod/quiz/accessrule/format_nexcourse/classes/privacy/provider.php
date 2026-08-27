<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider (null).
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcourse\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy subsystem implementation.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
