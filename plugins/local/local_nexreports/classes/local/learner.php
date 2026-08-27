<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Learner self-report helpers.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Data for the signed-in learner's own dashboard blocks.
 */
class learner {

    /**
     * Current user's daily time spent on site (Edwiser "My Time Spent On Site").
     *
     * Always scoped to the signed-in account. Admin/guest exclusion is skipped so
     * site admins still see their own heartbeat rows.
     *
     * @param int $days
     * @return array
     */
    public static function my_timespent(int $days = 7): array {
        global $USER;

        $userid = (int) $USER->id;
        if ($userid <= 1) {
            return [
                'period' => $days === 30 ? 30 : 7,
                'generated' => time(),
                'available' => false,
                'labels' => [],
                'values' => [],
                'average' => 0,
                'total' => 0,
                'change' => 0.0,
                'selecteduserid' => 0,
                'selectedusername' => '',
            ];
        }

        return overview::timespent_site($days === 30 ? 30 : 7, $userid, false);
    }
}
