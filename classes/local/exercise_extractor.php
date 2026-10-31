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
 * Detects and transcribes the exercises of a worksheet page with a vision model.
 *
 * Each detected exercise is returned as a normalised question, together with the bounding
 * boxes of the whole exercise and of its figure, so the caller can crop them.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exercise_extractor {

    /** Maximum number of exercises requested from one page. */
    public const MAX_PER_PAGE = 12;

    /** Maximum number of exercises accepted from a PDF. */
    public const MAX_PER_PDF = 20;

    /** Maximum number of open questions closed by an extra request in one import. */
    public const MAX_CLOSE_PASSES = 15;

    /** Maximum number of answer options kept per question. */
    public const MAX_OPTIONS = 8;

    /** Question types the importer can produce. */
    public const TYPES = ['multichoice', 'multiselect', 'truefalse', 'gapselect', 'ddwtos', 'shortanswer', 'essay'];

    /** Types graded by hand or sensitive to spelling: they are listed last and never chosen automatically. */
    public const OPEN_TYPES = ['shortanswer', 'essay'];

    /** Types whose statement carries [[n]] placeholders that point to the n-th option. */
    public const GAP_TYPES = ['gapselect', 'ddwtos'];

    /** Other names the models use for the same types. */
    protected const TYPE_ALIASES = [
        'true_false' => 'truefalse', 'true-false' => 'truefalse', 'tf' => 'truefalse', 'boolean' => 'truefalse',
        'verdadero_falso' => 'truefalse', 'multiple_choice' => 'multichoice', 'mcq' => 'multichoice',
        'multiple_select' => 'multiselect', 'multi_select' => 'multiselect', 'multiple_answer' => 'multiselect',
        'short_answer' => 'shortanswer', 'fill_blank' => 'shortanswer', 'fill_in_the_blank' => 'shortanswer',
        'cloze' => 'shortanswer', 'open' => 'essay', 'open_ended' => 'essay',
        'select_missing_words' => 'gapselect', 'missing_word' => 'gapselect', 'dropdown' => 'gapselect',
        'drag_drop' => 'ddwtos', 'drag_and_drop' => 'ddwtos', 'dragdrop' => 'ddwtos', 'drag_words' => 'ddwtos',
    ];

    /** Rules for the wrong options of a question whose options are groups of items. */
    protected const DISTRACTOR_RULES = 'Each wrong group must mix correct and wrong items in a different way; never put '
        . 'the same correct item in every option, because it would reveal the answer, and never make a wrong group '
        . 'obviously absurd.';

    /** Maximum length of the statement (it may include a reading passage). */
    public const MAX_TEXT = 8000;

    /** @var ai_client */
    protected $client;
    /** @var bool Whether questions keep LaTeX. */
    protected $mathmode;
    /** @var string[] */
    protected $warnings = [];

    /** @var int Exercises whose bounding boxes were missing in the last parse. */
    protected $missingboxes = 0;

    /** @var int Exercises with fewer options than the AI counted on the page, in the last parse. */
    protected $shortoptions = 0;

    /**
     * Constructor.
     *
     * @param ai_client $client AI client.
     * @param bool $mathmode Keep LaTeX and line breaks in the generated text.
     */
    public function __construct(ai_client $client, bool $mathmode = true) {
        $this->client = $client;
        $this->mathmode = $mathmode;
    }

    /**
     * Warnings produced during the last extraction.
     *
     * @return string[]
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Extract the exercises of one page image.
     *
     * @param array $image Prepared image: ['data' => bytes, 'mimetype' => ...].
     * @param int $page Page number, used only to label the exercises.
     * @param string $language Language for the options and the feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements from the teacher.
     * @return array[] Normalised exercises.
     * @throws \moodle_exception On AI or JSON errors.
     */
    public function extract_from_image(array $image, int $page, string $language, int $age,
            string $instructions = ''): array {
        $this->warnings = [];
        $this->missingboxes = 0;
        $this->shortoptions = 0;
        $system = $this->system_prompt();
        $prompt = $this->image_prompt($page, $language, $age, $instructions);
        $files = [[
            'mime' => $image['mimetype'] ?? 'image/jpeg',
            'data' => base64_encode((string) $image['data']),
        ]];
        $raw = $this->client->complete_multimodal($system, $prompt, $files);
        return $this->parse_with_retry($raw, $system, $prompt, $files, true, $page, self::MAX_PER_PAGE);
    }

    /**
     * Extract the exercises of a PDF (sent to the model as a document, without cropping).
     *
     * @param string $pdf PDF bytes.
     * @param string $language Language for the options and the feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements from the teacher.
     * @return array[] Normalised exercises.
     * @throws \moodle_exception On AI or JSON errors.
     */
    public function extract_from_pdf(string $pdf, string $language, int $age, string $instructions = ''): array {
        $this->warnings = [];
        $this->missingboxes = 0;
        $this->shortoptions = 0;
        $system = $this->system_prompt();
        $prompt = $this->pdf_prompt($language, $age, $instructions);
        $files = [['mime' => 'application/pdf', 'data' => base64_encode($pdf)]];
        $raw = $this->client->complete_multimodal($system, $prompt, $files);
        return $this->parse_with_retry($raw, $system, $prompt, $files, false, 0, self::MAX_PER_PDF);
    }

    /**
     * Rewrite one exercise in a requested format (second phase of the import).
     *
     * The original statement, the printed alternatives and, when there is one, the picture that goes with
     * the question are sent to the model, which returns a single question of the requested type.
     *
     * @param array $ex Exercise of the manifest (uses "original", falling back to the current fields).
     * @param string $target One of self::TYPES except essay.
     * @param string $language Language of the alternatives and the feedback.
     * @param int $age Target age.
     * @param array|null $image Picture of the question: ['mime' => ..., 'data' => '<base64>'].
     * @param string $previous Text of the version the teacher rejected; the new one must differ.
     * @param string $imagetitle Title of a reference picture the teacher chose (the question must depend on it).
     * @return array|null Normalised exercise, or null when the model returned nothing usable.
     * @throws \moodle_exception On AI or JSON errors.
     */
    public function reformulate(array $ex, string $target, string $language, int $age, ?array $image,
            string $previous = '', string $imagetitle = ''): ?array {
        $this->warnings = [];
        $this->missingboxes = 0;
        $this->shortoptions = 0;
        $source = $ex['original'] ?? $ex;
        $system = $this->system_prompt();

        $lines = [];
        $lines[] = 'Below is ONE exercise taken from a worksheet. Rewrite it as ONE Moodle question whose type is '
            . '"' . $target . '".';
        $lines[] = '=== ORIGINAL STATEMENT (it may start with a reading passage) ===';
        $lines[] = (string) ($source['text'] ?? '');
        if (!empty($source['options'])) {
            $lines[] = '=== PRINTED ALTERNATIVES ===';
            foreach ($source['options'] as $index => $option) {
                $lines[] = chr(65 + $index) . ') ' . $option['text'] . (!empty($option['correct']) ? '   <- correct' : '');
            }
        }
        if (!empty($source['answers'])) {
            $lines[] = '=== KNOWN ANSWERS ===';
            $lines[] = implode(' / ', $source['answers']);
        }
        if (isset($source['answer']) && $source['answer'] !== null && ($source['type'] ?? '') === 'truefalse') {
            $lines[] = 'Known answer: ' . ($source['answer'] ? 'true' : 'false');
        }
        if (!empty($source['imagealt'])) {
            $lines[] = '=== PICTURE THAT ACCOMPANIES THE QUESTION ===';
            $lines[] = (string) $source['imagealt'];
        }
        if ($image) {
            $lines[] = 'The attached image is the picture that will be shown together with the question. Look at it: '
                . 'the correct answer may depend on what it shows. Refer to it as "la imagen" / "the image" and never '
                . 'ask the student to look at the worksheet.';
        }
        if ($imagetitle !== '') {
            $lines[] = 'The picture was chosen by the teacher as the reference of the question and its file title is: "'
                . $imagetitle . '". Write the question so that it depends on the picture (for example who or what it '
                . 'shows), and make the correct answer what the picture shows. Never write that name or title in the '
                . 'statement, because it would give the answer away.';
        }
        $lines[] = '=== FORMAT RULES ===';
        $lines = array_merge($lines, self::format_rules($target));
        $lines[] = '=== GENERAL RULES ===';
        $lines[] = '- Keep the intention and the difficulty of the original exercise; do not invent content that is not '
            . 'in it, except plausible distractors.';
        $lines[] = '- If the statement has a reading passage, keep it complete at the start of "text".';
        $lines[] = '- "feedback" (2 to 4 lines) and every alternative or word you create are written in ' . $language . '.';
        $lines[] = '- LaTeX only for real mathematical notation, written as \\\\( ... \\\\) with the backslash doubled in '
            . 'the JSON. Words and names are plain text.';
        $lines[] = '- Do not use Markdown, HTML or code fences.';
        $lines[] = '- Target audience: ' . question_generator::age_descriptor($age);
        if (trim($previous) !== '') {
            $lines[] = '- The teacher did not like this earlier version, so write a clearly different one (other '
                . 'wording, other distractors): ' . \core_text::substr(trim($previous), 0, 600);
        }
        $lines[] = 'Reply with a JSON object {"exercises": [ONE exercise]} where the exercise has the keys: number, '
            . 'text, type ("' . $target . '"), feedback, options, option_count, correct_option, correct_options, '
            . 'answer, answers (only the ones that the format needs).';
        $prompt = implode("\n", $lines);

        $files = [];
        if ($image) {
            $files[] = $image;
        }
        $raw = $this->client->complete_multimodal($system, $prompt, $files);
        $items = $this->parse($raw, false, (int) ($ex['page'] ?? 0), 1);
        if (!$items) {
            return null;
        }
        $result = $items[0];
        if ($result['type'] !== $target) {
            $result['needsreview'] = true;
        }
        return $result;
    }

    /**
     * Turn an exercise that came back as an open question (short answer or essay) into a closed one.
     *
     * Tries "multichoice" first and then "truefalse", sending the picture when there is one. When both fail,
     * or the providers are down, the exercise is flagged as not gradable automatically so the teacher decides.
     *
     * @param array $item Normalised exercise (must carry "original" for the statement).
     * @param string $language Language of the alternatives and the feedback.
     * @param int $age Target age.
     * @param array|null $image Picture of the question: ['mime' => ..., 'data' => '<base64>'].
     * @return array The exercise, closed or flagged "ungradable".
     */
    public function close_open(array $item, string $language, int $age, ?array $image): array {
        foreach (['multichoice', 'truefalse'] as $target) {
            try {
                $fresh = $this->reformulate($item, $target, $language, $age, $image);
            } catch (\moodle_exception $e) {
                break;
            }
            if ($fresh && !in_array($fresh['type'], self::OPEN_TYPES, true)) {
                foreach (['type', 'text', 'answer', 'answers', 'options', 'feedback', 'needsreview', 'math'] as $key) {
                    $item[$key] = $fresh[$key];
                }
                $item['recommended'] = $fresh['type'];
                $item['viable'] = self::viable_types([], $fresh['type']);
                $item['rewritten'] = true;
                $item['ungradable'] = false;
                return $item;
            }
        }
        $item['ungradable'] = true;
        $item['needsreview'] = true;
        return $item;
    }

    /**
     * Create new questions from topics (or from the teacher's own statements) in one or several formats.
     *
     * Used by the classic generator for every format beyond multiple choice and true/false. "mixedall" mixes the
     * formats that grade themselves; short answer and essay are produced only when the teacher asks for them.
     *
     * @param string[] $topics Topics to cover.
     * @param string[] $stems Teacher statements that still need to become questions.
     * @param int $n Number of questions wanted.
     * @param string $qtype One of self::TYPES or "mixedall".
     * @param string $language Language of the alternatives and the feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements from the teacher.
     * @param string[] $avoid Statements that must not be repeated.
     * @return array[] Normalised exercises.
     * @throws \moodle_exception On AI or JSON errors.
     */
    public function generate_questions(array $topics, array $stems, int $n, string $qtype, string $language, int $age,
            string $instructions = '', array $avoid = []): array {
        $this->warnings = [];
        $this->missingboxes = 0;
        $this->shortoptions = 0;
        $closed = array_values(array_diff(self::TYPES, self::OPEN_TYPES));
        $types = $qtype === 'mixedall' ? $closed : (in_array($qtype, self::TYPES, true) ? [$qtype] : ['multichoice']);

        $lines = [];
        if ($stems) {
            $lines[] = 'The teacher wrote ' . count($stems) . ' question(s) WITHOUT answer options. Keep the meaning and '
                . 'the topic of each one (you may fix spelling) and turn each into ONE Moodle question of the format '
                . 'rules below. Return them in the same order and return exactly ' . count($stems) . ' items.';
            $lines[] = '=== TEACHER QUESTIONS ===';
            foreach ($stems as $i => $stem) {
                $lines[] = ($i + 1) . '. ' . $stem;
            }
            if ($topics) {
                $lines[] = 'General context of the course topics: ' . implode('; ', $topics) . '.';
            }
        } else {
            $lines[] = 'Create exactly ' . $n . ' assessment question(s).';
            $lines[] = 'Topics (cover them evenly): ' . implode('; ', $topics) . '.';
        }
        if (count($types) === 1) {
            $lines[] = 'Use ONLY the type "' . $types[0] . '" for every question.';
        } else {
            $lines[] = 'Mix these types: ' . implode(', ', $types) . '. Use "multichoice" for about 40%, and spread the '
                . 'others. NEVER use "shortanswer" or "essay". Choose for each question the type that fits it best.';
        }
        $lines[] = '=== FORMAT RULES ===';
        foreach ($types as $type) {
            $lines = array_merge($lines, self::format_rules($type));
        }
        $lines[] = '=== GENERAL RULES ===';
        $lines[] = '- Facts must be correct and the questions must not repeat each other.';
        $lines[] = '- "feedback" (2 to 4 lines) and every alternative or word you create are written in ' . $language . '.';
        $lines[] = '- LaTeX only for real mathematical notation, written as \\\\( ... \\\\) with the backslash doubled in the '
            . 'JSON. Words and names are plain text.';
        $lines[] = '- Do not use Markdown, HTML or code fences.';
        $lines[] = '- Target audience: ' . question_generator::age_descriptor($age);
        if ($avoid) {
            $lines[] = 'Do NOT repeat or rephrase these existing questions: ' . implode(' | ', array_map(function ($t) {
                return \core_text::substr((string) $t, 0, 90);
            }, array_slice($avoid, -40)));
        }
        if (trim($instructions) !== '') {
            $lines[] = 'Additional instructions from the teacher (follow them, but keep the allowed types and the JSON shape):';
            $lines[] = trim($instructions);
        }
        $lines[] = 'Reply with a JSON object {"exercises": [...]} where each item has the keys: number, text, type, '
            . 'feedback, options, option_count (0, because you create the options), correct_option, correct_options, '
            . 'answer, answers (only the ones that the format needs).';
        $prompt = implode("\n", $lines);
        $system = 'You are an expert instructional designer who writes accurate, well-balanced assessment questions for '
            . 'a school or university LMS. You always reply with one single valid JSON object and nothing else.';

        $items = null;
        for ($attempt = 0; $attempt < 2 && $items === null; $attempt++) {
            $raw = $this->client->complete($system, $prompt);
            try {
                $items = $this->parse($raw, false, 0, max(1, $n));
            } catch (\moodle_exception $e) {
                if ($attempt === 1 || !in_array($e->errorcode, ['error_badjson', 'error_emptyresponse'], true)) {
                    throw $e;
                }
            }
        }
        // Short answer and essay never appear unless the teacher asked for them (a failed gap question falls back to
        // multiple choice, which is fine; an open one is not).
        $openwanted = (bool) array_intersect($types, self::OPEN_TYPES);
        return array_values(array_filter($items ?? [], function ($item) use ($openwanted) {
            return $openwanted || !in_array($item['type'], self::OPEN_TYPES, true);
        }));
    }

    /**
     * Instructions that describe one format to the model.
     *
     * @param string $type Format.
     * @return string[] Lines.
     */
    protected static function format_rules(string $type): array {
        switch ($type) {
            case 'multichoice':
                return ['- "multichoice": 4 alternatives (up to 8 if the original prints more), exactly one correct, '
                    . 'plausible distractors of similar length. Give "options" with "correct" flags and "correct_option" '
                    . '(the letter). Do not write letters inside the options and do not repeat them in "text". If the '
                    . 'original asks to write or name N things, the options are GROUPS of N items and the question asks '
                    . 'which group is correct. If it asks to explain or define, the options are statements and one of '
                    . 'them is the correct explanation. ' . self::DISTRACTOR_RULES];
            case 'multiselect':
                return ['- "multiselect": 4 to 6 alternatives of which two or more are correct. Give "options" with '
                    . '"correct" flags and "correct_options" (list of letters). The statement must say that several '
                    . 'answers apply.'];
            case 'truefalse':
                return ['- "truefalse": "text" is ONE affirmation (with its passage if any) that is clearly true or '
                    . 'clearly false, for example "El dios de la imagen es Zeus." Choose at random whether it is true '
                    . 'or false; a false affirmation must change one key fact and stay plausible. "answer" is true or '
                    . 'false. No options.'];
            case 'gapselect':
            case 'ddwtos':
                return ['- "' . $type . '": "text" is a sentence or short text with the missing words written as the '
                    . 'placeholders [[1]], [[2]]... "options" are the choices: the missing words plus 1 to 3 plausible '
                    . 'distractors, each a single word or a short phrase, without "correct" flags. The number inside '
                    . '[[n]] is the 1-based number of the option that is the correct word of that gap. Example: text '
                    . '"El dios [[1]] es el de la imagen.", options Zeus, Hera, Poseidón, Atenea. Use 1 to 4 gaps.'];
            case 'shortanswer':
                return ['- "shortanswer": "text" is the question or a sentence with a gap written ____ ; the answer is ONE '
                    . 'word or a very short phrase. "answers" lists 1 to 5 accepted answers: the main one and the common '
                    . 'variants (with and without accent, with and without article, usual alternative spellings).'];
            default:
                return ['- "essay": "text" is an open question; no options.'];
        }
    }

    /**
     * Parse an answer and retry once when it was cut off or was not valid JSON.
     *
     * Providers occasionally cut a long JSON answer or return a malformed object. A second
     * request with the same files normally solves it; if the first answer was a valid empty
     * result, it is respected and no extra request is made.
     *
     * @param string $raw Raw answer of the first request.
     * @param string $system System prompt.
     * @param string $prompt User prompt.
     * @param array[] $files Attachments.
     * @param bool $allowboxes Whether bounding boxes are expected.
     * @param int $page Page number.
     * @param int $limit Maximum number of exercises.
     * @return array[] Normalised exercises.
     * @throws \moodle_exception When the second answer is also unusable.
     */
    protected function parse_with_retry(string $raw, string $system, string $prompt, array $files,
            bool $allowboxes, int $page, int $limit): array {
        try {
            $items = $this->parse($raw, $allowboxes, $page, $limit);
            if ($items) {
                // If the model returned the exercises but omitted every box, ask once more.
                if (!$allowboxes || $this->missingboxes < count($items)) {
                    return $items;
                }
            } else {
                $finish = strtoupper($this->client->get_last_finish_reason());
                if (!in_array($finish, ['MAX_TOKENS', 'LENGTH'], true)) {
                    return $items;
                }
            }
        } catch (\moodle_exception $e) {
            if (!in_array($e->errorcode, ['error_badjson', 'error_emptyresponse'], true)) {
                throw $e;
            }
        }

        // The first warnings described the unusable answer; they do not apply to the retry.
        $this->warnings = [];
        $retryprompt = $prompt . "\n\nIMPORTANT: The previous answer was incomplete or was not valid JSON. "
            . 'Return the complete JSON object again for the same file. Do not omit any exercise, do not '
            . 'stop in the middle and keep every feedback short.';
        if ($allowboxes) {
            $retryprompt .= ' Every exercise must include "exercise_box" and "figure_box" (or null). '
                . 'Write each box as [left, top, right, bottom] with left < right and top < bottom, using '
                . 'fractions of the image width and height. The exercise box must enclose the whole exercise.';
        }
        $raw = $this->client->complete_multimodal($system, $retryprompt, $files);
        return $this->parse($raw, $allowboxes, $page, $limit);
    }

    /**
     * System instructions shared by both prompts.
     *
     * @return string
     */
    protected function system_prompt(): string {
        return 'You are an expert teacher and assessment designer. You read photographs, scans and PDF '
            . 'documents of exercises and turn every exercise into a question for a Moodle question bank. '
            . 'You never invent exercises that are not on the page. You always reply with one single valid '
            . 'JSON object and nothing else.';
    }

    /**
     * Rules shared by the image and the PDF prompts: fields, question types, LaTeX policy, figures.
     *
     * @param string $language Output language for options and feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements.
     * @param bool $ispdf Whether the document is a PDF (no boxes).
     * @return string[] Lines.
     */
    protected function shared_lines(string $language, int $age, string $instructions, bool $ispdf): array {
        $lines = [];
        $lines[] = 'For each exercise return:';
        $lines[] = '- "number": the printed label of the exercise as text ("9", "12.a", "3"...). Use "" when there is no label.';
        $lines[] = '- "text": the full statement, transcribed faithfully, including the instructions, the data and '
            . 'any sub-items a), b), c) that belong to the question itself. Do not solve it here. Do NOT copy the '
            . 'answer options into "text": the printed alternatives (A), B), C)... or a), b), c)...) go only in '
            . '"options". IMPORTANT: if the statement depends on a passage, paragraph, poem, text, table or list of '
            . 'data that is printed outside the numbered item (for example "Lee el siguiente párrafo y ..."), copy '
            . 'that passage completely at the beginning of "text", then a blank line, then the question. Never '
            . 'return the question without the passage it refers to. If one passage serves several exercises, '
            . 'repeat it in each of them.';
        if ($ispdf) {
            $lines[] = '  If the exercise depends on a figure, describe it briefly in square brackets at the end of '
                . 'the statement, for example "[Figura: triángulo rectángulo con catetos 3 y 4]".';
        }
        $lines[] = '- "type": the RECOMMENDED format for this exercise, one of:';
        $lines[] = '    "multichoice": one correct option among printed or created alternatives (the DEFAULT);';
        $lines[] = '    "multiselect": several correct options chosen from a list or passage that the exercise gives '
            . '(for example "encuentra las palabras esdrújulas" in a paragraph);';
        $lines[] = '    "truefalse": a statement that is judged as true or false;';
        $lines[] = '    "gapselect": a sentence with missing words, each chosen from a drop-down list;';
        $lines[] = '    "ddwtos": the same as "gapselect" but the words are dragged into the text (use it when the page '
            . 'prints a word bank or a box of words to drag).';
        $lines[] = '- NEVER use "shortanswer" or "essay": the teacher chooses those by hand. Every exercise must become a '
            . 'closed question that Moodle grades automatically.';
        $lines[] = '- Choosing the format: if the exercise prints alternatives, keep them ("multichoice" or "multiselect"). '
            . 'If it prints none, REWRITE it as a closed question, "multichoice" by default:';
        $lines[] = '    * "write / name N things" (for example "escribe 3 países de Sudamérica") becomes "multichoice" '
            . 'whose options are GROUPS of N items and the question asks which group is correct ("¿Cuál de los '
            . 'siguientes grupos contiene solo países de Sudamérica?"). ' . self::DISTRACTOR_RULES;
        $lines[] = '    * "explain, define, describe, argue" becomes "multichoice": which of the statements explains or '
            . 'defines it correctly, with plausible but wrong statements;';
        $lines[] = '    * a statement to judge becomes "truefalse"; a missing word becomes "gapselect" or "ddwtos" (or '
            . '"multichoice" with the candidate words); a question about a picture ("¿quién es el dios de la imagen?") '
            . 'becomes "multichoice".';
        $lines[] = '    * Keep the intention and the topic of the original exercise and never invent content that is not '
            . 'in the document.';
        $lines[] = '- "rewritten": true when the page printed no alternatives and you restructured the exercise (you created '
            . 'the options or changed its format); false when you only transcribed it.';
        $lines[] = '- "ungradable": true ONLY when the exercise cannot be turned into any closed question (draw, build, '
            . 'perform, handwriting). In that single case use type "essay" and set it to true; otherwise false.';
        $lines[] = '- "viable_types": the closed formats of the list above that could reasonably express this exercise, '
            . 'the recommended one first (for example ["multichoice","truefalse","gapselect"]).';
        $lines[] = '- "gapselect" and "ddwtos": write the missing words in "text" as the placeholders [[1]], [[2]]... '
            . 'and return in "options" the choices: the missing words plus one to three plausible distractors, each a '
            . 'single word or short phrase. The number inside [[n]] is the NUMBER (1-based) of the option that is the '
            . 'correct word of that gap. Example: text "El dios [[1]] es el de la imagen.", options Zeus, Hera, '
            . 'Poseidón, Atenea. Do not put "correct" flags and do not use "correct_option".';
        $lines[] = '- "feedback": the worked solution, a short explanation of the correct answer (2 to 4 lines).';
        $lines[] = '- "options": for "multichoice" and "multiselect", EVERY printed alternative in the printed order. '
            . 'A question can have from 2 to ' . self::MAX_OPTIONS . ' alternatives (A to H): count them first and '
            . 'never reduce them to 4. Write the count in "option_count". Only when no alternatives are printed '
            . 'create them (4 for "multichoice", 5 or 6 for "multiselect"). Each option is '
            . '{"text": "...", "correct": true or false}. For "multichoice" exactly one option is correct. For '
            . '"multiselect" two or more are correct. Distractors must be plausible and similar in length to the '
            . 'correct options. Never use "all of the above", "none of the above" or "both".';
        $lines[] = '- "option_count": number of alternatives printed on the page (0 when you created them).';
        $lines[] = '- "correct_option": for "multichoice", the letter (A, B, C...) of the correct option in the printed '
            . 'order. Solve the exercise yourself first in "feedback" and only then choose the letter. The correct '
            . 'option can be in any position; never default to A. Make the "correct": true flag agree with it.';
        $lines[] = '- "correct_options": for "multiselect", the list of letters of all the correct options.';
        $lines[] = '- "answer": for "truefalse", true or false.';
        $lines[] = '- "answers": for "shortanswer", the list of acceptable answers (the main one first, then valid '
            . 'variants such as plural, accent or capitalisation alternatives). One to five items.';
        $lines[] = '- Write the keys in this order: number, text, type, viable_types, rewritten, ungradable, feedback, options, option_count, '
            . 'correct_option, correct_options, answer, answers, so the solution is worked out before the answer '
            . 'is chosen.';
        if ($ispdf) {
            $lines[] = '- "needs_figure": true when the exercise depends on a picture or figure that cannot be read from the text.';
            $lines[] = '- "page": the 1-based number of the PDF page where the exercise appears.';
            $lines[] = '- "exercise_box" and "figure_box": null. The PDF pages are not cropped.';
        } else {
            $lines[] = '- "exercise_box": [x0, y0, x1, y1], the box that tightly encloses the whole exercise, including '
                . 'its options, as fractions of the image width and height (0 = left/top, 1 = right/bottom). '
                . 'Always use x0 < x1 and y0 < y1. It is only a reference for the teacher; it is NOT used as the '
                . 'image of the question.';
            $lines[] = '- "figure_box": [x0, y0, x1, y1] or null. For a real drawing, diagram, graph, map, photograph '
                . 'or table drawn as an image that the student must SEE to answer, including a picture the statement '
                . 'refers to ("de la imagen", "observa", "la foto", "el mapa"). It must not contain the text of the '
                . 'statement or the options. For exercises made only of text (language, definitions, readings) it '
                . 'MUST be null: never frame a text-only exercise.';
            $lines[] = '- "imagealt": a short description of the figure ("" when there is no figure).';
            $lines[] = '- "needs_figure": true when the exercise depends on a picture (it refers to one) and you could '
                . 'not give a tight figure_box. False for text-only exercises.';
            $lines[] = '- "page": the page number given above.';
        }
        $lines[] = 'Rules:';
        $lines[] = '- Transcribe everything that is printed (statements, passages, printed alternatives) in its original '
            . 'language. The "feedback" is ALWAYS written in ' . $language . ', and so are the alternatives or words '
            . 'that you create yourself.';
        $lines[] = '- LaTeX policy: use LaTeX (\\( ... \\) inline, \\[ ... \\] display) ONLY for real mathematical, '
            . 'physical or chemical notation: fractions, powers, roots, equations, formulas, symbols. Words, names, '
            . 'sentences, years, ordinary numbers, letters of alternatives and punctuation are plain text and must '
            . 'NEVER be wrapped in \\( \\). Language, literature, history, biology and other theory exercises '
            . 'normally contain no LaTeX at all. Inside the JSON, write each LaTeX backslash twice.';
        $lines[] = '- Do not use Markdown, HTML, code fences or single-dollar delimiters.';
        $lines[] = '- Target audience: ' . question_generator::age_descriptor($age);
        $lines[] = '- Keep sub-items a), b), c) inside the same exercise; do not split them into separate exercises. '
            . 'Do not merge two different exercises. Do not invent exercises.';
        if ($instructions !== '') {
            $lines[] = '- Additional instructions from the teacher (follow them, but keep exactly the JSON shape): '
                . $instructions;
        }
        return $lines;
    }

    /**
     * Build the prompt used for one page image.
     *
     * @param int $page Page number.
     * @param string $language Output language for options and feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements.
     * @return string
     */
    protected function image_prompt(int $page, string $language, int $age, string $instructions): string {
        $limit = self::MAX_PER_PAGE;
        $lines = [];
        $lines[] = "The attached image is page {$page} of a worksheet or an exam.";
        $lines[] = 'The page may be a scan, a photo or a collage of images. Read the whole page, including the text '
            . 'that appears inside images, tables and boxes.';
        $lines[] = 'First count every numbered exercise on the page (1, 2, 3...). Then detect every complete exercise '
            . "in reading order; the number of objects in \"exercises\" must match that count. Return at most {$limit} exercises.";
        $lines[] = 'Do not stop after the first exercise or after the first column. Continue to the bottom of the page.';
        $lines = array_merge($lines, $this->shared_lines($language, $age, $instructions, false));
        $lines[] = '- Ignore headers, footers, page numbers, logos and instructions that are not part of an exercise.';
        $lines[] = '- If the page has no exercises, return {"exercises": []}. If the image is rotated, read it as upright.';
        $lines[] = 'Reply with JSON in exactly this shape:';
        $lines[] = $this->example_json($page);
        return implode("\n", $lines);
    }

    /**
     * Build the prompt used for a PDF document.
     *
     * @param string $language Output language for options and feedback.
     * @param int $age Target age.
     * @param string $instructions Extra requirements.
     * @return string
     */
    protected function pdf_prompt(string $language, int $age, string $instructions): string {
        $limit = self::MAX_PER_PDF;
        $lines = [];
        $lines[] = 'The attached file is a PDF of a worksheet or an exam (at most 15 pages).';
        $lines[] = 'The PDF may be a scan, a photo or a composition of images. Read every page completely, including '
            . 'the text that appears inside images, tables and boxes.';
        $lines[] = 'First count every numbered exercise on every page (1, 2, 3...). Then detect all of them in reading '
            . "order; the number of objects in \"exercises\" must match that count. Return at most {$limit} exercises.";
        $lines[] = 'Do not stop after the first page or after the first exercises. Continue to the end of the document.';
        $lines = array_merge($lines, $this->shared_lines($language, $age, $instructions, true));
        $lines[] = '- If the PDF has no exercises, return {"exercises": []}.';
        $lines[] = 'Reply with JSON in exactly this shape:';
        $lines[] = $this->example_json(1, true);
        return implode("\n", $lines);
    }

    /**
     * Compact JSON example included in the prompt.
     *
     * The first example is a plain-text theory exercise with six alternatives and no LaTeX, so the model
     * does not learn that every statement needs mathematics.
     *
     * @param int $page Page number.
     * @param bool $ispdf Whether the example is for a PDF (no boxes).
     * @return string
     */
    protected function example_json(int $page, bool $ispdf = false): string {
        $math = true;
        $box = function (array $box) use ($ispdf) {
            return $ispdf ? null : $box;
        };
        $examples = [
            [
                'number' => '1',
                'text' => 'Sinónimo de LIMPIO',
                'type' => 'multichoice',
                'viable_types' => ['multichoice', 'truefalse', 'gapselect'],
                'rewritten' => false,
                'ungradable' => false,
                'feedback' => 'Limpio significa sin suciedad; su sinónimo es aseado.',
                'options' => [
                    ['text' => 'Sucio', 'correct' => false],
                    ['text' => 'Aseado', 'correct' => true],
                    ['text' => 'Manchado', 'correct' => false],
                    ['text' => 'Turbio', 'correct' => false],
                    ['text' => 'Opaco', 'correct' => false],
                    ['text' => 'Gris', 'correct' => false],
                ],
                'option_count' => 6,
                'correct_option' => 'B',
                'exercise_box' => $box([0.05, 0.08, 0.95, 0.22]),
                'figure_box' => null,
                'imagealt' => '',
                'needs_figure' => false,
                'page' => $page,
            ],
            [
                'number' => '2',
                'text' => "Lee el siguiente párrafo: «El murciélago vuela de noche y busca fruta en el árbol.»\n\n"
                    . 'Encuentra las palabras esdrújulas.',
                'type' => 'multiselect',
                'viable_types' => ['multiselect', 'truefalse'],
                'rewritten' => true,
                'ungradable' => false,
                'feedback' => 'Esdrújula es la palabra con acento en la antepenúltima sílaba: murciélago.',
                'options' => [
                    ['text' => 'murciélago', 'correct' => true],
                    ['text' => 'vuela', 'correct' => false],
                    ['text' => 'noche', 'correct' => false],
                    ['text' => 'árbol', 'correct' => false],
                ],
                'option_count' => 0,
                'correct_options' => ['A'],
                'exercise_box' => $box([0.05, 0.25, 0.95, 0.4]),
                'figure_box' => null,
                'imagealt' => '',
                'needs_figure' => false,
                'page' => $page,
            ],
            [
                'number' => '3',
                'text' => 'Completa: La capital del Perú es [[1]].',
                'type' => 'gapselect',
                'viable_types' => ['gapselect', 'multichoice', 'truefalse'],
                'rewritten' => true,
                'ungradable' => false,
                'feedback' => 'La capital del Perú es Lima.',
                'options' => [
                    ['text' => 'Lima'],
                    ['text' => 'Cusco'],
                    ['text' => 'Arequipa'],
                ],
                'option_count' => 0,
                'exercise_box' => $box([0.05, 0.42, 0.95, 0.5]),
                'figure_box' => null,
                'imagealt' => '',
                'needs_figure' => false,
                'page' => $page,
            ],
        ];
        if ($math) {
            $examples[] = [
                'number' => '4',
                'text' => 'Resuelve \\(2x+3=11\\). [Ver el gráfico.]',
                'type' => 'multichoice',
                'viable_types' => ['multichoice'],
                'rewritten' => false,
                'ungradable' => false,
                'feedback' => 'Restamos 3: \\(2x=8\\). Dividimos entre 2: \\(x=4\\).',
                'options' => [
                    ['text' => '\\(x=7\\)', 'correct' => false],
                    ['text' => '\\(x=5\\)', 'correct' => false],
                    ['text' => '\\(x=4\\)', 'correct' => true],
                    ['text' => '\\(x=14\\)', 'correct' => false],
                ],
                'option_count' => 4,
                'correct_option' => 'C',
                'exercise_box' => $box([0.05, 0.52, 0.95, 0.78]),
                'figure_box' => $box([0.62, 0.55, 0.9, 0.75]),
                'imagealt' => 'Gráfico de una recta',
                'needs_figure' => false,
                'page' => $page,
            ];
        }
        return json_encode(['exercises' => $examples], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse and normalise the model answer.
     *
     * @param string $raw Raw answer.
     * @param bool $allowboxes Whether the exercises may carry bounding boxes.
     * @param int $page Page number used for image pages.
     * @param int $limit Maximum number of exercises accepted.
     * @return array[] Normalised exercises.
     * @throws \moodle_exception When no usable JSON was returned.
     */
    protected function parse(string $raw, bool $allowboxes, int $page, int $limit): array {
        $this->missingboxes = 0;
        $this->shortoptions = 0;
        $data = ai_json::extract($raw, $this->mathmode);
        if ($data === null) {
            throw new \moodle_exception('error_badjson', 'local_ai_yared_course');
        }
        if (array_is_list($data)) {
            $items = $data;
        } else {
            $items = $data['exercises'] ?? $data['questions'] ?? $data['items'] ?? [];
        }
        if (!is_array($items)) {
            $items = [];
        }

        $exercises = [];
        $seen = [];
        $skipped = 0;
        $review = 0;
        $badboxes = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (count($exercises) >= $limit) {
                $this->warnings[] = get_string('import_warn_limit', 'local_ai_yared_course', $limit);
                break;
            }
            $exercise = $this->normalise($item, $allowboxes, $page, $review);
            if ($exercise === null) {
                $skipped++;
                continue;
            }
            if ($allowboxes && !$exercise['figure'] && !$exercise['exercise']) {
                $this->missingboxes++;
            }
            if (!$exercise['figure'] && !$exercise['exercise'] && $allowboxes
                    && (!empty($item['figure_box']) || !empty($item['exercise_box']))) {
                $badboxes++;
            }
            // The whole statement: exercises that share a reading passage differ only at the end.
            $key = sha1(\core_text::strtolower(preg_replace('/\s+/u', ' ', $exercise['text'])));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $exercises[] = $exercise;
        }

        if ($skipped > 0) {
            $this->warnings[] = get_string('import_warn_skipped', 'local_ai_yared_course', $skipped);
        }
        if ($review > 0) {
            $this->warnings[] = get_string('import_warn_review', 'local_ai_yared_course', $review);
        }
        if ($badboxes > 0) {
            $this->warnings[] = get_string('import_warn_boxes', 'local_ai_yared_course', $badboxes);
        }
        if ($this->shortoptions > 0) {
            $this->warnings[] = get_string('import_warn_optioncount', 'local_ai_yared_course', $this->shortoptions);
        }
        if (!$items) {
            $this->warnings[] = get_string('import_warn_none', 'local_ai_yared_course');
        }

        return $exercises;
    }

    /**
     * Remove the answer options when the model copied them at the end of the statement.
     *
     * The options already travel in their own field, so the statement keeps only the question. The block
     * is removed only when it starts with the label "a)" and contains the text of at least two of the
     * options, so sub-items a), b) that belong to the question itself are left alone.
     *
     * @param string $text Statement.
     * @param array $options Normalised options ([['text' => ..., 'correct' => ...], ...]).
     * @return string Statement without the options.
     */
    protected function strip_options_from_text(string $text, array $options): string {
        if (count($options) < 2) {
            return $text;
        }
        // Models sometimes write a literal "\n" (backslash + n) before each option label.
        $work = preg_replace('/\\\\n(?=\s*\(?[A-Ha-h]\s*[\)\.])/u', "\n", $text);
        $label = '/(?<![\p{L}\p{N}])\(?([A-Ha-h])\s*[\)\.](?=\s)/u';
        if (!preg_match_all($label, $work, $matches, PREG_OFFSET_CAPTURE) || count($matches[0]) < 2) {
            return $text;
        }
        $normalise = function (string $value): string {
            return preg_replace('/[\s\\\\()\[\]$]+/u', '', \core_text::strtolower($value));
        };
        $needed = min(2, count($options));

        // Try the last "a)" first: the options come after the question.
        for ($k = count($matches[0]) - 1; $k >= 0; $k--) {
            if (\core_text::strtolower($matches[1][$k][0]) !== 'a') {
                continue;
            }
            $cut = $matches[0][$k][1];
            $tail = substr($work, $cut);
            $head = rtrim(substr($work, 0, $cut));
            if ($head === '' || !preg_match('/(?<![\p{L}\p{N}])\(?[Bb]\s*[\)\.](?=\s)/u', $tail)) {
                continue;
            }
            $normalisedtail = $normalise($tail);
            $found = 0;
            foreach ($options as $option) {
                $needle = $normalise((string) $option['text']);
                if ($needle !== '' && strpos($normalisedtail, $needle) !== false) {
                    $found++;
                }
            }
            if ($found >= $needed) {
                return $head;
            }
        }
        return $text;
    }

    /**
     * Validate one exercise and convert it to the internal structure.
     *
     * Nothing is dropped silently: an exercise whose options are unusable becomes an open (essay) question
     * flagged for review, so the teacher decides instead of losing it.
     *
     * @param array $item Raw exercise.
     * @param bool $allowboxes Whether bounding boxes are expected.
     * @param int $page Page number for image pages.
     * @param int $review Counter of exercises that need the teacher to confirm the answer (by reference).
     * @return array|null Normalised exercise, or null when it has no statement.
     */
    protected function normalise(array $item, bool $allowboxes, int $page, int &$review): ?array {
        $text = $this->clean((string) ($item['text'] ?? $item['statement'] ?? $item['question'] ?? ''));
        // A passage returned apart from the statement belongs at the beginning of the question.
        $context = $this->clean((string) ($item['context'] ?? $item['passage'] ?? ''));
        if ($context !== '' && $text !== '' && \core_text::strpos($text, \core_text::substr($context, 0, 60)) === false) {
            $text = $context . "\n\n" . $text;
        }
        if ($text === '') {
            return null;
        }
        if (\core_text::strlen($text) > self::MAX_TEXT) {
            $text = \core_text::substr($text, 0, self::MAX_TEXT);
        }

        $options = [];
        foreach ((array) ($item['options'] ?? []) as $option) {
            if (is_string($option)) {
                $option = ['text' => $option];
            }
            if (!is_array($option)) {
                continue;
            }
            $otext = $this->clean((string) ($option['text'] ?? $option['label'] ?? ''));
            $otext = trim((string) preg_replace('/^\s*[A-Ha-h1-8][\)\.\:]\s+/u', '', $otext));
            if ($otext === '') {
                continue;
            }
            if (\core_text::strlen($otext) > 1000) {
                $otext = \core_text::substr($otext, 0, 1000);
            }
            $correct = false;
            if (isset($option['correct'])) {
                $correct = is_string($option['correct'])
                    ? in_array(\core_text::strtolower(trim($option['correct'])),
                        ['true', '1', 'yes', 'si', 'sí', 'verdadero'], true)
                    : (bool) $option['correct'];
            }
            $options[] = ['text' => $otext, 'correct' => $correct];
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        $type = \core_text::strtolower(trim((string) ($item['type'] ?? '')));
        $type = self::TYPE_ALIASES[$type] ?? $type;
        if (!in_array($type, self::TYPES, true)) {
            $type = '';
        }

        $answer = null;
        if (isset($item['answer']) && !is_array($item['answer'])) {
            if (is_bool($item['answer'])) {
                $answer = $item['answer'];
            } else {
                $answer = in_array(\core_text::strtolower(trim((string) $item['answer'])),
                    ['true', '1', 'yes', 'si', 'sí', 'verdadero', 'v'], true);
            }
        }

        // Accepted answers of a short-answer question.
        $answers = [];
        $rawanswers = $item['answers'] ?? ($item['accepted_answers'] ?? null);
        if ($rawanswers === null && isset($item['answer']) && is_string($item['answer']) && $type === 'shortanswer') {
            $rawanswers = [$item['answer']];
        }
        foreach ((array) $rawanswers as $candidate) {
            $candidate = trim(strip_tags((string) (is_array($candidate) ? ($candidate['text'] ?? '') : $candidate)));
            if ($candidate !== '' && !in_array($candidate, $answers, true) && count($answers) < 5) {
                $answers[] = \core_text::substr($candidate, 0, 200);
            }
        }

        $figurebox = $allowboxes ? crop_helper::normalise_box($item['figure_box'] ?? null) : null;
        $exercisebox = $allowboxes ? crop_helper::normalise_box($item['exercise_box'] ?? null) : null;
        // A real exercise spans a meaningful part of the page. Reject a narrow strip that some
        // models return when they confuse the x/y order; the teacher can still draw the crop.
        if ($exercisebox !== null && ($exercisebox[2] - $exercisebox[0]) < 0.08) {
            $exercisebox = null;
        }

        $feedback = \core_text::substr($this->clean((string) ($item['feedback'] ?? '')), 0, 4000);
        $needsreview = false;
        $optioncount = max(0, (int) ($item['option_count'] ?? 0));

        // A gap type needs [[n]] placeholders that point to existing options; otherwise it degrades gracefully.
        $gapok = false;
        if (in_array($type, self::GAP_TYPES, true)) {
            $numbers = self::placeholders($text);
            $gapok = count($options) >= 2 && $numbers && min($numbers) >= 1 && max($numbers) <= count($options);
            if (!$gapok) {
                $text = trim((string) preg_replace('/\[\[\d+\]\]/u', '____', $text));
                $type = count($options) >= 2 ? 'multichoice' : ($answers ? 'shortanswer' : 'essay');
                $needsreview = true;
            }
        }

        // Decide the final type. Exercises are never dropped for lacking alternatives.
        if ($gapok) {
            // The options that some gap points to are the correct words; the rest are distractors.
            $used = array_flip(array_map(function ($n) {
                return $n - 1;
            }, self::placeholders($text)));
            foreach ($options as $index => $option) {
                $options[$index]['correct'] = isset($used[$index]);
            }
        } else if ($type === 'truefalse') {
            $options = [];
            if ($answer === null) {
                $answer = false;
                $needsreview = true;
            }
        } else if (in_array($type, ['multichoice', 'multiselect', ''], true) && count($options) >= 2) {
            if ($type === '') {
                $type = 'multichoice';
            }
        } else if ($answers && $type !== 'essay') {
            $type = 'shortanswer';
            $options = [];
        } else if ($type === 'shortanswer' || $type === 'essay') {
            $options = [];
        } else {
            // Options missing or unusable and no accepted answer: keep the exercise as an open question.
            $type = 'essay';
            $options = [];
            $needsreview = true;
        }
        if ($type === 'shortanswer' && !$answers) {
            // Without a known answer it cannot be graded: open question for the teacher to complete.
            $type = 'essay';
            $needsreview = true;
        }

        if (in_array($type, ['multichoice', 'multiselect'], true)) {
            // The options must not stay inside the statement as well.
            $text = $this->strip_options_from_text($text, $options);

            // Letters given by the model, as an answer independent of the per-option flags.
            $letters = [];
            $rawletters = $type === 'multiselect'
                ? ($item['correct_options'] ?? $item['correct_letters'] ?? [])
                : [$item['correct_option'] ?? $item['correct_letter'] ?? null];
            foreach ((array) $rawletters as $rawletter) {
                if (is_string($rawletter) && preg_match('/^\s*\(?([A-Ha-h])\b/u', $rawletter, $lettermatch)
                        && ord(strtoupper($lettermatch[1])) - 65 < count($options)) {
                    $letters[ord(strtoupper($lettermatch[1])) - 65] = true;
                }
            }
            $letters = array_keys($letters);

            $flagged = [];
            foreach ($options as $index => $option) {
                if ($option['correct']) {
                    $flagged[] = $index;
                }
            }

            if ($type === 'multichoice') {
                if (count($flagged) !== 1) {
                    // The flags are missing or ambiguous: use the letter if the model gave one. Only when there is
                    // no usable answer does the first option stay selected, and the teacher must confirm it.
                    $chosen = $letters ? $letters[0] : ($flagged ? $flagged[0] : 0);
                    foreach ($options as $index => $option) {
                        $options[$index]['correct'] = ($index === $chosen);
                    }
                    if (!$letters) {
                        $needsreview = true;
                    }
                } else if ($letters && $letters[0] !== $flagged[0]) {
                    // The flag and the letter disagree: keep the flag but ask the teacher to check.
                    $needsreview = true;
                }
            } else {
                $chosen = $flagged ?: $letters;
                if (!$chosen) {
                    $chosen = [0];
                    $needsreview = true;
                } else if ($flagged && $letters && array_values($flagged) !== array_values($letters)) {
                    $needsreview = true;
                }
                foreach ($options as $index => $option) {
                    $options[$index]['correct'] = in_array($index, $chosen, true);
                }
            }

            // The model counted more alternatives on the page than it returned.
            if ($optioncount > count($options)) {
                $needsreview = true;
                $this->shortoptions++;
            }
        }

        // Remove LaTeX that was wrapped around plain text, then record whether any is left.
        $bundle = ['text' => $text, 'feedback' => $feedback];
        foreach ($options as $index => $option) {
            $bundle['o' . $index] = $option['text'];
        }
        foreach ($answers as $index => $candidate) {
            $bundle['a' . $index] = $candidate;
        }
        $bundle = latex_cleaner::clean_all($bundle, 'auto');
        $text = $bundle['text'];
        $feedback = $bundle['feedback'];
        foreach ($options as $index => $option) {
            $options[$index]['text'] = $bundle['o' . $index];
        }
        foreach ($answers as $index => $candidate) {
            $answers[$index] = $bundle['a' . $index];
        }
        $math = false;
        foreach ($bundle as $value) {
            $math = $math || latex_cleaner::has_latex((string) $value);
        }

        if ($needsreview) {
            $review++;
        }

        // The question image is the figure only. A text-only exercise never gets an image of itself.
        return [
            'number' => \core_text::substr(trim((string) ($item['number'] ?? '')), 0, 20),
            'text' => $text,
            'type' => $type,
            'answer' => $type === 'truefalse' ? (bool) $answer : null,
            'answers' => $type === 'shortanswer' ? $answers : [],
            'options' => $options,
            'feedback' => $feedback,
            'figure' => $figurebox,
            'exercise' => $exercisebox,
            'imagealt' => \core_text::substr(trim((string) ($item['imagealt'] ?? '')), 0, 250),
            'needsfigure' => !empty($item['needs_figure']),
            'recommended' => $type,
            'viable' => self::viable_types($item['viable_types'] ?? [], $type),
            'rewritten' => !empty($item['rewritten'])
                || ($optioncount === 0 && in_array($type, ['multichoice', 'multiselect'], true) && count($options) >= 2),
            'ungradable' => !empty($item['ungradable']) && in_array($type, self::OPEN_TYPES, true),
            'needsreview' => $needsreview,
            'page' => $allowboxes ? $page : max(0, (int) ($item['page'] ?? 0)),
            'math' => $math,
        ];
    }

    /**
     * Numbers of the [[n]] placeholders found in a statement.
     *
     * @param string $text Statement.
     * @return int[] Placeholder numbers in order of appearance.
     */
    public static function placeholders(string $text): array {
        if (!preg_match_all('/\[\[(\d+)\]\]/u', $text, $matches)) {
            return [];
        }
        return array_map('intval', $matches[1]);
    }

    /**
     * Clean the list of formats a model considers viable for an exercise.
     *
     * @param mixed $raw List returned by the model.
     * @param string $type The format finally chosen.
     * @return string[] Formats, the chosen one first, without duplicates.
     */
    protected static function viable_types($raw, string $type): array {
        $list = in_array($type, self::OPEN_TYPES, true) ? [] : [$type];
        foreach ((array) $raw as $candidate) {
            $candidate = \core_text::strtolower(trim((string) (is_scalar($candidate) ? $candidate : '')));
            $candidate = self::TYPE_ALIASES[$candidate] ?? $candidate;
            if (in_array($candidate, self::TYPES, true) && !in_array($candidate, self::OPEN_TYPES, true)
                    && !in_array($candidate, $list, true)) {
                $list[] = $candidate;
            }
        }
        return $list;
    }

    /**
     * Strip tags and normalise whitespace without destroying LaTeX.
     *
     * @param string $text Text.
     * @return string
     */
    protected function clean(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!$this->mathmode) {
            $text = strip_tags($text);
        }
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim((string) $text);
    }
}
