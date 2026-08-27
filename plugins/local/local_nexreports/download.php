<?php
// This file is part of Moodle - http://moodle.org/
/**
 * CSV / Excel / PDF download endpoint for NexReports.
 *
 * Exports only columns shown in each report UI, with CAPITAL headers.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
local_nexreports_require_access();
\local_nexreports\local\access::require_capability('local/nexreports:export', $context);

$report = required_param('report', PARAM_ALPHANUMEXT);
$format = optional_param('format', 'csv', PARAM_ALPHA);

/**
 * @param array $rows
 * @param callable $mapper function(array $row): array
 * @return array
 */
$maprows = static function(array $rows, callable $mapper): array {
    $out = [];
    foreach ($rows as $row) {
        if (is_object($row)) {
            $row = (array) $row;
        }
        $out[] = $mapper($row);
    }
    return $out;
};

if ($report === 'courses_summary') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
    }
    $enrolment = optional_param('enrolment', 'all', PARAM_TEXT);
    $exclude = optional_param('exclude', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\courses_report::summary($enrolment, $exclude, $search, 2000);
    $columns = [
        'name' => 'COURSE NAME',
        'category' => 'CATEGORY',
        'enrolments' => 'ENROLLED',
        'completed' => 'COMPLETED',
        'notstarted' => 'NOT STARTED',
        'inprogress' => 'IN PROGRESS',
        'atleastoneactivitystarted' => 'AT LEAST ONE ACTIVITY STARTED',
        'totalactivities' => 'TOTAL ACTIVITIES',
        'avgprogress' => 'AVG. PROGRESS',
        'avggrade' => 'AVG. GRADE',
        'highestgrade' => 'HIGHEST GRADE',
        'lowestgrade' => 'LOWEST GRADE',
        'totaltimespent' => 'TOTAL TIME SPENT',
        'avgtimespent' => 'AVG. TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'name' => $row['name'] ?? '',
            'category' => $row['category'] ?? '',
            'enrolments' => (int) ($row['enrolments'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'notstarted' => (int) ($row['notstarted'] ?? 0),
            'inprogress' => (int) ($row['inprogress'] ?? 0),
            'atleastoneactivitystarted' => (int) ($row['atleastoneactivitystarted'] ?? 0),
            'totalactivities' => (int) ($row['totalactivities'] ?? 0),
            'avgprogress' => (isset($row['avgprogress']) ? $row['avgprogress'] : '0') . '%',
            'avggrade' => $row['avggrade'] ?? '',
            'highestgrade' => $row['highestgrade'] ?? '',
            'lowestgrade' => $row['lowestgrade'] ?? '',
            'totaltimespent' => \local_nexreports\local\export::duration_hms((int) ($row['totaltimespent'] ?? 0)),
            'avgtimespent' => \local_nexreports\local\export::duration_hms((int) ($row['avgtimespent'] ?? 0)),
        ];
    });
    \local_nexreports\local\export::download('nexreports_courses_summary', $columns, $rows, $format);
}

if ($report === 'course_activities_summary') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\courses_report::activities_summary(
        $courseid, 0, $search, 2000, $year, $department, $institution
    );
    $columns = [
        'rank' => '#',
        'name' => 'ACTIVITY',
        'type' => 'TYPE',
        'status' => 'STATUS',
        'learnerscompleted' => 'COMPLETED',
        'completionrate' => 'COMPLETION %',
        'totalgrade' => 'TOTAL GRADE',
        'averagegrade' => 'AVG GRADE',
        'totalvisits' => 'VISITS',
        'totaltimespent' => 'TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        $secs = (int) ($row['totaltimespent'] ?? ((int) ($row['totaltimespentminutes'] ?? 0) * MINSECS));
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'name' => $row['name'] ?? '',
            'type' => $row['type'] ?? '',
            'status' => $row['status'] ?? '',
            'learnerscompleted' => (int) ($row['learnerscompleted'] ?? 0),
            'completionrate' => (isset($row['completionrate']) ? $row['completionrate'] : '0') . '%',
            'totalgrade' => $row['totalgrade'] ?? '',
            'averagegrade' => $row['averagegrade'] ?? '',
            'totalvisits' => (int) ($row['totalvisits'] ?? 0),
            'totaltimespent' => \local_nexreports\local\export::duration_hms($secs),
        ];
    });
    \local_nexreports\local\export::download('nexreports_course_activities_summary', $columns, $rows, $format);
}

