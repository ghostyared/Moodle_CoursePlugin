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

namespace local_ai_yared_course\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\syllabus;
use local_ai_yared_course\local\topic_runner;
use local_ai_yared_course\local\topic_store;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: create or update a topic of a course.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_topic extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'topicid' => new external_value(PARAM_INT, 'Topic id (0 to create a new one)', VALUE_DEFAULT, 0),
            'title' => new external_value(PARAM_TEXT, 'Topic title'),
            'summary' => new external_value(PARAM_RAW, 'Topic summary', VALUE_DEFAULT, ''),
            'date' => new external_value(PARAM_TEXT, 'Class date (YYYY-MM-DD)', VALUE_DEFAULT, ''),
            'options' => api::options_structure(),
            'generate' => new external_value(PARAM_BOOL, 'Generate the content with AI right now', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Create or update the topic.
     *
     * @param int $courseid Course id.
     * @param int $topicid Topic id.
     * @param string $title Topic title.
     * @param string $summary Topic summary.
     * @param string $date Class date.
     * @param array $options Generation options.
     * @param bool $generate Generate the content now.
     * @return array
     */
    public static function execute($courseid, $topicid = 0, $title = '', $summary = '', $date = '', $options = [],
            $generate = false) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'topicid' => $topicid,
            'title' => $title,
            'summary' => $summary,
            'date' => $date,
            'options' => $options,
            'generate' => $generate,
        ]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/ai_yared_course:manage', $context);
        require_capability('moodle/course:manageactivities', $context);

        $classdate = trim($params['date']) !== '' ? (int) strtotime($params['date']) : 0;
        $id = topic_store::upsert($course->id, (int) $params['topicid'], $params['title'], $params['summary'], $classdate);

        $generated = false;
        if ($params['generate']) {
            $topic = topic_store::get($id);
            $client = ai_factory::create();
            if ($topic && $client->is_configured()) {
                $runoptions = api::normalise_options($params['options']);
                topic_runner::generate($course, $client, $topic, $runoptions);
                $generated = true;
            }
        }

        syllabus::publish($course, topic_store::get_topics($course->id));

        $topic = topic_store::get($id);
        return [
            'topicid' => $id,
            'generated' => $generated,
            'topic' => [
                'id' => (int) $topic->id,
                'title' => $topic->title,
                'summary' => (string) $topic->summary,
                'date' => (int) $topic->classdate > 0 ? date('Y-m-d', (int) $topic->classdate) : '',
                'status' => $topic->status,
                'sectionnum' => (int) $topic->sectionnum,
            ],
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'topicid' => new external_value(PARAM_INT, 'The id of the topic'),
            'generated' => new external_value(PARAM_BOOL, 'Whether the content was generated now'),
            'topic' => api::topic_structure(),
        ]);
    }
}
