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
use local_ai_yared_course\local\syllabus;
use local_ai_yared_course\local\topic_store;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: update the class dates of the topics and republish the syllabus.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_syllabus extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'dates' => new external_multiple_structure(
                api::date_structure(),
                'Topics whose class date changes',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Update the dates and republish the syllabus label.
     *
     * @param int $courseid Course id.
     * @param array $dates List of ['topicid', 'date'].
     * @return array
     */
    public static function execute($courseid, $dates = []) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'dates' => $dates,
        ]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/ai_yared_course:manage', $context);
        require_capability('moodle/course:manageactivities', $context);

        $map = [];
        foreach ($params['dates'] as $date) {
            $map[(int) $date['topicid']] = $date['date'];
        }

        $updated = topic_store::update_dates($course->id, $map);
        syllabus::publish($course, topic_store::get_topics($course->id));

        return [
            'updated' => $updated,
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
            'updated' => new external_value(PARAM_INT, 'Number of topics updated'),
            'topics' => new external_multiple_structure(api::topic_structure(), 'The topics of the course'),
        ]);
    }
}
