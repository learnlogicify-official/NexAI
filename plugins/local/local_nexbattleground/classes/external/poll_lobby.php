<?php
namespace local_nexbattleground\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexbattleground\local\matchmaker;

class poll_lobby extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, 'Recent battles page (0-based)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Recent battles per page', VALUE_DEFAULT, 8),
        ]);
    }

    public static function execute(int $page = 0, int $perpage = 8): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexbattleground:view', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'page' => $page,
            'perpage' => $perpage,
        ]);
        return matchmaker::lobby_state(
            (int) $USER->id,
            (int) $params['page'],
            (int) $params['perpage']
        );
    }

    public static function execute_returns(): external_single_structure {
        $summary = new external_single_structure([
            'battleid' => new external_value(PARAM_INT, 'id'),
            'status' => new external_value(PARAM_TEXT, 'status'),
            'outcome' => new external_value(PARAM_TEXT, 'outcome'),
            'result' => new external_value(PARAM_TEXT, 'your result'),
            'opponent' => new external_value(PARAM_TEXT, 'opponent name'),
            'problemname' => new external_value(PARAM_TEXT, 'problem'),
            'difficulty' => new external_value(PARAM_TEXT, 'difficulty'),
            'timefinish' => new external_value(PARAM_INT, 'finished'),
            'timecreated' => new external_value(PARAM_INT, 'created'),
            'url' => new external_value(PARAM_URL, 'battle url'),
        ]);
        return new external_single_structure([
            'queued' => new external_value(PARAM_BOOL, 'In queue'),
            'battleid' => new external_value(PARAM_INT, 'Open battle'),
            'battlestatus' => new external_value(PARAM_TEXT, 'Open status'),
            'roomcode' => new external_value(PARAM_ALPHANUM, 'Active room code if hosting'),
            'roomDifficulty' => new external_value(PARAM_TEXT, 'Hosted room difficulty'),
            'incoming' => new external_multiple_structure(new external_single_structure([
                'battleid' => new external_value(PARAM_INT, 'id'),
                'from' => new external_value(PARAM_TEXT, 'challenger'),
                'username' => new external_value(PARAM_TEXT, 'username'),
                'difficulty' => new external_value(PARAM_TEXT, 'diff'),
                'timecreated' => new external_value(PARAM_INT, 'when'),
            ])),
            'recent' => new external_multiple_structure($summary),
            'recentTotal' => new external_value(PARAM_INT, 'Total finished battles'),
            'recentPage' => new external_value(PARAM_INT, 'Current recent page'),
            'recentPerpage' => new external_value(PARAM_INT, 'Recent page size'),
            'servertime' => new external_value(PARAM_INT, 'now'),
        ]);
    }
}
