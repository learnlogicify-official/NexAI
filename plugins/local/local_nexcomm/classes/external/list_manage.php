<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

class list_manage extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:manage', $context);
        $rows = $DB->get_records('local_nexcomm_activity', null, 'timemodified DESC');
        $items = [];
        foreach ($rows as $r) {
            $questions = $DB->get_records('local_nexcomm_question', ['activityid' => $r->id], 'sortorder ASC');
            $rawparts = [];
            foreach ($questions as $q) {
                $lines = ['Q: ' . $q->stem];
                $choices = json_decode($q->choices, true) ?: [];
                foreach ($choices as $key => $label) {
                    $prefix = ((string) $key === (string) $q->correctkey) ? '*' : '';
                    $lines[] = $prefix . $label;
                }
                $rawparts[] = implode("\n", $lines);
            }
            $items[] = [
                'id' => (int) $r->id,
                'skill' => (string) $r->skill,
                'difficulty' => (string) $r->difficulty,
                'title' => (string) $r->title,
                'status' => (string) $r->status,
                'qcount' => count($questions),
                'body' => (string) ($r->body ?? ''),
                'prompt' => (string) ($r->prompt ?? ''),
                'audiourl' => (string) ($r->audiourl ?? ''),
                'passmark' => (int) $r->passmark,
                'minwords' => (int) $r->minwords,
                'tags' => (string) ($r->tags ?? ''),
                'questionsraw' => implode("\n---\n", $rawparts),
            ];
        }
        return ['items' => $items];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'skill' => new external_value(PARAM_TEXT, 'skill'),
                'difficulty' => new external_value(PARAM_TEXT, 'diff'),
                'title' => new external_value(PARAM_TEXT, 'title'),
                'status' => new external_value(PARAM_TEXT, 'status'),
                'qcount' => new external_value(PARAM_INT, 'questions'),
                'body' => new external_value(PARAM_RAW, 'body'),
                'prompt' => new external_value(PARAM_RAW, 'prompt'),
                'audiourl' => new external_value(PARAM_RAW, 'audio'),
                'passmark' => new external_value(PARAM_INT, 'pass'),
                'minwords' => new external_value(PARAM_INT, 'words'),
                'tags' => new external_value(PARAM_TEXT, 'tags'),
                'questionsraw' => new external_value(PARAM_RAW, 'questions raw'),
            ])),
        ]);
    }
}
