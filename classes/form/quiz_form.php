<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_yared_course\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Quiz wizard form.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];
        $coursename = $this->_customdata['coursename'];
        $banks = $this->_customdata['banks'];
        $sections = $this->_customdata['sections'];
        $defaultsection = (int) $this->_customdata['defaultsection'];

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'quizheader', get_string('quiz_wizard_header', 'local_ai_yared_course'));

        $mform->addElement('text', 'name', get_string('quiz_wizard_name', 'local_ai_yared_course'),
            ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->setDefault('name', get_string('quiz_wizard_defaultname', 'local_ai_yared_course', $coursename));

        $mform->addElement('editor', 'intro', get_string('quiz_wizard_intro', 'local_ai_yared_course'),
            null, ['maxfiles' => 0, 'maxbytes' => 0]);
        $mform->setType('intro', PARAM_RAW);
        $mform->addHelpButton('intro', 'quiz_wizard_intro', 'local_ai_yared_course');

        $mform->addElement('header', 'banksheader', get_string('quiz_wizard_banks_header', 'local_ai_yared_course'));

        $options = [];
        foreach ($banks as $bank) {
            $options[$bank['id']] = str_repeat('— ', (int) $bank['depth']) . $bank['name']
                . ' (' . get_string('questions_short', 'local_ai_yared_course', $bank['count']) . ')';
        }
        $mform->addElement('autocomplete', 'categoryids',
            get_string('quiz_wizard_banks', 'local_ai_yared_course'), $options, [
                'multiple' => true,
                'noselectionstring' => get_string('quiz_wizard_banks_none', 'local_ai_yared_course'),
            ]);
        $mform->addRule('categoryids', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('categoryids', 'quiz_wizard_banks', 'local_ai_yared_course');

        $mform->addElement('text', 'questioncount', get_string('quiz_wizard_count', 'local_ai_yared_course'),
            ['size' => 4]);
        $mform->setType('questioncount', PARAM_INT);
        $mform->addRule('questioncount', get_string('required'), 'required', null, 'client');
        $mform->setDefault('questioncount', 10);
        $mform->addHelpButton('questioncount', 'quiz_wizard_count', 'local_ai_yared_course');

        $mform->addElement('header', 'placementheader', get_string('quiz_wizard_placement', 'local_ai_yared_course'));

        $mform->addElement('select', 'section', get_string('quiz_wizard_section', 'local_ai_yared_course'),
            $sections);
        $mform->setDefault('section', $defaultsection);
        $mform->addHelpButton('section', 'quiz_wizard_section', 'local_ai_yared_course');

        $mform->addElement('advcheckbox', 'visible', get_string('quiz_wizard_visible', 'local_ai_yared_course'),
            get_string('quiz_wizard_visible_desc', 'local_ai_yared_course'));
        $mform->setDefault('visible', 1);

        $mform->addElement('static', 'settingssummary', get_string('quiz_wizard_defaults', 'local_ai_yared_course'),
            get_string('quiz_wizard_defaults_desc', 'local_ai_yared_course'));

        $this->add_action_buttons(false, get_string('quiz_wizard_create', 'local_ai_yared_course'));
    }

    /**
     * Validation.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $banks = $this->_customdata['banks'];
        $sections = $this->_customdata['sections'];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = get_string('quiz_wizard_err_name', 'local_ai_yared_course');
        } else if (\core_text::strlen($name) > 255) {
            $errors['name'] = get_string('quiz_wizard_err_namelong', 'local_ai_yared_course');
        }

        $count = (int) ($data['questioncount'] ?? 0);
        if ($count < 1 || $count > 100) {
            $errors['questioncount'] = get_string('quiz_wizard_err_count', 'local_ai_yared_course');
        }

        $selected = $data['categoryids'] ?? [];
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        $selected = array_values(array_unique(array_map('intval', $selected)));
        if (!$selected) {
            $errors['categoryids'] = get_string('quiz_wizard_err_banks', 'local_ai_yared_course');
        } else {
            $available = 0;
            foreach ($selected as $categoryid) {
                if (!isset($banks[$categoryid])) {
                    $errors['categoryids'] = get_string('quiz_wizard_err_bankunknown',
                        'local_ai_yared_course');
                    break;
                }
                if ((int) $banks[$categoryid]['count'] < 1) {
                    $errors['categoryids'] = get_string('quiz_wizard_err_bankempty',
                        'local_ai_yared_course', $banks[$categoryid]['name']);
                    break;
                }
                $available += (int) $banks[$categoryid]['count'];
            }
            if (!isset($errors['categoryids']) && $available < $count) {
                $errors['questioncount'] = get_string('quiz_wizard_err_notenough',
                    'local_ai_yared_course', (object) ['requested' => $count, 'available' => $available]);
            }
        }

        $section = (int) ($data['section'] ?? -1);
        if (!array_key_exists($section, $sections)) {
            $errors['section'] = get_string('quiz_wizard_err_section', 'local_ai_yared_course');
        }

        return $errors;
    }
}
