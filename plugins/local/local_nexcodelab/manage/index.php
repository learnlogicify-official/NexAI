<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Manage NexCodeLab — missions + optional CodeRunner challenges.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$mpage = max(0, optional_param('mpage', 0, PARAM_INT));
$ppage = max(0, optional_param('ppage', 0, PARAM_INT));
$perpage = 20;

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/index.php', array_filter([
    'mpage' => $mpage ?: null,
    'ppage' => $ppage ?: null,
])));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage', 'local_nexcodelab'));
$PAGE->set_heading(get_string('manage', 'local_nexcodelab'));
$PAGE->navbar->add(get_string('pluginname', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_nexcodelab'));
$PAGE->requires->css('/local/nexcodelab/styles.css');

$hasmissions = $DB->get_manager()->table_exists('local_nexcodelab_mission');
$mtotal = $hasmissions ? $DB->count_records('local_nexcodelab_mission') : 0;
$missions = $hasmissions
    ? $DB->get_records('local_nexcodelab_mission', null, 'timemodified DESC', '*', $mpage * $perpage, $perpage)
    : [];
$ptotal = $DB->count_records('local_nexcodelab_problem');
$problems = $DB->get_records('local_nexcodelab_problem', null, 'timemodified DESC', '*', $ppage * $perpage, $perpage);

echo $OUTPUT->header();
echo html_writer::start_div('ncl-app ncl-manage');
echo html_writer::link(
    new moodle_url('/local/nexcodelab/index.php'),
    '← ' . get_string('backtolist', 'local_nexcodelab'),
    ['class' => 'ncl-back']
);
echo html_writer::tag('h1', get_string('manage', 'local_nexcodelab'), ['class' => 'ncl-page-title']);

echo html_writer::tag('h2', get_string('missions', 'local_nexcodelab'), ['class' => 'ncl-panel__title']);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/nexcodelab/manage/mission_edit.php'),
        get_string('createmission', 'local_nexcodelab'),
        ['class' => 'ncl-btn ncl-btn--primary']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/nexcodelab/manage/mission_import.php'),
        get_string('importmissions', 'local_nexcodelab'),
        ['class' => 'ncl-btn']
    ),
    'ncl-manage__actions'
);

$mtable = new html_table();
$mtable->attributes['class'] = 'ncl-table generaltable';
$mtable->head = [
    get_string('name', 'local_nexcodelab'),
    get_string('track', 'local_nexcodelab'),
    get_string('status', 'local_nexcodelab'),
    get_string('steps', 'local_nexcodelab'),
    '',
];
$mtable->data = [];
foreach ($missions as $m) {
    $steps = $DB->count_records('local_nexcodelab_mission_step', ['missionid' => $m->id]);
    $trackkey = 'track_' . $m->track;
    $track = get_string_manager()->string_exists($trackkey, 'local_nexcodelab')
        ? get_string($trackkey, 'local_nexcodelab') : $m->track;
    $open = html_writer::link(
        new moodle_url('/local/nexcodelab/mission.php', ['id' => $m->id]),
        get_string('openmission', 'local_nexcodelab')
    );
    $edit = html_writer::link(
        new moodle_url('/local/nexcodelab/manage/mission_edit.php', ['id' => $m->id]),
        get_string('editmission', 'local_nexcodelab')
    );
    $del = html_writer::link(
        new moodle_url('/local/nexcodelab/manage/mission_delete.php', ['id' => $m->id, 'sesskey' => sesskey()]),
        get_string('delete', 'local_nexcodelab'),
        ['class' => 'ncl-danger']
    );
    $mtable->data[] = [
        format_string($m->name),
        $track,
        $m->status,
        $steps,
        $open . ' · ' . $edit . ' · ' . $del,
    ];
}
if ($mtotal < 1) {
    echo html_writer::tag('p', get_string('nomissions', 'local_nexcodelab'));
} else {
    echo html_writer::table($mtable);
    echo $OUTPUT->paging_bar($mtotal, $mpage, $perpage, new moodle_url('/local/nexcodelab/manage/index.php', [
        'ppage' => $ppage,
    ]), 'mpage');
}

echo html_writer::tag('h2', get_string('challengesmanage', 'local_nexcodelab'), ['class' => 'ncl-panel__title']);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/nexcodelab/manage/edit.php'),
        get_string('createproblem', 'local_nexcodelab'),
        ['class' => 'ncl-btn ncl-btn--primary']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/nexcodelab/manage/import.php'),
        get_string('importcoderunner', 'local_nexcodelab'),
        ['class' => 'ncl-btn ncl-btn--secondary']
    ),
    'ncl-manage__actions'
);

$ptable = new html_table();
$ptable->attributes['class'] = 'ncl-table generaltable';
$ptable->head = [
    get_string('name', 'local_nexcodelab'),
    get_string('difficulty', 'local_nexcodelab'),
    get_string('track', 'local_nexcodelab'),
    get_string('status', 'local_nexcodelab'),
    '',
];
$ptable->data = [];
foreach ($problems as $p) {
    $edit = html_writer::link(
        new moodle_url('/local/nexcodelab/manage/edit.php', ['id' => $p->id]),
        get_string('editproblem', 'local_nexcodelab')
    );
    $del = html_writer::link(
        new moodle_url('/local/nexcodelab/manage/delete.php', ['id' => $p->id, 'sesskey' => sesskey()]),
        get_string('delete', 'local_nexcodelab'),
        ['class' => 'ncl-danger']
    );
    $diffkey = (string) $p->difficulty;
    $diff = get_string_manager()->string_exists($diffkey, 'local_nexcodelab')
        ? get_string($diffkey, 'local_nexcodelab') : $diffkey;
    $trackkey = 'track_' . $p->track;
    $track = get_string_manager()->string_exists($trackkey, 'local_nexcodelab')
        ? get_string($trackkey, 'local_nexcodelab') : (string) $p->track;
    $statuskey = (string) $p->status;
    $status = get_string_manager()->string_exists($statuskey, 'local_nexcodelab')
        ? get_string($statuskey, 'local_nexcodelab') : $statuskey;
    $ptable->data[] = [
        format_string($p->name),
        $diff,
        $track,
        $status,
        $edit . ' · ' . $del,
    ];
}
if ($ptotal < 1) {
    echo html_writer::tag('p', get_string('noproblems', 'local_nexcodelab'));
} else {
    echo html_writer::table($ptable);
    echo $OUTPUT->paging_bar($ptotal, $ppage, $perpage, new moodle_url('/local/nexcodelab/manage/index.php', [
        'mpage' => $mpage,
    ]), 'ppage');
}

echo html_writer::end_div();
echo $OUTPUT->footer();
