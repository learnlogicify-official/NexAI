<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Renderer for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\output;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\base as format_base;
use core_courseformat\output\section_renderer;
use stdClass;

/**
 * Basic renderer for NexCoursePro format.
 */
class renderer extends section_renderer {

    public function section_title($section, $course): string {
        return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section));
    }

    public function section_title_without_link($section, $course): string {
        return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section, false));
    }
}
