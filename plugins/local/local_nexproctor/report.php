<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Proctoring report for a quiz.
 * @package local_nexproctor
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/nexproctor:viewreport', $context);

$PAGE->set_url('/local/nexproctor/report.php', ['cmid' => $cmid, 'sessionid' => $sessionid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report_title', 'local_nexproctor'));
$PAGE->set_heading(format_string($quiz->name));
$PAGE->set_pagelayout('report');
$PAGE->requires->css(new moodle_url('/local/nexproctor/styles/monitor.css'));

echo $OUTPUT->header();
echo html_writer::tag('h2', get_string('report_title', 'local_nexproctor'));

if ($sessionid) {
    $session = $DB->get_record('local_nexproctor_sessions', ['id' => $sessionid], '*', MUST_EXIST);
    if ((int) $session->cmid !== (int) $cmid) {
        throw new moodle_exception('invalidsession', 'local_nexproctor');
    }
    $user = $DB->get_record('user', ['id' => $session->userid], '*', MUST_EXIST);
    echo html_writer::tag('h3', fullname($user) . ' — ' .
        get_string('trustscore', 'local_nexproctor') . ': ' . (int) $session->trustscore);

    $events = $DB->get_records('local_nexproctor_events', ['sessionid' => $sessionid], 'timecreated ASC');
    $evidence = $DB->get_records('local_nexproctor_evidence', ['sessionid' => $sessionid], 'timecreated ASC');
    $evbyevent = [];
    foreach ($evidence as $e) {
        if ($e->eventid) {
            $evbyevent[$e->eventid][] = $e;
        }
    }

    $table = new html_table();
    $table->head = [
        get_string('time', 'local_nexproctor'),
        get_string('event', 'local_nexproctor'),
        get_string('severity', 'local_nexproctor'),
        get_string('penalty', 'local_nexproctor'),
        get_string('evidence', 'local_nexproctor'),
    ];
    foreach ($events as $ev) {
        $bits = [];
        if (!empty($evbyevent[$ev->id])) {
            foreach ($evbyevent[$ev->id] as $filemeta) {
                $fs = get_file_storage();
                $files = $fs->get_area_files(
                    $context->id,
                    'local_nexproctor',
                    $filemeta->filearea,
                    $filemeta->itemid,
                    'timecreated DESC',
                    false
                );
                foreach ($files as $f) {
                    $url = moodle_url::make_pluginfile_url(
                        $f->get_contextid(),
                        $f->get_component(),
                        $f->get_filearea(),
                        $f->get_itemid(),
                        $f->get_filepath(),
                        $f->get_filename()
                    );
                    if (strpos($filemeta->mimetype, 'image/') === 0) {
                        $bits[] = html_writer::empty_tag('img', [
                            'src' => $url->out(false),
                            'class' => 'np-report__thumb',
                            'alt' => $filemeta->filearea,
                        ]);
                    } else {
                        $bits[] = html_writer::link($url, $filemeta->filearea);
                    }
                    break;
                }
            }
        }
        $table->data[] = [
            userdate($ev->timecreated, '%Y-%m-%d %H:%M:%S'),
            s($ev->eventtype),
            s($ev->severity),
            (int) $ev->penalty,
            implode(' ', $bits),
        ];
    }
    echo html_writer::table($table);
    echo html_writer::div(html_writer::link(
        new moodle_url('/local/nexproctor/report.php', ['cmid' => $cmid]),
        get_string('backtosessions', 'local_nexproctor')
    ));
} else {
    $sessions = $DB->get_records('local_nexproctor_sessions', ['cmid' => $cmid], 'startedat DESC', '*', 0, 200);
    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('status', 'local_nexproctor'),
        get_string('trustscore', 'local_nexproctor'),
        get_string('started', 'local_nexproctor'),
        get_string('actions', 'local_nexproctor'),
    ];
    foreach ($sessions as $s) {
        $u = $DB->get_record('user', ['id' => $s->userid]);
        $table->data[] = [
            $u ? fullname($u) : $s->userid,
            s($s->status),
            (int) $s->trustscore,
            $s->startedat ? userdate($s->startedat) : '—',
            html_writer::link(
                new moodle_url('/local/nexproctor/report.php', ['cmid' => $cmid, 'sessionid' => $s->id]),
                get_string('view', 'local_nexproctor')
            ),
        ];
    }
    if (!$sessions) {
        echo html_writer::tag('p', get_string('nosessions', 'local_nexproctor'));
    } else {
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
