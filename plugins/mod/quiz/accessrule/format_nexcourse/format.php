<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Format file — course page rendering for format_nexcourse.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/completionlib.php');

$format = core_courseformat\base::instance($course);
$course = $format->get_course();
$context = context_course::instance($course->id);

course_create_sections_if_missing($course, 0);

$renderer = $format->get_renderer($PAGE);

// While editing, show the full section list so drag-drop / subsections work.
// (Single-section view only when browsing with editing off.)
if ($PAGE->user_is_editing()) {
    if (method_exists($format, 'set_sectionnum')) {
        $format->set_sectionnum(null);
    } else if (method_exists($format, 'set_section_number')) {
        $format->set_section_number(0);
    }
} else if (!empty($displaysection)) {
    if (method_exists($format, 'set_sectionnum')) {
        $format->set_sectionnum($displaysection);
    } else if (method_exists($format, 'set_section_number')) {
        $format->set_section_number($displaysection);
    }
}

// Note: do not call $PAGE->add_body_class() here — output has already started.
// Moodle already adds body class "format-nexcourse" from the course format name.

$outputclass = $format->get_output_classname('content');
$widget = new $outputclass($format);
echo $renderer->render($widget);

$PAGE->requires->js_call_amd('format_nexcourse/ui', 'init', []);