if ($report === 'course_activity_completion') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $cmid = optional_param('cmid', 0, PARAM_INT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\courses_report::activity_completion(
        $courseid, $cmid, 0, $search, 2000, $year, $department, $institution
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'email' => 'EMAIL',
        'institution' => 'INSTITUTION',
        'department' => 'DEPARTMENT',
        'yearofpassing' => 'YEAR OF PASSING',
        'completedlabel' => 'STATUS',
        'completedon' => 'COMPLETED ON',
        'grade' => 'GRADE',
        'totalmark' => 'TOTAL MARK',
        'gradepercent' => 'GRADE %',
        'passgrade' => 'PASS MARK',
        'gradedon' => 'GRADED ON',
        'firstaccess' => 'FIRST ACCESS',
        'lastaccess' => 'LAST ACCESS',
        'visits' => 'VISITS',
        'timespent' => 'TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        $secs = (int) ($row['timespent'] ?? ((int) ($row['timespentminutes'] ?? 0) * MINSECS));
        $pct = $row['gradepercent'] ?? $row['gradepercentvalue'] ?? '';
        if ($pct !== '' && $pct !== null && substr((string) $pct, -1) !== '%') {
            $pct = $pct . '%';
        }
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'email' => $row['email'] ?? '',
            'institution' => $row['institution'] ?? '',
            'department' => $row['department'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'completedlabel' => $row['completedlabel'] ?? '',
            'completedon' => $row['completedon'] ?? '',
            'grade' => $row['grade'] ?? '',
            'totalmark' => $row['totalmark'] ?? '',
            'gradepercent' => $pct,
            'passgrade' => $row['passgrade'] ?? '',
            'gradedon' => $row['gradedon'] ?? '',
            'firstaccess' => $row['firstaccess'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
            'visits' => (int) ($row['visits'] ?? 0),
            'timespent' => \local_nexreports\local\export::duration_hms($secs),
        ];
    });
    \local_nexreports\local\export::download('nexreports_course_activity_completion', $columns, $rows, $format);
}

if ($report === 'course_grades') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\course_grades_report::report(
        $courseid, $search, 2000, $year, $department, $institution
    );
    [$keys, $labels] = \local_nexreports\local\course_grades_report::export_columns($data);
    $columns = [];
    foreach ($keys as $i => $key) {
        $columns[$key] = \core_text::strtoupper($labels[$i] ?? $key);
    }
    $rows = \local_nexreports\local\course_grades_report::export_rows($data);
    \local_nexreports\local\export::download('nexreports_course_grades', $columns, $rows, $format);
}

if ($report === 'course_quiz_cumulative' || $report === 'course_completion') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewcourse', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewcourse', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    if ($report === 'course_quiz_cumulative') {
        $data = \local_nexreports\local\courses_report::quiz_cumulative(
            $courseid, 0, 0, $search, 2000, $year, $department, $institution
        );
        $basename = 'nexreports_course_quiz_cumulative';
    } else {
        $data = \local_nexreports\local\courses_report::course_completion(
            $courseid, 0, 0, $search, 2000, $year, $department, $institution
        );
        $basename = 'nexreports_course_completion';
    }
    $columns = [
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'email' => 'EMAIL',
        'institution' => 'INSTITUTION',
        'department' => 'DEPARTMENT',
        'yearofpassing' => 'YEAR OF PASSING',
        'enrolledon' => 'ENROLLED ON',
        'lastaccess' => 'LAST ACCESS',
        'progress' => 'PROGRESS',
        'completedlabel' => 'STATUS',
        'completedon' => 'COMPLETED ON',
        'completedactivities' => 'COMPLETED ACTIVITIES',
        'totalactivities' => 'TOTAL ACTIVITIES',
        'codingsolved' => 'CODING SOLVED',
        'codingtotal' => 'CODING TOTAL',
        'visits' => 'VISITS',
        'timespent' => 'TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        $secs = (int) ($row['timespent'] ?? ((int) ($row['timespentminutes'] ?? 0) * MINSECS));
        return [
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'email' => $row['email'] ?? '',
            'institution' => $row['institution'] ?? '',
            'department' => $row['department'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'enrolledon' => $row['enrolledon'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
            'progress' => (isset($row['progress']) ? $row['progress'] : '0') . '%',
            'completedlabel' => $row['completedlabel'] ?? '',
            'completedon' => $row['completedon'] ?? '',
            'completedactivities' => (int) ($row['completedactivities'] ?? 0),
            'totalactivities' => (int) ($row['totalactivities'] ?? 0),
            'codingsolved' => (int) ($row['codingsolved'] ?? 0),
            'codingtotal' => (int) ($row['codingtotal'] ?? 0),
            'visits' => (int) ($row['visits'] ?? 0),
            'timespent' => \local_nexreports\local\export::duration_hms($secs),
        ];
    });
    \local_nexreports\local\export::download($basename, $columns, $rows, $format);
}

