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
 * Turns a designed topic (stored in local_ai_yared_course_topic) into a course section with its activities.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_runner {

    /** Maximum number of topics accepted in one request (UI and API). */
    public const MAX_TOPICS = 10;

    /** Maximum number of questions generated or added to a quiz for one topic. */
    public const MAX_QUESTIONS = 30;

    /**
     * Clamp the generation options to safe values.
     *
     * The web form already offers only sensible values, but the external API and the designer page
     * accept free numbers, so every entry point goes through this method before any AI call.
     *
     * @param array $options Raw options.
     * @return array Options with every numeric value inside its limits.
     */
    public static function sanitise_options(array $options): array {
        $clamp = function ($value, int $min, int $max, int $default) {
            if (!is_numeric($value)) {
                return $default;
            }
            return max($min, min($max, (int) $value));
        };
        $options['age'] = $clamp($options['age'] ?? null, levels::MIN_AGE, levels::MAX_AGE, 12);
        $options['questioncount'] = $clamp($options['questioncount'] ?? null, 0, self::MAX_QUESTIONS, 10);
        $options['quizquestions'] = $clamp($options['quizquestions'] ?? null, 0, self::MAX_QUESTIONS, 10);
        $options['quizgrade'] = $clamp($options['quizgrade'] ?? null, 1, 100, 20);
        $options['quiztimelimit'] = $clamp($options['quiztimelimit'] ?? null, 0, 600, 60);
        $options['quizattempts'] = $clamp($options['quizattempts'] ?? null, 0, 20, 2);
        $options['quizclosedays'] = $clamp($options['quizclosedays'] ?? null, 0, 365, 7);
        if (!isset(\local_ai_yared_course\form\generate_form::LANGUAGES[$options['language'] ?? ''])) {
            $options['language'] = 'es';
        }
        return $options;
    }

    /**
     * Default global options used to generate a topic.
     *
     * @return array
     */
    public static function default_options(): array {
        return [
            'age' => 12,
            'language' => 'es',
            'findvideo' => 1,
            'questioncount' => 10,
            'extensiontype' => 'assign',
            'hidesection' => 1,
            'quizopen' => strtotime('today'),
            'quizclosedays' => 7,
            'quiztimelimit' => 60,
            'quizattempts' => 2,
            'quizgrade' => 20,
            'quizquestions' => 10,
            'quizvisible' => 0,
            'createbank' => 1,
            'createquiz' => 1,
            'createassign' => 1,
            'createpdf' => 1,
            'createforum' => 1,
        ];
    }

    /**
     * Generate one topic: create its section and activities, then remember where it went.
     *
     * @param \stdClass $course Course.
     * @param \local_ai_yared_course\local\ai_client $client Configured AI client.
     * @param \stdClass $topic Topic record from local_ai_yared_course_topic.
     * @param array $options Global options (see default_options).
     * @param \progress_trace|null $trace Optional progress output.
     * @return array Result of course_builder::create_topic().
     */
    public static function generate(\stdClass $course, $client, \stdClass $topic, array $options = [],
            ?\progress_trace $trace = null): array {
        $options = self::sanitise_options($options + self::default_options());

        $input = [
            'title' => $topic->title,
            'description' => trim((string) $topic->summary) !== '' ? $topic->summary : $topic->title,
            // Each topic can override the global level with its own age; 0 means "use the global option".
            'age' => (int) ($topic->age ?? 0) > 0
                ? min(levels::MAX_AGE, max(levels::MIN_AGE, (int) $topic->age)) : (int) $options['age'],
            'language' => $options['language'],
            'languagename' => \local_ai_yared_course\form\generate_form::LANGUAGES[$options['language']] ?? 'Spanish',
            'sectionnum' => course_builder::NEWSECTION,
            'findvideo' => !empty($options['findvideo']),
            'questioncount' => (int) $options['questioncount'],
            'extensiontype' => $options['extensiontype'] === 'quiz' ? 'quiz' : 'assign',
            'hidesection' => !empty($options['hidesection']),
            'classdate' => (int) $topic->classdate,
            'quizopen' => (int) $options['quizopen'],
            'quizclosedays' => (int) $options['quizclosedays'],
            'quiztimelimit' => (int) $options['quiztimelimit'],
            'quizattempts' => (int) $options['quizattempts'],
            'quizgrade' => (int) $options['quizgrade'],
            'quizquestions' => (int) $options['quizquestions'],
            'quizvisible' => !empty($options['quizvisible']),
            'createbank' => !empty($options['createbank']),
            'createquiz' => !empty($options['createquiz']),
            'createassign' => !empty($options['createassign']),
            'createpdf' => !empty($options['createpdf']),
            'createforum' => !empty($options['createforum']),
        ];

        $builder = new course_builder($course, $client, $trace);
        $started = microtime(true);
        usage_log::set_course((int) $course->id);
        try {
            $result = $builder->create_topic($input);
        } catch (\Throwable $e) {
            usage_log::topic($course, 0, (string) $topic->title, (int) $input['age'], null, $e->getMessage(),
                (int) round((microtime(true) - $started) * 1000), $client->get_usage());
            throw $e;
        } finally {
            usage_log::set_course(0);
        }
        usage_log::topic($course, 0, (string) $topic->title, (int) $input['age'], $result, '',
            (int) round((microtime(true) - $started) * 1000), $client->get_usage());

        topic_store::mark_generated((int) $topic->id, (int) $result['sectionid'], (int) $result['sectionnum']);

        // Leave a trace of who generated what, since every run spends the site's AI quota.
        \local_ai_yared_course\event\topic_generated::create([
            'objectid' => (int) $topic->id,
            'context' => \context_course::instance($course->id),
            'other' => [
                'provider' => (string) ($result['provider'] ?? ''),
                'model' => (string) ($result['model'] ?? ''),
                'questions' => (int) ($result['questions'] ?? 0),
            ],
        ])->trigger();

        return $result;
    }
}
