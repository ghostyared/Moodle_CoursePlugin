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

/**
 * Quiz wizard: create a standard Moodle quiz from one or more question banks.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_ai_yared_course\form\quiz_form;
use local_ai_yared_course\local\quiz_builder;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);
require_capability('moodle/course:manageactivities', $context);
require_capability('mod/quiz:addinstance', $context);

$PAGE->set_url('/local/ai_yared_course/quiz.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('quiz_wizard_title', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/ai_yared_course/styles.css'));

// Sections of the course, including subsections of FlexTiles/Flexsections.
$sections = quiz_builder::get_section_options($course);

// Default section: the first one after General, unless the URL asks for another.
$defaultsection = optional_param('section', -1, PARAM_INT);
if (!array_key_exists($defaultsection, $sections)) {
    $defaultsection = 0;
    foreach (array_keys($sections) as $number) {
        if ($number > 0) {
            $defaultsection = (int) $number;
            break;
        }
    }
}

$banks = quiz_builder::get_banks($context);

$form = new quiz_form($PAGE->url->out(false), [
    'courseid' => $course->id,
    'coursename' => format_string($course->fullname),
    'banks' => $banks,
    'sections' => $sections,
    'defaultsection' => $defaultsection,
]);

// Coming from "Create quiz with this bank": preselect that category.
$preselected = optional_param('categoryid', 0, PARAM_INT);
if ($preselected && isset($banks[$preselected]) && !$form->is_submitted()) {
    $form->set_data(['categoryids' => [$preselected]]);
}

if ($data = $form->get_data()) {
    try {
        $builder = new quiz_builder($course);
        $result = $builder->create((array) $data);
        \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::QUIZ, (int) $course->id,
            (string) $data->name, 0, ['banks' => count((array) ($data->categoryids ?? []))]);

        $message = get_string('quiz_wizard_created', 'local_ai_yared_course', (object) [
            'name' => s($data->name),
            'section' => s($result['sectionname']),
        ]);
        $editurl = new moodle_url('/mod/quiz/edit.php', ['cmid' => $result['cmid']]);
        $message .= ' ' . html_writer::link($editurl, get_string('editquiz', 'quiz'));

        redirect(new moodle_url('/mod/quiz/view.php', ['id' => $result['cmid']]), $message, null,
            \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::QUIZ, (int) $course->id,
            (string) ($data->name ?? ''), 0, [], 'error', $e->getMessage());
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('quiz_wizard_title', 'local_ai_yared_course'));
        echo $OUTPUT->notification(s($e->getMessage()), 'error');
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('quiz_wizard_title', 'local_ai_yared_course'));
echo html_writer::tag('p', get_string('quiz_wizard_pageintro', 'local_ai_yared_course'),
    ['class' => 'text-muted']);

if (!$banks) {
    echo $OUTPUT->notification(get_string('quiz_wizard_nobanks', 'local_ai_yared_course'), 'warning');
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id]),
            get_string('bank_menu', 'local_ai_yared_course'), 'get', ['type' => 'primary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

$form->display();
echo $OUTPUT->footer();
