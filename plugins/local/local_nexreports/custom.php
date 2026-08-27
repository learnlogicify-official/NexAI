<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Custom reports builder tab.
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
\local_nexreports\local\access::require_capability('local/nexreports:managecustom', $context);

$PAGE->set_url(new moodle_url('/local/nexreports/custom.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('customreports', 'local_nexreports'));
$PAGE->set_heading(get_string('pluginname', 'local_nexreports'));
local_nexreports_setup_page($PAGE);
$PAGE->requires->css('/local/nexreports/styles.css');

$shell = local_nexreports_shell_context('custom');
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexreports/placeholder', array_merge($shell, [
    'heading' => get_string('customreports', 'local_nexreports'),
    'message' => get_string('paritybuilding', 'local_nexreports'),
]));
echo $OUTPUT->footer();
