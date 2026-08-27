<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Format file — course page rendering for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
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

$outputclass = $format->get_output_classname('content');
$widget = new $outputclass($format);
echo $renderer->render($widget);

// AMD init is queued once via format_nexcoursepro\local\chrome (lib.php hooks).
// Do not call js_call_amd again here — a second init double-binds the rail toggle.
