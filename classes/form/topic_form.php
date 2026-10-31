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

use local_ai_yared_course\local\course_builder;
use local_ai_yared_course\local\levels;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form where the teacher describes the topic to create.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $course = $this->_customdata['course'];

        $mform->addElement('hidden', 'id', $course->id);
        $mform->setType('id', PARAM_INT);

        // Content.
        $mform->addElement('header', 'contentheader', get_string('contentheader', 'local_ai_yared_course'));

        $mform->addElement('text', 'title', get_string('topictitle', 'local_ai_yared_course'), ['size' => 60, 'maxlength' => 200]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 200), 'maxlength', 200, 'client');

        $mform->addElement('textarea', 'description', get_string('topicdescription', 'local_ai_yared_course'),
            ['rows' => 7, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addRule('description', null, 'required', null, 'client');
        $mform->addHelpButton('description', 'topicdescription', 'local_ai_yared_course');

        $ages = [];
        for ($age = levels::MIN_AGE; $age <= levels::MAX_AGE; $age++) {
            $ages[$age] = get_string('ageoption', 'local_ai_yared_course', (object) [
                'age' => $age,
                'level' => get_string('level_' . levels::key($age), 'local_ai_yared_course'),
            ]);
        }
        $mform->addElement('select', 'age', get_string('age', 'local_ai_yared_course'), $ages);
        $mform->setDefault('age', 12);
        $mform->addHelpButton('age', 'age', 'local_ai_yared_course');

        $languages = [];
        foreach (\local_ai_yared_course\form\generate_form::LANGUAGES as $code => $name) {
            $languages[$code] = get_string('lang_' . $code, 'local_ai_yared_course');
        }
        $mform->addElement('select', 'language', get_string('language', 'local_ai_yared_course'), $languages);
        $current = substr(current_language(), 0, 2);
        $mform->setDefault('language', isset($languages[$current]) ? $current : 'es');

        // Options.
        $mform->addElement('header', 'optionsheader', get_string('optionsheader', 'local_ai_yared_course'));

        $sections = [course_builder::NEWSECTION => get_string('newsection', 'local_ai_yared_course')];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $number => $info) {
            if ($info->is_delegated()) {
                continue;
            }
            $sections[$number] = get_section_name($course, $info);
        }
        $mform->addElement('select', 'sectionnum', get_string('targetsection', 'local_ai_yared_course'), $sections);
        $mform->setDefault('sectionnum', course_builder::NEWSECTION);

        $mform->addElement('advcheckbox', 'findvideo', get_string('findvideo', 'local_ai_yared_course'),
            get_string('findvideo_desc', 'local_ai_yared_course'));
        $mform->setDefault('findvideo', 1);

        $counts = [0 => get_string('questioncount_none', 'local_ai_yared_course'), 10 => 10, 20 => 20, 30 => 30];
        $mform->addElement('select', 'questioncount', get_string('questioncount', 'local_ai_yared_course'), $counts);
        $mform->setDefault('questioncount', 10);
        $mform->addHelpButton('questioncount', 'questioncount', 'local_ai_yared_course');

        // Graded practice quiz.
        $mform->addElement('header', 'quizheader', get_string('quizheader', 'local_ai_yared_course'));

        $mform->addElement('date_time_selector', 'quizopen', get_string('quizopen', 'local_ai_yared_course'), ['optional' => false]);
        $mform->setDefault('quizopen', strtotime('today'));

        $mform->addElement('text', 'quizclosedays', get_string('quizclosedays', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('quizclosedays', PARAM_INT);
        $mform->setDefault('quizclosedays', 7);

        $mform->addElement('text', 'quiztimelimit', get_string('quiztimelimit', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('quiztimelimit', PARAM_INT);
        $mform->setDefault('quiztimelimit', 60);

        $mform->addElement('text', 'quizattempts', get_string('quizattempts', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('quizattempts', PARAM_INT);
        $mform->setDefault('quizattempts', 2);

        $mform->addElement('text', 'quizgrade', get_string('quizgrade', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('quizgrade', PARAM_INT);
        $mform->setDefault('quizgrade', 20);

        $mform->addElement('text', 'quizquestions', get_string('quizquestions', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('quizquestions', PARAM_INT);
        $mform->setDefault('quizquestions', 10);
        $mform->addHelpButton('quizquestions', 'quizquestions', 'local_ai_yared_course');

        $mform->addElement('advcheckbox', 'quizvisible', get_string('quizvisible', 'local_ai_yared_course'),
            get_string('quizvisible_desc', 'local_ai_yared_course'));
        $mform->setDefault('quizvisible', 0);

        $mform->addElement('select', 'extensiontype', get_string('extensiontype', 'local_ai_yared_course'), [
            'assign' => get_string('extension_assign', 'local_ai_yared_course'),
            'quiz' => get_string('extension_quiz', 'local_ai_yared_course'),
        ]);
        $mform->setDefault('extensiontype', 'assign');

        $mform->addElement('advcheckbox', 'hidesection', get_string('hidesection', 'local_ai_yared_course'),
            get_string('hidesection_desc', 'local_ai_yared_course'));
        $mform->setDefault('hidesection', 1);
        $mform->hideIf('hidesection', 'sectionnum', 'neq', course_builder::NEWSECTION);

        $this->add_action_buttons(true, get_string('createtopic', 'local_ai_yared_course'));
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

        if (trim($data['title'] ?? '') === '') {
            $errors['title'] = get_string('required');
        }
        if (\core_text::strlen(trim($data['description'] ?? '')) < 10) {
            $errors['description'] = get_string('err_description', 'local_ai_yared_course');
        }
        $age = (int) ($data['age'] ?? 0);
        if ($age < levels::MIN_AGE || $age > levels::MAX_AGE) {
            $errors['age'] = get_string('err_age', 'local_ai_yared_course', (object) ['min' => levels::MIN_AGE, 'max' => levels::MAX_AGE]);
        }

        // Graded practice quiz settings.
        $ranges = ['quizclosedays' => [0, 3650], 'quiztimelimit' => [0, 1440], 'quizattempts' => [0, 100],
            'quizgrade' => [1, 1000], 'quizquestions' => [0, 1000]];
        foreach ($ranges as $field => [$min, $max]) {
            $value = (int) ($data[$field] ?? $min);
            if ($value < $min || $value > $max) {
                $errors[$field] = get_string('err_quizrange', 'local_ai_yared_course', (object) ['min' => $min, 'max' => $max]);
            }
        }
        return $errors;
    }
}
