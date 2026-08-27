<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Form to create / edit a custom interviewer.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Interviewer edit form.
 */
class local_nexinterview_interviewer_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'hdridentity', get_string('interviewer_hdr_identity', 'local_nexinterview'));

        $mform->addElement('text', 'name', get_string('interviewer_name', 'local_nexinterview'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', get_string('interviewer_description', 'local_nexinterview'),
            ['rows' => 2, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('header', 'hdrtrack', get_string('interviewer_hdr_track', 'local_nexinterview'));

        $roles = [];
        $trackkeys = [
            'sde_intern' => 'sde',
            'ai_engineer' => 'ai',
            'resume_deep' => 'resume',
        ];
        foreach (\local_nexinterview\local\interviewers::ROLE_TRACKS as $rid) {
            $roles[$rid] = get_string('track_' . ($trackkeys[$rid] ?? $rid), 'local_nexinterview');
        }
        $mform->addElement('select', 'roletrack', get_string('interviewer_roletrack', 'local_nexinterview'), $roles);
        $mform->setDefault('roletrack', 'sde_intern');
        $mform->addHelpButton('roletrack', 'interviewer_roletrack', 'local_nexinterview');

        $mform->addElement('text', 'topics', get_string('interviewer_topics', 'local_nexinterview'), ['size' => 60]);
        $mform->setType('topics', PARAM_TEXT);
        $mform->addHelpButton('topics', 'interviewer_topics', 'local_nexinterview');
        $mform->setDefault('topics', 'problem solving,data structures,apis,debugging,tradeoffs');

        $mform->addElement('text', 'avoidtopics', get_string('interviewer_avoidtopics', 'local_nexinterview'), ['size' => 60]);
        $mform->setType('avoidtopics', PARAM_TEXT);
        $mform->addHelpButton('avoidtopics', 'interviewer_avoidtopics', 'local_nexinterview');

        $mform->addElement('text', 'durationminutes', get_string('interviewer_duration', 'local_nexinterview'),
            ['size' => 6]);
        $mform->setType('durationminutes', PARAM_INT);
        $mform->setDefault('durationminutes', 17);
        $mform->addRule('durationminutes', null, 'required', null, 'client');
        $mform->addRule('durationminutes', null, 'numeric', null, 'client');

        $mform->addElement('text', 'qaminutes', get_string('interviewer_qaminutes', 'local_nexinterview'),
            ['size' => 6]);
        $mform->setType('qaminutes', PARAM_INT);
        $mform->setDefault('qaminutes', 0);
        $mform->addHelpButton('qaminutes', 'interviewer_qaminutes', 'local_nexinterview');

        $mform->addElement('header', 'hdrbehavior', get_string('interviewer_hdr_behavior', 'local_nexinterview'));

        $styles = [];
        foreach (\local_nexinterview\local\interviewers::STYLES as $s) {
            $styles[$s] = get_string('style_' . $s, 'local_nexinterview');
        }
        $mform->addElement('select', 'style', get_string('interviewer_style', 'local_nexinterview'), $styles);
        $mform->setDefault('style', 'friendly');
        $mform->addHelpButton('style', 'interviewer_style', 'local_nexinterview');

        $diffs = [];
        foreach (\local_nexinterview\local\interviewers::DIFFICULTIES as $d) {
            $diffs[$d] = get_string('difficulty_' . $d, 'local_nexinterview');
        }
        $mform->addElement('select', 'difficulty', get_string('interviewer_difficulty', 'local_nexinterview'), $diffs);
        $mform->setDefault('difficulty', 'intermediate');
        $mform->addHelpButton('difficulty', 'interviewer_difficulty', 'local_nexinterview');

        $paces = [];
        foreach (\local_nexinterview\local\interviewers::PACES as $p) {
            $paces[$p] = get_string('pace_' . $p, 'local_nexinterview');
        }
        $mform->addElement('select', 'pace', get_string('interviewer_pace', 'local_nexinterview'), $paces);
        $mform->setDefault('pace', 'standard');
        $mform->addHelpButton('pace', 'interviewer_pace', 'local_nexinterview');

        $mixes = [];
        foreach (\local_nexinterview\local\interviewers::QUESTION_MIXES as $q) {
            $mixes[$q] = get_string('qmix_' . $q, 'local_nexinterview');
        }
        $mform->addElement('select', 'questionmix', get_string('interviewer_questionmix', 'local_nexinterview'), $mixes);
        $mform->setDefault('questionmix', 'conceptual');
        $mform->addHelpButton('questionmix', 'interviewer_questionmix', 'local_nexinterview');

        $depths = [];
        foreach (\local_nexinterview\local\interviewers::FOLLOWUP_DEPTHS as $f) {
            $depths[$f] = get_string('followup_' . $f, 'local_nexinterview');
        }
        $mform->addElement('select', 'followupdepth', get_string('interviewer_followupdepth', 'local_nexinterview'), $depths);
        $mform->setDefault('followupdepth', 'moderate');
        $mform->addHelpButton('followupdepth', 'interviewer_followupdepth', 'local_nexinterview');

        $mform->addElement('textarea', 'briefing', get_string('interviewer_briefing', 'local_nexinterview'),
            ['rows' => 6, 'cols' => 60]);
        $mform->setType('briefing', PARAM_RAW);
        $mform->addHelpButton('briefing', 'interviewer_briefing', 'local_nexinterview');

        $mform->addElement('advcheckbox', 'includecoding', get_string('interviewer_includecoding', 'local_nexinterview'));
        $mform->setDefault('includecoding', 1);
        $mform->addHelpButton('includecoding', 'interviewer_includecoding', 'local_nexinterview');

        $mform->addElement('header', 'hdrpublish', get_string('interviewer_hdr_publish', 'local_nexinterview'));

        $mform->addElement('advcheckbox', 'enabled', get_string('interviewer_enabled', 'local_nexinterview'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('interviewer_sortorder', 'local_nexinterview'), ['size' => 6]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $mins = (int) ($data['durationminutes'] ?? 0);
        if ($mins < 10 || $mins > 45) {
            $errors['durationminutes'] = get_string('interviewer_duration_range', 'local_nexinterview');
        }
        $qa = (int) ($data['qaminutes'] ?? 0);
        if ($qa < 0 || ($mins > 0 && $qa > 0 && $qa > ($mins - 2))) {
            $errors['qaminutes'] = get_string('interviewer_qaminutes_range', 'local_nexinterview');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        return $errors;
    }
}
