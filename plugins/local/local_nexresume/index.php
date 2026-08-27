<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexResume builder page.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/local/templates.php');

require_login();

global $CFG;

$context = context_system::instance();
require_capability('local/nexresume:view', $context);

$PAGE->set_url(new moodle_url('/local/nexresume/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexresume'));
local_nexresume_setup_page($PAGE);
$PAGE->requires->css(new moodle_url('/local/nexresume/styles.css', [
    'v' => (string) get_config('local_nexresume', 'version'),
]));

$canmanage = has_capability('local/nexresume:manageown', $context);
$portfoliourl = file_exists($CFG->dirroot . '/local/nexportfolio/index.php')
    ? (new moodle_url('/local/nexportfolio/index.php'))->out(false)
    : '';
$header = local_nexresume_header_context((int) $USER->id);

$PAGE->requires->js_call_amd('local_nexresume/builder', 'init', [[
    'canManage' => $canmanage,
    'portfolioUrl' => $portfoliourl,
    'templates' => \local_nexresume\local\templates::list_for_ui(),
    'strings' => [
        'save' => get_string('save', 'local_nexresume'),
        'saving' => get_string('saving', 'local_nexresume'),
        'saved' => get_string('saved', 'local_nexresume'),
        'refreshsources' => get_string('refreshsources', 'local_nexresume'),
        'refreshing' => get_string('refreshing', 'local_nexresume'),
        'exportpdf' => get_string('exportpdf', 'local_nexresume'),
        'contact' => get_string('contact', 'local_nexresume'),
        'objective' => get_string('objective', 'local_nexresume'),
        'education' => get_string('education', 'local_nexresume'),
        'projects' => get_string('projects', 'local_nexresume'),
        'skills' => get_string('skills', 'local_nexresume'),
        'certifications' => get_string('certifications', 'local_nexresume'),
        'competitive' => get_string('competitive', 'local_nexresume'),
        'achievements' => get_string('achievements', 'local_nexresume'),
        'volunteering' => get_string('volunteering', 'local_nexresume'),
        'includesection' => get_string('includesection', 'local_nexresume'),
        'autosource' => get_string('autosource', 'local_nexresume'),
        'studentinput' => get_string('studentinput', 'local_nexresume'),
        'noprojects' => get_string('noprojects', 'local_nexresume'),
        'connectportfolio' => get_string('connectportfolio', 'local_nexresume'),
        'bullets' => get_string('bullets', 'local_nexresume'),
        'lines' => get_string('lines', 'local_nexresume'),
        'selectprojects' => get_string('selectprojects', 'local_nexresume'),
        'projectlimit' => get_string('projectlimit', 'local_nexresume'),
        'projecturl' => get_string('projecturl', 'local_nexresume'),
        'editor' => get_string('editor', 'local_nexresume'),
        'preview' => get_string('preview', 'local_nexresume'),
        'templates' => get_string('templates', 'local_nexresume'),
        'templates_help' => get_string('templates_help', 'local_nexresume'),
        'addeducation' => get_string('addeducation', 'local_nexresume'),
        'removeeducation' => get_string('removeeducation', 'local_nexresume'),
        'badge_auto' => get_string('badge_auto', 'local_nexresume'),
        'badge_yours' => get_string('badge_yours', 'local_nexresume'),
        'editor_help' => get_string('editor_help', 'local_nexresume'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexresume/builder', array_merge($header, [
    'canmanage' => $canmanage,
    'portfoliourl' => $portfoliourl,
]));
echo $OUTPUT->footer();
