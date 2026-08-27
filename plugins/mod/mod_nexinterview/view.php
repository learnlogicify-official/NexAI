<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Activity view — start / continue interview.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('nexinterview', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('nexinterview', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/nexinterview:view', $context);

$PAGE->set_url('/mod/nexinterview/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$PAGE->requires->css('/mod/nexinterview/styles.css');

$template = nexinterview_export_view_context($cm, $instance);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_nexinterview/view', $template);
echo $OUTPUT->footer();
