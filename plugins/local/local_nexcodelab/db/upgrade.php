<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Upgrade steps for local_nexcodelab.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nexcodelab_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080700) {
        $table = new xmldb_table('local_nexcodelab_problem');

        $track = new xmldb_field('track', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'wrangling', 'difficulty');
        if (!$dbman->field_exists($table, $track)) {
            $dbman->add_field($table, $track);
        }
        $fixture = new xmldb_field('fixturepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'track');
        if (!$dbman->field_exists($table, $fixture)) {
            $dbman->add_field($table, $fixture);
        }
        $index = new xmldb_index('track_ix', XMLDB_INDEX_NOTUNIQUE, ['track', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026080700, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080702) {
        $tables = [
            'local_nexcodelab_mission' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
                $table->add_field('slug', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
                $table->add_field('scenario', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
                $table->add_field('track', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'wrangling');
                $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
                $table->add_field('estimateminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30');
                $table->add_field('coverkey', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'lab');
                $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('mission_slug_uix', XMLDB_INDEX_UNIQUE, ['slug']);
                $table->add_index('mission_track_ix', XMLDB_INDEX_NOTUNIQUE, ['track', 'status']);
            },
            'local_nexcodelab_mission_step' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('missionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
                $table->add_field('instructions', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
                $table->add_field('hint', XMLDB_TYPE_TEXT);
                $table->add_field('checkkind', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'stdout');
                $table->add_field('graderpayload', XMLDB_TYPE_TEXT);
                $table->add_field('xp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '25');
                $table->add_field('unlockprev', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('step_mission_ix', XMLDB_INDEX_NOTUNIQUE, ['missionid', 'sortorder']);
            },
            'local_nexcodelab_mission_file' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('missionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('path', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
                $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'code');
                $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
                $table->add_field('readonly', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('file_mission_path_uix', XMLDB_INDEX_UNIQUE, ['missionid', 'path']);
            },
            'local_nexcodelab_step_attempt' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('stepid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'fail');
                $table->add_field('code_snapshot', XMLDB_TYPE_TEXT);
                $table->add_field('output', XMLDB_TYPE_TEXT);
                $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('step_attempt_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'stepid', 'timecreated']);
            },
            'local_nexcodelab_mission_progress' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('missionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('currentstep', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('mission_progress_uix', XMLDB_INDEX_UNIQUE, ['userid', 'missionid']);
            },
            'local_nexcodelab_workspace' => function (xmldb_table $table) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('missionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
                $table->add_field('path', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
                $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
                $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('workspace_uix', XMLDB_INDEX_UNIQUE, ['userid', 'missionid', 'path']);
            },
        ];

        foreach ($tables as $name => $builder) {
            $table = new xmldb_table($name);
            if (!$dbman->table_exists($table)) {
                $builder($table);
                $dbman->create_table($table);
            }
        }

        require_once(__DIR__ . '/../classes/local/mission_seed.php');
        \local_nexcodelab\local\mission_seed::seed();

        upgrade_plugin_savepoint(true, 2026080702, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080705) {
        // Re-register problem AJAX web services (restored after mission-only services.php).
        upgrade_plugin_savepoint(true, 2026080705, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080706) {
        // Mission catalog with NexPractice-style index UI.
        upgrade_plugin_savepoint(true, 2026080706, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080707) {
        // Mission bench light theme + RemUI theme switcher.
        upgrade_plugin_savepoint(true, 2026080707, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080708) {
        // Manage UI: create / edit / delete missions.
        upgrade_plugin_savepoint(true, 2026080708, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080709) {
        // Richer Titanic triage brief + step instructions.
        require_once(__DIR__ . '/../classes/local/mission_seed.php');
        \local_nexcodelab\local\mission_seed::refresh_copy(['titanic-triage']);
        upgrade_plugin_savepoint(true, 2026080709, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080710) {
        // High-tech dark-first mission bench UI.
        upgrade_plugin_savepoint(true, 2026080710, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080711) {
        // Stacks-style studio layout; RemUI light/dark sync.
        upgrade_plugin_savepoint(true, 2026080711, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080712) {
        // Resizable mission panes; fill viewport (no empty right column).
        upgrade_plugin_savepoint(true, 2026080712, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080713) {
        // Fix empty middle column: explicit 3-column grid layout.
        upgrade_plugin_savepoint(true, 2026080713, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080714) {
        // Steps rail + editor file tabs for clearer access.
        upgrade_plugin_savepoint(true, 2026080714, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080715) {
        // Titanic brief: data dictionary for each column.
        require_once(__DIR__ . '/../classes/local/mission_seed.php');
        \local_nexcodelab\local\mission_seed::refresh_copy(['titanic-triage']);
        upgrade_plugin_savepoint(true, 2026080715, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080716) {
        // Titanic family_size: business rule, not formula transcription.
        require_once(__DIR__ . '/../classes/local/mission_seed.php');
        \local_nexcodelab\local\mission_seed::refresh_copy(['titanic-triage']);
        upgrade_plugin_savepoint(true, 2026080716, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080717) {
        // Preserve learner completion when editing mission steps in place.
        upgrade_plugin_savepoint(true, 2026080717, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080718) {
        // Scenario-led copy, soft hints, per-step signatures; XML import.
        require_once(__DIR__ . '/../classes/local/mission_seed.php');
        \local_nexcodelab\local\mission_seed::refresh_copy(null);
        upgrade_plugin_savepoint(true, 2026080718, 'local', 'nexcodelab');
    }

    if ($oldversion < 2026080719) {
        // Mission catalog pagination (12 per page).
        upgrade_plugin_savepoint(true, 2026080719, 'local', 'nexcodelab');
    }

    return true;
}

