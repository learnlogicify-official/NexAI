<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Section output for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\output\courseformat;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\output\local\content\section as section_base;
use renderer_base;
use stdClass;

/**
 * Section output — core template + add-section controls.
 */
class section extends section_base {

    public function get_template_name(renderer_base $renderer): string {
        return 'core_courseformat/local/content/section';
    }

    public function export_for_template(renderer_base $output): stdClass {
        $data = parent::export_for_template($output);
        if (!$this->format->get_sectionnum() && !$this->section->get_component_instance()) {
            $addsectionclass = $this->format->get_output_classname('content\\addsection');
            $addsection = new $addsectionclass($this->format);
            $data->numsections = $addsection->export_for_template($output);
            $data->insertafter = true;
        }
        return $data;
    }
}