if ($report === 'students_engagement') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewstudents', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewstudents', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $inactive = optional_param('inactive', 'all', PARAM_ALPHANUMEXT);
    $data = \local_nexreports\local\students_report::engagement(
        $courseid, 0, $search, 5000, $year, $department, $inactive, $institution
    );
    $columns = [
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'email' => 'EMAIL',
        'institution' => 'INSTITUTION',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'status' => 'STATUS',
        'lastaccess' => 'LAST ACCESS',
        'enrolledcourses' => 'ENROLLED COURSES',
        'inprogress' => 'IN-PROGRESS COURSES',
        'completed' => 'COMPLETED COURSES',
        'avgprogress' => 'COMPLETION PROGRESS',
        'totalgrade' => 'TOTAL GRADE',
        'codingsolved' => 'CODING SOLVED',
        'codingtotal' => 'CODING TOTAL',
        'timespentonsite' => 'TIME SPENT ON SITE',
        'timespentoncourse' => 'TIME SPENT ON COURSE',
        'activitiescompleted' => 'ACTIVITIES COMPLETED',
        'visits' => 'VISITS ON COURSE',
        'completedassignments' => 'COMPLETED ASSIGNMENTS',
        'completedquizzes' => 'COMPLETED QUIZZES',
        'completedscorms' => 'COMPLETED SCORMS',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'email' => $row['email'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'status' => $row['status'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
            'enrolledcourses' => (int) ($row['enrolledcourses'] ?? 0),
            'inprogress' => (int) ($row['inprogress'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'avgprogress' => (isset($row['avgprogress']) ? $row['avgprogress'] : '0') . '%',
            'totalgrade' => $row['totalgrade'] ?? '',
            'codingsolved' => (int) ($row['codingsolved'] ?? 0),
            'codingtotal' => (int) ($row['codingtotal'] ?? 0),
            'timespentonsite' => \local_nexreports\local\export::duration_hms((int) ($row['timespentonsite'] ?? 0)),
            'timespentoncourse' => \local_nexreports\local\export::duration_hms((int) ($row['timespentoncourse'] ?? 0)),
            'activitiescompleted' => (int) ($row['activitiescompleted'] ?? 0),
            'visits' => (int) ($row['visits'] ?? 0),
            'completedassignments' => (int) ($row['completedassignments'] ?? 0),
            'completedquizzes' => (int) ($row['completedquizzes'] ?? 0),
            'completedscorms' => (int) ($row['completedscorms'] ?? 0),
        ];
    });
    \local_nexreports\local\export::download('nexreports_students_engagement', $columns, $rows, $format);
}

if ($report === 'learner_course_progress') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewstudents', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewstudents', $context);
    }
    $userid = optional_param('userid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $data = \local_nexreports\local\students_report::course_progress(
        $userid, $search, $year, $department, '', false, $institution
    );
    $columns = [
        'coursename' => 'COURSE',
        'status' => 'STATUS',
        'enrolledon' => 'ENROLLED ON',
        'completedon' => 'COMPLETED ON',
        'lastaccess' => 'LAST ACCESS',
        'progress' => 'PROGRESS',
        'grade' => 'GRADE',
        'totalactivities' => 'TOTAL ACTIVITIES',
        'completedactivities' => 'COMPLETED ACTIVITIES',
        'attemptedactivities' => 'ATTEMPTED ACTIVITIES',
        'codingsolved' => 'CODING SOLVED',
        'codingtotal' => 'CODING TOTAL',
        'visits' => 'VISITS',
        'timespent' => 'TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'coursename' => $row['coursename'] ?? '',
            'status' => $row['status'] ?? '',
            'enrolledon' => $row['enrolledon'] ?? '',
            'completedon' => $row['completedon'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
            'progress' => (isset($row['progress']) ? $row['progress'] : '0') . '%',
            'grade' => $row['grade'] ?? '',
            'totalactivities' => (int) ($row['totalactivities'] ?? 0),
            'completedactivities' => (int) ($row['completedactivities'] ?? 0),
            'attemptedactivities' => (int) ($row['attemptedactivities'] ?? 0),
            'codingsolved' => (int) ($row['codingsolved'] ?? 0),
            'codingtotal' => (int) ($row['codingtotal'] ?? 0),
            'visits' => (int) ($row['visits'] ?? 0),
            'timespent' => \local_nexreports\local\export::duration_hms((int) ($row['timespent'] ?? 0)),
        ];
    });
    \local_nexreports\local\export::download('nexreports_learner_course_progress', $columns, $rows, $format);
}

