<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course format: NexCoursePro — full-screen learn shell with syllabus rail.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/format/lib.php');

/**
 * Main class for the NexCoursePro format.
 */
class format_nexcoursepro extends core_courseformat\base {

    public function uses_sections(): bool {
        return true;
    }

    public function page_set_course(moodle_page $page): void {
        parent::page_set_course($page);
        try {
            \format_nexcoursepro\local\chrome::on_course_set($page);
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro chrome: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public function page_set_cm(moodle_page $page): void {
        parent::page_set_cm($page);
        try {
            \format_nexcoursepro\local\chrome::on_cm_set($page);
        } catch (\Throwable $e) {
            debugging('format_nexcoursepro chrome cm: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public function uses_indentation(): bool {
        return false;
    }

    public function uses_course_index(): bool {
        return false;
    }

    public function supports_ajax(): stdClass {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    public function supports_components(): bool {
        return true;
    }

    public function ajax_section_move(): array {
        global $PAGE;
        $titles = [];
        $course = $this->get_course();
        $modinfo = get_fast_modinfo($course);
        $renderer = $this->get_renderer($PAGE);
        if ($renderer && ($sections = $modinfo->get_section_info_all())) {
            foreach ($sections as $number => $section) {
                $titles[$number] = $renderer->section_title($section, $course);
            }
        }
        return ['sectiontitles' => $titles, 'action' => 'move'];
    }

    public function can_delete_section($section): bool {
        return true;
    }

    public function supports_news(): bool {
        return true;
    }

    public function get_section_name($section): string {
        $section = $this->get_section($section);
        if ((string) $section->name !== '') {
            return format_string(
                $section->name,
                true,
                ['context' => context_course::instance($this->courseid)]
            );
        }
        return $this->get_default_section_name($section);
    }

    public function get_default_section_name($section): string {
        $sectionnum = is_object($section)
            ? (int) ($section->section ?? $section->sectionnum ?? 0)
            : (int) $section;
        if ($sectionnum === 0) {
            return get_string('section0name', 'format_nexcoursepro');
        }
        return get_string('sectionname', 'format_nexcoursepro') . ' ' . $sectionnum;
    }

    public function get_view_url($section, $options = []): moodle_url {
        $course = $this->get_course();
        $url = new moodle_url('/course/view.php', ['id' => $course->id]);

        $sectionno = null;
        if (is_object($section)) {
            $sectionno = isset($section->section) ? (int) $section->section : null;
            if ($sectionno === null && isset($section->sectionnum)) {
                $sectionno = (int) $section->sectionnum;
            }
        } else if ($section !== null && $section !== '') {
            $sectionno = (int) $section;
        }

        if ($sectionno !== null && $sectionno > 0) {
            $url->param('section', $sectionno);
        }
        return $url;
    }

    public function course_format_options($foreditform = false): array {
        static $courseformatoptions = false;
        if ($courseformatoptions === false) {
            $courseformatoptions = [
                'hiddensections' => [
                    'default' => 1,
                    'type' => PARAM_INT,
                ],
                'coursedisplay' => [
                    'default' => COURSE_DISPLAY_SINGLEPAGE,
                    'type' => PARAM_INT,
                ],
            ];
        }
        if ($foreditform && !isset($courseformatoptions['coursedisplay']['label'])) {
            $courseformatoptionsedit = [
                'hiddensections' => [
                    'label' => new lang_string('hiddensections'),
                    'element_type' => 'select',
                    'element_attributes' => [
                        [
                            0 => new lang_string('hiddensectionscollapsed'),
                            1 => new lang_string('hiddensectionsinvisible'),
                        ],
                    ],
                ],
                'coursedisplay' => [
                    'label' => new lang_string('coursedisplay'),
                    'element_type' => 'select',
                    'element_attributes' => [
                        [
                            COURSE_DISPLAY_SINGLEPAGE => new lang_string('coursedisplay_single'),
                            COURSE_DISPLAY_MULTIPAGE => new lang_string('coursedisplay_multi'),
                        ],
                    ],
                    'help' => 'coursedisplay',
                    'help_component' => 'moodle',
                ],
            ];
            $courseformatoptions = array_merge_recursive($courseformatoptions, $courseformatoptionsedit);
        }
        return $courseformatoptions;
    }
}

/**
 * @param string $itemtype
 * @param int $itemid
 * @param mixed $newvalue
 * @return \core\output\inplace_editable|null
 */
function format_nexcoursepro_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');
    if ($itemtype === 'sectionname' || $itemtype === 'sectionnamenl') {
        $section = $DB->get_record_sql(
            'SELECT s.* FROM {course_sections} s JOIN {course} c ON s.course = c.id
              WHERE s.id = ? AND c.format = ?',
            [$itemid, 'nexcoursepro'],
            MUST_EXIST
        );
        $format = core_courseformat\base::instance($section->course);
        return $format->inplace_editable_update_section_name($section, $itemtype, $newvalue);
    }
    return null;
}
