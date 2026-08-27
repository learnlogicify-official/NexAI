<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexReports site overview dashboard.
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
local_nexreports_require_access();
\local_nexreports\local\access::require_capability('local/nexreports:viewsite', $context);

$PAGE->set_url(new moodle_url('/local/nexreports/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$institutionadmin = \local_nexreports\local\access::is_scoped();
$PAGE->requires->js_call_amd('local_nexreports/overview', 'init', [[
    'defaultDays' => 7,
    'institutionAdmin' => $institutionadmin,
]]);

echo $OUTPUT->header();
$shell = local_nexreports_shell_context('overview', true, 7);
$headcount = \local_nexreports\local\overview::learner_headcount_template();
echo $OUTPUT->render_from_template('local_nexreports/overview', array_merge($shell, [
    'headcount' => $headcount,
    'institutionadmin' => $institutionadmin,
]));
echo $OUTPUT->footer();
