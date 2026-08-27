<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Whether the current account may open NexReports.
 *
 * @param int|null $userid Null checks the current user
 * @return bool
 */
function local_nexreports_can_access(?int $userid = null): bool {
    return \local_nexreports\local\access::can_view_reports($userid);
}

/**
 * Block anyone who may not open NexReports from the reports UI or its AJAX.
 *
 * @throws \required_capability_exception
 */
function local_nexreports_require_access(): void {
    \local_nexreports\local\access::require_reports();
}

/**
 * Add NexReports to the top custom menu when enabled.
 *
 * @param global_navigation $nav
 */
function local_nexreports_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexreports', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    // Menu link follows the same access gate as the pages themselves.
    if (!local_nexreports_can_access()) {
        return;
    }

    $url = '/local/nexreports/index.php';

    $label = get_string('pluginname', 'local_nexreports');

    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        $haystack = (string) ($CFG->custommenuitems ?? '');
        if (stripos($haystack, $url) === false) {
            $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
            array_unshift($nodes, $label . '|' . $url);
            $CFG->custommenuitems = implode("\n", array_filter($nodes, static function ($line) {
                return trim((string) $line) !== '';
            }));
        }
        return;
    }

    $icon = new pix_icon('i/report', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexreports',
        'nexreports',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * Legacy alias.
 *
 * @param global_navigation $nav
 */
function local_nexreports_extends_navigation(global_navigation $nav): void {
    local_nexreports_extend_navigation($nav);
}

/**
 * Shared page chrome for NexReports.
 *
 * @param moodle_page $page
 */
function local_nexreports_setup_page(moodle_page $page): void {
    $page->set_pagelayout('standard');
    $page->add_body_class('path-local-nexreports');
    $page->add_body_class('nxr-fullwidth');
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexreports/fonts.css');
}

/**
 * Course-family sub-nav keys (Edwiser Course dropdown parity).
 *
 * @return string[]
 */
function local_nexreports_course_report_keys(): array {
    return [
        'courses',
        'courseactivities',
        'courseactivitycompletion',
        'coursequizcumulative',
        'coursegrades',
        'coursecompletion',
    ];
}

/**
 * Students-family sub-nav keys (Edwiser Learners dropdown parity).
 *
 * @return string[]
 */
function local_nexreports_student_report_keys(): array {
    return [
        'students',
        'learnercourseprogress',
        'learnercourseactivities',
        'weeklyinsights',
    ];
}

/**
 * Portfolio-family sub-nav keys (Platforms / GitHub).
 *
 * @return string[]
 */
function local_nexreports_portfolio_report_keys(): array {
    return [
        'portfolio',
        'portfoliogithub',
    ];
}

/**
 * Sub-nav items under the Courses tab.
 *
 * @param string $active summary|activities|activitycompletion|quizcumulative|grades|completion
 * @return array
 */
function local_nexreports_courses_subnav(string $active = 'summary'): array {
    $items = [
        ['key' => 'summary', 'file' => 'courses.php', 'stringid' => 'allcoursessummary'],
        ['key' => 'activities', 'file' => 'course_activities.php', 'stringid' => 'courseactivitiessummary'],
        ['key' => 'activitycompletion', 'file' => 'course_activity_completion.php', 'stringid' => 'courseactivitycompletion'],
        ['key' => 'quizcumulative', 'file' => 'course_quiz_cumulative.php', 'stringid' => 'coursequizcumulative'],
        ['key' => 'grades', 'file' => 'course_grades.php', 'stringid' => 'coursegrades'],
        ['key' => 'completion', 'file' => 'course_completion.php', 'stringid' => 'coursecompletion'],
    ];
    $out = [];
    foreach ($items as $item) {
        $out[] = [
            'key' => $item['key'],
            'label' => get_string($item['stringid'], 'local_nexreports'),
            'url' => (new moodle_url('/local/nexreports/' . $item['file']))->out(false),
            'active' => $active === $item['key'],
        ];
    }
    return $out;
}

/**
 * Sub-nav items under the Students tab.
 *
 * @param string $active summary|courseprogress|courseactivities
 * @return array
 */
function local_nexreports_students_subnav(string $active = 'summary'): array {
    $items = [
        ['key' => 'summary', 'file' => 'students.php', 'stringid' => 'studentengagement'],
        ['key' => 'courseprogress', 'file' => 'learner_course_progress.php', 'stringid' => 'learnercourseprogress'],
        ['key' => 'courseactivities', 'file' => 'learner_course_activities.php', 'stringid' => 'learnercourseactivities'],
        ['key' => 'weeklyinsights', 'file' => 'weekly_insights.php', 'stringid' => 'weeklyinsights'],
    ];
    $out = [];
    foreach ($items as $item) {
        $out[] = [
            'key' => $item['key'],
            'label' => get_string($item['stringid'], 'local_nexreports'),
            'url' => (new moodle_url('/local/nexreports/' . $item['file']))->out(false),
            'active' => $active === $item['key'],
        ];
    }
    return $out;
}

/**
 * Sub-nav items under the Portfolio tab.
 *
 * @param string $active platforms|github
 * @return array
 */
function local_nexreports_portfolio_subnav(string $active = 'platforms'): array {
    $items = [
        ['key' => 'platforms', 'file' => 'portfolio.php', 'stringid' => 'portfolioplatforms'],
        ['key' => 'github', 'file' => 'portfolio_github.php', 'stringid' => 'portfoliogithub'],
    ];
    $out = [];
    foreach ($items as $item) {
        $out[] = [
            'key' => $item['key'],
            'label' => get_string($item['stringid'], 'local_nexreports'),
            'url' => (new moodle_url('/local/nexreports/' . $item['file']))->out(false),
            'active' => $active === $item['key'],
        ];
    }
    return $out;
}

/**
 * Tab navigation context for Mustache.
 *
 * @param string $active overview|courses|courseactivities|courseactivitycompletion|coursequizcumulative|coursegrades|coursecompletion|students|learnercourseprogress|learnercourseactivities|weeklyinsights|learner|custom|nexpractice|nexbattleground|nexcodelab|nexinterview|portfolio|portfoliogithub
 * @param bool $showperiod
 * @param int $perioddays
 * @return array
 */
function local_nexreports_shell_context(string $active = 'overview', bool $showperiod = false, int $perioddays = 7): array {
    $context = context_system::instance();
    $tabs = [];
    $allowed = local_nexreports_can_access();
    $coursesfamily = in_array($active, local_nexreports_course_report_keys(), true);
    $studentsfamily = in_array($active, local_nexreports_student_report_keys(), true);
    $portfoliofamily = in_array($active, local_nexreports_portfolio_report_keys(), true);

    $add = static function (string $key, string $file, string $stringid, string $cap) use (
        &$tabs,
        $active,
        $context,
        $allowed,
        $coursesfamily,
        $studentsfamily,
        $portfoliofamily
    ): void {
        $enabled = $allowed && \local_nexreports\local\access::has_capability($cap, $context);
        // Soft-enable NexPractice / Portfolio only when plugins exist.
        // Institution-scoped reports: these site-wide tabs stay disabled.
        if ($key === 'nexpractice') {
            $enabled = $enabled
                && !\local_nexreports\local\access::is_scoped()
                && (get_config('local_learnlogic', 'version') !== false);
        }
        if ($key === 'nexbattleground') {
            $enabled = $enabled
                && !\local_nexreports\local\access::is_scoped()
                && (get_config('local_nexbattleground', 'version') !== false);
        }
        if ($key === 'nexcodelab') {
            $enabled = $enabled
                && !\local_nexreports\local\access::is_scoped()
                && (get_config('local_nexcodelab', 'version') !== false);
        }
        if ($key === 'nexinterview') {
            $enabled = $enabled
                && !\local_nexreports\local\access::is_scoped()
                && (get_config('local_nexinterview', 'version') !== false);
        }
        if ($key === 'portfolio') {
            $enabled = $enabled
                && !\local_nexreports\local\access::is_scoped()
                && (get_config('local_nexportfolio', 'version') !== false);
        }
        $isactive = $key === 'courses' ? $coursesfamily
            : ($key === 'students' ? $studentsfamily
            : ($key === 'portfolio' ? $portfoliofamily : ($active === $key)));
        $tabs[] = [
            'key' => $key,
            'label' => get_string($stringid, 'local_nexreports'),
            'url' => (new moodle_url('/local/nexreports/' . $file))->out(false),
            'active' => $isactive,
            'enabled' => $enabled,
        ];
    };

    $add('overview', 'index.php', 'overview', 'local/nexreports:viewsite');
    $add('courses', 'courses.php', 'courses', 'local/nexreports:viewcourse');
    $add('students', 'students.php', 'students', 'local/nexreports:viewstudents');
    $add('nexpractice', 'nexpractice.php', 'nexpractice', 'local/nexreports:viewsite');
    $add('nexbattleground', 'nexbattleground.php', 'nexbattleground', 'local/nexreports:viewsite');
    $add('nexcodelab', 'nexcodelab.php', 'nexcodelab', 'local/nexreports:viewsite');
    $add('nexinterview', 'nexinterview.php', 'nexinterview', 'local/nexreports:viewsite');
    $add('portfolio', 'portfolio.php', 'portfolio', 'local/nexreports:viewsite');
    $add('learner', 'learner.php', 'learner', 'local/nexreports:viewlearner');
    $add('custom', 'custom.php', 'customreports', 'local/nexreports:managecustom');

    // If the user cannot see overview but can see learner, still show learner.
    $visible = array_filter($tabs, static fn($t) => $t['enabled']);
    if (!$visible) {
        $tabs[0]['enabled'] = \local_nexreports\local\access::has_capability('local/nexreports:viewsite', $context);
    }

    $submap = [
        'courses' => 'summary',
        'courseactivities' => 'activities',
        'courseactivitycompletion' => 'activitycompletion',
        'coursequizcumulative' => 'quizcumulative',
        'coursegrades' => 'grades',
        'coursecompletion' => 'completion',
    ];
    $studentsubmap = [
        'students' => 'summary',
        'learnercourseprogress' => 'courseprogress',
        'learnercourseactivities' => 'courseactivities',
        'weeklyinsights' => 'weeklyinsights',
    ];
    $portfoliosubmap = [
        'portfolio' => 'platforms',
        'portfoliogithub' => 'github',
    ];

    return [
        'pluginname' => get_string('pluginname', 'local_nexreports'),
        'showperiod' => $showperiod,
        'period7' => $perioddays === 7,
        'period30' => $perioddays === 30,
        'tabs' => $tabs,
        'showcoursesubnav' => $coursesfamily,
        'coursesubnav' => $coursesfamily
            ? local_nexreports_courses_subnav($submap[$active] ?? 'summary')
            : [],
        'showstudentssubnav' => $studentsfamily,
        'studentssubnav' => $studentsfamily
            ? local_nexreports_students_subnav($studentsubmap[$active] ?? 'summary')
            : [],
        'showportfoliosubnav' => $portfoliofamily,
        'portfoliosubnav' => $portfoliofamily
            ? local_nexreports_portfolio_subnav($portfoliosubmap[$active] ?? 'platforms')
            : [],
    ];
}

