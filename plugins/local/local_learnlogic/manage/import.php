<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import CodeRunner questions into NexPractice.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_learnlogic\local\importer;
use local_learnlogic\local\manage;
use local_learnlogic\local\runner;

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:manageproblems', $context);

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

$PAGE->set_url(new moodle_url('/local/learnlogic/manage/import.php', $urlparams));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('importcoderunner', 'local_learnlogic'));
$PAGE->set_heading('');
$PAGE->navbar->add(get_string('pluginname', 'local_learnlogic'), new moodle_url('/local/learnlogic/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_learnlogic'), new moodle_url('/local/learnlogic/manage/index.php'));
$PAGE->navbar->add(get_string('importcoderunner', 'local_learnlogic'));
local_learnlogic_setup_manage_page($PAGE);

$available = runner::coderunner_installed();
$formurl = (new moodle_url('/local/learnlogic/manage/import.php'))->out(false);

if ($confirm && confirm_sesskey() && $available) {
    $questionids = optional_param_array('questionids', [], PARAM_INT);
    $difficulty = optional_param('difficulty', 'medium', PARAM_ALPHA);
    $status = optional_param('status', 'ready', PARAM_ALPHA);
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
            debugging('NexPractice import q' . $qid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    $msg = get_string('importresult', 'local_learnlogic', (object) [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
    ]);
    $return = new moodle_url('/local/learnlogic/manage/import.php', array_filter([
        'bank' => $bankcontextid ?: null,
        'category' => $categoryid ?: null,
    ]));
    if ($firstid && ($created + $updated) === 1) {
        redirect(
            new moodle_url('/local/learnlogic/manage/edit.php', ['id' => $firstid, 'imported' => 1]),
            $msg,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($return, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

if (!$available) {
    echo $OUTPUT->notification(get_string('nocoderunner', 'local_learnlogic'), 'error');
    echo $OUTPUT->footer();
    exit;
}

if ($bankcontextid < 1) {
    $banks = importer::list_banks();
    $bankrows = [];
    foreach ($banks as $bank) {
        $bankrows[] = [
            'name' => format_string($bank['name']),
            'count' => (int) $bank['count'],
            'openurl' => (new moodle_url('/local/learnlogic/manage/import.php', [
                'bank' => $bank['contextid'],
            ]))->out(false),
        ];
    }
    echo $OUTPUT->render_from_template('local_learnlogic/manage_import_banks', [
        'manageurl' => (new moodle_url('/local/learnlogic/manage/index.php'))->out(false),
        'hasbanks' => !empty($bankrows),
        'banks' => $bankrows,
    ]);
    echo $OUTPUT->footer();
    exit;
}

$bankname = importer::bank_name($bankcontextid);
$categories = importer::list_categories($bankcontextid);
$catopts = [[
    'id' => 0,
    'label' => get_string('importallcategories', 'local_learnlogic'),
    'selected' => $categoryid === 0,
]];
foreach ($categories as $cat) {
    $catopts[] = [
        'id' => (int) $cat['id'],
        'label' => $cat['name'] . ' (' . $cat['count'] . ')',
        'selected' => $categoryid === (int) $cat['id'],
    ];
}

$rawcandidates = importer::search_coderunner($bankcontextid, $categoryid, $search, 250);
$candidates = [];
foreach ($rawcandidates as $c) {
    $row = manage::format_import_candidate($c);
    $row['namesearch'] = \core_text::strtolower($c['name'] ?? '');
    $row['tagsearch'] = implode(' ', $c['tags'] ?? []);
    $candidates[] = $row;
}

$difficulties = [];
foreach (['easy', 'medium', 'hard', 'veryhard'] as $d) {
    $difficulties[] = [
        'key' => $d,
        'label' => get_string($d, 'local_learnlogic'),
        'selected' => $d === 'medium',
    ];
}
$statuses = [];
foreach (['draft', 'ready'] as $s) {
    $statuses[] = [
        'key' => $s,
        'label' => get_string($s, 'local_learnlogic'),
        'selected' => $s === 'ready',
    ];
}

echo $OUTPUT->render_from_template('local_learnlogic/manage_import_questions', [
    'banksurl' => (new moodle_url('/local/learnlogic/manage/import.php'))->out(false),
    'formurl' => $formurl,
    'bankid' => $bankcontextid,
    'importtitle' => get_string('importstep2', 'local_learnlogic', $bankname),
    'foundlabel' => get_string('importfoundinbank', 'local_learnlogic', count($candidates)),
    'categoryid' => $categoryid ?: null,
    'search' => s($search),
    'sesskey' => sesskey(),
    'hascategories' => !empty($categories),
    'categories' => $catopts,
    'hascandidates' => !empty($candidates),
    'candidates' => $candidates,
    'difficulties' => $difficulties,
    'statuses' => $statuses,
]);

echo $OUTPUT->footer();
