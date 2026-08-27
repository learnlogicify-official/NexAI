<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\attempt_service;

class submit_attempt extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'activityid' => new external_value(PARAM_INT, 'Activity id'),
            'answersjson' => new external_value(PARAM_RAW, 'MCQ answers JSON', VALUE_DEFAULT, '{}'),
            'text' => new external_value(PARAM_RAW, 'Writing / speaking transcript', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Speech draft item id', VALUE_DEFAULT, 0),
            'duration' => new external_value(PARAM_INT, 'Speaking duration seconds', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $activityid,
        string $answersjson = '{}',
        string $text = '',
        int $draftitemid = 0,
        int $duration = 0
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:attempt', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'activityid', 'answersjson', 'text', 'draftitemid', 'duration'
        ));
        $answers = json_decode((string) $params['answersjson'], true) ?: [];
        $result = attempt_service::submit((int) $USER->id, (int) $params['activityid'], [
            'answers' => $answers,
            'text' => (string) $params['text'],
            'transcript' => (string) $params['text'],
            'draftitemid' => (int) $params['draftitemid'],
            'duration' => (int) $params['duration'],
        ]);
        $result['targetsJson'] = json_encode($result['targets']);
        unset($result['targets']);
        if (!isset($result['transcript'])) {
            $result['transcript'] = '';
        }
        if (!isset($result['analysisJson'])) {
            $result['analysisJson'] = '';
        }
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'attempt'),
            'status' => new external_value(PARAM_TEXT, 'status'),
            'score' => new external_value(PARAM_FLOAT, 'score'),
            'passmark' => new external_value(PARAM_INT, 'passmark'),
            'xpAwarded' => new external_value(PARAM_INT, 'xp'),
            'dailyBonus' => new external_value(PARAM_INT, 'daily bonus'),
            'weeklyBonus' => new external_value(PARAM_INT, 'weekly bonus'),
            'targetsJson' => new external_value(PARAM_RAW, 'targets'),
            'transcript' => new external_value(PARAM_RAW, 'transcript'),
            'analysisJson' => new external_value(PARAM_RAW, 'analysis'),
        ]);
    }
}
