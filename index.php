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
 * AI topic builder: asks for the section, the topics and what to create, then builds every topic.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_ai_yared_course\form\generate_form;
use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\course_builder;
use local_ai_yared_course\local\job_store;
use local_ai_yared_course\task\run_job;
use local_ai_yared_course\local\levels;
use local_ai_yared_course\local\section_options;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url('/local/ai_yared_course/index.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
// Ask nginx not to buffer the response: the progress is flushed while the AI works.
@header('X-Accel-Buffering: no');

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$client = ai_factory::create();

// Sections of the course, to choose an existing one. Subsections are indented below their
// parent with "↳" and show the parent name, so the hierarchy is visible in the flat select.
$modinfo = get_fast_modinfo($course);
$sectionoptions = section_options::for_select($course, true);
// Default section: the first empty one after "General", otherwise the first one after "General".
$defaultsection = array_key_exists(0, $sectionoptions) ? 0 : (int) array_key_first($sectionoptions);
foreach (array_keys($sectionoptions) as $number) {
    if ($number > 0) {
        $defaultsection = (int) $number;
        break;
    }
}
foreach (array_keys($sectionoptions) as $number) {
    if ($number > 0 && empty($modinfo->sections[$number])) {
        $defaultsection = (int) $number;
        break;
    }
}

// Default language of the generated content: the current language when the AI supports it.
$candidate = substr(current_language(), 0, 2);
$defaultlanguage = isset(generate_form::LANGUAGES[$candidate]) ? $candidate : 'es';

// Read the form. An unchecked checkbox is not sent, so the defaults only apply on the first load.
$issubmit = (bool) optional_param('generate', 0, PARAM_INT);
$sectionmode = optional_param('sectionmode', 'existing', PARAM_ALPHA);
if ($sectionmode !== 'new') {
    $sectionmode = 'existing';
}
$sectionnum = optional_param('sectionnum', $defaultsection, PARAM_INT);
$topiccount = min(10, max(1, optional_param('topiccount', 1, PARAM_INT)));
$language = optional_param('language', $defaultlanguage, PARAM_ALPHA);
if (!isset(generate_form::LANGUAGES[$language])) {
    $language = $defaultlanguage;
}
$questioncount = min(30, max(1, optional_param('questioncount', 10, PARAM_INT)));
$findvideo = $issubmit ? optional_param('findvideo', 0, PARAM_INT) : 1;
$createbank = $issubmit ? optional_param('createbank', 0, PARAM_INT) : 1;
$createquiz = $issubmit ? optional_param('createquiz', 0, PARAM_INT) : 1;
$createassign = $issubmit ? optional_param('createassign', 0, PARAM_INT) : 1;
$createpdf = $issubmit ? optional_param('createpdf', 0, PARAM_INT) : 1;
$createforum = $issubmit ? optional_param('createforum', 0, PARAM_INT) : 1;
$subsupport = course_builder::subsection_support($course);
$usesubsection = $issubmit ? optional_param('usesubsection', 0, PARAM_INT) : 1;
if ($subsupport === 'flextiles') {
    // Formato-Mariategui: a topic inside an existing section is always a subsection,
    // and it always has a display mode. There is no "no subsection" option.
    $usesubsection = 1;
}
// Accordion (default) or mosaic. The value is mandatory: an unknown value falls back to accordion.
$subsectionmode = optional_param('subsectionmode', 'accordion', PARAM_ALPHA);
if (!in_array($subsectionmode, ['accordion', 'mosaic'], true)) {
    $subsectionmode = 'accordion';
}
$hidesection = $issubmit ? optional_param('hidesection', 0, PARAM_INT) : 1;

// Topic rows: title (or concept), difficulty (age) and class date.
$topics = [];
for ($i = 1; $i <= 10; $i++) {
    $defaultdate = date('Y-m-d', strtotime('today +' . (($i - 1) * 7) . ' days'));
    $rawdate = optional_param('tdate' . $i, $defaultdate, PARAM_TEXT);
    $timestamp = $rawdate !== '' ? strtotime($rawdate . ' 00:00:00') : false;
    if ($timestamp === false) {
        $timestamp = (int) strtotime($defaultdate . ' 00:00:00');
    }
    $title = trim(optional_param('ttitle' . $i, '', PARAM_TEXT));
    $topics[$i] = [
        'title' => \core_text::substr($title, 0, 200),
        'age' => min(levels::MAX_AGE, max(levels::MIN_AGE, optional_param('tage' . $i, 5, PARAM_INT))),
        'classdate' => (int) $timestamp,
    ];
}

// Validate before doing any expensive work.
$errors = [];
if ($issubmit) {
    require_sesskey();

    for ($i = 1; $i <= $topiccount; $i++) {
        if ($topics[$i]['title'] === '') {
            $errors[] = get_string('err_topictitle', 'local_ai_yared_course', $i);
        }
    }
    if ($createquiz && !$createbank) {
        $errors[] = get_string('err_quizneedsbank', 'local_ai_yared_course');
    }
    if ($sectionmode === 'existing' && !array_key_exists($sectionnum, $sectionoptions)) {
        $errors[] = get_string('err_section', 'local_ai_yared_course');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_ai_yared_course'));

if (!$client->is_configured()) {
    if (has_capability('moodle/site:config', context_system::instance())) {
        $url = new moodle_url('/admin/settings.php', ['section' => 'local_ai_yared_course']);
        echo $OUTPUT->notification(get_string('notconfigured_admin', 'local_ai_yared_course', $url->out()), 'warning');
    } else {
        echo $OUTPUT->notification(get_string('notconfigured_teacher', 'local_ai_yared_course'), 'warning');
    }
    echo $OUTPUT->footer();
    exit;
}

// Queue the whole batch when the form is valid: the work runs in the background (Moodle cron), so the
// teacher can close the window, change tabs or come back later from the progress page.
if ($issubmit && !$errors) {
    $active = job_store::get_active_for_user((int) $USER->id);
    if ($active) {
        redirect(new moodle_url('/local/ai_yared_course/job.php', ['id' => $active->id]),
            get_string('job_already_running', 'local_ai_yared_course'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $jobtopics = [];
    for ($i = 1; $i <= $topiccount; $i++) {
        if ($topics[$i]['title'] === '') {
            continue;
        }
        $jobtopics[] = [
            'title' => $topics[$i]['title'],
            'age' => (int) $topics[$i]['age'],
            'classdate' => (int) $topics[$i]['classdate'],
        ];
    }

    $jobid = job_store::create_exclusive((int) $USER->id, (int) $course->id, [
        'topics' => $jobtopics,
        'language' => $language,
        'sectionmode' => $sectionmode,
        'sectionnum' => $sectionnum,
        'questioncount' => $questioncount,
        'findvideo' => !empty($findvideo),
        'createbank' => !empty($createbank),
        'createquiz' => !empty($createquiz),
        'createassign' => !empty($createassign),
        'createpdf' => !empty($createpdf),
        'createforum' => !empty($createforum),
        'usesubsection' => $subsupport !== '' && !empty($usesubsection),
        'subsectionmode' => $subsupport === 'flextiles' ? $subsectionmode : '',
        'hidesection' => !empty($hidesection),
    ], count($jobtopics));
    if (!$jobid) {
        // Another request of this teacher created a job at the same moment (or is still creating it).
        $other = job_store::get_active_for_user((int) $USER->id);
        redirect($other ? new moodle_url('/local/ai_yared_course/job.php', ['id' => $other->id])
            : new moodle_url('/local/ai_yared_course/index.php', ['id' => $course->id]),
            get_string('job_already_running', 'local_ai_yared_course'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    run_job::queue($jobid, (int) $USER->id);

    redirect(new moodle_url('/local/ai_yared_course/job.php', ['id' => $jobid]));
}

// A running job of this teacher, and the latest ones, so the progress can always be reopened.
$activejob = job_store::get_active_for_user((int) $USER->id);
if ($activejob) {
    echo $OUTPUT->notification(get_string('job_active_notice', 'local_ai_yared_course') . ' ' .
        html_writer::link(new moodle_url('/local/ai_yared_course/job.php', ['id' => $activejob->id]),
            get_string('job_view', 'local_ai_yared_course'), ['class' => 'btn btn-sm btn-primary ms-2']), 'info');
}
$recentjobs = job_store::get_recent((int) $USER->id, (int) $course->id, 5);
if ($recentjobs) {
    $items = [];
    foreach ($recentjobs as $recent) {
        $items[] = html_writer::link(new moodle_url('/local/ai_yared_course/job.php', ['id' => $recent->id]),
            userdate((int) $recent->timecreated, get_string('strftimedatetimeshort')) . ' · ' .
            get_string('job_status_' . $recent->status, 'local_ai_yared_course') . ' · ' .
            get_string('job_topics_count', 'local_ai_yared_course', (int) $recent->total));
    }
    echo html_writer::tag('details', html_writer::tag('summary', get_string('job_recent', 'local_ai_yared_course'),
        ['class' => 'fw-semibold']) . html_writer::alist($items, ['class' => 'small mt-2']), ['class' => 'mb-3']);
}

echo html_writer::tag('p', get_string('batchintro', 'local_ai_yared_course'), ['class' => 'text-muted']);
foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'error');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false),
    'class' => 'aiyared-batch-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// 1. Where to add the topics.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('step_section', 'local_ai_yared_course'), ['class' => 'h5 card-title']);
echo html_writer::start_div('row g-3 aiyared-choices');
$choices = [
    ['existing', 'sectionmode-existing', 'section_existing', 'section_existing_card', 'bi-folder'],
    ['new', 'sectionmode-new', 'section_new', 'section_new_card', 'bi-folder-plus'],
];
foreach ($choices as [$value, $inputid, $labelkey, $desckey]) {
    $attrs = ['type' => 'radio', 'name' => 'sectionmode', 'id' => $inputid, 'value' => $value,
        'class' => 'form-check-input aiyared-choice-input'];
    if ($sectionmode === $value) {
        $attrs['checked'] = 'checked';
    }
    echo html_writer::start_div('col-12 col-md-6');
    echo html_writer::start_tag('label', ['for' => $inputid, 'class' => 'aiyared-choice']);
    echo html_writer::empty_tag('input', $attrs);
    echo html_writer::span(
        html_writer::span(get_string($labelkey, 'local_ai_yared_course'), 'aiyared-choice-title') .
        html_writer::span(get_string($desckey, 'local_ai_yared_course'), 'aiyared-choice-desc'),
        'aiyared-choice-text');
    echo html_writer::end_tag('label');
    echo html_writer::end_div();
}

echo html_writer::start_div('col-12', ['id' => 'aiyared-existing-section']);
echo html_writer::label(get_string('choose_section', 'local_ai_yared_course'), 'sectionnum', false,
    ['class' => 'form-label']);
echo html_writer::select($sectionoptions, 'sectionnum', $sectionnum, false,
    ['id' => 'sectionnum', 'class' => 'form-control']);
$helpkey = $subsupport === '' ? 'section_existing_help'
    : ($subsupport === 'flextiles' ? 'section_existing_help_sub' : 'section_existing_help_sub_optional');
echo html_writer::div(get_string($helpkey, 'local_ai_yared_course'), 'form-text');
if ($subsupport === 'flextiles') {
    // The display mode is mandatory: every topic becomes a subsection, and it is always
    // rendered either as an accordion (default) or as a mosaic tile.
    $modes = [
        'accordion' => get_string('subsection_mode_accordion', 'local_ai_yared_course'),
        'mosaic' => get_string('subsection_mode_mosaic', 'local_ai_yared_course'),
    ];
    echo html_writer::start_div('mt-3');
    echo html_writer::label(get_string('subsection_mode', 'local_ai_yared_course'), 'subsectionmode', false,
        ['class' => 'form-label']);
    echo html_writer::select($modes, 'subsectionmode', $subsectionmode, false,
        ['id' => 'subsectionmode', 'class' => 'form-control']);
    echo html_writer::div(get_string('subsection_mode_help', 'local_ai_yared_course'), 'form-text');
    echo html_writer::div(get_string('subsection_mode_required', 'local_ai_yared_course'),
        'form-text fw-semibold');
    echo html_writer::end_div();
} else if ($subsupport !== '') {
    $subattrs = ['type' => 'checkbox', 'name' => 'usesubsection', 'id' => 'usesubsection', 'value' => 1,
        'class' => 'form-check-input'];
    if ($usesubsection) {
        $subattrs['checked'] = 'checked';
    }
    $subinfokey = $subsupport === 'flexsections' ? 'subsection_info_flex' : 'subsection_info_moodle5';
    echo html_writer::div(
        html_writer::empty_tag('input', $subattrs) .
        html_writer::label(get_string('subsection_label', 'local_ai_yared_course'), 'usesubsection', false,
            ['class' => 'form-check-label']) .
        html_writer::div(get_string($subinfokey, 'local_ai_yared_course') . ' ' .
            get_string('subsection_label_desc', 'local_ai_yared_course'), 'form-text'),
        'form-check mt-2');
} else {
    if (\core_component::get_plugin_directory('format', 'flextiles') !== null) {
        $needkey = 'subsection_need_format_tiles';
    } else if (\core_component::get_plugin_directory('format', 'flexsections') !== null) {
        $needkey = 'subsection_need_format';
    } else {
        $needkey = 'subsection_need_flex';
    }
    echo html_writer::div(get_string($needkey, 'local_ai_yared_course'), 'alert alert-info small mt-2 mb-0');
}
echo html_writer::end_div();

echo html_writer::end_div(); // .row
echo html_writer::end_div(); // .card-body
echo html_writer::end_div(); // .card

// 2. How many topics.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('step_count', 'local_ai_yared_course'), ['class' => 'h5 card-title']);
echo html_writer::start_div('row align-items-center');
echo html_writer::start_div('col-12 col-md-4');
$countoptions = [];
for ($i = 1; $i <= 10; $i++) {
    $countoptions[$i] = $i;
}
echo html_writer::select($countoptions, 'topiccount', $topiccount, false,
    ['id' => 'topiccount', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::start_div('col-12 col-md-8');
echo html_writer::div(get_string('count_topics_help', 'local_ai_yared_course'), 'form-text');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// 3. The topics, with their difficulty and date.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('step_topics', 'local_ai_yared_course'), ['class' => 'h5 card-title']);
echo html_writer::tag('p', get_string('step_topics_help', 'local_ai_yared_course'), ['class' => 'text-muted small']);

$ages = [];
for ($age = levels::MIN_AGE; $age <= levels::MAX_AGE; $age++) {
    $ages[$age] = get_string('ageoption', 'local_ai_yared_course', (object) [
        'age' => $age,
        'level' => get_string('level_' . levels::key($age), 'local_ai_yared_course'),
    ]);
}

for ($i = 1; $i <= 10; $i++) {
    $topic = $topics[$i];
    $titleattrs = ['type' => 'text', 'name' => 'ttitle' . $i, 'id' => 'ttitle' . $i,
        'value' => $topic['title'], 'class' => 'form-control',
        'placeholder' => get_string('topic_title_placeholder', 'local_ai_yared_course'),
        'maxlength' => 200, 'autocomplete' => 'off'];
    $ageattrs = ['id' => 'tage' . $i, 'class' => 'form-control'];
    $dateattrs = ['type' => 'date', 'name' => 'tdate' . $i, 'id' => 'tdate' . $i,
        'value' => date('Y-m-d', (int) $topic['classdate']), 'class' => 'form-control'];

    echo html_writer::start_div('aiyared-topic-row border rounded p-3 mb-3',
        ['data-index' => $i, 'id' => 'aiyared-topic-row-' . $i]);
    echo html_writer::start_div('row g-2 align-items-end');

    echo html_writer::start_div('col-12 col-lg-6');
    echo html_writer::label(get_string('topic_number_title', 'local_ai_yared_course', $i), 'ttitle' . $i, false,
        ['class' => 'form-label small']);
    echo html_writer::empty_tag('input', $titleattrs);
    echo html_writer::end_div();

    echo html_writer::start_div('col-6 col-lg-3');
    echo html_writer::label(get_string('topic_age', 'local_ai_yared_course'), 'tage' . $i, false,
        ['class' => 'form-label small']);
    echo html_writer::select($ages, 'tage' . $i, (int) $topic['age'], false, $ageattrs);
    echo html_writer::end_div();

    echo html_writer::start_div('col-6 col-lg-3');
    echo html_writer::label(get_string('topic_date', 'local_ai_yared_course'), 'tdate' . $i, false,
        ['class' => 'form-label small']);
    echo html_writer::empty_tag('input', $dateattrs);
    echo html_writer::end_div();

    echo html_writer::end_div(); // .row
    echo html_writer::end_div(); // .aiyared-topic-row
}

echo html_writer::end_div(); // .card-body
echo html_writer::end_div(); // .card

// 4. What to create for every topic.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('step_contents', 'local_ai_yared_course'), ['class' => 'h5 card-title']);

$rendercheckbox = function (string $name, string $label, string $description, bool $checked): string {
    $id = 'aiyared-' . $name;
    $attributes = ['type' => 'checkbox', 'name' => $name, 'id' => $id, 'value' => 1, 'class' => 'form-check-input'];
    if ($checked) {
        $attributes['checked'] = 'checked';
    }
    return html_writer::start_div('form-check mb-3')
        . html_writer::empty_tag('input', $attributes)
        . html_writer::label($label, $id, false, ['class' => 'form-check-label'])
        . html_writer::div($description, 'form-text')
        . html_writer::end_div();
};

echo html_writer::start_div('row');
echo html_writer::start_div('col-12 col-lg-6');
echo $rendercheckbox('createpdf', get_string('createpdf', 'local_ai_yared_course'),
    get_string('createpdf_desc', 'local_ai_yared_course'), !empty($createpdf));
echo $rendercheckbox('findvideo', get_string('createvideo', 'local_ai_yared_course'),
    get_string('createvideo_desc', 'local_ai_yared_course'), !empty($findvideo));
echo $rendercheckbox('createbank', get_string('createbank', 'local_ai_yared_course'),
    get_string('createbank_desc', 'local_ai_yared_course'), !empty($createbank));
echo html_writer::end_div();
echo html_writer::start_div('col-12 col-lg-6');
echo $rendercheckbox('createquiz', get_string('createquiz', 'local_ai_yared_course'),
    get_string('createquiz_desc', 'local_ai_yared_course'), !empty($createquiz));
echo $rendercheckbox('createassign', get_string('createassign', 'local_ai_yared_course'),
    get_string('createassign_desc', 'local_ai_yared_course'), !empty($createassign));
echo $rendercheckbox('createforum', get_string('createforum', 'local_ai_yared_course'),
    get_string('createforum_desc', 'local_ai_yared_course'), !empty($createforum));
echo html_writer::end_div();
echo html_writer::end_div();

// Advanced options: keep the main form short.
echo html_writer::start_tag('details', ['class' => 'mb-2']);
echo html_writer::tag('summary', get_string('advancedoptions', 'local_ai_yared_course'), ['class' => 'fw-semibold']);
echo html_writer::start_div('row mt-2');

echo html_writer::start_div('col-12 col-md-4 mb-2');
echo html_writer::label(get_string('questionspertopic', 'local_ai_yared_course'), 'questioncount', false,
    ['class' => 'form-label']);
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'questioncount', 'id' => 'questioncount',
    'value' => $questioncount, 'class' => 'form-control', 'min' => 1, 'max' => 30]);
echo html_writer::div(get_string('questionspertopic_help', 'local_ai_yared_course'), 'form-text');
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-4 mb-2');
echo html_writer::label(get_string('language', 'local_ai_yared_course'), 'language', false, ['class' => 'form-label']);
$languages = [];
foreach (array_keys(generate_form::LANGUAGES) as $code) {
    $languages[$code] = get_string('lang_' . $code, 'local_ai_yared_course');
}
echo html_writer::select($languages, 'language', $language, false, ['id' => 'language', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-4 mb-2', ['id' => 'aiyared-hidesection-wrap']);
echo html_writer::start_div('form-check mt-md-4 pt-md-2');
$hideattrs = ['type' => 'checkbox', 'name' => 'hidesection', 'id' => 'hidesection', 'value' => 1,
    'class' => 'form-check-input'];
if (!empty($hidesection)) {
    $hideattrs['checked'] = 'checked';
}
echo html_writer::empty_tag('input', $hideattrs);
echo html_writer::label(get_string('hidesection', 'local_ai_yared_course'), 'hidesection', false,
    ['class' => 'form-check-label']);
echo html_writer::div(get_string('hidesection_desc', 'local_ai_yared_course'), 'form-text');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // .row
echo html_writer::end_tag('details');

echo html_writer::end_div(); // .card-body
echo html_writer::end_div(); // .card

echo html_writer::start_div('d-flex flex-wrap gap-2 mb-4');
echo html_writer::tag('button', get_string('create_topics', 'local_ai_yared_course'), [
    'type' => 'submit', 'name' => 'generate', 'value' => 1, 'class' => 'btn btn-primary btn-lg',
]);
echo html_writer::link($courseurl, get_string('cancel'), ['class' => 'btn btn-outline-secondary btn-lg']);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// Responsive behaviour: show only the chosen number of topics and keep the quiz tied to the bank.
echo '<script>
(function() {
    "use strict";
    var count = document.getElementById("topiccount");
    var rows = document.querySelectorAll(".aiyared-topic-row");
    var modeNew = document.getElementById("sectionmode-new");
    var modeExisting = document.getElementById("sectionmode-existing");
    var existingWrap = document.getElementById("aiyared-existing-section");
    var hideWrap = document.getElementById("aiyared-hidesection-wrap");
    var bank = document.getElementById("aiyared-createbank");
    var quiz = document.getElementById("aiyared-createquiz");

    function updateRows() {
        var total = parseInt(count.value, 10) || 1;
        rows.forEach(function(row) {
            row.style.display = (parseInt(row.getAttribute("data-index"), 10) <= total) ? "" : "none";
        });
    }

    function updateSection() {
        var existing = modeExisting.checked;
        existingWrap.style.display = existing ? "" : "none";
        if (hideWrap) {
            hideWrap.style.display = existing ? "none" : "";
        }
    }

    function updateBank() {
        if (!bank.checked) {
            quiz.checked = false;
            quiz.disabled = true;
        } else {
            quiz.disabled = false;
        }
    }

    count.addEventListener("change", updateRows);
    modeNew.addEventListener("change", updateSection);
    modeExisting.addEventListener("change", updateSection);
    bank.addEventListener("change", updateBank);
    quiz.addEventListener("change", function() {
        if (quiz.checked) {
            bank.checked = true;
        }
        updateBank();
    });

    updateRows();
    updateSection();
    updateBank();
})();
</script>';

echo $OUTPUT->footer();
