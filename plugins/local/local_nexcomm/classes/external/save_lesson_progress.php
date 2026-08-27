<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\lesson;

class save_lesson_progress extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'lessonid' => new external_value(PARAM_INT, 'Lesson id'),
            'mode' => new external_value(PARAM_ALPHANUMEXT, 'watch|learn|speak'),
            'answersjson' => new external_value(PARAM_RAW, 'Learn answers', VALUE_DEFAULT, '{}'),
            'lineid' => new external_value(PARAM_INT, 'Speak line id', VALUE_DEFAULT, 0),
            'transcript' => new external_value(PARAM_RAW, 'Spoken transcript', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $lessonid,
        string $mode,
        string $answersjson = '{}',
        int $lineid = 0,
        string $transcript = ''
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:attempt', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'lessonid', 'mode', 'answersjson', 'lineid', 'transcript'
        ));
        $answers = json_decode((string) $params['answersjson'], true) ?: [];
        $result = lesson::save_progress((int) $USER->id, (int) $params['lessonid'], [
            'mode' => (string) $params['mode'],
            'answers' => $answers,
            'lineid' => (int) $params['lineid'],
            'transcript' => (string) $params['transcript'],
        ]);
        $result['goalsJson'] = json_encode($result['goals']);
        unset($result['goals']);
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'watched' => new external_value(PARAM_BOOL, 'watched'),
            'wordsLearned' => new external_value(PARAM_INT, 'words'),
            'linesSpoken' => new external_value(PARAM_INT, 'lines'),
            'learnScore' => new external_value(PARAM_FLOAT, 'learn'),
            'speakScore' => new external_value(PARAM_FLOAT, 'speak'),
            'complete' => new external_value(PARAM_BOOL, 'complete'),
            'speakJson' => new external_value(PARAM_RAW, 'speak'),
            'xpAwarded' => new external_value(PARAM_INT, 'xp'),
            'goalsJson' => new external_value(PARAM_RAW, 'goals'),
            'lineScore' => new external_value(PARAM_FLOAT, 'line score'),
        ]);
    }
}
