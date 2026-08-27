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

class get_lessons extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcomm:view', $context);
        $data = lesson::list_lessons((int) $USER->id);
        $data['goals'] = lesson::goals_summary((int) $USER->id);
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'total'),
            'goals' => new external_single_structure([
                'watchDone' => new external_value(PARAM_INT, 'w'),
                'watchGoal' => new external_value(PARAM_INT, 'wg'),
                'watchPct' => new external_value(PARAM_INT, 'wp'),
                'learnDone' => new external_value(PARAM_INT, 'l'),
                'learnGoal' => new external_value(PARAM_INT, 'lg'),
                'learnPct' => new external_value(PARAM_INT, 'lp'),
                'speakDone' => new external_value(PARAM_INT, 's'),
                'speakGoal' => new external_value(PARAM_INT, 'sg'),
                'speakPct' => new external_value(PARAM_INT, 'sp'),
            ]),
            'items' => new external_multiple_structure(new external_single_structure([
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
            ])),
        ]);
    }
}