if ($report === 'learner_course_activities') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewstudents', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewstudents', $context);
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $userid = optional_param('userid', 0, PARAM_INT);
    $section = optional_param('section', -1, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $activitytype = optional_param('activitytype', '', PARAM_ALPHANUMEXT);
    $completionstatus = optional_param('completionstatus', 'all', PARAM_ALPHANUMEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $data = \local_nexreports\local\students_report::course_activities(
        $courseid, $userid, $section, $search, $activitytype, $completionstatus,
        '', false, $year, $department, $institution
    );
    $columns = [
        'activity' => 'ACTIVITY',
        'type' => 'TYPE',
        'status' => 'STATUS',
        'completedon' => 'COMPLETED ON',
        'grade' => 'GRADE',
        'gradedon' => 'GRADED ON',
        'attempts' => 'ATTEMPTS',
        'highestgrade' => 'HIGHEST GRADE',
        'lowestgrade' => 'LOWEST GRADE',
        'firstaccess' => 'FIRST ACCESS',
        'lastaccess' => 'LAST ACCESS',
        'visits' => 'VISITS',
        'timespent' => 'TIME SPENT',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'activity' => $row['activity'] ?? '',
            'type' => $row['type'] ?? '',
            'status' => $row['status'] ?? '',
            'completedon' => $row['completedon'] ?? '',
            'grade' => $row['grade'] ?? '',
            'gradedon' => $row['gradedon'] ?? '',
            'attempts' => (int) ($row['attempts'] ?? 0),
            'highestgrade' => $row['highestgrade'] ?? '',
            'lowestgrade' => $row['lowestgrade'] ?? '',
            'firstaccess' => $row['firstaccess'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
            'visits' => (int) ($row['visits'] ?? 0),
            'timespent' => \local_nexreports\local\export::duration_hms((int) ($row['timespent'] ?? 0)),
        ];
    });
    \local_nexreports\local\export::download('nexreports_learner_course_activities', $columns, $rows, $format);
}

