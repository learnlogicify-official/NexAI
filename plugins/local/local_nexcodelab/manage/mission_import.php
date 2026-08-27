<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Import Mission Labs from XML pack.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_nexcodelab\local\mission_xml;

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/mission_import.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('importmissions', 'local_nexcodelab'));
$PAGE->set_heading(get_string('importmissions', 'local_nexcodelab'));
$PAGE->navbar->add(get_string('pluginname', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/manage/index.php'));
$PAGE->navbar->add(get_string('importmissions', 'local_nexcodelab'));
$PAGE->requires->css('/local/nexcodelab/styles.css');

$confirm = optional_param('confirm', 0, PARAM_BOOL);

echo $OUTPUT->header();
echo html_writer::start_div('ncl-app ncl-manage');
echo html_writer::link(
    new moodle_url('/local/nexcodelab/manage/index.php'),
    '← ' . get_string('manage', 'local_nexcodelab'),
    ['class' => 'ncl-back']
);
echo html_writer::tag('h1', get_string('importmissions', 'local_nexcodelab'), ['class' => 'ncl-page-title']);
echo html_writer::tag('p', get_string('importmissions_help', 'local_nexcodelab'), ['class' => 'ncl-muted']);

if ($confirm && confirm_sesskey()) {
    $xml = trim(optional_param('xmltext', '', PARAM_RAW));
    if (!empty($_FILES['xmlfile']['tmp_name']) && is_uploaded_file($_FILES['xmlfile']['tmp_name'])) {
        $xml = file_get_contents($_FILES['xmlfile']['tmp_name']);
    }
    $mode = optional_param('mode', 'update', PARAM_ALPHA);
    $publish = optional_param('publish', 0, PARAM_BOOL);
    $result = mission_xml::import_xml($xml ?: '', (int) $USER->id, [
        'updateexisting' => $mode === 'update',
        'skipifexists' => $mode === 'skip',
        'publish' => (bool) $publish,
    ]);
    $msg = get_string('missionxmlresult', 'local_nexcodelab', (object) [
        'created' => $result['created'],
        'updated' => $result['updated'],
        'skipped' => $result['skipped'],
    ]);
    echo $OUTPUT->notification($msg, empty($result['errors']) ? 'success' : 'warning');
    if (!empty($result['errors'])) {
        echo html_writer::alist($result['errors']);
    }
    if (!empty($result['slugs'])) {
        echo html_writer::tag('p', get_string('missionxmlslugs', 'local_nexcodelab') . ': '
            . s(implode(', ', array_slice($result['slugs'], 0, 40)))
            . (count($result['slugs']) > 40 ? '…' : ''));
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'enctype' => 'multipart/form-data',
    'class' => 'ncl-mission-import',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => '1']);

echo html_writer::tag('label', get_string('missionxmlfile', 'local_nexcodelab'), ['for' => 'xmlfile']);
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'name' => 'xmlfile',
    'id' => 'xmlfile',
    'accept' => '.xml,text/xml,application/xml',
]);

echo html_writer::tag('p', get_string('missionxmlor', 'local_nexcodelab'), ['class' => 'ncl-muted']);
echo html_writer::tag('label', get_string('missionxmlpaste', 'local_nexcodelab'), ['for' => 'xmltext']);
echo html_writer::tag('textarea', '', [
    'name' => 'xmltext',
    'id' => 'xmltext',
    'rows' => 14,
    'style' => 'width:100%;font-family:ui-monospace,monospace;font-size:12px',
]);

echo html_writer::tag('label', get_string('missionxmlmode', 'local_nexcodelab'), ['for' => 'mode']);
echo html_writer::select(
    [
        'update' => get_string('missionxmlupdate', 'local_nexcodelab'),
        'skip' => get_string('missionxmlskip', 'local_nexcodelab'),
    ],
    'mode',
    'update',
    false
);

echo html_writer::checkbox('publish', 1, true, get_string('missionxmlpublish', 'local_nexcodelab'));
echo html_writer::empty_tag('br');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('importmissions', 'local_nexcodelab'),
    'class' => 'ncl-btn ncl-btn--primary',
]);
echo html_writer::end_tag('form');

$packurl = new moodle_url('/local/nexcodelab/content/missions_pack_50.xml');
echo html_writer::tag('p',
    get_string('missionxmlpackhint', 'local_nexcodelab') . ' '
    . html_writer::link($packurl, 'missions_pack_50.xml', ['target' => '_blank']),
    ['class' => 'ncl-muted', 'style' => 'margin-top:1rem']
);

echo html_writer::end_div();
echo $OUTPUT->footer();
