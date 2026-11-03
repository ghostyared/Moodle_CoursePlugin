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
 * Preview of a topic made by the Material Creator, before anything is created in the course.
 *
 * The teacher sees the topic as the students will see it, can fix the texts and the questions, and only then creates
 * it. The draft belongs to the teacher who generated it and expires after 24 hours.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\course_builder;
use local_ai_yared_course\local\import_store;
use local_ai_yared_course\local\job_report;
use local_ai_yared_course\local\question_saver;
use local_ai_yared_course\local\topic_draft;

$courseid = required_param('id', PARAM_INT);
$draftid = required_param('draft', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);
require_capability('moodle/course:manageactivities', $context);

$url = new moodle_url('/local/ai_yared_course/topicpreview.php', ['id' => $course->id, 'draft' => $draftid]);
$formurl = new moodle_url('/local/ai_yared_course/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('preview_title', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));

$backurl = $formurl;
try {
    $manifest = topic_draft::load($draftid, (int) $USER->id, (int) $course->id);
} catch (moodle_exception $e) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('preview_missing', 'local_ai_yared_course'), 'warning');
    echo $OUTPUT->single_button($formurl, get_string('createanotherbatch', 'local_ai_yared_course'), 'get');
    echo $OUTPUT->footer();
    exit;
}

if ((int) ($manifest['jobid'] ?? 0) > 0) {
    // Back to the list of topics of the same batch, to review the other ones.
    $backurl = new moodle_url('/local/ai_yared_course/job.php', ['id' => (int) $manifest['jobid']]);
}

// Discard.
if ($action === 'discard') {
    require_sesskey();
    topic_draft::delete($draftid, (int) $USER->id);
    redirect($backurl, get_string('preview_discarded', 'local_ai_yared_course'), null,
        \core\output\notification::NOTIFY_INFO);
}

// Save the edits or create the topic: both start by applying what the teacher changed.
if ($action === 'save' || $action === 'create') {
    require_sesskey();
    $factory = \core\lock\lock_config::get_lock_factory('local_ai_yared_course_topicdraft');
    $lock = $factory->get_lock('draft_' . $draftid, 5);
    if (!$lock) {
        redirect($url, get_string('preview_busy', 'local_ai_yared_course'), null, \core\output\notification::NOTIFY_WARNING);
    }
    try {
        // Read again inside the lock: a second click must not create the topic twice.
        $manifest = topic_draft::load($draftid, (int) $USER->id, (int) $course->id);
        $post = [
            'summary' => optional_param('summary', '', PARAM_RAW),
            'objectives' => optional_param('objectives', '', PARAM_RAW),
            'forum_question' => optional_param('forum_question', '', PARAM_RAW),
            'extension_title' => optional_param('extension_title', '', PARAM_RAW),
            'extension_instructions' => optional_param('extension_instructions', '', PARAM_RAW),
            'q' => optional_param_array('aiyq', [], PARAM_RAW),
            's' => optional_param_array('aiys', [], PARAM_RAW),
        ];
        $manifest = topic_draft::apply_edits($manifest, $post);
        topic_draft::save($draftid, (int) $USER->id, $manifest);

        if ($action === 'save') {
            redirect($url, get_string('preview_saved', 'local_ai_yared_course'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }

        // Create the topic from the approved content: no AI call is needed. The posted edit fields are removed from the
        // request first: Moodle activity code reads request parameters with common names and cannot read arrays.
        unset($_POST['aiyq'], $_POST['aiys'], $_REQUEST['aiyq'], $_REQUEST['aiys']);
        core_php_time_limit::raise(300);
        $createerror = '';
        try {
            $client = ai_factory::create();
            $builder = new course_builder($course, $client);
            $parts = topic_draft::parts_for_creation($draftid, (int) $USER->id, $manifest);
            $created = $builder->create_topic($manifest['in'], $parts);
            topic_draft::delete($draftid, (int) $USER->id);
        } catch (\Throwable $e) {
            // The draft is kept: nothing the teacher edited is lost.
            $createerror = $e->getMessage();
            debugging('local_ai_yared_course: topic creation from a draft failed: ' . $createerror, DEBUG_DEVELOPER);
        }
    } finally {
        $lock->release();
    }

    if ($createerror !== '') {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('preview_create_failed', 'local_ai_yared_course', s($createerror)), 'error');
        echo html_writer::div(
            $OUTPUT->single_button($url, get_string('preview_back_to_preview', 'local_ai_yared_course'), 'get',
                ['type' => 'primary']) .
            $OUTPUT->single_button(new moodle_url('/course/view.php', ['id' => $course->id]),
                get_string('gotocourse', 'local_ai_yared_course'), 'get'),
            'd-flex flex-wrap gap-2 mb-3');
        echo $OUTPUT->footer();
        exit;
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('preview_created_title', 'local_ai_yared_course'));
    echo $OUTPUT->notification(get_string('preview_created', 'local_ai_yared_course'), 'success');
    $in = $manifest['in'];
    echo job_report::render($course, [['title' => (string) $in['title'], 'result' => $created, 'error' => null]], [
        'findvideo' => !empty($in['findvideo']), 'createpdf' => !empty($in['createpdf']),
        'createbank' => !empty($in['createbank']), 'createquiz' => !empty($in['createquiz']),
        'createassign' => !empty($in['createassign']), 'createforum' => !empty($in['createforum']),
    ]);
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url('/course/view.php', ['id' => $course->id]),
            get_string('gotocourse', 'local_ai_yared_course'), 'get', ['type' => 'primary']) .
        ($backurl !== $formurl ? $OUTPUT->single_button($backurl, get_string('preview_back_to_batch',
            'local_ai_yared_course'), 'get') : '') .
        $OUTPUT->single_button($formurl, get_string('createanotherbatch', 'local_ai_yared_course'), 'get'),
        'd-flex flex-wrap gap-2 mb-3');
    echo $OUTPUT->footer();
    exit;
}

