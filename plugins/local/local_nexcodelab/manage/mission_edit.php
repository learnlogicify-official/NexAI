<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Create / edit a NexCodeLab mission.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/formslib.php');

use local_nexcodelab\local\mission_admin;

require_login();
$context = context_system::instance();
require_capability('local/nexcodelab:manageproblems', $context);

$id = optional_param('id', 0, PARAM_INT);
$bundle = null;
if ($id) {
    $bundle = mission_admin::load($id);
}

$PAGE->set_url(new moodle_url('/local/nexcodelab/manage/mission_edit.php', $id ? ['id' => $id] : []));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? get_string('editmission', 'local_nexcodelab') : get_string('createmission', 'local_nexcodelab'));
$PAGE->set_heading($PAGE->title);
$PAGE->navbar->add(get_string('pluginname', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/index.php'));
$PAGE->navbar->add(get_string('manage', 'local_nexcodelab'), new moodle_url('/local/nexcodelab/manage/index.php'));
$PAGE->navbar->add($PAGE->title);
$PAGE->requires->css('/local/nexcodelab/styles.css');

/**
 * Mission edit form.
 */
class local_nexcodelab_mission_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $stepcount = max(1, (int) ($this->_customdata['stepcount'] ?? 1));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'metahdr', get_string('missionmeta', 'local_nexcodelab'));

        $mform->addElement('text', 'name', get_string('name', 'local_nexcodelab'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('text', 'slug', 'Slug', ['size' => 40]);
        $mform->setType('slug', PARAM_ALPHANUMEXT);
        $mform->addRule('slug', null, 'required');

        $mform->addElement('textarea', 'scenario', get_string('missionscenario', 'local_nexcodelab'), 'rows="3" cols="80"');
        $mform->setType('scenario', PARAM_TEXT);
        $mform->addRule('scenario', null, 'required');

        $tracks = [];
        foreach (local_nexcodelab_tracks() as $tr) {
            $tracks[$tr] = get_string('track_' . $tr, 'local_nexcodelab');
        }
        $mform->addElement('select', 'track', get_string('track', 'local_nexcodelab'), $tracks);

        $covers = [];
        foreach (mission_admin::cover_keys() as $key) {
            $covers[$key] = $key;
        }
        $mform->addElement('select', 'coverkey', get_string('missioncover', 'local_nexcodelab'), $covers);

        $mform->addElement('text', 'estimateminutes', get_string('estimateminutes', 'local_nexcodelab'), ['size' => 6]);
        $mform->setType('estimateminutes', PARAM_INT);
        $mform->setDefault('estimateminutes', 30);

        $statuses = [
            'draft' => get_string('draft', 'local_nexcodelab'),
            'ready' => get_string('ready', 'local_nexcodelab'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_nexcodelab'), $statuses);

        $mform->addElement('header', 'fileshdr', get_string('missionfiles', 'local_nexcodelab'));
        $mform->addElement('static', 'fileshelp', '', get_string('missionfiles_help', 'local_nexcodelab'));

        $mform->addElement('textarea', 'file_brief', 'BRIEF.md', 'rows="8" cols="80"');
        $mform->setType('file_brief', PARAM_RAW);

        $mform->addElement('textarea', 'file_main', 'main.py', 'rows="14" cols="80"');
        $mform->setType('file_main', PARAM_RAW);

        $mform->addElement('textarea', 'file_data', 'data.csv', 'rows="10" cols="80"');
        $mform->setType('file_data', PARAM_RAW);

        $mform->addElement('header', 'stepshdr', get_string('steps', 'local_nexcodelab'));
        $mform->addElement('static', 'stepshelp', '', get_string('missionsteps_help', 'local_nexcodelab'));

        $repeat = [];
        $repeat[] = $mform->createElement('header', 'stephdr', get_string('stepn', 'local_nexcodelab', '{no}'));
        $repeat[] = $mform->createElement('text', 'steptitle', get_string('steptitle', 'local_nexcodelab'), ['size' => 50]);
        $repeat[] = $mform->createElement('textarea', 'stepinstructions', get_string('stepinstructions', 'local_nexcodelab'),
            'rows="4" cols="80"');
        $repeat[] = $mform->createElement('text', 'stephint', get_string('stephint', 'local_nexcodelab'), ['size' => 60]);
        $repeat[] = $mform->createElement('select', 'stepcheckkind', get_string('checkkind', 'local_nexcodelab'), [
            'frame' => 'frame (DataFrame CSV)',
            'metric' => 'metric (float)',
            'stdout' => 'stdout (exact text)',
        ]);
        $repeat[] = $mform->createElement('text', 'stepxp', get_string('stepxp', 'local_nexcodelab'), ['size' => 6]);
        $repeat[] = $mform->createElement('textarea', 'stepgrader', get_string('graderjson', 'local_nexcodelab'),
            'rows="8" cols="80"');

        $repeatopts = [
            'steptitle' => ['type' => PARAM_TEXT],
            'stepinstructions' => ['type' => PARAM_RAW],
            'stephint' => ['type' => PARAM_TEXT],
            'stepcheckkind' => ['type' => PARAM_ALPHANUMEXT],
            'stepxp' => ['type' => PARAM_INT, 'default' => 25],
            'stepgrader' => ['type' => PARAM_RAW],
        ];

        $this->repeat_elements(
            $repeat,
            $stepcount,
            $repeatopts,
            'step_repeats',
            'step_add_fields',
            1,
            get_string('addstep', 'local_nexcodelab'),
            true
        );

        $this->add_action_buttons(true, get_string('save', 'local_nexcodelab'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $titles = $data['steptitle'] ?? [];
        $any = false;
        if (is_array($titles)) {
            foreach ($titles as $i => $title) {
                if (trim((string) $title) === '') {
                    continue;
                }
                $any = true;
                $raw = trim((string) ($data['stepgrader'][$i] ?? ''));
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $errors["stepgrader[$i]"] = get_string('missiongraderinvalid', 'local_nexcodelab', $title);
                }
            }
        }
        if (!$any) {
            $errors['steptitle[0]'] = get_string('missionneedstep', 'local_nexcodelab');
        }
        return $errors;
    }
}

$stepcount = 1;
if ($bundle) {
    $stepcount = max(1, count($bundle['steps']));
}

$mform = new local_nexcodelab_mission_form(null, ['stepcount' => $stepcount]);

if ($bundle) {
    $m = $bundle['mission'];
    $defaults = [
        'id' => (int) $m->id,
        'name' => $m->name,
        'slug' => $m->slug,
        'scenario' => $m->scenario,
        'track' => $m->track,
        'coverkey' => $m->coverkey,
        'estimateminutes' => (int) $m->estimateminutes,
        'status' => $m->status,
        'file_brief' => '',
        'file_main' => '',
        'file_data' => '',
        'steptitle' => [],
        'stepinstructions' => [],
        'stephint' => [],
        'stepcheckkind' => [],
        'stepxp' => [],
        'stepgrader' => [],
    ];
    foreach ($bundle['files'] as $f) {
        if ($f->path === 'BRIEF.md') {
            $defaults['file_brief'] = $f->content;
        } else if ($f->path === 'main.py') {
            $defaults['file_main'] = $f->content;
        } else if ($f->path === 'data.csv') {
            $defaults['file_data'] = $f->content;
        }
    }
    foreach ($bundle['steps'] as $i => $s) {
        $defaults['steptitle'][$i] = $s->title;
        $defaults['stepinstructions'][$i] = $s->instructions;
        $defaults['stephint'][$i] = $s->hint;
        $defaults['stepcheckkind'][$i] = $s->checkkind;
        $defaults['stepxp'][$i] = (int) $s->xp;
        $payload = json_decode((string) $s->graderpayload, true);
        $defaults['stepgrader'][$i] = is_array($payload)
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : (string) $s->graderpayload;
    }
    $mform->set_data($defaults);
} else {
    $files = mission_admin::default_files();
    $step = mission_admin::default_step();
    $mform->set_data([
        'id' => 0,
        'status' => 'draft',
        'track' => 'wrangling',
        'coverkey' => 'lab',
        'estimateminutes' => 30,
        'file_brief' => $files[0]['content'],
        'file_main' => $files[1]['content'],
        'file_data' => $files[2]['content'],
        'steptitle' => [$step['title']],
        'stepinstructions' => [$step['instructions']],
        'stephint' => [$step['hint']],
        'stepcheckkind' => [$step['checkkind']],
        'stepxp' => [$step['xp']],
        'stepgrader' => [$step['graderpayload']],
    ]);
}

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/nexcodelab/manage/index.php'));
}

if ($data = $mform->get_data()) {
    try {
        $newid = mission_admin::save($data, (int) $USER->id);
        redirect(
            new moodle_url('/local/nexcodelab/manage/mission_edit.php', ['id' => $newid]),
            get_string('missionsaved', 'local_nexcodelab'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('ncl-app ncl-manage');
echo html_writer::link(
    new moodle_url('/local/nexcodelab/manage/index.php'),
    '← ' . get_string('manage', 'local_nexcodelab'),
    ['class' => 'ncl-back']
);
echo html_writer::tag('h1', $PAGE->title, ['class' => 'ncl-page-title']);
$mform->display();
echo html_writer::end_div();
echo $OUTPUT->footer();
