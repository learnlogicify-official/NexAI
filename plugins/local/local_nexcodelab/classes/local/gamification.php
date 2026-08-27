<?php
// This file is part of Moodle - http://moodle.org/
/**
 * XP and streak helpers.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Gamification.
 */
class gamification {

    /**
     * @param int $userid
     * @return array{xp:int,streak:int,longest:int,rank:int,solved:int}
     */
    public static function user_stats(int $userid): array {
        global $DB;
        $xp = (int) ($DB->get_field('local_nexcodelab_userxp', 'xp', ['userid' => $userid]) ?: 0);
        $streak = $DB->get_record('local_nexcodelab_streak', ['userid' => $userid]);
        $solved = 0;
        if ($DB->get_manager()->table_exists('local_nexcodelab_mission_progress')) {
            $solved = (int) $DB->count_records('local_nexcodelab_mission_progress', [
                'userid' => $userid,
                'completed' => 1,
            ]);
        } else {
            $solved = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT problemid)
                   FROM {local_nexcodelab_submission}
                  WHERE userid = ? AND status = 'ACCEPTED'",
                [$userid]
            );
        }
        $rank = 1;
        if ($xp > 0) {
            $rank = 1 + (int) $DB->count_records_select('local_nexcodelab_userxp', 'xp > ?', [$xp]);
        }
        return [
            'xp' => $xp,
            'streak' => $streak ? (int) $streak->currentstreak : 0,
            'longest' => $streak ? (int) $streak->longest : 0,
            'rank' => $rank,
            'solved' => $solved,
        ];
    }

    /**
     * Award XP + streak on first ACCEPTED for a problem.
     *
     * @param int $userid
     * @param \stdClass $problem
     * @return array{awarded:int,firstSolve:bool,streakBonus:int}
     */
    public static function award_accept(int $userid, $problem): array {
        global $DB;

        $acceptcount = (int) $DB->count_records('local_nexcodelab_submission', [
            'userid' => $userid,
            'problemid' => (int) $problem->id,
            'status' => 'ACCEPTED',
        ]);
        // Caller inserts the ACCEPTED row first — only award on the first accept.
        if ($acceptcount !== 1) {
            return ['awarded' => 0, 'firstSolve' => false, 'streakBonus' => 0];
        }

        $amount = local_nexcodelab_xp_for_difficulty((string) $problem->difficulty);
        $bonus = (int) (get_config('local_nexcodelab', 'xp_firstbonus') ?: 15);
        $total = $amount;

        self::add_xp($userid, $amount, (int) $problem->id, 'solve');
        if ($bonus > 0) {
            self::add_xp($userid, $bonus, (int) $problem->id, 'firstbonus');
            $total += $bonus;
        }

        $streakbonus = self::bump_streak($userid);
        if ($streakbonus > 0) {
            self::add_xp($userid, $streakbonus, (int) $problem->id, 'streak');
            $total += $streakbonus;
        }

        return ['awarded' => $total, 'firstSolve' => true, 'streakBonus' => $streakbonus];
    }

    /**
     * Award XP for first pass of a mission step.
     *
     * @param int $userid
     * @param int $amount
     * @param int $missionid
     * @param int $stepid
     * @return int
     */
    public static function award_step_xp(int $userid, int $amount, int $missionid, int $stepid): int {
        if ($amount <= 0) {
            return 0;
        }
        self::add_xp($userid, $amount, $missionid, 'step:' . $stepid);
        return $amount;
    }

    /**
     * @param int $userid
     * @param int $amount
     * @param int $problemid
     * @param string $reason
     */
    public static function add_xp(int $userid, int $amount, int $problemid, string $reason): void {
        global $DB;
        if ($amount <= 0) {
            return;
        }
        $now = time();
        $rec = $DB->get_record('local_nexcodelab_userxp', ['userid' => $userid]);
        if ($rec) {
            $rec->xp = (int) $rec->xp + $amount;
            $rec->timemodified = $now;
            $DB->update_record('local_nexcodelab_userxp', $rec);
        } else {
            $DB->insert_record('local_nexcodelab_userxp', (object) [
                'userid' => $userid,
                'xp' => $amount,
                'timemodified' => $now,
            ]);
        }
        self::add_xp_event($userid, $problemid, $amount, $reason, $now);
    }

    /**
     * @param int $userid
     * @param int $problemid
     * @param int $amount
     * @param string $reason
     * @param int $now
     */
    private static function add_xp_event(int $userid, int $problemid, int $amount, string $reason, int $now): void {
        global $DB;
        $DB->insert_record('local_nexcodelab_xpevent', (object) [
            'userid' => $userid,
            'problemid' => $problemid,
            'amount' => $amount,
            'reason' => $reason,
            'timecreated' => $now,
        ]);
    }

    /**
     * @param int $userid
     * @return int streak day bonus awarded (0 if already counted today)
     */
    public static function bump_streak(int $userid): int {
        global $DB;
        $today = userdate(time(), '%Y-%m-%d');
        $rec = $DB->get_record('local_nexcodelab_streak', ['userid' => $userid]);
        $bonus = (int) (get_config('local_nexcodelab', 'xp_streakday') ?: 5);

        if (!$rec) {
            $DB->insert_record('local_nexcodelab_streak', (object) [
                'userid' => $userid,
                'currentstreak' => 1,
                'longest' => 1,
                'lastday' => $today,
            ]);
            return $bonus;
        }
        if ($rec->lastday === $today) {
            return 0;
        }
        $yesterday = userdate(time() - DAYSECS, '%Y-%m-%d');
        if ($rec->lastday === $yesterday) {
            $rec->currentstreak = (int) $rec->currentstreak + 1;
        } else {
            $rec->currentstreak = 1;
        }
        $rec->longest = max((int) $rec->longest, (int) $rec->currentstreak);
        $rec->lastday = $today;
        $DB->update_record('local_nexcodelab_streak', $rec);
        return $bonus;
    }

    /**
     * Return ranked users, optionally restricted to one institution.
     *
     * @param int $limit
     * @param string $institution Exact Moodle user institution value.
     * @return array
     */
    public static function leaderboard(int $limit = 50, string $institution = ''): array {
        global $DB;
        $limit = max(1, min(100, $limit));
        $institution = trim($institution);
        $params = ['accepted' => 'ACCEPTED'];
        $institutionwhere = '';
        if ($institution !== '') {
            $institutionwhere = ' AND u.institution = :institution';
            $params['institution'] = $institution;
        }
        $rows = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.institution, x.xp, x.timemodified,
                    COUNT(DISTINCT s.problemid) AS solved
               FROM {local_nexcodelab_userxp} x
               JOIN {user} u ON u.id = x.userid
          LEFT JOIN {local_nexcodelab_submission} s
                 ON s.userid = u.id AND s.status = :accepted
              WHERE u.deleted = 0
                    {$institutionwhere}
           GROUP BY u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.institution, x.xp, x.timemodified
           ORDER BY x.xp DESC, x.timemodified ASC, u.id ASC",
            $params,
            0,
            $limit
        );
        $out = [];
        $rank = 1;
        foreach ($rows as $r) {
            $out[] = [
                'rank' => $rank++,
                'userid' => (int) $r->id,
                'fullname' => fullname($r),
                'institution' => trim((string) ($r->institution ?? '')),
                'xp' => (int) $r->xp,
                'solved' => (int) $r->solved,
            ];
        }
        return $out;
    }

    /**
     * Institutions represented by users who have leaderboard XP.
     *
     * @return string[]
     */
    public static function leaderboard_institutions(): array {
        global $DB;
        $values = $DB->get_fieldset_sql(
            "SELECT DISTINCT u.institution
               FROM {local_nexcodelab_userxp} x
               JOIN {user} u ON u.id = x.userid
              WHERE u.deleted = 0
                AND u.institution IS NOT NULL
                AND u.institution <> ''
           ORDER BY u.institution ASC"
        );
        return array_values(array_filter(array_map('trim', $values), static function(string $value): bool {
            return $value !== '';
        }));
    }

    /**
     * Positional rank using the same ordering as leaderboard().
     *
     * Returns zero when the user has no XP row or does not belong to the
     * selected institution.
     *
     * @param int $userid
     * @param string $institution
     * @return int
     */
    public static function leaderboard_rank(int $userid, string $institution = ''): int {
        global $DB;
        $institution = trim($institution);
        $mine = $DB->get_record_sql(
            "SELECT x.userid, x.xp, x.timemodified, u.institution
               FROM {local_nexcodelab_userxp} x
               JOIN {user} u ON u.id = x.userid
              WHERE x.userid = :userid AND u.deleted = 0",
            ['userid' => $userid]
        );
        if (!$mine || ($institution !== '' && (string) $mine->institution !== $institution)) {
            return 0;
        }

        $params = [
            'myxp1' => (int) $mine->xp,
            'myxp2' => (int) $mine->xp,
            'mytime1' => (int) $mine->timemodified,
            'myxp3' => (int) $mine->xp,
            'mytime2' => (int) $mine->timemodified,
            'myuserid' => $userid,
        ];
        $institutionwhere = '';
        if ($institution !== '') {
            $institutionwhere = ' AND u.institution = :institution';
            $params['institution'] = $institution;
        }
        $ahead = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_nexcodelab_userxp} x
               JOIN {user} u ON u.id = x.userid
              WHERE u.deleted = 0
                    {$institutionwhere}
                AND (
                    x.xp > :myxp1
                    OR (x.xp = :myxp2 AND x.timemodified < :mytime1)
                    OR (x.xp = :myxp3 AND x.timemodified = :mytime2 AND x.userid < :myuserid)
                )",
            $params
        );
        return $ahead + 1;
    }
}
