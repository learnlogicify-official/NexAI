<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Course format: NexCourse — card modules with subsection nesting.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/format/lib.php');

/**
 * Main class for the NexCourse format.
 */
class format_nexcourse extends core_courseformat\base {

    /**
     * Returns true if this course format uses sections.
     */
    public function uses_sections(): bool {
        return true;
    }

    /**
     * Apply shared NexCourse body classes / AMD on secondary-nav pages early.
     *
     * @param moodle_page $page
     */
    public function page_set_course(moodle_page $page): void {
        parent::page_set_course($page);
        try {
            \format_nexcourse\local\chrome::on_course_set($page);
        } catch (\Throwable $e) {
            debugging('format_nexcourse chrome: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public function uses_indentation(): bool {
        return false;
    }

    public function uses_course_index(): bool {
        return true;
    }

    /**
     * Returns the information about the ajax support in the given source format.
     */
    public function supports_ajax(): stdClass {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    public function supports_components(): bool {
        return true;
    }

    /**
     * Custom action after section has been moved in AJAX mode.
     *
     * @return array
     */
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

    /**
     * Whether this format allows to delete sections.
     *
     * @param int|stdClass|section_info $section
     */
    public function can_delete_section($section): bool {
        return true;
    }

    /**
     * Indicates whether the course format supports the creation of a news forum.
     */
    public function supports_news(): bool {
        return true;
    }

    /**
     * Returns the display name of the given section.
     *
     * @param int|stdClass $section
     */
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

    /**
     * Returns the default section name for the format.
     *
     * @param stdClass|section_info $section
     */
    public function get_default_section_name($section): string {
        $sectionnum = is_object($section)
            ? (int) ($section->section ?? $section->sectionnum ?? 0)
            : (int) $section;
        if ($sectionnum === 0) {
            return get_string('section0name', 'format_nexcourse');
        }
        return get_string('sectionname', 'format_nexcourse') . ' ' . $sectionnum;
    }

    /**
     * The URL to view this course / section.
     *
     * @param null|array|stdClass $section
     * @param array $options
     */
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

        if ($sectionno !== null && $sectionno >= 0) {
            // Module cards / explicit navigation open the dedicated module page.
            if (!empty($options['navigation']) || !empty($options['sr'])) {
                $url->param('section', $sectionno);
            } else {
                // Course home / editing: stay on one page and jump to the section.
                $url->set_anchor('section-' . $sectionno);
            }
        }
        return $url;
    }

    /**
     * Loads custom format options into the course object.
     */
    public function course_format_options($foreditform = false): array {
        static $courseformatoptions = false;
        if ($courseformatoptions === false) {
            $courseformatoptions = [
                'hiddensections' => [
                    'default' => 1,
                    'type' => PARAM_INT,
                ],
                // Single page = all modules visible together (required for cross-section DnD).
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
 * Implements callback inplace_editable().
 *
 * @param string $itemtype
 * @param int $itemid
 * @param mixed $newvalue
 * @return \core\output\inplace_editable|null
 */
function format_nexcourse_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');
    if ($itemtype === 'sectionname' || $itemtype === 'sectionnamenl') {
        $section = $DB->get_record_sql(
            'SELECT s.* FROM {course_sections} s JOIN {course} c ON s.course = c.id
              WHERE s.id = ? AND c.format = ?',
            [$itemid, 'nexcourse'],
            MUST_EXIST
        );
        $format = core_courseformat\base::instance($section->course);
        return $format->inplace_editable_update_section_name($section, $itemtype, $newvalue);
    }
    return null;
}
