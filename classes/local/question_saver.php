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

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

use core_question\local\bank\question_version_status;

/**
 * Saves generated questions into a Moodle question category.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_saver {

    /**
     * Save questions in the given category.
     *
     * @param array[] $questions Normalised questions from question_generator.
     * @param \stdClass $category Row of question_categories.
     * @param bool $draft Save as draft (needs review) instead of ready.
     * @return array ['created' => int, 'failed' => int, 'ids' => int[], 'warnings' => string[]]
     */
    public static function save(array $questions, \stdClass $category, bool $draft = false): array {
        global $USER;

        $created = 0;
        $failed = 0;
        $ids = [];
        $warnings = [];
        $status = $draft ? question_version_status::QUESTION_STATUS_DRAFT : question_version_status::QUESTION_STATUS_READY;

        foreach ($questions as $q) {
            try {
                $form = self::build_form($q, $category, $status, $warnings);
                $question = new \stdClass();
                $question->category = $category->id;
                $question->qtype = $q['type'];
                $question->createdby = $USER->id;
                $question->modifiedby = $USER->id;

                $saved = \question_bank::get_qtype($q['type'])->save_question($question, $form);
                $ids[] = $saved->id;
                $created++;
            } catch (\Throwable $e) {
                debugging('local_ai_yared_course: could not save a question: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $failed++;
            }
        }

        return ['created' => $created, 'failed' => $failed, 'ids' => $ids, 'warnings' => $warnings];
    }

    /**
     * Build the form-like object that question_type::save_question() expects.
     *
     * @param array $q Normalised question.
     * @param \stdClass $category Category row.
     * @param string $status Question status.
     * @param string[] $warnings Image warnings collected while building the form (by reference).
     * @return \stdClass
     */
    protected static function build_form(array $q, \stdClass $category, string $status, array &$warnings): \stdClass {
        $math = !empty($q['math']);
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        // The name is the question itself (the last paragraph), not the reading passage before it.
        $paragraphs = preg_split('/\n\s*\n/u', trim($q['text']));
        $namesource = trim(strip_tags((string) preg_replace('/\[\[\d+\]\]/u', '____', (string) end($paragraphs))));
        $form->name = \core_text::substr($namesource !== '' ? $namesource : $q['text'], 0, 80);

        $questionhtml = self::html($q['text'], $math);
        $questionitemid = null;
        if (!empty($q['image'])) {
            $image = self::draft_image($q['image'], $warnings);
            if ($image !== null) {
                $questionhtml .= self::image_html($image['filename'], $q['imagealt'] ?? '', 'image_default_alt');
                $questionitemid = $image['itemid'];
            }
        } else if (!empty($q['figure_svg'])) {
            $image = self::draft_figure((string) $q['figure_svg'], $warnings);
            if ($image !== null) {
                $questionhtml .= self::image_html($image['filename'], $q['imagealt'] ?? '', 'image_default_alt');
                $questionitemid = $image['itemid'];
            }
        }
        $form->questiontext = self::field($questionhtml, $questionitemid);
        $form->generalfeedback = self::field($q['feedback'] !== '' ? self::html($q['feedback'], $math) : '');
        $form->defaultmark = 1;
        $form->status = $status;
        $form->idnumber = null;
        $form->qtype = $q['type'];

        if ($q['type'] === 'truefalse') {
            $form->penalty = 1;
            $form->correctanswer = $q['answer'] ? 1 : 0;
            $form->feedbacktrue = self::field('');
            $form->feedbackfalse = self::field('');
            return $form;
        }

        if ($q['type'] === 'shortanswer') {
            $form->penalty = 0.3333333;
            $form->usecase = 0;
            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];
            foreach ((array) ($q['answers'] ?? []) as $accepted) {
                $form->answer[] = (string) $accepted;
                $form->fraction[] = 1.0;
                $form->feedback[] = self::field('');
            }
            return $form;
        }

        if ($q['type'] === 'essay') {
            $form->penalty = 0;
            $form->responseformat = 'editor';
            $form->responserequired = 1;
            $form->responsefieldlines = 12;
            $form->minwordlimit = null;
            $form->maxwordlimit = null;
            $form->attachments = 0;
            $form->attachmentsrequired = 0;
            $form->maxbytes = 0;
            $form->filetypeslist = '';
            $form->graderinfo = self::field('');
            $form->responsetemplate = self::field('');
            return $form;
        }

        if (in_array($q['type'], ['gapselect', 'ddwtos'], true)) {
            // Missing words: the text carries [[n]] placeholders and each one points to the n-th choice.
            $form->penalty = 0.3333333;
            $form->shuffleanswers = 1;
            $form->shownumcorrect = 1;
            $form->correctfeedback = self::field('');
            $form->partiallycorrectfeedback = self::field('');
            $form->incorrectfeedback = self::field('');
            $used = array_count_values(exercise_extractor::placeholders((string) $q['text']));
            $form->choices = [];
            foreach (array_values($q['options']) as $index => $option) {
                $choice = ['answer' => trim(strip_tags((string) $option['text'])), 'choicegroup' => 1];
                if ($q['type'] === 'ddwtos') {
                    // A word used in several gaps can be dragged more than once.
                    $choice['infinite'] = ($used[$index + 1] ?? 0) > 1 ? 1 : 0;
                }
                $form->choices[] = $choice;
            }
            return $form;
        }

        $multiple = $q['type'] === 'multiselect';
        $correctcount = 0;
        foreach ($q['options'] as $option) {
            $correctcount += !empty($option['correct']) ? 1 : 0;
        }
        // Standard Moodle grade values for "several correct answers".
        $weights = [1 => 1.0, 2 => 0.5, 3 => 0.3333333, 4 => 0.25, 5 => 0.2, 6 => 0.1666667, 7 => 0.1428571, 8 => 0.125];
        $weight = $weights[max(1, min(8, $correctcount))];

        $form->penalty = 0.3333333;
        $form->single = $multiple ? 0 : 1;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->shownumcorrect = 1;
        $form->correctfeedback = self::field('');
        $form->partiallycorrectfeedback = self::field('');
        $form->incorrectfeedback = self::field('');
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($q['options'] as $option) {
            $answerhtml = self::html($option['text'], $math);
            $answeritemid = null;
            if (!empty($option['image'])) {
                $image = self::draft_image($option['image'], $warnings);
                if ($image !== null) {
                    $answerhtml .= self::image_html($image['filename'], $option['imagealt'] ?? '', 'image_option_alt');
                    $answeritemid = $image['itemid'];
                }
            }
            $form->answer[] = self::field($answerhtml, $answeritemid);
            if ($multiple) {
                // A wrong selection takes away what a right one gives, so ticking everything scores zero.
                $form->fraction[] = $option['correct'] ? $weight : -$weight;
            } else {
                $form->fraction[] = $option['correct'] ? 1.0 : 0.0;
            }
            $form->feedback[] = self::field('');
        }
        return $form;
    }

    /**
     * Save one image in a draft area so question_type::save_question() can attach it to the question.
     *
     * @param string $url Image URL or data:image URI.
     * @param string[] $warnings Warnings (by reference).
     * @return array|null ['itemid' => int, 'filename' => string] or null.
     */
    protected static function draft_image(string $url, array &$warnings): ?array {
        global $USER;

        try {
            $fetched = image_helper::fetch($url);
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course: could not fetch a question image: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $display = stripos($url, 'data:') === 0
                ? get_string('image_datauri', 'local_ai_yared_course')
                : \core_text::substr($url, 0, 120);
            $warnings[] = get_string('warn_imagefetch', 'local_ai_yared_course', (object) [
                'url' => s($display),
                'error' => get_string('image_error_download', 'local_ai_yared_course'),
            ]);
            return null;
        }
        if (!empty($fetched['error'])) {
            $display = stripos($url, 'data:') === 0
                ? get_string('image_datauri', 'local_ai_yared_course')
                : \core_text::substr($url, 0, 120);
            $warnings[] = get_string('warn_imagefetch', 'local_ai_yared_course', (object) [
                'url' => s($display),
                'error' => $fetched['error'],
            ]);
            return null;
        }

        try {
            $draftid = file_get_unused_draft_itemid();
            if (empty($draftid)) {
                throw new \moodle_exception('image_error_save', 'local_ai_yared_course');
            }
            $context = \context_user::instance($USER->id);
            $fs = get_file_storage();
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => $fetched['filename'],
                'mimetype' => $fetched['mimetype'],
                'userid' => $USER->id,
            ], $fetched['data']);
            return ['itemid' => $draftid, 'filename' => $fetched['filename']];
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course: could not store a question image: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $display = stripos($url, 'data:') === 0
                ? get_string('image_datauri', 'local_ai_yared_course')
                : \core_text::substr($url, 0, 120);
            $warnings[] = get_string('warn_imagefetch', 'local_ai_yared_course', (object) [
                'url' => s($display),
                'error' => get_string('image_error_save', 'local_ai_yared_course'),
            ]);
            return null;
        }
    }

    /**
     * Save a figure drawn by the plugin (SVG) in a draft area.
     *
     * The SVG is built by figure_renderer from numbers and whitelisted labels only; anything else is refused.
     *
     * @param string $svg SVG markup.
     * @param string[] $warnings Warnings (by reference).
     * @return array|null ['itemid' => int, 'filename' => string] or null.
     */
    protected static function draft_figure(string $svg, array &$warnings): ?array {
        global $USER;

        if (strlen($svg) > 100000 || stripos($svg, '<script') !== false || stripos($svg, 'javascript:') !== false
                || !preg_match('/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg"/', $svg)) {
            $warnings[] = get_string('warn_figurefail', 'local_ai_yared_course');
            return null;
        }
        try {
            $draftid = file_get_unused_draft_itemid();
            if (empty($draftid)) {
                throw new \moodle_exception('image_error_save', 'local_ai_yared_course');
            }
            $filename = 'aiyared-fig-' . substr(sha1($svg), 0, 16) . '.svg';
            get_file_storage()->create_file_from_string([
                'contextid' => \context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => $filename,
                'mimetype' => 'image/svg+xml',
                'userid' => $USER->id,
            ], $svg);
            return ['itemid' => $draftid, 'filename' => $filename];
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course: could not store a figure: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $warnings[] = get_string('warn_figurefail', 'local_ai_yared_course');
            return null;
        }
    }

    /**
     * Build the HTML reference for a question or answer file.
     *
     * @param string $filename Stored filename.
     * @param string $alt Alt text from the AI.
     * @param string $defaultalt String key for the default alt text.
     * @return string
     */
    protected static function image_html(string $filename, string $alt, string $defaultalt): string {
        $alt = trim($alt);
        if ($alt === '') {
            $alt = get_string($defaultalt, 'local_ai_yared_course');
        }
        return '<p><img src="@@PLUGINFILE@@/' . $filename . '" alt="' . s($alt)
            . '" class="aiyared-question-image img-fluid"></p>';
    }

    /**
     * Rich text field structure.
     *
     * @param string $html
     * @param int|null $itemid Draft item id when the field has files.
     * @return array
     */
    protected static function field(string $html, ?int $itemid = null): array {
        $field = ['text' => $html, 'format' => FORMAT_HTML, 'files' => []];
        if (!empty($itemid)) {
            $field['itemid'] = $itemid;
        }
        return $field;
    }

    /**
     * Escape text and wrap it as HTML. Math text keeps its LaTeX delimiters intact.
     *
     * @param string $text
     * @param bool $math Whether this is mathematical text.
     * @return string
     */
    protected static function html(string $text, bool $math = false): string {
        if ($math) {
            return '<p>' . self::math_text($text) . '</p>';
        }
        return '<p>' . nl2br(s($text), false) . '</p>';
    }

    /**
     * Escape math text but keep line breaks outside the LaTeX delimiters.
     *
     * This lets feedback show solution steps on separate lines without inserting a <br>
     * inside a display formula, which would break MathJax.
     *
     * @param string $text Plain text with LaTeX delimiters.
     * @return string Escaped HTML.
     */
    protected static function math_text(string $text): string {
        $parts = preg_split('/(\\\\\(|\\\\\)|\\\\\[|\\\\\]|\$\$)/', s($text), -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return s($text);
        }
        $inside = false;
        $out = '';
        foreach ($parts as $part) {
            if ($part === '$$') {
                $inside = !$inside;
                $out .= $part;
                continue;
            }
            if ($part === '\\(' || $part === '\\[') {
                $inside = true;
                $out .= $part;
                continue;
            }
            if ($part === '\\)' || $part === '\\]') {
                $inside = false;
                $out .= $part;
                continue;
            }
            $out .= $inside ? $part : nl2br($part, false);
        }
        return $out;
    }

    /**
     * Render a question the way Moodle will show it, for the review screen.
     *
     * The text is escaped like in the saved question and passed through Moodle's filters, so LaTeX
     * (MathJax) looks the same as in the final question.
     *
     * @param array $q Question: type, text, feedback, math, options [text, correct], answer.
     * @param \context $context Course context.
     * @return array text, feedback, options [html, correct], answer (label or ''), mathactive.
     */
    public static function render_preview(array $q, \context $context): array {
        $math = !empty($q['math']);
        $format = function (string $text) use ($math, $context): string {
            if (trim($text) === '') {
                return '';
            }
            return format_text(self::html($text, $math), FORMAT_HTML, [
                'context' => $context,
                'noclean' => true,
                'para' => false,
            ]);
        };
        $out = [
            'text' => $format((string) ($q['text'] ?? '')),
            'feedback' => $format((string) ($q['feedback'] ?? '')),
            'options' => [],
            'answer' => '',
            'mathactive' => self::math_filter_active($context),
        ];
        if (($q['type'] ?? '') === 'truefalse') {
            if (isset($q['answer']) && $q['answer'] !== null) {
                $out['answer'] = $q['answer'] ? get_string('true', 'qtype_truefalse')
                    : get_string('false', 'qtype_truefalse');
            }
            return $out;
        }
        if (($q['type'] ?? '') === 'shortanswer') {
            $out['answer'] = implode(' / ', array_map('strval', (array) ($q['answers'] ?? [])));
            return $out;
        }
        if (($q['type'] ?? '') === 'essay') {
            $out['answer'] = get_string('import_essay_note', 'local_ai_yared_course');
            return $out;
        }
        if (in_array($q['type'] ?? '', ['gapselect', 'ddwtos'], true)) {
            // Show each gap filled with its correct word, and list the choices that are used.
            $choices = array_values((array) ($q['options'] ?? []));
            $used = [];
            $filled = preg_replace_callback('/\[\[(\d+)\]\]/u', function ($match) use ($choices, &$used) {
                $used[(int) $match[1] - 1] = true;
                return '[ ' . ($choices[(int) $match[1] - 1]['text'] ?? '?') . ' ]';
            }, (string) ($q['text'] ?? ''));
            $out['text'] = $format((string) $filled);
            foreach ($choices as $index => $option) {
                $out['options'][] = [
                    'html' => $format((string) ($option['text'] ?? '')),
                    'correct' => isset($used[$index]),
                ];
            }
            return $out;
        }
        foreach ((array) ($q['options'] ?? []) as $option) {
            $out['options'][] = [
                'html' => $format((string) ($option['text'] ?? '')),
                'correct' => !empty($option['correct']),
            ];
        }
        return $out;
    }

    /**
     * Build a safe preview for a generated question.
     *
     * @param array $q Normalised question.
     * @param \context $context Course context.
     * @return string HTML.
     */
    public static function preview_question(array $q, \context $context): string {
        $math = !empty($q['math']);
        $html = self::html($q['text'], $math);
        if (!empty($q['image'])) {
            $html .= self::preview_image($q['image'], $q['imagealt'] ?? '', 'image_default_alt');
        } else if (!empty($q['figure_svg'])) {
            $alt = trim((string) ($q['imagealt'] ?? ''));
            $html .= '<p><img src="data:image/svg+xml;base64,' . base64_encode((string) $q['figure_svg']) . '" alt="'
                . s($alt !== '' ? $alt : get_string('image_default_alt', 'local_ai_yared_course'))
                . '" class="aiyared-question-image img-fluid"></p>';
        }
        return format_text($html, FORMAT_HTML, [
            'context' => $context,
            'noclean' => true,
            'para' => false,
        ]);
    }

    /**
     * Build a safe preview for the correct answer of a generated question.
     *
     * @param array $q Normalised question.
     * @param \context $context Course context.
     * @return string HTML.
     */
    public static function preview_answer(array $q, \context $context): string {
        if ($q['type'] === 'truefalse') {
            return $q['answer'] ? get_string('true', 'qtype_truefalse') : get_string('false', 'qtype_truefalse');
        }
        if ($q['type'] === 'shortanswer') {
            return s(implode(' / ', array_map('strval', (array) ($q['answers'] ?? []))));
        }
        if ($q['type'] === 'essay' || in_array($q['type'], ['gapselect', 'ddwtos'], true)) {
            return '';
        }
        foreach ($q['options'] as $option) {
            if (!$option['correct']) {
                continue;
            }
            $html = self::html($option['text'], !empty($q['math']));
            if (!empty($option['image'])) {
                $html .= self::preview_image($option['image'], $option['imagealt'] ?? '', 'image_option_alt');
            }
            return format_text($html, FORMAT_HTML, [
                'context' => $context,
                'noclean' => true,
                'para' => false,
            ]);
        }
        return '';
    }

    /**
     * Build a preview image tag without downloading the file again.
     *
     * @param string $url Image URL or data:image URI.
     * @param string $alt Alt text.
     * @param string $defaultalt String key for the default alt text.
     * @return string
     */
    protected static function preview_image(string $url, string $alt, string $defaultalt): string {
        if (!image_helper::is_displayable_url($url)) {
            return '';
        }
        // Avoid putting multi-megabyte base64 strings in the results page.
        if (stripos($url, 'data:') === 0 && strlen($url) > 500000) {
            return '';
        }
        $alt = trim($alt);
        if ($alt === '') {
            $alt = get_string($defaultalt, 'local_ai_yared_course');
        }
        return '<p><img src="' . s($url) . '" alt="' . s($alt)
            . '" class="aiyared-question-image img-fluid"></p>';
    }

    /**
     * Check whether the site has a filter that can render LaTeX.
     *
     * @param \context $context Context.
     * @return bool
     */
    public static function math_filter_active(\context $context): bool {
        if (!function_exists('filter_get_active_state')) {
            return true;
        }
        $on = defined('TEXTFILTER_ON') ? TEXTFILTER_ON : 1;
        $off = defined('TEXTFILTER_OFF') ? TEXTFILTER_OFF : -1;
        foreach (['mathjaxloader', 'tex'] as $filtername) {
            $current = $context;
            while ($current) {
                $state = filter_get_active_state($filtername, $current);
                if ($state === $on) {
                    return true;
                }
                if ($state === $off) {
                    break;
                }
                $parent = $current->get_parent_context();
                if (!$parent || $parent->id === $current->id) {
                    break;
                }
                $current = $parent;
            }
        }
        return false;
    }
}
