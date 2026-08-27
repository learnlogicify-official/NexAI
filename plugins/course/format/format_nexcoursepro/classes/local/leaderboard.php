<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course grade leaderboard for the NexCoursePro learn shell.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\local;

defined('MOODLE_INTERNAL') || die();

use completion_info;
use context_course;
use context_user;
use moodle_url;

/**
 * Build leaderboard payload (overall list + current user card).
 */
class leaderboard {

    /** Rows per page. */
    public const PERPAGE = 25;

    /**
     * @param int $courseid
     * @param int $userid
     * @param array|null $flat
     * @param string $institution
     * @param int $page 0-based
     * @param int $perpage
     * @return array
     */
    public static function export(
        int $courseid,
        int $userid,
        ?array $flat = null,
        string $institution = '',
        int $page = 0,
        int $perpage = self::PERPAGE
    ): array {
        global $CFG;

        $institution = trim($institution);
        $perpage = max(1, min(100, $perpage > 0 ? $perpage : self::PERPAGE));
        $page = max(0, $page);

        $pixbase = (new moodle_url('/course/format/nexcoursepro/pix'))->out(false);
        $icons = [
            'scoreicon' => $pixbase . '/score.svg',
            'rankicon' => $pixbase . '/rank.svg',
            'collegeicon' => $pixbase . '/rank.svg',
        ];

        $empty = array_merge([
            'available' => false,
            'unavailablemessage' => get_string('leaderboardunavailable', 'format_nexcoursepro'),
            'me' => null,
            'hasme' => false,
            'entries' => [],
            'hasentries' => false,
            'playercount' => 0,
            'total' => 0,
            'page' => 0,
            'perpage' => $perpage,
            'institutions' => [],
            'institution' => $institution,
            'coursename' => '',
        ], $icons);

        if ($courseid < 1 || $userid < 1) {
            return $empty;
        }

        try {
            require_once($CFG->libdir . '/gradelib.php');
            require_once($CFG->libdir . '/completionlib.php');

            $course = get_course($courseid);
            $context = context_course::instance($courseid);
            $coursename = format_string($course->fullname, true, ['context' => $context]);

            $courseitem = \grade_item::fetch_course_item($courseid);
            if (!$courseitem || !(float) $courseitem->grademax) {
                return array_merge($empty, ['coursename' => $coursename]);
            }
            if ($courseitem->is_hidden() && !has_capability('moodle/grade:viewhidden', $context)) {
                return array_merge($empty, ['coursename' => $coursename]);
            }

            $decimals = method_exists($courseitem, 'get_decimals')
                ? (int) $courseitem->get_decimals()
                : (int) ($courseitem->decimals ?? 2);
            $grademax = (float) $courseitem->grademax;
            $totaldisplay = format_float($grademax, $decimals, true);

            $users = get_enrolled_users(
                $context,
                '',
                0,
                'u.id, u.username, u.institution, u.picture, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.imagealt, u.email',
                'u.lastname ASC, u.firstname ASC',
                0,
                0,
                true
            );
            if (empty($users)) {
                return array_merge($empty, $icons, [
                    'available' => true,
                    'unavailablemessage' => '',
                    'coursename' => $coursename,
                ]);
            }

            $studentids = self::student_user_ids($context);
            if (!empty($studentids)) {
                $users = array_filter($users, static function ($u) use ($studentids) {
                    return isset($studentids[(int) $u->id]);
                });
            }
            if (empty($users)) {
                return array_merge($empty, $icons, [
                    'available' => true,
                    'unavailablemessage' => '',
                    'coursename' => $coursename,
                ]);
            }

            $userids = array_map(static function ($u) {
                return (int) $u->id;
            }, $users);
            $grades = \grade_grade::fetch_users_grades($courseitem, $userids, true);
            $canviewparticipants = has_capability('moodle/course:viewparticipants', $context);
            $canviewallnames = $canviewparticipants || has_capability('moodle/course:update', $context);

            $rows = [];
            $colleges = [];
            foreach ($users as $user) {
                $uid = (int) $user->id;
                $college = trim((string) ($user->institution ?? ''));
                if ($college !== '') {
                    $colleges[$college] = true;
                }

                $grade = $grades[$uid] ?? null;
                if ($grade) {
                    $grade->grade_item = $courseitem;
                    if (method_exists($grade, 'is_hidden') && $grade->is_hidden()
                            && !has_capability('moodle/grade:viewhidden', $context)
                            && $uid !== $userid) {
                        continue;
                    }
                }

                $final = null;
                if ($grade && $grade->finalgrade !== null && $grade->finalgrade !== '') {
                    $final = (float) $grade->finalgrade;
                }

                $isme = ($uid === $userid);
                $showidentity = $isme || $canviewallnames
                    || ($canviewparticipants && has_capability('moodle/user:viewdetails', context_user::instance($uid)));

                $name = $showidentity
                    ? self::firstname_lastname($user)
                    : get_string('leaderboardanonymous', 'format_nexcoursepro');
                $username = $showidentity ? (string) ($user->username ?? '') : '—';

                $levelinfo = gamification::level_from_score($final);
                $avatarid = gamification::avatar_for_user($uid, (int) $levelinfo['level']);

                $rows[] = [
                    'userid' => $uid,
                    'name' => $name,
                    'username' => $username,
                    'institution' => $college,
                    'final' => $final,
                    'grade' => $final === null ? '—' : format_float($final, $decimals, true),
                    'percent' => $final === null
                        ? '—'
                        : ((int) round(($final / $grademax) * 100) . '%'),
                    'percentnum' => $final === null ? -1 : (int) round(($final / $grademax) * 100),
                    'isme' => $isme,
                    'avatar' => $avatarid,
                    'avatarurl' => gamification::avatar_url($avatarid),
                    'level' => (int) $levelinfo['level'],
                ];
            }

            $institutions = array_keys($colleges);
            sort($institutions, SORT_NATURAL | SORT_FLAG_CASE);

            self::sort_rows($rows);

            // Overall ranks (all students).
            $overalltotal = count($rows);
            $overallrankbyuser = [];
            $rank = 0;
            foreach ($rows as &$rowref) {
                $rank++;
                $rowref['overallrank'] = $rank;
                $overallrankbyuser[(int) $rowref['userid']] = $rank;
            }
            unset($rowref);

            // College ranks (within each institution).
            $bycollege = [];
            foreach ($rows as $row) {
                $key = $row['institution'] !== '' ? $row['institution'] : '__none__';
                $bycollege[$key][] = $row;
            }
            $collegerankbyuser = [];
            $collegetotalbyuser = [];
            foreach ($bycollege as $list) {
                self::sort_rows($list);
                $ctotal = count($list);
                $cr = 0;
                foreach ($list as $crow) {
                    $cr++;
                    $collegerankbyuser[(int) $crow['userid']] = $cr;
                    $collegetotalbyuser[(int) $crow['userid']] = $ctotal;
                }
            }
            foreach ($rows as &$rowref) {
                $uid = (int) $rowref['userid'];
                $rowref['collegerank'] = (int) ($collegerankbyuser[$uid] ?? 0);
                $rowref['collegetotal'] = (int) ($collegetotalbyuser[$uid] ?? 0);
            }
            unset($rowref);

            // Filtered view for the table.
            $filtered = $rows;
            if ($institution !== '') {
                $filtered = array_values(array_filter($rows, static function ($row) use ($institution) {
                    return ($row['institution'] ?? '') === $institution;
                }));
                self::sort_rows($filtered);
            }

            $total = count($filtered);
            $pages = max(1, (int) ceil($total / $perpage));
            if ($page > $pages - 1) {
                $page = max(0, $pages - 1);
            }

            // Display ranks in filtered list (1..n for current filter).
            $display = [];
            $fr = 0;
            foreach ($filtered as $row) {
                $fr++;
                $row['rank'] = $fr;
                $row['ranklabel'] = get_string('leaderboardrankn', 'format_nexcoursepro', $fr);
                $display[] = $row;
            }

            $slice = array_slice($display, $page * $perpage, $perpage);
            $entries = [];
            foreach ($slice as $row) {
                $entries[] = [
                    'rank' => (int) $row['rank'],
                    'ranklabel' => (string) $row['ranklabel'],
                    'userid' => (int) $row['userid'],
                    'name' => (string) $row['name'],
                    'username' => (string) $row['username'],
                    'institution' => $row['institution'] !== '' ? $row['institution'] : '—',
                    'avatarurl' => (string) $row['avatarurl'],
                    'grade' => (string) $row['grade'],
                    'percent' => (string) $row['percent'],
                    'percentnum' => (int) $row['percentnum'],
                    'level' => (int) ($row['level'] ?? 0),
                    'isme' => !empty($row['isme']),
                ];
            }

            $merow = null;
            foreach ($rows as $row) {
                if (!empty($row['isme'])) {
                    $merow = $row;
                    break;
                }
            }
            if ($merow === null && isset($users[$userid])) {
                $merow = self::viewer_fallback_row(
                    $users[$userid],
                    $grades[$userid] ?? null,
                    $grademax,
                    $decimals
                );
                $merow['overallrank'] = 0;
                $merow['collegerank'] = 0;
                $merow['collegetotal'] = 0;
            }

            $progress = self::viewer_progress($course, $userid, $flat);
            $mecard = null;
            if ($merow !== null) {
                $mecard = self::build_me_card(
                    $merow,
                    $overalltotal,
                    $totaldisplay,
                    $progress,
                    $coursename,
                    $icons
                );
            }

            return array_merge([
                'available' => true,
                'unavailablemessage' => '',
                'me' => $mecard,
                'hasme' => $mecard !== null,
                'entries' => $entries,
                'hasentries' => !empty($entries),
                'playercount' => $overalltotal,
                'total' => $total,
                'page' => $page,
                'perpage' => $perpage,
                'grademax' => $totaldisplay,
                'institutions' => array_map(static function ($name) use ($institution) {
                    return [
                        'name' => $name,
                        'selected' => $name === $institution,
                    ];
                }, $institutions),
                'institution' => $institution,
                'coursename' => $coursename,
            ], $icons);
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro leaderboard: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * @param array $rows
     */
    private static function sort_rows(array &$rows): void {
        usort($rows, static function ($a, $b) {
            $af = $a['final'];
            $bf = $b['final'];
            if ($af === null && $bf === null) {
                return $a['userid'] <=> $b['userid'];
            }
            if ($af === null) {
                return 1;
            }
            if ($bf === null) {
                return -1;
            }
            if ($af === $bf) {
                return $a['userid'] <=> $b['userid'];
            }
            return $bf <=> $af;
        });
    }

    /**
     * @param array $me
     * @param int $overalltotal
     * @param string $totaldisplay
     * @param array $progress
     * @param string $coursename
     * @param array $icons
     * @return array
     */
    private static function build_me_card(
        array $me,
        int $overalltotal,
        string $totaldisplay,
        array $progress,
        string $coursename,
        array $icons
    ): array {
        $final = $me['final'] ?? null;
        $levelinfo = gamification::level_from_score($final === null ? null : (float) $final);
        $level = (int) $levelinfo['level'];
        $avatar = gamification::avatar_for_user((int) $me['userid'], $level);

        $overallrank = (int) ($me['overallrank'] ?? 0);
        $collegerank = (int) ($me['collegerank'] ?? 0);
        $collegetotal = (int) ($me['collegetotal'] ?? 0);
        $college = (string) ($me['institution'] ?? '');

        $overallvalue = $overallrank > 0
            ? get_string('leaderboardrankordinal', 'format_nexcoursepro', (object) [
                'rank' => $overallrank,
                'total' => $overalltotal,
            ])
            : get_string('leaderboardunranked', 'format_nexcoursepro');

        if ($college === '') {
            $collegevalue = get_string('leaderboardnocollege', 'format_nexcoursepro');
        } else if ($collegerank > 0) {
            $collegevalue = get_string('leaderboardrankordinal', 'format_nexcoursepro', (object) [
                'rank' => $collegerank,
                'total' => $collegetotal,
            ]);
        } else {
            $collegevalue = get_string('leaderboardunranked', 'format_nexcoursepro');
        }

        return [
            'userid' => (int) $me['userid'],
            'name' => (string) $me['name'],
            'username' => (string) ($me['username'] ?? ''),
            'usernamehandle' => ($me['username'] ?? '') !== '' && ($me['username'] ?? '') !== '—'
                ? '@' . $me['username']
                : '',
            'institution' => $college,
            'avatar' => $avatar,
            'avatarurl' => gamification::avatar_url($avatar),
            'avatarchoices' => gamification::avatar_choices((int) $me['userid'], $level, $avatar),
            'coursename' => $coursename,
            'score' => (string) $me['grade'],
            'grade' => (string) $me['grade'],
            'grademax' => $totaldisplay,
            'gradedisplay' => $me['grade'] . ' / ' . $totaldisplay,
            'percent' => (string) $me['percent'],
            'overallrank' => $overallrank,
            'overallvalue' => $overallvalue,
            'collegerank' => $collegerank,
            'collegevalue' => $collegevalue,
            'level' => $level,
            'levelicon' => gamification::level_icon_url($level),
            'levelpercent' => (float) $levelinfo['percent'],
            'nextlevel' => (string) ($levelinfo['nextlevel'] ?? ''),
            'showlevel' => !empty($levelinfo['enabled']),
            'progresspct' => (int) ($progress['pct'] ?? 0),
            'activitydisplay' => (string) ($progress['display'] ?? ''),
            'scoreicon' => $icons['scoreicon'],
            'rankicon' => $icons['rankicon'],
            'collegeicon' => $icons['collegeicon'],
            'labelscore' => get_string('leaderboardscore', 'format_nexcoursepro'),
            'labeloverall' => get_string('leaderboardoverallranking', 'format_nexcoursepro'),
            'labelcollege' => get_string('leaderboardcollegeranking', 'format_nexcoursepro'),
            'labellevel' => get_string('leaderboardlevel', 'format_nexcoursepro'),
            'changeavatar' => get_string('leaderboardchangeavatar', 'format_nexcoursepro'),
        ];
    }

    /**
     * @param \context_course $context
     * @return array<int,bool>
     */
    private static function student_user_ids($context): array {
        global $DB;
        try {
            $roles = get_archetype_roles('student');
            if (empty($roles)) {
                return [];
            }
            $roleids = array_map(static function ($r) {
                return (int) $r->id;
            }, $roles);
            list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
            $params['ctxid'] = $context->id;
            $sql = "SELECT DISTINCT ra.userid
                      FROM {role_assignments} ra
                     WHERE ra.contextid = :ctxid AND ra.roleid $insql";
            $ids = $DB->get_records_sql($sql, $params);
            $out = [];
            foreach ($ids as $row) {
                $out[(int) $row->userid] = true;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param \stdClass $user
     * @param \grade_grade|null $grade
     * @param float $grademax
     * @param int $decimals
     * @return array
     */
    private static function viewer_fallback_row($user, $grade, float $grademax, int $decimals): array {
        $final = null;
        if ($grade && $grade->finalgrade !== null && $grade->finalgrade !== '') {
            $final = (float) $grade->finalgrade;
        }
        $levelinfo = gamification::level_from_score($final);
        $avatar = gamification::avatar_for_user((int) $user->id, (int) $levelinfo['level']);
        return [
            'userid' => (int) $user->id,
            'name' => self::firstname_lastname($user),
            'username' => (string) ($user->username ?? ''),
            'institution' => trim((string) ($user->institution ?? '')),
            'final' => $final,
            'grade' => $final === null ? '—' : format_float($final, $decimals, true),
            'percent' => $final === null ? '—' : ((int) round(($final / $grademax) * 100) . '%'),
            'percentnum' => $final === null ? -1 : (int) round(($final / $grademax) * 100),
            'avatar' => $avatar,
            'avatarurl' => gamification::avatar_url($avatar),
            'level' => (int) $levelinfo['level'],
            'isme' => true,
        ];
    }

    /**
     * @param \stdClass $user
     * @return string
     */
    private static function firstname_lastname($user): string {
        return trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
    }

    /**
     * @param \stdClass $course
     * @param int $userid
     * @param array|null $flat
     * @return array{pct:int,display:string}
     */
    private static function viewer_progress($course, int $userid, ?array $flat): array {
        if (is_array($flat)) {
            $progress = catalog::progress_from_flat($flat);
            return ['pct' => $progress['pct'], 'display' => $progress['display']];
        }
        try {
            global $PAGE;
            $format = course_get_format($course);
            $completion = new completion_info($course);
            $built = catalog::flat_activities(
                $format,
                $format->get_modinfo(),
                $completion,
                $userid,
                $PAGE,
                true
            );
            $progress = catalog::progress_from_flat($built);
            return ['pct' => $progress['pct'], 'display' => $progress['display']];
        } catch (\Throwable $e) {
            return [
                'pct' => 0,
                'display' => get_string('activitiesprogress', 'format_nexcoursepro', (object) [
                    'completed' => 0,
                    'total' => 0,
                ]),
            ];
        }
    }
}
