<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Manage NexPractice problems — list.
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

$manageurl = new moodle_url('/local/learnlogic/manage/index.php');
$PAGE->set_url($manageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage', 'local_learnlogic'));
$PAGE->set_heading('');
$PAGE->navbar->add(get_string('pluginname', 'local_learnlogic'), new moodle_url('/local/learnlogic/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_learnlogic'));
local_learnlogic_setup_manage_page($PAGE);

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'bulkcompanies' && data_submitted() && confirm_sesskey()) {
    $problemids = optional_param_array('problemids', [], PARAM_INT);
    $companies = required_param('companies', PARAM_TEXT);
    $mode = optional_param('mode', 'add', PARAM_ALPHA);
    $names = preg_split('/\s*,\s*/', (string) $companies, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    try {
        $updated = manage::bulk_update_company_tags($problemids, $names, $mode);
        $key = ($mode === 'replace') ? 'bulkcompaniesreplaced' : 'bulkcompaniesadded';
        redirect($manageurl, get_string($key, 'local_learnlogic', $updated), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($manageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$problems = manage::list_problems();
$filtertags = manage::list_tags();
$filtercategories = manage::filter_categories_from_problems($problems);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnlogic/manage_index', [
    'listurl' => (new moodle_url('/local/learnlogic/index.php'))->out(false),
    'createurl' => (new moodle_url('/local/learnlogic/manage/edit.php'))->out(false),
    'importurl' => (new moodle_url('/local/learnlogic/manage/import.php'))->out(false),
    'tagsurl' => (new moodle_url('/local/learnlogic/manage/tags.php'))->out(false),
    'formaction' => $manageurl->out(false),
    'sesskey' => sesskey(),
    'hasproblems' => !empty($problems),
    'problemcount' => count($problems),
    'problems' => $problems,
    'hastagsforfilter' => !empty($filtertags),
    'filtertags' => $filtertags,
    'hascategoriesforfilter' => !empty($filtercategories),
    'filtercategories' => $filtercategories,
]);
echo $OUTPUT->footer();
