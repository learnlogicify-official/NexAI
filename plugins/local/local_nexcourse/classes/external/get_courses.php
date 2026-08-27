<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: paginated NexCourse list.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcourse\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexcourse\local\catalog;

/**
 * Get paginated enrolled courses.
 */
class get_courses extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, '0-based page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'page size', VALUE_DEFAULT, 12),
            'search' => new external_value(PARAM_RAW, 'search', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status filter', VALUE_DEFAULT, 'all'),
            'categoryid' => new external_value(PARAM_INT, 'category id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $page = 0,
        int $perpage = 12,
        string $search = '',
        string $status = 'all',
        int $categoryid = 0
    ): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcourse:view', $context);

        $allowed = ['all', 'completed', 'inprogress', 'notstarted'];
        if (!in_array($status, $allowed, true)) {
            $status = 'all';
        }

        return catalog::fetch(
            (int) $USER->id,
            $page,
            $perpage > 0 ? $perpage : catalog::PERPAGE,
            $search,
            $status,
            $categoryid
        );
    }

    public static function execute_returns(): external_single_structure {
        $course = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'shortname' => new external_value(PARAM_TEXT, 'shortname'),
            'summary' => new external_value(PARAM_TEXT, 'summary'),
            'initials' => new external_value(PARAM_TEXT, 'initials'),
            'tone' => new external_value(PARAM_ALPHANUMEXT, 'tone'),
            'progress' => new external_value(PARAM_INT, 'progress'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'status label'),
            'badge' => new external_value(PARAM_TEXT, 'badge'),
            'categoryid' => new external_value(PARAM_INT, 'category id'),
            'category' => new external_value(PARAM_TEXT, 'category'),
            'hascategory' => new external_value(PARAM_BOOL, 'has category'),
            'activities' => new external_value(PARAM_INT, 'activities'),
            'completed' => new external_value(PARAM_INT, 'completed'),
            'sections' => new external_value(PARAM_INT, 'sections'),
            'activitieslabel' => new external_value(PARAM_TEXT, 'activities label'),
            'sectionslabel' => new external_value(PARAM_TEXT, 'sections label'),
            'hassections' => new external_value(PARAM_BOOL, 'has sections'),
            'footlabel' => new external_value(PARAM_TEXT, 'foot label'),
            'footvalue' => new external_value(PARAM_TEXT, 'foot value'),
            'hasfootvalue' => new external_value(PARAM_BOOL, 'has foot'),
            'url' => new external_value(PARAM_URL, 'url'),
            'cta' => new external_value(PARAM_TEXT, 'cta'),
        ]);

        return new external_single_structure([
            'courses' => new external_multiple_structure($course),
            'total' => new external_value(PARAM_INT, 'total'),
            'page' => new external_value(PARAM_INT, 'page'),
            'perpage' => new external_value(PARAM_INT, 'perpage'),
            'pages' => new external_value(PARAM_INT, 'pages'),
            'counts' => new external_single_structure([
                'all' => new external_value(PARAM_INT, 'all'),
                'completed' => new external_value(PARAM_INT, 'completed'),
                'inprogress' => new external_value(PARAM_INT, 'inprogress'),
                'notstarted' => new external_value(PARAM_INT, 'notstarted'),
            ]),
            'header' => new external_single_structure([
                'contentpct' => new external_value(PARAM_INT, 'avg progress'),
                'contentitems' => new external_multiple_structure(
                    new external_single_structure([
                        'key' => new external_value(PARAM_ALPHANUMEXT, 'key'),
                        'label' => new external_value(PARAM_TEXT, 'label'),
                        'display' => new external_value(PARAM_TEXT, 'display'),
                    ])
                ),
                'stats' => new external_multiple_structure(
                    new external_single_structure([
                        'key' => new external_value(PARAM_ALPHANUMEXT, 'key'),
                        'value' => new external_value(PARAM_TEXT, 'value'),
                        'label' => new external_value(PARAM_TEXT, 'label'),
                    ])
                ),
            ], 'header stats', VALUE_OPTIONAL),
        ]);
    }
}
