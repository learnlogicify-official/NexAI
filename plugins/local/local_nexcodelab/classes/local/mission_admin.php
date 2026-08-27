<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Admin helpers for creating / updating / deleting missions.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Persist mission definitions from Manage UI.
 */
class mission_admin {

    /**
     * Cover keys used by catalog tiles.
     *
     * @return string[]
     */
    public static function cover_keys(): array {
        return ['lab', 'ship', 'sales', 'clinic', 'house', 'nlp', 'eda'];
    }

    /**
     * Default starter files for a new mission.
     *
     * @return array[]
     */
    public static function default_files(): array {
        return [
            [
                'path' => 'BRIEF.md',
                'role' => 'brief',
                'readonly' => 1,
                'content' => "# New mission\n\nDescribe the scenario. Learners implement helpers in `main.py` using `data.csv`.\n",
            ],
            [
                'path' => 'main.py',
                'role' => 'code',
                'readonly' => 0,
                'content' => "import pandas as pd\n\ndef solve(df: pd.DataFrame) -> pd.DataFrame:\n"
                    . "    \"\"\"Return a copy of the frame.\"\"\"\n    return df.copy()\n",
            ],
            [
                'path' => 'data.csv',
                'role' => 'data',
                'readonly' => 1,
                'content' => "id,value\n1,10\n2,20\n3,\n",
            ],
        ];
    }

