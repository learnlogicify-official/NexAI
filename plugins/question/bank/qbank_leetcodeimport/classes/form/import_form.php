<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Form for bulk LeetCode → CodeRunner import.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\form;

use qbank_leetcodeimport\local\coderunner_builder;
use qbank_leetcodeimport\local\prototypes;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Bulk import configuration form.
 */
class import_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('header', 'problemsheader', get_string('problemids', 'qbank_leetcodeimport'));
        $mform->addElement(
            'textarea',
            'problemids',
            get_string('problemids', 'qbank_leetcodeimport'),
            ['rows' => 10, 'cols' => 80]
        );
        $mform->setType('problemids', PARAM_RAW);
        $mform->addHelpButton('problemids', 'problemids', 'qbank_leetcodeimport');
        $mform->addRule('problemids', null, 'required', null, 'client');

        $mform->addElement('header', 'bulkheader', get_string('bulksettings', 'qbank_leetcodeimport'));

        $catalogue = $custom['prototypes'] ?? prototypes::catalogue();
        $typeoptions = $catalogue['options'] ?? [];
        $defaulttype = get_config('qbank_leetcodeimport', 'default_coderunnertype') ?: 'multilanguage';
        if (!isset($typeoptions[$defaulttype])) {
            if (isset($typeoptions['multilanguage'])) {
                $defaulttype = 'multilanguage';
            } else if (isset($typeoptions['python3'])) {
                $defaulttype = 'python3';
            } else {
                $defaulttype = (string) array_key_first($typeoptions);
            }
        }

        $mform->addElement(
            'select',
            'coderunnertype',
            get_string('coderunnertype', 'qbank_leetcodeimport'),
            $typeoptions
        );
        $mform->setDefault('coderunnertype', $defaulttype);
        $mform->addHelpButton('coderunnertype', 'coderunnertype', 'qbank_leetcodeimport');

        $mform->addElement('advcheckbox', 'usestdin', get_string('usestdin', 'qbank_leetcodeimport'));
        $mform->setDefault('usestdin', 1);
        $mform->addHelpButton('usestdin', 'usestdin', 'qbank_leetcodeimport');

        $defaultlang = get_config('qbank_leetcodeimport', 'default_language') ?: 'python3';
        $langoptions = [
            'python3' => 'Python 3',
            'java' => 'Java',
            'cpp' => 'C++',
            'c' => 'C',
            'javascript' => 'JavaScript',
            'multilanguage' => 'Multilanguage (hint)',
        ];
        $mform->addElement(
            'select',
            'language',
            get_string('language', 'qbank_leetcodeimport'),
            $langoptions
        );
        $mform->setDefault('language', isset($langoptions[$defaultlang]) ? $defaultlang : 'python3');

        $mform->addElement('text', 'defaultgrade', get_string('defaultgrade', 'qbank_leetcodeimport'));
        $mform->setType('defaultgrade', PARAM_FLOAT);
        $mform->setDefault('defaultgrade', 1);

        $mform->addElement('text', 'penaltyregime', get_string('penaltyregime', 'qbank_leetcodeimport'), ['size' => 40]);
        $mform->setType('penaltyregime', PARAM_TEXT);
        $mform->setDefault('penaltyregime', '10, 20, ...');

        $mform->addElement('advcheckbox', 'allornothing', get_string('allornothing', 'qbank_leetcodeimport'));
        $mform->setDefault('allornothing', 1);

        $precheckopts = [
            0 => get_string('precheck_disabled', 'qbank_leetcodeimport'),
            1 => get_string('precheck_empty', 'qbank_leetcodeimport'),
            2 => get_string('precheck_examples', 'qbank_leetcodeimport'),
            3 => get_string('precheck_selected', 'qbank_leetcodeimport'),
            4 => get_string('precheck_all', 'qbank_leetcodeimport'),
        ];
        $mform->addElement('select', 'precheck', get_string('precheck', 'qbank_leetcodeimport'), $precheckopts);
        $mform->setDefault('precheck', coderunner_builder::PRECHECK_EXAMPLES);
        $mform->addHelpButton('precheck', 'precheck', 'qbank_leetcodeimport');

        $mform->addElement('advcheckbox', 'hiderestiffail', get_string('hiderestiffail', 'qbank_leetcodeimport'));
        $mform->setDefault('hiderestiffail', 1);
        $mform->addHelpButton('hiderestiffail', 'hiderestiffail', 'qbank_leetcodeimport');

        $mform->addElement('text', 'answerboxlines', get_string('answerboxlines', 'qbank_leetcodeimport'));
        $mform->setType('answerboxlines', PARAM_INT);
        $mform->setDefault('answerboxlines', 12);

        $mform->addElement('text', 'answerboxcolumns', get_string('answerboxcolumns', 'qbank_leetcodeimport'));
        $mform->setType('answerboxcolumns', PARAM_INT);
        $mform->setDefault('answerboxcolumns', 100);

        $mform->addElement('advcheckbox', 'validateonsave', get_string('validateonsave', 'qbank_leetcodeimport'));
        $mform->setDefault('validateonsave', 0);

        $mform->addElement('advcheckbox', 'generatehiddentests', get_string('generatehiddentests', 'qbank_leetcodeimport'));
        $mform->setDefault('generatehiddentests', 1);

        $mform->addElement('text', 'hiddentestcount', get_string('hiddentestcount', 'qbank_leetcodeimport'));
        $mform->setType('hiddentestcount', PARAM_INT);
        $mform->setDefault('hiddentestcount', 4);
        $mform->disabledIf('hiddentestcount', 'generatehiddentests', 'notchecked');

        $mform->addElement('advcheckbox', 'includeanswer', get_string('includeanswer', 'qbank_leetcodeimport'));
        $mform->setDefault('includeanswer', 1);
        $mform->addHelpButton('includeanswer', 'includeanswer', 'qbank_leetcodeimport');

        $mform->addElement('advcheckbox', 'stoponerror', get_string('stoponerror', 'qbank_leetcodeimport'));
        $mform->setDefault('stoponerror', 0);

        $mform->addElement('advcheckbox', 'dryrun', get_string('dryrun', 'qbank_leetcodeimport'));
        $mform->setDefault('dryrun', 0);

        $defaultmodel = get_config('qbank_leetcodeimport', 'openai_model') ?: 'gpt-4o';
        $modelopts = \qbank_leetcodeimport\local\openai_client::model_options();
        if (!isset($modelopts[$defaultmodel])) {
            $modelopts = [$defaultmodel => $defaultmodel . ' (site default)'] + $modelopts;
        }
        $mform->addElement(
            'select',
            'openai_model',
            get_string('openai_model_override', 'qbank_leetcodeimport'),
            $modelopts
        );
        $mform->setDefault('openai_model', $defaultmodel);
        $mform->addHelpButton('openai_model', 'openai_model_override', 'qbank_leetcodeimport');

        $mform->addElement('hidden', 'category', $custom['defaultcategory'] ?? '');
        $mform->setType('category', PARAM_RAW);

        $this->add_action_buttons(true, get_string('pluginname', 'qbank_leetcodeimport'));
    }
}
