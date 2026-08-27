<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Manage NexPractice tags — add, rename, kind, and delete.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_learnlogic\local\manage;

require_login();
$context = context_system::instance();
require_capability('local/learnlogic:manageproblems', $context);

$returnurl = new moodle_url('/local/learnlogic/manage/index.php');
$kindfilter = optional_param('kind', 'all', PARAM_ALPHA);
if (!in_array($kindfilter, ['all', 'topic', 'company'], true)) {
    $kindfilter = 'all';
}
$tagsurl = new moodle_url('/local/learnlogic/manage/tags.php', $kindfilter === 'all' ? [] : ['kind' => $kindfilter]);
$action = optional_param('action', '', PARAM_ALPHA);
$tagid = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url($tagsurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managetags', 'local_learnlogic'));
$PAGE->set_heading('');
$PAGE->navbar->add(get_string('pluginname', 'local_learnlogic'), new moodle_url('/local/learnlogic/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_learnlogic'), $returnurl);
$PAGE->navbar->add(get_string('managetags', 'local_learnlogic'));
local_learnlogic_setup_manage_page($PAGE);

if ($action === 'rename' && data_submitted() && confirm_sesskey()) {
    $tagid = required_param('id', PARAM_INT);
    $tagname = required_param('tagname', PARAM_TEXT);
    try {
        $result = manage::rename_tag($tagid, $tagname);
        if ($result['action'] === 'merged') {
            $message = get_string('tagmerged', 'local_learnlogic', (object) [
                'from' => $result['from'],
                'name' => $result['name'],
            ]);
        } else if ($result['action'] === 'renamed') {
            $message = get_string('tagrenamed', 'local_learnlogic', $result['name']);
        } else {
            $message = get_string('tagrenameunchanged', 'local_learnlogic', $result['name']);
        }
        redirect($tagsurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect($tagsurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'setkind' && $tagid > 0 && confirm_sesskey()) {
    $kind = required_param('kind', PARAM_ALPHA);
    try {
        $name = manage::set_tag_kind($tagid, $kind);
        redirect(
            $tagsurl,
            get_string('tagkindchanged', 'local_learnlogic', (object) [
                'name' => $name,
                'kind' => get_string('tagkind_' . manage::normalize_tag_kind($kind), 'local_learnlogic'),
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\moodle_exception $e) {
        redirect($tagsurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'add' && data_submitted() && confirm_sesskey()) {
    $tagname = required_param('tagname', PARAM_TEXT);
    $kind = optional_param('tagkind', 'topic', PARAM_ALPHA);
    try {
        manage::create_tag($tagname, $kind);
        redirect(
            $tagsurl,
            get_string('tagcreated', 'local_learnlogic', manage::normalize_tag_name($tagname)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\moodle_exception $e) {
        redirect($tagsurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'delete' && $tagid > 0) {
    $tag = $DB->get_record('local_learnlogic_tag', ['id' => $tagid], '*', MUST_EXIST);
    $problemcount = manage::tag_problem_count($tagid);

    if ($confirm && confirm_sesskey()) {
        $deletedname = manage::delete_tag($tagid);
        redirect(
            $tagsurl,
            get_string('tagdeleted', 'local_learnlogic', $deletedname),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletetag', 'local_learnlogic', (object) [
            'name' => $tag->name,
            'count' => $problemcount,
        ]),
        new moodle_url('/local/learnlogic/manage/tags.php', [
            'action' => 'delete',
            'id' => $tagid,
            'confirm' => 1,
            'sesskey' => sesskey(),
            'kind' => $kindfilter,
        ]),
        $tagsurl
    );
    echo $OUTPUT->footer();
    exit;
}

$tags = manage::list_tags($kindfilter);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnlogic/manage_tags', [
    'manageurl' => $returnurl->out(false),
    'formaction' => (new moodle_url('/local/learnlogic/manage/tags.php'))->out(false),
    'sesskey' => sesskey(),
    'hastags' => !empty($tags),
    'tagcount' => count($tags),
    'tags' => $tags,
    'filterallurl' => (new moodle_url('/local/learnlogic/manage/tags.php'))->out(false),
    'filtertopicurl' => (new moodle_url('/local/learnlogic/manage/tags.php', ['kind' => 'topic']))->out(false),
    'filtercompanyurl' => (new moodle_url('/local/learnlogic/manage/tags.php', ['kind' => 'company']))->out(false),
    'isfilterall' => $kindfilter === 'all',
    'isfiltertopic' => $kindfilter === 'topic',
    'isfiltercompany' => $kindfilter === 'company',
]);
echo $OUTPUT->footer();
