<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Dwell-time tracking for NexReports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Records measured time-on-page reported by the browser heartbeat.
 *
 * Each row is one visit window (user + course + activity). The client counts
 * seconds while the tab is visible and posts them periodically; consecutive
 * hits within REUSE_WINDOW extend the same row instead of creating a new one.
 */
class tracking {

    /** Reuse an existing row when its last ping is within this many seconds. */
    public const REUSE_WINDOW = 300;

    /** Default seconds between client flushes. */
    public const DEFAULT_FREQUENCY = 60;

    /**
     * Whether tracking is enabled for the current user/session.
     *
     * @return bool
     */
    public static function enabled(): bool {
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        if (\core\session\manager::is_loggedinas()) {
            return false;
        }
        return (string) get_config('local_nexreports', 'enabletracking') !== '0';
    }

    /**
     * Configured flush frequency in seconds.
     *
     * @return int
     */
    public static function frequency(): int {
        $seconds = (int) (get_config('local_nexreports', 'trackfrequency') ?: self::DEFAULT_FREQUENCY);
        return max(30, min(900, $seconds));
    }

    /**
     * Find or create the tracking row for a page context and return its id.
     *
     * @param int $contextid
     * @return int
     */
    public static function start(int $contextid): int {
        global $DB, $USER;

        $now = time();
        $courseid = SITEID;
        $cmid = 0;

        $context = $DB->get_record('context', ['id' => $contextid]);
        if ($context) {
            if ((int) $context->contextlevel === CONTEXT_COURSE) {
                $courseid = (int) $context->instanceid;
            } else if ((int) $context->contextlevel === CONTEXT_MODULE) {
                $cm = $DB->get_record('course_modules', ['id' => $context->instanceid], 'id, course');
                if ($cm) {
                    $courseid = (int) $cm->course;
                    $cmid = (int) $cm->id;
                }
            }
        }

        $sql = "SELECT id
                  FROM {nexreports_tracking}
                 WHERE userid = :userid
                   AND courseid = :courseid
                   AND cmid = :cmid
                   AND lastping >= :since
              ORDER BY lastping DESC";
        $existing = $DB->get_record_sql($sql, [
            'userid' => (int) $USER->id,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'since' => $now - self::REUSE_WINDOW,
        ], IGNORE_MULTIPLE);
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) $DB->insert_record('nexreports_tracking', (object) [
            'userid' => (int) $USER->id,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'timestart' => $now,
            'timespent' => 0,
            'lastping' => $now,
        ]);
    }

    /**
     * Add reported seconds to a tracking row owned by the current user.
     *
     * @param int $id
     * @param int $seconds
     * @return bool
     */
    public static function ping(int $id, int $seconds): bool {
        global $DB, $USER;

        // Clamp to twice the flush frequency so a tampered client cannot inflate totals.
        $seconds = max(0, min($seconds, self::frequency() * 2));
        if ($seconds === 0) {
            return true;
        }

        $sql = "UPDATE {nexreports_tracking}
                   SET timespent = timespent + :seconds,
                       lastping = :now
                 WHERE id = :id
                   AND userid = :userid";
        return $DB->execute($sql, [
            'seconds' => $seconds,
            'now' => time(),
            'id' => $id,
            'userid' => (int) $USER->id,
        ]);
    }

    /**
     * Timestamp of the earliest row with real measured seconds, or 0.
     * Ignores empty start() rows so they do not disable log-gap fallback.
     *
     * @return int
     */
    public static function first_tracked(): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT MIN(timestart) FROM {nexreports_tracking} WHERE timespent > 0'
        );
    }
}
