<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Full course grades matrix — sections as column groups, activities as sub-columns.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Course grades report: one row per learner, grades under each section/activity.
 */
class course_grades_report {

    /**
     * @param int $courseid
     * @param string $search
     * @param int $limit
     * @param string $year
     * @param string $department
     * @param string $institution
     * @return array
     */
    public static function report(
        int $courseid = 0,
        string $search = '',
        int $limit = 500,
        string $year = '',
        string $department = '',
        string $institution = ''
    ): array {
        global $DB;

        $limit = max(1, min(2000, $limit));
        $courseid = courses_report::resolve_courseid($courseid);
        $courses = courses_report::course_options();
        $search = trim(\core_text::strtolower($search));
        $cascade = courses_report::college_year_department_options($courseid, $institution, $year, $department);
        $institution = $cascade['institution'];
        $year = $cascade['year'];
        $department = $cascade['department'];

        $empty = [
            'generated' => time(),
            'rows' => [],
            'sections' => [],
            'courses' => $courses,
            'colleges' => $cascade['colleges'],
            'years' => $cascade['years'],
            'departments' => $cascade['departments'],
            'selectedcourseid' => $courseid > 1 ? $courseid : 0,
            'selectedinstitution' => $institution,
            'selectedyear' => $year,
            'selecteddepartment' => $department,
            'showcollege' => $cascade['showcollege'],
            'showdepartment' => $cascade['showdepartment'],
            'search' => $search,
            'coursetotalmax' => '',
            'coursetotalmaxvalue' => -1.0,
            'activitycount' => 0,
        ];

        if ($courseid <= 1) {
            return $empty;
        }

        $sections = self::build_sections($courseid);
        $empty['sections'] = $sections;
        $activitycount = 0;
        $cmids = [];
        $itemids = [];
        foreach ($sections as $section) {
            foreach ($section['activities'] as $act) {
                $activitycount++;
                $cmids[] = (int) $act['cmid'];
                if ((int) $act['itemid'] > 0) {
                    $itemids[] = (int) $act['itemid'];
                }
            }
        }
        $empty['activitycount'] = $activitycount;
        $empty['selectedcourseid'] = $courseid;

        $coursetotal = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
        ], 'id, grademax', IGNORE_MISSING);
        $coursetotalitemid = $coursetotal ? (int) $coursetotal->id : 0;
        $coursetotalmax = $coursetotal ? round((float) $coursetotal->grademax, 2) : 0.0;
        $empty['coursetotalmax'] = $coursetotalmax > 0 ? self::format_grade($coursetotalmax) : '';
        $empty['coursetotalmaxvalue'] = $coursetotalmax > 0 ? $coursetotalmax : -1.0;

        $learnerids = filters::learner_ids($courseid, 0, 0);
        $profileids = profile_filters::userids($courseid, $year, $department, $institution);
        if ($profileids !== null) {
            $learnerids = array_values(array_intersect($learnerids, $profileids));
        }
        if (!$learnerids) {
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');

        $gradesbyuser = [];
        if ($itemids) {
            [$ginsql, $ginparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'gi');
            $gsql = "SELECT userid, itemid, finalgrade
                       FROM {grade_grades}
                      WHERE itemid $ginsql
                        AND userid $insql
                        AND finalgrade IS NOT NULL";
            $rs = $DB->get_recordset_sql($gsql, array_merge($ginparams, $inparams));
            foreach ($rs as $g) {
                $uid = (int) $g->userid;
                $gradesbyuser[$uid][(int) $g->itemid] = (float) $g->finalgrade;
            }
            $rs->close();
        }

        $totalsbyuser = [];
        if ($coursetotalitemid > 0) {
            $tsql = "SELECT userid, finalgrade
                       FROM {grade_grades}
                      WHERE itemid = :itemid
                        AND userid $insql
                        AND finalgrade IS NOT NULL";
            $rs = $DB->get_recordset_sql($tsql, array_merge(['itemid' => $coursetotalitemid], $inparams));
            foreach ($rs as $g) {
                $totalsbyuser[(int) $g->userid] = (float) $g->finalgrade;
            }
            $rs->close();
        }

        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, institution, department, idnumber, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );

        $unspecified = get_string('notset', 'local_nexreports');
        $rows = [];
        $rank = 1;
        foreach ($users as $user) {
            $fullname = fullname($user);
            $yearofpassing = overview::normalize_year_of_passing_public(
                (string) ($user->idnumber ?? ''),
                $unspecified
            );
            if ($search !== '' && !self::matches_search($user, $fullname, $yearofpassing, $search)) {
                continue;
            }

            $uid = (int) $user->id;
            $usergades = $gradesbyuser[$uid] ?? [];
            $cells = [];
            $sum = 0.0;
            $hasany = false;
            foreach ($sections as $section) {
                foreach ($section['activities'] as $act) {
                    $itemid = (int) $act['itemid'];
                    $cmid = (int) $act['cmid'];
                    if ($itemid > 0 && array_key_exists($itemid, $usergades)) {
                        $val = round($usergades[$itemid], 2);
                        $cells[] = [
                            'cmid' => $cmid,
                            'display' => self::format_grade($val),
                            'value' => $val,
                        ];
                        $sum += $val;
                        $hasany = true;
                    } else {
                        $cells[] = [
                            'cmid' => $cmid,
                            'display' => '—',
                            'value' => -1.0,
                        ];
                    }
                }
            }

            if (array_key_exists($uid, $totalsbyuser)) {
                $totalval = round($totalsbyuser[$uid], 2);
                $totaldisplay = self::format_grade($totalval);
            } else if ($hasany && $coursetotalitemid <= 0) {
                $totalval = round($sum, 2);
                $totaldisplay = self::format_grade($totalval);
            } else {
                $totalval = -1.0;
                $totaldisplay = '—';
            }

            $rows[] = [
                'rank' => $rank++,
                'userid' => $uid,
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'fullname' => $fullname,
                'username' => (string) ($user->username ?? ''),
                'email' => (string) ($user->email ?? ''),
                'institution' => trim((string) ($user->institution ?? '')) !== ''
                    ? trim((string) $user->institution) : '—',
                'department' => trim((string) ($user->department ?? '')) !== ''
                    ? trim((string) $user->department) : '—',
                'yearofpassing' => $yearofpassing !== '' ? $yearofpassing : '—',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                'gradecells' => $cells,
                'total' => $totaldisplay,
                'totalvalue' => $totalval,
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        $empty['rows'] = $rows;
        return $empty;
    }

    /**
     * Flat CSV column keys + labels from a report payload.
     *
     * @param array $data
     * @return array{0: string[], 1: string[]}
     */
    public static function export_columns(array $data): array {
        $keys = [
            'rank', 'firstname', 'lastname', 'username', 'email',
            'institution', 'yearofpassing', 'department',
        ];
        $labels = [
            '#', 'First name', 'Last name', 'Username', 'Email',
            'College', 'Year of passing', 'Department',
        ];
        foreach ($data['sections'] ?? [] as $section) {
            $secname = (string) ($section['name'] ?? 'Section');
            foreach ($section['activities'] ?? [] as $act) {
                $keys[] = 'cm_' . (int) $act['cmid'];
                $labels[] = $secname . ' › ' . (string) ($act['name'] ?? 'Activity');
            }
        }
        $keys[] = 'total';
        $labels[] = 'Total';
        return [$keys, $labels];
    }

    /**
     * Flatten report rows for CSV (grade cells expanded to cm_* keys).
     *
     * @param array $data
     * @return array<int, array<string, mixed>>
     */
    public static function export_rows(array $data): array {
        $out = [];
        foreach ($data['rows'] ?? [] as $row) {
            $flat = [
                'rank' => $row['rank'] ?? '',
                'firstname' => $row['firstname'] ?? '',
                'lastname' => $row['lastname'] ?? '',
                'username' => $row['username'] ?? '',
                'email' => $row['email'] ?? '',
                'institution' => $row['institution'] ?? '',
                'yearofpassing' => $row['yearofpassing'] ?? '',
                'department' => $row['department'] ?? '',
                'total' => $row['total'] ?? '',
            ];
            foreach ($row['gradecells'] ?? [] as $cell) {
                $flat['cm_' . (int) $cell['cmid']] = $cell['display'] ?? '—';
            }
            $out[] = $flat;
        }
        return $out;
    }

    /**
     * Gradeable activities grouped by course section (visible order).
     *
     * @param int $courseid
     * @return array<int, array{id:int,name:string,activities:array}>
     */
    private static function build_sections(int $courseid): array {
        global $DB;

        $modinfo = get_fast_modinfo($courseid);
        $items = $DB->get_records_sql(
            "SELECT gi.id, gi.itemmodule, gi.iteminstance, gi.grademax, gi.itemname, cm.id AS cmid
               FROM {grade_items} gi
               JOIN {modules} m ON m.name = gi.itemmodule
               JOIN {course_modules} cm
                 ON cm.module = m.id
                AND cm.instance = gi.iteminstance
                AND cm.course = gi.courseid
              WHERE gi.courseid = :cid
                AND gi.itemtype = 'mod'
                AND gi.itemnumber = 0",
            ['cid' => $courseid]
        );
        $bycm = [];
        foreach ($items as $item) {
            $bycm[(int) $item->cmid] = $item;
        }

        $bysection = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || !isset($bycm[(int) $cm->id])) {
                continue;
            }
            $bysection[(int) $cm->sectionnum][] = $cm;
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $cms = $bysection[(int) $section->section] ?? [];
            if (!$cms) {
                continue;
            }
            $sectionname = get_section_name($courseid, $section);
            if (trim($sectionname) === '') {
                $sectionname = get_string('section') . ' ' . (int) $section->section;
            }
            $activities = [];
            foreach ($cms as $cm) {
                $gi = $bycm[(int) $cm->id];
                $max = round((float) $gi->grademax, 2);
                $activities[] = [
                    'cmid' => (int) $cm->id,
                    'itemid' => (int) $gi->id,
                    'name' => format_string($cm->name),
                    'modname' => $cm->modname,
                    'modlabel' => courses_report::activity_type_label($cm->modname),
                    'maxgrade' => $max > 0 ? self::format_grade($max) : '',
                    'maxgradevalue' => $max > 0 ? $max : -1.0,
                ];
            }
            $sections[] = [
                'id' => (int) $section->id,
                'section' => (int) $section->section,
                'name' => format_string($sectionname),
                'activities' => $activities,
            ];
        }
        return $sections;
    }

    /**
     * @param \stdClass $user
     * @param string $fullname
     * @param string $yearofpassing
     * @param string $search lowercase
     * @return bool
     */
    private static function matches_search($user, string $fullname, string $yearofpassing, string $search): bool {
        $hay = [
            $fullname,
            (string) ($user->firstname ?? ''),
            (string) ($user->lastname ?? ''),
            (string) ($user->username ?? ''),
            (string) ($user->email ?? ''),
            (string) ($user->institution ?? ''),
            (string) ($user->department ?? ''),
            $yearofpassing,
        ];
        foreach ($hay as $part) {
            if ($part !== '' && \core_text::strpos(\core_text::strtolower($part), $search) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param float $value
     * @return string
     */
    private static function format_grade(float $value): string {
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }
        return format_float($value, 2, true, true);
    }
}
