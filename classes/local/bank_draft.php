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
 * Keeps the questions of the generator as a draft, so the teacher can review and edit them before saving.
 *
 * The draft uses the same store and review screen as the PDF/image import (import.php), marked with
 * origin "bank" and with the parameters needed to write another variant of one question.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bank_draft {

    /** Origin marker stored in the manifest. */
    public const ORIGIN = 'bank';

    /** @var string[] Warnings found while building exercises (for example an image that could not be downloaded). */
    public static $warnings = [];

    /**
     * Create the draft.
     *
     * @param int $userid Owner.
     * @param int $courseid Course.
     * @param array[] $questions Questions returned by question_generator.
     * @param array $gen Generation parameters: topics, age, language (name), qtype, instructions, mathmode, allowfigures, label.
     * @param int $categoryid Destination category (0 = create $newcategory).
     * @param string $newcategory Name of the new category.
     * @param string[] $warnings Warnings to show on the review.
     * @return int Draft id.
     */
    public static function create(int $userid, int $courseid, array $questions, array $gen, int $categoryid,
            string $newcategory, array $warnings = []): int {
        $manifest = [
            'version' => 2,
            'userid' => $userid,
            'timecreated' => time(),
            'math' => !empty($gen['mathmode']),
            'attachimage' => false,
            'courseid' => $courseid,
            'categoryid' => $categoryid,
            'newcategory' => $newcategory,
            'sources' => [['filename' => (string) ($gen['label'] ?? 'IA')]],
            'exercises' => [],
            'warnings' => $warnings,
            'age' => (int) ($gen['age'] ?? 12),
            'language' => 'es',
            'origin' => self::ORIGIN,
            'gen' => [
                'topics' => array_values((array) ($gen['topics'] ?? [])),
                'age' => (int) ($gen['age'] ?? 12),
                'language' => (string) ($gen['language'] ?? 'Spanish'),
                'qtype' => (string) ($gen['qtype'] ?? 'multichoice'),
                'instructions' => (string) ($gen['instructions'] ?? ''),
                'mathmode' => !empty($gen['mathmode']),
                'allowfigures' => !empty($gen['allowfigures']),
            ],
        ];
        $draftid = import_store::create($userid, $manifest);
        self::$warnings = [];
        foreach (array_values($questions) as $i => $question) {
            $manifest['exercises'][] = self::exercise($question, $i + 1, $draftid, $userid);
        }
        $manifest['warnings'] = array_merge($manifest['warnings'], self::$warnings);
        import_store::save_manifest($draftid, $userid, $manifest);
        return $draftid;
    }

    /**
     * Convert a generated question to an exercise of the manifest (the figure becomes a file of the draft).
     *
     * @param array $q Question from question_generator.
     * @param int $number 1-based number.
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @return array
     */
    public static function exercise(array $q, int $number, int $draftid, int $userid): array {
        $fields = self::fields($q, $number, $draftid, $userid);
        $ex = $fields + [
            'number' => (string) $number,
            'needsreview' => false,
            'needsfigure' => false,
            'page' => 0,
            'figure' => null,
            'exercise' => null,
            'box' => null,
            'sourcefile' => '',
            'recommended' => $fields['type'],
            'viable' => [],
            'include' => true,
            'rewritten' => false,
            'ungradable' => false,
            'reformulated' => false,
            'auto' => null,
        ];
        // What the AI wrote first: "Restore" brings it back, and the rewrites start from it (like the PDF original).
        $ex['auto'] = $fields;
        $ex['original'] = [
            'type' => $fields['type'],
            'text' => $fields['text'],
            'options' => $fields['options'],
            'answer' => $fields['answer'],
            'answers' => $fields['answers'],
            'imagealt' => $fields['imagealt'],
        ];
        return $ex;
    }

    /**
     * The fields of an exercise that depend on the generated question.
     *
     * @param array $q Question.
     * @param int $number Number used in the file name.
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @return array
     */
    public static function fields(array $q, int $number, int $draftid, int $userid): array {
        $type = in_array($q['type'], exercise_extractor::TYPES, true) ? $q['type'] : 'multichoice';
        $options = [];
        if (in_array($type, ['multichoice', 'multiselect', 'gapselect', 'ddwtos'], true)) {
            foreach ((array) ($q['options'] ?? []) as $option) {
                $options[] = ['text' => (string) $option['text'], 'correct' => !empty($option['correct'])];
            }
        }
        $image = '';
        $svg = (string) ($q['figure_svg'] ?? '');
        if (!empty($q['image'])) {
            // A picture the teacher supplied by URL: download it now so the draft keeps its own copy.
            $fetched = image_helper::fetch((string) $q['image']);
            if (empty($fetched['error'])) {
                $image = import_store::add_file($draftid, $userid,
                    'q' . $number . '-img-' . substr(sha1($fetched['data']), 0, 8) . '.' . pathinfo($fetched['filename'], PATHINFO_EXTENSION),
                    $fetched['data'], $fetched['mimetype']);
            } else {
                self::$warnings[] = get_string('warn_imagefetch', 'local_ai_yared_course', (object) [
                    'url' => s(\core_text::substr((string) $q['image'], 0, 120)),
                    'error' => $fetched['error'],
                ]);
            }
        } else if ($svg !== '') {
            $image = import_store::add_file($draftid, $userid,
                'q' . $number . '-fig-' . substr(sha1($svg), 0, 8) . '.svg', $svg, 'image/svg+xml');
        }
        return [
            'type' => $type,
            'text' => (string) $q['text'],
            'answer' => $type === 'truefalse' ? !empty($q['answer']) : null,
            'answers' => $type === 'shortanswer' ? array_values(array_map('strval', (array) ($q['answers'] ?? []))) : [],
            'options' => $options,
            'feedback' => (string) ($q['feedback'] ?? ''),
            'math' => !empty($q['math']),
            'image' => $image,
            'useimage' => $image !== '',
            'imagealt' => (string) ($q['imagealt'] ?? ''),
        ];
    }
}