// ---- Preview ----
$in = $manifest['in'];
$parts = $manifest['parts'];
$overview = $parts['overview'];
$questions = array_values((array) $parts['questions']);
$mathactive = question_saver::math_filter_active($context);

$builder = new course_builder($course, ai_factory::create());
$modules = $builder->preview_topic($in, $parts + ['pdf' => !empty($parts['haspdf']) ? '' : null]);

$icons = ['url' => '🔗', 'resource' => '📄', 'quiz' => '📝', 'assign' => '📋', 'forum' => '💬'];
$kindlabels = [
    'url' => get_string('preview_kind_url', 'local_ai_yared_course'),
    'resource' => get_string('preview_kind_resource', 'local_ai_yared_course'),
    'quiz' => get_string('preview_kind_quiz', 'local_ai_yared_course'),
    'assign' => get_string('preview_kind_assign', 'local_ai_yared_course'),
    'forum' => get_string('preview_kind_forum', 'local_ai_yared_course'),
];
$included = count($questions);
$linked = (int) $in['quizquestions'] > 0 ? min($included, (int) $in['quizquestions']) : $included;
$date = function (int $timestamp): string {
    return $timestamp > 0 ? userdate($timestamp, '%d-%m-%Y %H:%M') : get_string('preview_none', 'local_ai_yared_course');
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('preview_title', 'local_ai_yared_course') . ': ' . s($in['title']));
echo $OUTPUT->notification(get_string('preview_intro', 'local_ai_yared_course'), 'info');
if ($backurl !== $formurl) {
    echo html_writer::div(html_writer::link($backurl, '← ' . get_string('preview_back_to_batch', 'local_ai_yared_course')),
        'mb-2');
}
if (!empty($parts['warnings'])) {
    echo html_writer::tag('details', html_writer::tag('summary',
        get_string('warnings_show', 'local_ai_yared_course', count($parts['warnings']))) .
        html_writer::alist($parts['warnings'], ['class' => 'mb-0 small']), ['class' => 'mb-3 alert alert-warning']);
}
if ($questions && !empty($parts['mathcourse']) && !$mathactive) {
    echo $OUTPUT->notification(get_string('warn_mathjax', 'local_ai_yared_course'), 'warning');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'id' => 'aiyared-preview-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// 1. The section as students will see it.
echo html_writer::tag('h3', get_string('preview_section', 'local_ai_yared_course'), ['class' => 'h5 mt-3']);
echo html_writer::start_div('aiyared-preview-section');
foreach ($modules as $module) {
    $mod = $module['modname'];
    $intro = format_text($module['intro'], FORMAT_HTML, ['context' => $context, 'noclean' => true, 'para' => false]);
    if ($mod === 'label') {
        echo html_writer::div($intro, 'aiyared-preview-label' . ($module['visible'] ? '' : ' aiyared-preview-hidden'));
        continue;
    }
    $badge = $module['visible']
        ? html_writer::span(get_string('preview_visible', 'local_ai_yared_course'), 'badge bg-success')
        : html_writer::span(get_string('preview_hidden', 'local_ai_yared_course'), 'badge bg-secondary');
    $details = '';
    $extra = $module['extra'];
    if ($mod === 'url' && !empty($extra['externalurl'])) {
        $details .= html_writer::div(html_writer::link($extra['externalurl'], s($extra['externalurl']),
            ['target' => '_blank', 'rel' => 'noopener noreferrer']), 'small');
    }
    if ($mod === 'resource' && !empty($parts['haspdf'])) {
        $details .= html_writer::div(html_writer::link(import_store::file_url($draftid, (int) $USER->id, topic_draft::PDF),
            get_string('preview_openpdf', 'local_ai_yared_course'), ['target' => '_blank']), 'small');
    }
    if ($mod === 'quiz') {
        $details .= html_writer::div(get_string('preview_quizinfo', 'local_ai_yared_course', (object) [
            'questions' => $linked,
            'open' => $date((int) ($extra['timeopen'] ?? 0)),
            'close' => $date((int) ($extra['timeclose'] ?? 0)),
            'minutes' => (int) round(((int) ($extra['timelimit'] ?? 0)) / 60),
            'attempts' => (int) ($extra['attempts'] ?? 0),
        ]), 'small text-muted');
    }
    if ($mod === 'assign') {
        $details .= html_writer::div(get_string('preview_assigninfo', 'local_ai_yared_course', (object) [
            'open' => $date((int) ($extra['allowsubmissionsfromdate'] ?? 0)),
            'due' => $date((int) ($extra['duedate'] ?? 0)),
        ]), 'small text-muted');
    }
    echo html_writer::div(
        html_writer::div(($icons[$mod] ?? '▫️') . ' ' . html_writer::tag('strong', s($module['name'])) . ' ' . $badge .
            ' ' . html_writer::span($kindlabels[$mod] ?? $mod, 'small text-muted'), 'aiyared-preview-title') .
        html_writer::div($intro, 'small') . $details,
        'aiyared-preview-activity' . ($module['visible'] ? '' : ' aiyared-preview-hidden'));
}
echo html_writer::end_div();

// 2. Texts the teacher can fix.
echo html_writer::start_tag('details', ['class' => 'mt-3 mb-3']);
echo html_writer::tag('summary', get_string('preview_edit_texts', 'local_ai_yared_course'), ['class' => 'fw-semibold']);
echo html_writer::start_div('mt-2');
$field = function (string $name, string $label, string $value, bool $multiline, int $rows = 3): string {
    $control = $multiline
        ? html_writer::tag('textarea', s($value), ['name' => $name, 'id' => 'aiyared-' . $name, 'rows' => $rows,
            'class' => 'form-control'])
        : html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'id' => 'aiyared-' . $name,
            'value' => $value, 'class' => 'form-control']);
    return html_writer::div(html_writer::label($label, 'aiyared-' . $name, false, ['class' => 'form-label']) . $control,
        'mb-3');
};
echo $field('summary', get_string('preview_f_summary', 'local_ai_yared_course'), (string) $overview['summary'], true, 4);
echo $field('objectives', get_string('preview_f_objectives', 'local_ai_yared_course'),
    implode("\n", (array) ($overview['objectives'] ?? [])), true, 4);
