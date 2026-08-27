<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * XP, streak, and ranking.
 */
class gamification {

    /**
     * @param int $userid
     * @return array{xp:int,streak:int,longest:int,rank:int}
     */
    public static function user_stats(int $userid): array {
        global $DB;
        $xp = (int) ($DB->get_field('local_nexcomm_userxp', 'xp', ['userid' => $userid]) ?: 0);
        $streak = $DB->get_record('local_nexcomm_streak', ['userid' => $userid]);
        $rank = 1;
        if ($xp > 0) {
            $rank = 1 + (int) $DB->count_records_select('local_nexcomm_userxp', 'xp > ?', [$xp]);
        }
        return [
            'xp' => $xp,
            'streak' => $streak ? (int) $streak->currentstreak : 0,
            'longest' => $streak ? (int) $streak->longest : 0,
            'rank' => $rank,
        ];
    }

    /**
     * @param string $difficulty
     * @param string $skill
     * @return int
     */
    public static function xp_for(string $difficulty, string $skill): int {
        $difficulty = strtolower($difficulty);
        $skill = strtolower($skill);
        $elevated = in_array($skill, ['speaking', 'writing'], true);
        $map = $elevated
            ? ['easy' => 15, 'medium' => 25, 'hard' => 40]
            : ['easy' => 10, 'medium' => 20, 'hard' => 30];
        return $map[$difficulty] ?? ($elevated ? 15 : 10);
    }

    /**
     * @param int $userid
     * @param int $amount
     * @param int $activityid
     * @param string $reason
     */
    public static function add_xp(int $userid, int $amount, int $activityid, string $reason): void {
        global $DB;
        if ($amount < 1 || $userid < 1) {
            return;
        }
        if ($DB->record_exists('local_nexcomm_xpevent', ['userid' => $userid, 'reason' => $reason])) {
            return;
        }
        $now = time();
        $rec = $DB->get_record('local_nexcomm_userxp', ['userid' => $userid]);
        if ($rec) {
            $rec->xp = (int) $rec->xp + $amount;
            $rec->timemodified = $now;
            $DB->update_record('local_nexcomm_userxp', $rec);
        } else {
            $DB->insert_record('local_nexcomm_userxp', (object) [
                'userid' => $userid,
                'xp' => $amount,
                'timemodified' => $now,
            ]);
        }
        $DB->insert_record('local_nexcomm_xpevent', (object) [
            'userid' => $userid,
            'activityid' => $activityid,
            'amount' => $amount,
            'reason' => $reason,
            'timecreated' => $now,
        ]);
        self::touch_streak($userid);
    }

    /**
     * @param int $userid
     */
    public static function touch_streak(int $userid): void {
        global $DB;
        $day = self::day_key();
        $rec = $DB->get_record('local_nexcomm_streak', ['userid' => $userid]);
        if (!$rec) {
            $DB->insert_record('local_nexcomm_streak', (object) [
                'userid' => $userid,
                'currentstreak' => 1,
                'longest' => 1,
                'lastday' => $day,
            ]);
            return;
        }
        if ($rec->lastday === $day) {
            return;
        }
        $yesterday = self::day_key(time() - DAYSECS);
        if ($rec->lastday === $yesterday) {
            $rec->currentstreak = (int) $rec->currentstreak + 1;
        } else {
            $rec->currentstreak = 1;
        }
        $rec->longest = max((int) $rec->longest, (int) $rec->currentstreak);
        $rec->lastday = $day;
        $DB->update_record('local_nexcomm_streak', $rec);
    }

    /**
     * @param int $ts
     * @return string YYYYMMDD
     */
    public static function day_key(int $ts = 0): string {
        if (!$ts) {
            $ts = time();
        }
        return userdate($ts, '%Y%m%d');
    }

    /**
     * ISO-ish week key YYYYWW in user timezone.
     *
     * @param int $ts
     * @return string
     */
    public static function week_key(int $ts = 0): string {
        if (!$ts) {
            $ts = time();
        }
        return userdate($ts, '%G%V');
    }

    /**
     * @param int $limit
     * @param string $institution
     * @return array
     */
    public static function leaderboard(int $limit = 50, string $institution = ''): array {
        global $DB;
        $limit = max(1, min(200, $limit));
        $institution = trim($institution);
        $params = [];
        $instsql = '';
        if ($institution !== '') {
            $instsql = ' AND u.institution = :inst';
            $params['inst'] = $institution;
        }
        $sql = "SELECT x.userid, x.xp, u.firstname, u.lastname, u.institution
                  FROM {local_nexcomm_userxp} x
                  JOIN {user} u ON u.id = x.userid
                 WHERE u.deleted = 0 AND u.suspended = 0 AND x.xp > 0
                   {$instsql}
              ORDER BY x.xp DESC, x.timemodified ASC, u.id ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, $limit);
        $entries = [];
        $rank = 1;
        foreach ($rows as $r) {
            $skillcounts = catalog::skill_pass_counts((int) $r->userid);
            $entries[] = [
                'rank' => $rank++,
                'userid' => (int) $r->userid,
                'fullname' => fullname($r),
                'institution' => trim((string) $r->institution),
                'xp' => (int) $r->xp,
                'reading' => $skillcounts['reading'],
                'listening' => $skillcounts['listening'],
                'speaking' => $skillcounts['speaking'],
                'writing' => $skillcounts['writing'],
            ];
        }
        return $entries;
    }

    /**
     * @param int $userid
     * @param string $institution
     * @return int
     */
    public static function leaderboard_rank(int $userid, string $institution = ''): int {
        $rank = 1;
        foreach (self::leaderboard(500, $institution) as $row) {
            if ((int) $row['userid'] === $userid) {
                return $rank;
            }
            $rank++;
        }
        return 0;
    }

    /**
     * @return string[]
     */
    public static function institutions(): array {
        global $DB;
        $sql = "SELECT DISTINCT u.institution
                  FROM {local_nexcomm_userxp} x
                  JOIN {user} u ON u.id = x.userid
                 WHERE u.deleted = 0 AND u.institution <> ''
              ORDER BY u.institution ASC";
        $raw = $DB->get_fieldset_sql($sql) ?: [];
        $out = [];
        foreach ($raw as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }
}
