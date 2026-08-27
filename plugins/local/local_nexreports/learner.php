<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Learner self-report tab.
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
\local_nexreports\local\access::require_capability('local/nexreports:viewlearner', $context);

$PAGE->set_url(new moodle_url('/local/nexreports/learner.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('learner', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');
$PAGE->requires->js_call_amd('local_nexreports/learner', 'init', [[
    'defaultDays' => 7,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/learner', local_nexreports_shell_context('learner', true, 7));
echo $OUTPUT->footer();
