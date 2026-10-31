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

use local_ai_yared_course\local\image_helper;
use local_ai_yared_course\local\source_reader;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Upload form for the PDF/image question importer.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {

    /** Languages for the alternatives and the feedback, with the name sent to the AI. */
    public const LANGUAGE_NAMES = [
        'es' => 'Latin American Spanish',
        'en' => 'English',
    ];

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        $categories = $this->_customdata['categories'];
        $cancreatecategory = $this->_customdata['cancreatecategory'];

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'extract');
        $mform->setType('action', PARAM_ALPHA);

        // Source files.
        $mform->addElement('header', 'sourceheader', get_string('import_sourceheader', 'local_ai_yared_course'));
        $mform->setExpanded('sourceheader', true);
        $mform->addElement('static', 'sourcedesc', '',
            get_string('import_sourcedesc', 'local_ai_yared_course'));
        $mform->addElement('filemanager', 'sources', get_string('import_sources', 'local_ai_yared_course'),
            null, [
                'maxbytes' => source_reader::MAX_PDF_BYTES,
                'accepted_types' => ['.pdf', '.png', '.jpg', '.jpeg', '.gif', '.webp'],
                'maxfiles' => source_reader::MAX_FILES,
                'subdirs' => 0,
            ]);
        $mform->setType('sources', PARAM_INT);
        $mform->addHelpButton('sources', 'import_sources', 'local_ai_yared_course');

        // Content.
        $mform->addElement('header', 'importcontentheader', get_string('import_contentheader', 'local_ai_yared_course'));
        $mform->setExpanded('importcontentheader', true);
        $mform->addElement('textarea', 'instructions', get_string('instructions', 'local_ai_yared_course'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('instructions', PARAM_RAW);
        $mform->addHelpButton('instructions', 'instructions', 'local_ai_yared_course');

        $languages = [
            'es' => get_string('import_lang_es', 'local_ai_yared_course'),
            'en' => get_string('import_lang_en', 'local_ai_yared_course'),
        ];
        $mform->addElement('select', 'language', get_string('import_language', 'local_ai_yared_course'), $languages);
        $mform->setDefault('language', 'es');
        $mform->addHelpButton('language', 'import_language', 'local_ai_yared_course');

        $levels = [
            'initial' => get_string('level_initial', 'local_ai_yared_course'),
            'primary' => get_string('level_primary', 'local_ai_yared_course'),
            'secondary' => get_string('level_secondary', 'local_ai_yared_course'),
            'university' => get_string('level_university', 'local_ai_yared_course'),
            'custom' => get_string('level_custom', 'local_ai_yared_course'),
        ];
        $mform->addElement('select', 'level', get_string('level', 'local_ai_yared_course'), $levels);
        $mform->setDefault('level', 'primary');

        $mform->addElement('text', 'age', get_string('bank_age', 'local_ai_yared_course'), ['size' => 4]);
        $mform->setType('age', PARAM_INT);
        $mform->setDefault('age', 12);
        $mform->hideIf('age', 'level', 'neq', 'custom');

        $mform->addElement('advcheckbox', 'attachimage', get_string('import_attachimage', 'local_ai_yared_course'),
            get_string('import_attachimage_desc', 'local_ai_yared_course'));
        $mform->setDefault('attachimage', 1);
        $mform->addHelpButton('attachimage', 'import_attachimage', 'local_ai_yared_course');

        // Destination.
        $mform->addElement('header', 'destinationheader', get_string('destinationheader', 'local_ai_yared_course'));
        $mform->setExpanded('destinationheader', true);

        $destinations = $categories;
        if ($cancreatecategory) {
            $destinations = [0 => get_string('category_new', 'local_ai_yared_course')] + $categories;
        }
        $mform->addElement('select', 'categoryid', get_string('category', 'local_ai_yared_course'), $destinations);
        $mform->addHelpButton('categoryid', 'category', 'local_ai_yared_course');
        if ($cancreatecategory) {
            $mform->addElement('text', 'newcategory', get_string('newcategory', 'local_ai_yared_course'), ['size' => 40]);
            $mform->setType('newcategory', PARAM_TEXT);
            $mform->hideIf('newcategory', 'categoryid', 'neq', 0);
            if (!$categories) {
                $mform->setDefault('categoryid', 0);
            }
        }

        $this->add_action_buttons(false, get_string('import_analyse', 'local_ai_yared_course'));
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

        $itemid = (int) ($data['sources'] ?? 0);
        if ($itemid <= 0 || !image_helper::draft_has_file($itemid)) {
            $errors['sources'] = get_string('import_err_nofile', 'local_ai_yared_course');
        }
        if (\core_text::strlen(trim($data['instructions'] ?? '')) > 2000) {
            $errors['instructions'] = get_string('err_instructionslong', 'local_ai_yared_course', 2000);
        }
        if (($data['level'] ?? '') === 'custom') {
            $age = (int) ($data['age'] ?? 0);
            if ($age < 2 || $age > 99) {
                $errors['age'] = get_string('bank_err_age', 'local_ai_yared_course');
            }
        }
        if (isset($data['categoryid']) && (int) $data['categoryid'] === 0 && trim($data['newcategory'] ?? '') === '') {
            $errors['newcategory'] = get_string('err_newcategory', 'local_ai_yared_course');
        }
        return $errors;
    }
}
