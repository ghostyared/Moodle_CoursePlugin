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

use local_ai_yared_course\local\ai_client;
use local_ai_yared_course\local\question_generator;

defined('MOODLE_INTERNAL') || die();

/**
 * Asks the AI for the teaching content of a topic and validates the answer.
 *
 * Two requests are made (overview, then the long class document) so that the answers stay short enough
 * to be complete and valid JSON.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_generator {

    /** @var ai_client */
    protected $client;

    /**
     * Constructor.
     *
     * @param ai_client $client AI client.
     */
    public function __construct(ai_client $client) {
        $this->client = $client;
    }

    /**
     * Amount of content and writing style for an age.
     *
     * @param int $age Age in years.
     * @return array
     */
    public static function size_rules(int $age): array {
        if ($age <= 5) {
            return ['objectives' => 2, 'keypoints' => 3, 'sections' => 2, 'glossary' => 3, 'review' => 3,
                'style' => 'Use extremely simple words and very short sentences (maximum 8 words). Playful and warm tone. '
                    . 'Every paragraph has at most 2 sentences. Only concrete things a small child knows.'];
        }
        if ($age <= 8) {
            return ['objectives' => 3, 'keypoints' => 3, 'sections' => 3, 'glossary' => 4, 'review' => 4,
                'style' => 'Use short sentences, common words, a friendly tone and concrete everyday examples. '
                    . 'Every paragraph has at most 3 sentences.'];
        }
        if ($age <= 12) {
            return ['objectives' => 3, 'keypoints' => 4, 'sections' => 4, 'glossary' => 5, 'review' => 5,
                'style' => 'Use clear language, a motivating tone, analogies with daily life and simple reasoning.'];
        }
        if ($age <= 17) {
            return ['objectives' => 4, 'keypoints' => 4, 'sections' => 5, 'glossary' => 6, 'review' => 6,
                'style' => 'Use appropriate subject terminology explained when first used, cause-effect reasoning and '
                    . 'real-life applications.'];
        }
        return ['objectives' => 4, 'keypoints' => 4, 'sections' => 6, 'glossary' => 8, 'review' => 6,
            'style' => 'Use an academic but readable tone, precise terminology, depth and rigour; include a worked example '
                . 'or a short case.'];
    }

    /**
     * Generate the class presentation (slides) of a topic.
     *
     * @param string $title Topic title.
     * @param string $description Teacher description of the content.
     * @param array $overview Overview already generated (summary, objectives, key points).
     * @param int $age Target age.
     * @param string $language Language name (e.g. "Spanish").
     * @param string $langcode Language code (e.g. "es").
     * @param bool $allowfigures Whether geometric figures can be described (mathematics topics).
     * @return array|null The deck (see slides::from_ai()), or null when nothing usable came back.
     * @throws \moodle_exception If the answer is not valid JSON.
     */
    public function generate_slides(string $title, string $description, array $overview, int $age, string $language,
            string $langcode, bool $allowfigures = false): ?array {
        $size = slides::size_rules($age);
        $rules = self::size_rules($age);
        $system = 'You are an expert teacher who designs clear classroom slides. You always reply with one single valid '
            . 'JSON object and nothing else.';
        $points = [];
        foreach ((array) ($overview['key_points'] ?? []) as $point) {
            $points[] = trim(($point['title'] ?? '') . ': ' . ($point['text'] ?? ''));
        }
        $lines = [
            "Topic title: {$title}",
            "Teacher's description of what the topic must cover: {$description}",
            'Audience: ' . question_generator::age_descriptor($age),
            "Language of ALL the text: {$language}.",
            'Writing style: ' . $rules['style'],
            'Learning objectives already decided: ' . implode(' | ', (array) ($overview['objectives'] ?? [])),
            'Key ideas already decided: ' . implode(' | ', $points),
            '',
            "Create the slides for the class presentation: about {$size['slides']} slides in this order: 1 title slide, "
                . '1 objectives slide, the core content slides (one idea per slide), 1 worked example or activity slide, '
                . '1 summary slide. Rules:',
            "- Each slide has at most {$size['bullets']} bullets of at most {$size['words']} words each; slides are read "
                . 'from far away: short phrases, no paragraphs, no markdown, no HTML.',
            '- "layout" is one of "title", "bullets", "example", "summary".',
            '- "text" is an optional one-sentence line (use it on the title slide as the subtitle).',
            '- "notes" are 1-3 sentences for the teacher to say or explain on that slide (not shown to students).',
            '- "image_query" is 2 to 5 words (in English) to search a free picture for that slide, or "" when a picture '
                . 'would not help. Never ask for a picture of a real living person.',
            '- "emoji" is ONE emoji that represents the slide (shown as its icon when there is no picture).',
            '- Facts must be correct and consistent with the key ideas above.',
        ];
        if ($allowfigures) {
            foreach (question_generator::figure_rules() as $rule) {
                $lines[] = $rule;
            }
            $lines[] = '- Use the "figure" field only on slides where a figure makes the idea clear.';
        }
        $lines[] = 'Reply with JSON in exactly this shape:';
        $lines[] = '{"title":"...","slides":[{"layout":"title","title":"...","text":"...","bullets":[],"notes":"...",'
            . '"image_query":"...","emoji":"📘"},{"layout":"bullets","title":"...","bullets":["..."],"notes":"...",'
            . '"image_query":"","emoji":"💡"}]}';

        $data = self::decode($this->client->complete($system, implode("\n", $lines)));
        return slides::from_ai($data, $title, $langcode, $allowfigures);
    }

    /**
     * Generate the overview used for the header, the practice and the extension activity.
     *
     * @param string $title Topic title.
     * @param string $description Teacher description of the content.
     * @param int $age Target age.
     * @param string $language Language name (e.g. "Spanish").
     * @return array Validated overview.
     * @throws \moodle_exception If the answer is not usable.
     */
    public function generate_overview(string $title, string $description, int $age, string $language): array {
        $rules = self::size_rules($age);
        $system = 'You are an expert teacher and instructional designer. You write accurate, engaging content for students. '
            . 'You always reply with one single valid JSON object and nothing else.';

        $prompt = implode("\n", [
            "Topic title: {$title}",
            "Teacher's description of what the topic must cover: {$description}",
            'Audience: ' . question_generator::age_descriptor($age),
            "Language of ALL the text: {$language}.",
            'Writing style: ' . $rules['style'],
            '',
            'Create the overview of this topic for the course page. Rules:',
            "- \"summary\": {$this->summary_length($age)}, an inviting introduction for the student (plain text).",
            "- \"objectives\": exactly {$rules['objectives']} learning objectives, each starting with an action verb "
                . '(e.g. "Recognize...", "Explain...").',
            "- \"key_points\": exactly {$rules['keypoints']} key ideas, each with one fitting emoji, a short title and "
                . 'one or two sentences.',
            '- "fun_fact": one surprising and TRUE curiosity related to the topic (one or two sentences).',
            '- "video_query": 3 to 7 words to search a good educational video about the topic on YouTube, in the '
                . "language of the text and suited to the age (for young children include words like \"para niños\" / \"for kids\").",
            '- "practice_intro": one motivating sentence that invites the student to take the graded practice.',
            '- "extension": a hands-on or creative extension activity that deepens the topic and can be done by the student '
                . 'alone: a "title", "instructions" (2-3 sentences) and "steps" (3 to 5 short steps).',
            '- "forum_question": one open discussion question about the topic for a class forum, suited to the age, '
                . 'that invites students to give an opinion or an example and comment on each other\'s answers '
                . '(one or two sentences, no yes/no question, plain text).',
            '- Facts must be correct. Plain text only: no markdown and no HTML.',
            'Reply with JSON in exactly this shape:',
            '{"summary":"...","objectives":["..."],"key_points":[{"emoji":"💡","title":"...","text":"..."}],'
                . '"fun_fact":"...","video_query":"...","practice_intro":"...","forum_question":"...",'
                . '"extension":{"title":"...","instructions":"...","steps":["..."]}}',
        ]);

        $data = self::decode($this->client->complete($system, $prompt));

        $summary = self::text($data['summary'] ?? '', 900);
        if ($summary === '') {
            throw new \moodle_exception('error_badjson', 'local_ai_yared_course');
        }

        $keypoints = [];
        foreach ((array) ($data['key_points'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = self::text($item['text'] ?? '', 400);
            $ktitle = self::text($item['title'] ?? '', 80);
            if ($text === '' && $ktitle === '') {
                continue;
            }
            $emoji = trim((string) ($item['emoji'] ?? ''));
            $keypoints[] = [
                'emoji' => ($emoji !== '' && \core_text::strlen($emoji) <= 6) ? $emoji : '⭐',
                'title' => $ktitle,
                'text' => $text,
            ];
        }

        $extension = (array) ($data['extension'] ?? []);

        return [
            'summary' => $summary,
            'objectives' => self::text_list($data['objectives'] ?? [], 8, 200),
            'key_points' => array_slice($keypoints, 0, 6),
            'fun_fact' => self::text($data['fun_fact'] ?? '', 400),
            'video_query' => self::text($data['video_query'] ?? '', 120) ?: $title,
            'practice_intro' => self::text($data['practice_intro'] ?? '', 300),
            'forum_question' => self::text($data['forum_question'] ?? '', 500),
            'extension' => [
                'title' => self::text($extension['title'] ?? '', 120),
                'instructions' => self::text($extension['instructions'] ?? '', 800),
                'steps' => self::text_list($extension['steps'] ?? [], 8, 250),
            ],
        ];
    }

    /**
     * Generate the content of the class document (PDF).
     *
     * @param string $title Topic title.
     * @param string $description Teacher description.
     * @param array $overview Result of generate_overview().
     * @param int $age Target age.
     * @param string $language Language name.
     * @return array Validated document content.
     * @throws \moodle_exception If the answer is not usable.
     */
    public function generate_pdf(string $title, string $description, array $overview, int $age, string $language): array {
        $rules = self::size_rules($age);
        $system = 'You are an expert teacher who writes clear class handouts for students. '
            . 'You always reply with one single valid JSON object and nothing else.';

        $prompt = implode("\n", [
            "Topic title: {$title}",
            "Teacher's description of what the topic must cover: {$description}",
            'Learning objectives already announced to the students: ' . implode(' | ', $overview['objectives']),
            'Audience: ' . question_generator::age_descriptor($age),
            "Language of ALL the text: {$language}.",
            'Writing style: ' . $rules['style'],
            '',
            'Write the class handout (a printable PDF the student reads) for this topic. Rules:',
            '- "subtitle": one short line.',
            '- "intro": one short introductory paragraph.',
            "- \"sections\": exactly {$rules['sections']} sections that develop the topic in a logical order. Each has a "
                . '"heading", 1 or 2 "paragraphs" (full sentences) and optionally "bullets" (short list items).',
            '- "example": one concrete example, activity or mini case that illustrates the topic.',
            "- \"glossary\": {$rules['glossary']} key terms with a simple \"definition\".",
            '- "summary_points": 3 to 5 short sentences that summarise what was learned.',
            "- \"review_questions\": {$rules['review']} open questions so the student can check understanding "
                . '(no answers).',
            '- Facts must be correct. Plain text only: no markdown, no HTML and no emojis.',
            'Reply with JSON in exactly this shape:',
            '{"subtitle":"...","intro":"...","sections":[{"heading":"...","paragraphs":["..."],"bullets":["..."]}],'
                . '"example":"...","glossary":[{"term":"...","definition":"..."}],"summary_points":["..."],'
                . '"review_questions":["..."]}',
        ]);

        $data = self::decode($this->client->complete($system, $prompt));

        $sections = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $paragraphs = self::text_list($section['paragraphs'] ?? [], 4, 1500);
            $heading = self::text($section['heading'] ?? '', 120);
            if (!$paragraphs && $heading === '') {
                continue;
            }
            $sections[] = [
                'heading' => $heading,
                'paragraphs' => $paragraphs,
                'bullets' => self::text_list($section['bullets'] ?? [], 8, 250),
            ];
        }
        if (!$sections) {
            throw new \moodle_exception('error_badjson', 'local_ai_yared_course');
        }

        $glossary = [];
        foreach ((array) ($data['glossary'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $term = self::text($entry['term'] ?? '', 80);
            $definition = self::text($entry['definition'] ?? '', 300);
            if ($term !== '' && $definition !== '') {
                $glossary[] = ['term' => $term, 'definition' => $definition];
            }
        }

        return [
            'subtitle' => self::text($data['subtitle'] ?? '', 200),
            'intro' => self::text($data['intro'] ?? '', 900),
            'sections' => $sections,
            'example' => self::text($data['example'] ?? '', 1200),
            'glossary' => array_slice($glossary, 0, 12),
            'summary_points' => self::text_list($data['summary_points'] ?? [], 8, 300),
            'review_questions' => self::text_list($data['review_questions'] ?? [], 10, 300),
        ];
    }

    /**
     * Length hint of the summary.
     *
     * @param int $age Age in years.
     * @return string
     */
    protected function summary_length(int $age): string {
        if ($age <= 5) {
            return '2 very short sentences';
        }
        if ($age <= 12) {
            return '2 to 3 sentences';
        }
        return '3 to 4 sentences';
    }

    /**
     * Decode a JSON answer, tolerating code fences and text around the object.
     *
     * @param string $raw Raw model output.
     * @return array
     * @throws \moodle_exception If it is not valid JSON.
     */
    public static function decode(string $raw): array {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($data)) {
            throw new \moodle_exception('error_badjson', 'local_ai_yared_course');
        }
        return $data;
    }

    /**
     * Clean a plain text value: no tags, collapsed spaces, limited length.
     *
     * @param mixed $value Value.
     * @param int $max Maximum characters.
     * @return string
     */
    public static function text($value, int $max): string {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', strip_tags((string) $value)));
        return \core_text::strlen($text) > $max ? rtrim(\core_text::substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * Clean a list of plain text values, removing empty ones and list markers.
     *
     * @param mixed $values List.
     * @param int $maxitems Maximum items.
     * @param int $maxlen Maximum characters per item.
     * @return string[]
     */
    public static function text_list($values, int $maxitems, int $maxlen): array {
        $result = [];
        foreach ((array) $values as $value) {
            $text = self::text($value, $maxlen);
            $text = preg_replace('/^\s*(?:\d+\s*[\.\)\-]|[-*•])\s*/u', '', $text);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return array_slice($result, 0, $maxitems);
    }
}