if ($report === 'portfolio_learners') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('portfolio', 'local_nexreports'));
    }
    if (get_config('local_nexportfolio', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('portfolio', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $platform = optional_param('platform', '', PARAM_ALPHANUMEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $data = \local_nexreports\local\portfolio_report::connected_learners(
        $cohortid,
        $platform,
        $search,
        2000,
        $institution,
        $year,
        $department
    );
    $cols = $data['platformcolumns'] ?? [];
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
    ];
    foreach ($cols as $col) {
        $p = strtoupper((string) ($col['short'] ?? $col['name'] ?? $col['id']));
        $id = $col['id'];
        $columns[$id . '_handle'] = $p . ' HANDLE';
        $columns[$id . '_solved'] = $p . ' SOLVED';
        $columns[$id . '_rating'] = $p . ' RATING';
        $columns[$id . '_bestrating'] = $p . ' BEST';
        $columns[$id . '_contests'] = $p . ' CONTESTS';
    }
    $flat = [];
    foreach ($data['rows'] as $row) {
        $line = [
            'rank' => $row['rank'],
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
        ];
        $bykey = [];
        foreach ($row['platformstats'] ?? [] as $m) {
            $bykey[$m['platform']] = $m;
        }
        foreach ($cols as $col) {
            $p = $col['id'];
            $m = $bykey[$p] ?? ['connected' => false];
            $line[$p . '_handle'] = !empty($m['connected']) ? ($m['handle'] ?? '') : '';
            $line[$p . '_solved'] = !empty($m['connected']) ? (int) ($m['solved'] ?? 0) : '';
            $line[$p . '_rating'] = !empty($m['connected']) ? (int) ($m['rating'] ?? 0) : '';
            $line[$p . '_bestrating'] = !empty($m['connected']) ? (int) ($m['bestrating'] ?? 0) : '';
            $line[$p . '_contests'] = !empty($m['connected']) ? (int) ($m['contests'] ?? 0) : '';
        }
        $flat[] = $line;
    }
    \local_nexreports\local\export::download('nexreports_portfolio_learners', $columns, $flat, $format);
}

if ($report === 'portfolio_github') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('portfoliogithub', 'local_nexreports'));
    }
    if (get_config('local_nexportfolio', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('portfoliogithub', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $data = \local_nexreports\local\portfolio_github_report::leaderboard(
        $cohortid,
        $search,
        2000,
        $institution,
        $year,
        $department
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'login' => 'GITHUB LOGIN',
        'contributionsyear' => 'CONTRIBUTIONS (YEAR)',
        'commitsyear' => 'COMMITS (YEAR)',
        'prsyear' => 'PRS (YEAR)',
        'issuesyear' => 'ISSUES (YEAR)',
        'reviewsyear' => 'REVIEWS (YEAR)',
        'publicrepos' => 'PUBLIC REPOS',
        'followers' => 'FOLLOWERS',
        'following' => 'FOLLOWING',
        'starsreceived' => 'STARS',
        'forksreceived' => 'FORKS',
        'projectcount' => 'IMPORTED PROJECTS',
        'lastfetch' => 'LAST UPDATED',
        'profileurl' => 'GITHUB URL',
    ];
    $flat = [];
    foreach ($data['rows'] as $row) {
        $flat[] = [
            'rank' => $row['rank'],
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'login' => $row['login'] ?? '',
            'contributionsyear' => (int) ($row['contributionsyear'] ?? 0),
            'commitsyear' => (int) ($row['commitsyear'] ?? 0),
            'prsyear' => (int) ($row['prsyear'] ?? 0),
            'issuesyear' => (int) ($row['issuesyear'] ?? 0),
            'reviewsyear' => (int) ($row['reviewsyear'] ?? 0),
            'publicrepos' => (int) ($row['publicrepos'] ?? 0),
            'followers' => (int) ($row['followers'] ?? 0),
            'following' => (int) ($row['following'] ?? 0),
            'starsreceived' => (int) ($row['starsreceived'] ?? 0),
            'forksreceived' => (int) ($row['forksreceived'] ?? 0),
            'projectcount' => (int) ($row['projectcount'] ?? 0),
            'lastfetch' => $row['lastfetch'] ?? '',
            'profileurl' => $row['profileurl'] ?? '',
        ];
    }
    \local_nexreports\local\export::download('nexreports_portfolio_github', $columns, $flat, $format);
}

if ($report === 'practice_leaderboard') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexpractice', 'local_nexreports'));
    }
    if (get_config('local_learnlogic', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexpractice', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $data = \local_nexreports\local\practice_report::leaderboard(
        $cohortid,
        $search,
        2000,
        $institution,
        $year,
        $department
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'practicexp' => 'PRACTICE XP',
        'xp' => 'TOTAL XP',
        'bonusxp' => 'BONUS XP',
        'solved' => 'SOLVED',
        'streak' => 'STREAK',
        'longest' => 'LONGEST STREAK',
        'attempts' => 'ATTEMPTS',
        'lastactivity' => 'LAST ACTIVITY',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'practicexp' => (int) ($row['practicexp'] ?? 0),
            'xp' => (int) ($row['xp'] ?? 0),
            'bonusxp' => (int) ($row['bonusxp'] ?? 0),
            'solved' => (int) ($row['solved'] ?? 0),
            'streak' => (int) ($row['streak'] ?? 0),
            'longest' => (int) ($row['longest'] ?? 0),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'lastactivity' => $row['lastactivity'] ?? '',
        ];
    });
    \local_nexreports\local\export::download('nexreports_practice_leaderboard', $columns, $rows, $format);
}

