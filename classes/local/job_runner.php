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

/**
 * Executes a job: builds every topic of the request, saving progress as it goes.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

use local_ai_yared_course\form\generate_form;

class job_runner {

    /**
     * Run a queued job.
     *
     * @param \stdClass $job Job record.
     */
    public static function run(\stdClass $job): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $id = (int) $job->id;
        $options = job_store::options($job);
        $results = [];
        $success = 0;
        $failed = 0;
        $status = job_store::DONE;
        $error = '';

        job_store::update($id, ['status' => job_store::RUNNING, 'step' => get_string('job_starting', 'local_ai_yared_course')]);
        \core_php_time_limit::raise(0);

        try {
            $course = get_course((int) $job->courseid);
            $context = \context_course::instance($course->id);
            if (!has_capability('local/ai_yared_course:use', $context, (int) $job->userid)
                    || !has_capability('moodle/course:manageactivities', $context, (int) $job->userid)) {
                throw new \moodle_exception('job_error_permission', 'local_ai_yared_course');
            }
            usage_log::set_course((int) $course->id);
            $client = ai_factory::create();
            // Jobs created by the external API may ask for the structure only, without AI content.
            $useai = !array_key_exists('ai', $options) || !empty($options['ai']);
            if ($useai && !$client->is_configured()) {
                throw new \moodle_exception('notconfigured_teacher', 'local_ai_yared_course');
            }
            $trace = new job_trace($id);
            // Preview first: the content is generated and kept as a draft; nothing is created in the course until
            // the teacher approves it.
            $preview = $useai && !empty($options['preview']);
            $topics = (array) ($options['topics'] ?? []);
            $total = count($topics);

            foreach ($topics as $number => $topic) {
                if (job_store::cancel_requested($id)) {
                    $status = job_store::CANCELLED;
                    break;
                }
                $title = (string) $topic['title'];
                $started = microtime(true);
                try {
                    $trace->output(get_string('generating_topic_number', 'local_ai_yared_course', (object) [
                        'current' => $number + 1, 'total' => $total, 'title' => $title]));
                    $input = self::build_input($options, $topic);
                    $builder = new course_builder($course, $client, $trace);
                    if ($preview) {
                        $parts = $builder->prepare_topic($input);
                        $draftid = topic_draft::create((int) $job->userid, (int) $course->id, $input, $parts);
                        $result = [
                            'draft' => $draftid,
                            'preview' => true,
                            'items' => [],
                            'sectionnum' => 0,
                            'sectionid' => 0,
                            'warnings' => array_values((array) $parts['warnings']),
                            'hasvideo' => $parts['video'] !== null,
                            'haspdf' => !empty($parts['haspdf']) || $parts['pdf'] !== null,
                            'questions' => count((array) $parts['questions']),
                            'categoryid' => 0,
                        ] + (array) $parts['meta'];
                    } else {
                        $result = $useai ? $builder->create_topic($input) : $builder->create_empty_topic($input);
                    }
                    $results[] = ['title' => $title, 'result' => $result, 'error' => null];
                    $success++;
                    usage_log::topic($course, (int) $job->userid, $title, (int) $input['age'], $result, '',
                        (int) round((microtime(true) - $started) * 1000), $client->get_usage());
                } catch (job_cancelled $e) {
                    $status = job_store::CANCELLED;
                    $results[] = ['title' => $title, 'result' => null,
                        'error' => get_string('job_topic_cancelled', 'local_ai_yared_course')];
                    break;
                } catch (\Throwable $e) {
                    $results[] = ['title' => $title, 'result' => null, 'error' => $e->getMessage()];
                    $failed++;
                    usage_log::topic($course, (int) $job->userid, $title, (int) ($topic['age'] ?? 0), null, $e->getMessage(),
                        (int) round((microtime(true) - $started) * 1000), $client->get_usage());
                    debugging('local_ai_yared_course: topic "' . $title . '" failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
                // Save after every topic: if the server stops, what was already built is not lost from the report.
                job_store::update($id, ['done' => $success, 'failed' => $failed,
                    'results' => self::encode($results)]);
            }
        } catch (\Throwable $e) {
            $status = job_store::FAILED;
            $error = $e->getMessage();
        }

        usage_log::set_course(0);
        if ($status === job_store::DONE && $success === 0 && $failed > 0) {
            $status = job_store::FAILED;
        }
        job_store::update($id, [
            'status' => $status,
            'done' => $success,
            'failed' => $failed,
            'results' => self::encode($results),
            'error' => $error,
            'step' => '',
            'timefinished' => time(),
        ]);
    }

    /**
     * Input of course_builder::create_topic() for one topic.
     *
     * @param array $o Job options.
     * @param array $topic Topic: title, age, classdate.
     * @return array
     */
    protected static function build_input(array $o, array $topic): array {
        $language = isset(generate_form::LANGUAGES[$o['language'] ?? '']) ? $o['language'] : 'es';
        $createbank = !empty($o['createbank']);
        $questioncount = $createbank ? max(1, min(topic_runner::MAX_QUESTIONS, (int) ($o['questioncount'] ?? 10))) : 0;
        return [
            'title' => (string) $topic['title'],
            'description' => trim((string) ($topic['summary'] ?? '')) !== ''
                ? (string) $topic['summary'] : (string) $topic['title'],
            'age' => (int) $topic['age'],
            'language' => $language,
            'languagename' => generate_form::LANGUAGES[$language],
            // A topic can carry its own parent section (external API); otherwise the job-wide choice applies.
            'sectionnum' => isset($topic['sectionnum'])
                ? (int) $topic['sectionnum']
                : (($o['sectionmode'] ?? 'existing') === 'existing'
                    ? (int) ($o['sectionnum'] ?? 0) : course_builder::NEWSECTION),
            'findvideo' => !empty($o['findvideo']),
            'questioncount' => $questioncount,
            'qkind' => math_catalog::valid_choice((string) ($o['qkind'] ?? 'auto')) ? (string) ($o['qkind'] ?? 'auto') : 'auto',
            'createbank' => $createbank,
            'createquiz' => !empty($o['createquiz']),
            'createassign' => !empty($o['createassign']),
            'createpdf' => !empty($o['createpdf']),
            'createforum' => !empty($o['createforum']),
            'createslides' => !empty($o['createslides']),
            'usesubsection' => !empty($o['usesubsection']),
            'subsectionmode' => in_array(($o['subsectionmode'] ?? ''), ['accordion', 'mosaic'], true)
                ? $o['subsectionmode'] : '',
            'classdate' => (int) $topic['classdate'],
            'quizopen' => (int) $topic['classdate'],
            'quizclosedays' => 7,
            'quiztimelimit' => 60,
            'quizattempts' => 2,
            'quizgrade' => 20,
            'quizquestions' => $questioncount,
            'quizvisible' => 0,
            'extensiontype' => 'assign',
            'hidesection' => !empty($o['hidesection']),
        ];
    }

    /**
     * JSON of the results, tolerant to invalid UTF-8.
     *
     * @param array $results Results.
     * @return string
     */
    protected static function encode(array $results): string {
        return json_encode($results, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
    }
}
