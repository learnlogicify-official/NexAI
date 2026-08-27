<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Past interview reports list.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:view', $context);

$wantall = (int) optional_param('all', 0, PARAM_INT) === 1;
$canall = has_capability('local/nexinterview:viewallreports', $context) || is_siteadmin();
$showall = $wantall && $canall;

$PAGE->set_url(new moodle_url('/local/nexinterview/reports.php', $showall ? ['all' => 1] : []));
$PAGE->set_context($context);
$PAGE->set_title(get_string($showall ? 'allreportstitle' : 'reportstitle', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$history = $showall
    ? local_nexinterview_all_reports_context(100)
    : local_nexinterview_history_context((int) $USER->id, 50);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/reports', array_merge($history, [
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'canviewall' => $canall,
    'showall' => $showall,
    'allreportsurl' => (new moodle_url('/local/nexinterview/reports.php', ['all' => 1]))->out(false),
    'myreportsurl' => (new moodle_url('/local/nexinterview/reports.php'))->out(false),
    'pagetitle' => get_string($showall ? 'allreportstitle' : 'reportstitle', 'local_nexinterview'),
    'pagesub' => get_string($showall ? 'allreportssub' : 'reportssub', 'local_nexinterview'),
]));
echo $OUTPUT->footer();