echo $field('extension_title', get_string('preview_f_exttitle', 'local_ai_yared_course'),
    (string) ($overview['extension']['title'] ?? ''), false);
echo $field('extension_instructions', get_string('preview_f_extinstr', 'local_ai_yared_course'),
    (string) ($overview['extension']['instructions'] ?? ''), true, 3);
echo $field('forum_question', get_string('preview_f_forum', 'local_ai_yared_course'),
    (string) ($overview['forum_question'] ?? ''), true, 2);
echo html_writer::end_div();
echo html_writer::end_tag('details');

// 3. Class presentation.
$slidelist = (array) ($parts['slides']['slides'] ?? []);
if ($slidelist) {
    echo html_writer::tag('h3', get_string('preview_slides', 'local_ai_yared_course', count($slidelist)), ['class' => 'h5 mt-3']);
    echo html_writer::tag('p', get_string('preview_slides_help', 'local_ai_yared_course'), ['class' => 'text-muted small']);
    echo html_writer::div(\local_ai_yared_course\local\slides::render_viewer($parts['slides']), 'aiyared-preview-deck');
    echo html_writer::tag('details', html_writer::tag('summary', get_string('preview_slides_edit', 'local_ai_yared_course'),
        ['class' => 'fw-semibold']) . html_writer::div(\local_ai_yared_course\local\slides::editor_fields($parts['slides']),
        'mt-2'), ['class' => 'mb-3']);
    $PAGE->requires->js_init_code(\local_ai_yared_course\local\slides::viewer_js());
}

