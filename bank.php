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
 * AI question bank: generates questions and saves them in the course question bank.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

use local_ai_yared_course\form\generate_form;
use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\bank_draft;
use local_ai_yared_course\local\category_helper;
use local_ai_yared_course\local\flush_progress_trace;
use local_ai_yared_course\local\image_helper;
use local_ai_yared_course\local\question_generator;
use local_ai_yared_course\local\question_saver;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);

$mode = optional_param('mode', '', PARAM_ALPHA);
if (!in_array($mode, ['classic', 'math'], true)) {
    $mode = '';
}

$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);
require_capability('moodle/question:add', $context);

$pageparams = ['id' => $course->id];
if ($mode !== '') {
    $pageparams['mode'] = $mode;
}
$PAGE->set_url('/local/ai_yared_course/bank.php', $pageparams);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('bank_menu', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
// Ask nginx not to buffer the response: the progress is flushed while the AI works.
@header('X-Accel-Buffering: no');
$PAGE->requires->css(new moodle_url('/local/ai_yared_course/styles.css'));

$client = ai_factory::create();
\local_ai_yared_course\local\usage_log::set_course((int) $course->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bank_menu', 'local_ai_yared_course'));

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

$cancreatecategory = has_capability('moodle/question:managecategory', $context);
$categories = category_helper::get_options($context);

// Landing page: choose one of the three ways to create questions.
if ($mode === '') {
    echo html_writer::tag('p', get_string('bank_choose_intro', 'local_ai_yared_course'),
        ['class' => 'text-muted aiyared-mode-intro']);

    $cards = [
        [
            'key' => 'classic',
            'icon' => 'fa-pencil',
            'title' => get_string('mode_classic_title', 'local_ai_yared_course'),
            'desc' => get_string('mode_classic_desc', 'local_ai_yared_course'),
            'cta' => get_string('mode_classic_cta', 'local_ai_yared_course'),
            'url' => new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id, 'mode' => 'classic']),
        ],
        [
            'key' => 'math',
            'icon' => 'fa-calculator',
            'title' => get_string('mode_math_title', 'local_ai_yared_course'),
            'desc' => get_string('mode_math_desc', 'local_ai_yared_course'),
            'cta' => get_string('mode_math_cta', 'local_ai_yared_course'),
            'url' => new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id, 'mode' => 'math']),
        ],
        [
            'key' => 'import',
            'icon' => 'fa-upload',
            'title' => get_string('mode_import_title', 'local_ai_yared_course'),
            'desc' => get_string('mode_import_desc', 'local_ai_yared_course'),
            'cta' => get_string('mode_import_cta', 'local_ai_yared_course'),
            'url' => new moodle_url('/local/ai_yared_course/import.php', ['id' => $course->id]),
        ],
        [
            'key' => 'quiz',
            'icon' => 'fa-list-check',
            'title' => get_string('quiz_wizard_card_title', 'local_ai_yared_course'),
            'desc' => get_string('quiz_wizard_card_desc', 'local_ai_yared_course'),
            'cta' => get_string('quiz_wizard_card_cta', 'local_ai_yared_course'),
            'url' => new moodle_url('/local/ai_yared_course/quiz.php', ['id' => $course->id]),
        ],
    ];

    echo html_writer::start_div('aiyared-mode-grid');
    foreach ($cards as $card) {
        echo html_writer::start_div('aiyared-mode-card card h-100 aiyared-mode-' . $card['key']);
        echo html_writer::start_div('card-body d-flex flex-column');
        echo html_writer::div(html_writer::tag('i', '', ['class' => 'fa ' . $card['icon'], 'aria-hidden' => 'true']),
            'aiyared-mode-icon');
        echo html_writer::tag('h2', $card['title'], ['class' => 'h4 card-title']);
        echo html_writer::tag('p', $card['desc'], ['class' => 'card-text text-muted flex-grow-1']);
        echo html_writer::link($card['url'], $card['cta'], ['class' => 'btn btn-primary align-self-start']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::tag('p', get_string('bank_choose_footer', 'local_ai_yared_course'),
        ['class' => 'text-muted small mt-3']);
    echo $OUTPUT->footer();
    exit;
}

$mform = new generate_form($PAGE->url->out(false), [
    'courseid' => $course->id,
    'categories' => $categories,
    'cancreatecategory' => $cancreatecategory,
    'mode' => $mode,
]);

if ($data = $mform->get_data()) {
    $trace = null;
    try {
        core_php_time_limit::raise(900);
        // Release the session lock during the long AI call.
        \core\session\manager::write_close();

        // Show the waiting view before the AI call and flush it so the browser paints it.
        echo $OUTPUT->box_start('generalbox aiyared-bank-server-wait');
        echo html_writer::div(
            html_writer::span('', 'spinner-border spinner-border-sm me-2', [
                'id' => 'aiyared-bank-server-spinner',
                'role' => 'status',
                'aria-hidden' => 'true',
            ]) .
            html_writer::tag('strong', get_string('bank_generating_title', 'local_ai_yared_course')),
            'd-flex align-items-center mb-2'
        );
        echo html_writer::tag('p', get_string('bank_generating_intro', 'local_ai_yared_course'),
            ['class' => 'text-muted mb-0']);
        echo $OUTPUT->box_end();
        flush_progress_trace::flush_now();

        $trace = new flush_progress_trace();
        $trace->output(get_string('bank_generating_topics', 'local_ai_yared_course'));

        $topics = generate_form::split_topics($data->topics);
        $stems = generate_form::split_stems($data->stems);
        $age = generate_form::resolve_age($data->level, (int) $data->age);
        $language = generate_form::LANGUAGES[$data->language] ?? 'Spanish';
        $total = max((int) $data->count, count($stems));
        $mathmode = $mode === 'math';
        $allowimages = !empty($data->allowimages);
        $instructions = trim((string) ($data->instructions ?? ''));
        $mathcourse = $mathmode ? (string) ($data->mathcourse ?? '') : '';
        if ($mathcourse !== '') {
            // The course sets the style of the exercises; the teacher's own instructions come after it.
            $instructions = trim(\local_ai_yared_course\local\math_catalog::prompt_line($mathcourse) . "\n" . $instructions);
        }
        $imageurls = $allowimages ? generate_form::split_image_urls((string) ($data->imageurls ?? '')) : [];
        $imagewarnings = [];

        $trace->output(get_string('bank_generating_ai', 'local_ai_yared_course'));
        $generator = new question_generator($client, $client->get_batchsize(), $mathmode, $allowimages);
        $qtype = (string) ($data->qtype ?? '') ?: 'multichoice';
        $questions = $generator->generate($topics, $stems, $total, $age, $qtype, $language, $instructions);
        if (empty($questions)) {
            throw new moodle_exception('error_noquestions', 'local_ai_yared_course');
        }
        if ($allowimages && $imageurls) {
            foreach ($questions as $i => $question) {
                if (!isset($imageurls[$i])) {
                    continue;
                }
                if (!image_helper::is_safe_url($imageurls[$i])) {
                    $imagewarnings[] = get_string('warn_imagebadurl', 'local_ai_yared_course');
                    continue;
                }
                $questions[$i]['image'] = $imageurls[$i];
                $questions[$i]['imagealt'] = '';
                $questions[$i]['figure_svg'] = null;
            }
        }
        if ($allowimages) {
            $trace->output(get_string('bank_generating_images', 'local_ai_yared_course'));
        }

        if (!empty($data->review)) {
            // Review first: keep the questions as a draft the teacher can read, edit and approve before saving.
            $draftid = bank_draft::create((int) $USER->id, (int) $course->id, $questions, [
                'topics' => $topics,
                'age' => $age,
                'language' => $language,
                'qtype' => (string) ($data->qtype ?? 'multichoice') ?: 'multichoice',
                'instructions' => $instructions,
                'mathmode' => $mathmode,
                'allowfigures' => $allowimages,
                'label' => \core_text::substr(trim(\local_ai_yared_course\local\math_catalog::name($mathcourse) . ' '
                    . implode('; ', $topics)), 0, 120),
            ], (int) $data->categoryid, trim((string) ($data->newcategory ?? '')),
                array_merge($generator->get_warnings(), $imagewarnings));
            \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::BANK, (int) $course->id,
                \core_text::substr(trim(\local_ai_yared_course\local\math_catalog::name($mathcourse) . ': '
                    . implode('; ', $topics), ': '), 0, 255), (int) $age,
                ['requested' => (int) $total, 'created' => 0, 'generated' => count($questions), 'review' => 1,
                    'mode' => (string) $mode],
                count($questions) < $total ? 'warn' : 'ok', '', 0,
                ['provider' => $client->get_provider(), 'model' => $client->get_model()]);
            $trace->finished();
            redirect(new moodle_url('/local/ai_yared_course/import.php', ['id' => $course->id, 'draft' => $draftid]));
        }

        // Resolve (or create) the destination category only after the AI answered.
        if ((int) $data->categoryid === 0) {
            require_capability('moodle/question:managecategory', $context);
            $category = category_helper::create($context, trim($data->newcategory));
        } else {
            $category = $DB->get_record('question_categories',
                ['id' => $data->categoryid, 'contextid' => $context->id], '*', MUST_EXIST);
        }

        // Short answer and essay (only when the teacher chose them) are saved last, after the ones that grade themselves.
        usort($questions, function ($x, $y) {
            return (int) in_array($x['type'], ['shortanswer', 'essay'], true)
                <=> (int) in_array($y['type'], ['shortanswer', 'essay'], true);
        });
        $trace->output(get_string('bank_generating_saving', 'local_ai_yared_course'));
        $result = question_saver::save($questions, $category, !empty($data->draft));
        \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::BANK, (int) $course->id,
            \core_text::substr(trim(\local_ai_yared_course\local\math_catalog::name($mathcourse) . ': '
                . implode('; ', $topics), ': '), 0, 255), (int) $age,
            ['requested' => (int) $total, 'created' => (int) $result['created'], 'failed' => (int) $result['failed'],
                'mode' => (string) $mode],
            ($result['failed'] > 0 || $result['created'] < $total) ? 'warn' : 'ok', '', 0,
            ['provider' => $client->get_provider(), 'model' => $client->get_model()]);
        $trace->output(get_string('bank_generating_preview', 'local_ai_yared_course'));
        $trace->finished();

        // Results.
        if ($result['created'] > 0) {
            echo $OUTPUT->notification(get_string('created', 'local_ai_yared_course', (object) [
                'count' => $result['created'],
                'category' => format_string($category->name, true, ['context' => $context]),
            ]), 'success');
            if (!empty($data->draft)) {
                echo $OUTPUT->notification(get_string('saveddraft', 'local_ai_yared_course'), 'info');
            }
        }
        if ($result['failed'] > 0) {
            echo $OUTPUT->notification(get_string('failed', 'local_ai_yared_course', $result['failed']), 'warning');
        }
        if ($result['created'] < $total) {
            echo $OUTPUT->notification(get_string('fewer', 'local_ai_yared_course', (object) [
                'created' => $result['created'],
                'requested' => $total,
            ]), 'warning');
        }
        $warnings = array_merge($generator->get_warnings(), $imagewarnings, $result['warnings'] ?? []);
        foreach ($warnings as $warning) {
            echo $OUTPUT->notification($warning, 'warning');
        }
        if ($mathmode && $result['created'] > 0 && !question_saver::math_filter_active($context)) {
            echo $OUTPUT->notification(get_string('warn_mathjax', 'local_ai_yared_course'), 'warning');
        }
        $fallbacks = $client->get_fallback_messages();
        if ($fallbacks) {
            echo $OUTPUT->notification(get_string('warn_fallback', 'local_ai_yared_course',
                s(implode(' | ', $fallbacks))), 'warning');
        }

        // Preview table.
        $table = new html_table();
        $table->head = [
            '#',
            get_string('col_question', 'local_ai_yared_course'),
            get_string('col_type', 'local_ai_yared_course'),
            get_string('col_answer', 'local_ai_yared_course'),
        ];
        $table->attributes['class'] = 'table table-sm generaltable';
        foreach ($questions as $i => $q) {
            $typelabel = get_string('qtype_' . $q['type'], 'local_ai_yared_course');
            $questionhtml = question_saver::preview_question($q, $context);
            $answerhtml = question_saver::preview_answer($q, $context);
            $table->data[] = [$i + 1, $questionhtml, $typelabel, $answerhtml];
        }
        echo $OUTPUT->heading(get_string('preview', 'local_ai_yared_course'), 3);
        echo html_writer::table($table);

        $bankurl = new moodle_url('/question/edit.php', [
            'courseid' => $course->id,
            'cat' => $category->id . ',' . $category->contextid,
        ]);
        $quizurl = new moodle_url('/local/ai_yared_course/quiz.php', [
            'id' => $course->id,
            'categoryid' => $category->id,
        ]);
        echo html_writer::div(
            $OUTPUT->single_button($bankurl, get_string('gotobank', 'local_ai_yared_course'), 'get', ['type' => 'primary']) .
            $OUTPUT->single_button($quizurl, get_string('quiz_wizard_frombank', 'local_ai_yared_course'), 'get',
                ['type' => 'secondary']) .
            $OUTPUT->single_button($PAGE->url, get_string('generatemore', 'local_ai_yared_course'), 'get'),
            'd-flex gap-2 mb-3 flex-wrap'
        );
        $PAGE->requires->js_init_code(
            'document.querySelectorAll(".aiyared-bank-server-wait").forEach(function(el){el.style.display="none";});'
        );
        echo $OUTPUT->footer();
        exit;
    } catch (moodle_exception $e) {
        if ($trace) {
            $trace->finished();
        }
        \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::BANK, (int) $course->id,
            '', 0, ['mode' => (string) $mode], 'error', $e->getMessage());
        echo $OUTPUT->notification($e->getMessage(), 'error');
    }
}

