<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import CodeRunner questions into NexCodeLab.
 *
 * Step 1: pick a question bank (context).
 * Step 2: pick CodeRunner questions inside that bank.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_nexcodelab\local\importer;
use local_nexcodelab\local\runner;

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$bankcontextid = optional_param('bank', 0, PARAM_INT);
$categoryid = optional_param('category', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$urlparams = [];
if ($bankcontextid) {
    $urlparams['bank'] = $bankcontextid;
}
if ($categoryid) {
    $urlparams['category'] = $categoryid;
}
if ($search !== '') {
    $urlparams['search'] = $search;
}

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/import.php', $urlparams));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('importcoderunner', 'local_nexcodelab'));
$PAGE->set_heading(get_string('importcoderunner', 'local_nexcodelab'));
$PAGE->navbar->add(get_string('pluginname', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/manage/index.php'));
$PAGE->navbar->add(get_string('importcoderunner', 'local_nexcodelab'));
$PAGE->requires->css('/local/nexcodelab/styles.css');

$available = runner::coderunner_installed();

if ($confirm && confirm_sesskey() && $available) {
    $questionids = optional_param_array('questionids', [], PARAM_INT);
    $difficulty = optional_param('difficulty', 'medium', PARAM_ALPHA);
    $status = optional_param('status', 'draft', PARAM_ALPHA);
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $firstid = 0;
    foreach ($questionids as $qid) {
        $qid = (int) $qid;
        if ($qid < 1) {
            continue;
        }
        try {
            $result = importer::import_question($qid, (int) $USER->id, [
                'difficulty' => $difficulty,
                'status' => $status,
                'skipifexists' => false,
            ]);
            if (!empty($result['created'])) {
                $created++;
            } else {
                $updated++;
            }
            if (!$firstid) {
                $firstid = (int) $result['problemid'];
            }
        } catch (\Throwable $e) {
            $skipped++;
            debugging('NexCodeLab import q' . $qid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    $msg = get_string('importresult', 'local_nexcodelab', (object) [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
    ]);
    $return = new moodle_url('/local/nexcodelab/manage/import.php', array_filter([
        'bank' => $bankcontextid ?: null,
        'category' => $categoryid ?: null,
    ]));
    if ($firstid && ($created + $updated) === 1) {
        redirect(
            new moodle_url('/local/nexcodelab/manage/edit.php', ['id' => $firstid, 'imported' => 1]),
            $msg,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($return, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::start_div('ncl-app ncl-manage');
echo html_writer::link(
    new moodle_url('/local/nexcodelab/manage/index.php'),
    '← ' . get_string('manage', 'local_nexcodelab'),
    ['class' => 'ncl-back']
);
echo html_writer::tag('h1', get_string('importcoderunner', 'local_nexcodelab'), ['class' => 'ncl-page-title']);
echo html_writer::tag('p', get_string('importcoderunner_desc', 'local_nexcodelab'), ['class' => 'ncl-muted']);

if (!$available) {
    echo $OUTPUT->notification(get_string('nocoderunner', 'local_nexcodelab'), 'error');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// —— Step 1: choose question bank ——
if ($bankcontextid < 1) {
    echo html_writer::tag('h2', get_string('importstep1', 'local_nexcodelab'), ['class' => 'ncl-panel__title']);
    $banks = importer::list_banks();
    if (empty($banks)) {
        echo html_writer::tag('p', get_string('importnobanks', 'local_nexcodelab'), ['class' => 'ncl-empty']);
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'ncl-table generaltable';
        $table->head = [
            get_string('importbank', 'local_nexcodelab'),
            get_string('importbankcount', 'local_nexcodelab'),
            '',
        ];
        $table->data = [];
        foreach ($banks as $bank) {
            $open = html_writer::link(
                new moodle_url('/local/nexcodelab/manage/import.php', ['bank' => $bank['contextid']]),
                get_string('importopenbank', 'local_nexcodelab'),
                ['class' => 'ncl-btn ncl-btn--primary']
            );
            $table->data[] = [
                format_string($bank['name']),
                (int) $bank['count'],
                $open,
            ];
        }
        echo html_writer::table($table);
    }
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// —— Step 2: questions in selected bank ——
$bankname = importer::bank_name($bankcontextid);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/nexcodelab/manage/import.php'),
        '← ' . get_string('importbackbanks', 'local_nexcodelab'),
        ['class' => 'ncl-back']
    ),
    ''
);
echo html_writer::tag('h2', get_string('importstep2', 'local_nexcodelab', $bankname), ['class' => 'ncl-panel__title']);

$categories = importer::list_categories($bankcontextid);
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/nexcodelab/manage/import.php'))->out(false),
    'class' => 'ncl-import-search',
    'style' => 'display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin:0.75rem 0 1rem',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bank', 'value' => $bankcontextid]);

if (!empty($categories)) {
    $catopts = [0 => get_string('importallcategories', 'local_nexcodelab')];
    foreach ($categories as $cat) {
        $catopts[$cat['id']] = $cat['name'] . ' (' . $cat['count'] . ')';
    }
    echo html_writer::select($catopts, 'category', $categoryid, false);
}

echo html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'search',
    'value' => s($search),
    'placeholder' => get_string('importsearch', 'local_nexcodelab'),
    'class' => 'ncl-input',
    'style' => 'min-width:16rem',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter', 'moodle'),
    'class' => 'ncl-btn ncl-btn--secondary',
]);
echo html_writer::end_tag('form');

$candidates = importer::search_coderunner($bankcontextid, $categoryid, $search, 250);

if (empty($candidates)) {
    echo html_writer::tag('p', get_string('importnoneinbank', 'local_nexcodelab'), ['class' => 'ncl-empty']);
} else {
    echo html_writer::tag(
        'p',
        get_string('importfoundinbank', 'local_nexcodelab', count($candidates)),
        ['class' => 'ncl-muted']
    );

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/nexcodelab/manage/import.php'))->out(false),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => '1']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bank', 'value' => $bankcontextid]);
    if ($categoryid) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'category', 'value' => $categoryid]);
    }

    echo html_writer::start_div('ncl-import-options', ['style' => 'margin-bottom:0.75rem']);
    echo html_writer::tag('label', get_string('difficulty', 'local_nexcodelab') . ' ');
    echo html_writer::select(
        [
            'easy' => get_string('easy', 'local_nexcodelab'),
            'medium' => get_string('medium', 'local_nexcodelab'),
            'hard' => get_string('hard', 'local_nexcodelab'),
            'veryhard' => get_string('veryhard', 'local_nexcodelab'),
        ],
        'difficulty',
        'medium',
        false
    );
    echo ' ';
    echo html_writer::tag('label', get_string('status', 'local_nexcodelab') . ' ');
    echo html_writer::select(
        [
            'draft' => get_string('draft', 'local_nexcodelab'),
            'ready' => get_string('ready', 'local_nexcodelab'),
        ],
        'status',
        'ready',
        false
    );
    echo html_writer::end_div();

    $table = new html_table();
    $table->attributes['class'] = 'ncl-table generaltable';
    $table->head = [
        html_writer::checkbox('checkall', 1, false, '', ['id' => 'ncl-import-checkall']),
        get_string('name', 'local_nexcodelab'),
        get_string('importcategory', 'local_nexcodelab'),
        get_string('language', 'local_nexcodelab'),
        get_string('importmultilang', 'local_nexcodelab'),
        get_string('status', 'local_nexcodelab'),
    ];
    $table->data = [];
    foreach ($candidates as $c) {
        $cb = html_writer::checkbox('questionids[]', $c['id'], false, '', ['class' => 'ncl-import-cb']);
        $multi = !empty($c['multilanghint']) ? s(implode(', ', $c['multilanghint'])) : '—';
        $statuscell = $c['imported']
            ? html_writer::link(
                new moodle_url('/local/nexcodelab/manage/edit.php', ['id' => $c['problemid']]),
                get_string('importalready', 'local_nexcodelab') . ' (' . $c['problemstatus'] . ')'
            )
            : get_string('importnew', 'local_nexcodelab');
        $table->data[] = [
            $cb,
            format_string($c['name']) .
                html_writer::span(
                    ' #' . $c['id'] . ($c['version'] ? ' · v' . $c['version'] : ''),
                    'ncl-muted'
                ),
            s($c['category'] ?: '—'),
            s($c['language']) . ($c['coderunnertype'] ? ' · ' . s($c['coderunnertype']) : ''),
            $multi,
            $statuscell,
        ];
    }
    echo html_writer::table($table);

    echo html_writer::div(
        html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('importselected', 'local_nexcodelab'),
            'class' => 'ncl-btn ncl-btn--primary',
        ]),
        'ncl-manage__actions'
    );
    echo html_writer::end_tag('form');

    echo html_writer::script("
        (function(){
          var all = document.getElementById('ncl-import-checkall');
          if (!all) return;
          all.addEventListener('change', function(){
            document.querySelectorAll('.ncl-import-cb').forEach(function(cb){ cb.checked = all.checked; });
          });
        })();
    ");
}

echo html_writer::end_div();
echo $OUTPUT->footer();
