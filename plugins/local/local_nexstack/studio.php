<?php
// This file is part of Moodle - http://moodle.org/
/**
 * NexStack Studio — NexCodeLab-style multi-file practice bench.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexstack:attempt', $context);

$id = required_param('id', PARAM_INT);
$mission = \local_nexstack\local\missions::get($id);
if (!$mission || $mission->status !== 'ready') {
    throw new moodle_exception('invalidrecord', 'error');
}

$ws = \local_nexstack\local\missions::ensure_workspace((int) $USER->id, (int) $mission->id);
$files = \local_nexstack\local\missions::decode_files($ws->filesjson);
$steps = \local_nexstack\local\missions::decode_steps($mission->stepsjson);
if (!$files) {
    $files = \local_nexstack\local\missions::decode_files($mission->scaffoldjson);
    if ($files) {
        $ws = \local_nexstack\local\missions::save_files((int) $USER->id, (int) $mission->id, $files);
    }
}

$filelist = [];
$firstpath = '';
$firstcontent = '';
foreach ($files as $path => $content) {
    if ($firstpath === '') {
        $firstpath = $path;
        $firstcontent = $content;
    }
    $filelist[] = ['path' => $path, 'content' => $content];
}

$completed = [];
if ($ws->completedsteps !== '') {
    foreach (explode(',', $ws->completedsteps) as $s) {
        if ($s !== '') {
            $completed[] = (int) $s;
        }
    }
}
$activestep = (int) $ws->activestep;
$current = $steps[$activestep] ?? ($steps[0] ?? null);

$bootstrap = [
    'id' => (int) $mission->id,
    'name' => (string) $mission->name,
    'slug' => (string) $mission->slug,
    'track' => (string) $mission->track,
    'difficulty' => (string) $mission->difficulty,
    'runtime' => (string) $mission->runtime,
    'summary' => (string) ($mission->summary ?? ''),
    'briefmd' => (string) ($mission->briefmd ?? ''),
    'stepsjson' => json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
    'files' => $filelist,
    'activestep' => $activestep,
    'completedcsv' => (string) ($ws->completedsteps ?? ''),
    'status' => (string) $ws->status,
];
$bootstrapjson = json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

$PAGE->set_url(new moodle_url('/local/nexstack/studio.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title($mission->name . ' · ' . get_string('studio', 'local_nexstack'));
// Same approach as NexCodeLab IDE: standard layout so AMD boots; CSS hides Moodle chrome.
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('path-local-nexstack');
$PAGE->add_body_class('nxs-ide-attempt');
$PAGE->add_body_class('nxs-ide-boot');
$PAGE->set_heading('');

// Cache-bust explicit sheet (NexCodeLab pattern) + plugin styles.css is auto-aggregated.
$studiocss = new moodle_url('/local/nexstack/styles_studio.css', ['v' => '2026081622']);
$PAGE->requires->css($studiocss);

$acebase = '';
$aceurl = 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ace.js';
$acedir = $CFG->dirroot . '/question/type/coderunner/ace';
if (is_readable($acedir . '/ace.js')) {
    $jsrev = empty($CFG->jsrev) ? -1 : $CFG->jsrev;
    $acebase = $CFG->wwwroot . '/lib/javascript.php/' . $jsrev . '/question/type/coderunner/ace';
    $PAGE->requires->js(new moodle_url('/question/type/coderunner/ace/ace.js'), true);
    $aceurl = (new moodle_url('/question/type/coderunner/ace/ace.js'))->out(false);
}

$sandbox = (int) (get_config('local_nexstack', 'sandbox_enabled') ?: 0);
$wc = (int) (get_config('local_nexstack', 'webcontainers') ?: 0);

$PAGE->requires->js_call_amd('local_nexstack/studio', 'init', [[
    'missionid' => (int) $mission->id,
    'runtime' => (string) $mission->runtime,
    'sandbox' => (bool) $sandbox,
    'webcontainers' => (bool) $wc,
    'wcframeurl' => (new moodle_url('/local/nexstack/wcframe.php'))->out(false),
    'aceBaseUrl' => $acebase,
    'aceUrl' => $aceurl,
    'catalogurl' => (new moodle_url('/local/nexstack/index.php'))->out(false),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexstack/studio', [
    'missionname' => $mission->name,
    'catalogurl' => (new moodle_url('/local/nexstack/index.php'))->out(false),
    'studiocssurl' => $studiocss->out(false),
    'iswc' => $mission->runtime === 'webcontainer',
    'briefmd' => (string) ($mission->briefmd ?? ''),
    'steptitle' => $current['title'] ?? '',
    'stepinstructions' => $current['instructions'] ?? 'Follow the steps above.',
    'activepath' => $firstpath,
    'activecontent' => $firstcontent,
    'bootstrapjson' => $bootstrapjson,
]);
echo $OUTPUT->footer();
