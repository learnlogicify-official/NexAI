<?php
namespace local_nexcomm\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcomm\local\lesson;

class get_lesson extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'lessonid' => new external_value(PARAM_INT, 'Lesson id'),
        ]);
    }

    public static function execute(int $lessonid): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), ['lessonid' => $lessonid]);
        $data = lesson::get_lesson((int) $params['lessonid'], (int) $USER->id);
        $data['linesJson'] = json_encode($data['lines']);
        $data['wordsJson'] = json_encode($data['words']);
        unset($data['lines'], $data['words']);
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'title' => new external_value(PARAM_TEXT, 'title'),
            'difficulty' => new external_value(PARAM_TEXT, 'diff'),
            'summary' => new external_value(PARAM_RAW, 'summary'),
            'topic' => new external_value(PARAM_TEXT, 'topic'),
            'videourl' => new external_value(PARAM_RAW, 'video'),
            'lineCount' => new external_value(PARAM_INT, 'lines'),
            'wordCount' => new external_value(PARAM_INT, 'words'),
            'watched' => new external_value(PARAM_BOOL, 'watched'),
            'wordsLearned' => new external_value(PARAM_INT, 'wl'),
            'linesSpoken' => new external_value(PARAM_INT, 'ls'),
            'complete' => new external_value(PARAM_BOOL, 'done'),
            'learnScore' => new external_value(PARAM_FLOAT, 'learn'),
            'speakScore' => new external_value(PARAM_FLOAT, 'speak'),
            'url' => new external_value(PARAM_URL, 'url'),
            'linesJson' => new external_value(PARAM_RAW, 'lines'),
            'wordsJson' => new external_value(PARAM_RAW, 'words'),
            'speakJson' => new external_value(PARAM_RAW, 'speak'),
            'learnJson' => new external_value(PARAM_RAW, 'learn'),
            'videosUrl' => new external_value(PARAM_URL, 'videos'),
        ]);
    }
}