// 4. Questions of the bank.
if (!empty($in['createbank']) && (int) $in['questioncount'] > 0) {
    echo html_writer::tag('h3', get_string('preview_questions', 'local_ai_yared_course', $included), ['class' => 'h5 mt-3']);
    echo html_writer::tag('p', get_string('preview_questions_help', 'local_ai_yared_course'), ['class' => 'text-muted small']);
    if (!$questions) {
        echo $OUTPUT->notification(get_string('preview_noquestions', 'local_ai_yared_course'), 'warning');
    }
    foreach ($questions as $i => $question) {
        $render = question_saver::render_preview($question, $context);
        $body = question_saver::preview_question($question, $context);
        $options = '';
        foreach ($render['options'] as $option) {
            $options .= html_writer::tag('li', $option['html'] . ($option['correct']
                ? ' ' . html_writer::span('✔ ' . get_string('preview_correct', 'local_ai_yared_course'),
                    'badge bg-success') : ''));
        }
        $editor = html_writer::tag('textarea', s((string) $question['text']), ['name' => "aiyq[$i][text]", 'rows' => 3,
            'class' => 'form-control mb-2']);
        foreach ((array) ($question['options'] ?? []) as $j => $option) {
            $editor .= html_writer::div(
                html_writer::empty_tag('input', array_filter(['type' => 'radio', 'name' => "aiyq[$i][correct]", 'value' => $j,
                    'checked' => !empty($option['correct']) ? 'checked' : null, 'class' => 'form-check-input mt-0',
                    'title' => get_string('preview_correct', 'local_ai_yared_course')])) .
                html_writer::empty_tag('input', ['type' => 'text', 'name' => "aiyq[$i][options][$j]",
                    'value' => (string) $option['text'], 'class' => 'form-control']),
                'd-flex gap-2 align-items-center mb-1');
        }
        echo html_writer::div(
            html_writer::div(
                html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => "aiyq[$i][include]", 'value' => 1,
                    'checked' => 'checked', 'id' => "aiyared-q-$i", 'class' => 'form-check-input']) .
                html_writer::label(get_string('preview_question_n', 'local_ai_yared_course', $i + 1), "aiyared-q-$i", false,
                    ['class' => 'form-check-label fw-semibold ms-2']),
                'form-check mb-2') .
            html_writer::div($body, 'mb-2') .
            html_writer::tag('ol', $options, ['class' => 'aiyared-preview-options', 'type' => 'A']) .
            ($render['feedback'] !== '' ? html_writer::div(html_writer::tag('em', get_string('preview_feedback',
                'local_ai_yared_course')) . ' ' . $render['feedback'], 'small text-muted mb-2') : '') .
            html_writer::tag('details', html_writer::tag('summary', get_string('preview_edit_question',
                'local_ai_yared_course')) . html_writer::div($editor, 'mt-2'), ['class' => 'small']),
            'aiyared-preview-question');
    }
}

// Actions.
echo html_writer::start_div('d-flex flex-wrap gap-2 my-4');
echo html_writer::tag('button', get_string('preview_create', 'local_ai_yared_course'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'create', 'class' => 'btn btn-primary']);
echo html_writer::tag('button', get_string('preview_save', 'local_ai_yared_course'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'save', 'class' => 'btn btn-secondary']);
echo html_writer::tag('button', get_string('preview_discard', 'local_ai_yared_course'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'discard', 'class' => 'btn btn-outline-danger',
        'formnovalidate' => 'formnovalidate', 'onclick' => 'return confirm(' .
        json_encode(get_string('preview_discard_confirm', 'local_ai_yared_course')) . ');']);
echo html_writer::end_div();
echo html_writer::tag('p', get_string('preview_expires', 'local_ai_yared_course'), ['class' => 'text-muted small']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
