<?php
namespace format_nexcourse\output\courseformat;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\output\local\content\section\cmitem as cmitem_base;
use renderer_base;

/**
 * Course module item — reuse core cmitem template.
 */
class cmitem extends cmitem_base {
    public function get_template_name(renderer_base $renderer): string {
        return 'core_courseformat/local/content/section/cmitem';
    }
}
