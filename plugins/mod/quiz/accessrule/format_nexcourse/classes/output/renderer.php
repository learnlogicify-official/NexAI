<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Renderer for format_nexcourse.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcourse\output;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\base as format_base;
use core_courseformat\output\section_renderer;
use stdClass;

/**
 * Basic renderer for NexCourse format.
 */
class renderer extends section_renderer {

    /**
     * Generate the section title, wraps it in a link to the section page.
     *
     * @param \section_info|stdClass $section
     * @param stdClass $course
     */
    public function section_title($section, $course): string {
        return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section));
    }

    /**
     * Generate the section title without a link.
     *
     * @param \section_info|stdClass $section
     * @param int|stdClass $course
     */
    public function section_title_without_link($section, $course): string {
        return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section, false));
    }
}
