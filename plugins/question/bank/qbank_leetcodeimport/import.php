<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Question bank page: LeetCode → CodeRunner import.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/renderer.php');

use qbank_leetcodeimport\form\import_form;
use qbank_leetcodeimport\local\importer;
use qbank_leetcodeimport\local\prototypes;

require_login();
core_question\local\bank\helper::require_plugin_enabled('qbank_leetcodeimport');

list($thispageurl, $contexts, $cmid, $cm, $module, $pagevars) =
    question_edit_setup('import', '/question/bank/leetcodeimport/import.php');

global $DB, $PAGE, $OUTPUT, $COURSE;

list($catid, $catcontext) = explode(',', $pagevars['cat']);
if (!$category = $DB->get_record('question_categories', ['id' => $catid])) {
    throw new moodle_exception('nocategory', 'question');
}
$categorycontext = context::instance_by_id($category->contextid);
$category->context = $categorycontext;

if ($contexts === null) {
    $contexts = new core_question\local\bank\question_edit_contexts($categorycontext);
    $thiscontext = $contexts->lowest();
    if ($thiscontext->contextlevel == CONTEXT_COURSE) {
        require_login($thiscontext->instanceid, false);
    } else if ($thiscontext->contextlevel == CONTEXT_MODULE) {
        list($module, $cm) = get_module_from_cmid($thiscontext->instanceid);
        require_login($cm->course, false, $cm);
    }
}

$contexts->require_one_edit_tab_cap('import');
$PAGE->set_url($thispageurl);
$PAGE->set_title(get_string('pageheading', 'qbank_leetcodeimport'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->activityheader->disable();

$catalogue = prototypes::catalogue();

$mform = new import_form($thispageurl, [
    'contexts' => $contexts->having_one_edit_tab_cap('import'),
    'defaultcategory' => $pagevars['cat'],
    'prototypes' => $catalogue,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/question/edit.php', $thispageurl->params()));
}

echo $OUTPUT->header();
$renderer = $PAGE->get_renderer('core_question', 'bank');
$qbankaction = new \core_question\output\qbank_action_menu($thispageurl);
echo $renderer->render($qbankaction);

echo $OUTPUT->heading_with_help(
    get_string('pageheading', 'qbank_leetcodeimport'),
    'pageheading',
    'qbank_leetcodeimport'
);

if (!empty($catalogue['warnings'])) {
    foreach ($catalogue['warnings'] as $warning) {
        echo $OUTPUT->notification($warning, 'warning');
    }
    echo $OUTPUT->notification(get_string('duplicatetprototype_fix', 'qbank_leetcodeimport'), 'info');
}

if ($data = $mform->get_data()) {
    $problems = importer::parse_problem_list($data->problemids);
    if (!$problems) {
        echo $OUTPUT->notification(get_string('noproblems', 'qbank_leetcodeimport'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $options = [
        'coderunnertype' => (string) $data->coderunnertype,
        'language' => (string) $data->language,
        'usestdin' => !empty($data->usestdin) || prototypes::uses_stdin((string) $data->coderunnertype),
        'forcestdin' => !empty($data->usestdin),
        'defaultgrade' => (float) $data->defaultgrade,
        'penaltyregime' => (string) $data->penaltyregime,
        'allornothing' => !empty($data->allornothing) ? 1 : 0,
        'precheck' => (int) $data->precheck,
        'hiderestiffail' => !empty($data->hiderestiffail) ? 1 : 0,
        'answerboxlines' => (int) $data->answerboxlines,
        'answerboxcolumns' => (int) $data->answerboxcolumns,
        'validateonsave' => !empty($data->validateonsave),
        'generatehiddentests' => !empty($data->generatehiddentests),
        'hiddentestcount' => (int) $data->hiddentestcount,
        'includeanswer' => !empty($data->includeanswer) ? 1 : 0,
        'stoponerror' => !empty($data->stoponerror),
        'dryrun' => !empty($data->dryrun),
        'model' => trim((string) ($data->openai_model ?? '')),
    ];

    if (!empty($catalogue['duplicates'][$options['coderunnertype']]) && empty($options['dryrun'])) {
        echo $OUTPUT->notification(
            get_string('duplicatetprototype_block', 'qbank_leetcodeimport', $options['coderunnertype']),
            'error'
        );
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $back = new moodle_url('/question/edit.php', $thispageurl->params());
    $ajaxurl = (new moodle_url('/question/bank/leetcodeimport/ajax_process.php'))->out(false);

    // Live AJAX runner UI (no long PHP request — avoids browser freeze).
    echo html_writer::start_div('qbank-lc-runner', ['id' => 'qbank-lc-runner']);
    echo html_writer::div(
        get_string('progress_start', 'qbank_leetcodeimport', count($problems)),
        'alert alert-info'
    );
    echo html_writer::div(
        html_writer::div('', 'qbank-lc-barfill', [
            'data-role' => 'bar',
            'role' => 'progressbar',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
            'aria-valuenow' => '0',
            'style' => 'width:0%',
        ]),
        'qbank-lc-bartrack'
    );
    echo html_writer::div('0 / ' . count($problems) . ' (0%)', 'qbank-lc-barlabel', ['data-role' => 'barlabel']);
    echo html_writer::div('', 'qbank-lc-log', ['data-role' => 'log']);
    echo html_writer::div('', 'alert alert-success d-none qbank-lc-summary', ['data-role' => 'summary']);

    echo html_writer::start_div('table-responsive');
    echo html_writer::start_tag('table', ['class' => 'generaltable qbank-lc-results']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag('tr',
        html_writer::tag('th', get_string('problem', 'qbank_leetcodeimport'))
        . html_writer::tag('th', get_string('status', 'qbank_leetcodeimport'))
        . html_writer::tag('th', get_string('detail', 'qbank_leetcodeimport'))
    );
    echo html_writer::end_tag('thead');
    echo html_writer::tag('tbody', '', ['data-role' => 'results']);
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    echo html_writer::start_div('qbank-lc-done d-none', ['data-role' => 'done']);
    echo html_writer::link('#', get_string('downloadxml', 'qbank_leetcodeimport'), [
        'class' => 'btn btn-secondary d-none me-2',
        'data-role' => 'download',
    ]);
    echo $OUTPUT->continue_button($back);
    echo html_writer::end_div();
    echo html_writer::end_div();

    $PAGE->requires->js_call_amd('qbank_leetcodeimport/import', 'init', [[
        'ajaxurl' => $ajaxurl,
        'sesskey' => sesskey(),
        'courseid' => (int) $COURSE->id,
        'cat' => $pagevars['cat'],
        'problems' => array_values($problems),
        'options' => $options,
        'strings' => [
            'start' => get_string('progress_ajax_start', 'qbank_leetcodeimport'),
            'complete' => get_string('progress_ajax_complete', 'qbank_leetcodeimport'),
            'summary' => get_string('progress_ajax_summary', 'qbank_leetcodeimport'),
        ],
    ]]);

    echo $OUTPUT->footer();
    exit;
}

$mform->display();
echo $OUTPUT->footer();
