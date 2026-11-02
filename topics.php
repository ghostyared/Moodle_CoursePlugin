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
 * Designer of the topics of an academic period and publisher of the syllabus.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\flush_progress_trace;
use local_ai_yared_course\local\levels;
use local_ai_yared_course\local\syllabus;
use local_ai_yared_course\local\topic_runner;
use local_ai_yared_course\local\topic_store;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:manage', $context);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url('/local/ai_yared_course/topics.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('designer', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
// Ask nginx not to buffer the response: the progress is flushed while the AI works.
@header('X-Accel-Buffering: no');

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

// Number of rows shown by the designer (the teacher can add or remove rows with the links below).
$rowcount = optional_param('rowcount', 0, PARAM_INT);
$storedtopics = topic_store::get_topics($course->id);
if ($rowcount <= 0) {
    $rowcount = max(count($storedtopics) + 2, 8);
}
$rowcount = min(max($rowcount, 1), 50);

// Which action was submitted (if any). Checkboxes only make sense once the form was sent.
$saveall = optional_param('saveall', 0, PARAM_INT);
$generateall = optional_param('generateall', 0, PARAM_INT);
$generateone = optional_param('generate', 0, PARAM_INT);
$issubmit = (bool) ($saveall || $generateall || $generateone);

// Global options, shared by every topic.
$options = [
    'age' => optional_param('age', 12, PARAM_INT),
    'language' => optional_param('language', 'es', PARAM_ALPHA),
    'findvideo' => $issubmit ? optional_param('findvideo', 0, PARAM_INT) : 1,
    'questioncount' => min(30, max(0, optional_param('questioncount', 10, PARAM_INT))),
    'qkind' => optional_param('qkind', 'auto', PARAM_RAW_TRIMMED),
    'extensiontype' => optional_param('extensiontype', 'assign', PARAM_ALPHA),
    'hidesection' => $issubmit ? optional_param('hidesection', 0, PARAM_INT) : 1,
    'quizopendate' => optional_param('quizopendate', date('Y-m-d'), PARAM_TEXT),
    'quizclosedays' => optional_param('quizclosedays', 7, PARAM_INT),
    'quiztimelimit' => optional_param('quiztimelimit', 60, PARAM_INT),
    'quizattempts' => optional_param('quizattempts', 2, PARAM_INT),
    'quizgrade' => optional_param('quizgrade', 20, PARAM_INT),
    'quizquestions' => optional_param('quizquestions', 10, PARAM_INT),
    'quizvisible' => $issubmit ? optional_param('quizvisible', 0, PARAM_INT) : 0,
    'createbank' => $issubmit ? optional_param('createbank', 0, PARAM_INT) : 1,
    'createquiz' => $issubmit ? optional_param('createquiz', 0, PARAM_INT) : 1,
    'createassign' => $issubmit ? optional_param('createassign', 0, PARAM_INT) : 1,
];

// Read the rows: from the submitted form when saving/generating, otherwise from the database.
$rows = [];
if ($issubmit) {
    for ($i = 1; $i <= $rowcount; $i++) {
        $rawdate = optional_param('tdate' . $i, '', PARAM_TEXT);
        $rows[] = [
            'id' => optional_param('tid' . $i, 0, PARAM_INT),
            'title' => optional_param('ttitle' . $i, '', PARAM_TEXT),
            'summary' => optional_param('tsummary' . $i, '', PARAM_TEXT),
            'classdate' => $rawdate !== '' ? (int) strtotime($rawdate . ' 00:00:00') : 0,
            'age' => optional_param('tage' . $i, 0, PARAM_INT),
        ];
    }
} else {
    foreach (array_values($storedtopics) as $topic) {
        $rows[] = [
            'id' => (int) $topic->id,
            'title' => $topic->title,
            'summary' => (string) $topic->summary,
            'classdate' => (int) $topic->classdate,
            'age' => (int) ($topic->age ?? 0),
        ];
    }
    while (count($rows) < $rowcount) {
        $rows[] = ['id' => 0, 'title' => '', 'summary' => '', 'classdate' => 0, 'age' => 0];
    }
    $rows = array_slice($rows, 0, $rowcount);
}

/**
 * Build the designer rows from the stored topics.
 *
 * @param array $topics Topics indexed by id.
 * @return array
 */
$makerows = function (array $topics) use ($rowcount): array {
    $rows = [];
    foreach (array_values($topics) as $topic) {
        $rows[] = [
            'id' => (int) $topic->id,
            'title' => $topic->title,
            'summary' => (string) $topic->summary,
            'classdate' => (int) $topic->classdate,
            'age' => (int) ($topic->age ?? 0),
        ];
    }
    while (count($rows) < $rowcount) {
        $rows[] = ['id' => 0, 'title' => '', 'summary' => '', 'classdate' => 0, 'age' => 0];
    }
    return array_slice($rows, 0, $rowcount);
};

$generating = false;
if ($issubmit) {
    require_sesskey();

    // Persist whatever the teacher edited before generating.
    topic_store::save($course->id, $rows);

    // Re-read: new rows now have an id, so a second save updates them instead of duplicating them.
    $storedtopics = topic_store::get_topics($course->id);
    $rows = $makerows($storedtopics);
    $generating = (bool) ($generateall || $generateone);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('designer', 'local_ai_yared_course'));
echo html_writer::tag('p', get_string('designer_intro', 'local_ai_yared_course'), ['class' => 'text-muted']);

if ($issubmit && !$generating) {
    echo $OUTPUT->notification(get_string('saved', 'local_ai_yared_course'), \core\output\notification::NOTIFY_SUCCESS);
    syllabus::publish($course, $storedtopics);
}

if ($generating) {
    $client = ai_factory::create();
    if (!$client->is_configured()) {
        echo $OUTPUT->notification(get_string('notconfigured_teacher', 'local_ai_yared_course'), 'warning');
    } else {
        core_php_time_limit::raise(1800);
        // Release the session lock while the topics are generated.
        \core\session\manager::write_close();
        echo $OUTPUT->notification(get_string('generating_wait', 'local_ai_yared_course'), 'info');
        echo html_writer::div(
            html_writer::span('', 'spinner-border spinner-border-sm me-2', ['role' => 'status', 'aria-hidden' => 'true']) .
            html_writer::tag('strong', get_string('generating_topics', 'local_ai_yared_course')),
            'd-flex align-items-center mb-3'
        );
        flush_progress_trace::flush_now();

        $trace = new flush_progress_trace();
        $done = 0;
        $failed = 0;
        $failures = [];

        foreach ($storedtopics as $topic) {
            if ($generateone > 0 && (int) $topic->id !== $generateone) {
                continue;
            }
            if ($generateall && $topic->status === topic_store::STATUS_GENERATED) {
                continue;
            }
            $trace->output(get_string('generating_topic', 'local_ai_yared_course', $topic->title));
            try {
                $runneroptions = $options;
                $runneroptions['quizopen'] = (int) strtotime($options['quizopendate'] . ' 00:00:00');
                topic_runner::generate($course, $client, $topic, $runneroptions, $trace);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = $topic->title . ': ' . $e->getMessage();
                $trace->output('✗ ' . $e->getMessage(), 1);
                debugging('local_ai_yared_course: no se pudo generar el tema "' . $topic->title . '": ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
            if ($generateone > 0) {
                break;
            }
        }
        $trace->finished();

        $message = get_string('generated_summary', 'local_ai_yared_course', (object) ['done' => $done, 'failed' => $failed]);
        $type = $failed > 0 ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS;
        echo $OUTPUT->notification($message, $type);
        foreach ($failures as $failure) {
            echo $OUTPUT->notification(s($failure), 'warning');
        }

        // Refresh the syllabus so its dates always match the designer.
        syllabus::publish($course, topic_store::get_topics($course->id));

        // Re-read the statuses shown in the table.
        $storedtopics = topic_store::get_topics($course->id);
        $rows = $makerows($storedtopics);
    }
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rowcount', 'value' => $rowcount]);

// Global options.
echo html_writer::tag('h4', get_string('optionsheader', 'local_ai_yared_course'));
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');

echo html_writer::start_div('row');

$ages = [];
for ($age = levels::MIN_AGE; $age <= levels::MAX_AGE; $age++) {
    $ages[$age] = get_string('ageoption', 'local_ai_yared_course', (object) [
        'age' => $age,
        'level' => get_string('level_' . levels::key($age), 'local_ai_yared_course'),
    ]);
}
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('age', 'local_ai_yared_course'), 'age');
echo html_writer::select($ages, 'age', $options['age'], false, ['id' => 'age', 'class' => 'form-control']);
echo html_writer::end_div();

$languages = [];
foreach (\local_ai_yared_course\form\generate_form::LANGUAGES as $code => $name) {
    $languages[$code] = get_string('lang_' . $code, 'local_ai_yared_course');
}
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('language', 'local_ai_yared_course'), 'language');
echo html_writer::select($languages, 'language', $options['language'], false, ['id' => 'language', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('questioncount', 'local_ai_yared_course'), 'questioncount');
echo html_writer::select([0 => get_string('questioncount_none', 'local_ai_yared_course'), 10 => 10, 20 => 20, 30 => 30],
    'questioncount', $options['questioncount'], false, ['id' => 'questioncount', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('qkind', 'local_ai_yared_course'), 'qkind');
echo html_writer::select(\local_ai_yared_course\local\math_catalog::choices(), 'qkind',
    \local_ai_yared_course\local\math_catalog::valid_choice((string) $options['qkind']) ? $options['qkind'] : 'auto', false,
    ['id' => 'qkind', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('extensiontype', 'local_ai_yared_course'), 'extensiontype');
echo html_writer::select([
    'assign' => get_string('extension_assign', 'local_ai_yared_course'),
    'quiz' => get_string('extension_quiz', 'local_ai_yared_course'),
], 'extensiontype', $options['extensiontype'], false, ['id' => 'extensiontype', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quizopen', 'local_ai_yared_course'), 'quizopendate');
echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'quizopendate', 'id' => 'quizopendate',
    'value' => $options['quizopendate'], 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quizclosedays', 'local_ai_yared_course'), 'quizclosedays');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'quizclosedays', 'id' => 'quizclosedays',
    'value' => $options['quizclosedays'], 'class' => 'form-control', 'min' => 0]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quiztimelimit', 'local_ai_yared_course'), 'quiztimelimit');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'quiztimelimit', 'id' => 'quiztimelimit',
    'value' => $options['quiztimelimit'], 'class' => 'form-control', 'min' => 0]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quizattempts', 'local_ai_yared_course'), 'quizattempts');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'quizattempts', 'id' => 'quizattempts',
    'value' => $options['quizattempts'], 'class' => 'form-control', 'min' => 0]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quizgrade', 'local_ai_yared_course'), 'quizgrade');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'quizgrade', 'id' => 'quizgrade',
    'value' => $options['quizgrade'], 'class' => 'form-control', 'min' => 1]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::label(get_string('quizquestions', 'local_ai_yared_course'), 'quizquestions');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'quizquestions', 'id' => 'quizquestions',
    'value' => $options['quizquestions'], 'class' => 'form-control', 'min' => 0]);
echo html_writer::end_div();

echo html_writer::end_div(); // .row

echo html_writer::start_div('d-flex flex-wrap gap-3 mt-2');
echo html_writer::checkbox('findvideo', 1, !empty($options['findvideo']), get_string('createvideo', 'local_ai_yared_course'));
echo html_writer::checkbox('createbank', 1, !empty($options['createbank']), get_string('createbank', 'local_ai_yared_course'));
echo html_writer::checkbox('createquiz', 1, !empty($options['createquiz']), get_string('createquiz', 'local_ai_yared_course'));
echo html_writer::checkbox('createassign', 1, !empty($options['createassign']), get_string('createassign', 'local_ai_yared_course'));
echo html_writer::checkbox('hidesection', 1, !empty($options['hidesection']), get_string('hidesection', 'local_ai_yared_course'));
echo html_writer::checkbox('quizvisible', 1, !empty($options['quizvisible']), get_string('quizvisible', 'local_ai_yared_course'));
echo html_writer::end_div();

echo html_writer::end_div(); // .card-body
echo html_writer::end_div(); // .card

// Topics table.
echo html_writer::tag('h4', get_string('topicsheader', 'local_ai_yared_course'));

$table = new html_table();
$table->attributes['class'] = 'table table-sm generaltable align-middle';
$table->head = [
    get_string('col_number', 'local_ai_yared_course'),
    get_string('col_topicname', 'local_ai_yared_course'),
    get_string('col_topicdate', 'local_ai_yared_course'),
    get_string('col_topicage', 'local_ai_yared_course'),
    get_string('col_topicsummary', 'local_ai_yared_course'),
    get_string('col_status', 'local_ai_yared_course'),
    get_string('col_actions', 'local_ai_yared_course'),
];

$rowages = [0 => get_string('age_global', 'local_ai_yared_course')];
for ($age = levels::MIN_AGE; $age <= levels::MAX_AGE; $age++) {
    $rowages[$age] = $age . ' · ' . get_string('level_' . levels::key($age), 'local_ai_yared_course');
}

for ($i = 1; $i <= $rowcount; $i++) {
    $row = $rows[$i - 1];
    $existing = $storedtopics[$row['id']] ?? null;

    $titlefield = html_writer::empty_tag('input', ['type' => 'text', 'name' => 'ttitle' . $i,
        'value' => $row['title'], 'class' => 'form-control form-control-sm', 'placeholder' => get_string('rowtitle', 'local_ai_yared_course')]);
    $datefield = html_writer::empty_tag('input', ['type' => 'date', 'name' => 'tdate' . $i,
        'value' => $row['classdate'] > 0 ? date('Y-m-d', $row['classdate']) : '', 'class' => 'form-control form-control-sm']);
    $agefield = html_writer::select($rowages, 'tage' . $i, (int) $row['age'], false,
        ['id' => 'tage' . $i, 'class' => 'form-control form-control-sm']);
    $summaryfield = html_writer::tag('textarea', s($row['summary']), ['name' => 'tsummary' . $i, 'rows' => 2,
        'class' => 'form-control form-control-sm', 'placeholder' => get_string('rowsummary', 'local_ai_yared_course')]);
    $hiddenid = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tid' . $i, 'value' => $row['id']]);

    if ($existing && $existing->status === topic_store::STATUS_GENERATED) {
        $status = html_writer::span(get_string('status_generated', 'local_ai_yared_course'), 'badge bg-success');
    } else if ((int) $row['id'] > 0) {
        $status = html_writer::span(get_string('status_pending_row', 'local_ai_yared_course'), 'badge bg-warning text-dark');
    } else {
        $status = html_writer::span(get_string('status_new', 'local_ai_yared_course'), 'badge bg-secondary');
    }

    $actions = '';
    if ((int) $row['id'] > 0) {
        $actions = html_writer::tag('button', get_string('generate', 'local_ai_yared_course'), [
            'type' => 'submit', 'name' => 'generate', 'value' => $row['id'], 'class' => 'btn btn-sm btn-outline-primary',
        ]);
    }

    $table->data[] = [$i, $titlefield . $hiddenid, $datefield, $agefield, $summaryfield, $status, $actions];
}
echo html_writer::table($table);

// Row links and action buttons.
echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-center mb-3');
echo html_writer::link(new moodle_url($PAGE->url, ['rowcount' => min($rowcount + 2, 50)]),
    get_string('addrows', 'local_ai_yared_course'), ['class' => 'btn btn-sm btn-outline-secondary']);
echo html_writer::link(new moodle_url($PAGE->url, ['rowcount' => max($rowcount - 2, 1)]),
    get_string('delrows', 'local_ai_yared_course'), ['class' => 'btn btn-sm btn-outline-secondary']);
echo html_writer::end_div();

echo html_writer::start_div('d-flex flex-wrap gap-2 mb-4');
echo html_writer::tag('button', get_string('saveall', 'local_ai_yared_course'), [
    'type' => 'submit', 'name' => 'saveall', 'value' => 1, 'class' => 'btn btn-secondary',
]);
echo html_writer::tag('button', get_string('generateall', 'local_ai_yared_course'), [
    'type' => 'submit', 'name' => 'generateall', 'value' => 1, 'class' => 'btn btn-primary',
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::div(
    $OUTPUT->single_button($courseurl, get_string('gotocourse', 'local_ai_yared_course'), 'get'),
    'mb-3'
);

echo $OUTPUT->footer();
