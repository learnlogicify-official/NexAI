<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import missions from NexCodeLab XML packs.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Parse and persist mission XML packs.
 */
class mission_xml {

    /**
     * Import missions from XML string.
     *
     * @param string $xml
     * @param int $userid
     * @param array $options skipifexists|updateexisting|publish
     * @return array{created:int,updated:int,skipped:int,errors:string[],slugs:string[]}
     */
    public static function import_xml(string $xml, int $userid, array $options = []): array {
        global $DB;

        $skip = !empty($options['skipifexists']);
        $update = !empty($options['updateexisting']);
        $forcepublish = !empty($options['publish']);

        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'slugs' => [],
        ];

        $xml = trim($xml);
        if ($xml === '') {
            $result['errors'][] = 'Empty XML';
            return $result;
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($doc === false) {
            foreach (libxml_get_errors() as $err) {
                $result['errors'][] = trim($err->message);
            }
            libxml_clear_errors();
            return $result;
        }

        $missions = [];
        if (isset($doc->mission)) {
            foreach ($doc->mission as $m) {
                $missions[] = $m;
            }
        } else if ($doc->getName() === 'mission') {
            $missions[] = $doc;
        }

        if (!$missions) {
            $result['errors'][] = 'No <mission> elements found';
            return $result;
        }

        foreach ($missions as $node) {
            try {
                $def = self::node_to_definition($node, $forcepublish);
                $slug = $def['slug'];
                $existing = $DB->get_record('local_nexcodelab_mission', ['slug' => $slug]);
                if ($existing) {
                    if ($skip) {
                        $result['skipped']++;
                        continue;
                    }
                    if ($update) {
                        self::upsert_definition($def, $userid, (int) $existing->id);
                        $result['updated']++;
                        $result['slugs'][] = $slug;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    self::upsert_definition($def, $userid, 0);
                    $result['created']++;
                    $result['slugs'][] = $slug;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param \SimpleXMLElement $node
     * @param bool $forcepublish
     * @return array
     */
    private static function node_to_definition(\SimpleXMLElement $node, bool $forcepublish): array {
        $name = trim((string) ($node->name ?? ''));
        $slug = trim((string) ($node->slug ?? ''));
        if ($name === '' || $slug === '') {
            throw new \moodle_exception('missionxmlinvalid', 'local_nexcodelab');
        }
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($slug));
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new \moodle_exception('missionxmlinvalid', 'local_nexcodelab');
        }

        $status = trim((string) ($node->status ?? 'published'));
        if ($forcepublish) {
            $status = 'published';
        }
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'published';
        }

        $track = trim((string) ($node->track ?? 'wrangling'));
        $cover = trim((string) ($node->coverkey ?? 'lab'));
        $covers = mission_admin::cover_keys();
        if (!in_array($cover, $covers, true)) {
            $cover = 'lab';
        }

        $brief = self::cdata($node, 'brief');
        $starter = self::cdata($node, 'starter');
        if ($starter === '') {
            $starter = "import pandas as pd\n";
        }
        $data = self::cdata($node, 'data');
        if ($data === '') {
            throw new \invalid_parameter_exception('Mission ' . $slug . ' is missing <data>');
        }

        $steps = [];
        if (isset($node->steps->step)) {
            foreach ($node->steps->step as $s) {
                $title = trim((string) ($s->title ?? ''));
                if ($title === '') {
                    continue;
                }
                $kind = trim((string) ($s->checkkind ?? 'frame'));
                if (!in_array($kind, ['frame', 'metric', 'stdout'], true)) {
                    $kind = 'frame';
                }
                $fn = trim((string) ($s->fn ?? 'solve'));
                $signature = self::cdata($s, 'signature');
                if ($signature === '') {
                    $signature = self::default_signature($fn, $kind);
                }
                $grader = [
                    'kind' => $kind,
                    'fn' => $fn,
                    'signature' => $signature,
                ];
                $preprocess = trim((string) ($s->preprocess ?? ''));
                if ($preprocess !== '') {
                    $grader['preprocess'] = $preprocess;
                }
                $expectcsv = self::cdata($s, 'expect_csv');
                if ($expectcsv !== '') {
                    $grader['expect_csv'] = $expectcsv;
                }
                $expect = trim((string) ($s->expect ?? ''));
                if ($expect !== '') {
                    $grader['expect'] = $expect;
                }
                if (isset($s->floor) && (string) $s->floor !== '') {
                    $grader['floor'] = (float) $s->floor;
                }

                $instructions = self::cdata($s, 'instructions');
                if ($instructions !== '' && stripos($instructions, '<pre') === false) {
                    $instructions .= '<p><strong>Function to implement:</strong></p>'
                        . '<pre class="ncl-bench__sig">' . htmlspecialchars($signature) . '</pre>';
                }

                $steps[] = [
                    'title' => $title,
                    'instructions' => $instructions,
                    'hint' => trim((string) ($s->hint ?? '')),
                    'checkkind' => $kind,
                    'xp' => max(0, (int) ($s->xp ?? 25)),
                    'grader' => $grader,
                ];
            }
        }
        if (!$steps) {
            throw new \invalid_parameter_exception('Mission ' . $slug . ' has no steps');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'scenario' => trim((string) ($node->scenario ?? '')),
            'track' => $track,
            'status' => $status,
            'estimateminutes' => max(5, (int) ($node->estimateminutes ?? 30)),
            'coverkey' => $cover,
            'files' => [
                ['path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1, 'content' => $brief],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => $starter],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $data],
            ],
            'steps' => $steps,
        ];
    }

    /**
     * @param \SimpleXMLElement $parent
     * @param string $name
     * @return string
     */
    private static function cdata(\SimpleXMLElement $parent, string $name): string {
        if (!isset($parent->{$name})) {
            return '';
        }
        return (string) $parent->{$name};
    }

    /**
     * @param string $fn
     * @param string $kind
     * @return string
     */
    private static function default_signature(string $fn, string $kind): string {
        if ($kind === 'metric') {
            return "def {$fn}(df: pd.DataFrame) -> float:\n"
                . "    \"\"\"Implement this step.\"\"\"\n"
                . "    return 0.0\n";
        }
        return "def {$fn}(df: pd.DataFrame) -> pd.DataFrame:\n"
            . "    \"\"\"Implement this step.\"\"\"\n"
            . "    return df\n";
    }

    /**
     * Insert or update a mission definition (preserves step ids when updating).
     *
     * @param array $def
     * @param int $userid
     * @param int $missionid 0 = create
     * @return int mission id
     */
    public static function upsert_definition(array $def, int $userid, int $missionid = 0): int {
        global $DB;

        $now = time();
        if ($missionid > 0) {
            $row = $DB->get_record('local_nexcodelab_mission', ['id' => $missionid], '*', MUST_EXIST);
            $row->name = $def['name'];
            $row->slug = $def['slug'];
            $row->scenario = $def['scenario'];
            $row->track = $def['track'];
            $row->status = $def['status'] ?? 'published';
            $row->estimateminutes = $def['estimateminutes'];
            $row->coverkey = $def['coverkey'];
            $row->timemodified = $now;
            $row->usermodified = $userid;
            $DB->update_record('local_nexcodelab_mission', $row);
        } else {
            $missionid = (int) $DB->insert_record('local_nexcodelab_mission', (object) [
                'name' => $def['name'],
                'slug' => $def['slug'],
                'scenario' => $def['scenario'],
                'track' => $def['track'],
                'status' => $def['status'] ?? 'published',
                'estimateminutes' => $def['estimateminutes'],
                'coverkey' => $def['coverkey'],
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }

        foreach ($def['files'] as $i => $file) {
            $existing = $DB->get_record('local_nexcodelab_mission_file', [
                'missionid' => $missionid,
                'path' => $file['path'],
            ]);
            $payload = (object) [
                'missionid' => $missionid,
                'path' => $file['path'],
                'role' => $file['role'],
                'content' => $file['content'],
                'readonly' => !empty($file['readonly']) ? 1 : 0,
                'sortorder' => $i,
            ];
            if ($existing) {
                // Keep learner workspaces; still refresh brief/data. Refresh starter only if no workspace.
                if ($file['path'] === 'main.py') {
                    $hasws = $DB->record_exists('local_nexcodelab_workspace', ['missionid' => $missionid]);
                    if ($hasws) {
                        continue;
                    }
                }
                $payload->id = $existing->id;
                $DB->update_record('local_nexcodelab_mission_file', $payload);
            } else {
                $DB->insert_record('local_nexcodelab_mission_file', $payload);
            }
        }

        $existingsteps = array_values($DB->get_records(
            'local_nexcodelab_mission_step',
            ['missionid' => $missionid],
            'sortorder ASC'
        ));
        $order = 0;
        foreach ($def['steps'] as $step) {
            $grader = $step['grader'];
            $rec = (object) [
                'missionid' => $missionid,
                'sortorder' => $order,
                'title' => $step['title'],
                'instructions' => $step['instructions'],
                'hint' => $step['hint'] ?? '',
                'checkkind' => $step['checkkind'],
                'graderpayload' => json_encode($grader, JSON_UNESCAPED_SLASHES),
                'xp' => $step['xp'],
                'unlockprev' => 1,
            ];
            if (isset($existingsteps[$order])) {
                $rec->id = (int) $existingsteps[$order]->id;
                $DB->update_record('local_nexcodelab_mission_step', $rec);
            } else {
                $DB->insert_record('local_nexcodelab_mission_step', $rec);
            }
            $order++;
        }
        for ($i = $order; $i < count($existingsteps); $i++) {
            $sid = (int) $existingsteps[$i]->id;
            $DB->delete_records('local_nexcodelab_step_attempt', ['stepid' => $sid]);
            $DB->delete_records('local_nexcodelab_mission_step', ['id' => $sid]);
        }

        return $missionid;
    }
}
