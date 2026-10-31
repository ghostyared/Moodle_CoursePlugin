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
 * Builds prompts, calls the AI client in batches and normalises the answers.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_generator {

    /** @var ai_client */
    protected $client;
    /** @var int */
    protected $batchsize;
    /** @var bool Whether mathematical content is requested as LaTeX. */
    protected $mathmode;
    /** @var string[] Question types produced by the exercise engine instead of the classic multiple choice prompt. */
    public const EXTENDED_TYPES = ['multiselect', 'gapselect', 'ddwtos', 'shortanswer', 'essay', 'mixedall'];

    /** @var bool Whether the AI may propose image URLs. */
    protected $allowimages;
    /** @var string[] */
    protected $warnings = [];

    /**
     * Constructor.
     *
     * @param ai_client $client AI client.
     * @param int $batchsize Questions requested per call.
     * @param bool $mathmode Ask the AI to write formulas in LaTeX.
     * @param bool $allowimages Let the AI propose image URLs.
     */
    public function __construct(ai_client $client, int $batchsize = 10, bool $mathmode = false, bool $allowimages = false) {
        $this->client = $client;
        $this->batchsize = max(1, $batchsize);
        $this->mathmode = $mathmode;
        $this->allowimages = $allowimages;
    }

    /**
     * Warnings produced during the last generate() call (e.g. partial results).
     *
     * @return string[]
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Describe the audience for a given age.
     *
     * @param int $age Age in years.
     * @return string
     */
    public static function age_descriptor(int $age): string {
        if ($age <= 5) {
            return "preschool children about {$age} years old. Use very short, simple sentences and concrete everyday ideas "
                . '(colours, animals, family, objects, places). No abstract concepts. Options must be only a few words long. '
                . 'Cognitive complexity: only recognising and remembering (Bloom: remember).';
        }
        if ($age <= 8) {
            return "early primary school children about {$age} years old. Use short sentences, common words and concrete examples. "
                . 'Cognitive complexity: remember and basic understanding.';
        }
        if ($age <= 12) {
            return "primary school students about {$age} years old. Use clear language and simple reasoning appropriate for that age. "
                . 'Cognitive complexity: remember, understand and simple application with everyday examples.';
        }
        if ($age <= 17) {
            return "secondary school students about {$age} years old. Use appropriate subject terminology and moderate abstraction. "
                . 'Cognitive complexity: understand, apply and basic analysis (cause and effect, comparing, classifying), '
                . 'with only some pure recall questions.';
        }
        return "university-level or adult learners about {$age} years old. Use precise technical terminology. "
            . 'Cognitive complexity: mostly apply, analyse and evaluate (case-based or scenario questions, comparing approaches, '
            . 'identifying errors); avoid pure memorisation and trivial distractors.';
    }

    /**
     * Generate questions.
     *
     * @param string[] $topics Topics to cover.
     * @param string[] $stems Teacher questions that still need answer options.
     * @param int $total Total number of questions wanted (stems are included in the total).
     * @param int $age Target age.
     * @param string $qtype multichoice|truefalse|mixed
     * @param string $language Language name for the questions (e.g. "Spanish").
     * @param string $instructions Extra requirements from the teacher.
     * @return array[] Normalised questions.
     * @throws \moodle_exception If nothing could be generated.
     */
    public function generate(array $topics, array $stems, int $total, int $age, string $qtype, string $language,
            string $instructions = ''): array {
        $this->warnings = [];
        $questions = [];
        $seen = [];

        if (in_array($qtype, self::EXTENDED_TYPES, true)) {
            return $this->generate_extended($topics, $stems, $total, $age, $qtype, $language, $instructions);
        }

        try {
            // Phase 1: complete the teacher's own questions with answer options.
            foreach (array_chunk(array_values($stems), $this->batchsize) as $chunk) {
                $items = $this->run_batch(count($chunk), $topics, $chunk, $age, $qtype, $language, $questions,
                    $instructions);
                $this->add_unique($items, $questions, $seen, count($chunk));
            }

            // Phase 2: generate the rest from the topics.
            $needed = max(0, $total - count($stems));
            $generated = 0;
            $attempts = 0;
            $maxattempts = (int) ceil($needed / $this->batchsize) + 2;
            while ($generated < $needed && $attempts < $maxattempts) {
                $n = min($this->batchsize, $needed - $generated);
                $before = count($questions);
                $items = $this->run_batch($n, $topics, [], $age, $qtype, $language, $questions, $instructions);
                $this->add_unique($items, $questions, $seen, $n);
                $generated += count($questions) - $before;
                $attempts++;
            }
        } catch (\moodle_exception $e) {
            if (empty($questions)) {
                throw $e;
            }
            $this->warnings[] = $e->getMessage();
        }

        return $questions;
    }

    /**
     * Generate questions of the other formats (several correct answers, missing word, drag and drop, short answer,
     * essay, or a mix of the ones that grade themselves) with the same engine that rewrites the imported exercises.
     *
     * @param string[] $topics Topics.
     * @param string[] $stems Teacher statements.
     * @param int $total Questions wanted (the statements are included).
     * @param int $age Target age.
     * @param string $qtype One of self::EXTENDED_TYPES.
     * @param string $language Language name.
     * @param string $instructions Extra requirements.
     * @return array[] Normalised questions.
     * @throws \moodle_exception If nothing could be generated.
     */
    protected function generate_extended(array $topics, array $stems, int $total, int $age, string $qtype,
            string $language, string $instructions): array {
        $extractor = new exercise_extractor($this->client, true);
        $questions = [];
        $seen = [];
        $add = function (array $items) use (&$questions, &$seen) {
            foreach ($items as $item) {
                $key = sha1(\core_text::strtolower(preg_replace('/\s+/u', ' ', (string) $item['text'])));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $options = [];
                foreach ($item['options'] as $option) {
                    $options[] = ['text' => $option['text'], 'correct' => !empty($option['correct']),
                        'image' => null, 'imagealt' => ''];
                }
                $questions[] = [
                    'type' => $item['type'],
                    'text' => $item['text'],
                    'options' => $options,
                    'answer' => $item['answer'],
                    'answers' => $item['answers'],
                    'feedback' => $item['feedback'],
                    'image' => null,
                    'imagealt' => '',
                    'figure_svg' => null,
                    'math' => !empty($item['math']),
                ];
            }
        };
        try {
            foreach (array_chunk(array_values($stems), $this->batchsize) as $chunk) {
                $add($extractor->generate_questions($topics, $chunk, count($chunk), $qtype, $language, $age,
                    $instructions, array_column($questions, 'text')));
            }
            $needed = max(0, $total - count($stems));
            $generated = 0;
            $attempts = 0;
            $maxattempts = (int) ceil($needed / $this->batchsize) + 2;
            while ($generated < $needed && $attempts < $maxattempts) {
                $n = min($this->batchsize, $needed - $generated);
                $before = count($questions);
                $add($extractor->generate_questions($topics, [], $n, $qtype, $language, $age, $instructions,
                    array_column($questions, 'text')));
                $this->warnings = array_merge($this->warnings, $extractor->get_warnings());
                $generated += count($questions) - $before;
                $attempts++;
            }
        } catch (\moodle_exception $e) {
            if (empty($questions)) {
                throw $e;
            }
            $this->warnings[] = $e->getMessage();
        }
        $this->warnings = array_values(array_unique($this->warnings));
        return $questions;
    }

    /**
     * Run one AI call and return the decoded question items.
     *
     * @param int $n Questions requested.
     * @param string[] $topics
     * @param string[] $stems
     * @param int $age
     * @param string $qtype
     * @param string $language
     * @param array[] $existing Questions already produced (to avoid repeats).
     * @param string $instructions Extra requirements from the teacher.
     * @return array[]
     */
    protected function run_batch(int $n, array $topics, array $stems, int $age, string $qtype,
            string $language, array $existing, string $instructions = ''): array {
        $avoid = [];
        foreach (array_slice($existing, -40) as $q) {
            $avoid[] = \core_text::substr($q['text'], 0, 90);
        }
        $system = 'You are an expert instructional designer who writes accurate, well-balanced assessment questions '
            . 'for a school or university LMS. You always reply with one single valid JSON object and nothing else.';
        $prompt = $this->build_prompt($n, $topics, $stems, $age, $qtype, $language, $avoid, $instructions);
        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $raw = $this->client->complete($system, $prompt);
            try {
                return $this->parse($raw, $this->mathmode);
            } catch (\moodle_exception $e) {
                $last = $e;
                // Ask again with a stricter reminder before giving up.
                $prompt .= "\n\nIMPORTANT: reply with ONE complete, valid JSON object and nothing else. "
                    . 'Do not truncate the answer.';
            }
        }
        throw $last;
    }

    /**
     * Build the user prompt.
     *
     * @param int $n
     * @param string[] $topics
     * @param string[] $stems
     * @param int $age
     * @param string $qtype
     * @param string $language
     * @param string[] $avoid
     * @param string $instructions Extra requirements from the teacher.
     * @return string
     */
    protected function build_prompt(int $n, array $topics, array $stems, int $age, string $qtype,
            string $language, array $avoid, string $instructions = ''): string {
        switch ($qtype) {
            case 'truefalse':
                $typerule = 'Use ONLY "truefalse" questions (a statement that is either true or false).';
                break;
            case 'mixed':
                $typerule = 'Mix the types: about 70% "multichoice" and 30% "truefalse".';
                break;
            default:
                $typerule = 'Use ONLY "multichoice" questions with exactly 4 options.';
        }

        $lines = [];
        if ($stems) {
            $lines[] = "The teacher wrote {$n} question(s) WITHOUT answer options. Keep each question's meaning "
                . '(you may only fix spelling or grammar) and create the answer options and feedback for each of them. '
                . 'Return them in the same order, and return exactly ' . $n . ' items. Use a "multichoice" question with '
                . '4 options, unless the text is clearly a statement to judge and true/false questions are allowed.';
            $lines[] = 'Teacher questions:';
            foreach ($stems as $i => $stem) {
                $lines[] = ($i + 1) . '. ' . $stem;
            }
            if ($topics) {
                $lines[] = 'General context of the course topics: ' . implode('; ', $topics) . '.';
            }
        } else {
            $lines[] = "Create exactly {$n} assessment questions.";
            $lines[] = 'Topics (cover them evenly): ' . implode('; ', $topics) . '.';
            $lines[] = $typerule;
        }

        $lines[] = "Language of all the text: {$language}.";
        $lines[] = 'Target audience: ' . self::age_descriptor($age);
        $lines[] = 'Rules:';
        $lines[] = '- Facts must be correct. Questions must not repeat each other.';
        $lines[] = '- "multichoice": exactly 4 options, exactly ONE correct. Distractors must be plausible and similar in length '
            . 'to the correct option. Never use "all of the above" or "none of the above". Vary the position of the correct option.';
        $lines[] = '- "truefalse": "answer" is a JSON boolean.';
        $lines[] = '- "feedback": a concise explanation of why the correct answer is right.';
        if ($this->mathmode) {
            $lines[] = '- Write every mathematical expression in LaTeX. Use \\( ... \\) for inline formulas '
                . 'and \\[ ... \\] for display formulas.';
            $lines[] = '- In the JSON text, write each LaTeX backslash twice. For example, the JSON must contain '
                . '"\\\\(\\\\sin^2(x)+\\\\cos^2(x)=1\\\\)" to produce \\(\\sin^2(x)+\\cos^2(x)=1\\).';
            $lines[] = '- Do not use Markdown, code fences, HTML or single-dollar delimiters.';
            $lines[] = '- In "feedback", show the key solution steps (at least two algebraic steps when applicable), in LaTeX.';
        } else {
            $lines[] = '- Plain text only: no markdown, no HTML, no numbering or letters in front of options.';
        }
        if ($this->allowimages) {
            foreach (self::figure_rules() as $figureline) {
                $lines[] = $figureline;
            }
        }
        if ($avoid) {
            $lines[] = 'Do NOT repeat or rephrase these existing questions: ' . implode(' | ', $avoid);
        }
        if ($instructions !== '') {
            $lines[] = 'Additional instructions from the teacher (follow them, but the answer must still use the allowed '
                . 'question types and exactly the JSON shape below):';
            $lines[] = $instructions;
        }
        $lines[] = 'Reply with JSON in exactly this shape:';

        $example = [
            'questions' => [
                [
                    'type' => 'multichoice',
                    'text' => $this->mathmode ? '\\(\\sin^2(x)+\\cos^2(x)=1\\)' : '...',
                    'options' => [
                        ['text' => $this->mathmode ? '\\(1\\)' : '...', 'correct' => false],
                        ['text' => $this->mathmode ? '\\(0\\)' : '...', 'correct' => true],
                        ['text' => $this->mathmode ? '\\(2\\)' : '...', 'correct' => false],
                        ['text' => $this->mathmode ? '\\(-1\\)' : '...', 'correct' => false],
                    ],
                    'feedback' => '...',
                ],
                [
                    'type' => 'truefalse',
                    'text' => '...',
                    'answer' => true,
                    'feedback' => '...',
                ],
            ],
        ];
        if ($this->allowimages) {
            $example['questions'][0]['figure'] = ['shapes' => [[
                'type' => 'triangle', 'base_angles' => [50, 70], 'vertices' => ['A', 'B', 'C'],
                'labels' => ['base' => '10', 'left' => 'x'], 'angles' => ['left' => ['label' => '50°']],
            ]]];
            $example['questions'][0]['imagealt'] = 'Triangle ABC';
        }
        $lines[] = json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", $lines);
    }

    /**
     * Decode the JSON returned by the model, tolerating the usual imperfections.
     *
     * Handles markdown fences, text around the object, trailing commas, characters the model
     * cannot escape and answers that were truncated by the provider.
     *
     * @param string $raw Raw model output.
     * @param bool $math Whether LaTeX backslash repair is needed.
     * @return array[] Raw question items.
     * @throws \moodle_exception If nothing usable was found.
     */
    protected function parse(string $raw, bool $math = false): array {
        $data = ai_json::extract($raw, $math);
        if ($data === null) {
            throw new \moodle_exception('error_badjson', 'local_ai_yared_course');
        }
        $items = $data['questions'] ?? $data['items'] ?? (array_is_list($data) ? $data : []);
        return is_array($items) ? $items : [];
    }

    /**
     * Normalise and append valid, non-duplicated items.
     *
     * @param array $items Raw items.
     * @param array $questions Accumulated questions (by reference).
     * @param array $seen Lower-cased texts already used (by reference).
     * @param int $max Maximum number of items to add.
     */
    protected function add_unique(array $items, array &$questions, array &$seen, int $max): void {
        foreach ($items as $item) {
            if ($max <= 0) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $q = $this->normalise($item);
            if ($q === null) {
                continue;
            }
            $key = \core_text::strtolower($q['text']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $questions[] = $q;
            $max--;
        }
    }

    /**
     * Validate one question item and convert it to the internal structure.
     *
     * @param array $item Raw item.
     * @return array|null Normalised question or null if invalid.
     */
    protected function normalise(array $item): ?array {
        $text = $this->clean((string) ($item['text'] ?? $item['question'] ?? ''), $this->mathmode);
        if ($text === '') {
            return null;
        }
        $feedback = $this->clean((string) ($item['feedback'] ?? $item['general_feedback'] ?? ''), $this->mathmode);
        $type = strtolower(trim((string) ($item['type'] ?? 'multichoice')));
        [$image, $imagealt] = $this->image_from($item);
        $figuresvg = $this->figure_from($item, $imagealt);

        if (in_array($type, ['truefalse', 'true_false', 'true-false', 'tf', 'boolean'], true)) {
            $answer = $item['answer'] ?? null;
            if (is_string($answer)) {
                $answer = in_array(strtolower(trim($answer)), ['true', 'verdadero', 'v', 'si', 'sí', '1', 'yes'], true);
            } else if ($answer === null) {
                return null;
            }
            return [
                'type' => 'truefalse',
                'text' => $text,
                'answer' => (bool) $answer,
                'feedback' => $feedback,
                'image' => $image,
                'imagealt' => $imagealt,
                'figure_svg' => $figuresvg,
                'math' => $this->mathmode,
            ];
        }

        $options = [];
        $correct = 0;
        foreach ((array) ($item['options'] ?? []) as $opt) {
            if (is_string($opt)) {
                $opt = ['text' => $opt];
            }
            if (!is_array($opt)) {
                continue;
            }
            $otext = $this->clean((string) ($opt['text'] ?? ''), $this->mathmode);
            $otext = (string) preg_replace('/^\s*[A-Ea-e1-5][\)\.\:]\s+/u', '', $otext);
            [$oimage, $oalt] = $this->image_from($opt);
            if ($otext === '' && $oimage === null) {
                continue;
            }
            if ($otext === '') {
                $otext = $oalt !== '' ? $oalt : get_string('image_option_alt', 'local_ai_yared_course');
            }
            $iscorrect = false;
            if (isset($opt['correct'])) {
                if (is_string($opt['correct'])) {
                    $iscorrect = in_array(strtolower(trim($opt['correct'])), ['true', '1', 'yes', 'si', 'sí'], true);
                } else {
                    $iscorrect = (bool) $opt['correct'];
                }
            }
            $correct += $iscorrect ? 1 : 0;
            $options[] = [
                'text' => $otext,
                'correct' => $iscorrect,
                'image' => $oimage,
                'imagealt' => $oalt,
            ];
        }
        if (count($options) < 3 || $correct !== 1) {
            return null;
        }
        return [
            'type' => 'multichoice',
            'text' => $text,
            'options' => $options,
            'feedback' => $feedback,
            'image' => $image,
            'imagealt' => $imagealt,
            'figure_svg' => $figuresvg,
            'math' => $this->mathmode,
        ];
    }

    /**
     * Extract an optional image URL and alt text from a raw question or option.
     *
     * @param array $item Raw item.
     * @return array [string|null $url, string $alt]
     */
    protected function image_from(array $item): array {
        // The AI no longer proposes image URLs (they were invented too often): figures are drawn from a description
        // (see figure_from) and real pictures are supplied by the teacher in the form.
        return [null, ''];
    }

    /**
     * Draw the figure that the AI described for a question.
     *
     * Sets the alternative text from "imagealt" (by reference) when the model gave one.
     *
     * @param array $item Raw item.
     * @param string $alt Alternative text (by reference).
     * @return string|null SVG, or null when there is no figure or it could not be drawn.
     */
    protected function figure_from(array $item, string &$alt): ?string {
        if (!$this->allowimages || empty($item['figure'])) {
            return null;
        }
        $spec = $item['figure'];
        if (is_string($spec)) {
            $spec = json_decode($spec, true);
        }
        $rawalt = $item['imagealt'] ?? $item['image_alt'] ?? $item['alt'] ?? '';
        $alt = is_string($rawalt) ? \core_text::substr($this->clean($rawalt), 0, 250) : '';
        $svg = figure_renderer::render($spec, $alt);
        if ($svg === null) {
            $this->warnings[] = get_string('warn_figurefail', 'local_ai_yared_course');
        }
        return $svg;
    }

    /**
     * Prompt lines that teach the model how to describe a figure.
     *
     * @return string[]
     */
    public static function figure_rules(): array {
        return [
            '- If a question needs a figure (geometry, angles, a graph), add a "figure" field. DO NOT draw anything and '
            . 'do not write URLs: DESCRIBE the figure as JSON and the plugin draws it exactly. Add "imagealt" with a '
            . 'short description. Questions that do not need a figure must not have the field.',
            '  Format: {"shapes": [shape, ...]} with at most 3 shapes, drawn side by side. Every length, angle or letter '
            . 'that the statement needs must appear as a label in the figure, and the figure must agree with the '
            . 'statement and with the correct answer. Labels are short (up to 18 characters: letters, digits, + - = ° / ( )).',
            '  Shapes (all keys are optional except "type"):',
            '  {"type":"triangle", "base_angles":[left,right] (degrees) OR "sides":[base,left,right] (proportions) OR '
            . '"legs":[horizontal,vertical] (right triangle, right angle at the left vertex), "orientation":"up"|"down", '
            . '"vertices":[leftname,rightname,apexname], "labels":{"base":"..","left":"..","right":".."}, '
            . '"angles":{"left"|"right"|"apex": {"label":"α","arcs":1|2|3}}, "right_angle":"left"|"right"|"apex", '
            . '"ticks":{"base"|"left"|"right": 1|2|3} (marks for equal sides), "base_dot":true}',
            '  {"type":"quad", "kind":"rectangle"|"square"|"parallelogram"|"rhombus"|"trapezoid", "width":n, "height":n, '
            . '"skew":n, "top_width":n, "vertices":["A","B","C","D"] (bottom-left, bottom-right, top-right, top-left), '
            . '"labels":{"bottom"|"right"|"top"|"left":".."}, "angles":{"A"|"B"|"C"|"D":{"label":"..","arcs":1}}, '
            . '"right_angles":true, "ticks":{...}, "diagonals":true}',
            '  {"type":"circle", "center_label":"O", "points":[{"label":"A","angle":degrees counter-clockwise from the '
            . 'right}], "radii":[{"to":"A","label":"r"}], "chords":[{"from":"A","to":"B","label":".."}], '
            . '"central_angle":{"from":"A","to":"B","label":"60°"}}',
            '  {"type":"segments", "points":["A","B","C"], "lengths":["3","x"] (one per stretch), '
            . '"ratios":[1,2] (proportions for the drawing), "total":"AC = 12"}',
            '  {"type":"parallel_lines", "lines":["L1","L2"], "angle":degrees of the transversal, '
            . '"transversal_label":"t", "angle_labels":{"1".."8": "label" or {"label":"..","arcs":1}} (1-4 around the upper '
            . 'crossing, 5-8 around the lower one; each group is: above-left, above-right, below-left, below-right)}',
            '  {"type":"axes", "xrange":[min,max], "yrange":[min,max], "points":[{"label":"A","x":1,"y":2}], '
            . '"polygon":["A","B","C"], "functions":[{"type":"linear","m":2,"b":1} or {"type":"quadratic","a":1,"b":0,"c":-4}]}',
        ];
    }

    /**
     * Strip tags and extra whitespace.
     *
     * In math mode tags are not stripped because "<" and ">" can be part of the formula;
     * the saver escapes the final text before storing it as HTML.
     *
     * @param string $text Text.
     * @param bool $math Whether this is mathematical text.
     * @return string
     */
    protected function clean(string $text, bool $math = false): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!$math) {
            $text = strip_tags($text);
        }
        return trim(preg_replace('/[ \t]+/', ' ', $text));
    }
}