$modeheading = get_string('mode_' . $mode . '_title', 'local_ai_yared_course');
$modeintro = get_string('bank_mode_' . $mode . '_pageintro', 'local_ai_yared_course');
echo html_writer::div(
    html_writer::link(new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id]),
        get_string('bank_mode_back', 'local_ai_yared_course'),
        ['class' => 'btn btn-sm btn-outline-secondary mb-3']) .
    html_writer::tag('h2', $modeheading, ['class' => 'h3 mb-1']) .
    html_writer::tag('p', $modeintro, ['class' => 'text-muted']),
    'aiyared-mode-form-header'
);

$mform->display();

// Waiting overlay: it appears as soon as the teacher submits the form, before the server answers.
echo html_writer::start_div('yared-bank-wait', [
    'id' => 'yared-bank-wait',
    'hidden' => 'hidden',
    'role' => 'dialog',
    'aria-modal' => 'true',
    'aria-labelledby' => 'yared-bank-wait-title',
    'aria-describedby' => 'yared-bank-wait-intro',
    'aria-hidden' => 'true',
    'tabindex' => '-1',
]);
echo html_writer::start_div('yared-bank-wait-card');
echo html_writer::div('', 'yared-bank-wait-spinner', ['aria-hidden' => 'true']);
echo html_writer::tag('h2', get_string('bank_generating_title', 'local_ai_yared_course'),
    ['id' => 'yared-bank-wait-title', 'class' => 'h4 mb-2']);
