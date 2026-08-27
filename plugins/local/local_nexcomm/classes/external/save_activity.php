<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

class save_activity extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Id 0=new', VALUE_DEFAULT, 0),
            'skill' => new external_value(PARAM_ALPHANUMEXT, 'Skill'),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'Difficulty'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body', VALUE_DEFAULT, ''),
            'prompt' => new external_value(PARAM_RAW, 'Prompt', VALUE_DEFAULT, ''),
            'audiourl' => new external_value(PARAM_RAW, 'Audio URL', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'draft'),
            'passmark' => new external_value(PARAM_INT, 'Pass mark', VALUE_DEFAULT, 70),
            'minwords' => new external_value(PARAM_INT, 'Min words', VALUE_DEFAULT, 0),
            'tags' => new external_value(PARAM_TEXT, 'Tags', VALUE_DEFAULT, ''),
            'questionsjson' => new external_value(PARAM_RAW, 'Questions JSON', VALUE_DEFAULT, '[]'),
        ]);
    }

    public static function execute(
        int $id,
        string $skill,
        string $difficulty,
        string $title,
        string $body = '',
        string $prompt = '',
        string $audiourl = '',
        string $status = 'draft',
        int $passmark = 70,
        int $minwords = 0,
        string $tags = '',
        string $questionsjson = '[]'
    ): array {
        global $DB, $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:manage', $context);
        $p = self::validate_parameters(self::execute_parameters(), compact(
            'id', 'skill', 'difficulty', 'title', 'body', 'prompt', 'audiourl',
            'status', 'passmark', 'minwords', 'tags', 'questionsjson'
        ));

        $skill = strtolower((string) $p['skill']);
        $difficulty = strtolower((string) $p['difficulty']);
        $status = strtolower((string) $p['status']);
        if (!in_array($skill, ['reading', 'listening', 'speaking', 'writing'], true)) {
            throw new \invalid_parameter_exception('Invalid skill');
        }
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $difficulty = 'easy';
        }
        if (!in_array($status, ['draft', 'ready'], true)) {
            $status = 'draft';
        }

        $now = time();
        $record = (object) [
            'skill' => $skill,
            'difficulty' => $difficulty,
            'title' => trim((string) $p['title']),
            'body' => (string) $p['body'],
            'prompt' => (string) $p['prompt'],
            'audiourl' => (string) $p['audiourl'],
            'status' => $status,
            'passmark' => max(1, min(100, (int) $p['passmark'])),
            'minwords' => max(0, (int) $p['minwords']),
            'timelimit' => 0,
            'tags' => trim((string) $p['tags']),
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ];

        $id = (int) $p['id'];
        if ($id > 0) {
            $record->id = $id;
            $DB->update_record('local_nexcomm_activity', $record);
        } else {
            $record->timecreated = $now;
            $id = (int) $DB->insert_record('local_nexcomm_activity', $record);
        }

        $DB->delete_records('local_nexcomm_question', ['activityid' => $id]);
        $questions = json_decode((string) $p['questionsjson'], true) ?: [];
        $order = 0;
        foreach ($questions as $q) {
            $stem = trim((string) ($q['stem'] ?? ''));
            if ($stem === '') {
                continue;
            }
            $choices = $q['choices'] ?? [];
            $correct = (string) ($q['correctkey'] ?? 'A');
            if (is_string($choices)) {
                // Lines, * marks correct.
                $lines = preg_split("/\r\n|\n|\r/", $choices) ?: [];
                $map = [];
                $idx = 0;
                $correct = 'A';
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $key = chr(65 + $idx);
                    if (str_starts_with($line, '*')) {
                        $correct = $key;
                        $line = ltrim(substr($line, 1));
                    }
                    $map[$key] = $line;
                    $idx++;
                }
                $choices = $map;
            }
            $DB->insert_record('local_nexcomm_question', (object) [
                'activityid' => $id,
                'qtype' => 'mcq',
                'stem' => $stem,
                'choices' => json_encode($choices),
                'correctkey' => $correct,
                'sortorder' => $order++,
            ]);
        }

        return ['id' => $id, 'message' => get_string('activitysaved', 'local_nexcomm')];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'message' => new external_value(PARAM_TEXT, 'msg'),
        ]);
    }
}
