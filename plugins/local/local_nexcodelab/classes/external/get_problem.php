<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: get one problem.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcodelab\local\catalog;
use moodle_exception;

/**
 * Problem detail for the IDE.
 */
class get_problem extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'problemid' => new external_value(PARAM_INT, 'Problem id'),
        ]);
    }

    public static function execute(int $problemid): array {
        global $USER, $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:view', $context);

        $params = self::validate_parameters(self::execute_parameters(), ['problemid' => $problemid]);
        $canmanage = has_capability('local/nexcodelab:manageproblems', $context);
        $p = $DB->get_record('local_nexcodelab_problem', ['id' => $params['problemid']]);
        if (!$p || ($p->status !== 'ready' && !$canmanage)) {
            throw new moodle_exception('notfound', 'local_nexcodelab');
        }

        $data = catalog::get_problem((int) $p->id, (int) $USER->id, true);
        if (!$data) {
            throw new moodle_exception('notfound', 'local_nexcodelab');
        }

        // Flatten drafts for WS.
        $drafts = [];
        foreach ((array) $data['drafts'] as $lang => $code) {
            $drafts[] = ['language' => (string) $lang, 'code' => (string) $code];
        }
        $data['drafts'] = $drafts;
        $data['canManage'] = $canmanage;
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'number' => new external_value(PARAM_INT, 'number', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'slug' => new external_value(PARAM_TEXT, 'slug'),
            'difficulty' => new external_value(PARAM_ALPHANUMEXT, 'difficulty'),
            'track' => new external_value(PARAM_ALPHANUMEXT, 'track', VALUE_DEFAULT, 'wrangling'),
            'fixturepath' => new external_value(PARAM_TEXT, 'fixture path', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHA, 'status'),
            'userstatus' => new external_value(PARAM_ALPHANUMEXT, 'userstatus'),
            'tags' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'name' => new external_value(PARAM_TEXT, 'name'),
                'count' => new external_value(PARAM_INT, 'count', VALUE_DEFAULT, 0),
            ])),
            'url' => new external_value(PARAM_URL, 'url'),
            'solvers' => new external_value(PARAM_INT, 'solvers', VALUE_DEFAULT, 0),
            'acceptance' => new external_value(PARAM_INT, 'acceptance', VALUE_DEFAULT, 0),
            'estimateminutes' => new external_value(PARAM_INT, 'estimateminutes', VALUE_DEFAULT, 15),
            'statement' => new external_value(PARAM_RAW, 'statement'),
            'defaultlanguage' => new external_value(PARAM_TEXT, 'defaultlanguage'),
            'languages' => new external_multiple_structure(new external_single_structure([
                'language' => new external_value(PARAM_TEXT, 'language'),
                'preload' => new external_value(PARAM_RAW, 'preload'),
                'prototype' => new external_value(PARAM_INT, 'prototype'),
            ])),
            'samples' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'stdin' => new external_value(PARAM_RAW, 'stdin'),
                'expected' => new external_value(PARAM_RAW, 'expected'),
                'display' => new external_value(PARAM_ALPHA, 'display'),
                'explanation' => new external_value(PARAM_RAW, 'explanation'),
            ])),
            'hiddenCount' => new external_value(PARAM_INT, 'hiddenCount'),
            'drafts' => new external_multiple_structure(new external_single_structure([
                'language' => new external_value(PARAM_TEXT, 'language'),
                'code' => new external_value(PARAM_RAW, 'code'),
            ])),
            'canManage' => new external_value(PARAM_BOOL, 'canManage'),
            'sourcequestionid' => new external_value(PARAM_INT, 'Linked CodeRunner question id', VALUE_DEFAULT, 0),
        ]);
    }
}
