<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexStack mission catalog (NexCodeLab-style UI).
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexstack:view', $context);

$PAGE->set_url(new moodle_url('/local/nexstack/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_nexstack'));
local_nexstack_setup_page($PAGE);

$cards = \local_nexstack\local\missions::catalog_for_user((int) $USER->id);
foreach ($cards as $i => &$c) {
    $raw = (string) ($c['status'] ?? 'new');
    if ($raw === 'completed') {
        $c['status'] = 'completed';
        $c['userstatus'] = 'completed';
    } else if ($raw === 'inprogress' || $raw === 'in_progress') {
        $c['status'] = 'inprogress';
        $c['userstatus'] = 'inprogress';
    } else {
        $c['status'] = 'notstarted';
        $c['userstatus'] = 'notstarted';
    }
    $c['number'] = $i + 1;
}
unset($c);

$header = local_nexstack_header_context((int) $USER->id, $cards);
$catalogcss = new moodle_url('/local/nexstack/styles_catalog.css', ['v' => '2026081618']);

$PAGE->requires->js_call_amd('local_nexstack/catalog', 'init', []);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexstack/catalog', array_merge($header, [
    'listurl' => (new moodle_url('/local/nexstack/index.php'))->out(false),
    'catalogcssurl' => $catalogcss->out(false),
    'missionsjson' => json_encode($cards, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS),
]));
echo $OUTPUT->footer();
