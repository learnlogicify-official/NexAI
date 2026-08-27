<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course content output for format_nexcourse.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcourse\output\courseformat;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\output\local\content as content_base;
use format_nexcourse\local\catalog;
use renderer_base;

/**
 * Renders NexCourse cards on home; section tabs on module pages; core UI when editing.
 */
class content extends content_base {

    public function get_template_name(renderer_base $renderer): string {
        return 'format_nexcourse/local/content';
    }

    /**
     * @param renderer_base $output
     * @return \stdClass|array
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE;

        // section.php never loads format.php — register AMD here so RemUI hide + tabs run.
        static $amdloaded = false;
        if (!$amdloaded) {
            $PAGE->requires->js_call_amd('format_nexcourse/ui', 'init', []);
            $amdloaded = true;
        }

        $editing = $PAGE->user_is_editing();

        // Editing needs the full course editor DOM (all sections) for drag-drop.
        // Do not clear the section on dedicated /course/section.php pages.
        $onsectionpage = $PAGE->pagetype
            && str_starts_with((string) $PAGE->pagetype, 'course-view-section-');
        if ($editing && !$onsectionpage) {
            if (method_exists($this->format, 'set_sectionnum')) {
                $this->format->set_sectionnum(null);
            } else if (method_exists($this->format, 'set_section_number')) {
                $this->format->set_section_number(0);
            }
        }

        $sectionnum = null;
        if (method_exists($this->format, 'get_sectionnum')) {
            $sectionnum = $this->format->get_sectionnum();
        } else if (method_exists($this->format, 'get_section_number')) {
            $sectionnum = $this->format->get_section_number();
        }
        // Fallback when section.php set_sectionid() but get_sectionnum() is empty.
        if (($sectionnum === null || (int) $sectionnum === 0) && $onsectionpage
                && method_exists($this->format, 'get_sectionid')) {
            $sectionid = $this->format->get_sectionid();
            if ($sectionid) {
                $sectioninfo = $this->format->get_modinfo()->get_section_info_by_id((int) $sectionid, IGNORE_MISSING);
                if ($sectioninfo) {
                    $sectionnum = (int) $sectioninfo->section;
                }
            }
        }

        $parent = parent::export_for_template($output);
        $data = is_object($parent) ? $parent : (object) $parent;

        $singlesection = !$editing && ($sectionnum !== null && (int) $sectionnum > 0);
        $showhome = !$singlesection && !$editing;

        $home = null;
        $courseheader = null;
        $sectionpanel = null;
        try {
            $home = catalog::export_home($this->format, $PAGE);
            $courseheader = $home['courseheader'] ?? null;
            if ($singlesection) {
                $sectionpanel = catalog::export_section_panel($this->format, $PAGE, (int) $sectionnum);
            }
        } catch (\Throwable $e) {
            debugging('format_nexcourse export failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $home = null;
            $courseheader = null;
            $sectionpanel = null;
        }

        if ($home && ($editing || $singlesection)) {
            $home = null;
        }

        $showsectionpanel = $singlesection && !empty($sectionpanel);
        // Hide Moodle core section chrome when we show cards or subsection tabs.
        $hidecore = ($showhome && $home) || $showsectionpanel;

        $backurl = (new \moodle_url('/course/view.php', [
            'id' => $this->format->get_course()->id,
        ]))->out(false);
        $backlabel = get_string('backtocourse', 'format_nexcourse');

        // Keep back-nav inside the section panel so the module page is one composition.
        if ($showsectionpanel && is_array($sectionpanel)) {
            $sectionpanel['backurl'] = $backurl;
            $sectionpanel['backlabel'] = $backlabel;
        }

        $data->nexcourse = [
            'showhome' => $showhome && $home,
            'showsection' => ($singlesection && !$showsectionpanel) || $editing,
            'showsectionpanel' => $showsectionpanel,
            'sectionpanel' => $sectionpanel,
            'home' => $home,
            'courseheader' => $courseheader,
            'hascourseheader' => !empty($courseheader),
            'hidecore' => $hidecore,
            'backurl' => $backurl,
            'backlabel' => $backlabel,
            'singlesection' => $singlesection,
            'editing' => $editing,
        ];

        return $data;
    }
}
