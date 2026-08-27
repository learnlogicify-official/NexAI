<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Create / edit a NexPractice problem.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/formslib.php');

use local_learnlogic\local\manage;
use local_learnlogic\local\solutions;

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:manageproblems', $context);

$id = optional_param('id', 0, PARAM_INT);
$imported = optional_param('imported', 0, PARAM_BOOL);
$problem = $id ? $DB->get_record('local_learnlogic_problem', ['id' => $id], '*', MUST_EXIST) : null;

$pagetitle = $problem ? get_string('editproblem', 'local_learnlogic') : get_string('createproblem', 'local_learnlogic');

$PAGE->set_url(new moodle_url('/local/learnlogic/manage/edit.php', $id ? ['id' => $id] : []));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($pagetitle);
$PAGE->set_heading('');
$PAGE->navbar->add(get_string('pluginname', 'local_learnlogic'), new moodle_url('/local/learnlogic/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_learnlogic'), new moodle_url('/local/learnlogic/manage/index.php'));
$PAGE->navbar->add($pagetitle);
local_learnlogic_setup_manage_page($PAGE);
local_learnlogic_load_ace($PAGE);

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'import_cr_solution' && $id > 0 && confirm_sesskey() && $problem) {
    $sourceqid = (int) ($problem->sourcequestionid ?? 0);
    if ($sourceqid > 0 && solutions::tables_exist()) {
        $overwrite = optional_param('overwrite', 0, PARAM_BOOL);
        $count = solutions::import_from_coderunner($id, $sourceqid, $overwrite);
        redirect(
            new moodle_url('/local/learnlogic/manage/edit.php', ['id' => $id]),
            get_string('solutionimported', 'local_learnlogic', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

/**
 * Problem edit form.
 */
class local_learnlogic_problem_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;
        $sourceqid = (int) ($custom['sourcequestionid'] ?? 0);
        $samples = $custom['samples'] ?? [];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'basicshdr', get_string('manage_section_basics', 'local_learnlogic'));

        $mform->addElement('text', 'name', get_string('name', 'local_learnlogic'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('text', 'slug', get_string('slug', 'local_learnlogic'), ['size' => 40]);
        $mform->setType('slug', PARAM_ALPHANUMEXT);
        $mform->addRule('slug', null, 'required');

        $diffs = [
            'easy' => get_string('easy', 'local_learnlogic'),
            'medium' => get_string('medium', 'local_learnlogic'),
            'hard' => get_string('hard', 'local_learnlogic'),
            'veryhard' => get_string('veryhard', 'local_learnlogic'),
        ];
        $mform->addElement('select', 'difficulty', get_string('difficulty', 'local_learnlogic'), $diffs);

        $statuses = [
            'draft' => get_string('draft', 'local_learnlogic'),
            'ready' => get_string('ready', 'local_learnlogic'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_learnlogic'), $statuses);

        $langs = [];
        foreach (local_learnlogic_languages() as $l) {
            $langs[$l] = $l;
        }
        $mform->addElement('select', 'defaultlanguage', get_string('language', 'local_learnlogic'), $langs);

        $mform->addElement('text', 'topics', get_string('topics', 'local_learnlogic'), ['size' => 60]);
        $mform->setType('topics', PARAM_TEXT);
        $mform->addHelpButton('topics', 'topics', 'local_learnlogic');

        $mform->addElement('text', 'companies', get_string('companies', 'local_learnlogic'), ['size' => 60]);
        $mform->setType('companies', PARAM_TEXT);
        $mform->addHelpButton('companies', 'companies', 'local_learnlogic');

        $mform->addElement('header', 'contenthdr', get_string('manage_section_content', 'local_learnlogic'));

        if ($sourceqid > 0) {
            $mform->addElement(
                'static',
                'statement_live',
                get_string('statement', 'local_learnlogic'),
                get_string('importlinkstatement', 'local_learnlogic', $sourceqid)
            );
        } else {
            $mform->addElement('editor', 'statement_editor', get_string('statement', 'local_learnlogic'));
            $mform->setType('statement_editor', PARAM_RAW);
        }

        $mform->addElement('header', 'codehdr', get_string('manage_section_code', 'local_learnlogic'));

        $mform->addElement('textarea', 'preload_python3', get_string('preload', 'local_learnlogic') . ' (python3)',
            'rows="8" cols="80"');
        $mform->setType('preload_python3', PARAM_RAW);

        $mform->addElement('textarea', 'preload_java', get_string('preload', 'local_learnlogic') . ' (java)',
            'rows="6" cols="80"');
        $mform->setType('preload_java', PARAM_RAW);

        $mform->addElement('textarea', 'preload_cpp', get_string('preload', 'local_learnlogic') . ' (cpp)',
            'rows="6" cols="80"');
        $mform->setType('preload_cpp', PARAM_RAW);

        $mform->addElement('header', 'prototypeshdr', get_string('langprototypes', 'local_learnlogic'));
        $mform->addElement('static', 'prototypeshelp', '', get_string('langprototypes_desc', 'local_learnlogic'));

        foreach (local_learnlogic_languages() as $lang) {
            $mform->addElement(
                'text',
                'prototype_' . $lang,
                get_string('prototype_lang', 'local_learnlogic', $lang),
                ['size' => 10]
            );
            $mform->setType('prototype_' . $lang, PARAM_INT);
        }

        $mform->addElement('header', 'solutionshdr', get_string('manage_section_solutions', 'local_learnlogic'));
        if ($sourceqid > 0) {
            $mform->addElement('static', 'solutionimportnote', '', get_string('solutionimport_help', 'local_learnlogic'));
        } else {
            $mform->addElement('static', 'solutionmanualnote', '', get_string('solutionmanual_help', 'local_learnlogic'));
        }
        foreach (local_learnlogic_languages() as $lang) {
            $mform->addElement(
                'textarea',
                'solution_code_' . $lang,
                get_string('solutioncode', 'local_learnlogic', $lang),
                [
                    'rows' => 14,
                    'cols' => 80,
                    'class' => 'll-manage-code-source',
                    'data-ll-code-lang' => $lang,
                    'spellcheck' => 'false',
                    'wrap' => 'off',
                ]
            );
            $mform->setType('solution_code_' . $lang, PARAM_RAW);
            $mform->addElement(
                'editor',
                'solution_explain_' . $lang,
                get_string('solutionexplain', 'local_learnlogic', $lang),
                null,
                ['maxfiles' => 0]
            );
            $mform->setType('solution_explain_' . $lang, PARAM_RAW);
        }

        $mform->addElement('header', 'testshdr', get_string('testcases', 'local_learnlogic'));

        if ($sourceqid > 0) {
            $mform->addElement(
                'static',
                'testcases_live',
                get_string('testcases', 'local_learnlogic'),
                get_string('importlinktests', 'local_learnlogic', $sourceqid)
            );
        } else {
            $mform->addElement('textarea', 'testcases_raw', get_string('testcases_raw_hint', 'local_learnlogic'),
                'rows="16" cols="80"');
            $mform->setType('testcases_raw', PARAM_RAW);
        }

        if (!empty($samples)) {
            $mform->addElement('header', 'sampleexplhdr', get_string('sampleexplanations', 'local_learnlogic'));
            $mform->addElement('static', 'sampleexplhelp', '', get_string('sampleexplanations_help', 'local_learnlogic'));
            foreach ($samples as $idx => $sample) {
                $preview = shorten_text(
                    trim((string) ($sample['stdin'] ?? '')) . ' → ' . trim((string) ($sample['expected'] ?? '')),
                    90
                );
                $mform->addElement(
                    'editor',
                    'sample_explanation_' . $idx,
                    get_string('sampleexplainlabel', 'local_learnlogic', $idx + 1) . ' — ' . $preview,
                    null,
                    ['maxfiles' => 0]
                );
                $mform->setType('sample_explanation_' . $idx, PARAM_RAW);
            }
        }

        $this->add_action_buttons(true, get_string('save', 'local_learnlogic'));
    }
}

$form = new local_learnlogic_problem_form(null, [
    'sourcequestionid' => $problem ? (int) ($problem->sourcequestionid ?? 0) : 0,
    'samples' => $problem ? solutions::samples_for_edit($problem) : [],
]);

$defaults = ['id' => $id];
if ($problem) {
    $defaults['name'] = $problem->name;
    $defaults['slug'] = $problem->slug;
    if (empty($problem->sourcequestionid)) {
        $defaults['statement_editor'] = ['text' => $problem->statement, 'format' => FORMAT_HTML];
    }
    $defaults['difficulty'] = $problem->difficulty;
    $defaults['status'] = $problem->status;
    $defaults['defaultlanguage'] = $problem->defaultlanguage;

    $kindselect = manage::tag_kind_supported() ? 't.kind' : "'topic' AS kind";
    $tagrows = $DB->get_records_sql(
        "SELECT t.name, {$kindselect} FROM {local_learnlogic_tag} t
           JOIN {local_learnlogic_problem_tag} pt ON pt.tagid = t.id
          WHERE pt.problemid = ?
       ORDER BY t.name ASC",
        [$id]
    );
    $topics = [];
    $companies = [];
    foreach ($tagrows as $t) {
        $kind = manage::normalize_tag_kind((string) ($t->kind ?? 'topic'));
        if ($kind === 'company') {
            $companies[] = $t->name;
        } else {
            $topics[] = $t->name;
        }
    }
    $defaults['topics'] = implode(', ', $topics);
    $defaults['companies'] = implode(', ', $companies);

    foreach (local_learnlogic_languages() as $lang) {
        $lr = $DB->get_record('local_learnlogic_lang', ['problemid' => $id, 'language' => $lang]);
        if (in_array($lang, ['python3', 'java', 'cpp'], true)) {
            $defaults['preload_' . $lang] = $lr ? (string) $lr->preload : '';
        }
        $defaults['prototype_' . $lang] = $lr ? (int) $lr->prototype : 0;
    }

    $storedsolutions = solutions::for_problem($id);
    foreach (local_learnlogic_languages() as $lang) {
        $defaults['solution_code_' . $lang] = $storedsolutions[$lang]['code'] ?? '';
        $defaults['solution_explain_' . $lang] = [
            'text' => $storedsolutions[$lang]['explanation'] ?? '',
            'format' => FORMAT_HTML,
        ];
    }

    $editsamples = solutions::samples_for_edit($problem);
    foreach ($editsamples as $idx => $sample) {
        $defaults['sample_explanation_' . $idx] = [
            'text' => $sample['explanation'] ?? '',
            'format' => FORMAT_HTML,
        ];
    }

    $tests = [];
    if (empty($problem->sourcequestionid)) {
        $tests = $DB->get_records('local_learnlogic_testcase', ['problemid' => $id], 'sortorder ASC, id ASC');
    }
    $blocks = [];
    foreach ($tests as $t) {
        $expl = trim((string) ($t->explanation ?? ''));
        $block = $t->display . "|\n" . (string) $t->stdin . "|\n" . (string) $t->expected;
        if ($expl !== '') {
            $block .= "|\n" . $expl;
        }
        $blocks[] = $block;
    }
    $defaults['testcases_raw'] = implode("\n---\n", $blocks);
} else {
    $defaults['statement_editor'] = ['text' => '', 'format' => FORMAT_HTML];
    $defaults['difficulty'] = 'easy';
    $defaults['status'] = 'draft';
    $defaults['defaultlanguage'] = 'python3';
    $defaults['testcases_raw'] = "sample|\n1\n|\n1\n---\nhidden|\n2\n|\n2";
    foreach (local_learnlogic_languages() as $lang) {
        $defaults['prototype_' . $lang] = 0;
        $defaults['solution_code_' . $lang] = '';
        $defaults['solution_explain_' . $lang] = ['text' => '', 'format' => FORMAT_HTML];
    }
}

$form->set_data($defaults);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/learnlogic/manage/index.php'));
}

if ($data = $form->get_data()) {
    $now = time();
    $linkedqid = $problem ? (int) ($problem->sourcequestionid ?? 0) : 0;
    $rec = (object) [
        'name' => trim($data->name),
        'slug' => trim($data->slug),
        // Linked CR problems never store a duplicate statement — live questiontext only.
        'statement' => $linkedqid > 0 ? '' : ($data->statement_editor['text'] ?? ''),
        'difficulty' => $data->difficulty,
        'status' => $data->status,
        'defaultlanguage' => $data->defaultlanguage,
        'timemodified' => $now,
        'usermodified' => (int) $USER->id,
    ];
    if ($id) {
        $rec->id = $id;
        $DB->update_record('local_learnlogic_problem', $rec);
        $pid = $id;
    } else {
        $rec->timecreated = $now;
        $rec->sourcequestionid = 0;
        $pid = $DB->insert_record('local_learnlogic_problem', $rec);
    }

    // Languages / preload + per-language CodeRunner prototype overrides.
    foreach (local_learnlogic_languages() as $lang) {
        $preloadfield = 'preload_' . $lang;
        $protofield = 'prototype_' . $lang;
        $preload = isset($data->$preloadfield) ? (string) $data->$preloadfield : '';
        $prototype = isset($data->$protofield) ? (int) $data->$protofield : 0;
        $existing = $DB->get_record('local_learnlogic_lang', ['problemid' => $pid, 'language' => $lang]);

        $haspreload = trim($preload) !== '';
        $hasproto = $prototype > 0;
        if (!$existing && !$haspreload && !$hasproto && $lang !== $data->defaultlanguage) {
            continue;
        }
        if ($existing) {
            if (isset($data->$preloadfield)) {
                $existing->preload = $preload;
            }
            $existing->prototype = $prototype;
            $DB->update_record('local_learnlogic_lang', $existing);
        } else {
            $DB->insert_record('local_learnlogic_lang', (object) [
                'problemid' => $pid,
                'language' => $lang,
                'preload' => $preload,
                'prototype' => $prototype,
            ]);
        }
    }
    // Ensure default language row exists.
    if (!$DB->record_exists('local_learnlogic_lang', ['problemid' => $pid, 'language' => $data->defaultlanguage])) {
        $DB->insert_record('local_learnlogic_lang', (object) [
            'problemid' => $pid,
            'language' => $data->defaultlanguage,
            'preload' => '',
            'prototype' => 0,
        ]);
    }

    // Editorial solutions (per language).
    $solutionpayload = [];
    foreach (local_learnlogic_languages() as $lang) {
        $codefield = 'solution_code_' . $lang;
        $explainfield = 'solution_explain_' . $lang;
        $solutionpayload[$lang] = [
            'code' => isset($data->$codefield) ? (string) $data->$codefield : '',
            'explanation' => isset($data->$explainfield) ? local_learnlogic_editor_text($data->$explainfield) : '',
        ];
    }
    solutions::save_for_problem($pid, $solutionpayload);

    $sampleexpl = [];
    for ($i = 0; $i < 50; $i++) {
        $field = 'sample_explanation_' . $i;
        if (!property_exists($data, $field)) {
            break;
        }
        $sampleexpl[$i] = local_learnlogic_editor_text($data->$field);
    }

    // Topics + companies.
    $topicnames = preg_split('/\s*,\s*/', (string) ($data->topics ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $companynames = preg_split('/\s*,\s*/', (string) ($data->companies ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    manage::sync_problem_tag_names($pid, $topicnames, 'topic');
    manage::sync_problem_tag_names($pid, $companynames, 'company');

    // Testcases — only for non-linked (manual) problems. Linked CR problems
    // always read tests live from the CodeRunner question.
    $DB->delete_records('local_learnlogic_testcase', ['problemid' => $pid]);
    $linkedsrc = 0;
    if ($id) {
        $linkedsrc = (int) $DB->get_field('local_learnlogic_problem', 'sourcequestionid', ['id' => $pid]);
    }
    if ($linkedsrc < 1) {
        $raw = (string) ($data->testcases_raw ?? '');
        $chunks = preg_split('/\n---\n/', $raw) ?: [];
        $order = 0;
        $sampleidx = 0;
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = explode('|', $chunk, 4);
            if (count($parts) < 3) {
                continue;
            }
            $display = trim($parts[0]) === 'hidden' ? 'hidden' : 'sample';
            $explanation = '';
            if ($display === 'sample') {
                if (isset($sampleexpl[$sampleidx]) && trim($sampleexpl[$sampleidx]) !== '') {
                    $explanation = trim($sampleexpl[$sampleidx]);
                } else if (isset($parts[3])) {
                    $explanation = trim($parts[3]);
                }
                $sampleidx++;
            } else if (isset($parts[3])) {
                $explanation = trim($parts[3]);
            }
            $DB->insert_record('local_learnlogic_testcase', (object) [
                'problemid' => $pid,
                'stdin' => trim($parts[1]),
                'expected' => trim($parts[2]),
                'display' => $display,
                'sortorder' => $order++,
                'explanation' => $explanation,
            ]);
        }
    } else {
        solutions::save_sample_explanations($pid, $sampleexpl);
    }

    redirect(new moodle_url('/local/learnlogic/manage/index.php'));
}

echo $OUTPUT->header();
echo html_writer::start_div('ll-app ll-manage ll-manage--modern ll-manage--edit');

$subtitle = '';
if ($problem && !empty($problem->sourcequestionid)) {
    $subtitle = get_string('manage_edit_linked', 'local_learnlogic');
}

echo $OUTPUT->render_from_template('local_learnlogic/manage_edit_header', [
    'manageurl' => (new moodle_url('/local/learnlogic/manage/index.php'))->out(false),
    'title' => $pagetitle,
    'subtitle' => $subtitle,
    'showimportednotice' => $imported && $problem,
    'sourcelabel' => ($imported && $problem && !empty($problem->sourcequestionid))
        ? get_string('importsourceq', 'local_learnlogic', (int) $problem->sourcequestionid)
        : '',
    'showsolutionimport' => $problem && !empty($problem->sourcequestionid),
    'solutionimporturl' => (new moodle_url('/local/learnlogic/manage/edit.php', [
        'id' => $id,
        'action' => 'import_cr_solution',
        'sesskey' => sesskey(),
    ]))->out(false),
    'solutionimportoverwriteurl' => (new moodle_url('/local/learnlogic/manage/edit.php', [
        'id' => $id,
        'action' => 'import_cr_solution',
        'overwrite' => 1,
        'sesskey' => sesskey(),
    ]))->out(false),
]);

echo html_writer::start_div('ll-manage-form-shell');
$form->display();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