echo html_writer::tag('p', get_string('bank_generating_intro', 'local_ai_yared_course'),
    ['id' => 'yared-bank-wait-intro', 'class' => 'text-muted']);
echo html_writer::tag('p', get_string('bank_generating_topics', 'local_ai_yared_course'),
    ['id' => 'yared-bank-wait-message', 'class' => 'yared-bank-wait-message', 'aria-live' => 'polite']);
echo html_writer::div(
    html_writer::span(get_string('bank_generating_elapsed', 'local_ai_yared_course'), 'yared-bank-wait-elapsed-label') .
    ' ' . html_writer::tag('strong', '00:00', ['id' => 'yared-bank-wait-time']),
    'yared-bank-wait-elapsed'
);
echo html_writer::end_div();
echo html_writer::end_div();

$waitmessages = [
    get_string('bank_generating_topics', 'local_ai_yared_course'),
    get_string('bank_generating_ai', 'local_ai_yared_course'),
    get_string('bank_generating_review', 'local_ai_yared_course'),
    get_string('bank_generating_images', 'local_ai_yared_course'),
    get_string('bank_generating_saving', 'local_ai_yared_course'),
    get_string('bank_generating_preview', 'local_ai_yared_course'),
];
$jsonmessages = json_encode($waitmessages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$waitjs = <<<JS
(function() {
    'use strict';

    function init() {
        // A previous error page may have left the server-side waiting panel visible.
        document.querySelectorAll('.aiyared-bank-server-wait').forEach(function(el) {
            el.style.display = 'none';
        });

        var button = document.getElementById('id_submitbutton');
        var form = button ? button.closest('form') : document.querySelector('form.mform');
        if (!button && form) {
            button = form.querySelector('button[type="submit"], input[type="submit"]');
        }
        var overlay = document.getElementById('yared-bank-wait');
        if (!form || !button || !overlay) {
            return;
        }

        var message = document.getElementById('yared-bank-wait-message');
        var time = document.getElementById('yared-bank-wait-time');
        var messages = {$jsonmessages};
        var started = 0;
        var tick = null;
        var rotate = null;
        var index = 0;
        var submitting = false;

        function pad(value) {
            return (value < 10 ? '0' : '') + value;
        }

        function updateTime() {
            if (!started || !time) {
                return;
            }
            var elapsed = Math.max(0, Math.floor((Date.now() - started) / 1000));
            var hours = Math.floor(elapsed / 3600);
            var minutes = Math.floor((elapsed % 3600) / 60);
            var seconds = elapsed % 60;
            time.textContent = (hours > 0 ? hours + ':' : '') + pad(minutes) + ':' + pad(seconds);
        }

        function hide() {
            submitting = false;
            overlay.hidden = true;
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('yared-bank-waiting');
            if (tick) {
                window.clearInterval(tick);
                tick = null;
            }
            if (rotate) {
                window.clearInterval(rotate);
                rotate = null;
            }
            button.removeAttribute('aria-disabled');
        }

        function show() {
            if (submitting) {
                return;
            }
            submitting = true;
            index = 0;
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('yared-bank-waiting');
            if (message) {
                message.textContent = messages[0];
            }
            started = Date.now();
            updateTime();
            tick = window.setInterval(updateTime, 1000);
            rotate = window.setInterval(function() {
                index = (index + 1) % messages.length;
                if (message) {
                    message.textContent = messages[index];
                }
            }, 5000);
            button.setAttribute('aria-disabled', 'true');
            try {
                overlay.focus();
            } catch (e) {
                // Ignore focus errors.
            }
        }

        form.addEventListener('submit', function(event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                return;
            }
            show();
        });
        form.addEventListener('invalid', hide, true);
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                hide();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
$PAGE->requires->js_init_code($waitjs);
echo $OUTPUT->footer();
