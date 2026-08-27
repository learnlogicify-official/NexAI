<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Combined student leaderboard: course grades + Practice + CodeLab + BattleGround.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Site-wide overall leaderboard.
 *
 * BattleGround wins are stored as NexPractice XP events (`battle_win_%`).
 * Those amounts are shown in the Battle column and subtracted from Practice
 * so they are not counted twice.
 */
class overall_leaderboard {

    public const PERPAGE = 25;

    /**
     * One page of ranked students plus the viewer's own row.
     *
     * @param int $page 1-based page
     * @param int $perpage
     * @param string $institution Exact Moodle user institution, or empty for all
     * @param int $viewerid
     * @return array
     */
    public static function page(int $page, int $perpage, string $institution, int $viewerid): array {
        global $DB;

        $perpage = max(1, min(100, $perpage));
        $page = max(1, $page);
        $institution = trim($institution);

        $parts = self::score_parts();
        $params = $parts['params'];
        $where = self::user_where($institution, $params);
        $scored = $where . ' AND ' . $parts['total'] . ' > 0';

        $total = (int) $DB->count_records_sql(
            "SELECT COUNT(1) FROM {user} u {$parts['joins']} WHERE {$scored}",
            $params
        );

        $pages = max(1, (int) ceil(max(1, $total) / $perpage));
        if ($total < 1) {
            $pages = 1;
            $page = 1;
        } else if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perpage;

        $entries = [];
        if ($total > 0) {
            $entries = self::fetch_entries($parts, $params, $scored, $offset, $perpage, $viewerid);
        }

        return [
            'entries' => $entries,
            'top3' => self::top3($viewerid),
            'institutions' => self::institutions(),
            'current' => self::current_user($viewerid, $institution, $parts),
            'page' => $page,
            'perpage' => $perpage,
            'total' => $total,
        ];
    }

    /**
     * Site-wide top three, ignoring the college filter.
     *
     * @param int $viewerid
     * @return array
     */
    public static function top3(int $viewerid): array {
        $parts = self::score_parts();
        $params = $parts['params'];
        $where = self::user_where('', $params);
        $scored = $where . ' AND ' . $parts['total'] . ' > 0';
        return self::fetch_entries($parts, $params, $scored, 0, 3, $viewerid);
    }

