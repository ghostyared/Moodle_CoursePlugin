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
 * Class presentation ("deck") of a topic: validation of the AI answer and the pictures.
 *
 * A deck is plain data. It is turned into a PowerPoint file (see pptx_export) that is published in the course as an
 * ordinary file resource, so the teacher edits or replaces it with PowerPoint, Microsoft 365 or Google Slides.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slides {

    /** Maximum number of slides in a deck. */
    public const MAX_SLIDES = 24;

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
     * @param int $max Maximum number of pictures.
     * @return array [deck, pictures (file name => bytes), error message ('' when nothing went wrong)].
     */
    public static function attach_images(array $deck, int $max = 5): array {
        $pictures = [];
        if (!commons_search::is_enabled()) {
            return [$deck, $pictures, ''];
        }
        $search = new commons_search();
        $error = '';
        foreach ($deck['slides'] as $i => $slide) {
            if (count($pictures) >= $max) {
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
                $filename = 'img-' . substr(sha1($fetched['data']), 0, 10) . '.' .
                    strtolower(pathinfo($fetched['filename'], PATHINFO_EXTENSION));
                $pictures[$filename] = $fetched['data'];
                $deck['slides'][$i]['image'] = ['file' => $filename, 'credit' => commons_search::credit($candidate),
                    'alt' => self::clean($candidate['title'] ?? '', 150)];
                $error = '';
                break;
            }
        }
        return [$deck, $pictures, $error];
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
}