if ($report === 'battle_leaderboard') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexbattleground', 'local_nexreports'));
    }
    if (get_config('local_nexbattleground', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexbattleground', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $data = \local_nexreports\local\battle_report::leaderboard(
        $cohortid,
        $search,
        2000,
        $institution,
        $year,
        $department
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'battlexp' => 'BATTLE XP',
        'wins' => 'WINS',
        'losses' => 'LOSSES',
        'ties' => 'TIES',
        'battles' => 'BATTLES',
        'winrate' => 'WIN RATE',
        'attempts' => 'ATTEMPTS',
        'lastactivity' => 'LAST ACTIVITY',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'battlexp' => (int) ($row['battlexp'] ?? 0),
            'wins' => (int) ($row['wins'] ?? 0),
            'losses' => (int) ($row['losses'] ?? 0),
            'ties' => (int) ($row['ties'] ?? 0),
            'battles' => (int) ($row['battles'] ?? 0),
            'winrate' => (int) ($row['winrate'] ?? 0) . '%',
            'attempts' => (int) ($row['attempts'] ?? 0),
            'lastactivity' => $row['lastactivity'] ?? '',
        ];
    });
    \local_nexreports\local\export::download('nexreports_battle_leaderboard', $columns, $rows, $format);
}

if ($report === 'interview_attempts') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexinterview', 'local_nexreports'));
    }
    if (get_config('local_nexinterview', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexinterview', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $status = optional_param('status', 'all', PARAM_ALPHANUMEXT);
    $track = optional_param('track', '', PARAM_ALPHANUMEXT);
    $data = \local_nexreports\local\interview_report::attempts(
        $cohortid,
        $search,
        2000,
        $institution,
        $year,
        $department,
        $status,
        $track
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'track' => 'TRACK',
        'status' => 'STATUS',
        'scoredisplay' => 'OVERALL',
        'conceptualdisplay' => 'CONCEPTUAL',
        'problemsolvingdisplay' => 'PROBLEM-SOLVING',
        'codingdisplay' => 'CODING',
        'explanationdisplay' => 'EXPLANATION',
        'communicationdisplay' => 'COMMUNICATION',
        'independencedisplay' => 'INDEPENDENCE',
        'started' => 'STARTED',
        'completed' => 'COMPLETED',
        'feedbackurl' => 'FEEDBACK URL',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'track' => $row['track'] ?? '',
            'status' => $row['status'] ?? '',
            'scoredisplay' => $row['scoredisplay'] ?? '',
            'conceptualdisplay' => $row['conceptualdisplay'] ?? '',
            'problemsolvingdisplay' => $row['problemsolvingdisplay'] ?? '',
            'codingdisplay' => $row['codingdisplay'] ?? '',
            'explanationdisplay' => $row['explanationdisplay'] ?? '',
            'communicationdisplay' => $row['communicationdisplay'] ?? '',
            'independencedisplay' => $row['independencedisplay'] ?? '',
            'started' => $row['started'] ?? '',
            'completed' => $row['completed'] ?? '',
            'feedbackurl' => $row['feedbackurl'] ?? '',
        ];
    });
    \local_nexreports\local\export::download('nexreports_interview_attempts', $columns, $rows, $format);
}

if ($report === 'codelab_leaderboard') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    if (\local_nexreports\local\access::is_scoped()) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexcodelab', 'local_nexreports'));
    }
    if (get_config('local_nexcodelab', 'version') === false) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('nexcodelab', 'local_nexreports'));
    }
    $cohortid = optional_param('cohortid', 0, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $data = \local_nexreports\local\codelab_report::leaderboard(
        $cohortid,
        $search,
        2000,
        $institution,
        $year,
        $department
    );
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'xp' => 'XP',
        'missionscompleted' => 'MISSIONS COMPLETED',
        'missionsstarted' => 'MISSIONS STARTED',
        'solved' => 'CHALLENGES SOLVED',
        'streak' => 'STREAK',
        'longest' => 'LONGEST STREAK',
        'attempts' => 'ATTEMPTS',
        'lastactivity' => 'LAST ACTIVITY',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'xp' => (int) ($row['xp'] ?? 0),
            'missionscompleted' => (int) ($row['missionscompleted'] ?? 0),
            'missionsstarted' => (int) ($row['missionsstarted'] ?? 0),
            'solved' => (int) ($row['solved'] ?? 0),
            'streak' => (int) ($row['streak'] ?? 0),
            'longest' => (int) ($row['longest'] ?? 0),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'lastactivity' => $row['lastactivity'] ?? '',
        ];
    });
    \local_nexreports\local\export::download('nexreports_codelab_leaderboard', $columns, $rows, $format);
}