    /**
     * Default first step.
     *
     * @return array
     */
    public static function default_step(): array {
        return [
            'title' => 'Step 1',
            'instructions' => '<p>Implement <code>solve</code> to return a copy of the input DataFrame.</p>',
            'hint' => 'You need an independent table that still looks identical to the input.',
            'checkkind' => 'frame',
            'xp' => 25,
            'graderpayload' => json_encode([
                'kind' => 'frame',
                'fn' => 'solve',
                'expect_csv' => "id,value\n1,10.0\n2,20.0\n3,\n",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Load mission + files + steps for the edit form.
     *
     * @param int $missionid
     * @return array{mission:\stdClass,files:array,steps:array}
     */
    public static function load(int $missionid): array {
        global $DB;
        $mission = $DB->get_record('local_nexcodelab_mission', ['id' => $missionid], '*', MUST_EXIST);
        $files = $DB->get_records('local_nexcodelab_mission_file', ['missionid' => $missionid], 'sortorder ASC, id ASC');
        $steps = $DB->get_records('local_nexcodelab_mission_step', ['missionid' => $missionid], 'sortorder ASC, id ASC');
        return [
            'mission' => $mission,
            'files' => array_values($files),
            'steps' => array_values($steps),
        ];
    }

    /**
     * Create or update a mission from validated form data.
     *
     * @param \stdClass $data
     * @param int $userid
     * @return int Mission id
     */
    public static function save(\stdClass $data, int $userid): int {
        global $DB;

        $now = time();
        $id = (int) ($data->id ?? 0);
        $slug = trim((string) $data->slug);

        // Unique slug (exclude self).
        $existing = $DB->get_record('local_nexcodelab_mission', ['slug' => $slug]);
        if ($existing && (int) $existing->id !== $id) {
            throw new \moodle_exception('missionslugexists', 'local_nexcodelab');
        }

        $record = (object) [
            'name' => trim((string) $data->name),
            'slug' => $slug,
            'scenario' => trim((string) $data->scenario),
            'track' => (string) $data->track,
            'status' => (string) $data->status,
            'estimateminutes' => max(1, (int) $data->estimateminutes),
            'coverkey' => (string) $data->coverkey,
            'timemodified' => $now,
            'usermodified' => $userid,
        ];

        if ($id > 0) {
            $record->id = $id;
            $DB->update_record('local_nexcodelab_mission', $record);
        } else {
            $record->timecreated = $now;
            $id = (int) $DB->insert_record('local_nexcodelab_mission', $record);
        }

        self::save_files($id, $data);
        self::save_steps($id, $data);

        return $id;
    }

    /**
     * @param int $missionid
     * @param \stdClass $data
     */
    private static function save_files(int $missionid, \stdClass $data): void {
        global $DB;

        $defs = [
            ['path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1, 'field' => 'file_brief', 'order' => 0],
            ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'field' => 'file_main', 'order' => 1],
            ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'field' => 'file_data', 'order' => 2],
        ];

        foreach ($defs as $def) {
            $content = (string) ($data->{$def['field']} ?? '');
            $existing = $DB->get_record('local_nexcodelab_mission_file', [
                'missionid' => $missionid,
                'path' => $def['path'],
            ]);
            if ($existing) {
                $existing->content = $content;
                $existing->role = $def['role'];
                $existing->readonly = $def['readonly'];
                $existing->sortorder = $def['order'];
                $DB->update_record('local_nexcodelab_mission_file', $existing);
            } else {
                $DB->insert_record('local_nexcodelab_mission_file', (object) [
                    'missionid' => $missionid,
                    'path' => $def['path'],
                    'role' => $def['role'],
                    'content' => $content,
                    'readonly' => $def['readonly'],
                    'sortorder' => $def['order'],
                ]);
            }
        }
    }

    /**
     * Upsert mission steps from repeated form fields.
     *
     * Preserves step ids (and learner attempts) when steps are edited in place.
     * Only removes attempts for deleted steps, then recomputes completion.
     *
     * @param int $missionid
     * @param \stdClass $data
     */
    private static function save_steps(int $missionid, \stdClass $data): void {
        global $DB;

        $titles = $data->steptitle ?? [];
        if (!is_array($titles)) {
            $titles = [];
        }

        $incoming = [];
        foreach (array_keys($titles) as $i) {
            $title = trim((string) ($data->steptitle[$i] ?? ''));
            if ($title === '') {
                continue;
            }
            $kind = (string) ($data->stepcheckkind[$i] ?? 'frame');
            if (!in_array($kind, ['frame', 'metric', 'stdout'], true)) {
                $kind = 'frame';
            }
            $graderraw = trim((string) ($data->stepgrader[$i] ?? ''));
            $grader = json_decode($graderraw, true);
            if (!is_array($grader)) {
                throw new \moodle_exception('missiongraderinvalid', 'local_nexcodelab', '', $title);
            }
            if (empty($grader['kind'])) {
                $grader['kind'] = $kind;
            }
            $incoming[] = (object) [
                'title' => $title,
                'instructions' => (string) ($data->stepinstructions[$i] ?? ''),
                'hint' => (string) ($data->stephint[$i] ?? ''),
                'checkkind' => $kind,
                'graderpayload' => json_encode($grader, JSON_UNESCAPED_SLASHES),
                'xp' => max(0, (int) ($data->stepxp[$i] ?? 25)),
                'unlockprev' => 1,
            ];
        }

        if (!$incoming) {
            throw new \moodle_exception('missionneedstep', 'local_nexcodelab');
        }

        $existing = array_values($DB->get_records(
            'local_nexcodelab_mission_step',
            ['missionid' => $missionid],
            'sortorder ASC'
        ));

        $order = 0;
        foreach ($incoming as $step) {
            $step->missionid = $missionid;
            $step->sortorder = $order;
            if (isset($existing[$order])) {
                $step->id = (int) $existing[$order]->id;
                $DB->update_record('local_nexcodelab_mission_step', $step);
            } else {
                $DB->insert_record('local_nexcodelab_mission_step', $step);
            }
            $order++;
        }

        // Drop trailing steps that were removed in the form (and their attempts only).
        for ($i = $order; $i < count($existing); $i++) {
            $sid = (int) $existing[$i]->id;
            $DB->delete_records('local_nexcodelab_step_attempt', ['stepid' => $sid]);
            $DB->delete_records('local_nexcodelab_mission_step', ['id' => $sid]);
        }

        self::recompute_progress_for_mission($missionid);
    }

    /**
     * Recompute currentstep/completed for every learner on a mission from pass attempts.
     *
     * @param int $missionid
     */
    private static function recompute_progress_for_mission(int $missionid): void {
        global $DB;

        $steps = array_values($DB->get_records(
            'local_nexcodelab_mission_step',
            ['missionid' => $missionid],
            'sortorder ASC'
        ));
        $stepcount = count($steps);
        $progressrows = $DB->get_records('local_nexcodelab_mission_progress', ['missionid' => $missionid]);
        if (!$progressrows) {
            return;
        }

        $now = time();
        foreach ($progressrows as $row) {
            $userid = (int) $row->userid;
            $passed = 0;
            $current = 0;
            foreach ($steps as $s) {
                $ok = $DB->record_exists('local_nexcodelab_step_attempt', [
                    'userid' => $userid,
                    'stepid' => (int) $s->id,
                    'status' => 'pass',
                ]);
                if ($ok) {
                    $passed++;
                    $current = (int) $s->sortorder + 1;
                }
            }
            $row->currentstep = $current;
            $row->completed = ($passed === $stepcount && $stepcount > 0) ? 1 : 0;
            $row->timemodified = $now;
            $DB->update_record('local_nexcodelab_mission_progress', $row);
        }
    }

    /**
     * Delete a mission and related rows.
     *
     * @param int $missionid
     */
    public static function delete(int $missionid): void {
        global $DB;

        $steps = $DB->get_records('local_nexcodelab_mission_step', ['missionid' => $missionid], '', 'id');
        foreach ($steps as $step) {
            $DB->delete_records('local_nexcodelab_step_attempt', ['stepid' => (int) $step->id]);
        }
        $DB->delete_records('local_nexcodelab_mission_step', ['missionid' => $missionid]);
        $DB->delete_records('local_nexcodelab_mission_file', ['missionid' => $missionid]);
        $DB->delete_records('local_nexcodelab_mission_progress', ['missionid' => $missionid]);
        $DB->delete_records('local_nexcodelab_workspace', ['missionid' => $missionid]);
        $DB->delete_records('local_nexcodelab_mission', ['id' => $missionid]);
    }
}
