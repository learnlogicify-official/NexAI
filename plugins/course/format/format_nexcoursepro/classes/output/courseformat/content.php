<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course content output for format_nexcoursepro.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\output\courseformat;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\output\local\content as content_base;
use format_nexcoursepro\local\catalog;
use renderer_base;

/**
 * Renders the Pro learn shell (or core editor when editing).
 */
class content extends content_base {

    public function get_template_name(renderer_base $renderer): string {
        return 'format_nexcoursepro/local/content';
    }

    public function export_for_template(renderer_base $output) {
        global $PAGE;

        $editing = $PAGE->user_is_editing();

        $onsectionpage = $PAGE->pagetype
            && str_starts_with((string) $PAGE->pagetype, 'course-view-section-');
        if ($editing && !$onsectionpage) {
            if (method_exists($this->format, 'set_sectionnum')) {
                $this->format->set_sectionnum(null);
            } else if (method_exists($this->format, 'set_section_number')) {
                $this->format->set_section_number(0);
            }
        }

        $sectionnum = 0;
        if (method_exists($this->format, 'get_sectionnum')) {
            $sectionnum = (int) ($this->format->get_sectionnum() ?? 0);
        } else if (method_exists($this->format, 'get_section_number')) {
            $sectionnum = (int) ($this->format->get_section_number() ?? 0);
        }

        $cmid = optional_param('cmid', 0, PARAM_INT);

        $parent = parent::export_for_template($output);
        $data = is_object($parent) ? $parent : (object) $parent;

        $learn = null;
        try {
            $learn = catalog::export_learn($this->format, $PAGE, $sectionnum, $cmid);
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro export failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $learn = null;
        }

        // Browse mode: Pro learn shell. Edit mode: native Moodle course editor.
        $showlearn = !empty($learn) && !$editing;
        $data->nexcoursepro = [
            'showlearn' => $showlearn,
            'learn' => $showlearn ? $learn : null,
            'editing' => $editing,
            'hidecore' => $showlearn,
        ];

        return $data;
    }
}