if ($report === 'inactive_users') {
    \local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);
    $months = optional_param('months', 1, PARAM_INT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\overview_extra::inactive_users($months, $search, 1000);
    $columns = [
        'rank' => '#',
        'fullname' => 'FULL NAME',
        'email' => 'EMAIL',
        'lastaccess' => 'LAST ACCESS',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'fullname' => $row['fullname'] ?? '',
            'email' => $row['email'] ?? '',
            'lastaccess' => $row['lastaccess'] ?? '',
        ];
    });
    \local_nexreports\local\export::download('nexreports_inactive_users', $columns, $rows, $format);
}

if ($report === 'weekly_insights') {
    if (!\local_nexreports\local\access::has_capability('local/nexreports:viewstudents', $context)
            && !\local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context)) {
        \local_nexreports\local\access::require_capability('local/nexreports:viewstudents', $context);
    }
    $institution = optional_param('institution', '', PARAM_TEXT);
    $year = optional_param('year', '', PARAM_TEXT);
    $department = optional_param('department', '', PARAM_TEXT);
    $search = optional_param('search', '', PARAM_TEXT);
    $data = \local_nexreports\local\weekly_insights::report($institution, $year, $department, $search, 2000);
    $columns = [
        'rank' => '#',
        'firstname' => 'FIRST NAME',
        'lastname' => 'LAST NAME',
        'username' => 'USERNAME',
        'institution' => 'COLLEGE NAME',
        'yearofpassing' => 'YEAR OF PASSING',
        'department' => 'DEPARTMENT',
        'status' => 'OVERALL',
        'timespent' => 'TIME SPENT',
        'deltatimespent' => 'Δ TIME SPENT',
        'visits' => 'VISITS',
        'deltavisits' => 'Δ VISITS',
        'activedays' => 'ACTIVE DAYS',
        'deltaactivedays' => 'Δ ACTIVE DAYS',
        'activitiescompleted' => 'ACTIVITIES COMPLETED',
        'deltaactivities' => 'Δ ACTIVITIES',
        'codingsolved' => 'CODING SOLVED',
        'deltacoding' => 'Δ CODING',
        'quizattempts' => 'QUIZ ATTEMPTS',
        'deltaquiz' => 'Δ QUIZ',
    ];
    $rows = $maprows($data['rows'], static function(array $row): array {
        return [
            'rank' => (int) ($row['rank'] ?? 0),
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'username' => $row['username'] ?? '',
            'institution' => $row['institution'] ?? '',
            'yearofpassing' => $row['yearofpassing'] ?? '',
            'department' => $row['department'] ?? '',
            'status' => strtoupper((string) ($row['status'] ?? '')),
            'timespent' => \local_nexreports\local\export::duration_hms((int) ($row['timespent'] ?? 0)),
            'deltatimespent' => (int) ($row['deltatimespent'] ?? 0),
            'visits' => (int) ($row['visits'] ?? 0),
            'deltavisits' => (int) ($row['deltavisits'] ?? 0),
            'activedays' => (int) ($row['activedays'] ?? 0),
            'deltaactivedays' => (int) ($row['deltaactivedays'] ?? 0),
            'activitiescompleted' => (int) ($row['activitiescompleted'] ?? 0),
            'deltaactivities' => (int) ($row['deltaactivities'] ?? 0),
            'codingsolved' => (int) ($row['codingsolved'] ?? 0),
            'deltacoding' => (int) ($row['deltacoding'] ?? 0),
            'quizattempts' => (int) ($row['quizattempts'] ?? 0),
            'deltaquiz' => (int) ($row['deltaquiz'] ?? 0),
        ];
    });
    \local_nexreports\local\export::download('nexreports_weekly_insights', $columns, $rows, $format);
}

throw new moodle_exception('invalidparameter', 'error');
