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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\syllabus;
use local_ai_yared_course\local\topic_runner;
use local_ai_yared_course\local\topic_store;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: create a course with its designed topics.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'categoryid' => new external_value(PARAM_INT, 'Course category id', VALUE_DEFAULT, 1),
            'summary' => new external_value(PARAM_RAW, 'Course summary', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible', VALUE_DEFAULT, true),
            'options' => api::options_structure(),
            'topics' => new external_multiple_structure(
                new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'Topic title'),
                    'summary' => new external_value(PARAM_RAW, 'Topic summary', VALUE_DEFAULT, ''),
                    'date' => new external_value(PARAM_TEXT, 'Class date (YYYY-MM-DD)', VALUE_DEFAULT, ''),
                ]),
                'Topics to design',
                VALUE_DEFAULT,
                []
            ),
            'generate' => new external_value(PARAM_BOOL, 'Generate the content with AI right now', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Create the course and its topics.
     *
     * @param string $fullname Course full name.
     * @param string $shortname Course short name.
     * @param int $categoryid Category id.
     * @param string $summary Course summary.
     * @param bool $visible Whether the course is visible.
     * @param array $options Generation options.
     * @param array $topics Topics to design.
     * @param bool $generate Generate the content now.
     * @return array
     */
    public static function execute($fullname, $shortname, $categoryid = 1, $summary = '', $visible = true,
            $options = [], $topics = [], $generate = false) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'categoryid' => $categoryid,
            'summary' => $summary,
            'visible' => $visible,
            'options' => $options,
            'topics' => $topics,
            'generate' => $generate,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        if (count($params['topics']) > topic_runner::MAX_TOPICS) {
            throw new \invalid_parameter_exception('A maximum of ' . topic_runner::MAX_TOPICS
                . ' topics is accepted per request.');
        }

        // Make sure the target category exists, otherwise use the default one.
        if (!\core_course_category::get($params['categoryid'], IGNORE_MISSING)) {
            $params['categoryid'] = (int) \core_course_category::get_default()->id;
        }
        // The permission to create courses is checked in the category where the course will live, so
        // managers of a single category can use the API there.
        require_capability('moodle/course:create', \context_coursecat::instance($params['categoryid']));

        $course = create_course((object) [
            'fullname' => $params['fullname'],
            'shortname' => $params['shortname'],
            'category' => $params['categoryid'],
            'summary' => $params['summary'],
            'summaryformat' => FORMAT_HTML,
            'format' => 'topics',
            'visible' => $params['visible'] ? 1 : 0,
        ]);

        topic_store::save($course->id, api::topics_to_rows($params['topics']));

        $generated = 0;
        if ($params['generate'] && !empty($params['topics'])) {
            $client = ai_factory::create();
            if ($client->is_configured()) {
                $runoptions = api::normalise_options($params['options']);
                foreach (topic_store::get_topics($course->id) as $topic) {
                    try {
                        topic_runner::generate($course, $client, $topic, $runoptions);
                        $generated++;
                    } catch (\Throwable $e) {
                        debugging('local_ai_yared_course external create_course: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
        }

        syllabus::publish($course, topic_store::get_topics($course->id));

        return [
            'courseid' => (int) $course->id,
            'generated' => $generated,
            'topics' => api::export_topics($course->id),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The id of the new course'),
            'generated' => new external_value(PARAM_INT, 'Number of topics generated'),
            'topics' => new external_multiple_structure(api::topic_structure(), 'The topics of the course'),
        ]);
    }
}
