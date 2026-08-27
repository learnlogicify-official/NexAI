<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexCourse — My Courses hub.
 *
 * @package    local_nexcourse
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcourse:view', $context);

$PAGE->set_title(get_string('pluginname', 'local_nexcourse'));
local_nexcourse_setup_page($PAGE);
$PAGE->requires->css('/local/nexcourse/styles.css');

$payload = \local_nexcourse\local\catalog::page_context((int) $USER->id);

$PAGE->requires->js_call_amd('local_nexcourse/courses', 'init', [[
    'page' => (int) ($payload['page'] ?? 0),
    'total' => (int) ($payload['total'] ?? 0),
    'autoload' => !empty($payload['autoload']),
    'strings' => [
        'coursesfound' => get_string('coursesfound', 'local_nexcourse'),
        'nocourses_filtered' => get_string('nocourses_filtered', 'local_nexcourse'),
        'progress' => get_string('progress', 'local_nexcourse'),
        'prev' => get_string('prev', 'local_nexcourse'),
        'next' => get_string('next', 'local_nexcourse'),
        'showing' => get_string('showing', 'local_nexcourse', (object) [
            'from' => '{$a->from}',
            'to' => '{$a->to}',
            'total' => '{$a->total}',
        ]),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexcourse/courses', array_merge($payload, [
    'listurl' => (new moodle_url('/local/nexcourse/index.php'))->out(false),
    'dashboardurl' => (new moodle_url('/local/nexdashboard/index.php'))->out(false),
    'hasdashboard' => file_exists($CFG->dirroot . '/local/nexdashboard/version.php'),
]));
echo $OUTPUT->footer();
