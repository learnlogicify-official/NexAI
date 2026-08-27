<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Site-wide Question Bank overview (site admins only).
 *
 * @package    local_nexqbank
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_nexqbank\local\catalog;

local_nexqbank_require_siteadmin();

$level = optional_param('level', catalog::LEVEL_ALL, PARAM_ALPHA);
$search = optional_param('q', '', PARAM_RAW_TRIMMED);

if (!array_key_exists($level, catalog::level_options())) {
    $level = catalog::LEVEL_ALL;
}

$PAGE->set_url(new moodle_url('/local/nexqbank/index.php', [
    'level' => $level,
    'q' => $search,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pagetitle', 'local_nexqbank'));
$PAGE->set_heading(get_string('pluginname', 'local_nexqbank'));
$PAGE->navbar->add(get_string('pluginname', 'local_nexqbank'));

$result = catalog::get_banks($level, $search);
$template = catalog::export_for_template($result['banks'], $result['totals'], $level, $search);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexqbank/overview', $template);
echo $OUTPUT->footer();
