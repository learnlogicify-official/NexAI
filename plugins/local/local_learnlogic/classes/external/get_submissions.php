<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: user submissions for a problem or all.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnlogic\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * Submission history.
 */
class get_submissions extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'problemid' => new external_value(PARAM_INT, 'Problem id (0 = all)', VALUE_DEFAULT, 0),
            'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 20),
        ]);
    }

    public static function execute(int $problemid = 0, int $page = 0, int $perpage = 20): array {
        global $DB, $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/learnlogic:view', $context);

        $params = self::validate_parameters(self::execute_parameters(), compact('problemid', 'page', 'perpage'));
        $page = max(0, (int) $params['page']);
        $perpage = max(1, min(50, (int) $params['perpage']));

        $where = 's.userid = :uid';
        $sqlparams = ['uid' => (int) $USER->id];
        if ((int) $params['problemid'] > 0) {
            $where .= ' AND s.problemid = :pid';
            $sqlparams['pid'] = (int) $params['problemid'];
        }

        $total = (int) $DB->count_records_sql(
            "SELECT COUNT(1) FROM {local_learnlogic_submission} s WHERE {$where}",
            $sqlparams
        );
        $rows = $DB->get_records_sql(
            "SELECT s.*, p.name AS problemname, p.difficulty
               FROM {local_learnlogic_submission} s
               JOIN {local_learnlogic_problem} p ON p.id = s.problemid
              WHERE {$where}
           ORDER BY s.timecreated DESC",
            $sqlparams,
            $page * $perpage,
            $perpage
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r->id,
                'problemid' => (int) $r->problemid,
                'problemname' => $r->problemname,
                'difficulty' => $r->difficulty,
                'language' => $r->language,
                'status' => $r->status,
                'passed' => (int) $r->passed,
                'total' => (int) $r->total,
                'timecreated' => (int) $r->timecreated,
                'timestr' => userdate((int) $r->timecreated),
            ];
        }
        return ['submissions' => $items, 'total' => $total, 'page' => $page, 'perpage' => $perpage];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submissions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'problemid' => new external_value(PARAM_INT, 'problemid'),
                'problemname' => new external_value(PARAM_TEXT, 'problemname'),
                'difficulty' => new external_value(PARAM_ALPHA, 'difficulty'),
                'language' => new external_value(PARAM_TEXT, 'language'),
                'status' => new external_value(PARAM_TEXT, 'status'),
                'passed' => new external_value(PARAM_INT, 'passed'),
                'total' => new external_value(PARAM_INT, 'total'),
                'timecreated' => new external_value(PARAM_INT, 'timecreated'),
                'timestr' => new external_value(PARAM_TEXT, 'timestr'),
            ])),
            'total' => new external_value(PARAM_INT, 'total'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'perpage'),
        ]);
    }
}
