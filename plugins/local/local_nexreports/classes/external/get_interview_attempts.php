<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: NexInterview attempts report.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_nexreports\local\access;
use local_nexreports\local\interview_report;

/**
 * NexInterview tab — attempt ledger with scores and feedback links.
 */
class get_interview_attempts extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cohortid' => new external_value(PARAM_INT, 'Cohort filter', VALUE_DEFAULT, 0),
            'search' => new external_value(PARAM_TEXT, 'Name/email search', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Max rows', VALUE_DEFAULT, 500),
            'institution' => new external_value(PARAM_TEXT, 'College filter', VALUE_DEFAULT, ''),
            'year' => new external_value(PARAM_TEXT, 'Year of passing', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status filter', VALUE_DEFAULT, 'all'),
            'track' => new external_value(PARAM_ALPHANUMEXT, 'Track filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $cohortid = 0,
        string $search = '',
        int $limit = 500,
        string $institution = '',
        string $year = '',
        string $department = '',
        string $status = 'all',
        string $track = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        access::require_reports();
        access::require_capability('local/nexreports:viewsite', $context);
        if (access::is_scoped()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('nexinterview', 'local_nexreports'));
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'cohortid' => $cohortid,
            'search' => $search,
            'limit' => $limit,
            'institution' => $institution,
            'year' => $year,
            'department' => $department,
            'status' => $status,
            'track' => $track,
        ]);

        return interview_report::attempts(
            (int) $params['cohortid'],
            (string) $params['search'],
            (int) $params['limit'],
            (string) $params['institution'],
            (string) $params['year'],
            (string) $params['department'],
            (string) $params['status'],
            (string) $params['track']
        );
    }

    public static function execute_returns(): external_single_structure {
        $option = new external_single_structure([
            'id' => new external_value(PARAM_RAW, 'Id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);
        $row = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'Rank'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'username' => new external_value(PARAM_TEXT, 'Username'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'institution' => new external_value(PARAM_TEXT, 'College'),
            'yearofpassing' => new external_value(PARAM_TEXT, 'Year of passing'),
            'department' => new external_value(PARAM_TEXT, 'Department'),
            'url' => new external_value(PARAM_URL, 'Profile URL'),
            'track' => new external_value(PARAM_TEXT, 'Track title'),
            'trackid' => new external_value(PARAM_ALPHANUMEXT, 'Track id'),
            'status' => new external_value(PARAM_TEXT, 'Status label'),
            'statusid' => new external_value(PARAM_ALPHANUMEXT, 'Status id'),
            'score' => new external_value(PARAM_INT, 'Score (0 when in progress)'),
            'scoredisplay' => new external_value(PARAM_TEXT, 'Score display'),
            'conceptual' => new external_value(PARAM_INT, 'Conceptual score', VALUE_DEFAULT, 0),
            'conceptualdisplay' => new external_value(PARAM_TEXT, 'Conceptual display'),
            'problemsolving' => new external_value(PARAM_INT, 'Problem-solving score', VALUE_DEFAULT, 0),
            'problemsolvingdisplay' => new external_value(PARAM_TEXT, 'Problem-solving display'),
            'coding' => new external_value(PARAM_INT, 'Coding score', VALUE_DEFAULT, 0),
            'codingdisplay' => new external_value(PARAM_TEXT, 'Coding display'),
            'explanation' => new external_value(PARAM_INT, 'Explanation score', VALUE_DEFAULT, 0),
            'explanationdisplay' => new external_value(PARAM_TEXT, 'Explanation display'),
            'communication' => new external_value(PARAM_INT, 'Communication score', VALUE_DEFAULT, 0),
            'communicationdisplay' => new external_value(PARAM_TEXT, 'Communication display'),
            'independence' => new external_value(PARAM_INT, 'Independence score', VALUE_DEFAULT, 0),
            'independencedisplay' => new external_value(PARAM_TEXT, 'Independence display'),
            'started' => new external_value(PARAM_TEXT, 'Started'),
            'completed' => new external_value(PARAM_TEXT, 'Completed'),
            'sessionid' => new external_value(PARAM_RAW, 'Session id'),
            'feedbackurl' => new external_value(PARAM_RAW, 'Feedback URL'),
            'feedbacklabel' => new external_value(PARAM_TEXT, 'Feedback link label'),
        ]);
        return new external_single_structure([
            'generated' => new external_value(PARAM_INT, 'Generated'),
            'rows' => new external_multiple_structure($row),
            'summary' => new external_single_structure([
                'learners' => new external_value(PARAM_INT, 'Unique learners'),
                'totalattempts' => new external_value(PARAM_INT, 'Total attempts'),
                'completed' => new external_value(PARAM_INT, 'Completed attempts'),
                'inprogress' => new external_value(PARAM_INT, 'In-progress attempts'),
                'avgscore' => new external_value(PARAM_INT, 'Average completed score'),
            ]),
            'cohorts' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
            ])),
            'colleges' => new external_multiple_structure($option),
            'years' => new external_multiple_structure($option),
            'departments' => new external_multiple_structure($option),
            'tracks' => new external_multiple_structure($option),
            'selectedcohortid' => new external_value(PARAM_INT, 'Selected cohort'),
            'selectedinstitution' => new external_value(PARAM_TEXT, 'Selected college'),
            'selectedyear' => new external_value(PARAM_TEXT, 'Selected year'),
            'selecteddepartment' => new external_value(PARAM_TEXT, 'Selected department'),
            'selectedstatus' => new external_value(PARAM_ALPHANUMEXT, 'Selected status'),
            'selectedtrack' => new external_value(PARAM_ALPHANUMEXT, 'Selected track'),
            'showcollege' => new external_value(PARAM_INT, '1 when college filter is available'),
            'showdepartment' => new external_value(PARAM_INT, '1 when department filter is available'),
            'search' => new external_value(PARAM_TEXT, 'Search'),
            'total' => new external_value(PARAM_INT, 'Total matched'),
        ]);
    }
}
