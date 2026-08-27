<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexreports.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade local_nexreports.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexreports_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080509) {
        $table = new xmldb_table('nexreports_snapshot');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockname', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('perioddays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '7');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessiongap', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('blockuniq', XMLDB_KEY_UNIQUE, ['blockname', 'perioddays', 'userid', 'courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080509, 'local', 'nexreports');
    }

    if ($oldversion < 2026080511) {
        $table = new xmldb_table('nexreports_tracking');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timespent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastping', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('usertime', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timestart']);
        $table->add_index('coursetime', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timestart']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080511, 'local', 'nexreports');
    }

    if ($oldversion < 2026080512) {
        // Course progress cache.
        $table = new xmldb_table('nexreports_course_progress');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('completedmodules', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('totalmodules', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completablemods', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('progress', XMLDB_TYPE_FLOAT, '10', '3', XMLDB_NOTNULL, null, '0');
        $table->add_field('completiontime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseuser', XMLDB_KEY_UNIQUE, ['courseid', 'userid']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Custom reports.
        $table = new xmldb_table('nexreports_custom_reports');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('shortname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabledesktop', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Scheduled emails.
        $table = new xmldb_table('nexreports_schedemails');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reportkey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emaildata', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Daily summary.
        $table = new xmldb_table('nexreports_summary_detailed');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('datakey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('datavalue', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('daycoursekey', XMLDB_INDEX_NOTUNIQUE, ['datecreated', 'courseid', 'datakey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Graph download payloads.
        $table = new xmldb_table('nexreports_graph_data');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('downloadkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('blockname', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('format', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('downloadkey', XMLDB_INDEX_UNIQUE, ['downloadkey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080512, 'local', 'nexreports');
    }

    if ($oldversion < 2026080600) {
        // KPI definitions and the reporting window changed; cached payloads and cached
        // completion times would otherwise keep serving the previous numbers.
        $DB->delete_records('nexreports_snapshot');
        $DB->execute('UPDATE {nexreports_course_progress} SET timemodified = 0');

        upgrade_plugin_savepoint(true, 2026080600, 'local', 'nexreports');
    }

    if ($oldversion < 2026080601) {
        // No schema change — progress task fatal ($CFG in namespaced file) is fixed in code.
        upgrade_plugin_savepoint(true, 2026080601, 'local', 'nexreports');
    }

    if ($oldversion < 2026080602) {
        // Completion timestamps are recalculated under the new rule, and active users are now
        // learner scoped, so both the progress cache and the snapshots must be rebuilt.
        $DB->delete_records('nexreports_snapshot');
        $DB->execute('UPDATE {nexreports_course_progress} SET completiontime = NULL, timemodified = 0');

        upgrade_plugin_savepoint(true, 2026080602, 'local', 'nexreports');
    }

    if ($oldversion < 2026080603) {
        // Restart the resumable progress refresh from the first course.
        unset_config('progresscursor', 'local_nexreports');

        upgrade_plugin_savepoint(true, 2026080603, 'local', 'nexreports');
    }

    if ($oldversion < 2026080604) {
        // CLI unlock/rebuild helper only; no schema change.
        upgrade_plugin_savepoint(true, 2026080604, 'local', 'nexreports');
    }

    if ($oldversion < 2026080605) {
        // Restart the cursor so the bulk progress implementation rebuilds every course.
        unset_config('progresscursor', 'local_nexreports');

        upgrade_plugin_savepoint(true, 2026080605, 'local', 'nexreports');
    }

    if ($oldversion < 2026080606) {
        // Progress now ignores failed activities and hidden modules, matching core, so every
        // cached percentage and completion timestamp has to be recalculated.
        $DB->delete_records('nexreports_snapshot');
        $DB->execute('UPDATE {nexreports_course_progress} SET timemodified = 0');
        unset_config('progresscursor', 'local_nexreports');

        upgrade_plugin_savepoint(true, 2026080606, 'local', 'nexreports');
    }

    if ($oldversion < 2026080607) {
        // 2026080606 judged module visibility against the account running the refresh, so under
        // cron and CLI every course was skipped and the cache kept its pre-0.3.7 values.
        $DB->delete_records('nexreports_snapshot');
        $DB->execute('UPDATE {nexreports_course_progress} SET timemodified = 0');
        unset_config('progresscursor', 'local_nexreports');

        upgrade_plugin_savepoint(true, 2026080607, 'local', 'nexreports');
    }

    if ($oldversion < 2026080608) {
        // Access restrictions other than group and grouping can also exclude a learner from an
        // activity, and stealth activities never count, so denominators change again.
        $DB->delete_records('nexreports_snapshot');
        $DB->execute('UPDATE {nexreports_course_progress} SET timemodified = 0');
        unset_config('progresscursor', 'local_nexreports');

        upgrade_plugin_savepoint(true, 2026080608, 'local', 'nexreports');
    }

    if ($oldversion < 2026080609) {
        // Visits now count course and activity views only, so every stored figure is too high.
        $DB->delete_records('nexreports_snapshot');

        upgrade_plugin_savepoint(true, 2026080609, 'local', 'nexreports');
    }

    if ($oldversion < 2026080610) {
        // Adds course time-spent group filtering; no schema change.
        upgrade_plugin_savepoint(true, 2026080610, 'local', 'nexreports');
    }

    if ($oldversion < 2026080611) {
        // Group and user filters are now scoped to the chosen course; no schema change.
        upgrade_plugin_savepoint(true, 2026080611, 'local', 'nexreports');
    }

    if ($oldversion < 2026080612) {
        // Reports UI is temporarily limited to site administrators.
        upgrade_plugin_savepoint(true, 2026080612, 'local', 'nexreports');
    }

    if ($oldversion < 2026080613) {
        // Scrollable realtime / inactive user cards; no schema change.
        upgrade_plugin_savepoint(true, 2026080613, 'local', 'nexreports');
    }

    if ($oldversion < 2026080614) {
        // Daily activities SQL fix for ambiguous id and hourly GROUP BY; no schema change.
        upgrade_plugin_savepoint(true, 2026080614, 'local', 'nexreports');
    }

    if ($oldversion < 2026080615) {
        // Daily activities definitions aligned with Edwiser; no schema change.
        upgrade_plugin_savepoint(true, 2026080615, 'local', 'nexreports');
    }

    if ($oldversion < 2026080616) {
        // Course Progress overview block (average + bucket donut); no schema change.
        upgrade_plugin_savepoint(true, 2026080616, 'local', 'nexreports');
    }

    if ($oldversion < 2026080617) {
        // Course Progress learner set matches Edwiser (include inactive enrolments).
        upgrade_plugin_savepoint(true, 2026080617, 'local', 'nexreports');
    }

    if ($oldversion < 2026080618) {
        // Visits user filter + chart export menus; registers get_visits_site service.
        upgrade_plugin_savepoint(true, 2026080618, 'local', 'nexreports');
    }

    if ($oldversion < 2026080619) {
        // Pair realtime/inactive card heights with popular courses / daily activities.
        upgrade_plugin_savepoint(true, 2026080619, 'local', 'nexreports');
    }

    if ($oldversion < 2026080620) {
        // Compact scrollable heights for table and daily/inactive card pairs.
        upgrade_plugin_savepoint(true, 2026080620, 'local', 'nexreports');
    }

    if ($oldversion < 2026080621) {
        // Raise popular/realtime/daily/inactive cards to chart-block height.
        upgrade_plugin_savepoint(true, 2026080621, 'local', 'nexreports');
    }

    if ($oldversion < 2026080622) {
        // Sync popular/realtime/inactive heights to daily activities content height.
        upgrade_plugin_savepoint(true, 2026080622, 'local', 'nexreports');
    }

    if ($oldversion < 2026080623) {
        // Reduce equal heights for popular/realtime/daily/inactive panels.
        upgrade_plugin_savepoint(true, 2026080623, 'local', 'nexreports');
    }

    if ($oldversion < 2026080624) {
        // Increase popular/realtime/daily/inactive panel height by 25%.
        upgrade_plugin_savepoint(true, 2026080624, 'local', 'nexreports');
    }

    if ($oldversion < 2026080625) {
        // Increase popular/realtime/daily/inactive panel height by another 25%.
        upgrade_plugin_savepoint(true, 2026080625, 'local', 'nexreports');
    }

    if ($oldversion < 2026080626) {
        // Course Activity Status overview block; registers get_activity_status.
        upgrade_plugin_savepoint(true, 2026080626, 'local', 'nexreports');
    }

    if ($oldversion < 2026080627) {
        // My Time Spent On Site on Learner tab; registers get_my_timespent.
        upgrade_plugin_savepoint(true, 2026080627, 'local', 'nexreports');
    }

    if ($oldversion < 2026080628) {
        // Courses sub-nav: Activities Summary, Activity Completion, Course Completion.
        upgrade_plugin_savepoint(true, 2026080628, 'local', 'nexreports');
    }

    if ($oldversion < 2026080629) {
        // All Courses Summary Edwiser column/filter parity.
        upgrade_plugin_savepoint(true, 2026080629, 'local', 'nexreports');
    }

    if ($oldversion < 2026080630) {
        // ACS UI polish; time spent from Edwiser activity log / tracking / log-gap.
        upgrade_plugin_savepoint(true, 2026080630, 'local', 'nexreports');
    }

    if ($oldversion < 2026080631) {
        // Remove all direct reads of Edwiser DB tables; ACS time uses tracking + log-gap only.
        upgrade_plugin_savepoint(true, 2026080631, 'local', 'nexreports');
    }

    if ($oldversion < 2026080632) {
        // Fix All Courses Summary colliding/truncated columns.
        upgrade_plugin_savepoint(true, 2026080632, 'local', 'nexreports');
    }

    if ($oldversion < 2026080633) {
        // Course Completion time spent: tracking + log-gap (no Edwiser tables).
        upgrade_plugin_savepoint(true, 2026080633, 'local', 'nexreports');
    }

    if ($oldversion < 2026080634) {
        // Activities Summary/Completion: stable group filter, pagination, activity time spent.
        upgrade_plugin_savepoint(true, 2026080634, 'local', 'nexreports');
    }

    if ($oldversion < 2026080635) {
        // Pagination on All Courses Summary and Course Completion.
        upgrade_plugin_savepoint(true, 2026080635, 'local', 'nexreports');
    }

    if ($oldversion < 2026080826) {
        // Course Completion: Coding solved (CodeRunner raw fraction, in-progress quizzes).
        upgrade_plugin_savepoint(true, 2026080826, 'local', 'nexreports');
    }

    if ($oldversion < 2026080827) {
        // Coding solved: read marks from question_attempt_steps (qa has no fraction).
        upgrade_plugin_savepoint(true, 2026080827, 'local', 'nexreports');
    }

    if ($oldversion < 2026080828) {
        // Quiz Grades report + Pass/Fail status on activity completion + coding tried.
        upgrade_plugin_savepoint(true, 2026080828, 'local', 'nexreports');
    }

    if ($oldversion < 2026080829) {
        // All Quizzes cumulative report (passed/failed/in progress per quiz + per learner).
        upgrade_plugin_savepoint(true, 2026080829, 'local', 'nexreports');
    }

    if ($oldversion < 2026080830) {
        // All Quizzes: Course Completion layout; progress includes pass+fail+in-progress.
        upgrade_plugin_savepoint(true, 2026080830, 'local', 'nexreports');
    }

    if ($oldversion < 2026080831) {
        // All Quizzes CSV: UI headers uppercase, no rank/id, human time spent.
        upgrade_plugin_savepoint(true, 2026080831, 'local', 'nexreports');
    }

    if ($oldversion < 2026080900) {
        // Overview: remove Nex Academy eyebrow; total students + institution/department.
        upgrade_plugin_savepoint(true, 2026080900, 'local', 'nexreports');
    }

    if ($oldversion < 2026080901) {
        // Move Students by institution block to the bottom of Overview.
        upgrade_plugin_savepoint(true, 2026080901, 'local', 'nexreports');
    }

    if ($oldversion < 2026080902) {
        // Headcount: SSR under KPIs, always-visible institution/department table.
        upgrade_plugin_savepoint(true, 2026080902, 'local', 'nexreports');
    }

    if ($oldversion < 2026080903) {
        // Colleges list + department dropdown beside Course Progress.
        upgrade_plugin_savepoint(true, 2026080903, 'local', 'nexreports');
    }

    if ($oldversion < 2026080904) {
        // Headcount: categorise by idnumber as Year of Passing.
        upgrade_plugin_savepoint(true, 2026080904, 'local', 'nexreports');
    }

    if ($oldversion < 2026080905) {
        // Colleges beside Course Activity Status; departments beside Course Progress.
        upgrade_plugin_savepoint(true, 2026080905, 'local', 'nexreports');
    }

    if ($oldversion < 2026081010) {
        // Catch-up savepoint for 0.3.53–0.3.65 (filters, activity completion, remove Quiz Grades).
        // Prior releases bumped version.php without matching upgrade savepoints, which left
        // Notifications/upgrade spinning until the disk version was reached here.
        upgrade_plugin_savepoint(true, 2026081010, 'local', 'nexreports');
    }

    if ($oldversion < 2026081011) {
        // Rename All Quizzes tab/report to Course Completion ( Without Pass Grade Condition ).
        upgrade_plugin_savepoint(true, 2026081011, 'local', 'nexreports');
    }

    if ($oldversion < 2026081012) {
        // Course Completion (without pass): profile columns + sortable headers.
        upgrade_plugin_savepoint(true, 2026081012, 'local', 'nexreports');
    }

    if ($oldversion < 2026081013) {
        // Course Completion (without pass): Year/Department filters replace cohort/group.
        upgrade_plugin_savepoint(true, 2026081013, 'local', 'nexreports');
    }

    if ($oldversion < 2026081014) {
        // Course Completion: Year/Department filters, profile columns, sortable headers.
        upgrade_plugin_savepoint(true, 2026081014, 'local', 'nexreports');
    }

    if ($oldversion < 2026081015) {
        // All Learner Summary: Year/Department filters, KPIs, profile columns, sortable table.
        upgrade_plugin_savepoint(true, 2026081015, 'local', 'nexreports');
    }

    if ($oldversion < 2026081016) {
        // All Learner Summary: time spent uses tracking + log-gap (parity with Course Completion).
        upgrade_plugin_savepoint(true, 2026081016, 'local', 'nexreports');
    }

    if ($oldversion < 2026081017) {
        // Students: Learner Course Progress sub-report + subnav.
        upgrade_plugin_savepoint(true, 2026081017, 'local', 'nexreports');
    }

    if ($oldversion < 2026081018) {
        // Learner Course Progress: fix completed count; Year → Department → Learner filters.
        upgrade_plugin_savepoint(true, 2026081018, 'local', 'nexreports');
    }

    if ($oldversion < 2026081019) {
        // Learner picker: first+last name only, short list, searchable combo.
        upgrade_plugin_savepoint(true, 2026081019, 'local', 'nexreports');
    }

    if ($oldversion < 2026081020) {
        // Learner Course Progress: count fail completions; coding solved/total columns.
        upgrade_plugin_savepoint(true, 2026081020, 'local', 'nexreports');
    }

    if ($oldversion < 2026081021) {
        // Students: Learner Course Activities sub-report.
        upgrade_plugin_savepoint(true, 2026081021, 'local', 'nexreports');
    }

    if ($oldversion < 2026081022) {
        // Learner Course Activities: Year of passing → Department → Learner cascade.
        upgrade_plugin_savepoint(true, 2026081022, 'local', 'nexreports');
    }

    if ($oldversion < 2026081023) {
        // ACS: remove groups. Site-admin college filter on course reports.
        upgrade_plugin_savepoint(true, 2026081023, 'local', 'nexreports');
    }

    if ($oldversion < 2026081024) {
        // Students reports: site-admin college filter before year of passing.
        upgrade_plugin_savepoint(true, 2026081024, 'local', 'nexreports');
    }

    if ($oldversion < 2026081025) {
        // All Learner Summary: sitewide time spent + enrolled-only total grade %.
        upgrade_plugin_savepoint(true, 2026081025, 'local', 'nexreports');
    }

    if ($oldversion < 2026081026) {
        // All Learner Summary: total grade as sum of marks + coding solved/total columns.
        upgrade_plugin_savepoint(true, 2026081026, 'local', 'nexreports');
    }

    if ($oldversion < 2026081027) {
        // Coding total: use active enrolments (same as LCP), not learner_ids capability filter.
        upgrade_plugin_savepoint(true, 2026081027, 'local', 'nexreports');
    }

    if ($oldversion < 2026081028) {
        // All Learner Summary: keep College filter on All colleges when data is unfiltered.
        upgrade_plugin_savepoint(true, 2026081028, 'local', 'nexreports');
    }

    if ($oldversion < 2026081029) {
        // Exclude Assessment (timed) quizzes from all coding solved/total counts.
        upgrade_plugin_savepoint(true, 2026081029, 'local', 'nexreports');
    }

    if ($oldversion < 2026081030) {
        // Course Completion reports: separate coding solved and coding total columns.
        upgrade_plugin_savepoint(true, 2026081030, 'local', 'nexreports');
    }

    if ($oldversion < 2026081031) {
        // Split activities columns; CSV/Excel/PDF export menu with UI-matched CAPITAL headers.
        upgrade_plugin_savepoint(true, 2026081031, 'local', 'nexreports');
    }

    if ($oldversion < 2026081032) {
        // Institution-scoped reports: disable NexPractice and Portfolio tabs.
        upgrade_plugin_savepoint(true, 2026081032, 'local', 'nexreports');
    }

    if ($oldversion < 2026081033) {
        // Department admins (e.g. CSBS-ADMIN): college + department scoped reports, all years.
        upgrade_plugin_savepoint(true, 2026081033, 'local', 'nexreports');
    }

    if ($oldversion < 2026081034) {
        // Portfolio: college/year/department filters + identity columns.
        upgrade_plugin_savepoint(true, 2026081034, 'local', 'nexreports');
    }

    if ($oldversion < 2026081035) {
        $table = new xmldb_table('nexreports_weekly_learner');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weekstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timespent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('activedays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('activitiescompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('codingsolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('quizattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userweek', XMLDB_KEY_UNIQUE, ['userid', 'weekstart']);
        $table->add_index('weekstart', XMLDB_INDEX_NOTUNIQUE, ['weekstart']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Queue 8-week backfill after install/upgrade (runs via cron / adhoc).
        $task = new \local_nexreports\task\rebuild_weekly_insights();
        $task->set_custom_data(['weeks' => 8]);
        \core\task\manager::queue_adhoc_task($task, true);
        upgrade_plugin_savepoint(true, 2026081035, 'local', 'nexreports');
    }

    if ($oldversion < 2026081036) {
        // Placeholder: 0.3.92 server-side pagination (reverted in 0.3.93).
        upgrade_plugin_savepoint(true, 2026081036, 'local', 'nexreports');
    }

    if ($oldversion < 2026081037) {
        // Revert All Learner Summary server-side pagination to prior client-side behaviour.
        upgrade_plugin_savepoint(true, 2026081037, 'local', 'nexreports');
    }

    if ($oldversion < 2026082201) {
        // Tracker ping used Ajax loginrequired=false, so timespent never increased.
        // Clear empty start rows and cached zero timespent snapshots so overview
        // recomputes (log-gap until real pings arrive).
        if ($dbman->table_exists('nexreports_tracking')) {
            $DB->delete_records('nexreports_tracking', ['timespent' => 0]);
        }
        if ($dbman->table_exists('nexreports_snapshot')) {
            $DB->delete_records('nexreports_snapshot', ['blockname' => 'timespent_site']);
            $DB->delete_records('nexreports_snapshot', ['blockname' => 'timespent_course']);
        }
        // Prefer the new default flush interval when still on the old 5-minute default.
        if ((string) get_config('local_nexreports', 'trackfrequency') === '300') {
            set_config('trackfrequency', 60, 'local_nexreports');
        }
        upgrade_plugin_savepoint(true, 2026082201, 'local', 'nexreports');
    }

    return true;
}