    /**
     * @param array $parts
     * @param array $params
     * @param string $scored
     * @param int $offset
     * @param int $limit
     * @param int $viewerid
     * @return array
     */
    private static function fetch_entries(
        array $parts,
        array $params,
        string $scored,
        int $offset,
        int $limit,
        int $viewerid
    ): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.institution, u.email, u.picture, u.imagealt,
                       {$parts['course']} AS coursegrade,
                       {$parts['practice']} AS practicexp,
                       {$parts['codelab']} AS codelabxp,
                       {$parts['battle']} AS battlexp,
                       {$parts['total']} AS total
                  FROM {user} u
                       {$parts['joins']}
                 WHERE {$scored}
              ORDER BY {$parts['total']} DESC, u.id ASC";
        $rows = $DB->get_records_sql($sql, $params, $offset, $limit);
        $out = [];
        $rank = $offset + 1;
        foreach ($rows as $row) {
            $out[] = self::format_entry($row, $rank, $viewerid);
            $rank++;
        }
        return $out;
    }

    /**
     * Distinct colleges that appear on the unfiltered board.
     *
     * @return string[]
     */
    public static function institutions(): array {
        global $DB;

        $parts = self::score_parts();
        $params = $parts['params'];
        $where = self::user_where('', $params);
        $sql = "SELECT DISTINCT u.institution
                  FROM {user} u
                       {$parts['joins']}
                 WHERE {$where}
                   AND {$parts['total']} > 0
                   AND u.institution IS NOT NULL
                   AND u.institution <> ''
              ORDER BY u.institution ASC";
        $values = $DB->get_fieldset_sql($sql, $params);
        return array_values(array_filter(array_map('trim', $values), static function(string $value): bool {
            return $value !== '';
        }));
    }

    /**
     * Score SQL fragments. Missing plugin tables become 0.
     *
     * @return array
     */
    private static function score_parts(): array {
        global $DB;

        $joins = '';
        $params = [];
        $course = '0';
        $practice = '0';
        $codelab = '0';
        $battle = '0';

        if (self::table_exists('grade_grades') && self::table_exists('grade_items')) {
            $joins .= " LEFT JOIN (
                            SELECT gg.userid, SUM(gg.finalgrade) AS coursegrade
                              FROM {grade_grades} gg
                              JOIN {grade_items} gi ON gi.id = gg.itemid
                             WHERE gi.itemtype = 'course'
                               AND gg.finalgrade IS NOT NULL
                          GROUP BY gg.userid
                        ) gsc ON gsc.userid = u.id";
            $course = 'COALESCE(gsc.coursegrade, 0)';
        }

        $haspractice = self::table_exists('local_learnlogic_userxp');
        $hasevents = self::table_exists('local_learnlogic_xpevent');

        if ($hasevents) {
            $joins .= " LEFT JOIN (
                            SELECT userid, SUM(amount) AS battlexp
                              FROM {local_learnlogic_xpevent}
                             WHERE " . $DB->sql_like('reason', ':battlepat') . "
                          GROUP BY userid
                        ) btl ON btl.userid = u.id";
            $params['battlepat'] = 'battle_win_%';
            $battle = 'COALESCE(btl.battlexp, 0)';
        }

        if ($haspractice) {
            $joins .= ' LEFT JOIN {local_learnlogic_userxp} pxp ON pxp.userid = u.id';
            if ($hasevents) {
                $practice = "CASE WHEN (COALESCE(pxp.xp, 0) - {$battle}) > 0
                                  THEN (COALESCE(pxp.xp, 0) - {$battle})
                                  ELSE 0 END";
            } else {
                $practice = 'COALESCE(pxp.xp, 0)';
            }
        }

        if (self::table_exists('local_nexcodelab_userxp')) {
            $joins .= ' LEFT JOIN {local_nexcodelab_userxp} cxp ON cxp.userid = u.id';
            $codelab = 'COALESCE(cxp.xp, 0)';
        }

        return [
            'joins' => $joins,
            'params' => $params,
            'course' => $course,
            'practice' => $practice,
            'codelab' => $codelab,
            'battle' => $battle,
            'total' => "({$course} + {$practice} + {$codelab} + {$battle})",
        ];
    }

    /**
     * Active, non-guest users. Optional college filter.
     *
     * @param string $institution
     * @param array $params
     * @return string
     */
    private static function user_where(string $institution, array &$params): string {
        global $CFG;

        $params['guestid'] = (int) ($CFG->siteguest ?? 1);
        $sql = 'u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1 AND u.id <> :guestid';
        if ($institution !== '') {
            $params['institution'] = $institution;
            $sql .= ' AND u.institution = :institution';
        }
        return $sql;
    }

    /**
     * Viewer rank + column breakdown for the selected filter.
     *
     * @param int $viewerid
     * @param string $institution
     * @param array $parts
     * @return array
     */
    private static function current_user(int $viewerid, string $institution, array $parts): array {
        global $DB;

        $empty = [
            'rank' => 0,
            'coursegrade' => 0,
            'practicexp' => 0,
            'codelabxp' => 0,
            'battlexp' => 0,
            'total' => 0,
        ];
        if ($viewerid < 2) {
            return $empty;
        }

        $params = $parts['params'];
        $params['viewerid'] = $viewerid;
        $mine = $DB->get_record_sql(
            "SELECT u.id, u.institution,
                    {$parts['course']} AS coursegrade,
                    {$parts['practice']} AS practicexp,
                    {$parts['codelab']} AS codelabxp,
                    {$parts['battle']} AS battlexp,
                    {$parts['total']} AS total
               FROM {user} u
                    {$parts['joins']}
              WHERE u.id = :viewerid AND u.deleted = 0",
            $params
        );
        if (!$mine) {
            return $empty;
        }

        $current = [
            'rank' => 0,
            'coursegrade' => (int) round((float) $mine->coursegrade),
            'practicexp' => (int) round((float) $mine->practicexp),
            'codelabxp' => (int) round((float) $mine->codelabxp),
            'battlexp' => (int) round((float) $mine->battlexp),
            'total' => (int) round((float) $mine->total),
        ];

        if ($institution !== '' && trim((string) ($mine->institution ?? '')) !== $institution) {
            return $current;
        }
        if ((float) $mine->total <= 0) {
            return $current;
        }

        $rankparams = $parts['params'];
        $where = self::user_where($institution, $rankparams);
        // Moodle counts every :name occurrence, so names must not be reused.
        $rankparams['mytotal1'] = (float) $mine->total;
        $rankparams['mytotal2'] = (float) $mine->total;
        $rankparams['myid'] = $viewerid;
        $ahead = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {user} u
                    {$parts['joins']}
              WHERE {$where}
                AND {$parts['total']} > 0
                AND ({$parts['total']} > :mytotal1
                     OR ({$parts['total']} = :mytotal2 AND u.id < :myid))",
            $rankparams
        );
        $current['rank'] = $ahead + 1;
        return $current;
    }

    /**
     * @param \stdClass $row
     * @param int $rank
     * @param int $viewerid
     * @return array
     */
    private static function format_entry(\stdClass $row, int $rank, int $viewerid): array {
        return [
            'rank' => $rank,
            'userid' => (int) $row->id,
            'fullname' => fullname($row),
            'institution' => trim((string) ($row->institution ?? '')),
            'picture' => self::picture_url($row),
            'coursegrade' => (int) round((float) $row->coursegrade),
            'practicexp' => (int) round((float) $row->practicexp),
            'codelabxp' => (int) round((float) $row->codelabxp),
            'battlexp' => (int) round((float) $row->battlexp),
            'total' => (int) round((float) $row->total),
            'isme' => (int) $row->id === $viewerid,
        ];
    }

    /**
     * @param \stdClass $user
     * @return string
     */
    private static function picture_url(\stdClass $user): string {
        global $PAGE;
        try {
            $pic = new \user_picture($user);
            $pic->size = 120;
            $pic->link = false;
            return $pic->get_url($PAGE)->out(false);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function table_exists(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists($table);
    }
}
