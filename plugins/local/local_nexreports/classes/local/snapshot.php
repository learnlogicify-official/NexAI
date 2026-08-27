<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Persistent precomputed overview blocks.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read/write helpers for the nexreports_snapshot table.
 */
class snapshot {

    public const BLOCK_SUMMARY = 'summary';
    public const BLOCK_TIMESPENT_SITE = 'timespent_site';
    public const BLOCK_TIMESPENT_COURSE = 'timespent_course';

    /**
     * Maximum age in seconds before a stored snapshot is treated as missing.
     *
     * @return int
     */
    public static function max_age(): int {
        $minutes = (int) (get_config('local_nexreports', 'snapshotmaxage') ?: 30);
        return max(5, min(24 * 60, $minutes)) * MINSECS;
    }

    /**
     * Load a fresh snapshot payload, or null when missing/stale/wrong session gap.
     *
     * @param string $blockname
     * @param int $perioddays
     * @param int $userid
     * @param int $courseid
     * @return array|null
     */
    public static function get(string $blockname, int $perioddays, int $userid = 0, int $courseid = 0): ?array {
        global $DB;

        $row = $DB->get_record('nexreports_snapshot', [
            'blockname' => $blockname,
            'perioddays' => $perioddays,
            'userid' => $userid,
            'courseid' => $courseid,
        ]);
        if (!$row) {
            return null;
        }

        $needsgap = ($blockname === self::BLOCK_TIMESPENT_SITE
            || $blockname === self::BLOCK_TIMESPENT_COURSE);
        if ($needsgap && (int) $row->sessiongap !== overview::session_gap()) {
            return null;
        }
        if ((time() - (int) $row->timemodified) > self::max_age()) {
            return null;
        }

        $payload = json_decode($row->payload, true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Upsert a snapshot row.
     *
     * @param string $blockname
     * @param int $perioddays
     * @param int $userid
     * @param int $courseid
     * @param array $payload
     * @param int $sessiongap
     */
    public static function put(
        string $blockname,
        int $perioddays,
        int $userid,
        int $courseid,
        array $payload,
        int $sessiongap = 0
    ): void {
        global $DB;

        $now = time();
        $record = (object) [
            'blockname' => $blockname,
            'perioddays' => $perioddays,
            'userid' => $userid,
            'courseid' => $courseid,
            'sessiongap' => $sessiongap,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timemodified' => $now,
        ];

        $existing = $DB->get_record('nexreports_snapshot', [
            'blockname' => $blockname,
            'perioddays' => $perioddays,
            'userid' => $userid,
            'courseid' => $courseid,
        ], 'id');

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('nexreports_snapshot', $record);
        } else {
            $DB->insert_record('nexreports_snapshot', $record);
        }
    }
}
