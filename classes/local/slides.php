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

defined('MOODLE_INTERNAL') || die();

/**
 * Class presentation ("deck") of a topic: validation, editing, storage and the HTML viewer.
 *
 * A deck is plain data (JSON) that lives in the course context file area "presentation", so it is removed with the
 * course and needs no database table. The viewer is a page of the plugin served by Moodle with the course access rules;
 * nothing is sent to any external service.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slides {

    /** File area of the decks. */
    public const AREA = 'presentation';

    /** Name of the file that holds the deck data. */
    public const DECK = 'deck.json';

    /** Maximum number of slides in a deck. */
    public const MAX_SLIDES = 24;

    /** Maximum size of the deck data in bytes. */
    public const MAX_BYTES = 700000;

    /** Slide layouts. */
    public const LAYOUTS = ['title', 'bullets', 'example', 'summary'];

    /**
     * Number of slides and bullets asked from the AI for an age.
     *
     * @param int $age Age in years.
     * @return array slides, bullets, words
     */
    public static function size_rules(int $age): array {
        if ($age <= 8) {
            return ['slides' => 6, 'bullets' => 3, 'words' => 8];
        }
        if ($age <= 12) {
            return ['slides' => 8, 'bullets' => 4, 'words' => 12];
        }
        if ($age <= 17) {
            return ['slides' => 10, 'bullets' => 4, 'words' => 14];
        }
        return ['slides' => 12, 'bullets' => 5, 'words' => 16];
    }

    /**
     * Clean a plain text value: no tags, collapsed white space, limited length.
     *
     * @param mixed $value Value.
     * @param int $max Maximum characters.
     * @param bool $multiline Whether line breaks are kept.
     * @return string
     */
    public static function clean($value, int $max, bool $multiline = false): string {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        $text = $multiline ? preg_replace('/[ \t]+/u', ' ', $text) : preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * A blank slide.
     *
     * @param string $layout Layout.
     * @return array
     */
    public static function blank(string $layout = 'bullets'): array {
        return ['layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'bullets', 'title' => '', 'text' => '',
            'bullets' => [], 'notes' => '', 'image_query' => '', 'emoji' => '', 'figure_svg' => null, 'image' => null];
    }

    /**
     * Clean one slide of untrusted data (AI answer or form).
     *
     * @param array $data Raw slide.
     * @param bool $allowfigures Whether a "figure" description is drawn.
     * @return array|null The slide, or null when it has no content at all.
     */
    public static function clean_slide(array $data, bool $allowfigures = false): ?array {
        $slide = self::blank((string) ($data['layout'] ?? 'bullets'));
        $slide['title'] = self::clean($data['title'] ?? '', 120);
        $slide['text'] = self::clean($data['text'] ?? '', 400);
        $slide['notes'] = self::clean($data['notes'] ?? '', 700, true);
        $slide['image_query'] = self::clean($data['image_query'] ?? '', 80);
        $slide['emoji'] = self::clean_emoji($data['emoji'] ?? '');
        $bullets = $data['bullets'] ?? [];
        if (is_string($bullets)) {
            $bullets = preg_split('/\R/u', $bullets);
        }
        foreach ((array) $bullets as $bullet) {
            $line = self::clean($bullet, 200);
            $line = (string) preg_replace('/^\s*(?:\d+\s*[\.\)\-]|[-*•])\s*/u', '', $line);
            if ($line !== '') {
                $slide['bullets'][] = $line;
            }
        }
        $slide['bullets'] = array_slice($slide['bullets'], 0, 7);
        if ($allowfigures && !empty($data['figure']) && is_array($data['figure'])) {
            $alttext = self::clean($data['imagealt'] ?? $slide['title'], 200);
            $svg = figure_renderer::render($data['figure'], $alttext);
            if ($svg !== null) {
                $slide['figure_svg'] = $svg;
            }
        }
        if (!empty($data['figure_svg']) && is_string($data['figure_svg']) && $slide['figure_svg'] === null) {
            // A figure that this class drew earlier (kept in the deck data).
            $slide['figure_svg'] = $data['figure_svg'];
        }
        if (!empty($data['image']) && is_array($data['image']) && !empty($data['image']['file'])) {
            $slide['image'] = [
                'file' => clean_filename((string) $data['image']['file']),
                'credit' => self::clean($data['image']['credit'] ?? '', 400),
                'alt' => self::clean($data['image']['alt'] ?? '', 200),
            ];
        }
        if ($slide['title'] === '' && $slide['text'] === '' && !$slide['bullets'] && $slide['figure_svg'] === null
                && $slide['image'] === null) {
            return null;
        }
        return $slide;
    }

    /**
     * Clean the icon (one emoji) of a slide.
     *
     * @param mixed $value Raw value.
     * @return string The emoji, or '' when it is not a short symbol.
     */
    public static function clean_emoji($value): string {
        $value = trim(strip_tags((string) $value));
        if ($value === '' || \core_text::strlen($value) > 8 || preg_match('/[\p{L}\p{N}<>&"\'\s]/u', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * Attach free pictures from Wikimedia Commons to the slides that asked for one (best effort).
     *
     * It stops at the first network failure, so a server without internet access does not make the generation slow.
     * Slides keep working without a picture (they show their icon).
     *
     * @param array $deck Deck.
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param int $max Maximum number of pictures.
     * @return array [deck, number attached, error message ('' when nothing went wrong)].
     */
    public static function attach_images(array $deck, \context $context, int $itemid, int $max = 5): array {
        if (!commons_search::is_enabled()) {
            return [$deck, 0, ''];
        }
        $search = new commons_search();
        $attached = 0;
        $error = '';
        foreach ($deck['slides'] as $i => $slide) {
            if ($attached >= $max) {
                break;
            }
            $query = trim((string) ($slide['image_query'] ?? ''));
            if ($query === '' || !empty($slide['image']) || !empty($slide['figure_svg'])) {
                continue;
            }
            $results = $search->search($query, 4);
            if ($results === null) {
                $error = $search->get_error();
                break;
            }
            foreach (array_slice($results, 0, 3) as $candidate) {
                if (!commons_search::is_commons_image_url((string) ($candidate['url'] ?? ''))) {
                    continue;
                }
                $fetched = image_helper::fetch((string) $candidate['url']);
                if (!empty($fetched['error'])) {
                    $error = (string) $fetched['error'];
                    continue;
                }
                $filename = self::add_image($context, $itemid, $fetched['data'],
                    pathinfo($fetched['filename'], PATHINFO_EXTENSION));
                $deck['slides'][$i]['image'] = ['file' => $filename, 'credit' => commons_search::credit($candidate),
                    'alt' => self::clean($candidate['title'] ?? '', 150)];
                $attached++;
                $error = '';
                break;
            }
        }
        return [$deck, $attached, $error];
    }

    /**
     * Build a deck from the AI answer.
     *
     * @param array $data Decoded answer: {"title": "...", "slides": [...]}.
     * @param string $title Topic title (used when the answer has none).
     * @param string $language Content language code.
     * @param bool $allowfigures Whether figure descriptions are drawn.
     * @return array|null The deck, or null when no usable slide came back.
     */
    public static function from_ai(array $data, string $title, string $language, bool $allowfigures = false): ?array {
        $slides = [];
        foreach ((array) ($data['slides'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $slide = self::clean_slide($raw, $allowfigures);
            if ($slide !== null) {
                $slides[] = $slide;
            }
            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }
        }
        if (!$slides) {
            return null;
        }
        return ['version' => 1, 'title' => self::clean($data['title'] ?? $title, 150) ?: $title, 'language' => $language,
            'cmid' => 0, 'slides' => $slides];
    }

    /**
     * Apply the teacher's edits to a deck.
     *
     * Slides that were not sent, or whose "keep" box is not ticked, are removed. The "order" numbers reorder the slides.
     * Pictures and drawn figures of a slide are kept as they are.
     *
     * @param array $deck Deck.
     * @param array $post Posted slides: index => [keep, order, layout, title, text, bullets, notes, image_query].
     * @param bool $addblank Whether a blank slide is added at the end.
     * @return array Updated deck.
     */
    public static function apply_edits(array $deck, array $post, bool $addblank = false): array {
        $old = array_values((array) ($deck['slides'] ?? []));
        if ($post) {
            $kept = [];
            foreach ($old as $i => $slide) {
                $edit = $post[$i] ?? null;
                if (!is_array($edit) || empty($edit['keep'])) {
                    continue;
                }
                $clean = self::clean_slide($edit + ['figure_svg' => null]);
                $slide['layout'] = $clean['layout'] ?? $slide['layout'];
                $slide['title'] = $clean['title'] ?? '';
                $slide['text'] = $clean['text'] ?? '';
                $slide['bullets'] = $clean['bullets'] ?? [];
                $slide['notes'] = $clean['notes'] ?? '';
                $slide['image_query'] = $clean['image_query'] ?? '';
                $slide['emoji'] = $clean['emoji'] ?? '';
                $kept[] = [isset($edit['order']) && is_numeric($edit['order']) ? (int) $edit['order'] : $i + 1, $i, $slide];
            }
            usort($kept, function ($a, $b) {
                return [$a[0], $a[1]] <=> [$b[0], $b[1]];
            });
            $old = array_map(function ($row) {
                return $row[2];
            }, $kept);
        }
        if ($addblank && count($old) < self::MAX_SLIDES) {
            $old[] = self::blank();
        }
        $deck['slides'] = array_values($old);
        return $deck;
    }

    /**
     * Files (pictures) a deck refers to.
     *
     * @param array $deck Deck.
     * @return string[] File names.
     */
    public static function image_files(array $deck): array {
        $files = [];
        foreach ((array) ($deck['slides'] ?? []) as $slide) {
            if (!empty($slide['image']['file'])) {
                $files[] = (string) $slide['image']['file'];
            }
        }
        return array_values(array_unique($files));
    }

    /**
     * Create the id of a new deck.
     *
     * @return int
     */
    public static function new_itemid(): int {
        return random_int(100000000, 2000000000);
    }

    /**
     * File record of a stored file of a deck.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param string $filename File name.
     * @return array
     */
    protected static function record(\context $context, int $itemid, string $filename): array {
        return ['contextid' => $context->id, 'component' => 'local_ai_yared_course', 'filearea' => self::AREA,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename];
    }

    /**
     * Store a deck.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param array $deck Deck.
     * @throws \moodle_exception When the data is too big.
     */
    public static function save(\context $context, int $itemid, array $deck): void {
        $json = json_encode($deck, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new \moodle_exception('presentation_toobig', 'local_ai_yared_course');
        }
        $fs = get_file_storage();
        $record = self::record($context, $itemid, self::DECK);
        $old = $fs->get_file($record['contextid'], $record['component'], $record['filearea'], $itemid, '/', self::DECK);
        if ($old) {
            $old->delete();
        }
        $fs->create_file_from_string($record, $json);
    }

    /**
     * Load a deck.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @return array|null The deck, or null when it does not exist.
     */
    public static function load(\context $context, int $itemid): ?array {
        $file = get_file_storage()->get_file($context->id, 'local_ai_yared_course', self::AREA, $itemid, '/', self::DECK);
        if (!$file) {
            return null;
        }
        $data = json_decode($file->get_content(), true);
        if (!is_array($data) || !isset($data['slides']) || !is_array($data['slides'])) {
            return null;
        }
        return $data;
    }

    /**
     * Store a picture of a deck.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param string $data Picture bytes.
     * @param string $extension File extension (jpg, png, webp).
     * @return string File name.
     */
    public static function add_image(\context $context, int $itemid, string $data, string $extension): string {
        $extension = in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'webp'], true) ? strtolower($extension) : 'jpg';
        $filename = 'img-' . substr(sha1($data), 0, 10) . '.' . $extension;
        $fs = get_file_storage();
        if (!$fs->file_exists($context->id, 'local_ai_yared_course', self::AREA, $itemid, '/', $filename)) {
            $fs->create_file_from_string(self::record($context, $itemid, $filename), $data);
        }
        return $filename;
    }

    /**
     * Bytes of a picture of a deck.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param string $filename File name.
     * @return string Bytes, or '' when the file does not exist.
     */
    public static function image_bytes(\context $context, int $itemid, string $filename): string {
        $file = get_file_storage()->get_file($context->id, 'local_ai_yared_course', self::AREA, $itemid, '/',
            clean_filename($filename));
        return $file ? (string) $file->get_content() : '';
    }

    /**
     * Delete the pictures that the deck no longer uses.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param array $deck Deck.
     */
    public static function prune_images(\context $context, int $itemid, array $deck): void {
        $used = self::image_files($deck);
        $files = get_file_storage()->get_area_files($context->id, 'local_ai_yared_course', self::AREA, $itemid, 'filename',
            false);
        foreach ($files as $file) {
            if ($file->get_filename() !== self::DECK && !in_array($file->get_filename(), $used, true)) {
                $file->delete();
            }
        }
    }

    /**
     * URL of the viewer.
     *
     * @param int $courseid Course id.
     * @param int $itemid Deck id.
     * @param bool $edit Whether it is the editor.
     * @return \moodle_url
     */
    public static function url(int $courseid, int $itemid, bool $edit = false): \moodle_url {
        $params = ['id' => $courseid, 'p' => $itemid];
        if ($edit) {
            $params['edit'] = 1;
        }
        return new \moodle_url('/local/ai_yared_course/presentation.php', $params);
    }

    /**
     * URL of a stored picture.
     *
     * @param \context $context Course context.
     * @param int $itemid Deck id.
     * @param string $filename File name.
     * @return string
     */
    public static function image_url(\context $context, int $itemid, string $filename): string {
        return \moodle_url::make_pluginfile_url($context->id, 'local_ai_yared_course', self::AREA, $itemid, '/',
            $filename)->out(false);
    }

    /**
     * HTML of the viewer: all the slides, a toolbar and the teacher notes.
     *
     * @param array $deck Deck.
     * @param callable|null $imageurl Function (file name) => URL of a picture; null when pictures are not available.
     * @param string $id Element id (several viewers can share a page).
     * @return string HTML
     */
    public static function render_viewer(array $deck, ?callable $imageurl = null, string $id = 'aiyared-deck'): string {
        $slides = array_values((array) ($deck['slides'] ?? []));
        $html = \html_writer::start_div('aiyared-deck', ['id' => $id, 'tabindex' => '0']);
        $html .= \html_writer::start_div('aiyared-deck-stage');
        foreach ($slides as $i => $slide) {
            $layout = in_array($slide['layout'] ?? '', self::LAYOUTS, true) ? $slide['layout'] : 'bullets';
            $media = '';
            if (!empty($slide['figure_svg'])) {
                $media = \html_writer::tag('figure', \html_writer::empty_tag('img', [
                    'src' => 'data:image/svg+xml;base64,' . base64_encode((string) $slide['figure_svg']),
                    'alt' => s((string) ($slide['title'] ?? ''))]), ['class' => 'aiyared-slide-media']);
            } else if (!empty($slide['image']['file']) && $imageurl) {
                $credit = (string) ($slide['image']['credit'] ?? '');
                $media = \html_writer::tag('figure', \html_writer::empty_tag('img', [
                    'src' => $imageurl((string) $slide['image']['file']), 'alt' => s((string) ($slide['image']['alt'] ?? ''))]) .
                    ($credit !== '' ? \html_writer::tag('figcaption', s($credit), ['class' => 'aiyared-slide-credit']) : ''),
                    ['class' => 'aiyared-slide-media']);
            }
            if ($media === '' && !empty($slide['emoji'])) {
                $media = \html_writer::div(s((string) $slide['emoji']), 'aiyared-slide-media aiyared-slide-icon',
                    ['aria-hidden' => 'true']);
            }
            $body = \html_writer::tag('h2', s((string) ($slide['title'] ?? '')), ['class' => 'aiyared-slide-title']);
            if (!empty($slide['text'])) {
                $body .= \html_writer::tag('p', s((string) $slide['text']), ['class' => 'aiyared-slide-text']);
            }
            if (!empty($slide['bullets'])) {
                $items = '';
                foreach ($slide['bullets'] as $bullet) {
                    $items .= \html_writer::tag('li', s((string) $bullet));
                }
                $body .= \html_writer::tag('ul', $items, ['class' => 'aiyared-slide-bullets']);
            }
            $html .= \html_writer::tag('section',
                \html_writer::div($body, 'aiyared-slide-body') . $media,
                ['class' => 'aiyared-slide aiyared-layout-' . $layout . ($media !== '' ? ' has-media' : '') .
                    ($i === 0 ? ' is-active' : ''), 'data-notes' => s((string) ($slide['notes'] ?? ''))]);
        }
        $html .= \html_writer::end_div();
        $html .= \html_writer::start_div('aiyared-deck-bar');
        $html .= \html_writer::tag('button', '◀ ' . get_string('presentation_prev', 'local_ai_yared_course'),
            ['type' => 'button', 'class' => 'btn btn-secondary btn-sm', 'data-act' => 'prev']);
        $html .= \html_writer::span('1 / ' . max(1, count($slides)), 'aiyared-deck-count');
        $html .= \html_writer::tag('button', get_string('presentation_next', 'local_ai_yared_course') . ' ▶',
            ['type' => 'button', 'class' => 'btn btn-secondary btn-sm', 'data-act' => 'next']);
        $html .= \html_writer::tag('button', '⛶ ' . get_string('presentation_fullscreen', 'local_ai_yared_course'),
            ['type' => 'button', 'class' => 'btn btn-primary btn-sm ms-auto', 'data-act' => 'full']);
        $html .= \html_writer::end_div();
        $html .= \html_writer::end_div();
        return $html;
    }

    /**
     * JavaScript of the viewer (navigation, full screen, swipe).
     *
     * @param string $id Element id of the viewer.
     * @return string
     */
    public static function viewer_js(string $id = 'aiyared-deck'): string {
        $js = <<<'JS'
(function() {
    var root = document.getElementById(__ID__);
    if (!root) { return; }
    var slides = Array.prototype.slice.call(root.querySelectorAll('.aiyared-slide'));
    var count = root.querySelector('.aiyared-deck-count');
    var current = 0;
    function show(index) {
        if (!slides.length) { return; }
        current = Math.max(0, Math.min(slides.length - 1, index));
        slides.forEach(function(slide, i) { slide.classList.toggle('is-active', i === current); });
        if (count) { count.textContent = (current + 1) + ' / ' + slides.length; }
        root.dispatchEvent(new CustomEvent('aiyared-slide', {detail: {index: current, notes: slides[current].getAttribute('data-notes') || ''}}));
    }
    function fullscreen() {
        var request = root.requestFullscreen || root.webkitRequestFullscreen;
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else if (request) {
            request.call(root);
        }
    }
    root.addEventListener('click', function(event) {
        var button = event.target.closest('[data-act]');
        if (!button) { return; }
        var act = button.getAttribute('data-act');
        if (act === 'prev') { show(current - 1); }
        if (act === 'next') { show(current + 1); }
        if (act === 'full') { fullscreen(); }
    });
    root.addEventListener('keydown', function(event) {
        if (event.target.closest('input, textarea, select')) { return; }
        var key = event.key;
        if (key === 'ArrowRight' || key === 'PageDown' || key === ' ') { show(current + 1); event.preventDefault(); }
        if (key === 'ArrowLeft' || key === 'PageUp') { show(current - 1); event.preventDefault(); }
        if (key === 'Home') { show(0); event.preventDefault(); }
        if (key === 'End') { show(slides.length - 1); event.preventDefault(); }
        if (key === 'f' || key === 'F') { fullscreen(); }
    });
    var startx = null;
    root.addEventListener('touchstart', function(event) { startx = event.changedTouches[0].clientX; }, {passive: true});
    root.addEventListener('touchend', function(event) {
        if (startx === null) { return; }
        var dx = event.changedTouches[0].clientX - startx;
        startx = null;
        if (Math.abs(dx) > 40) { show(current + (dx < 0 ? 1 : -1)); }
    }, {passive: true});
    show(0);
})();
JS;
        return str_replace('__ID__', json_encode($id), $js);
    }

    /**
     * Form fields to edit every slide (the same markup is used by the preview and by the editor).
     *
     * @param array $deck Deck.
     * @param callable|null $extra Function (index, slide) => HTML added at the end of each slide card.
     * @return string HTML
     */
    public static function editor_fields(array $deck, ?callable $extra = null): string {
        $component = 'local_ai_yared_course';
        $layouts = [];
        foreach (self::LAYOUTS as $layout) {
            $layouts[$layout] = get_string('slide_layout_' . $layout, $component);
        }
        $html = '';
        foreach (array_values((array) ($deck['slides'] ?? [])) as $i => $slide) {
            $name = function (string $field) use ($i): string {
                return "aiys_{$i}_{$field}";
            };
            $field = function (string $field, string $label, string $control) use ($i): string {
                return \html_writer::div(\html_writer::label($label, "aiyared-s$i-$field", false,
                    ['class' => 'form-label small mb-0']) . $control, 'mb-2');
            };
            $header = \html_writer::div(
                \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => $name('keep'), 'value' => 1,
                    'checked' => 'checked', 'id' => "aiyared-s$i-keep", 'class' => 'form-check-input mt-0']) .
                \html_writer::label(get_string('slide_n', $component, $i + 1), "aiyared-s$i-keep", false,
                    ['class' => 'fw-semibold mb-0']) .
                \html_writer::span(get_string('slide_f_order', $component), 'ms-auto small text-muted') .
                \html_writer::empty_tag('input', ['type' => 'number', 'name' => $name('order'), 'value' => $i + 1,
                    'min' => 1, 'max' => self::MAX_SLIDES, 'class' => 'form-control form-control-sm',
                    'style' => 'width:5rem', 'id' => "aiyared-s$i-order"]),
                'd-flex align-items-center gap-2 mb-2');
            $body = $field('layout', get_string('slide_f_layout', $component),
                \html_writer::select($layouts, $name('layout'), $slide['layout'] ?? 'bullets', false,
                    ['id' => "aiyared-s$i-layout", 'class' => 'form-select form-control form-control-sm'])) .
                $field('emoji', get_string('slide_f_emoji', $component), \html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => $name('emoji'), 'value' => (string) ($slide['emoji'] ?? ''),
                    'id' => "aiyared-s$i-emoji", 'maxlength' => 8, 'class' => 'form-control form-control-sm',
                    'style' => 'width:6rem'])) .
                $field('title', get_string('slide_f_title', $component), \html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => $name('title'), 'value' => (string) ($slide['title'] ?? ''),
                    'id' => "aiyared-s$i-title", 'maxlength' => 120, 'class' => 'form-control'])) .
                $field('text', get_string('slide_f_text', $component), \html_writer::tag('textarea',
                    s((string) ($slide['text'] ?? '')), ['name' => $name('text'), 'rows' => 2,
                        'id' => "aiyared-s$i-text", 'class' => 'form-control'])) .
                $field('bullets', get_string('slide_f_bullets', $component), \html_writer::tag('textarea',
                    s(implode("\n", (array) ($slide['bullets'] ?? []))), ['name' => $name('bullets'), 'rows' => 4,
                        'id' => "aiyared-s$i-bullets", 'class' => 'form-control'])) .
                $field('notes', get_string('slide_f_notes', $component), \html_writer::tag('textarea',
                    s((string) ($slide['notes'] ?? '')), ['name' => $name('notes'), 'rows' => 2,
                        'id' => "aiyared-s$i-notes", 'class' => 'form-control'])) .
                \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name('image_query'),
                    'value' => (string) ($slide['image_query'] ?? '')]);
            $html .= \html_writer::div($header . $body . ($extra ? $extra($i, $slide) : ''),
                'aiyared-slide-card', ['data-index' => $i]);
        }
        return $html;
    }

    /**
     * Whether the current user may open a deck.
     *
     * Teachers always can. Students can only when the activity that links to the presentation is visible to them, so a
     * hidden topic (the default) keeps its presentation hidden too.
     *
     * @param \stdClass $course Course.
     * @param \context $context Course context.
     * @param array $deck Deck.
     * @return bool
     */
    public static function can_view(\stdClass $course, \context $context, array $deck): bool {
        if (self::can_edit($context)) {
            return true;
        }
        $cmid = (int) ($deck['cmid'] ?? 0);
        if ($cmid <= 0) {
            return false;
        }
        $cms = get_fast_modinfo($course)->cms;
        return isset($cms[$cmid]) && $cms[$cmid]->uservisible;
    }

    /**
     * Whether the current user may edit decks of a course.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function can_edit(\context $context): bool {
        return has_capability('local/ai_yared_course:use', $context)
            && has_capability('moodle/course:manageactivities', $context);
    }
}
