<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Create / edit a NexCodeLab problem.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/formslib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$id = optional_param('id', 0, PARAM_INT);
$imported = optional_param('imported', 0, PARAM_BOOL);
$problem = $id ? $DB->get_record('local_nexcodelab_problem', ['id' => $id], '*', MUST_EXIST) : null;

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/edit.php', $id ? ['id' => $id] : []));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($problem ? get_string('editproblem', 'local_nexcodelab') : get_string('createproblem', 'local_nexcodelab'));
$PAGE->set_heading($PAGE->title);
$PAGE->navbar->add(get_string('pluginname', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/manage/index.php'));
$PAGE->navbar->add($PAGE->title);
$PAGE->requires->css('/local/nexcodelab/styles.css');

/**
 * Problem edit form.
 */
class local_nexcodelab_problem_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;
        $sourceqid = (int) ($custom['sourcequestionid'] ?? 0);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('name', 'local_nexcodelab'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('text', 'slug', 'Slug', ['size' => 40]);
        $mform->setType('slug', PARAM_ALPHANUMEXT);
        $mform->addRule('slug', null, 'required');

        $mform->addElement('editor', 'statement_editor', get_string('statement', 'local_nexcodelab'));
        $mform->setType('statement_editor', PARAM_RAW);

        $diffs = [
            'easy' => get_string('easy', 'local_nexcodelab'),
            'medium' => get_string('medium', 'local_nexcodelab'),
            'hard' => get_string('hard', 'local_nexcodelab'),
            'veryhard' => get_string('veryhard', 'local_nexcodelab'),
        ];
        $mform->addElement('select', 'difficulty', get_string('difficulty', 'local_nexcodelab'), $diffs);

        $tracks = [];
        foreach (local_nexcodelab_tracks() as $tr) {
            $tracks[$tr] = get_string('track_' . $tr, 'local_nexcodelab');
        }
        $mform->addElement('select', 'track', get_string('track', 'local_nexcodelab'), $tracks);

        $mform->addElement('text', 'fixturepath', get_string('fixturepath', 'local_nexcodelab'), ['size' => 60]);
        $mform->setType('fixturepath', PARAM_PATH);

        $statuses = [
            'draft' => get_string('draft', 'local_nexcodelab'),
            'ready' => get_string('ready', 'local_nexcodelab'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_nexcodelab'), $statuses);

        $langs = [];
        foreach (local_nexcodelab_languages() as $l) {
            $langs[$l] = $l;
        }
        $mform->addElement('select', 'defaultlanguage', get_string('language', 'local_nexcodelab'), $langs);

        $mform->addElement('text', 'tags', get_string('tags', 'local_nexcodelab'), ['size' => 60]);
        $mform->setType('tags', PARAM_TEXT);

        $mform->addElement('textarea', 'preload_python3', get_string('preload', 'local_nexcodelab') . ' (python3)',
            'rows="8" cols="80"');
        $mform->setType('preload_python3', PARAM_RAW);

        $mform->addElement('header', 'prototypeshdr', get_string('langprototypes', 'local_nexcodelab'));
        $mform->addElement('static', 'prototypeshelp', '', get_string('langprototypes_desc', 'local_nexcodelab'));

        foreach (local_nexcodelab_languages() as $lang) {
            $mform->addElement(
                'text',
                'prototype_' . $lang,
                get_string('prototype_lang', 'local_nexcodelab', $lang),
                ['size' => 10]
            );
            $mform->setType('prototype_' . $lang, PARAM_INT);
        }

        if ($sourceqid > 0) {
            // Linked CR problems: tests are never stored locally.
            $mform->addElement(
                'static',
                'testcases_live',
                get_string('testcases', 'local_nexcodelab'),
                get_string('importlinktests', 'local_nexcodelab', $sourceqid)
            );
        } else {
            $mform->addElement('textarea', 'testcases_raw', get_string('testcases', 'local_nexcodelab')
                . ' (one per block: display|stdin|expected — use --- between cases)',
                'rows="16" cols="80"');
            $mform->setType('testcases_raw', PARAM_RAW);
        }

        $this->add_action_buttons(true, get_string('save', 'local_nexcodelab'));
    }
}

$form = new local_nexcodelab_problem_form(null, [
    'sourcequestionid' => $problem ? (int) ($problem->sourcequestionid ?? 0) : 0,
]);

$defaults = ['id' => $id];
if ($problem) {
    $defaults['name'] = $problem->name;
    $defaults['slug'] = $problem->slug;
    $defaults['statement_editor'] = ['text' => $problem->statement, 'format' => FORMAT_HTML];
    $defaults['difficulty'] = $problem->difficulty;
    $defaults['track'] = $problem->track ?? 'wrangling';
    $defaults['fixturepath'] = $problem->fixturepath ?? '';
    $defaults['status'] = $problem->status;
    $defaults['defaultlanguage'] = $problem->defaultlanguage;

    $tagrows = $DB->get_records_sql(
        "SELECT t.name FROM {local_nexcodelab_tag} t
           JOIN {local_nexcodelab_problem_tag} pt ON pt.tagid = t.id
          WHERE pt.problemid = ?",
        [$id]
    );
    $defaults['tags'] = implode(', ', array_map(static function ($t) {
        return $t->name;
    }, $tagrows));

    foreach (local_nexcodelab_languages() as $lang) {
        $lr = $DB->get_record('local_nexcodelab_lang', ['problemid' => $id, 'language' => $lang]);
        if (in_array($lang, ['python3', 'java', 'cpp'], true)) {
            $defaults['preload_' . $lang] = $lr ? (string) $lr->preload : '';
        }
        $defaults['prototype_' . $lang] = $lr ? (int) $lr->prototype : 0;
    }

    $tests = [];
    if (empty($problem->sourcequestionid)) {
        $tests = $DB->get_records('local_nexcodelab_testcase', ['problemid' => $id], 'sortorder ASC, id ASC');
    }
    $blocks = [];
    foreach ($tests as $t) {
        $blocks[] = $t->display . "|\n" . (string) $t->stdin . "|\n" . (string) $t->expected;
    }
    $defaults['testcases_raw'] = implode("\n---\n", $blocks);
} else {
    $defaults['statement_editor'] = ['text' => '', 'format' => FORMAT_HTML];
    $defaults['difficulty'] = 'easy';
    $defaults['track'] = 'wrangling';
    $defaults['fixturepath'] = '';
    $defaults['status'] = 'draft';
    $defaults['defaultlanguage'] = 'python3';
    $defaults['testcases_raw'] = "sample|\n1\n|\n1\n---\nhidden|\n2\n|\n2";
    foreach (local_nexcodelab_languages() as $lang) {
        $defaults['prototype_' . $lang] = 0;
    }
}

$form->set_data($defaults);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/nexcodelab/manage/index.php'));
}

if ($data = $form->get_data()) {
    $now = time();
    $rec = (object) [
        'name' => trim($data->name),
        'slug' => trim($data->slug),
        'statement' => $data->statement_editor['text'] ?? '',
        'difficulty' => $data->difficulty,
        'track' => $data->track ?? 'wrangling',
        'fixturepath' => trim((string) ($data->fixturepath ?? '')),
        'status' => $data->status,
        'defaultlanguage' => $data->defaultlanguage,
        'timemodified' => $now,
        'usermodified' => (int) $USER->id,
    ];
    if ($id) {
        $rec->id = $id;
        $DB->update_record('local_nexcodelab_problem', $rec);
        $pid = $id;
    } else {
        $rec->timecreated = $now;
        $rec->sourcequestionid = 0;
        $pid = $DB->insert_record('local_nexcodelab_problem', $rec);
    }

    // Languages / preload + per-language CodeRunner prototype overrides.
    foreach (local_nexcodelab_languages() as $lang) {
        $preloadfield = 'preload_' . $lang;
        $protofield = 'prototype_' . $lang;
        $preload = isset($data->$preloadfield) ? (string) $data->$preloadfield : '';
        $prototype = isset($data->$protofield) ? (int) $data->$protofield : 0;
        $existing = $DB->get_record('local_nexcodelab_lang', ['problemid' => $pid, 'language' => $lang]);

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
            $DB->update_record('local_nexcodelab_lang', $existing);
        } else {
            $DB->insert_record('local_nexcodelab_lang', (object) [
                'problemid' => $pid,
                'language' => $lang,
                'preload' => $preload,
                'prototype' => $prototype,
            ]);
        }
    }
    // Ensure default language row exists.
    if (!$DB->record_exists('local_nexcodelab_lang', ['problemid' => $pid, 'language' => $data->defaultlanguage])) {
        $DB->insert_record('local_nexcodelab_lang', (object) [
            'problemid' => $pid,
            'language' => $data->defaultlanguage,
            'preload' => '',
            'prototype' => 0,
        ]);
    }

    // Tags.
    $DB->delete_records('local_nexcodelab_problem_tag', ['problemid' => $pid]);
    $tagnames = preg_split('/\s*,\s*/', (string) ($data->tags ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tagnames as $tname) {
        $tname = core_text::strtolower(trim($tname));
        if ($tname === '') {
            continue;
        }
        $tag = $DB->get_record('local_nexcodelab_tag', ['name' => $tname]);
        if (!$tag) {
            $tid = $DB->insert_record('local_nexcodelab_tag', (object) ['name' => $tname]);
        } else {
            $tid = $tag->id;
        }
        if (!$DB->record_exists('local_nexcodelab_problem_tag', ['problemid' => $pid, 'tagid' => $tid])) {
            $DB->insert_record('local_nexcodelab_problem_tag', (object) [
                'problemid' => $pid,
                'tagid' => $tid,
            ]);
        }
    }

    // Testcases — only for non-linked (manual) problems. Linked CR problems
    // always read tests live from the CodeRunner question.
    $DB->delete_records('local_nexcodelab_testcase', ['problemid' => $pid]);
    $linkedsrc = 0;
    if ($id) {
        $linkedsrc = (int) $DB->get_field('local_nexcodelab_problem', 'sourcequestionid', ['id' => $pid]);
    }
    if ($linkedsrc < 1) {
        $raw = (string) ($data->testcases_raw ?? '');
        $chunks = preg_split('/\n---\n/', $raw) ?: [];
        $order = 0;
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = explode('|', $chunk, 3);
            if (count($parts) < 3) {
                continue;
            }
            $display = trim($parts[0]) === 'hidden' ? 'hidden' : 'sample';
            $DB->insert_record('local_nexcodelab_testcase', (object) [
                'problemid' => $pid,
                'stdin' => trim($parts[1]),
                'expected' => trim($parts[2]),
                'display' => $display,
                'sortorder' => $order++,
                'explanation' => '',
            ]);
        }
    }

    redirect(new moodle_url('/local/nexcodelab/manage/index.php'));
}

echo $OUTPUT->header();
echo html_writer::start_div('ncl-app ncl-manage');
if ($imported && $problem) {
    echo $OUTPUT->notification(get_string('importfixmultilang', 'local_nexcodelab'), 'info');
    if (!empty($problem->sourcequestionid)) {
        echo html_writer::tag(
            'p',
            get_string('importsourceq', 'local_nexcodelab', (int) $problem->sourcequestionid),
            ['class' => 'ncl-muted']
        );
    }
}
$form->display();
echo html_writer::end_div();
echo $OUTPUT->footer();
