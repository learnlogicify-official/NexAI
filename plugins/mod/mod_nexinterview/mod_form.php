<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Activity settings form — profile (track or interviewer), window, duration.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_nexinterview_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;
        $mform = $this->_form;

        require_once($CFG->dirroot . '/local/nexinterview/lib.php');

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements(get_string('moduleintro', 'nexinterview'));

        $mform->addElement('header', 'interviewhdr', get_string('interviewhdr', 'nexinterview'));

        $options = ['' => get_string('chooseprofile', 'nexinterview')];
        $durations = [];

        // Default hub tracks.
        $trackgroup = [];
        foreach (local_nexinterview_tracks() as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $key = 'track:' . $id;
            $mins = local_nexinterview_is_resume_track($id) ? 20 : 17;
            $options[$key] = get_string('profile_track', 'nexinterview', format_string($t['title']));
            $durations[$key] = $mins;
            $trackgroup[] = $key;
        }

        // Custom interviewers.
        $customgroup = [];
        if (class_exists('\\local_nexinterview\\local\\interviewers')) {
            foreach (\local_nexinterview\local\interviewers::list_enabled() as $row) {
                $mins = max(10, min(45, (int) $row->durationminutes));
                $key = 'interviewer:' . (int) $row->id;
                $options[$key] = get_string(
                    'profile_interviewer',
                    'nexinterview',
                    format_string($row->name) . ' (~' . $mins . ' min)'
                );
                $durations[$key] = $mins;
                $customgroup[] = $key;
            }
        }

        $mform->addElement('select', 'profilesource', get_string('profilesource', 'nexinterview'), $options);
        $mform->addRule('profilesource', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('profilesource', 'profilesource', 'nexinterview');
        $mform->setType('profilesource', PARAM_RAW);

        // Hidden DB fields filled in data_postprocessing.
        $mform->addElement('hidden', 'interviewerid', 0);
        $mform->setType('interviewerid', PARAM_INT);
        $mform->addElement('hidden', 'roletrack', '');
        $mform->setType('roletrack', PARAM_ALPHANUMEXT);

        $mform->addElement(
            'text',
            'durationminutes',
            get_string('durationminutes', 'nexinterview'),
            ['size' => '4']
        );
        $mform->setType('durationminutes', PARAM_INT);
        $mform->setDefault('durationminutes', 17);
        $mform->addHelpButton('durationminutes', 'durationminutes', 'nexinterview');
        $mform->addRule('durationminutes', null, 'required', null, 'client');
        $mform->addRule('durationminutes', get_string('durationrange', 'nexinterview'), 'numeric', null, 'client');

        if (!empty($durations)) {
            $json = json_encode($durations);
            $mform->addElement('html', '<script>
(function() {
  var map = ' . $json . ';
  var sel = document.getElementById("id_profilesource");
  var dur = document.getElementById("id_durationminutes");
  if (!sel || !dur) { return; }
  sel.addEventListener("change", function() {
    var key = sel.value || "";
    if (map[key] && (!dur.value || dur.dataset.autofill !== "0")) {
      dur.value = map[key];
      dur.dataset.autofill = "1";
    }
  });
  dur.addEventListener("input", function() { dur.dataset.autofill = "0"; });
})();
</script>');
        }

        if (empty($trackgroup) && empty($customgroup)) {
            $mform->addElement('static', 'noprofiles', '', get_string('noprofiles', 'nexinterview'));
        } else if (empty($customgroup)) {
            $mform->addElement('static', 'nocustomhint', '', get_string('nocustomhint', 'nexinterview'));
        }

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'nexinterview'), ['size' => '4']);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 3);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'nexinterview');

        $mform->addElement('header', 'timinghdr', get_string('timinghdr', 'nexinterview'));
        $mform->addElement(
            'date_time_selector',
            'timeopen',
            get_string('timeopen', 'nexinterview'),
            ['optional' => true]
        );
        $mform->addHelpButton('timeopen', 'timeopen', 'nexinterview');
        $mform->addElement(
            'date_time_selector',
            'timeclose',
            get_string('timeclose', 'nexinterview'),
            ['optional' => true]
        );
        $mform->addHelpButton('timeclose', 'timeclose', 'nexinterview');

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prefill profilesource from stored interviewerid / roletrack.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $iid = (int) ($defaultvalues['interviewerid'] ?? 0);
        $track = (string) ($defaultvalues['roletrack'] ?? '');
        if ($iid > 0) {
            $defaultvalues['profilesource'] = 'interviewer:' . $iid;
        } else if ($track !== '') {
            $defaultvalues['profilesource'] = 'track:' . $track;
        } else {
            $defaultvalues['profilesource'] = 'track:sde_intern';
        }
    }

    /**
     * Map profilesource → interviewerid + roletrack before save.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $src = (string) ($data->profilesource ?? '');
        if (str_starts_with($src, 'interviewer:')) {
            $data->interviewerid = (int) substr($src, strlen('interviewer:'));
            $data->roletrack = '';
        } else if (str_starts_with($src, 'track:')) {
            $data->interviewerid = 0;
            $data->roletrack = substr($src, strlen('track:'));
        } else {
            $data->interviewerid = 0;
            $data->roletrack = 'sde_intern';
        }
        unset($data->profilesource);
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $src = (string) ($data['profilesource'] ?? '');
        if (str_starts_with($src, 'interviewer:')) {
            $iid = (int) substr($src, strlen('interviewer:'));
            if ($iid <= 0) {
                $errors['profilesource'] = get_string('chooseprofile', 'nexinterview');
            } else if (class_exists('\\local_nexinterview\\local\\interviewers')) {
                $row = \local_nexinterview\local\interviewers::get($iid);
                if (!$row || !(int) $row->enabled) {
                    $errors['profilesource'] = get_string('interviewerunavailable', 'nexinterview');
                }
            } else {
                $errors['profilesource'] = get_string('localrequired', 'nexinterview');
            }
        } else if (str_starts_with($src, 'track:')) {
            $track = substr($src, strlen('track:'));
            $allowed = class_exists('\\local_nexinterview\\local\\interviewers')
                ? \local_nexinterview\local\interviewers::ROLE_TRACKS
                : [];
            if ($track === '' || ($allowed && !in_array($track, $allowed, true))) {
                $errors['profilesource'] = get_string('chooseprofile', 'nexinterview');
            }
        } else {
            $errors['profilesource'] = get_string('chooseprofile', 'nexinterview');
        }

        $mins = (int) ($data['durationminutes'] ?? 0);
        if ($mins < 10 || $mins > 45) {
            $errors['durationminutes'] = get_string('durationrange', 'nexinterview');
        }
        $max = (int) ($data['maxattempts'] ?? 0);
        if ($max < 1 || $max > 20) {
            $errors['maxattempts'] = get_string('maxattemptsrange', 'nexinterview');
        }

        $open = (int) ($data['timeopen'] ?? 0);
        $close = (int) ($data['timeclose'] ?? 0);
        if ($open > 0 && $close > 0 && $close <= $open) {
            $errors['timeclose'] = get_string('closebeforeopen', 'nexinterview');
        }

        return $errors;
    }
}
