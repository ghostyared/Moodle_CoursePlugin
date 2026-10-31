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
 * Question generation form shown to teachers.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_form extends \moodleform {

    /** Maximum number of teacher questions accepted per run. */
    public const MAX_STEMS = 50;

    /** @var array Age used for each preset level. */
    public const LEVEL_AGES = ['initial' => 3, 'primary' => 12, 'secondary' => 15, 'university' => 20];

    /** @var array Languages offered for the questions: key => name sent to the AI. */
    public const LANGUAGES = [
        'es' => 'Spanish',
        'en' => 'English',
        'pt' => 'Portuguese',
        'fr' => 'French',
    ];

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        $categories = $this->_customdata['categories'];
        $cancreatecategory = $this->_customdata['cancreatecategory'];
        $mode = $this->_customdata['mode'] ?? 'classic';
        $mathmode = $mode === 'math';

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'mode', $mode);
        $mform->setType('mode', PARAM_ALPHA);

        // Content.
        $mform->addElement('header', 'contentheader', get_string('bank_contentheader', 'local_ai_yared_course'));

        if ($mathmode) {
            // Course of mathematics: it sets the style of the exercises and offers topics to pick from.
            $courseoptions = ['' => get_string('mathcourse_choose', 'local_ai_yared_course')]
                + \local_ai_yared_course\local\math_catalog::options();
            $mform->addElement('select', 'mathcourse', get_string('mathcourse', 'local_ai_yared_course'), $courseoptions);
            $mform->setType('mathcourse', PARAM_ALPHANUMEXT);
            $mform->addHelpButton('mathcourse', 'mathcourse', 'local_ai_yared_course');
        }

        // These fields are only sent to the AI prompt, so keep "<" and ">" for math formulas.
        $topicslabel = $mathmode ? 'topics_math' : 'topics';
        $stemlabel = $mathmode ? 'stems_math' : 'stems';
        $topicsoptions = [
            'rows' => 4,
            'cols' => 60,
            'placeholder' => get_string($mathmode ? 'topics_math_placeholder' : 'topics_placeholder',
                'local_ai_yared_course'),
        ];
        $mform->addElement('textarea', 'topics', get_string($topicslabel, 'local_ai_yared_course'),
            $topicsoptions);
        $mform->setType('topics', PARAM_RAW);
        $mform->addHelpButton('topics', 'topics', 'local_ai_yared_course');
        if ($mathmode) {
            $mform->addElement('static', 'topicchips', '',
                '<div id="aiyared-topicchips" class="aiyared-topicchips" aria-live="polite"></div>');
            self::init_topic_chips();
        }

        $stemoptions = [
            'rows' => 6,
            'cols' => 60,
            'placeholder' => get_string($mathmode ? 'stems_math_placeholder' : 'stems_placeholder',
                'local_ai_yared_course'),
        ];
        $mform->addElement('textarea', 'stems', get_string($stemlabel, 'local_ai_yared_course'), $stemoptions);
        $mform->setType('stems', PARAM_RAW);
        $mform->addHelpButton('stems', 'stems', 'local_ai_yared_course');

        if ($mathmode) {
            $mform->addElement('textarea', 'instructions', get_string('instructions', 'local_ai_yared_course'),
                ['rows' => 4, 'cols' => 60]);
            $mform->setType('instructions', PARAM_RAW);
            $mform->addHelpButton('instructions', 'instructions', 'local_ai_yared_course');
        }

        // Audience.
        $mform->addElement('header', 'audienceheader', get_string('audienceheader', 'local_ai_yared_course'));

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

        $counts = [5 => 5, 10 => 10, 20 => 20, 30 => 30, 40 => 40, 50 => 50];
        $mform->addElement('select', 'count', get_string('count', 'local_ai_yared_course'), $counts);
        $mform->setDefault('count', 10);
        $mform->addHelpButton('count', 'count', 'local_ai_yared_course');

        if ($mathmode) {
            // Mathematics keeps the formats that can carry a figure.
            $types = [
                'multichoice' => get_string('qtype_multichoice', 'local_ai_yared_course'),
                'truefalse' => get_string('qtype_truefalse', 'local_ai_yared_course'),
                'mixed' => get_string('qtype_mixed', 'local_ai_yared_course'),
            ];
        } else {
            $types = [
                get_string('import_fmt_group_closed', 'local_ai_yared_course') => [
                    'multichoice' => get_string('qtype_multichoice', 'local_ai_yared_course'),
                    'multiselect' => get_string('qtype_multiselect', 'local_ai_yared_course'),
                    'truefalse' => get_string('qtype_truefalse', 'local_ai_yared_course'),
                    'gapselect' => get_string('qtype_gapselect', 'local_ai_yared_course'),
                    'ddwtos' => get_string('qtype_ddwtos', 'local_ai_yared_course'),
                    'mixedall' => get_string('qtype_mixedall', 'local_ai_yared_course'),
                ],
                get_string('import_fmt_group_open', 'local_ai_yared_course') => [
                    'shortanswer' => get_string('qtype_shortanswer', 'local_ai_yared_course'),
                    'essay' => get_string('qtype_essay', 'local_ai_yared_course'),
                ],
            ];
        }
        $mform->addElement('selectgroups', 'qtype', get_string('qtype', 'local_ai_yared_course'), $types);
        $mform->setDefault('qtype', 'multichoice');
        $mform->addHelpButton('qtype', 'qtype', 'local_ai_yared_course');

        $languages = [];
        foreach (self::LANGUAGES as $code => $name) {
            $languages[$code] = get_string('lang_' . $code, 'local_ai_yared_course');
        }
        $mform->addElement('select', 'language', get_string('bank_language', 'local_ai_yared_course'), $languages);
        $current = substr(current_language(), 0, 2);
        $mform->setDefault('language', isset($languages[$current]) ? $current : 'es');

        if ($mathmode) {
            $mform->addElement('hidden', 'contentformat', 'math');
            $mform->setType('contentformat', PARAM_ALPHA);
            $mform->addElement('static', 'contentformatstatic', get_string('contentformat', 'local_ai_yared_course'),
                get_string('contentformat_math', 'local_ai_yared_course'));
        } else {
            $mform->addElement('hidden', 'contentformat', 'plain');
            $mform->setType('contentformat', PARAM_ALPHA);
            $mform->addElement('static', 'contentformatstatic', get_string('contentformat', 'local_ai_yared_course'),
                get_string('contentformat_plain', 'local_ai_yared_course'));
        }

        if ($mathmode) {
            $mform->addElement('advcheckbox', 'allowimages', get_string('allowimages', 'local_ai_yared_course'),
                get_string('allowimages_desc', 'local_ai_yared_course'));
            $mform->setDefault('allowimages', 1);
            $mform->addHelpButton('allowimages', 'allowimages', 'local_ai_yared_course');

            $mform->addElement('textarea', 'imageurls', get_string('imageurls', 'local_ai_yared_course'),
                ['rows' => 3, 'cols' => 60]);
            $mform->setType('imageurls', PARAM_RAW);
            $mform->addHelpButton('imageurls', 'imageurls', 'local_ai_yared_course');
            $mform->hideIf('imageurls', 'allowimages', 'eq', 0);
            $mform->setAdvanced('imageurls');
        }

        // Destination.
        $mform->addElement('header', 'destinationheader', get_string('destinationheader', 'local_ai_yared_course'));

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

        $mform->addElement('advcheckbox', 'review', get_string('bank_review', 'local_ai_yared_course'),
            get_string('bank_review_desc', 'local_ai_yared_course'));
        $mform->setDefault('review', 1);
        $mform->addHelpButton('review', 'bank_review', 'local_ai_yared_course');

        $mform->addElement('advcheckbox', 'draft', get_string('draft', 'local_ai_yared_course'),
            get_string('draft_desc', 'local_ai_yared_course'));
        $mform->hideIf('draft', 'review', 'eq', 1);

        $this->add_action_buttons(false, get_string('bank_generate', 'local_ai_yared_course'));
    }

    /**
     * Script that shows the suggested topics of the chosen course as buttons that add them to the topics box.
     */
    protected static function init_topic_chips(): void {
        global $PAGE;

        $map = json_encode(\local_ai_yared_course\local\math_catalog::topics_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $label = json_encode(get_string('mathcourse_topics', 'local_ai_yared_course'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $js = <<<'JS'
(function() {
    var topics = __MAP__;
    var label = __LABEL__;
    var select = document.getElementById('id_mathcourse');
    var box = document.getElementById('id_topics');
    var holder = document.getElementById('aiyared-topicchips');
    if (!select || !box || !holder) { return; }
    function parts() {
        return box.value.split(/[\r\n,;]+/).map(function(t) { return t.trim().toLowerCase(); });
    }
    function render() {
        holder.textContent = '';
        var list = topics[select.value] || [];
        if (!list.length) { return; }
        var title = document.createElement('div');
        title.className = 'small text-muted mb-1';
        title.textContent = label;
        holder.appendChild(title);
        var used = parts();
        list.forEach(function(topic) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm me-1 mb-1 ' + (used.indexOf(topic.toLowerCase()) >= 0 ? 'btn-primary' : 'btn-outline-secondary');
            b.textContent = topic;
            b.addEventListener('click', function() {
                var current = parts();
                var at = current.indexOf(topic.toLowerCase());
                if (at >= 0) {
                    var kept = box.value.split(/[\r\n,;]+/).map(function(t) { return t.trim(); }).filter(function(t) {
                        return t !== '' && t.toLowerCase() !== topic.toLowerCase();
                    });
                    box.value = kept.join('\n');
                } else {
                    box.value = (box.value.trim() === '' ? '' : box.value.replace(/\s+$/, '') + '\n') + topic;
                }
                render();
            });
            holder.appendChild(b);
        });
    }
    select.addEventListener('change', render);
    box.addEventListener('input', render);
    render();
})();
JS;
        $PAGE->requires->js_init_code(str_replace(['__MAP__', '__LABEL__'], [$map, $label], $js));
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

        $topics = self::split_topics($data['topics'] ?? '');
        $stems = self::split_stems($data['stems'] ?? '');
        $count = (int) ($data['count'] ?? 10);

        if (!$topics && !$stems) {
            $errors['topics'] = get_string('err_nocontent', 'local_ai_yared_course');
        } else if (!$topics && $count > count($stems)) {
            $errors['topics'] = get_string('err_topicsneeded', 'local_ai_yared_course');
        }
        if (count($stems) > self::MAX_STEMS) {
            $errors['stems'] = get_string('err_toomanystems', 'local_ai_yared_course', self::MAX_STEMS);
        }
        if (($data['level'] ?? '') === 'custom') {
            $age = (int) ($data['age'] ?? 0);
            if ($age < 2 || $age > 99) {
                $errors['age'] = get_string('bank_err_age', 'local_ai_yared_course');
            }
        }
        if (\core_text::strlen(trim($data['instructions'] ?? '')) > 2000) {
            $errors['instructions'] = get_string('err_instructionslong', 'local_ai_yared_course', 2000);
        }
        if (isset($data['categoryid']) && (int) $data['categoryid'] === 0 && trim($data['newcategory'] ?? '') === '') {
            $errors['newcategory'] = get_string('err_newcategory', 'local_ai_yared_course');
        }
        return $errors;
    }

    /**
     * Split the topics text (one per line or comma separated).
     *
     * @param string $text
     * @return string[]
     */
    public static function split_topics(string $text): array {
        $parts = preg_split('/[\r\n,;]+/', $text);
        $parts = array_map('trim', $parts);
        return array_values(array_unique(array_filter($parts, 'strlen')));
    }

    /**
     * Split the teacher questions (one per line or separated by ";"), removing list numbering.
     *
     * @param string $text
     * @return string[]
     */
    public static function split_stems(string $text): array {
        $lines = preg_split('/[\r\n;]+/', $text);
        $stems = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*(?:\d+\s*[\.\)\-]|[-*•])\s*/u', '', $line));
            if ($line !== '') {
                $stems[] = $line;
            }
        }
        return $stems;
    }

    /**
     * Split the optional image URLs (one per line).
     *
     * @param string $text
     * @return string[]
     */
    public static function split_image_urls(string $text): array {
        $lines = preg_split('/[\r\n]+/', $text);
        $urls = array_map('trim', $lines);
        return array_values(array_filter($urls, 'strlen'));
    }

    /**
     * Age for the selected level.
     *
     * @param string $level initial|primary|secondary|university|custom
     * @param int $customage Age typed by the teacher.
     * @return int
     */
    public static function resolve_age(string $level, int $customage): int {
        if (isset(self::LEVEL_AGES[$level])) {
            // The age of each level is configurable by the administrator.
            $age = (int) get_config('local_ai_yared_course', 'age_' . $level);
            return $age >= 2 ? min(99, $age) : self::LEVEL_AGES[$level];
        }
        return max(2, min(99, $customage));
    }
}
