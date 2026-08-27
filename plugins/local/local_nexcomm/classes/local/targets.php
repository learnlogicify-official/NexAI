<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily / weekly activity targets.
 */
class targets {

    /**
     * @return int
     */
    public static function daily_goal(): int {
        return max(1, (int) (get_config('local_nexcomm', 'dailytarget') ?: 4));
    }

    /**
     * @return int
     */
    public static function weekly_goal(): int {
        return max(1, (int) (get_config('local_nexcomm', 'weeklytarget') ?: 20));
    }

    /**
     * @return int
     */
    public static function daily_bonus(): int {
        return max(0, (int) (get_config('local_nexcomm', 'dailybonus') ?: 15));
    }

    /**
     * @return int
     */
    public static function weekly_bonus(): int {
        return max(0, (int) (get_config('local_nexcomm', 'weeklybonus') ?: 75));
    }

    /**
     * Record a completed activity toward targets (once per activity per day/week).
     *
     * @param int $userid
     * @param int $activityid
     * @return array{dailyBonus:int,weeklyBonus:int}
     */
    public static function record_completion(int $userid, int $activityid): array {
        global $DB;

        $dailybonusawarded = 0;
        $weeklybonusawarded = 0;
        $daykey = gamification::day_key();
        $weekkey = gamification::week_key();

        $day = $DB->get_record('local_nexcomm_targetday', ['userid' => $userid, 'daykey' => $daykey]);
        $dayids = $day && $day->activityids ? (json_decode($day->activityids, true) ?: []) : [];
        if (!in_array((int) $activityid, array_map('intval', $dayids), true)) {
            $dayids[] = (int) $activityid;
            if ($day) {
                $day->completed = count($dayids);
                $day->activityids = json_encode(array_values($dayids));
                $DB->update_record('local_nexcomm_targetday', $day);
            } else {
                $day = (object) [
                    'userid' => $userid,
                    'daykey' => $daykey,
                    'completed' => count($dayids),
                    'claimed' => 0,
                    'activityids' => json_encode(array_values($dayids)),
                ];
                $day->id = $DB->insert_record('local_nexcomm_targetday', $day);
            }
        }

        $week = $DB->get_record('local_nexcomm_targetweek', ['userid' => $userid, 'weekkey' => $weekkey]);
        $weekids = $week && $week->activityids ? (json_decode($week->activityids, true) ?: []) : [];
        if (!in_array((int) $activityid, array_map('intval', $weekids), true)) {
            $weekids[] = (int) $activityid;
            if ($week) {
                $week->completed = count($weekids);
                $week->activityids = json_encode(array_values($weekids));
                $DB->update_record('local_nexcomm_targetweek', $week);
            } else {
                $week = (object) [
                    'userid' => $userid,
                    'weekkey' => $weekkey,
                    'completed' => count($weekids),
                    'claimed' => 0,
                    'activityids' => json_encode(array_values($weekids)),
                ];
                $week->id = $DB->insert_record('local_nexcomm_targetweek', $week);
            }
        }

        // Refresh records.
        $day = $DB->get_record('local_nexcomm_targetday', ['userid' => $userid, 'daykey' => $daykey]);
        $week = $DB->get_record('local_nexcomm_targetweek', ['userid' => $userid, 'weekkey' => $weekkey]);

        if ($day && !(int) $day->claimed && (int) $day->completed >= self::daily_goal()) {
            $bonus = self::daily_bonus();
            if ($bonus > 0) {
                gamification::add_xp($userid, $bonus, 0, 'daily_target_' . $daykey);
                $dailybonusawarded = $bonus;
            }
            $day->claimed = 1;
            $DB->update_record('local_nexcomm_targetday', $day);
        }

        if ($week && !(int) $week->claimed && (int) $week->completed >= self::weekly_goal()) {
            $bonus = self::weekly_bonus();
            if ($bonus > 0) {
                gamification::add_xp($userid, $bonus, 0, 'weekly_target_' . $weekkey);
                $weeklybonusawarded = $bonus;
            }
            $week->claimed = 1;
            $DB->update_record('local_nexcomm_targetweek', $week);
        }

        return [
            'dailyBonus' => $dailybonusawarded,
            'weeklyBonus' => $weeklybonusawarded,
        ];
    }

    /**
     * @param int $userid
     * @return array
     */
    public static function summary(int $userid): array {
        global $DB;
        $daygoal = self::daily_goal();
        $weekgoal = self::weekly_goal();
        $day = $DB->get_record('local_nexcomm_targetday', [
            'userid' => $userid,
            'daykey' => gamification::day_key(),
        ]);
        $week = $DB->get_record('local_nexcomm_targetweek', [
            'userid' => $userid,
            'weekkey' => gamification::week_key(),
        ]);
        $dailydone = $day ? (int) $day->completed : 0;
        $weeklydone = $week ? (int) $week->completed : 0;
        return [
            'dailyDone' => $dailydone,
            'dailyGoal' => $daygoal,
            'dailyPct' => min(100, (int) round(($dailydone / $daygoal) * 100)),
            'dailyComplete' => $dailydone >= $daygoal,
            'weeklyDone' => $weeklydone,
            'weeklyGoal' => $weekgoal,
            'weeklyPct' => min(100, (int) round(($weeklydone / $weekgoal) * 100)),
            'weeklyComplete' => $weeklydone >= $weekgoal,
        ];
    }
}
