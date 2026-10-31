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

namespace local_ai_yared_course\local;

use core_question\local\bank\condition;
use core_question\local\bank\question_version_status;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Creates a standard Moodle quiz from one or more question bank categories.
 *
 * The wizard is a shortcut, not a new activity type: the result is a regular
 * mod_quiz activity that the teacher can edit with the standard quiz editor.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_builder {

    /** @var \stdClass Course record. */
    protected $course;

    /** @var \context_course Course context. */
    protected $context;

    /**
     * Constructor.
     *
     * @param \stdClass $course Course record.
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->context = \context_course::instance($course->id);
    }

    /**
     * Question bank categories of the course, with their direct question count.
     *
     * @param \context $context Course context.
     * @return array[] Category id => id, name, depth and count.
     */
    public static function get_banks(\context $context): array {
        global $DB;

        $top = question_get_top_category($context->id, true);
        $categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'sortorder, name');

        $children = [];
        foreach ($categories as $category) {
            if ((int) $category->id === (int) $top->id) {
                continue;
            }
            $children[(int) $category->parent][] = $category;
        }

        $banks = [];
        $walk = function (int $parentid, int $depth) use (&$walk, &$children, &$banks, $context) {
            foreach ($children[$parentid] ?? [] as $category) {
                $id = (int) $category->id;
                $banks[$id] = [
                    'id' => $id,
                    'name' => format_string($category->name, true, ['context' => $context]),
                    'depth' => $depth,
                    'count' => self::count_questions($id),
                ];
                $walk($id, $depth + 1);
            }
        };
        $walk((int) $top->id, 0);

        return $banks;
    }

    /**
     * Section options for the quiz form, including FlexTiles/Flexsections subsections.
     *
     * Subsections are indented and show their parent section, so a flat select
     * still makes the course hierarchy clear.
     *
     * @param \stdClass $course Course record.
     * @return array Section number => label.
     */
    public static function get_section_options(\stdClass $course): array {
        return section_options::for_select($course);
    }

    /**
     * Count the ready questions that a random question can use in a category.
     *
     * This mirrors core's random_question_loader: latest ready version, top-level
     * question (not a subquestion) and no random questions.
     *
     * @param int $categoryid Question category id.
     * @return int
     */
    public static function count_questions(int $categoryid): int {
        global $DB;

        $sql = "SELECT COUNT(q.id)
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :categoryid
                   AND q.parent = 0
                   AND q.qtype <> :random
                   AND qv.version = (
                           SELECT MAX(version)
                             FROM {question_versions}
                            WHERE questionbankentryid = qbe.id
                              AND status = :ready
                       )";

        return (int) $DB->count_records_sql($sql, [
            'categoryid' => $categoryid,
            'random' => 'random',
            'ready' => question_version_status::QUESTION_STATUS_READY,
        ]);
    }

    /**
     * Distribute a total number of random questions among the selected banks.
     *
     * Every selected bank receives one slot first (when the total allows it), then
     * the remaining slots are assigned in rounds. A bank never receives more slots
     * than the number of questions it has.
     *
     * @param array $available Category id => available questions.
     * @param int $total Total questions requested.
     * @return array Category id => number of random slots.
     */
    public static function allocate(array $available, int $total): array {
        $ids = array_keys($available);
        $allocation = array_fill_keys($ids, 0);
        $remaining = $total;

        // Random order so the extra slots do not always go to the first bank.
        shuffle($ids);
        foreach ($ids as $id) {
            if ($remaining <= 0) {
                break;
            }
            if ($available[$id] > 0) {
                $allocation[$id]++;
                $remaining--;
            }
        }

        while ($remaining > 0) {
            $progress = false;
            foreach ($ids as $id) {
                if ($remaining <= 0) {
                    break;
                }
                if ($allocation[$id] >= $available[$id]) {
                    continue;
                }
                $allocation[$id]++;
                $remaining--;
                $progress = true;
            }
            if (!$progress) {
                break;
            }
        }

        if ($remaining > 0) {
            throw new \moodle_exception('quiz_wizard_err_notenough', 'local_ai_yared_course', '',
                (object) ['requested' => $total, 'available' => array_sum($available)]);
        }

        return $allocation;
    }

    /**
     * Create the quiz and its random questions.
     *
     * @param array $data Form data: name, intro, introformat, categoryids, questioncount, section, visible.
     * @return array quizid, cmid, sectionnum, sectionname and allocation.
     */
    public function create(array $data): array {
        global $DB;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \moodle_exception('quiz_wizard_err_name', 'local_ai_yared_course');
        }
        if (\core_text::strlen($name) > 255) {
            throw new \moodle_exception('quiz_wizard_err_namelong', 'local_ai_yared_course');
        }
        if (!has_capability('moodle/question:useall', $this->context)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'moodle/question:useall');
        }

        $banks = self::get_banks($this->context);
        $selected = $data['categoryids'] ?? [];
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        $selected = array_values(array_unique(array_map('intval', $selected)));
        if (!$selected) {
            throw new \moodle_exception('quiz_wizard_err_banks', 'local_ai_yared_course');
        }

        $available = [];
        foreach ($selected as $categoryid) {
            if (!isset($banks[$categoryid])) {
                throw new \moodle_exception('quiz_wizard_err_bankunknown', 'local_ai_yared_course');
            }
            if ((int) $banks[$categoryid]['count'] < 1) {
                throw new \moodle_exception('quiz_wizard_err_bankempty', 'local_ai_yared_course',
                    '', $banks[$categoryid]['name']);
            }
            $available[$categoryid] = (int) $banks[$categoryid]['count'];
        }

        $total = (int) ($data['questioncount'] ?? 0);
        if ($total < 1 || $total > 100) {
            throw new \moodle_exception('quiz_wizard_err_count', 'local_ai_yared_course');
        }
        $availabletotal = array_sum($available);
        if ($availabletotal < $total) {
            throw new \moodle_exception('quiz_wizard_err_notenough', 'local_ai_yared_course', '',
                (object) ['requested' => $total, 'available' => $availabletotal]);
        }

        $allocation = self::allocate($available, $total);

        $sectionnum = (int) ($data['section'] ?? 0);
        $section = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $sectionnum], '*', MUST_EXIST);

        // The editor element returns an array with text, format and itemid.
        $intro = $data['intro'] ?? '';
        if (is_array($intro)) {
            $introtext = (string) ($intro['text'] ?? '');
            $introformat = (int) ($intro['format'] ?? FORMAT_HTML);
        } else {
            $introtext = (string) $intro;
            $introformat = (int) ($data['introformat'] ?? FORMAT_HTML);
        }

        $fields = self::quiz_fields($data);
        $info = (object) ($fields + [
            'modulename' => 'quiz',
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST),
            'course' => $this->course->id,
            'section' => $sectionnum,
            'name' => \core_text::substr(trim((string) ($data['name'] ?? '')), 0, 255),
            'intro' => $introtext,
            'introformat' => $introformat,
            'visible' => !empty($data['visible']) ? 1 : 0,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'conditiongradegroup' => [],
            'conditionfieldgroup' => [],
            'conditioncompletiongroup' => [],
        ]);

        $created = add_moduleinfo($info, $this->course);
        $quiz = $DB->get_record('quiz', ['id' => $created->instance], '*', MUST_EXIST);

        try {
            $settings = \mod_quiz\quiz_settings::create($quiz->id);
            $structure = $settings->get_structure();
            foreach ($allocation as $categoryid => $number) {
                if ($number < 1) {
                    continue;
                }
                $filter = [
                    'filter' => [
                        'category' => [
                            'jointype' => condition::JOINTYPE_DEFAULT,
                            'values' => [(int) $categoryid],
                            'filteroptions' => ['includesubcategories' => false],
                        ],
                    ],
                ];
                $structure->add_random_questions(1, $number, $filter);
            }

            // Give every random slot the same mark so the quiz adds up to its grade.
            $mark = round((float) $fields['grade'] / max(1, $total), 5);
            $DB->set_field('quiz_slots', 'maxmark', $mark, ['quizid' => $quiz->id]);
            $settings->get_grade_calculator()->recompute_quiz_sumgrades();
        } catch (\Throwable $e) {
            // Do not leave a half-built quiz in the course.
            try {
                course_delete_module($created->coursemodule);
            } catch (\Throwable $cleanuperror) {
                debugging('local_ai_yared_course: could not remove the incomplete quiz: '
                    . $cleanuperror->getMessage(), DEBUG_DEVELOPER);
            }
            throw $e;
        }

        $sectionname = trim(clean_text((string) $section->name));
        if ($sectionnum === 0) {
            $sectionname = get_string('general', 'moodle');
        } else if ($sectionname === '') {
            $sectionname = get_string('section') . ' ' . $sectionnum;
        }

        return [
            'quizid' => (int) $quiz->id,
            'cmid' => (int) $created->coursemodule,
            'sectionnum' => $sectionnum,
            'sectionname' => $sectionname,
            'allocation' => $allocation,
        ];
    }

    /**
     * Default quiz settings used by the wizard.
     *
     * @param array $data Form data.
     * @return array Quiz fields for add_moduleinfo().
     */
    protected static function quiz_fields(array $data): array {
        $fields = [
            'timeopen' => 0,
            'timeclose' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'attempts' => 2,
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 0,
            'shuffleanswers' => 1,
            'sumgrades' => 0,
            'grade' => 20,
            'timelimit' => 30 * MINSECS,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 86400,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'showdescription' => 1,
        ];

        // Review options: same defaults as the rest of the plugin.
        foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
                'rightanswer', 'overallfeedback'] as $option) {
            $fields[$option . 'during'] = ($option === 'overallfeedback') ? 0 : 1;
            $fields[$option . 'immediately'] = 1;
            $fields[$option . 'open'] = 1;
            $fields[$option . 'closed'] = 1;
        }

        return $fields;
    }
}
