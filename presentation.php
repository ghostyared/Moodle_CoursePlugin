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
 * Class presentation of a topic: viewer (students and teachers) and editor (teachers).
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_yared_course\local\slides;

$courseid = required_param('id', PARAM_INT);
$itemid = required_param('p', PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);

$deck = slides::load($context, $itemid);
$url = slides::url((int) $course->id, $itemid, (bool) $edit);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading(format_string($course->fullname));

if (!$deck || !slides::can_view($course, $context, $deck)) {
    $PAGE->set_title(get_string('presentation_missing_title', 'local_ai_yared_course'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('presentation_missing', 'local_ai_yared_course'), 'warning');
    echo $OUTPUT->single_button(new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('gotocourse', 'local_ai_yared_course'), 'get');
    echo $OUTPUT->footer();
    exit;
}

$canedit = slides::can_edit($context);
$title = (string) ($deck['title'] ?? '');
$PAGE->set_title(get_string('presentation_title', 'local_ai_yared_course', s($title)));
$imageurl = function (string $file) use ($context, $itemid): string {
    return slides::image_url($context, $itemid, $file);
};

// Download as PowerPoint (opens in PowerPoint, Google Slides and LibreOffice).
if ($action === 'pptx') {
    $content = \local_ai_yared_course\local\pptx_export::build($deck, function (string $file) use ($context, $itemid): string {
        return slides::image_bytes($context, $itemid, $file);
    });
    $name = clean_filename(preg_replace('/\s+/u', '_', $title) ?: 'presentacion') . '.pptx';
    send_file($content, $name, 0, 0, true, true,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation');
    exit;
}

// Save the edits (or add a blank slide).
if ($edit && $canedit && ($action === 'save' || $action === 'add')) {
    require_sesskey();
    // Flat field names (aiys_<n>_<field>): optional_param_array() cannot read nested arrays.
    $posted = [];
    foreach (array_keys((array) $deck['slides']) as $i) {
        $row = [];
        foreach (['keep', 'order', 'layout', 'title', 'text', 'bullets', 'notes', 'emoji', 'image_query'] as $field) {
            $row[$field] = optional_param("aiys_{$i}_{$field}", '', PARAM_RAW);
        }
        $posted[$i] = $row;
    }
    $deck = slides::apply_edits($deck, $posted, $action === 'add');
    $newtitle = trim(optional_param('deck_title', '', PARAM_TEXT));
    if ($newtitle !== '') {
        $deck['title'] = slides::clean($newtitle, 150);
    }
    slides::save($context, $itemid, $deck);
    slides::prune_images($context, $itemid, $deck);
    redirect(slides::url((int) $course->id, $itemid, true),
        get_string($action === 'add' ? 'presentation_added' : 'presentation_saved', 'local_ai_yared_course'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(s($title));

$pptxlink = html_writer::link(new moodle_url($url, ['action' => 'pptx', 'edit' => 0]),
    '⬇ ' . get_string('presentation_pptx', 'local_ai_yared_course'), ['class' => 'btn btn-outline-primary btn-sm ms-auto']);
$tabs = html_writer::div($pptxlink, 'd-flex gap-2 mb-3');
if ($canedit) {
    $tabs = html_writer::div(
        html_writer::link(slides::url((int) $course->id, $itemid), get_string('presentation_view', 'local_ai_yared_course'),
            ['class' => 'btn btn-' . ($edit ? 'outline-secondary' : 'secondary') . ' btn-sm']) .
        html_writer::link(slides::url((int) $course->id, $itemid, true),
            get_string('presentation_edit', 'local_ai_yared_course'),
            ['class' => 'btn btn-' . ($edit ? 'secondary' : 'outline-secondary') . ' btn-sm']) . $pptxlink,
        'd-flex gap-2 mb-3');
}
echo $tabs;

if (!$edit || !$canedit) {
    echo html_writer::div(get_string('presentation_help', 'local_ai_yared_course'), 'text-muted small mb-2');
    echo html_writer::div(get_string('presentation_pptx_help', 'local_ai_yared_course'), 'text-muted small mb-2');
    echo slides::render_viewer($deck, $imageurl);
    if ($canedit) {
        echo html_writer::div(html_writer::tag('strong', get_string('presentation_notes', 'local_ai_yared_course') . ': ') .
            html_writer::span('', '', ['id' => 'aiyared-deck-notes']), 'aiyared-deck-notes alert alert-light mt-2');
        $PAGE->requires->js_init_code(slides::viewer_js() . <<<'JS'
(function() {
    var root = document.getElementById('aiyared-deck');
    var notes = document.getElementById('aiyared-deck-notes');
    if (root && notes) {
        root.addEventListener('aiyared-slide', function(event) { notes.textContent = event.detail.notes || '—'; });
        var first = root.querySelector('.aiyared-slide');
        notes.textContent = (first && first.getAttribute('data-notes')) || '—';
    }
})();
JS
        );
    } else {
        $PAGE->requires->js_init_code(slides::viewer_js());
    }
    echo $OUTPUT->footer();
    exit;
}

// ---- Editor ----
echo $OUTPUT->notification(get_string('presentation_edit_intro', 'local_ai_yared_course'), 'info');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'id' => 'aiyared-deck-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::div(html_writer::label(get_string('presentation_deck_title', 'local_ai_yared_course'), 'aiyared-deck-title',
    false, ['class' => 'form-label']) . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'deck_title',
    'id' => 'aiyared-deck-title', 'value' => $title, 'maxlength' => 150, 'class' => 'form-control']), 'mb-3');

$imagesenabled = \local_ai_yared_course\local\commons_search::is_enabled();
$extra = function (int $i, array $slide) use ($imagesenabled, $imageurl): string {
    $component = 'local_ai_yared_course';
    $current = '';
    if (!empty($slide['figure_svg'])) {
        $current = html_writer::div(get_string('slide_image_figure', $component), 'small text-muted');
    } else if (!empty($slide['image']['file'])) {
        $current = html_writer::empty_tag('img', ['src' => $imageurl((string) $slide['image']['file']), 'alt' => '',
            'class' => 'aiyared-slide-thumb']) .
            html_writer::div(s((string) ($slide['image']['credit'] ?? '')), 'small text-muted');
    }
    $html = html_writer::div($current, 'aiyared-slide-current', ['data-index' => $i]);
    $html .= html_writer::tag('button', get_string('slide_image_remove', $component), ['type' => 'button',
        'class' => 'btn btn-outline-danger btn-sm aiyared-slide-remove mb-2' .
            (empty($slide['image']['file']) ? ' d-none' : ''), 'data-index' => $i]);
    $upload = html_writer::div(
        html_writer::label(get_string('slide_image_upload', $component), "aiyared-s$i-file", false,
            ['class' => 'form-label small mb-0']) .
        html_writer::div(html_writer::empty_tag('input', ['type' => 'file', 'id' => "aiyared-s$i-file",
            'accept' => 'image/jpeg,image/png,image/webp', 'class' => 'form-control form-control-sm aiyared-slide-file']) .
            html_writer::tag('button', get_string('slide_image_upload_btn', $component), ['type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm aiyared-slide-upload']), 'd-flex gap-2'),
        'mt-2');
    $status = html_writer::div('', 'small text-muted aiyared-slide-status', ['aria-live' => 'polite']);
    if (!$imagesenabled) {
        return html_writer::div($html . $upload . $status, 'mt-2');
    }
    $search = html_writer::div(
        html_writer::empty_tag('input', ['type' => 'text', 'class' => 'form-control aiyared-slide-query',
            'value' => (string) ($slide['image_query'] ?? ''), 'placeholder' => get_string('import_ref_query', $component)]) .
        html_writer::tag('button', get_string('import_ref_search', $component), ['type' => 'button',
            'class' => 'btn btn-secondary aiyared-slide-search']), 'd-flex gap-2 mb-2') .
        html_writer::div('', 'aiyared-ref-results aiyared-slide-results');
    $html .= html_writer::tag('details', html_writer::tag('summary', get_string('slide_image_search', $component)) .
        html_writer::div($search, 'mt-2'), ['class' => 'aiyared-slide-imagebox', 'data-index' => $i]);
    return html_writer::div($html . $upload . $status, 'mt-2');
};
echo slides::editor_fields($deck, $extra);

echo html_writer::start_div('d-flex flex-wrap gap-2 my-3');
echo html_writer::tag('button', get_string('presentation_save', 'local_ai_yared_course'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'save', 'class' => 'btn btn-primary']);
echo html_writer::tag('button', get_string('slide_add', 'local_ai_yared_course'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'add', 'class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

{
    $strings = [
        'searching' => get_string('import_ref_searching', 'local_ai_yared_course'),
        'found' => get_string('import_ref_found', 'local_ai_yared_course', '__N__'),
        'none' => get_string('import_ref_none', 'local_ai_yared_course'),
        'use' => get_string('slide_image_use', 'local_ai_yared_course'),
        'saving' => get_string('slide_image_saving', 'local_ai_yared_course'),
        'saved' => get_string('slide_image_saved', 'local_ai_yared_course'),
        'error' => get_string('slide_image_error', 'local_ai_yared_course'),
        'nofile' => get_string('slide_image_notchosen', 'local_ai_yared_course'),
    ];
    $config = ['ajax' => (new moodle_url('/local/ai_yared_course/presentation_ajax.php'))->out(false),
        'id' => (int) $course->id, 'p' => $itemid, 'sesskey' => sesskey(), 'strings' => $strings];
    $js = <<<'JS'
(function(config) {
    var form = document.getElementById('aiyared-deck-form');
    if (!form) { return; }
    function post(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function(key) { body.append(key, params[key]); });
        body.append('id', config.id); body.append('p', config.p); body.append('sesskey', config.sesskey);
        return fetch(config.ajax, {method: 'POST', body: body, credentials: 'same-origin'}).then(function(r) { return r.json(); });
    }
    function box(el) { return el.closest('.aiyared-slide-card'); }
    function setCurrent(card, url, credit) {
        var current = card.querySelector('.aiyared-slide-current');
        var remove = card.querySelector('.aiyared-slide-remove');
        current.textContent = '';
        if (url) {
            var img = document.createElement('img');
            img.src = url; img.alt = ''; img.className = 'aiyared-slide-thumb';
            var cap = document.createElement('div');
            cap.className = 'small text-muted'; cap.textContent = credit || '';
            current.appendChild(img); current.appendChild(cap);
        }
        if (remove) { remove.classList.toggle('d-none', !url); }
    }
    form.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && event.target.classList.contains('aiyared-slide-query')) {
            event.preventDefault();
            box(event.target).querySelector('.aiyared-slide-search').click();
        }
    });
    form.addEventListener('click', function(event) {
        var target = event.target;
        var card = box(target);
        if (!card) { return; }
        var index = card.getAttribute('data-index');
        var status = card.querySelector('.aiyared-slide-status');
        var results = card.querySelector('.aiyared-slide-results');
        if (target.classList.contains('aiyared-slide-search')) {
            var query = card.querySelector('.aiyared-slide-query').value;
            status.textContent = config.strings.searching;
            results.textContent = '';
            if (!results) { return; }
            post({action: 'imgsearch', query: query}).then(function(data) {
                if (!data.ok) { status.textContent = data.error || config.strings.error; return; }
                if (!data.results.length) { status.textContent = config.strings.none; return; }
                status.textContent = config.strings.found.replace('__N__', data.results.length);
                data.results.forEach(function(item) {
                    var figure = document.createElement('div');
                    figure.className = 'aiyared-ref-item';
                    var img = document.createElement('img');
                    img.src = item.thumb; img.alt = item.title || ''; img.loading = 'lazy';
                    var caption = document.createElement('div');
                    caption.className = 'aiyared-ref-caption';
                    caption.textContent = (item.title || '') + ' — ' + (item.license || '');
                    var button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-outline-primary btn-sm aiyared-slide-use';
                    button.textContent = config.strings.use;
                    button.candidate = item;
                    figure.appendChild(img); figure.appendChild(caption); figure.appendChild(button);
                    results.appendChild(figure);
                });
            }).catch(function() { status.textContent = config.strings.error; });
        }
        if (target.classList.contains('aiyared-slide-use')) {
            var item = target.candidate;
            status.textContent = config.strings.saving;
            post({action: 'setimage', index: index, imgurl: item.url, imgtitle: item.title || '', imgauthor: item.author || '',
                imglicense: item.license || '', imglicenseurl: item.licenseurl || '', imgpage: item.page || ''}).then(function(data) {
                if (!data.ok) { status.textContent = data.error || config.strings.error; return; }
                status.textContent = config.strings.saved;
                results.textContent = '';
                setCurrent(card, data.url, data.credit);
            }).catch(function() { status.textContent = config.strings.error; });
        }
        if (target.classList.contains('aiyared-slide-upload')) {
            var file = card.querySelector('.aiyared-slide-file');
            if (!file || !file.files.length) { status.textContent = config.strings.nofile; return; }
            var body = new FormData();
            body.append('action', 'upload'); body.append('index', index); body.append('id', config.id);
            body.append('p', config.p); body.append('sesskey', config.sesskey); body.append('file', file.files[0]);
            status.textContent = config.strings.saving;
            fetch(config.ajax, {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function(r) { return r.json(); }).then(function(data) {
                    if (!data.ok) { status.textContent = data.error || config.strings.error; return; }
                    status.textContent = config.strings.saved;
                    file.value = '';
                    setCurrent(card, data.url, data.credit);
                }).catch(function() { status.textContent = config.strings.error; });
        }
        if (target.classList.contains('aiyared-slide-remove')) {
            post({action: 'removeimage', index: index}).then(function(data) {
                if (data.ok) { setCurrent(card, '', ''); }
            });
        }
    });
})(__CONFIG__);
JS;
    $PAGE->requires->js_init_code(str_replace('__CONFIG__', json_encode($config), $js));
}
echo $OUTPUT->footer();
